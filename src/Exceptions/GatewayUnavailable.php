<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Exceptions;

use RuntimeException;

/**
 * The decision gateway could not be reached or refused the request.
 */
final class GatewayUnavailable extends RuntimeException {}
