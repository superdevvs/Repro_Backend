<?php

namespace App\Support;

use Illuminate\Http\Request;

final class PhotographerCapabilityFields
{
    /** These assignments are managed in Accounts, using the effective primary role. */
    public static function assertWritable(Request $request): void
    {
        if (!self::containsManagedFields($request->all())) {
            return;
        }

        $role = strtolower(str_replace(['_', '-'], '', (string) $request->user()?->role));
        abort_unless(
            !$request->attributes->get('is_impersonating') && in_array($role, ['admin', 'superadmin'], true),
            403,
            'Specialties and property experience are managed by an admin. Please contact your administrator to request a change.'
        );
    }

    private static function containsManagedFields(mixed $value): bool
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $key => $item) {
            $parts = preg_split('/[.\[\]]/', (string) $key, -1, PREG_SPLIT_NO_EMPTY);
            $keyName = strtolower(preg_replace('/[^a-z]/i', '', (string) end($parts)));
            if (in_array($keyName, ['specialties', 'propertytypes'], true)) {
                return true;
            }
            if ((is_array($item) || in_array($keyName, ['metadata', 'preferences'], true)) && self::containsManagedFields($item)) {
                return true;
            }
        }
        return false;
    }
}
