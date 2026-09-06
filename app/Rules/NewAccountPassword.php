<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Applies only when setting a password; existing credentials remain usable. */
class NewAccountPassword implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || mb_strlen($value) < 8) {
            $fail('The password must be at least 8 characters.');
        } elseif (strlen($value) > 72) {
            $fail('The password must be at most 72 UTF-8 bytes.');
        } elseif (str_contains($value, "\0")) {
            $fail('The password must not contain null characters.');
        }
    }
}
