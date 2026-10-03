<?php

namespace App\Support;

/**
 * Office phone is separate from the account phone number.
 *
 * The branded tour shows it only when a number is saved and
 * show_office_phone_on_tour is true. The client portal shows it
 * whenever a number is filled and has no visibility flag.
 */
class OfficePhoneFields
{
    public const PHONE = 'office_phone';

    public const TOUR_FLAG = 'show_office_phone_on_tour';

    /**
     * @return array<string, string>
     */
    public static function rules(): array
    {
        return [
            self::PHONE => 'nullable|string|max:50',
            self::TOUR_FLAG => 'nullable|boolean',
        ];
    }

    /**
     * When a non-empty office phone is saved and the tour flag is omitted, default it on.
     * Clearing the number does not force the flag; an empty number is never published.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function apply(array $validated): array
    {
        $hasPhone = array_key_exists(self::PHONE, $validated);
        $hasFlag = array_key_exists(self::TOUR_FLAG, $validated);

        if ($hasPhone) {
            $validated[self::PHONE] = self::normalize($validated[self::PHONE]);
        }

        if ($hasFlag) {
            $validated[self::TOUR_FLAG] = self::isShown($validated[self::TOUR_FLAG]);
        }

        if ($hasPhone && $validated[self::PHONE] !== null && ! $hasFlag) {
            $validated[self::TOUR_FLAG] = true;
        }

        return $validated;
    }

    /**
     * Authenticated account/settings payload. Always includes the flag so the form can render the toggle.
     *
     * @return array{office_phone: ?string, show_office_phone_on_tour: bool}
     */
    public static function forAccount(?string $officePhone, mixed $showOnTour): array
    {
        return [
            self::PHONE => self::normalize($officePhone),
            self::TOUR_FLAG => self::isShown($showOnTour),
        ];
    }

    /**
     * Branded tour public payload. Null means omit the number (empty phone or flag off).
     */
    public static function forTour(?string $officePhone, mixed $showOnTour): ?string
    {
        $phone = self::normalize($officePhone);
        if ($phone === null || ! self::isShown($showOnTour)) {
            return null;
        }

        return $phone;
    }

    /**
     * Client portal contact payload. Ignores the tour flag.
     */
    public static function forPortal(?string $officePhone): ?string
    {
        return self::normalize($officePhone);
    }

    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    public static function isShown(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            return $parsed === true;
        }

        return false;
    }
}
