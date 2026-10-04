<?php

namespace App\Support;

use App\Models\Shoot;

/**
 * Display helpers for shoot street / full address lines.
 *
 * Apt/Suite is stored on property_details (aptSuite or apt_suite) and is often
 * omitted from the street column. Formatters append it for cards, calendar,
 * and email without rewriting historical street values.
 */
class ShootAddress
{
    /**
     * @param  array<string, mixed>|null  $propertyDetails
     */
    public static function resolveAptSuite(?array $propertyDetails): ?string
    {
        if (! is_array($propertyDetails)) {
            return null;
        }

        foreach (['aptSuite', 'apt_suite', 'suite', 'unit'] as $key) {
            $value = $propertyDetails[$key] ?? null;
            if (! is_string($value) && ! is_numeric($value)) {
                continue;
            }

            $trimmed = trim((string) $value);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return null;
    }

    public static function formatUnitLabel(string $aptSuite): string
    {
        $trimmed = trim($aptSuite);
        if ($trimmed === '') {
            return '';
        }

        if (preg_match('/^(?:#|(?:apartment|apt\.?|unit|suite|ste\.?)(?=\s|#|$))/i', $trimmed) === 1) {
            return $trimmed;
        }

        return 'Unit '.$trimmed;
    }

    public static function streetContainsUnit(string $street, string $aptSuite): bool
    {
        $street = trim($street);
        $aptSuite = trim($aptSuite);
        if ($street === '' || $aptSuite === '') {
            return false;
        }

        $unitToken = preg_replace('/^(?:#|(?:apartment|apt\.?|unit|suite|ste\.?)(?=\s|#|$))\s*#?\s*/i', '', $aptSuite) ?? $aptSuite;
        $unitToken = trim($unitToken);
        if ($unitToken === '') {
            return false;
        }

        $escaped = preg_quote($unitToken, '/');
        $pattern = '/(?:^|[\s,])(?:#|apartment|apt\.?|unit|suite|ste\.?)\s*#?\s*'.$escaped.'(?=$|[\s,])/i';

        return preg_match($pattern, $street) === 1;
    }

    /**
     * @param  array<string, mixed>|null  $propertyDetails
     */
    public static function streetWithAptSuite(?string $street, ?array $propertyDetails): string
    {
        $street = trim((string) $street);
        $apt = self::resolveAptSuite($propertyDetails);
        if ($apt === null || $street === '') {
            return $street;
        }

        if (self::streetContainsUnit($street, $apt)) {
            return $street;
        }

        $label = self::formatUnitLabel($apt);
        if ($label === '') {
            return $street;
        }

        return $street.', '.$label;
    }

    public static function formatFullAddress(Shoot $shoot): string
    {
        $details = is_array($shoot->property_details) ? $shoot->property_details : null;
        $street = self::streetWithAptSuite($shoot->address, $details);

        $parts = array_filter([
            $street,
            trim((string) ($shoot->city ?? '')),
            trim(implode(' ', array_filter([
                trim((string) ($shoot->state ?? '')),
                trim((string) ($shoot->zip ?? '')),
            ]))),
        ], static fn ($part) => is_string($part) && $part !== '');

        return implode(', ', $parts);
    }
}
