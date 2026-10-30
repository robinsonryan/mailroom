<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Webhooks;

use Carbon\CarbonImmutable;
use RobinsonRyan\Mailroom\Enums\DeliveryStatus;
use RobinsonRyan\Mailroom\Events\MessageTyped;
use RobinsonRyan\Mailroom\Models\WebhookDelivery;
use RobinsonRyan\Mailroom\Models\WebhookSubscription;

/**
 * Owes one delivery to each active subscription of the event's type set.
 */
final class QueueDeliveries
{
    public function handle(MessageTyped $event): void
    {
        $subscriptions = WebhookSubscription::query()
            ->where('type_set', $event->typeSet)
            ->where('active', true)
            ->get();

        foreach ($subscriptions as $subscription) {
            $delivery = WebhookDelivery::query()->create([
                'subscription_id' => $subscription->id,
                'payload' => $event->toPayload(),
                'status' => DeliveryStatus::Pending,
                'next_attempt_at' => CarbonImmutable::now(),
            ]);

            DeliverWebhook::dispatch($delivery->id);
        }
    }
}
