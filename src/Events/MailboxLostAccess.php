<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Events;

use Illuminate\Foundation\Events\Dispatchable;
use RobinsonRyan\Mailroom\Models\Mailbox;

/**
 * The provider refused a mailbox's credentials; watching has stopped for it
 * until someone reconnects it. Fired once per disconnection.
 */
final readonly class MailboxLostAccess
{
    use Dispatchable;

    public function __construct(public Mailbox $mailbox) {}
}
