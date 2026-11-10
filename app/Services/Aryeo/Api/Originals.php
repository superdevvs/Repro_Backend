<?php

namespace App\Services\Aryeo\Api;

use App\Models\ShootFile;
use App\Services\Shoots\ShootFileAccessService;

class Originals
{
    public function withFile(ShootFile $file, callable $use): mixed
    {
        $access = app(ShootFileAccessService::class);
        foreach (array_unique(array_filter([$file->storage_path, $file->path])) as $path) {
            $local = $access->resolveLocalPath($path);
            if ($local && is_file($local)) {
                return $use($local);
            }
            $temporary = $access->downloadStoredFileToTemp($path);
            if ($temporary && is_file($temporary)) {
                try {
                    return $use($temporary);
                } finally {
                    @unlink($temporary);
                }
            }
        }
        abort(409, 'original_unavailable');
    }

    public function fingerprint(ShootFile $file): array
    {
        return $this->withFile($file, fn ($path) => ['sha256' => hash_file('sha256', $path), 'bytes' => filesize($path)]);
    }
}
