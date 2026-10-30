<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RobinsonRyan\Mailroom\Data\MessageRef;
use RobinsonRyan\Mailroom\Enums\DecidedBy;
use RobinsonRyan\Mailroom\Enums\DeliveryStatus;
use RobinsonRyan\Mailroom\Events\MessageTyped;
use RobinsonRyan\Mailroom\Mailroom;
use RobinsonRyan\Mailroom\Models\WebhookDelivery;
use RobinsonRyan\Mailroom\Models\WebhookSubscription;
use RobinsonRyan\Mailroom\Testing\FakeProvider;
use RobinsonRyan\Mailroom\Tests\Feature\Typing\Support\FakeGateway;
use RobinsonRyan\Mailroom\Tests\Feature\Typing\Support\InvoiceSet;
use RobinsonRyan\Mailroom\Typing\Typist;
use RobinsonRyan\Mailroom\Webhooks\DeliverWebhook;
use RobinsonRyan\Mailroom\Webhooks\Signature;

beforeEach(function (): void {
    // The subscription secret is an encrypted cast.
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
});

function subscription(string $typeSet = 'invoices', bool $active = true, string $url = 'https://receiver.test/hook'): WebhookSubscription
{
    return WebhookSubscription::query()->create(['type_set' => $typeSet, 'url' => $url, 'secret' => 'shh', 'active' => $active]);
}

function typedEvent(string $typeSet = 'invoices'): MessageTyped
{
    return new MessageTyped(
        new MessageRef('019a0000-0000-7000-8000-000000000001', 'm1', 't1', Carbon\CarbonImmutable::parse('2026-10-30 12:00:00', 'UTC')),
        $typeSet,
        'invoice',
        0.91,
        DecidedBy::Gateway,
        true,
        '019a0000-0000-7000-8000-000000000002',
    );
}

function pendingDelivery(?WebhookSubscription $subscription = null): WebhookDelivery
{
    return ($subscription ?? subscription())->deliveries()->create([
        'payload' => typedEvent()->toPayload(),
        'status' => DeliveryStatus::Pending,
        'next_attempt_at' => Carbon\CarbonImmutable::now(),
    ]);
}

it('creates one queued delivery per active subscription of that set', function (): void {
    Queue::fake();
    $wanted = subscription();
    $second = subscription(url: 'https://second.test/hook');
    subscription(active: false);
    subscription('legal');

    event(typedEvent());

    expect(WebhookDelivery::query()->pluck('subscription_id')->sort()->values()->all())
        ->toBe(collect([$wanted->id, $second->id])->sort()->values()->all());

    $delivery = WebhookDelivery::query()->where('subscription_id', $wanted->id)->sole();
    expect($delivery->status)->toBe(DeliveryStatus::Pending)
        ->and($delivery->attempts)->toBe(0)
        ->and($delivery->payload)->toEqual(typedEvent()->toPayload());

    Queue::assertPushed(DeliverWebhook::class, 2);
});

it('queues a delivery when the watcher types a message', function (): void {
    Queue::fake();
    $fake = FakeProvider::install();
    FakeGateway::install();
    InvoiceSet::register();
    subscription();
    $mailbox = app(Mailroom::class)->connect('info@example.com', 'afwd-info');

    app(Typist::class)->type($mailbox, $fake->deliver('info@example.com'));

    expect(WebhookDelivery::query()->sole()->payload['type'])->toBe('invoice');
    Queue::assertPushed(DeliverWebhook::class, 1);
});

it('posts the JSON payload signed over the exact bytes sent', function (): void {
    Http::fake(['receiver.test/*' => Http::response('ok', 200)]);
    $delivery = pendingDelivery();

    app()->call([new DeliverWebhook($delivery->id), 'handle']);

    Http::assertSent(function (Request $request): bool {
        expect($request->method())->toBe('POST')
            ->and($request->url())->toBe('https://receiver.test/hook')
            ->and($request->header('Content-Type'))->toBe(['application/json'])
            ->and(json_decode($request->body(), true)['message_ref']['provider_message_id'])->toBe('m1')
            ->and(Signature::verify($request->header('X-Mailroom-Signature')[0], $request->body(), 'shh'))->toBeTrue();

        return true;
    });

    $delivery->refresh();
    expect($delivery->status)->toBe(DeliveryStatus::Delivered)
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->last_status)->toBe(200)
        ->and($delivery->delivered_at)->not->toBeNull()
        ->and($delivery->next_attempt_at)->toBeNull();
});

it('retries after 60 seconds, doubling, and requeues itself for that time', function (): void {
    Queue::fake();
    Http::fake(['*' => Http::response('nope', 500)]);
    $this->freezeSecond();
    $delivery = pendingDelivery();
    $send = fn () => app()->call([new DeliverWebhook($delivery->id), 'handle']);

    $send();
    $delivery->refresh();
    expect($delivery->status)->toBe(DeliveryStatus::Pending)
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->last_status)->toBe(500)
        ->and($delivery->last_error)->toBe('HTTP 500')
        ->and($delivery->next_attempt_at?->getTimestamp())->toBe(Carbon\CarbonImmutable::now()->addSeconds(60)->getTimestamp());

    Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job): bool => $job->deliveryId === $delivery->id
        && Carbon\CarbonImmutable::instance($job->delay)->getTimestamp() === Carbon\CarbonImmutable::now()->addSeconds(60)->getTimestamp());

    $this->travel(60)->seconds();
    $send();
    expect($delivery->refresh()->next_attempt_at?->getTimestamp())->toBe(Carbon\CarbonImmutable::now()->addSeconds(120)->getTimestamp());

    $this->travel(120)->seconds();
    $send();
    expect($delivery->refresh()->next_attempt_at?->getTimestamp())->toBe(Carbon\CarbonImmutable::now()->addSeconds(240)->getTimestamp());
});

