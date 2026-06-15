<?php

namespace App\Jobs;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\ShootActivityLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Download iGUIDE deliverables (floor plan PDFs, JPG floor plans, etc.)
 * and persist them as ShootFile records of media_type=floorplan so they
 * appear in Media -> Floorplans, the Download Center and the Dropbox mirror.
 */
class IngestIguideAssetsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 600;
    public array $backoff = [30, 120, 300];

    public function __construct(
        public int $shootId,
        /** @var array<int, array<string, mixed>> Floorplan items as produced by IguideService::extractFloorplans */
        public array $floorplans,
    ) {
        $this->onQueue('default');
    }

    public function handle(ShootActivityLogger $activityLogger): void
    {
        $shoot = Shoot::find($this->shootId);
        if (!$shoot) {
            return;
        }

        if (empty($this->floorplans)) {
            return;
        }

        $existingByKey = ShootFile::query()
            ->where('shoot_id', $shoot->id)
            ->where('media_type', 'floorplan')
            ->get()
            ->keyBy(function (ShootFile $f) {
                $metadata = is_array($f->metadata) ? $f->metadata : [];
                return $metadata['iguide_asset_key'] ?? null;
            })
            ->filter(static fn ($_, $key) => is_string($key) && $key !== '');

        // shoot_files.uploaded_by is required (FK + NOT NULL).
        // For system-ingested assets we attribute to the shoot creator,
        // its photographer, or the first available admin/superadmin.
        $uploadedByUserId = $this->resolveSystemUploaderId($shoot);

        $ingestedFileIds = [];
        $disk = Storage::disk('public');

        foreach ($this->floorplans as $item) {
            $url = $item['url'] ?? null;
            $assetKey = $item['asset_key'] ?? null;
            if (!is_string($url) || $url === '' || !is_string($assetKey) || $assetKey === '') {
                continue;
            }

            try {
                if ($existingByKey->has($assetKey)) {
                    continue;
                }

                $filename = $this->sanitizeFilename(
                    $item['filename'] ?? basename(parse_url($url, PHP_URL_PATH) ?: $url) ?: 'iguide-asset'
                );
                $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION) ?: ($item['type'] ?? 'bin'));
                if ($extension === 'jpeg') {
                    $extension = 'jpg';
                }
                $storedFilename = sprintf(
                    '%s-%s.%s',
                    Str::slug(pathinfo($filename, PATHINFO_FILENAME) ?: 'iguide'),
                    substr(md5($assetKey), 0, 8),
                    $extension,
                );
                $relativePath = sprintf('shoots/%d/floorplans/%s', $shoot->id, $storedFilename);

                $response = Http::withOptions([
                    'verify' => config('app.env') === 'production',
                    'timeout' => 120,
                ])->get($url);

                if (!$response->successful()) {
                    Log::warning('IngestIguideAssetsJob: download failed', [
                        'shoot_id' => $shoot->id,
                        'asset_key' => $assetKey,
                        'status' => $response->status(),
                        'url' => $url,
                    ]);
                    continue;
                }

                $binary = $response->body();
                if ($binary === '' || $binary === null) {
                    continue;
                }

                $mimeType = $response->header('Content-Type') ?: $this->guessMimeType($extension);

                $disk->put($relativePath, $binary);
                $publicPath = 'storage/' . $relativePath;

                $shootFile = ShootFile::create([
                    'shoot_id' => $shoot->id,
                    'filename' => $filename,
                    'stored_filename' => $storedFilename,
                    'path' => $publicPath,
                    'storage_path' => $publicPath,
                    'file_type' => $mimeType,
                    'mime_type' => $mimeType,
                    'media_type' => 'floorplan',
                    'file_size' => strlen($binary),
                    'uploaded_by' => $uploadedByUserId,
                    'uploaded_at' => now(),
                    'workflow_stage' => ShootFile::STAGE_COMPLETED,
                    'metadata' => [
                        'source' => 'iguide',
                        'iguide_asset_key' => $assetKey,
                        'units' => $item['units'] ?? null,
                        'asset_type' => $item['type'] ?? null,
                        'floor_name' => $item['floor_name'] ?? null,
                        'floor_id' => $item['floor_id'] ?? null,
                        'label' => $item['label'] ?? null,
                        'original_url' => $url,
                        'ingested_at' => now()->toIso8601String(),
                    ],
                ]);

                $ingestedFileIds[] = $shootFile->id;

                // Mirror into Dropbox if enabled (mirrors how regular media is synced).
                try {
                    SyncShootFileToDropboxJob::dispatch($shootFile->id);
                } catch (\Throwable $e) {
                    Log::warning('IngestIguideAssetsJob: dropbox dispatch failed', [
                        'shoot_file_id' => $shootFile->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                // Mirror into R2 during the dual-write/R2-only cutover.
                if (config('media.dual_write') || config('media.r2_only')) {
                    try {
                        SyncShootFileToR2Job::dispatch($shootFile->id);
                    } catch (\Throwable $e) {
                        Log::warning('IngestIguideAssetsJob: r2 dispatch failed', [
                            'shoot_file_id' => $shootFile->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('IngestIguideAssetsJob: asset ingestion failed', [
                    'shoot_id' => $shoot->id,
                    'asset_key' => $assetKey,
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
        }

        if (!empty($ingestedFileIds)) {
            try {
                $activityLogger->log(
                    $shoot,
                    'iguide_assets_ingested',
                    [
                        'asset_count' => count($ingestedFileIds),
                        'file_ids' => $ingestedFileIds,
                        'iguide_property_id' => $shoot->iguide_property_id,
                        'iguide_work_order_id' => $shoot->iguide_work_order_id,
                    ],
                );
            } catch (\Throwable $e) {
                Log::warning('IngestIguideAssetsJob: activity log failed', [
                    'shoot_id' => $shoot->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('IngestIguideAssetsJob: failed permanently', [
            'shoot_id' => $this->shootId,
            'error' => $exception->getMessage(),
        ]);
    }

    private function resolveSystemUploaderId(Shoot $shoot): ?int
    {
        // Prefer existing related users to satisfy the FK constraint without
        // surprising audit trails.
        if (!empty($shoot->photographer_id)) {
            return (int) $shoot->photographer_id;
        }

        $createdBy = $shoot->created_by;
        if (is_numeric($createdBy)) {
            return (int) $createdBy;
        }

        $admin = User::query()
            ->whereIn('role', ['admin', 'superadmin'])
            ->orderBy('id')
            ->first();
        if ($admin) {
            return (int) $admin->id;
        }

        $any = User::query()->orderBy('id')->first();
        return $any ? (int) $any->id : null;
    }

    private function sanitizeFilename(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?? '';
        if ($name === '' || $name === '.' || $name === '..') {
            return 'iguide-asset';
        }
        return Str::limit($name, 120, '');
    }

    private function guessMimeType(string $extension): string
    {
        return match (strtolower($extension)) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'zip' => 'application/zip',
            'svg' => 'image/svg+xml',
            'dxf' => 'application/dxf',
            default => 'application/octet-stream',
        };
    }
}
