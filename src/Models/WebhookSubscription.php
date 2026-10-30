<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;
use RobinsonRyan\Mailroom\Models\Concerns\MailroomModel;

/**
 * An HTTP consumer of one type set's MessageTyped events. $secret signs each
 * delivery (HMAC-SHA256); it is stored encrypted with the app key.
 *
 * @property string $id
 * @property string $type_set
 * @property string $url
 * @property string $secret
 * @property bool $active
 */
final class WebhookSubscription extends Model
{
    use MailroomModel;

    protected $fillable = ['type_set', 'url', 'secret', 'active', 'owner_type', 'owner_id'];

    protected $hidden = ['secret'];

    /** @return HasMany<WebhookDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'subscription_id');
    }

    protected function baseTable(): string
    {
        return 'webhook_subscriptions';
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return ['secret' => 'encrypted', 'active' => 'boolean'];
    }
}