it('treats a redirect as a failure rather than following it', function (): void {
    Queue::fake();
    Http::fake(['*' => Http::response('', 302, ['Location' => 'https://elsewhere.test/'])]);
    $delivery = pendingDelivery();

    app()->call([new DeliverWebhook($delivery->id), 'handle']);

    expect($delivery->refresh()->status)->toBe(DeliveryStatus::Pending)
        ->and($delivery->last_status)->toBe(302);
});

it('treats an unreachable receiver as a failure', function (): void {
    Queue::fake();
    Http::fake(fn () => throw new ConnectionException('Could not resolve host'));
    $delivery = pendingDelivery();

    app()->call([new DeliverWebhook($delivery->id), 'handle']);

    expect($delivery->refresh()->status)->toBe(DeliveryStatus::Pending)
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->last_status)->toBeNull()
        ->and($delivery->last_error)->toBe('Connection failed');
});

it('goes dead once the next retry would fall past 24 hours, keeping the retry schedule until then', function (): void {
    Queue::fake();
    Http::fake(['*' => Http::response('nope', 503)]);
    $this->freezeSecond();
    $delivery = pendingDelivery();
    $start = Carbon\CarbonImmutable::now();
    $gaps = [];

    while ($delivery->refresh()->status === DeliveryStatus::Pending) {
        $before = Carbon\CarbonImmutable::now();
        app()->call([new DeliverWebhook($delivery->id), 'handle']);
        $next = $delivery->refresh()->next_attempt_at;

        if ($next === null) {
            break;
        }

        $gaps[] = $before->diffInSeconds($next);
        $this->travelTo($next);
    }

    expect($gaps)->toBe([60.0, 120.0, 240.0, 480.0, 960.0, 1920.0, 3840.0, 7680.0, 15360.0, 30720.0])
        ->and($delivery->status)->toBe(DeliveryStatus::Dead)
        ->and($delivery->attempts)->toBe(11)
        ->and($delivery->next_attempt_at)->toBeNull()
        ->and(Carbon\CarbonImmutable::now()->lessThan($start->addHours(24)))->toBeTrue();
});

it('marks dead rather than retrying when an attempt fails after the 24 hours are up', function (): void {
    Queue::fake();
    Http::fake(['*' => Http::response('nope', 500)]);
    $delivery = pendingDelivery();
    $this->travel(25)->hours();

    app()->call([new DeliverWebhook($delivery->id), 'handle']);

    expect($delivery->refresh()->status)->toBe(DeliveryStatus::Dead);
    Queue::assertNotPushed(DeliverWebhook::class);
});

it('does nothing for a delivery that is not due, is delivered or is dead', function (): void {
    Http::fake();
    $future = pendingDelivery();
    $future->update(['next_attempt_at' => Carbon\CarbonImmutable::now()->addHour()]);
    $done = pendingDelivery();
    $done->update(['status' => DeliveryStatus::Delivered]);
    $dead = pendingDelivery();
    $dead->update(['status' => DeliveryStatus::Dead]);

    foreach ([$future, $done, $dead] as $delivery) {
        app()->call([new DeliverWebhook($delivery->id), 'handle']);
    }

    Http::assertNothingSent();
});

it('sends a delivery once when the job and the sweep both reach it', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    $delivery = pendingDelivery();

    $this->artisan('mailroom:webhooks')->assertSuccessful();
    app()->call([new DeliverWebhook($delivery->id), 'handle']);

    Http::assertSentCount(1);
});

it('holds a delivery being sent so an overlapping sweep leaves it alone', function (): void {
    $delivery = pendingDelivery();
    $swept = false;
    Http::fake(function () use (&$swept) {
        if (! $swept) {
            $swept = true;
            $this->artisan('mailroom:webhooks')->assertSuccessful();
        }

        return Http::response('ok', 200);
    });

    app()->call([new DeliverWebhook($delivery->id), 'handle']);

    Http::assertSentCount(1);
    expect($delivery->refresh()->attempts)->toBe(1);
});

it('sweeps due deliveries so a lost job recovers, leaving the rest alone', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    $due = pendingDelivery();
    $later = pendingDelivery();
    $later->update(['next_attempt_at' => Carbon\CarbonImmutable::now()->addHour()]);

    $this->artisan('mailroom:webhooks')->assertSuccessful();

    expect($due->refresh()->status)->toBe(DeliveryStatus::Delivered)
        ->and($later->refresh()->status)->toBe(DeliveryStatus::Pending);
    Http::assertSentCount(1);
});

it('recovers a delivery abandoned mid-send once its hold runs out', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    $delivery = pendingDelivery();
    $delivery->update(['next_attempt_at' => Carbon\CarbonImmutable::now()->addSeconds(70)]);

    $this->artisan('mailroom:webhooks')->assertSuccessful();
    Http::assertNothingSent();

    $this->travel(71)->seconds();
    $this->artisan('mailroom:webhooks')->assertSuccessful();

    expect($delivery->refresh()->status)->toBe(DeliveryStatus::Delivered);
});
