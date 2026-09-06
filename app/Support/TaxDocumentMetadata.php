<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class TaxDocumentMetadata
{
    private static function protectedKey(string $key): bool
    {
        return str_starts_with(strtolower(preg_replace('/[^a-z0-9]/i', '', $key)), 'taxdocument');
    }

    public static function strip(mixed $value): mixed
    {
        if (!is_array($value)) { return $value; }
        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && self::protectedKey($key)) { continue; }
            $result[$key] = is_array($item) ? self::strip($item) : $item;
        }
        return $result;
    }

    /** Preserve migration pointers during unrelated legacy profile edits, without accepting replacements from callers. */
    public static function preserveLegacy(array $existing, array $incoming): array
    {
        foreach ($existing as $key => $value) {
            if (is_string($key) && self::protectedKey($key)) {
                $incoming[$key] = $value;
            } elseif (is_array($value)) {
                $preserved = self::preserveLegacy($value, is_array($incoming[$key] ?? null) ? $incoming[$key] : []);
                if ($preserved !== []) { $incoming[$key] = $preserved; }
            }
        }
        return $incoming;
    }

    /** Accept a complete request or decoded metadata; JSON metadata is checked too. */
    public static function assertWritable(mixed $value): void
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) { self::assertWritable($decoded); }
            return;
        }
        if (!is_array($value)) { return; }
        foreach ($value as $key => $item) {
            if (is_string($key) && self::protectedKey($key)) {
                throw ValidationException::withMessages(['metadata' => 'Tax documents can only be changed through the tax document upload.']);
            }
            if (is_array($item) || (is_string($key) && $key === 'metadata')) { self::assertWritable($item); }
        }
    }
}
