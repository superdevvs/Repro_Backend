<?php

namespace App\Services\Aryeo\Api;

use App\Models\AryeoApiAsset;
use App\Models\ShootFile;

class FrozenOriginals
{
    public function path(AryeoApiAsset $asset): string
    {
        abort_unless(preg_match('/^[a-f0-9-]{36}$/D', $asset->id), 409, 'invalid_asset_identity');

        return storage_path('app/private/aryeo-api/'.$asset->id.'.original');
    }

    public function freeze(AryeoApiAsset $asset): void
    {
        $target = $this->path($asset);
        if (is_file($target)) {
            $this->verify($asset);

            return;
        }
        $root = dirname($target);
        if (! is_dir($root)) {
            mkdir($root, 02770, true);
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            abort_unless(chgrp($root, config('aryeo_api.storage_group')) && chmod($root, 02770), 409, 'original_storage_permissions_required');
        }
        $source = ShootFile::where('shoot_id', $asset->export->snapshot['shoot_id'])->findOrFail($asset->source['id']);
        app(Originals::class)->withFile($source, function ($path) use ($target, $root, $asset) {
            $temporary = tempnam($root, 'copy-');
            abort_unless($temporary, 409, 'original_snapshot_failed');
            try {
                abort_unless(copy($path, $temporary), 409, 'original_snapshot_failed');
                $this->verify($asset, $temporary);
                if (PHP_OS_FAMILY !== 'Windows') {
                    abort_unless(chgrp($temporary, config('aryeo_api.storage_group')) && chmod($temporary, 0660), 409, 'original_storage_permissions_required');
                }
                abort_unless(rename($temporary, $target), 409, 'original_snapshot_failed');
            } finally {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
        });
    }

    public function verify(AryeoApiAsset $asset, ?string $path = null): void
    {
        $path ??= $this->path($asset);
        $expected = $asset->source['content'];
        abort_unless(is_file($path) && filesize($path) === $expected['bytes'] && hash_equals($expected['sha256'], hash_file('sha256', $path)), 409, 'original_snapshot_changed');
    }
}
