<?php

namespace App\Jobs;

use App\Models\Shoot;
use App\Models\ShootShareLink;
use App\Services\Media\MediaStorage;
use App\Services\Shoots\ShootShareLinkService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Assemble share-link ZIPs in a queue worker so editor "Share Link" requests
 * return quickly instead of timing out while packaging multi-GB raw sets.
 */
class GenerateShootShareLinkZipJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public array $backoff = [10, 30, 60];

    /**
     * @param  array<int, int>  $fileIds
     */
    public function __construct(
        public int $shareLinkId,
        public int $shootId,
        public array $fileIds,
        public string $mediaStage,
        public string $lockKey
    ) {
        $this->onQueue(\App\Services\Media\ArchiveQueue::name($shootId));
        if ($this->queue === 'media-archives') {
            $this->onConnection('database');
        }
        $this->afterCommit();
    }

    public function handle(ShootShareLinkService $shareLinkService, MediaStorage $media): void
    {
        try {
            $link = ShootShareLink::query()->find($this->shareLinkId);
            $shoot = Shoot::find($this->shootId);
            if (! $link || ! $shoot) {
                Log::warning('Share link zip job skipped; link or shoot missing', [
                    'share_link_id' => $this->shareLinkId,
                    'shoot_id' => $this->shootId,
                ]);

                return;
            }

            if (is_string($link->dropbox_path) && str_starts_with($link->dropbox_path, 'share-links/') && $media->exists($link->dropbox_path)) {
                return;
            }

            $files = $shareLinkService->selectEditorShareFiles($shoot, $this->mediaStage, $this->fileIds);
            if ($files->isEmpty()) {
                Log::info('Share link zip job skipped; no shareable files', [
                    'share_link_id' => $this->shareLinkId,
                    'shoot_id' => $this->shootId,
                ]);

                return;
            }

            $zipPath = $shareLinkService->generateFilesZip($shoot, $files);
            if (! $zipPath || ! file_exists($zipPath)) {
                throw new \RuntimeException('Failed to generate shareable ZIP file');
            }

            $publicPath = 'share-links/'.$shoot->id.'/share-link-'.Str::uuid()->toString().'.zip';
            try {
                app(\App\Services\Media\MediaArchivePublisher::class)->publish($publicPath, $zipPath);
            } finally {
                @unlink($zipPath);
            }

            $link->forceFill([
                'dropbox_path' => $publicPath,
                'share_url' => route('api.public.share-links.download', [
                    'token' => $link->public_token,
                ]),
            ])->save();

        } finally {
            Cache::forget($this->lockKey);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Cache::forget($this->lockKey);
        Log::error('Share link zip job failed permanently', [
            'share_link_id' => $this->shareLinkId,
            'shoot_id' => $this->shootId,
            'error' => $exception->getMessage(),
        ]);
    }
}
