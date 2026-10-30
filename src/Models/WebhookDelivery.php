<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;
use RobinsonRyan\Mailroom\Enums\DeliveryStatus;
use RobinsonRyan\Mailroom\Models\Concerns\MailroomModel;

/**
 * One event owed to one subscription, retried with backoff until delivered
 * or dead.
 *
 * @property string $id
 * @property string $subscription_id
 * @property array<string, mixed> $payload
 * @property DeliveryStatus $status
 * @property int $attempts
 * @property CarbonImmutable|null $next_attempt_at
 * @property int|null $last_status
 * @property string|null $last_error
 * @property CarbonImmutable|null $delivered_at
 * @property-read WebhookSubscription $subscription
 */
final class WebhookDelivery extends Model
{
    use MailroomModel;

    protected $fillable = ['subscription_id', 'payload', 'status', 'attempts', 'next_attempt_at', 'last_status', 'last_error', 'delivered_at'];

    /** @return BelongsTo<WebhookSubscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(WebhookSubscription::class, 'subscription_id');
    }

    protected function baseTable(): string
    {
        return 'webhook_deliveries';
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => DeliveryStatus::class,
            'attempts' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
        ];
    }
}
