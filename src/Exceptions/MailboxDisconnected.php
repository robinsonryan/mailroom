<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Exceptions;

use RuntimeException;

/**
 * The provider refused the mailbox's credentials (revoked, password change, idle).
 */
final class MailboxDisconnected extends RuntimeException {}
