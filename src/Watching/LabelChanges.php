<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Watching;

use Carbon\CarbonImmutable;
use RobinsonRyan\Mailroom\Contracts\Corrections;
use RobinsonRyan\Mailroom\Data\HistoryChange;
use RobinsonRyan\Mailroom\Enums\ChangeKind;
use RobinsonRyan\Mailroom\Enums\LabelAction;
use RobinsonRyan\Mailroom\Exceptions\MessageNotFound;
use RobinsonRyan\Mailroom\Models\LabelWrite;
use RobinsonRyan\Mailroom\Models\Mailbox;
use RobinsonRyan\Mailroom\Models\Message;
use RobinsonRyan\Mailroom\TypeSets\TypeDefinition;
use RobinsonRyan\Mailroom\TypeSets\TypeSet;
use RobinsonRyan\Mailroom\TypeSets\TypeSetRegistry;
use RobinsonRyan\Mailroom\Typing\Typist;

/**
 * Reads label events from the history: Mailroom's own writes coming back as
 * echoes, and everything else inside a registered prefix as a person's doing.
 */
final readonly class LabelChanges
{
    private const ECHO_WINDOW_MINUTES = 60;

    public function __construct(
        private TypeSetRegistry $typeSets,
        private Corrections $corrections,
        private Typist $typist,
    ) {}

    /**
     * @throws MessageNotFound
     */
    public function handle(Mailbox $mailbox, HistoryChange $change): void
    {
        foreach ($change->labels as $label) {
            $set = $this->typeSets->owning($label);

            if (! $set instanceof TypeSet) {
                continue;
            }

            $message = Message::query()
                ->where('mailbox_id', $mailbox->id)
                ->where('provider_message_id', $change->providerMessageId)
                ->first();

            if ($message instanceof Message && $this->isEcho($message, $label, $change->kind)) {
                continue;
            }

            $type = $set->typeForLabel($label);

            // Removing a type label says nothing about what the message is;
            // only adding one does.
            if ($change->kind === ChangeKind::LabelAdded && $type instanceof TypeDefinition && $set->appliesTo($mailbox->address)) {
                $message ??= $this->typist->record($mailbox, $change->providerMessageId);
                $this->corrections->correct($message->ref(), $set->key, $type->key, fromLabel: true);
            }
        }
    }

    /**
     * Consumes the oldest matching write: each write echoes once. An echo is
     * back within a tick or two, so an older write is one whose echo is never
     * coming (a killed tick left it), and must not swallow a person's change.
     */
    private function isEcho(Message $message, string $label, ChangeKind $kind): bool
    {
        $write = LabelWrite::query()
            ->where('message_id', $message->id)
            ->where('label', $label)
            ->where('action', $kind === ChangeKind::LabelAdded ? LabelAction::Add : LabelAction::Remove)
            ->whereNull('echoed_at')
            ->where('created_at', '>=', CarbonImmutable::now()->subMinutes(self::ECHO_WINDOW_MINUTES))
            ->oldest()
            ->first();

        if (! $write instanceof LabelWrite) {
            return false;
        }

        $write->update(['echoed_at' => CarbonImmutable::now()]);

        return true;
    }
}
