<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Exceptions;

use RuntimeException;

/**
 * The provider permanently refused one message (a 4xx that isn't about the
 * mailbox's credentials). Retrying won't help and it must not stop the mailbox.
 */
final class MessageUnreadable extends RuntimeException {}
