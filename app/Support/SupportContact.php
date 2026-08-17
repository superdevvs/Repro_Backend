<?php

namespace App\Support;

final class SupportContact
{
    /** Client-facing display format used everywhere in the product. */
    public const PHONE_DISPLAY = '(202) 868-1663';

    /** Machine-readable form used only for tel: links and telephony APIs. */
    public const PHONE_E164 = '+12028681663';

    public const EMAIL = 'contact@reprophotos.com';

    /**
     * Normalize only the known R/E Pro Photos support numbers. This method is
     * intentionally not a general phone formatter: customer, photographer,
     * access-contact, and SMS-recipient numbers must remain untouched.
     */
    public static function normalizeReferences(string $content): string
    {
        $normalized = preg_replace(
            '/(?<!\d)(?:\+?1[\s.\-]*)?\(?202\)?[\s.\-]*868[\s.\-]*(?:1113|1663)(?!\d)/',
            self::PHONE_DISPLAY,
            $content
        ) ?? $content;

        // The visible number uses the brand display format, while links use a
        // portable E.164 target understood consistently by mail clients.
        return preg_replace(
            '/tel:\s*\(202\)\s*868-1663/i',
            'tel:'.self::PHONE_E164,
            $normalized
        ) ?? $normalized;
    }
}
