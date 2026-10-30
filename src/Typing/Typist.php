<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Typing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use RobinsonRyan\Mailroom\Contracts\DecisionGateway;
use RobinsonRyan\Mailroom\Data\MessageSummary;
use RobinsonRyan\Mailroom\Enums\DecidedBy;
use RobinsonRyan\Mailroom\Events\MessageTyped;
use RobinsonRyan\Mailroom\Exceptions\GatewayUnavailable;
use RobinsonRyan\Mailroom\Exceptions\MessageNotFound;
use RobinsonRyan\Mailroom\Mailroom;
use RobinsonRyan\Mailroom\Models\Mailbox;
use RobinsonRyan\Mailroom\Models\Message;
use RobinsonRyan\Mailroom\Models\Typing;
use RobinsonRyan\Mailroom\ProviderRegistry;
use RobinsonRyan\Mailroom\TypeSets\TypeDefinition;
use RobinsonRyan\Mailroom\TypeSets\TypeSet;
use RobinsonRyan\Mailroom\TypeSets\TypeSetRegistry;

/**
 * Answers each type set's question about a message: rules first, then the
 * gateway. A set the gateway could not answer leaves no typing, which is what
 * puts the message on the retry queue.
 */
final readonly class Typist
{
    public function __construct(
        private ProviderRegistry $providers,
        private TypeSetRegistry $typeSets,
        private DecisionGateway $gateway,
        private GatewayOutage $outage,
        private Mailroom $mailroom,
    ) {}

    /**
     * Type a message for every set that has no current typing for it.
     *
     * @param  bool  $askGateway  false once this tick has found the gateway down: rules still run
     * @return bool whether the gateway is still worth asking this tick
     *
     * @throws MessageNotFound
     */
    public function type(Mailbox $mailbox, string $providerMessageId, bool $askGateway = true): bool
    {
        $message = $this->find($mailbox, $providerMessageId);
        $sets = $this->setsWanting($mailbox, $message);

        if ($message instanceof Message && $sets === []) {
            return $askGateway;
        }

        $summary = $this->summary($mailbox, $providerMessageId);
        $message = $this->store($mailbox, $summary);

        foreach ($sets as $set) {
            $askGateway = $this->typeForSet($mailbox, $message, $summary, $set, $askGateway);
        }

        return $askGateway;
    }

    /**
     * The message row with its metadata, for a message Mailroom may not have seen.
     *
     * @throws MessageNotFound
     */
    public function record(Mailbox $mailbox, string $providerMessageId): Message
    {
        return $this->store($mailbox, $this->summary($mailbox, $providerMessageId));
    }

    /**
     * Messages still missing a typing for some set, oldest first.
     *
     * @return Collection<int, Message>
     */
    public function untyped(Mailbox $mailbox, int $limit): Collection
    {
        $keys = array_map(fn (TypeSet $set): string => $set->key, $this->typeSets->forMailbox($mailbox->address));

        if ($keys === []) {
            return new Collection;
        }

        return Message::query()
            ->where('mailbox_id', $mailbox->id)
            ->where(function (Builder $query) use ($keys): void {
                foreach ($keys as $key) {
                    $query->orWhereDoesntHave('typings', fn (Builder $typings) => $typings->where('type_set', $key)->whereNull('superseded_by_id'));
                }
            })
            ->orderBy('received_at')
            ->limit($limit)
            ->get();
    }

    private function typeForSet(Mailbox $mailbox, Message $message, MessageSummary $summary, TypeSet $set, bool $askGateway): bool
    {
        foreach ($set->rules as $rule) {
            $type = $rule->match($summary);

            if ($type !== null) {
                $this->save($message, $summary, $set, $type, null, DecidedBy::Rule, true, null);

                return $askGateway;
            }
        }

        if (! $askGateway) {
            return false;
        }

        try {
            $answer = $this->gateway->ask($set, $this->state($summary), "mail:{$mailbox->address}:{$summary->providerMessageId}");
        } catch (GatewayUnavailable) {
            $this->outage->failed();

            return false;
        }

        // An answer the set cannot use: the gateway is reachable, so keep asking
        // it about other messages, but this one stays untyped.
        if (! $set->type($answer->type) instanceof TypeDefinition) {
            $this->outage->failed();

            return true;
        }

        $this->outage->recovered();
        $confident = $answer->confidence !== null && $answer->confidence >= $set->threshold;
        $this->save($message, $summary, $set, $answer->type, $answer->confidence, DecidedBy::Gateway, $confident, $answer->decisionId);

        return true;
    }

    /**
     * The label goes first: if it fails nothing is recorded, so the message is
     * typed again rather than left typed with no label and no event.
     */
    private function save(Message $message, MessageSummary $summary, TypeSet $set, string $type, ?float $confidence, DecidedBy $decidedBy, bool $confident, ?string $decisionId): void
    {
        $label = $confident ? $set->labelFor($type) : null;

        // A label the message already carries would never echo in the history.
        if ($label !== null && ! in_array($label, $summary->labels, true)) {
            $this->mailroom->changeLabels($message->ref(), [$label], []);
        }

        $typing = Typing::query()->create([
            'message_id' => $message->id,
            'type_set' => $set->key,
            'type_set_version' => $set->version,
            'type' => $type,
            'confidence' => $confidence,
            'decided_by' => $decidedBy,
            'confident' => $confident,
            'decision_id' => $decisionId,
        ]);

        MessageTyped::dispatch($message->ref(), $set->key, $type, $confidence, $decidedBy, $confident, $typing->id);
    }

    private function find(Mailbox $mailbox, string $providerMessageId): ?Message
    {
        return Message::query()
            ->where('mailbox_id', $mailbox->id)
            ->where('provider_message_id', $providerMessageId)
            ->first();
    }

    /**
     * @return list<TypeSet>
     */
    private function setsWanting(Mailbox $mailbox, ?Message $message): array
    {
        $sets = $this->typeSets->forMailbox($mailbox->address);

        if (! $message instanceof Message) {
            return $sets;
        }

        return array_values(array_filter($sets, fn (TypeSet $set): bool => ! $message->currentTyping($set->key) instanceof Typing));
    }

    private function summary(Mailbox $mailbox, string $providerMessageId): MessageSummary
    {
        return $this->providers->for($mailbox)->summary($mailbox, $providerMessageId, (int) config('mailroom.typing.text_limit'));
    }

    private function store(Mailbox $mailbox, MessageSummary $summary): Message
    {
        return Message::query()->updateOrCreate(
            ['mailbox_id' => $mailbox->id, 'provider_message_id' => $summary->providerMessageId],
            [
                'thread_id' => $summary->threadId,
                'received_at' => $summary->receivedAt,
                'from' => $summary->from,
                'subject' => $summary->subject,
            ],
        );
    }

    private function state(MessageSummary $summary): string
    {
        $pdfs = array_filter($summary->attachmentNames, fn (string $name): bool => str_ends_with(strtolower($name), '.pdf'));

        return "From: {$summary->from}\n"
            ."Subject: {$summary->subject}\n"
            ."Date: {$summary->receivedAt->toRfc2822String()}\n"
            .'PDF attachments: '.($pdfs === [] ? 'none' : implode(', ', $pdfs))."\n\n"
            ."Body:\n{$summary->text}";
    }
}
