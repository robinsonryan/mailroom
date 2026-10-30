<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Events;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The decision gateway has been failing since $since (past the configured
 * `typing.unreachable_after`). Fired once per outage.
 */
final readonly class GatewayStuck
{
    use Dispatchable;

    public function __construct(public CarbonImmutable $since) {}
}
