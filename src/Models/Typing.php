<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;
use RobinsonRyan\Mailroom\Enums\DecidedBy;
use RobinsonRyan\Mailroom\Models\Concerns\MailroomModel;

/**
 * One recorded answer for one message in one type set. A correction writes a
 * new typing and points the old one's superseded_by_id at it.
 *
 * @property string $id
 * @property string $message_id
 * @property string $type_set
 * @property int $type_set_version
 * @property string $type
 * @property float|null $confidence
 * @property DecidedBy $decided_by
 * @property bool $confident
 * @property string|null $decision_id
 * @property string|null $superseded_by_id
 * @property-read Message $message
 */
final class Typing extends Model
{
    use MailroomModel;

    protected $fillable = ['message_id', 'type_set', 'type_set_version', 'type', 'confidence', 'decided_by', 'confident', 'decision_id', 'superseded_by_id'];

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    protected function baseTable(): string
    {
        return 'typings';
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'decided_by' => DecidedBy::class,
            'confident' => 'boolean',
            'confidence' => 'float',
            'type_set_version' => 'integer',
        ];
    }
}
