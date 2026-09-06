<?php

namespace App\Services\Studio;

use App\Models\ShootFile;
use App\Models\StudioWorkspace;
use App\Models\User;
use App\Services\RawThumbnailService;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Shoots\ShootFileAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class WorkspaceMediaService
{
    public function __construct(private ShootAuthorizationSupport $authorization, private ShootFileAccessService $files, private RawThumbnailService $raw) {}

    /** Client URLs are display hints only. Persist only server-resolved, authorized references. */
    public function authorize(array $media, User $user, int $teamId): array
    {
        StudioClientAccess::authorize($user);
        if (! in_array($user->role, ['admin', 'superadmin', 'editing_manager', 'editor', 'client'], true)) {
            throw new AuthorizationException('This user no longer has Studio editing access.');
        }
        $result = [];
        foreach ($media as $item) {
            if (! empty($item['fileId'])) {
                $file = ShootFile::with('shoot')->findOrFail($item['fileId']);
                $shoot = $file->shoot;
                if (! $this->authorization->canInteractWithShootMediaFile($shoot, $file, $user)
                    || ($user->role === 'client' && (! $this->authorization->canDownloadShootMediaFile($shoot, $file, $user)
                        || app(\App\Services\Shoots\ShootClientReleaseAccessService::class)->isFileReleaseLocked($shoot, $file, $user)))) {
                    throw new AuthorizationException('This source is outside your Studio access.');
                }
                if ((! empty($item['shootId']) && (int) $item['shootId'] !== (int) $file->shoot_id) || $file->is_hidden || ! $file->isClearedForProcessing()) {
                    throw ValidationException::withMessages(['media' => 'This source is not available for editing.']);
                }
                $kind = $this->authorization->isRawCameraFile($file) ? 'raw' : ($this->authorization->isImageMediaFile($file) ? 'image' : 'video');
                if ($kind === 'video') {
                    throw ValidationException::withMessages(['media' => 'Choose photos or RAW images. Video source editing is not supported.']);
                }
                $url = url("/api/shoots/{$shoot->id}/files/{$file->id}/preview");
                $result[] = ['id' => $item['id'], 'shootId' => $shoot->id, 'fileId' => $file->id, 'url' => $url, 'thumbnailUrl' => $url, 'name' => $file->filename, 'kind' => $kind];
            } else {
                $ref = str_replace('\\', '/', (string) ($item['mediaRef'] ?? ''));
                $prefix = "studio/uploads/{$teamId}/{$user->id}/";
                if (! str_starts_with($ref, $prefix) || preg_match('#(^|/)\.\.?(/|$)#', $ref) || str_contains($ref, "\0")) {
                    throw new AuthorizationException('This upload is outside your Studio access.');
                }
                $disk = Storage::disk(config('studio_uploads.disk', 'public'));
                abort_unless($disk->exists($ref), 404, 'Uploaded media not found.');
                $ext = strtolower(pathinfo($ref, PATHINFO_EXTENSION));
                if (! in_array($ext, config('studio_uploads.workflows.photo-enhancement.extensions', ['jpg', 'jpeg', 'png', 'webp']), true)) {
                    throw ValidationException::withMessages(['media' => 'This uploaded media type is not supported.']);
                }
                $kind = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'avif', 'heic', 'heif'], true) ? 'image' : 'raw';
                $url = $disk->url($ref);
                $result[] = self::withUploadPreview(['id' => $item['id'], 'mediaRef' => $ref, 'url' => $url, 'thumbnailUrl' => $url, 'name' => basename($ref), 'kind' => $kind]);
            }
        }

        return $result;
    }

    /** The original reference stays intact; browsers receive an authenticated JPEG preview. */
    public static function withUploadPreview(array $media): array
    {
        if (! empty($media['mediaRef']) && empty($media['fileId']) && ($media['kind'] ?? $media['mediaType'] ?? null) === 'raw') {
            $preview = url('/api/studio/workspaces/sources/uploads/preview').'?'.http_build_query(['mediaRef' => $media['mediaRef']], '', '&', PHP_QUERY_RFC3986);
            $media['previewUrl'] = $preview;
            $media['thumbnailUrl'] = $preview;
            $media['url'] = $preview;
        }

        return $media;
    }

    public function uploadedPreview(string $mediaRef, User $user, int $teamId): string
    {
        // Recheck ownership and source existence even when a JPEG is already cached.
        $media = $this->authorize([['id' => 'preview', 'mediaRef' => $mediaRef]], $user, $teamId)[0];
        $mediaRef = $media['mediaRef'];
        $source = Storage::disk(config('studio_uploads.disk', 'public'));
        $key = hash('sha256', implode('|', [config('studio_uploads.disk', 'public'), $mediaRef, $source->lastModified($mediaRef), $source->size($mediaRef)]));
        $cache = Storage::disk('local');
        $path = "studio/previews/{$teamId}/{$user->id}/{$key}.jpg";
        if ($cache->exists($path)) {
            return $cache->get($path);
        }

        $image = @imagecreatefromstring($this->bytes($media));
        if (! $image) {
            throw new RuntimeException('The uploaded image could not be previewed.');
        }
        try {
            $width = imagesx($image);
            $height = imagesy($image);
            $scale = min(1, 2048 / max($width, $height));
            $preview = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
            try {
                imagecopyresampled($preview, $image, 0, 0, 0, 0, imagesx($preview), imagesy($preview), $width, $height);
                ob_start();
                imagejpeg($preview, null, 88);
                $bytes = ob_get_clean();
            } finally {
                imagedestroy($preview);
            }
        } finally {
            imagedestroy($image);
        }
        if (! $bytes || ! $cache->put($path, $bytes)) {
            throw new RuntimeException('The preview could not be cached.');
        }

        return $bytes;
    }

    public function bytes(array $media): string
    {
        $cleanup = [];
        try {
            if (! empty($media['fileId'])) {
                $file = ShootFile::findOrFail($media['fileId']);
                if (! $file->isClearedForProcessing() || $file->is_hidden) {
                    throw new RuntimeException('Source media is no longer available for processing.');
                }
                $path = $this->files->findLocalFilePath($file);
                if (! $path) {
                    $path = $this->files->downloadFromDropbox($file);
                    if ($path) {
                        $cleanup[] = $path;
                    }
                }
                $name = $file->filename;
            } else {
                $ref = $media['mediaRef'];
                $disk = Storage::disk(config('studio_uploads.disk', 'public'));
                $path = tempnam(sys_get_temp_dir(), 'studio-source-');
                $cleanup[] = $path;
                file_put_contents($path, $disk->get($ref));
                $name = basename($ref);
            }
            if (! $path || ! is_file($path)) {
                throw new RuntimeException('The original source file could not be read.');
            }
            if ($this->raw->isRawFile($name)) {
                $jpeg = $this->raw->extractFullSizeJpeg($path);
                if (! $jpeg) {
                    throw new RuntimeException('This RAW file does not contain a supported image preview.');
                }
                $cleanup[] = $jpeg;
                $path = $jpeg;
            }
            $bytes = file_get_contents($path);
            if (! $bytes || ! @getimagesizefromstring($bytes)) {
                throw new RuntimeException('The source is not a decodable image.');
            }

            return $bytes;
        } finally {
            foreach ($cleanup as $path) {
                if (is_string($path) && is_file($path)) {
                    @unlink($path);
                }
            }
        }
    }

    public function store(StudioWorkspace $workspace, string $bytes, string $suffix): array
    {
        if (! @getimagesizefromstring($bytes)) {
            throw new RuntimeException('The provider returned an invalid image.');
        }
        $path = "studio/workspaces/{$workspace->id}/{$suffix}.jpg";
        Storage::disk('public')->put($path, $bytes);

        return ['path' => $path, 'url' => Storage::disk('public')->url($path)];
    }
}
