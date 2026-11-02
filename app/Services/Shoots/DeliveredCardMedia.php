<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use Illuminate\Support\Collection;

/** A read-only, bounded preview contract; never a download entitlement. */
final class DeliveredCardMedia
{
    public function __construct(
        private ShootFileAccessService $files,
        private ShootAuthorizationSupport $authorization,
    ) {}

    /** @param Collection<int, ShootFile> $candidates Already scoped to the viewer. */
    public function forShoot(Shoot $shoot, Collection $candidates, ?User $viewer): array
    {
        $status = strtolower(trim((string) $shoot->payment_status));
        // An unsettled/missing payment status is not permission to show originals.
        // Do not lazy-load payments once per card to infer a broader entitlement.
        $watermarked = $this->authorization->isClientUser($viewer)
            && ! $shoot->bypass_paywall && ! in_array($status, ['paid', 'full'], true);
        $previews = [];
        $hero = null;

        foreach ($viewer ? $candidates->take(6) : [] as $file) {
            if (! $this->eligible($file)) {
                continue;
            }
            $paths = $watermarked
                ? [$file->watermarked_web_path, $file->watermarked_thumbnail_path, $file->watermarked_placeholder_path]
                : [$file->grid_path, $file->web_path, $file->thumbnail_path];
            $originalKeys = $watermarked ? collect([$file->grid_path, $file->web_path, $file->thumbnail_path, $file->path, $file->url])
                ->filter(fn ($path) => is_string($path) && trim($path) !== '')
                ->map(fn ($path) => $this->pathKey($this->files->resolvePublicStorageUrl($path)))
                ->filter()->all() : [];
            $url = null;
            foreach ($paths as $path) {
                if (is_string($path) && trim($path) !== '') {
                    $url = $this->files->resolvePublicStorageUrl($path);
                    if ($watermarked && in_array($this->pathKey($url), $originalKeys, true)) {
                        $url = null;
                    }
                    if ($url) {
                        break;
                    }
                }
            }
            if (! $url || in_array($url, $previews, true)) {
                continue;
            }
            $previews[] = $url;
            if ($file->is_cover && $hero === null) {
                $hero = $url;
            }
        }

        return [
            'version' => 1,
            'hero_image' => $hero ?? ($previews[0] ?? null),
            'preview_images' => $previews,
            // Six preview candidates do not establish the total photo count.
            'count' => null,
            'uses_watermark' => $watermarked,
            'delivered_at' => $shoot->admin_verified_at?->toIso8601String(),
        ];
    }

    private function pathKey(?string $url): ?string
    {
        return $url ? rawurldecode((string) (parse_url($url, PHP_URL_PATH) ?: $url)) : null;
    }

    private function eligible(ShootFile $file): bool
    {
        if ($file->is_hidden || $file->isBlockedFromDelivery()
            || ! in_array($file->workflow_stage, [ShootFile::STAGE_COMPLETED, ShootFile::STAGE_VERIFIED], true)) {
            return false;
        }
        $type = strtolower((string) $file->media_type);
        if (in_array($type, ['raw', 'video', 'floorplan', 'iguide'], true)) {
            return false;
        }
        $mime = strtolower((string) ($file->mime_type ?: $file->file_type));

        return in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/heic', 'image/heif', 'image/tiff'], true)
            || (bool) preg_match('/\.(jpe?g|png|webp|gif|heic|heif|tiff?)$/i', (string) $file->filename);
    }
}
