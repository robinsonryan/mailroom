<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;
use RobinsonRyan\Mailroom\Enums\MailboxStatus;
use RobinsonRyan\Mailroom\Models\Concerns\MailroomModel;

/**
 * One connected mail account (Contract A's Mailbox).
 *
 * @property string $id
 * @property string $address
 * @property string $provider
 * @property string $identity
 * @property MailboxStatus $status
 * @property string|null $cursor
 * @property CarbonImmutable|null $last_polled_at
 * @property CarbonImmutable|null $disconnected_at
 */
final class Mailbox extends Model
{
    use MailroomModel;

    protected $fillable = ['address', 'provider', 'identity', 'status', 'cursor', 'last_polled_at', 'disconnected_at', 'owner_type', 'owner_id'];

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function isWatched(): bool
    {
        return $this->status === MailboxStatus::Active;
    }

    protected function baseTable(): string
    {
        return 'mailboxes';
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'status' => MailboxStatus::class,
            'last_polled_at' => 'immutable_datetime',
            'disconnected_at' => 'immutable_datetime',
        ];
    }
}
