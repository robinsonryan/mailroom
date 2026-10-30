<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;
use RobinsonRyan\Mailroom\Data\MessageRef;
use RobinsonRyan\Mailroom\Models\Concerns\MailroomModel;

/**
 * A message Mailroom has seen. Metadata only: bodies are never stored.
 *
 * @property string $id
 * @property string $mailbox_id
 * @property string $provider_message_id
 * @property string $thread_id
 * @property CarbonImmutable $received_at
 * @property string $from
 * @property string $subject
 * @property-read Mailbox $mailbox
 */
final class Message extends Model
{
    use MailroomModel;

    protected $fillable = ['mailbox_id', 'provider_message_id', 'thread_id', 'received_at', 'from', 'subject'];

    /** @return BelongsTo<Mailbox, $this> */
    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    /** @return HasMany<Typing, $this> */
    public function typings(): HasMany
    {
        return $this->hasMany(Typing::class);
    }

    /** The current (not superseded) typing for a type set. */
    public function currentTyping(string $typeSet): ?Typing
    {
        return $this->typings()
            ->where('type_set', $typeSet)
            ->whereNull('superseded_by_id')
            ->latest('created_at')
            ->first();
    }

    public function ref(): MessageRef
    {
        return new MessageRef($this->mailbox_id, $this->provider_message_id, $this->thread_id, $this->received_at);
    }

    protected function baseTable(): string
    {
        return 'messages';
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return ['received_at' => 'immutable_datetime'];
    }
}
