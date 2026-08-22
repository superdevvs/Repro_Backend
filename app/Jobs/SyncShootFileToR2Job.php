<?php

namespace App\Jobs;

use App\Models\ShootFile;
use App\Services\Media\MediaStorage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Mirror a ShootFile's media objects (original + derived + watermark outputs)
 * to Cloudflare R2 under byte-for-byte identical keys.
 *
 * Dispatched from every local write entry (upload, image processing, watermark
 * generation, asset ingest) while MEDIA_DUAL_WRITE (or MEDIA_R2_ONLY) is on.
 * Idempotent and retryable: keys already present on R2 with a matching size are
 * skipped, so re-dispatching after derived assets are generated is safe.
 */
class SyncShootFileToR2Job implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [10, 30, 60];
    public $timeout = 300;

    public function __construct(public readonly int $shootFileId)
    {
        $this->onQueue('media');
    }

    /** All media-bearing key columns on a ShootFile, in mirror order. */
    public const KEY_ATTRIBUTES = [
        'path',
        'storage_path',
        'thumbnail_path',
        // The 600px grid rendition. Every card and tile resolves this key, and
        // once reads are flipped to R2 they resolve it against the CDN — so a
        // rendition that is never mirrored is a 404 on every grid surface.
        'grid_path',
        'web_path',
        'placeholder_path',
        'watermarked_storage_path',
        'watermarked_thumbnail_path',
        'watermarked_web_path',
        'watermarked_placeholder_path',
    ];

    public function handle(MediaStorage $media): void
    {
        if (! $media->dualWriteEnabled() && ! $media->r2Only()) {
            return;
        }

        $shootFile = ShootFile::find($this->shootFileId);
        if (! $shootFile) {
            Log::warning('SyncShootFileToR2Job: shoot file not found', ['shoot_file_id' => $this->shootFileId]);

            return;
        }

        $seen = [];
        $results = ['copied' => 0, 'skipped' => 0, 'missing' => 0, 'failed' => 0];

        foreach (self::KEY_ATTRIBUTES as $attribute) {
            $raw = $shootFile->{$attribute} ?? null;
            $key = $media->normalizeKey(is_string($raw) ? $raw : null);

            // Skip empty keys, absolute http(s) URLs, and duplicates (path often
            // equals storage_path).
            if ($key === null || str_starts_with($key, 'http://') || str_starts_with($key, 'https://')) {
                continue;
            }
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $status = $media->mirrorToR2($key);
            $results[$status] = ($results[$status] ?? 0) + 1;

            if ($status === 'failed') {
                Log::warning('SyncShootFileToR2Job: mirror failed', [
                    'shoot_file_id' => $shootFile->id,
                    'attribute' => $attribute,
                    'key' => $key,
                ]);
            }
        }

        // Surface failures so the queue retries; a missing local source is not a
        // failure (derived assets may not exist yet and will sync on re-dispatch).
        if ($results['failed'] > 0) {
            throw new \RuntimeException("SyncShootFileToR2Job: {$results['failed']} key(s) failed to mirror for shoot file {$shootFile->id}");
        }

        Log::info('SyncShootFileToR2Job: mirror complete', array_merge(
            ['shoot_file_id' => $shootFile->id],
            $results
        ));
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SyncShootFileToR2Job: failed permanently', [
            'shoot_file_id' => $this->shootFileId,
            'error' => $exception->getMessage(),
        ]);
    }
}
