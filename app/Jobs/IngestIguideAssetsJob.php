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
        public ?int $shootServiceId = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(ShootActivityLogger $activityLogger): void
    {
        $shoot = Shoot::find($this->shootId);
        if (!$shoot || $shoot->isInternalTestShoot()) {
            return;
        }

        if ($this->shootServiceId && ! $shoot->serviceItems()->whereKey($this->shootServiceId)->exists()) {
            return;
        }
        if (empty($this->floorplans)) {
            return;
        }

        $existingByKey = ShootFile::query()
            ->where('shoot_id', $shoot->id)
            ->where('media_type', 'floorplan')
            ->where('shoot_service_id', $this->shootServiceId)
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
        $retryPending = false;
        $mediaStorage = app(\App\Services\Media\MediaStorage::class);

        foreach ($this->floorplans as $item) {
            $url = $item['url'] ?? null;
            $assetKey = $item['asset_key'] ?? null;
            if (!is_string($url) || $url === '' || !is_string($assetKey) || $assetKey === '') {
                continue;
            }

            try {
                $existing = $existingByKey->get($assetKey);
                if ($existing && (!$existing->isInvalidProviderFloorplan() || $existing->scan_status === ShootFile::SCAN_STATUS_INFECTED)) {
                    $existing = $existingByKey->get($assetKey);
                    if (in_array($existing->scan_status, [ShootFile::SCAN_STATUS_QUARANTINED, ShootFile::SCAN_STATUS_FAILED], true)) {
                        ScanShootFileJob::dispatch($existing->id)->afterCommit();
                    }
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
                if ($existing) $storedFilename = pathinfo($storedFilename, PATHINFO_FILENAME).'-'.Str::uuid().'.'.$extension;
                $relativePath = $this->shootServiceId
                    ? sprintf('shoots/%d/services/%d/floorplans/%s', $shoot->id, $this->shootServiceId, $storedFilename)
                    : sprintf('shoots/%d/floorplans/%s', $shoot->id, $storedFilename);

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
                // Providers can return a 200 HTML document-generation waiting page.
                // It is not a floorplan and must never be persisted or scanned as one.
                if (str_starts_with(strtolower($mimeType), 'text/html') || preg_match('/^\s*(<!doctype\s+html|<html)/i', $binary)) {
                    $retryPending = true;
                    continue;
                }

                if (!$mediaStorage->put($relativePath, $binary)) {
                    throw new \RuntimeException('The imported floorplan could not be stored.');
                }
                $publicPath = $relativePath;

                $attributes = [
                    'shoot_id' => $shoot->id,
                    'shoot_service_id' => $this->shootServiceId,
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
                    'scan_status' => ShootFile::SCAN_STATUS_QUARANTINED,
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
                ];
                // Publish a fresh complete backing file before changing an existing
                // invalid import. Keep the old HTML payload for the recovery audit.
                if ($existing) {
                    $attributes['uploaded_by'] = $existing->uploaded_by;
                    $attributes['is_hidden'] = data_get($existing->metadata, 'provider_recovery_original_hidden', $existing->is_hidden);
                    $attributes['workflow_stage'] = $existing->workflow_stage;
                    $attributes['metadata'] = array_merge($existing->metadata ?? [], $attributes['metadata'], ['recovered_invalid_payload_path' => $existing->path]);
                    $attributes += ['scan_result' => null, 'scanned_at' => null, 'processing_failed_at' => null, 'processing_error' => null, 'web_path' => null, 'thumbnail_path' => null];
                    $shootFile = \App\Support\LockedWrite::run(function () use ($existing, $attributes) {
                        $file = ShootFile::findOrFail($existing->id);
                        if (!$file->isInvalidProviderFloorplan() || $file->scan_status === ShootFile::SCAN_STATUS_INFECTED) return $file;
                        $file->fill($attributes)->save();
                        return $file;
                    }, 'provider-floorplan.recover-payload');
                } else {
                    $shootFile = ShootFile::create($attributes);
                }

                $ingestedFileIds[] = $shootFile->id;
                // Floorplan originals remain withheld until the real scan is clean.
                ScanShootFileJob::dispatch($shootFile->id)->afterCommit();

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

        if ($retryPending) {
            if ($this->attempts() >= $this->tries) throw new \RuntimeException('Provider floorplan document is not ready; no HTML payload was published.');
            $this->release($this->backoff[min(max(0, $this->attempts() - 1), count($this->backoff) - 1)]);
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
