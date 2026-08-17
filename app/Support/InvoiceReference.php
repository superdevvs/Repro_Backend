<?php

namespace App\Support;

final class InvoiceReference
{
    public static function number(mixed $value, mixed $fallbackId = null): string
    {
        $reference = trim((string) $value);

        if ($reference === '') {
            return self::fallbackNumber($fallbackId);
        }

        if (strcasecmp($reference, 'invoice') === 0) {
            return self::fallbackNumber($fallbackId);
        }

        // Only remove "Invoice" when it is being used as a display label.
        // Hyphenated values such as INVOICE-1001 are identifiers in their own
        // right and must remain intact.
        $number = preg_replace('/^invoice(?=\s|:)(?:\s*:\s*|\s+)/i', '', $reference);
        $number = trim((string) ($number ?? $reference));

        return $number !== '' ? $number : self::fallbackNumber($fallbackId);
    }

    public static function label(mixed $value, mixed $fallbackId = null): string
    {
        $number = self::number($value, $fallbackId);

        return $number === '' ? '' : 'Invoice '.$number;
    }

    private static function fallbackNumber(mixed $fallbackId): string
    {
        return $fallbackId === null || $fallbackId === ''
            ? ''
            : '#'.trim((string) $fallbackId);
    }
}
