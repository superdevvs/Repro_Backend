<?php

namespace App\Services\Crm;

/** Normal forms used for duplicate checks: emails ignore case and spaces, phones keep digits only, names ignore case and punctuation. */
final class CrmNormalizer
{
    public static function email(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }
        $value = strtolower(preg_replace('/\s+/', '', $email) ?? '');

        return $value === '' ? null : $value;
    }

    /** Digits only. A leading US country code on an 11-digit number is dropped so (301) 555-0142 and +1 301 555 0142 match. */
    public static function phone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        return strlen($digits) >= 7 ? $digits : null;
    }

    public static function name(?string $name): string
    {
        $value = mb_strtolower(trim((string) $name));
        $value = preg_replace('/[^\p{L}\p{N}\s]+/u', '', $value) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }
}
