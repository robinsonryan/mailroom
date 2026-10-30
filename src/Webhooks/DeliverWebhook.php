<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Webhooks;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * One attempt at one delivery. Retries are the sender's business (it queues
 * the next attempt itself); if this job is lost, mailroom:webhooks recovers it.
 */
final class DeliverWebhook implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $deliveryId) {}

    public function handle(WebhookSender $sender): void
    {
        $sender->send($this->deliveryId);
    }
}
