<?php

namespace App\Jobs;

use App\Models\ShootFile;
use App\Services\DropboxWorkflowService;
use App\Services\IguideOfflinePackageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Publishes only the lifecycle pointer for a clean iGUIDE package. The ZIP stays
 * opaque in private storage; the viewer streams clean members without extracting
 * them into a public directory.
 */
class FinalizeIguideOfflinePackageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30];

    public function __construct(public readonly int $shootFileId)
    {
        $this->onQueue('default');
    }

    public function handle(IguideOfflinePackageService $packages, DropboxWorkflowService $dropbox): void
    {
        $file = ShootFile::find($this->shootFileId);
        if (
            $file === null
            || ! $file->isIguideOfflinePackage()
            || $file->scan_status !== ShootFile::SCAN_STATUS_CLEAN
        ) {
            Log::info('FinalizeIguideOfflinePackageJob skipped an ineligible file.', [
                'shoot_file_id' => $this->shootFileId,
                'scan_status' => $file?->scan_status,
            ]);

            return;
        }

        $packages->markScanning($file);
        $packages->markReady($file);

        if ($dropbox->isEnabled()) {
            try {
                SyncShootFileToDropboxJob::dispatch((int) $file->getKey());
            } catch (Throwable $exception) {
                // The authenticated private ZIP is already ready. A transient
                // mirror enqueue failure must not roll its lifecycle backward.
                Log::warning('Could not enqueue clean iGUIDE package Dropbox mirror.', [
                    'shoot_file_id' => $file->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    public function failed(?Throwable $exception = null): void
    {
        try {
            $file = ShootFile::find($this->shootFileId);
            if ($file !== null && $file->isIguideOfflinePackage()) {
                app(IguideOfflinePackageService::class)->markFailed(
                    $file,
                    $exception?->getMessage() ?: 'The clean package could not be finalized.'
                );
            }
        } catch (Throwable $failure) {
            Log::error('Unable to record iGUIDE package finalization failure.', [
                'shoot_file_id' => $this->shootFileId,
                'error' => $failure->getMessage(),
            ]);
        }
    }
}
