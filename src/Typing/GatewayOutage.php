<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Typing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use RobinsonRyan\Mailroom\Events\GatewayStuck;

/**
 * Remembers when the decision gateway started failing, across ticks, and says
 * so once when that has gone on too long.
 */
final class GatewayOutage
{
    private const SINCE = 'mailroom:gateway:outage-since';

    private const ANNOUNCED = 'mailroom:gateway:outage-announced';

    public function failed(): void
    {
        $now = CarbonImmutable::now();
        Cache::add(self::SINCE, $now->toIso8601String());
        $since = CarbonImmutable::parse((string) Cache::get(self::SINCE));

        if ($since->addMinutes((int) config('mailroom.typing.unreachable_after'))->greaterThan($now)) {
            return;
        }

        if (Cache::add(self::ANNOUNCED, true)) {
            GatewayStuck::dispatch($since);
        }
    }

    public function recovered(): void
    {
        Cache::forget(self::SINCE);
        Cache::forget(self::ANNOUNCED);
    }
}
