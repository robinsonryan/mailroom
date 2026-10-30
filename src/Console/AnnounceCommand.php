<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Console;

use Illuminate\Console\Command;
use RobinsonRyan\Mailroom\Models\Typing;
use RobinsonRyan\Mailroom\Typing\TypingAnnouncer;

/**
 * The way back for a typing whose MessageTyped a listener failed: every
 * listener hears it again, so they must take a repeat (webhook receivers
 * dedupe on typing_id).
 */
final class AnnounceCommand extends Command
{
    protected $signature = 'mailroom:announce {typing : The typing_id from the failure in the log}';

    protected $description = 'Fire MessageTyped again for a typing a listener failed on';

    public function handle(TypingAnnouncer $announcer): int
    {
        $typing = Typing::query()->find($this->argument('typing'));

        if (! $typing instanceof Typing) {
            $this->error('No such typing.');

            return self::FAILURE;
        }

        if ($typing->superseded_by_id !== null) {
            $this->error('This typing has been replaced, so consumers already have the newer one.');

            return self::FAILURE;
        }

        return $announcer->announce($typing) ? self::SUCCESS : self::FAILURE;
    }
}
