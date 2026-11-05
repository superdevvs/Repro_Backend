<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootService;
use App\Services\Media\MediaStorage;

/** Revocable, revalidated browser copies of published image derivatives only. */
class PublicTourImage
{
    public function __construct(protected MediaStorage $media, protected ShootClientReleaseAccessService $releaseAccess) {}

    public function canPublish(Shoot $shoot): bool
    {
        // Unit publication has a separate per-service gate. Keep its existing
        // signed delivery until this path can represent that gate explicitly.
        if ($shoot->isImportDraft() || $shoot->relationLoaded('tourUnit')) return false;
        if (app(ShootActiveWorkLifecycle::class)->hasActiveWork($shoot)) {
            if ($this->isSuspended($shoot)) return false;
            return $this->canRetainUnscopedHistory($shoot) || $this->retainedServiceIds($shoot) !== [];
        }
        return ($shoot->workflow_status ?: $shoot->status) === Shoot::STATUS_DELIVERED
            && ! $this->releaseAccess->isPublicReleaseLocked($shoot);
    }

    public function canPublishFile(ShootFile $file, ?Shoot $shoot = null): bool
    {
        $safe = ! $file->is_hidden && ! $file->isBlockedFromDelivery()
            && ! $file->isIguideOfflinePackage()
            && ! in_array(strtolower((string) $file->media_type), ['floorplan', 'video'], true)
            && in_array($file->workflow_stage, [ShootFile::STAGE_COMPLETED, ShootFile::STAGE_VERIFIED], true);
        if (! $safe) return false;
        $shoot ??= $file->relationLoaded('shoot') ? $file->shoot : $file->shoot()->first();
        if (! $shoot || ! app(ShootActiveWorkLifecycle::class)->hasActiveWork($shoot)) return $safe;
        return $this->canRetainPublishedFile($file, $shoot);
    }

    /** Shared retained-output gate; video originals do not become photo-rendition URLs. */
    public function canRetainPublishedFile(ShootFile $file, Shoot $shoot, bool $requirePayment = true): bool
    {
        if (! app(ShootActiveWorkLifecycle::class)->hasActiveWork($shoot) || $this->isSuspended($shoot)
            || (int) $file->shoot_id !== (int) $shoot->id
            || $file->is_hidden || $file->isBlockedFromDelivery() || $file->isIguideOfflinePackage()
            || $file->workflow_stage !== ShootFile::STAGE_VERIFIED
            || strtolower((string) $file->media_type) === 'raw') return false;
        if (! $file->shoot_service_id) {
            return $file->created_at && $file->created_at->lte($shoot->active_work_started_at)
                && (! $file->verified_at || $file->verified_at->lte($shoot->active_work_started_at))
                && $this->canRetainUnscopedHistory($shoot, $requirePayment);
        }
        $item = $file->relationLoaded('serviceItem') ? $file->serviceItem : $file->serviceItem()->first();
        return $item && (int) $item->shoot_id === (int) $shoot->id
            && $this->isRetainedDeliveredItem($shoot, $item)
            && (! $requirePayment || $this->isItemUnlocked($shoot, $item));
    }

    /** Only explicitly delivered, unlocked lines may contribute to an Active public preview. */
    public function retainedServiceIds(Shoot $shoot): array
    {
        if (! app(ShootActiveWorkLifecycle::class)->hasActiveWork($shoot) || $this->isSuspended($shoot)) return [];
        return $shoot->serviceItems()->get()->filter(fn (ShootService $item) =>
            $this->isRetainedDeliveredItem($shoot, $item) && $this->isItemUnlocked($shoot, $item))->modelKeys();
    }

    /** An unscoped historical image has no per-line payment identity; retain the whole-order gate. */
    public function canRetainUnscopedHistory(Shoot $shoot, bool $requirePayment = true): bool
    {
        return app(ShootActiveWorkLifecycle::class)->hasActiveWork($shoot) && ! $this->isSuspended($shoot)
            && $shoot->admin_verified_at && $shoot->admin_verified_at->lte($shoot->active_work_started_at)
            && (! $requirePayment || ! $this->releaseAccess->isPublicReleaseLocked($shoot));
    }

    private function isRetainedDeliveredItem(Shoot $shoot, ShootService $item): bool
    {
        return $item->is_deliverable && ! $item->cancelled_at
            && $item->workflow_status === ShootService::WORKFLOW_DELIVERED
            && $item->delivery_status === ShootService::DELIVERY_DELIVERED
            && $item->delivered_at;
    }

    private function isItemUnlocked(Shoot $shoot, ShootService $item): bool
    {
        if (! $this->releaseAccess->isPublicReleaseLocked($shoot)) return true;
        $item->setRelation('shoot', $shoot);
        return $item->is_unlocked_for_delivery;
    }

    private function isSuspended(Shoot $shoot): bool
    {
        return in_array(strtolower((string) ($shoot->workflow_status ?: $shoot->status)),
            ['on_hold', 'hold_on', 'cancelled', 'canceled', 'declined'], true);
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
        if (! $file || ! $this->canPublishFile($file, $shoot)) return null;
        $key = $this->key($file, $rendition);
        return $key && hash_equals($this->revision($file, $rendition, $key), $revision) ? $key : null;
    }
}
