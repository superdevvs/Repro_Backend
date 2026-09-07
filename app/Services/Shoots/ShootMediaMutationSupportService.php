<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootMediaAlbum;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ShootMediaMutationSupportService
{
    public function __construct(
        protected ShootAuthorizationSupport $shootAuthorizationSupport
    ) {
    }

    public function clearShootFilesCache(Shoot $shoot, ?User $user = null): void
    {
        $user = $user ?? auth()->user();
        $userId = $user ? $user->id : 'guest';
        $userRole = $user ? $user->role : 'guest';

        foreach (['', 'raw', 'edited', 'all'] as $type) {
            Cache::forget('shoot_files_' . $shoot->id . '_' . $type . '_' . $userId . '_' . $userRole);
        }

        if ($shoot->client_id && (!$user || (string) $user->id !== (string) $shoot->client_id)) {
            foreach (['', 'raw', 'edited', 'all'] as $type) {
                Cache::forget('shoot_files_' . $shoot->id . '_' . $type . '_' . $shoot->client_id . '_client');
            }
        }
    }

    public function refreshMediaCounters(Shoot $shoot): Shoot
    {
        $rawCount = $this->requiredRawFilesQuery($shoot)->count();
        // The edited photo count must equal what the edited photo tab shows
        // (Req 3.8 / Property 7): floor plans, extras and raw camera files are
        // not edited photos. Raw camera detection is filename/mime based, so we
        // reuse ShootAuthorizationSupport::isRawCameraFile() in PHP to keep the
        // definition identical to the archive service rather than approximating
        // it in SQL.
        $editedCount = $shoot->files()
            ->whereIn('workflow_stage', [ShootFile::STAGE_COMPLETED, ShootFile::STAGE_VERIFIED])
            ->where(function ($query) {
                $query->whereNull('flag_reason')
                    ->orWhere('flag_reason', '');
            })
            ->get()
            ->reject(fn (ShootFile $file) => $this->isExcludedFromEditedPhotoCount($file))
            ->count();
        $extraCount = $this->extraFilesQuery($shoot)->count();

        $shoot->raw_photo_count = $rawCount;
        $shoot->edited_photo_count = $editedCount;
        $shoot->extra_photo_count = $extraCount;

        // Derived from the service items: each contributes photo_count x its own
        // bracket size, so a shoot mixing 5x and 3x services is counted correctly.
        // The stored expected_raw_count was expected_final_count x a shoot-wide
        // bracket_mode and sat at 0 on every shoot, which made this shortfall
        // permanently 0.
        $expectedRaw = app(BracketModeResolver::class)->expectedRawForShoot($shoot);
        $expectedFinal = $shoot->expected_final_count ?? 0;

        $shoot->raw_missing_count = max(0, $expectedRaw - $rawCount);
        $shoot->edited_missing_count = max(0, $expectedFinal - $editedCount);
        $shoot->missing_raw = $shoot->raw_missing_count > 0;
        $shoot->missing_final = $shoot->edited_missing_count > 0;
        $shoot->save();

        return $shoot->fresh(['files']);
    }

    /**
     * A completed/verified file that is not an edited photo: floor plans and
     * extras (by media_type or the is_extra flag) and raw camera files. Mirrors
     * the archive service's edited-scope exclusions so counts and downloads
     * agree (Req 3.8).
     */
    protected function isExcludedFromEditedPhotoCount(ShootFile $file): bool
    {
        $mediaType = strtolower((string) ($file->media_type ?? ''));
        if (in_array($mediaType, ['extra', 'floorplan'], true)) {
            return true;
        }

        if ((bool) $file->is_extra) {
            return true;
        }

        return $this->shootAuthorizationSupport->isRawCameraFile($file);
    }

    protected function requiredRawFilesQuery(Shoot $shoot)
    {
        $query = $shoot->files()
            ->where('workflow_stage', ShootFile::STAGE_TODO)
            ->where(function ($scope) {
                $scope->whereNull('flag_reason')
                    ->orWhere('flag_reason', '');
            });

        if (Schema::hasColumn('shoot_files', 'is_extra') && Schema::hasColumn('shoot_files', 'required_for_editing')) {
            $query->where(function ($scope) {
                $scope->where(function ($nested) {
                    $nested->where('is_extra', false)->orWhereNull('is_extra');
                })->orWhere('required_for_editing', true);
            });
        } else {
            $query->where(function ($scope) {
                $scope->whereNull('media_type')->orWhere('media_type', '!=', 'extra');
            });
        }

        return $query;
    }

    protected function extraFilesQuery(Shoot $shoot)
    {
        $query = $shoot->files()
            ->where('workflow_stage', ShootFile::STAGE_TODO)
            ->where(function ($scope) {
                $scope->whereNull('flag_reason')
                    ->orWhere('flag_reason', '');
            });

        if (Schema::hasColumn('shoot_files', 'is_extra')) {
            return $query->where('is_extra', true);
        }

        return $query->where(function ($scope) {
            $scope->where('media_type', 'extra')
                ->orWhere('path', 'like', '%/extra/%');
        });
    }

    public function deleteShootMediaAssets(Shoot $shoot): int
    {
        $shoot->loadMissing(['files', 'mediaAlbums']);
        $deletedFileCount = 0;

        foreach ($shoot->files as $file) {
            $this->deleteStoredAssets($file);
            $deletedFileCount++;
        }

        foreach ($shoot->mediaAlbums as $album) {
            $coverImagePath = $this->normalizePublicStoragePath($album->cover_image_path);
            if ($coverImagePath && Storage::disk('public')->exists($coverImagePath)) {
                Storage::disk('public')->delete($coverImagePath);
            }
        }

        $this->clearShootFilesCache($shoot);

        return $deletedFileCount;
    }

    public function deleteStoredAssets(ShootFile $file): void
    {
        foreach ($this->collectStoredAssetPaths($file) as $storedPath) {
            if (Storage::disk('public')->exists($storedPath)) {
                Storage::disk('public')->delete($storedPath);
            }
        }
    }

    public function deleteFile(Shoot $shoot, ShootFile $file): Shoot
    {
        $wasCover = (bool) ($file->is_cover ?? false);

        $this->deleteStoredAssets($file);

        $file->delete();

        // Reassign the cover when the deleted file held it, so a shoot cannot keep
        // advertising a thumbnail whose image no longer exists (meeting 26 Jul 2026
        // [00:25:18], and the delivered-shoot-with-no-media case in A1.docx).
        if ($wasCover) {
            $this->reassignCoverImage($shoot);
        }

        $shoot = $this->refreshMediaCounters($shoot->fresh());
        $this->clearShootFilesCache($shoot);

        return $shoot;
    }

    /**
     * Promote the next deliverable image to cover, or leave the shoot with none.
     *
     * Preferring a delivered/verified image keeps the cover consistent with what a
     * client is allowed to see; falling back to any image is better than none.
     *
     * `shoots.hero_image` is cleared as well. It caches the resolved URL of the
     * cover file, and {@see ShootPresenter} only recomputes it when empty — so
     * leaving the old value behind kept the shoot advertising the URL of a file
     * that had just been deleted, which is the broken-thumbnail symptom this is
     * meant to fix.
     */
    protected function reassignCoverImage(Shoot $shoot): void
    {
        if (! Schema::hasColumn('shoot_files', 'is_cover')) {
            return;
        }

        $replacement = $shoot->files()
            ->where('is_cover', false)
            ->where(function ($query) {
                $query->whereNull('media_type')
                    ->orWhereIn('media_type', ['image', 'raw']);
            })
            ->orderByRaw(
                "CASE WHEN workflow_stage IN (?, ?) THEN 0 ELSE 1 END",
                [ShootFile::STAGE_COMPLETED, ShootFile::STAGE_VERIFIED]
            )
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        if ($replacement) {
            $replacement->update(['is_cover' => true]);
        }

        // Null it either way: with a replacement the presenter resolves the new
        // cover's URL, and with none it correctly reports no hero image rather
        // than a dead link.
        if (Schema::hasColumn('shoots', 'hero_image') && $shoot->hero_image !== null) {
            $shoot->hero_image = null;
            $shoot->save();
        }
    }

    public function transformFile(ShootFile $file): array
    {
        return [
            'id' => $file->id,
            'shoot_service_id' => $file->shoot_service_id,
            'shootServiceId' => $file->shoot_service_id,
            'filename' => $file->filename,
            'stored_filename' => $file->stored_filename,
            'path' => $file->path,
            'storage_path' => $file->storage_path,
            'dropbox_path' => $file->dropbox_path,
            'workflow_stage' => $file->workflow_stage,
            'media_type' => $file->media_type,
            'is_extra' => $file->isExtra(),
            'isExtra' => $file->isExtra(),
            'required_for_editing' => $file->isRequiredForEditing(),
            'requiredForEditing' => $file->isRequiredForEditing(),
            'file_size' => $file->file_size,
            'thumbnail_path' => $file->thumbnail_path,
            'web_path' => $file->web_path,
            'placeholder_path' => $file->placeholder_path,
            'uploaded_at' => $file->uploaded_at?->toIso8601String() ?? $file->created_at?->toIso8601String(),
            'created_at' => $file->created_at?->toIso8601String(),
        ];
    }

    public function getOrCreateAlbumForType(Shoot $shoot, string $type, User $user, ?int $shootServiceId = null): ShootMediaAlbum
    {
        $photographerId = $user->role === 'photographer' ? $user->id : $shoot->photographer_id;

        $album = $shoot->mediaAlbums()
            ->where('shoot_service_id', $shootServiceId)
            ->where('photographer_id', $photographerId)
            ->whereHas('files', function ($query) use ($type) {
                $query->where('media_type', $type);
            })
            ->first();

        if ($album) {
            return $album;
        }

        return ShootMediaAlbum::create([
            'shoot_id' => $shoot->id,
            'shoot_service_id' => $shootServiceId,
            'photographer_id' => $photographerId,
            'source' => 'local',
            'folder_path' => $shootServiceId
                ? "/shoots/{$shoot->id}/service-{$shootServiceId}/{$type}/{$photographerId}/"
                : "/shoots/{$shoot->id}/{$type}/{$photographerId}/",
            'is_watermarked' => false,
        ]);
    }

    /**
     * @return array<int, string>
     */
    protected function collectStoredAssetPaths(ShootFile $file): array
    {
        $paths = [
            $file->path,
            $file->storage_path,
            $file->thumbnail_path,
            $file->web_path,
            $file->placeholder_path,
            $file->watermarked_storage_path,
            $file->watermarked_thumbnail_path,
            $file->watermarked_web_path,
            $file->watermarked_placeholder_path,
        ];

        return array_values(array_filter(array_unique(array_map(
            fn (?string $path) => $this->normalizePublicStoragePath($path),
            $paths
        ))));
    }

    protected function normalizePublicStoragePath(?string $storedPath): ?string
    {
        if (!is_string($storedPath)) {
            return null;
        }

        $normalized = trim(str_replace('\\', '/', $storedPath));
        if ($normalized === '') {
            return null;
        }

        if (filter_var($normalized, FILTER_VALIDATE_URL)) {
            $parsedPath = parse_url($normalized, PHP_URL_PATH);
            $normalized = is_string($parsedPath) ? $parsedPath : $normalized;
        }

        $normalized = ltrim($normalized, '/');

        foreach (['storage/', 'public/', 'app/public/'] as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                $normalized = substr($normalized, strlen($prefix));
                break;
            }
        }

        if (preg_match('/^[A-Za-z]:\//', $normalized) === 1) {
            $publicRoot = str_replace('\\', '/', storage_path('app/public'));
            if (str_starts_with($normalized, $publicRoot . '/')) {
                $normalized = substr($normalized, strlen($publicRoot) + 1);
            } else {
                return null;
            }
        }

        return $normalized !== '' ? $normalized : null;
    }
}
