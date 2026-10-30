<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use RobinsonRyan\Mailroom\Enums\DeliveryStatus;
use RobinsonRyan\Mailroom\Models\WebhookDelivery;
use RobinsonRyan\Mailroom\Webhooks\WebhookSender;

/**
 * Sends what is due directly rather than queueing it: this is the recovery
 * path for when a queued job was lost.
 */
final class WebhooksCommand extends Command
{
    private const BATCH = 100;

    protected $signature = 'mailroom:webhooks';

    protected $description = 'Send webhook deliveries that are due';

    public function handle(WebhookSender $sender): int
    {
        WebhookDelivery::query()
            ->where('status', DeliveryStatus::Pending)
            ->where('next_attempt_at', '<=', CarbonImmutable::now())
            ->orderBy('next_attempt_at')
            ->limit(self::BATCH)
            ->pluck('id')
            ->each(fn (string $id) => $sender->send($id));

        return self::SUCCESS;
    }
}
