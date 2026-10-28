<?php

namespace App\Services\Shoots;

use App\Models\ShootFile;
use App\Models\ShootFileVersion;
use App\Services\Media\MediaStorage;
use App\Services\Scanning\ClamAvClient;
use App\Support\LockedWrite;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Prepare provider revisions privately, then atomically switch the existing gallery row. */
class CubiCasaAssetRevision
{
    public function __construct(private MediaStorage $storage, private ClamAvClient $scanner, private FloorplanPreviewService $previews) {}

    public function replace(ShootFile $current, string $binary, array $item): bool
    {
        $hash = hash('sha256', $binary);
        $old = $this->storage->get($current->path);
        if ($old !== null && hash_equals(hash('sha256', $old), $hash)) {
            return false;
        }

        // A pending initial import must finish before a revision can supersede it.
        // Otherwise an old scan/preview worker could write its result onto new bytes.
        if (! $current->isClearedForProcessing() || ! $current->web_path) {
            throw new \RuntimeException('The previous floorplan import is still processing; retry the revision.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($binary);
        $extension = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'][$mime] ?? null;
        if (! $extension) {
            throw new \RuntimeException('The revised floorplan is not a supported PDF or image.');
        }

        $temporary = tempnam(sys_get_temp_dir(), 'cubi-revision-');
        if ($temporary === false) {
            throw new \RuntimeException('Could not stage the revised floorplan.');
        }
        try {
            if (file_put_contents($temporary, $binary) !== strlen($binary)) {
                throw new \RuntimeException('The staged floorplan is incomplete.');
            }
            // Like ProcessMediaVersion, scan the unpublished version before rendering or publication.
            $verdict = $this->scanner->scan($temporary);
            if (! $verdict->isClean()) {
                throw new \RuntimeException('The revised floorplan failed its safety scan; the previous version is unchanged.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        $stored = 'cubicasa-'.$current->id.'-'.Str::uuid().'.'.$extension;
        $directory = $current->shoot_service_id
            ? "shoots/{$current->shoot_id}/services/{$current->shoot_service_id}/floorplans"
            : "shoots/{$current->shoot_id}/floorplans";
        $path = $directory.'/'.$stored;
        if (! $this->storage->put($path, $binary)) {
            throw new \RuntimeException('The revised floorplan could not be stored.');
        }

        $candidate = clone $current;
        $candidate->fill(array_fill_keys(['thumbnail_path', 'grid_path', 'web_path', 'placeholder_path',
            'watermarked_storage_path', 'watermarked_thumbnail_path', 'watermarked_web_path', 'watermarked_placeholder_path',
            'processed_at', 'processing_failed_at', 'processing_error'], null));
        $metadata = Arr::except($current->metadata ?? [], ['preview_images', 'preview_generated_at', 'preview_page_count']);
        $candidate->fill(['stored_filename' => $stored, 'path' => $path, 'storage_path' => $path,
            'mime_type' => $mime, 'file_type' => $mime, 'file_size' => strlen($binary),
            'scan_status' => ShootFile::SCAN_STATUS_CLEAN, 'scan_result' => 'clean (provider revision scan)', 'scanned_at' => now(),
            'metadata' => array_merge($metadata, ['original_url' => $item['url'], 'cubicasa_sha256' => $hash,
                'cubicasa_revised_at' => now()->toIso8601String()])]);
        $preview = $this->previews->ensurePreview($candidate, true, false);
        if (! in_array($preview['status'], ['pdf_rendered', 'generated'], true)) {
            throw new \RuntimeException('The revised floorplan preview is not ready.');
        }
        foreach (array_unique(array_filter(array_merge([$candidate->web_path, $candidate->thumbnail_path], $candidate->metadata['preview_images'] ?? []))) as $previewPath) {
            if (! $this->storage->exists($previewPath)) {
                throw new \RuntimeException('The revised floorplan preview is missing.');
            }
        }
        $candidate->processed_at = now();
        $published = LockedWrite::run(fn () => DB::transaction(function () use ($current, $candidate, $hash) {
            $fresh = ShootFile::find($current->id);
            if (! $fresh || $fresh->path !== $current->path || (int) $fresh->content_version !== (int) $current->content_version
                || $fresh->scan_status === ShootFile::SCAN_STATUS_INFECTED
                || (int) $fresh->shoot_service_id !== (int) $current->shoot_service_id) {
                throw new \RuntimeException('The current floorplan changed during revision processing; retry with the latest version.');
            }
            app(MediaVersionPublisher::class)->archiveCurrent($fresh, $fresh->uploaded_by);
            $fresh->fill(Arr::only($candidate->attributesToArray(), MediaVersionPublisher::CONTENT_FIELDS));
            // Preserve concurrent curation and all non-provider metadata.
            $fresh->metadata = array_merge(Arr::except($fresh->metadata ?? [], ['preview_images', 'preview_generated_at', 'preview_page_count']),
                Arr::only($candidate->metadata, ['original_url', 'cubicasa_sha256', 'cubicasa_revised_at', 'preview_images', 'preview_generated_at', 'preview_page_count']));
            $fresh->content_version = (int) $fresh->content_version + 1;
            $fresh->save();
            ShootFileVersion::create(['shoot_id' => $fresh->shoot_id, 'source_file_id' => $fresh->id, 'target_file_id' => $fresh->id,
                'published_file_id' => $fresh->id, 'expected_version' => $current->content_version, 'version' => $fresh->content_version,
                'request_key' => 'cubicasa:'.$fresh->id.':'.$fresh->content_version.':'.$hash, 'sha256' => $hash,
                'status' => 'published', 'created_by' => $fresh->uploaded_by, 'metadata' => ['origin' => 'cubicasa'],
                'snapshot' => Arr::only($fresh->attributesToArray(), MediaVersionPublisher::CONTENT_FIELDS)]);

            return $fresh;
        }), 'cubicasa.publish-revision');
        app(ShootMediaMutationSupportService::class)->clearShootFilesCache($published->shoot);

        return true;
    }
}
