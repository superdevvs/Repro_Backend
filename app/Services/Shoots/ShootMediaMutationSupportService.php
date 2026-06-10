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
        $editedCount = $shoot->files()
            ->whereIn('workflow_stage', [ShootFile::STAGE_COMPLETED, ShootFile::STAGE_VERIFIED])
            ->where(function ($query) {
                $query->whereNull('flag_reason')
                    ->orWhere('flag_reason', '');
            })
            ->count();
        $extraCount = $this->extraFilesQuery($shoot)->count();

        $shoot->raw_photo_count = $rawCount;
        $shoot->edited_photo_count = $editedCount;
        $shoot->extra_photo_count = $extraCount;

        $expectedRaw = $shoot->expected_raw_count ?? 0;
        $expectedFinal = $shoot->expected_final_count ?? 0;

        $shoot->raw_missing_count = max(0, $expectedRaw - $rawCount);
        $shoot->edited_missing_count = max(0, $expectedFinal - $editedCount);
        $shoot->missing_raw = $shoot->raw_missing_count > 0;
        $shoot->missing_final = $shoot->edited_missing_count > 0;
        $shoot->save();

        return $shoot->fresh(['files']);
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
        $this->deleteStoredAssets($file);

        $file->delete();

        $shoot = $this->refreshMediaCounters($shoot->fresh());
        $this->clearShootFilesCache($shoot);

        return $shoot;
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
            'source' => 'dropbox',
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
