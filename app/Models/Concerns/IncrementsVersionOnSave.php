<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;

trait IncrementsVersionOnSave
{
    protected static function bootIncrementsVersionOnSave(): void
    {
        static::saving(function (Model $model): void {
            if (! $model->exists) {
                $model->setAttribute('version', 1);

                return;
            }

            $model->setAttribute(
                'version',
                ((int) $model->getRawOriginal('version')) + 1
            );
        });
    }
}