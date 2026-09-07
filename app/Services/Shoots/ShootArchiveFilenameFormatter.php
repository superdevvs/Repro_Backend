<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use Illuminate\Support\Str;

class ShootArchiveFilenameFormatter
{
    private const MAX_DOWNLOAD_SLUG_BYTES = 180;

    /** Shared address spelling; existing cached archive paths retain this slug. */
    public function propertySlug(Shoot $shoot): string
    {
        $parts = array_filter([
            $shoot->address,
            $shoot->city,
            $shoot->state,
            $shoot->zip,
        ], fn ($value) => is_string($value) ? trim($value) !== '' : !empty($value));

        $candidate = trim(implode(' ', array_map('strval', $parts)));
        $slug = Str::slug($candidate, '-');

        return $slug !== '' ? $slug : "shoot-{$shoot->id}";
    }

    public function rawFiles(Shoot $shoot): string
    {
        return $this->downloadFilename($shoot, 'raw-files');
    }

    public function selection(Shoot $shoot, string $size): string
    {
        return $this->downloadFilename($shoot, $size === 'small' ? 'selected-mls' : 'selected-print');
    }

    private function downloadFilename(Shoot $shoot, string $suffix): string
    {
        // Restrict the response basename to portable ASCII and leave room for
        // the variant suffix. Never change the temporary ZIP or its entry names.
        $slug = preg_replace('/[^a-z0-9-]/', '', $this->propertySlug($shoot));
        $slug = trim(substr($slug, 0, self::MAX_DOWNLOAD_SLUG_BYTES), '-');
        if ($slug === '') {
            $slug = "shoot-{$shoot->id}";
        }

        return "{$slug}-{$suffix}.zip";
    }
}
