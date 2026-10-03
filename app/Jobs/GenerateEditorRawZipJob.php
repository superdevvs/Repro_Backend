<?php

namespace App\Jobs;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\Media\MediaStorage;
use App\Services\Shoots\ShootEditingAssignmentService;
use App\Services\Shoots\ShootShareLinkService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Build an editor-scoped raw ZIP outside the HTTP request so Cloudflare/nginx
 * do not 499/502 while multi-GB archives are assembled synchronously.
 */
class GenerateEditorRawZipJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public array $backoff = [10, 30, 60];

    /**
     * @param  array<int, int>  $fileIds
     */
    public function __construct(
        public int $shootId,
        public int $userId,
        public array $fileIds,
        public string $storagePath,
        public string $lockKey
    ) {
        $this->onQueue(\App\Services\Media\ArchiveQueue::name($shootId));
        if ($this->queue === 'media-archives') {
            $this->onConnection('database');
        }
        $this->afterCommit();
    }

    public function handle(
        ShootShareLinkService $shareLinkService,
        ShootEditingAssignmentService $editingAssignmentService,
        MediaStorage $media
    ): void {
        $zipPath = null;
        try {
            $shoot = Shoot::find($this->shootId);
            $user = User::find($this->userId);
            if (! $shoot || ! $user) {
                return;
            }
            $scoped = app(\App\Services\Shoots\EditorRawArchiveService::class);
            $files = $scoped->authorizedFiles($shoot, $user, $this->fileIds);
            if ($files->isEmpty() || $scoped->descriptor($shoot, $user, $files)['storage_path'] !== $this->storagePath) {
                // Selection, assignment or source changed after dispatch. The next poll queues its new version.
                return;
            }
            if ($media->exists($this->storagePath)) {
                return;
            }
            if ($scoped->reuseLegacyArchive($shoot, $user, $files, $this->storagePath)) {
                return;
            }
            $zipPath = $shareLinkService->generateFilesZip($shoot, $files);
            if (! $zipPath || ! is_file($zipPath)) {
                throw new \RuntimeException('Failed to generate editor raw ZIP');
            }
            $current = $scoped->authorizedFiles($shoot->fresh(), $user->fresh(), $this->fileIds);
            if ($scoped->descriptor($shoot->fresh(), $user, $current)['storage_path'] !== $this->storagePath) {
                return;
            }
            app(\App\Services\Media\MediaArchivePublisher::class)->publish($this->storagePath, $zipPath);
        } finally {
            if (is_string($zipPath)) {
                @unlink($zipPath);
            }
            Cache::forget($this->lockKey);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Cache::forget($this->lockKey);
        Log::error('Editor raw zip job failed permanently', [
            'shoot_id' => $this->shootId,
            'user_id' => $this->userId,
            'storage_path' => $this->storagePath,
            'error' => $exception->getMessage(),
        ]);
    }
}
