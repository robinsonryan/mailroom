<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * UUID v7 keys (minted here so the model knows its id before the insert; the
 * column's uuidv7() default is the backstop) and the configurable table prefix.
 *
 * @mixin Model
 */
trait MailroomModel
{
    public static function bootMailroomModel(): void
    {
        static::creating(function (Model $model): void {
            if ($model->getKey() === null) {
                $model->setAttribute($model->getKeyName(), (string) Str::uuid7());
            }
        });
    }

    public function getIncrementing(): bool
    {
        return false;
    }

    public function getKeyType(): string
    {
        return 'string';
    }

    public function getTable(): string
    {
        return config('mailroom.table_prefix', 'mailroom_').$this->baseTable();
    }

    abstract protected function baseTable(): string;
}
