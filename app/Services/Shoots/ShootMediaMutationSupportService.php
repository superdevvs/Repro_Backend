<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootMediaAlbum;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
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
        $rawCount = $shoot->files()->where('workflow_stage', ShootFile::STAGE_TODO)->count();
        $editedCount = $shoot->files()
            ->whereIn('workflow_stage', [ShootFile::STAGE_COMPLETED, ShootFile::STAGE_VERIFIED])
            ->count();

        $shoot->raw_photo_count = $rawCount;
        $shoot->edited_photo_count = $editedCount;

        $expectedRaw = $shoot->expected_raw_count ?? 0;
        $expectedFinal = $shoot->expected_final_count ?? 0;

        $shoot->raw_missing_count = max(0, $expectedRaw - $rawCount);
        $shoot->edited_missing_count = max(0, $expectedFinal - $editedCount);
        $shoot->missing_raw = $shoot->raw_missing_count > 0;
        $shoot->missing_final = $shoot->edited_missing_count > 0;
        $shoot->save();

        return $shoot->fresh(['files']);
    }

    public function deleteFile(Shoot $shoot, ShootFile $file): Shoot
    {
        if ($file->path && Storage::disk('public')->exists($file->path)) {
            Storage::disk('public')->delete($file->path);
        }

        $file->delete();

        $shoot = $this->refreshMediaCounters($shoot->fresh());
        $this->clearShootFilesCache($shoot);

        return $shoot;
    }

    public function transformFile(ShootFile $file): array
    {
        return [
            'id' => $file->id,
            'filename' => $file->filename,
            'stored_filename' => $file->stored_filename,
            'path' => $file->path,
            'storage_path' => $file->storage_path,
            'dropbox_path' => $file->dropbox_path,
            'workflow_stage' => $file->workflow_stage,
            'media_type' => $file->media_type,
            'file_size' => $file->file_size,
            'thumbnail_path' => $file->thumbnail_path,
            'web_path' => $file->web_path,
            'placeholder_path' => $file->placeholder_path,
            'uploaded_at' => $file->uploaded_at?->toIso8601String() ?? $file->created_at?->toIso8601String(),
            'created_at' => $file->created_at?->toIso8601String(),
        ];
    }

    public function getOrCreateAlbumForType(Shoot $shoot, string $type, User $user): ShootMediaAlbum
    {
        $photographerId = $user->role === 'photographer' ? $user->id : $shoot->photographer_id;

        $album = $shoot->mediaAlbums()
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
            'photographer_id' => $photographerId,
            'source' => 'dropbox',
            'folder_path' => "/shoots/{$shoot->id}/{$type}/{$photographerId}/",
            'is_watermarked' => false,
        ]);
    }
}
