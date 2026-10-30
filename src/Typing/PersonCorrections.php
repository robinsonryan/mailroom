<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Typing;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RobinsonRyan\Mailroom\Contracts\Corrections;
use RobinsonRyan\Mailroom\Contracts\DecisionGateway;
use RobinsonRyan\Mailroom\Data\MessageRef;
use RobinsonRyan\Mailroom\Enums\DecidedBy;
use RobinsonRyan\Mailroom\Exceptions\GatewayUnavailable;
use RobinsonRyan\Mailroom\Mailroom;
use RobinsonRyan\Mailroom\Models\Message;
use RobinsonRyan\Mailroom\Models\Typing;
use RobinsonRyan\Mailroom\TypeSets\TypeDefinition;
use RobinsonRyan\Mailroom\TypeSets\TypeSet;
use RobinsonRyan\Mailroom\TypeSets\TypeSetRegistry;
use Throwable;

/**
 * Takes the container rather than Mailroom because Mailroom depends on this
 * class: it is resolved at the moment of writing labels.
 */
final readonly class PersonCorrections implements Corrections
{
    /** Each attempt loses only to a typing that landed since it looked; three is plenty. */
    private const ATTEMPTS = 3;

    public function __construct(
        private Container $container,
        private TypeSetRegistry $typeSets,
        private DecisionGateway $gateway,
        private TypingAnnouncer $announcer,
    ) {}

    public function correct(MessageRef $ref, string $typeSet, string $type, bool $fromLabel = false): Typing
    {
        $set = $this->typeSets->get($typeSet);

        if (! $set->type($type) instanceof TypeDefinition) {
            throw new InvalidArgumentException("Type set {$typeSet} has no type {$type}.");
        }

        $mailroom = $this->container->make(Mailroom::class);
        $message = $mailroom->record($ref);

        // A typing landing mid-way takes the one current slot: a person's word goes on top of it.
        return retry(
            self::ATTEMPTS,
            fn (): Typing => $this->apply($mailroom, $message, $set, $type, $fromLabel),
            when: fn (Throwable $e): bool => $e instanceof UniqueConstraintViolationException,
        );
    }

    private function apply(Mailroom $mailroom, Message $message, TypeSet $set, string $type, bool $fromLabel): Typing
    {
        $current = $message->currentTyping($set->key);

        // A guess below the threshold is not settled: a person repeating it is a
        // decision the consumer is waiting for, so only a confident match is a no-op.
        if ($current instanceof Typing && $current->type === $type && $current->confident) {
            return $current;
        }

        if (! $fromLabel) {
            $this->moveLabel($mailroom, $message->ref(), $set, $current, $type);
        }

        $typing = $this->supersede($message, $set, $current, $type);

        $this->announcer->announce($typing);

        $this->report($message, $set, $type);

        return $typing;
    }

    private function moveLabel(Mailroom $mailroom, MessageRef $ref, TypeSet $set, ?Typing $current, string $type): void
    {
        $old = $current instanceof Typing && $current->confident ? $set->labelFor($current->type) : null;
        $new = $set->labelFor($type);

        $mailroom->changeLabels($ref, $new === null ? [] : [$new], $old === null ? [] : [$old]);
    }

    private function supersede(Message $message, TypeSet $set, ?Typing $current, string $type): Typing
    {
        return DB::connection($message->getConnectionName())->transaction(function () use ($message, $set, $current, $type): Typing {
            // Only one typing may be current, so the old one stands aside before the
            // new one is inserted, pointing at itself until there is a row to point at.
            $current?->update(['superseded_by_id' => $current->id]);

            $typing = Typing::query()->create([
                'message_id' => $message->id,
                'type_set' => $set->key,
                'type_set_version' => $set->version,
                'type' => $type,
                'confidence' => 1.0,
                'decided_by' => DecidedBy::Person,
                'confident' => true,
            ]);

            $current?->update(['superseded_by_id' => $typing->id]);

            return $typing;
        });
    }

    private function report(Message $message, TypeSet $set, string $type): void
    {
        try {
            $this->gateway->recordLabel($set, "mail:{$message->mailbox->address}:{$message->provider_message_id}", $type);
        } catch (GatewayUnavailable $e) {
            Log::warning('Mailroom could not report a correction to the gateway.', [
                'message_id' => $message->id,
                'type_set' => $set->key,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
