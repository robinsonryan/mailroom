<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Webhooks;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use RobinsonRyan\Mailroom\Enums\DeliveryStatus;
use RobinsonRyan\Mailroom\Models\WebhookDelivery;

/**
 * Makes one attempt at a due delivery and settles it: delivered, due again
 * later, or dead. Both the queued job and mailroom:webhooks come through here.
 */
final readonly class WebhookSender
{
    /** Added to the HTTP timeout: how long a claimed delivery is left alone. */
    private const CLAIM_MARGIN_SECONDS = 60;

    public function __construct(private Factory $http) {}

    public function send(string $deliveryId): void
    {
        if (! $this->claim($deliveryId)) {
            return;
        }

        $delivery = WebhookDelivery::query()->with('subscription')->findOrFail($deliveryId);
        $body = json_encode($delivery->payload, JSON_THROW_ON_ERROR);
        $now = CarbonImmutable::now()->getTimestamp();

        try {
            $response = $this->http
                ->timeout((int) config('mailroom.webhooks.timeout'))
                ->withoutRedirecting()
                ->withHeaders([Signature::HEADER => Signature::header($body, $delivery->subscription->secret, $now)])
                ->withBody($body, 'application/json')
                ->post($delivery->subscription->url);
        } catch (ConnectionException) {
            // Not the exception's message: it quotes the URL, which may hold a token.
            $this->failed($delivery, null, 'Connection failed');

            return;
        }

        if ($response->successful()) {
            $delivery->update([
                'status' => DeliveryStatus::Delivered,
                'attempts' => $delivery->attempts + 1,
                'last_status' => $response->status(),
                'last_error' => null,
                'next_attempt_at' => null,
                'delivered_at' => CarbonImmutable::now(),
            ]);

            return;
        }

        $this->failed($delivery, $response->status(), "HTTP {$response->status()}");
    }

    /**
     * Atomically pushes the due time out, so the job and the sweep cannot both
     * send it, and a sender that dies leaves it to be swept up later.
     */
    private function claim(string $deliveryId): bool
    {
        $hold = (int) config('mailroom.webhooks.timeout') + self::CLAIM_MARGIN_SECONDS;

        return WebhookDelivery::query()
            ->whereKey($deliveryId)
            ->where('status', DeliveryStatus::Pending)
            ->where('next_attempt_at', '<=', CarbonImmutable::now())
            ->update(['next_attempt_at' => CarbonImmutable::now()->addSeconds($hold)]) === 1;
    }

    /**
     * The give-up clock runs from when the delivery was created, which is when
     * its first attempt was queued; the table keeps no separate first-attempt time.
     */
    private function failed(WebhookDelivery $delivery, ?int $status, string $error): void
    {
        $attempts = $delivery->attempts + 1;
        $next = CarbonImmutable::now()->addSeconds((int) config('mailroom.webhooks.first_retry_seconds') * 2 ** ($attempts - 1));
        $deadline = CarbonImmutable::instance($delivery->created_at ?? CarbonImmutable::now())->addHours((int) config('mailroom.webhooks.give_up_after_hours'));
        $dead = $next->greaterThan($deadline);

        $delivery->update([
            'status' => $dead ? DeliveryStatus::Dead : DeliveryStatus::Pending,
            'attempts' => $attempts,
            'last_status' => $status,
            'last_error' => $error,
            'next_attempt_at' => $dead ? null : $next,
        ]);

        if (! $dead) {
            DeliverWebhook::dispatch($delivery->id)->delay($next);
        }
    }
}
