<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Exceptions;

use RuntimeException;

/**
 * Mailroom writes labels only inside a registered type set's prefix.
 */
final class LabelOutsidePrefix extends RuntimeException {}
