<?php

namespace App\Models\Concerns;

trait HasSafeAutomationError
{
    public function attributesToArray(): array
    {
        $attributes = parent::attributesToArray();
        if (array_key_exists('error_message', $attributes)) {
            $attributes['error_message'] = \App\Services\ApiErrorResponder::storedFailure($attributes['error_message'], 'Automation could not complete. Review its configuration and try again.');
        }
        return $attributes;
    }
}
