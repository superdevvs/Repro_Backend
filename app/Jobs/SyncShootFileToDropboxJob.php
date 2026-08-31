<?php

namespace App\Jobs;

use App\Models\ShootFile;
use App\Services\DropboxTokenService;
use App\Services\DropboxWorkflowService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SyncShootFileToDropboxJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [10, 30, 60];
    public $timeout = 300;

    protected int $shootFileId;

    public function __construct(int $shootFileId)
    {
        $this->shootFileId = $shootFileId;
        $this->onQueue('default');
    }

    public function handle(DropboxWorkflowService $dropboxService, DropboxTokenService $tokenService): void
    {
        $shootFile = ShootFile::with('shoot')->find($this->shootFileId);
        if (!$shootFile) {
            Log::warning('SyncShootFileToDropboxJob: Shoot file not found', ['shoot_file_id' => $this->shootFileId]);
            return;
        }

        if (!$dropboxService->isEnabled()) {
            Log::info('SyncShootFileToDropboxJob: Dropbox disabled, skipping', ['shoot_file_id' => $shootFile->id]);
            return;
        }

        if ($shootFile->dropbox_file_id && $shootFile->dropbox_path) {
            Log::info('SyncShootFileToDropboxJob: File already synced', ['shoot_file_id' => $shootFile->id]);
            return;
        }

        $localPath = $this->resolveLocalPath($shootFile);
        if (!$localPath) {
            Log::warning('SyncShootFileToDropboxJob: Local file missing', [
                'shoot_file_id' => $shootFile->id,
                'path' => $shootFile->path,
            ]);
            return;
        }

        $shoot = $shootFile->shoot;
        if (!$shoot) {
            Log::warning('SyncShootFileToDropboxJob: Shoot missing', ['shoot_file_id' => $shootFile->id]);
            return;
        }

        $folderType = $shootFile->isIguideOfflinePackage()
            ? 'edited'
            : ($shootFile->media_type === 'extra'
            ? 'extra'
            : ($shootFile->workflow_stage === ShootFile::STAGE_COMPLETED ? 'edited' : 'raw'));

        $folderPath = $shoot->getDropboxFolderForType($folderType);
        if (!$folderPath) {
            $dropboxService->createShootFolders($shoot);
            $shoot->refresh();
            $folderPath = $shoot->getDropboxFolderForType($folderType);
        }

        if (!$folderPath) {
            Log::warning('SyncShootFileToDropboxJob: Dropbox folder missing', [
                'shoot_file_id' => $shootFile->id,
                'folder_type' => $folderType,
            ]);
            return;
        }

        $filename = $shootFile->stored_filename ?: $shootFile->filename ?: basename($localPath);
        $dropboxPath = rtrim($folderPath, '/') . '/' . $filename;

        $stream = fopen($localPath, 'r');
        if ($stream === false) {
            Log::warning('SyncShootFileToDropboxJob: Unable to read local file', [
                'shoot_file_id' => $shootFile->id,
                'local_path' => $localPath,
            ]);
            return;
        }

        $apiArgs = json_encode([
            'path' => $dropboxPath,
            'mode' => 'overwrite',
            'autorename' => false,
            'mute' => false,
        ]);

        $response = Http::withToken($tokenService->getValidAccessToken())
            ->withOptions([
                'verify' => config('app.env') === 'production',
                'timeout' => 60,
            ])
            ->withHeaders(['Dropbox-API-Arg' => $apiArgs])
            ->withBody($stream, 'application/octet-stream')
            ->post('https://content.dropboxapi.com/2/files/upload');

        if (is_resource($stream)) {
            fclose($stream);
        }

        if (!$response->successful()) {
            Log::error('SyncShootFileToDropboxJob: Dropbox upload failed', [
                'shoot_file_id' => $shootFile->id,
                'status' => $response->status(),
                'error' => $response->json() ?: $response->body(),
            ]);
            return;
        }

        $fileData = $response->json();
        $shootFile->update([
            'dropbox_path' => $fileData['path_display'] ?? $dropboxPath,
            'dropbox_file_id' => $fileData['id'] ?? null,
        ]);

        Log::info('SyncShootFileToDropboxJob: Dropbox sync complete', [
            'shoot_file_id' => $shootFile->id,
            'dropbox_path' => $fileData['path_display'] ?? $dropboxPath,
        ]);
    }

    protected function resolveLocalPath(ShootFile $shootFile): ?string
    {
        $candidate = $shootFile->path ?: $shootFile->storage_path;
        if (!$candidate) {
            return null;
        }

        if (file_exists($candidate)) {
            return $candidate;
        }

        if (Storage::disk('public')->exists($candidate)) {
            return Storage::disk('public')->path($candidate);
        }

        if (Storage::disk('local')->exists($candidate)) {
            return Storage::disk('local')->path($candidate);
        }

        return null;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SyncShootFileToDropboxJob: Failed permanently', [
            'shoot_file_id' => $this->shootFileId,
            'error' => $exception->getMessage(),
        ]);
    }
}
