<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Exceptions;

use RuntimeException;

/**
 * The provider no longer holds history that old; resync.
 */
final class CursorExpired extends RuntimeException {}
