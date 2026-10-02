<?php

namespace App\Services\Shoots;

class MediaFilenameNumbering
{
    public function apply(string $stem, string $action, string $position, string $separator, int $number, int $digits, string $value = ''): string
    {
        $existing = null;
        // A separator is required so an address or camera ID is never mistaken for numbering.
        if (preg_match('/^(\d+)[_-]+(.+)$/u', $stem, $matches)) {
            $existing = $matches[1];
            $stem = $matches[2];
        } elseif (preg_match('/^(.+)[_-]+(\d+)$/u', $stem, $matches)) {
            $existing = $matches[2];
            $stem = $matches[1];
        }

        if ($action === 'remove') {
            return $stem;
        }

        if ($action === 'renumber') {
            $existing = str_pad((string) $number, $digits, '0', STR_PAD_LEFT);
            $stem = $value !== '' ? $value : $stem;
        }

        if ($existing === null) {
            return $stem;
        }

        return $position === 'start' ? $existing.$separator.$stem : $stem.$separator.$existing;
    }
}
