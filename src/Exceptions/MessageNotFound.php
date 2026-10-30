<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Exceptions;

use RuntimeException;

/**
 * The message is gone from the mailbox.
 */
final class MessageNotFound extends RuntimeException {}
