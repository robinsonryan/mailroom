<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Console;

use Illuminate\Console\Command;
use RobinsonRyan\Mailroom\Enums\MailboxStatus;
use RobinsonRyan\Mailroom\Models\Mailbox;
use RobinsonRyan\Mailroom\Watching\Watcher;
use Throwable;

final class WatchCommand extends Command
{
    protected $signature = 'mailroom:watch {--mailbox= : Watch only this address}';

    protected $description = 'Run one watcher tick for every active mailbox';

    public function handle(Watcher $watcher): int
    {
        $mailboxes = Mailbox::query()->where('status', MailboxStatus::Active)->orderBy('address');
        $address = $this->option('mailbox');

        if (is_string($address)) {
            $mailboxes->where('address', strtolower($address));
        }

        $exitCode = self::SUCCESS;

        foreach ($mailboxes->get() as $mailbox) {
            try {
                $watcher->tick($mailbox);
            } catch (Throwable $e) {
                // One mailbox's trouble is never another's: report it and carry on.
                report($e);
                $exitCode = self::FAILURE;
            }
        }

        return $exitCode;
    }
}
