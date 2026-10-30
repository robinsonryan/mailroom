<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Override;
use RobinsonRyan\Mailroom\Enums\LabelAction;
use RobinsonRyan\Mailroom\Models\Concerns\MailroomModel;

/**
 * A label change Mailroom made. When the history echoes it back, the watcher
 * sets echoed_at instead of reading it as a person's correction.
 *
 * @property string $id
 * @property string $message_id
 * @property string $label
 * @property LabelAction $action
 * @property CarbonImmutable|null $echoed_at
 */
final class LabelWrite extends Model
{
    use MailroomModel;

    protected $fillable = ['message_id', 'label', 'action', 'echoed_at'];

    protected function baseTable(): string
    {
        return 'label_writes';
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return ['action' => LabelAction::class, 'echoed_at' => 'immutable_datetime'];
    }
}
