<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Services\Media\MediaStorage;

/** Revocable, revalidated browser copies of published image derivatives only. */
class PublicTourImage
{
    public function __construct(protected MediaStorage $media, protected ShootClientReleaseAccessService $releaseAccess) {}

    public function canPublish(Shoot $shoot): bool
    {
        // Unit publication has a separate per-service gate. Keep its existing
        // signed delivery until this path can represent that gate explicitly.
        return ! $shoot->isImportDraft()
            && ! $shoot->relationLoaded('tourUnit')
            && ($shoot->workflow_status ?: $shoot->status) === Shoot::STATUS_DELIVERED
            && ! $this->releaseAccess->isPublicReleaseLocked($shoot);
    }

    public function canPublishFile(ShootFile $file): bool
    {
        return ! $file->is_hidden && ! $file->isBlockedFromDelivery()
            && ! $file->isIguideOfflinePackage()
            && ! in_array(strtolower((string) $file->media_type), ['floorplan', 'video'], true)
            && in_array($file->workflow_stage, [ShootFile::STAGE_COMPLETED, ShootFile::STAGE_VERIFIED], true);
    }

    public function key(ShootFile $file, string $rendition): ?string
    {
        $column = match ($rendition) {
            'web' => 'web_path', 'grid' => 'grid_path', 'thumbnail' => 'thumbnail_path', default => null,
        };
        $path = $column ? $file->getAttribute($column) : null;
        if (! is_string($path) || $path === '' || preg_match('#^https?://#i', $path)) return null;
        $key = $this->media->normalizeKey($path);
        if (! $key || ! str_starts_with($key, 'shoots/'.$file->shoot_id.'/')
            || str_contains($key, '..') || str_contains($key, '\\')
            || ! in_array(strtolower(pathinfo($key, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp', 'avif', 'gif'], true)) return null;
        // Never turn an original masquerading as a rendition into a cacheable URL.
        if (in_array($key, array_filter([$this->originalKey($file->path), $this->originalKey($file->storage_path)]), true)) return null;
        return $key;
    }

    private function originalKey(?string $path): ?string
    {
        if (is_string($path) && preg_match('#^https?://#i', $path)) {
            $path = rawurldecode((string) parse_url($path, PHP_URL_PATH));
            $path = preg_replace('#^/api/public/shoot-media/file/#', '', $path);
        }
        return $this->media->normalizeKey($path);
    }

    public function revision(ShootFile $file, string $rendition, string $key): string
    {
        return hash_hmac('sha256', implode('|', [
            $file->shoot_id, $file->id, $rendition, $key,
            $file->content_version ?: 1, (string) $file->getRawOriginal('updated_at'),
        ]), (string) config('app.key'));
    }

    public function url(ShootFile $file, string $rendition): ?string
    {
        $key = $this->key($file, $rendition);
        if (! $key || ! $this->media->exists($key)) return null;
        return route('api.public.tour-image', [
            'shootId' => $file->shoot_id, 'photoId' => $file->id, 'rendition' => $rendition,
            'revision' => $this->revision($file, $rendition, $key),
        ]);
    }

    public function resolvePublishedKey(int $shootId, int $fileId, string $rendition, string $revision): ?string
    {
        $shoot = Shoot::find($shootId);
        if (! $shoot || ! $this->canPublish($shoot) || $shoot->units()->exists()) return null;
        $file = ShootFile::where('shoot_id', $shootId)->find($fileId);
        if (! $file || ! $this->canPublishFile($file)) return null;
        $key = $this->key($file, $rendition);
        return $key && hash_equals($this->revision($file, $rendition, $key), $revision) ? $key : null;
    }
}
