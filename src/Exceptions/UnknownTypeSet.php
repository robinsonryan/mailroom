<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Exceptions;

use RuntimeException;

/**
 * No type set is registered under that key, or it has no such type.
 */
final class UnknownTypeSet extends RuntimeException {}
