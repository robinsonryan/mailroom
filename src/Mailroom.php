<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom;

use RobinsonRyan\Mailroom\Contracts\BodyPdf;
use RobinsonRyan\Mailroom\Contracts\Corrections;
use RobinsonRyan\Mailroom\Data\MessageContent;
use RobinsonRyan\Mailroom\Data\MessageRef;
use RobinsonRyan\Mailroom\Data\OutgoingMessage;
use RobinsonRyan\Mailroom\Enums\LabelAction;
use RobinsonRyan\Mailroom\Enums\MailboxStatus;
use RobinsonRyan\Mailroom\Exceptions\LabelOutsidePrefix;
use RobinsonRyan\Mailroom\Models\LabelWrite;
use RobinsonRyan\Mailroom\Models\Mailbox;
use RobinsonRyan\Mailroom\Models\Message;
use RobinsonRyan\Mailroom\Models\Typing;
use RobinsonRyan\Mailroom\TypeSets\TypeSetRegistry;
use Throwable;

/**
 * The consumer-facing surface (Contract A operations). Everything here takes a
 * MessageRef and delegates; watching and typing run in mailroom:watch.
 */
final readonly class Mailroom
{
    public function __construct(
        private ProviderRegistry $providers,
        private TypeSetRegistry $typeSets,
        private BodyPdf $bodyPdf,
        private Corrections $corrections,
    ) {}

    /**
     * Register (or update) a mailbox. Watching starts from "now" on the next tick.
     */
    public function connect(string $address, string $identity, string $provider = 'gmail'): Mailbox
    {
        $mailbox = Mailbox::query()->firstOrNew(['address' => strtolower($address)]);
        $mailbox->fill(['provider' => $provider, 'identity' => $identity, 'status' => MailboxStatus::Active, 'disconnected_at' => null]);
        $mailbox->save();

        return $mailbox;
    }

    public function mailbox(string $address): Mailbox
    {
        return Mailbox::query()->where('address', strtolower($address))->firstOrFail();
    }

    public function message(MessageRef $ref): MessageContent
    {
        $mailbox = $this->mailboxFor($ref);

        return $this->providers->for($mailbox)->content($mailbox, $ref->providerMessageId);
    }

    /** An attachment's raw bytes. */
    public function attachment(MessageRef $ref, string $partId): string
    {
        $mailbox = $this->mailboxFor($ref);

        return $this->providers->for($mailbox)->attachment($mailbox, $ref->providerMessageId, $partId);
    }

    /** The body rendered to PDF (header block on top, PDF date = message date). */
    public function asPdf(MessageRef $ref): string
    {
        return $this->bodyPdf->render($this->message($ref));
    }

    /**
     * @throws LabelOutsidePrefix
     */
    public function label(MessageRef $ref, string $name): void
    {
        $this->changeLabels($ref, [$name], []);
    }

    /**
     * @throws LabelOutsidePrefix
     */
    public function unlabel(MessageRef $ref, string $name): void
    {
        $this->changeLabels($ref, [], [$name]);
    }

    /**
     * Add and remove labels in one call; every name must sit inside a
     * registered type set's prefix. Each change is recorded so the watcher
     * does not mistake its echo for a person's correction.
     *
     * @param  list<string>  $add
     * @param  list<string>  $remove
     *
     * @throws LabelOutsidePrefix
     */
    public function changeLabels(MessageRef $ref, array $add, array $remove): void
    {
        foreach ([...$add, ...$remove] as $name) {
            $this->assertInsidePrefix($name);
        }

        if ($add === [] && $remove === []) {
            return;
        }

        $mailbox = $this->mailboxFor($ref);
        $message = $this->record($ref);

        // Recorded before the call so a fast watcher tick can't see the echo first;
        // dropped if the call fails, or an unechoed row would swallow a person's change.
        $writes = [];

        foreach ($add as $name) {
            $writes[] = LabelWrite::query()->create(['message_id' => $message->id, 'label' => $name, 'action' => LabelAction::Add]);
        }

        foreach ($remove as $name) {
            $writes[] = LabelWrite::query()->create(['message_id' => $message->id, 'label' => $name, 'action' => LabelAction::Remove]);
        }

        try {
            $this->providers->for($mailbox)->changeLabels($mailbox, $ref->providerMessageId, $add, $remove);
        } catch (Throwable $e) {
            foreach ($writes as $write) {
                $write->delete();
            }

            throw $e;
        }
    }

    public function archive(MessageRef $ref): void
    {
        $mailbox = $this->mailboxFor($ref);

        $this->providers->for($mailbox)->archive($mailbox, $ref->providerMessageId);
    }

    /**
     * @param  list<string>  $labels
     *
     * @throws LabelOutsidePrefix
     */
    public function insert(Mailbox $mailbox, string $rawMime, array $labels = []): string
    {
        foreach ($labels as $name) {
            $this->assertInsidePrefix($name);
        }

        return $this->providers->for($mailbox)->insert($mailbox, $rawMime, $labels);
    }

    public function draft(Mailbox $mailbox, OutgoingMessage $message): string
    {
        return $this->providers->for($mailbox)->draft($mailbox, $message);
    }

    public function send(Mailbox $mailbox, OutgoingMessage $message): string
    {
        return $this->providers->for($mailbox)->send($mailbox, $message);
    }

    /** A person's typing of a message (Contract A's correct). */
    public function correct(MessageRef $ref, string $typeSet, string $type): Typing
    {
        return $this->corrections->correct($ref, $typeSet, $type);
    }

    /** The message row for a ref, created when Mailroom has not seen it yet. */
    public function record(MessageRef $ref): Message
    {
        return Message::query()->firstOrCreate(
            ['mailbox_id' => $ref->mailboxId, 'provider_message_id' => $ref->providerMessageId],
            ['thread_id' => $ref->threadId, 'received_at' => $ref->receivedAt],
        );
    }

    private function mailboxFor(MessageRef $ref): Mailbox
    {
        return Mailbox::query()->findOrFail($ref->mailboxId);
    }

    private function assertInsidePrefix(string $name): void
    {
        if (! $this->typeSets->owning($name) instanceof TypeSets\TypeSet) {
            throw new LabelOutsidePrefix("Label {$name} is outside every registered type set's prefix.");
        }
    }
}
