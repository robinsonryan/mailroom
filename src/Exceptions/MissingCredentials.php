<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Exceptions;

use RuntimeException;

/**
 * No credentials are available for the identity.
 */
final class MissingCredentials extends RuntimeException {}
