<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Enums;

enum MailboxStatus: string
{
    /** Watched on every tick. */
    case Active = 'active';

    /** The provider refused its credentials (revoked, password change, idle); not watched until reconnected. */
    case Disconnected = 'disconnected';

    /** Turned off by a person; not watched. */
    case Paused = 'paused';
}
