<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RobinsonRyan\Mailroom\Events\GatewayStuck;
use RobinsonRyan\Mailroom\Typing\GatewayOutage;

beforeEach(function (): void {
    config(['mailroom.typing.unreachable_after' => 30]);
    Event::fake([GatewayStuck::class]);
    $this->outage = app(GatewayOutage::class);
});

it('stays quiet until the outage has lasted the configured minutes', function (): void {
    $this->outage->failed();
    $this->travel(29)->minutes();
    $this->outage->failed();

    Event::assertNotDispatched(GatewayStuck::class);
});

it('fires once per outage, naming when it began', function (): void {
    $start = Carbon\CarbonImmutable::now()->startOfSecond();
    $this->travelTo($start);
    $this->outage->failed();

    $this->travel(30)->minutes();
    $this->outage->failed();
    $this->travel(5)->minutes();
    $this->outage->failed();

    Event::assertDispatchedTimes(GatewayStuck::class, 1);
    Event::assertDispatched(GatewayStuck::class, fn (GatewayStuck $e): bool => $e->since->equalTo($start));
});

it('starts a new outage after a success', function (): void {
    $this->outage->failed();
    $this->travel(31)->minutes();
    $this->outage->failed();
    $this->outage->recovered();

    $this->outage->failed();
    $this->travel(29)->minutes();
    $this->outage->failed();
    Event::assertDispatchedTimes(GatewayStuck::class, 1);

    $this->travel(1)->minutes();
    $this->outage->failed();
    Event::assertDispatchedTimes(GatewayStuck::class, 2);
});
