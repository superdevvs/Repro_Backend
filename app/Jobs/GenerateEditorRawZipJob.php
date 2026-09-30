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
        $this->onQueue('default');
        $this->afterCommit();
    }

    public function handle(
        ShootShareLinkService $shareLinkService,
        ShootEditingAssignmentService $editingAssignmentService,
        MediaStorage $media
    ): void {
        $shoot = Shoot::find($this->shootId);
        $user = User::find($this->userId);
        if (! $shoot || ! $user) {
            Log::warning('Editor raw zip job skipped; shoot or user missing', [
                'shoot_id' => $this->shootId,
                'user_id' => $this->userId,
            ]);
            Cache::forget($this->lockKey);

            return;
        }

        if ($media->exists($this->storagePath)) {
            Cache::forget($this->lockKey);

            return;
        }

        $files = $shoot->files()
            ->whereIn('id', $this->fileIds)
            ->where('workflow_stage', ShootFile::STAGE_TODO)
            ->get()
            ->filter(fn (ShootFile $file) => $file->isRequiredForEditing())
            ->reject(fn (ShootFile $file) => $file->isBlockedFromDelivery())
            ->values();

        if (app(\App\Services\Shoots\ShootAuthorizationSupport::class)->hasRole($user, ['editor'])) {
            $files = $editingAssignmentService->filterFilesForEditor($files, $shoot, $user);
        }

        if ($files->isEmpty()) {
            Log::info('Editor raw zip job skipped; no downloadable files', [
                'shoot_id' => $this->shootId,
                'user_id' => $this->userId,
            ]);
            Cache::forget($this->lockKey);

            return;
        }

        $zipPath = $shareLinkService->generateFilesZip($shoot, $files);
        if (! $zipPath || ! file_exists($zipPath)) {
            throw new \RuntimeException('Failed to generate editor raw ZIP');
        }

        $stream = fopen($zipPath, 'r');
        if ($stream === false) {
            @unlink($zipPath);
            throw new \RuntimeException('Failed to read editor raw ZIP');
        }

        try {
            if (! $media->put($this->storagePath, $stream)) {
                throw new \RuntimeException('Failed to store editor raw ZIP');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            @unlink($zipPath);
        }

        Cache::forget($this->lockKey);
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
