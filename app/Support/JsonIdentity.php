<?php

namespace App\Support;

/** Compare decoded JSON objects without depending on database key ordering. */
final class JsonIdentity
{
    public static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::canonical($item);
        }
        // Lists represent ordered evidence. Only object keys are unordered.
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    public static function same(mixed $left, mixed $right): bool
    {
        return self::canonical($left) === self::canonical($right);
    }
}
