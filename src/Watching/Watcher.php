<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Watching;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RobinsonRyan\Mailroom\Data\HistoryChange;
use RobinsonRyan\Mailroom\Enums\ChangeKind;
use RobinsonRyan\Mailroom\Enums\MailboxStatus;
use RobinsonRyan\Mailroom\Events\MailboxLostAccess;
use RobinsonRyan\Mailroom\Exceptions\CursorExpired;
use RobinsonRyan\Mailroom\Exceptions\MailboxDisconnected;
use RobinsonRyan\Mailroom\Exceptions\MessageNotFound;
use RobinsonRyan\Mailroom\Exceptions\MessageUnreadable;
use RobinsonRyan\Mailroom\Models\Mailbox;
use RobinsonRyan\Mailroom\ProviderRegistry;
use RobinsonRyan\Mailroom\Typing\Typist;

/**
 * One tick for one mailbox: read the history from the stored cursor, handle
 * each change, store the new cursor, then work the retry queue. Handling must
 * be safe to repeat: a tick that fails keeps the old cursor and the next one
 * replays the same changes.
 */
final readonly class Watcher
{
    public const RETRIES_PER_TICK = 25;

    /**
     * Long enough for a full resync, so a slow tick is never overlapped. A
     * killed tick holds its mailbox this long, which only delays typing.
     */
    private const LOCK_SECONDS = 3600;

    public function __construct(
        private ProviderRegistry $providers,
        private Typist $typist,
        private LabelChanges $labelChanges,
    ) {}

    public static function lockName(Mailbox $mailbox): string
    {
        return "mailroom:watch:{$mailbox->id}";
    }

    public function tick(Mailbox $mailbox): void
    {
        $lock = Cache::lock(self::lockName($mailbox), self::LOCK_SECONDS);

        if (! $lock->get()) {
            return;
        }

        try {
            // The tick that held the lock may have moved the cursor or lost the mailbox.
            $mailbox->refresh();

            if ($mailbox->isWatched()) {
                $this->poll($mailbox);
            }
        } catch (MailboxDisconnected) {
            $mailbox->update(['status' => MailboxStatus::Disconnected, 'disconnected_at' => CarbonImmutable::now()]);
            MailboxLostAccess::dispatch($mailbox);
        } finally {
            $lock->release();
        }
    }

    private function poll(Mailbox $mailbox): void
    {
        $provider = $this->providers->for($mailbox);

        if ($mailbox->cursor === null) {
            $this->storeCursor($mailbox, $provider->currentCursor($mailbox));

            return;
        }

        $askGateway = true;

        try {
            $page = $provider->history($mailbox, $mailbox->cursor);
            $cursor = $page->cursor;

            foreach ($page->changes as $change) {
                $askGateway = $this->handle($mailbox, $change, $askGateway);
            }
        } catch (CursorExpired) {
            // Taken before the search: anything that lands during the resync,
            // and the echoes of its own labels, arrive through the next history.
            $cursor = $provider->currentCursor($mailbox);
            $askGateway = $this->resync($mailbox);
        }

        $this->storeCursor($mailbox, $cursor);

        if ($askGateway) {
            $this->retryUntyped($mailbox);
        }
    }

    private function handle(Mailbox $mailbox, HistoryChange $change, bool $askGateway): bool
    {
        try {
            if ($change->kind === ChangeKind::MessageAdded) {
                return $this->typist->type($mailbox, $change->providerMessageId, $askGateway);
            }

            $this->labelChanges->handle($mailbox, $change);
        } catch (MessageNotFound) {
            // Deleted before it could be read. Nothing to do, and retrying would
            // pin the cursor here for good.
        } catch (MessageUnreadable $e) {
            // One bad message must not stop the mailbox.
            Log::warning('mailroom: skipping an unreadable message', ['message' => $change->providerMessageId, 'error' => $e->getMessage()]);
        }

        return $askGateway;
    }

    private function resync(Mailbox $mailbox): bool
    {
        $days = (int) config('mailroom.watch.resync_days');
        $askGateway = true;

        foreach ($this->providers->for($mailbox)->search($mailbox, "newer_than:{$days}d -in:drafts -in:spam -in:trash") as $id) {
            try {
                $askGateway = $this->typist->type($mailbox, $id, $askGateway);
            } catch (MessageNotFound) {
                continue;
            } catch (MessageUnreadable $e) {
                Log::warning('mailroom: skipping an unreadable message', ['message' => $id, 'error' => $e->getMessage()]);
            }
        }

        return $askGateway;
    }

    private function retryUntyped(Mailbox $mailbox): void
    {
        foreach ($this->typist->untyped($mailbox, self::RETRIES_PER_TICK) as $message) {
            try {
                if (! $this->typist->type($mailbox, $message->provider_message_id)) {
                    return;
                }
            } catch (MessageNotFound|MessageUnreadable) {
                // Gone while it waited. Typings from other sets are worth keeping.
                if ($message->typings()->doesntExist()) {
                    $message->delete();
                }
            }
        }
    }

    private function storeCursor(Mailbox $mailbox, string $cursor): void
    {
        $mailbox->update(['cursor' => $cursor, 'last_polled_at' => CarbonImmutable::now()]);
    }
}
