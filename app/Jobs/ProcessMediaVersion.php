<?php

namespace App\Jobs;

use App\Models\ShootFileVersion;
use App\Services\ImageProcessingService;
use App\Services\Media\MediaStorage;
use App\Services\Scanning\ClamAvClient;
use App\Services\Shoots\MediaVersionPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class ProcessMediaVersion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 4;
    public int $timeout = 180;
    public array $backoff = [10, 30, 90];

    public function __construct(public string $versionId) { $this->onQueue('default'); }

    public function handle(MediaStorage $storage, ClamAvClient $scanner, ImageProcessingService $images, MediaVersionPublisher $publisher): void
    {
        $lock = Cache::lock('media-version:'.$this->versionId, 240);
        if (!$lock->get()) { $this->release(15); return; }
        $temporary = [];
        try {
            $version = ShootFileVersion::find($this->versionId);
            if (!$version) return;
            // A retry after publication can still be responsible for reconciliation/cache effects.
            if (in_array($version->status, ['published', 'archived', 'conflict', 'alternative', 'failed'], true)) { $publisher->publish($version); return; }
            if ($version->status === 'dismissed') return;
            if ($version->status === 'ready') { $publisher->publish($version); return; }
            $snapshot = $version->snapshot;
            $path = $storage->absolutePath($snapshot['path']);
            if (!$path) { $path = $storage->downloadToTemp($snapshot['path']); if ($path) $temporary[] = $path; }
            if (!$path || !is_file($path) || hash_file('sha256', $path) !== $version->sha256) throw new \RuntimeException('The saved export is missing or incomplete. Upload it again.');
            $version->update(['status' => 'processing', 'error' => null, 'error_code' => null]);
            $verdict = $scanner->scan($path);
            if (!$verdict->isClean()) {
                $version->update(['status' => 'failed', 'error_code' => 'scan_failed', 'error' => 'The returned file failed scanning. The previous image remains available.']);
                $publisher->syncDispatch($version);
                return;
            }
            $previewSource = $path;
            if (($snapshot['mime_type'] ?? '') === 'image/tiff') {
                $previewSource = tempnam(sys_get_temp_dir(), 'repro-tiff-');
                $temporary[] = $previewSource;
                // Reuse the ImageMagick installation already used for RAW extraction on the worker.
                // Decode only the first TIFF page, with bounded memory/disk/time.
                $conversion = new Process(['convert', '-limit', 'memory', '256MiB', '-limit', 'map', '512MiB', '-limit', 'disk', '1GiB',
                    $path.'[0]', '-auto-orient', '-colorspace', 'sRGB', '-background', 'white', '-alpha', 'remove', '-quality', '94', 'jpeg:'.$previewSource]);
                $conversion->setTimeout(60)->mustRun();
            }
            $size = @getimagesize($previewSource);
            if (!$size || $size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > 100000000) throw new \RuntimeException('Export a valid image with at most 100 megapixels.');
            // Unique stored names prevent new previews from overwriting a previous version's bytes.
            $paths = $images->processImageFromPath($version->shoot_id, $snapshot['stored_filename'], $previewSource);
            foreach (['thumbnail', 'grid', 'web', 'placeholder'] as $rendition) {
                if (empty($paths[$rendition]) || !$storage->exists($paths[$rendition])) throw new \RuntimeException('Image processing is incomplete. The previous image remains available.');
                $snapshot[$rendition.'_path'] = $paths[$rendition];
            }
            foreach (['watermarked_storage_path', 'watermarked_thumbnail_path', 'watermarked_web_path', 'watermarked_placeholder_path'] as $field) $snapshot[$field] = null;
            foreach (['large_path', 'medium_path'] as $field) {
                if (\Illuminate\Support\Facades\Schema::hasColumn('shoot_files', $field)) $snapshot[$field] = null;
            }
            $snapshot += ['scan_status' => 'clean', 'scan_result' => 'clean (version scan)', 'scanned_at' => now()->toIso8601String(),
                'processed_at' => now()->toIso8601String(), 'processing_failed_at' => null, 'processing_error' => null,
                'is_ai_edited' => ($version->metadata['origin'] ?? '') === 'ai', 'ai_editing_metadata' => $version->metadata['ai_editing_metadata'] ?? null];
            $version->update(['snapshot' => $snapshot, 'status' => !empty($version->metadata['alternative']) ? 'alternative' : 'ready']);
            $publisher->publish($version->fresh());
        } finally {
            foreach ($temporary as $file) if (is_file($file)) unlink($file);
            $lock->release();
        }
    }

    public function failed(\Throwable $exception): void
    {
        ShootFileVersion::whereKey($this->versionId)->whereIn('status', ['queued', 'processing'])->update([
            'status' => 'failed', 'error_code' => 'processing_failed', 'error' => 'The saved edit could not be processed. Retry the saved upload; the previous image is unchanged.',
        ]);
        Log::error('Media version processing failed', ['version_id' => $this->versionId, 'exception' => $exception]);
        if ($version = ShootFileVersion::find($this->versionId)) app(MediaVersionPublisher::class)->syncDispatch($version);
    }
}
