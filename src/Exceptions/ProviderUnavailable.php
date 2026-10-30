<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Exceptions;

use RuntimeException;

/**
 * The provider kept failing after retries.
 */
final class ProviderUnavailable extends RuntimeException {}
