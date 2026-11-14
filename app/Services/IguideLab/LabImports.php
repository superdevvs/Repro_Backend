<?php

namespace App\Services\IguideLab;

use App\Jobs\ProcessIguideLabImport;
use App\Models\IguideLabCase;
use App\Models\IguideLabImport;
use App\Models\IguideLabProject;
use App\Support\LockedWrite;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;
use Throwable;
use ZipArchive;

class LabImports
{
    public function start(IguideLabProject $project, array $input, ?UploadedFile $archive, ?UploadedFile $metadata): IguideLabImport
    {
        abort_unless($project->active(), 401);
        $kind = $archive ? 'zip' : 'iguide_link';
        $tour = $kind === 'iguide_link' ? app(LabImportNetwork::class)->tour($input['url']) : null;
        $identity = [$kind, $archive ? hash_file('sha256', $archive->getRealPath()) : $tour['pageUrl'], $metadata ? hash_file('sha256', $metadata->getRealPath()) : null];
        $hash = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
        if ($prior = IguideLabImport::where('project_id', $project->id)->where('request_id', $input['requestId'])->first()) {
            abort_unless(hash_equals($prior->request_hash, $hash), 409, 'This import request ID was already used with a different source.'); return $prior;
        }
        $id = (string) Str::uuid(); app(LabStorage::class)->prepareImport($project->id, $id);
        $prefix = $this->prefix($project->id, $id); $disk = Storage::disk(LabService::DISK); $owned = [];
        $private = ['tour' => $tour, 'archivePath' => $prefix.'/source.zip', 'archiveSha256' => $archive ? $identity[1] : null,
            'metadataPath' => $metadata ? $prefix.'/metadata.txt' : null, 'metadataSha256' => $identity[2]];
        try {
            foreach ([[$archive, $private['archivePath']], [$metadata, $private['metadataPath']]] as [$file, $path]) if ($file) {
                $owned[] = $path; $stream = fopen($file->getRealPath(), 'rb');
                try { abort_unless($disk->put($path, $stream, ['visibility' => 'private']), 503, 'Private import storage is unavailable.'); }
                finally { fclose($stream); }
            }
            [$record, $created] = LockedWrite::run(fn () => DB::transaction(function () use ($project, $input, $hash, $id, $kind, $private) {
                IguideLabProject::whereKey($project->id)->update(['updated_at' => now()]); abort_unless($project->fresh()->active(), 401);
                if ($prior = IguideLabImport::where('project_id', $project->id)->where('request_id', $input['requestId'])->first()) {
                    abort_unless(hash_equals($prior->request_hash, $hash), 409, 'This import request ID was already used with a different source.'); return [$prior, false];
                }
                abort_if(IguideLabImport::where('project_id', $project->id)->whereNotIn('status', ['completed', 'needs_attention'])->count() >= 2, 429, 'Two scan imports are already processing.');
                abort_if(IguideLabImport::where('project_id', $project->id)->where('created_at', '>=', now()->subDay())->count() >= 8, 429, 'This lab has reached its daily scan-import limit.');
                return [IguideLabImport::create(['id' => $id, 'project_id' => $project->id, 'request_id' => $input['requestId'],
                    'request_hash' => $hash, 'source_kind' => $kind, 'private_input' => $private, 'status' => 'queued', 'sources' => [],
                    'audit' => ['parserVersion' => LabImportMetadata::VERSION, 'sourceUrl' => $private['tour']['publicUrl'] ?? null,
                        'archiveSha256' => $private['archiveSha256'], 'metadataSha256' => $private['metadataSha256'], 'providerCalls' => 0]]), true];
            }), 'iguide-lab.import.reserve');
            if (! $created) { foreach ($owned as $path) $disk->delete($path); return $record; }
        } catch (Throwable $error) { foreach ($owned as $path) $disk->delete($path); throw $error; }
        try { ProcessIguideLabImport::dispatch($record->id); }
        catch (Throwable) { $this->attention($record, 'The import queue could not be confirmed. No source cases were created.'); }
        return $record->fresh();
    }

    public function process(IguideLabImport $record): void
    {
        $record->refresh(); if (in_array($record->status, ['completed', 'needs_attention'], true)) return;
        $project = IguideLabProject::find($record->project_id);
        if (! $project?->active()) { $this->attention($record, 'The lab session expired before the import completed.'); return; }
        $disk = Storage::disk(LabService::DISK); $prefix = $this->prefix($project->id, $record->id); $staged = [];
        try {
            app(LabStorage::class)->prepareImport($project->id, $record->id);
            $record->update(['status' => 'inspecting', 'started_at' => $record->started_at ?? now()]); $private = $record->private_input;
            if ($record->source_kind === 'iguide_link' && ! $private['archiveSha256']) {
                $network = app(LabImportNetwork::class); $tour = $network->tour($private['tour']['pageUrl']);
                $htmlPath = $prefix.'/tour.html';
                // Failed/terminated downloads have no committed hash and are free to restart.
                $disk->delete([$htmlPath, $private['archivePath']]);
                $network->download($tour['pageUrl'], $disk->path($htmlPath), LabImportMetadata::MAX_BYTES); $disk->setVisibility($htmlPath, 'private');
                $view = app(LabImportMetadata::class)->parse($disk->get($htmlPath));
                abort_unless($view['kind'] === 'iguide_viewObject' && (! $view['alias'] || $view['alias'] === $tour['alias']), 422, 'The tour metadata does not match the requested source.');
                $download = $network->download($tour['archiveUrl'], $disk->path($private['archivePath']), 1024 * 1024 * 1024); $disk->setVisibility($private['archivePath'], 'private');
                $private['archiveSha256'] = $download['sha256']; $private['metadataPath'] = $htmlPath; $private['metadataSha256'] = hash_file('sha256', $disk->path($htmlPath));
                $record->update(['private_input' => $private]);
            }
            abort_unless($disk->exists($private['archivePath']) && hash_equals($private['archiveSha256'], hash_file('sha256', $disk->path($private['archivePath']))), 422, 'The private archive changed before import.');
            $metadata = null;
            if ($private['metadataPath']) {
                $metadata = $disk->get($private['metadataPath']); abort_unless(hash_equals($private['metadataSha256'], hash('sha256', $metadata)), 422, 'The private metadata changed before import.');
            }
            $inventory = app(LabImportArchive::class)->inventory($disk->path($private['archivePath']), $metadata);
            $record->update(['status' => 'importing', 'audit' => $record->audit + ['format' => $inventory['format'], 'warnings' => $inventory['warnings']]]);
            $namespace = $inventory['tourId'] ?? $private['archiveSha256'];
            $zip = new ZipArchive; $zip->open($disk->path($private['archivePath']), ZipArchive::CHECKCONS); $plans = [];
            try {
                foreach ($inventory['sources'] as $source) {
                    $key = hash('sha256', json_encode([$namespace, $source['entry'], $source['sha256'], $inventory['metadataSha256']], JSON_THROW_ON_ERROR));
                    $id = Uuid::uuid5($project->id, 'scan-source/'.$key)->toString();
                    $extension = match ($source['mime']) { 'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg' };
                    $reusePath = null;
                    if ($existing = IguideLabCase::where('project_id', $project->id)->find($id)) {
                        $originId = $existing->metadata['importId'] ?? null;
                        $origin = is_string($originId) ? IguideLabImport::where('project_id', $project->id)->where('status', 'completed')->find($originId) : null;
                        $binding = $origin ? collect($origin->sources)->firstWhere('caseId', $id) : null;
                        abort_unless($binding && ($binding['importSourceKey'] ?? null) === $key && $binding['sourceSha256'] === $source['sha256']
                            && $binding['width'] === $source['width'] && $binding['height'] === $source['height']
                            && $existing->width === $source['width'] && $existing->height === $source['height']
                            && $existing->source_sha256 === $source['sha256'] && $existing->source_path === $this->prefix($project->id, $originId).'/'.$id.'.'.$extension
                            && $disk->exists($existing->source_path) && hash_equals($source['sha256'], hash_file('sha256', $disk->path($existing->source_path))), 409, 'An existing imported source failed its ownership or byte-integrity check.');
                        $reusePath = $existing->source_path;
                    }
                    $path = $prefix.'/'.$id.'.'.$extension; $bytes = app(LabImportArchive::class)->read($zip, $source['entry'], LabImportArchive::MAX_IMAGE_BYTES);
                    abort_unless(hash_equals($source['sha256'], hash('sha256', $bytes)), 422, 'A panorama changed during import.');
                    $staged[] = $path; abort_unless($disk->put($path, $bytes, ['visibility' => 'private']), 503, 'Private panorama storage is unavailable.');
                    $preview = LabImages::prepared($bytes); $previewPath = $prefix.'/'.$id.'-preview.png'; $staged[] = $previewPath;
                    abort_unless($disk->put($previewPath, $preview, ['visibility' => 'private']), 503, 'Private preview storage is unavailable.');
                    $previewSha = hash('sha256', $preview); unset($bytes, $preview);
                    $roomKey = $source['roomId'] === null ? null : 'scan:'.substr(hash('sha256', json_encode([$namespace, $source['buildingId'], $source['floorId'], $source['roomId']], JSON_THROW_ON_ERROR)), 0, 48);
                    $plans[] = $source + ['id' => $id, 'sourcePath' => $path, 'reusePath' => $reusePath, 'importSourceKey' => $key, 'roomKey' => $roomKey, 'previewPath' => $previewPath, 'previewSha256' => $previewSha];
                }
            } finally { $zip->close(); }
            LockedWrite::run(fn () => DB::transaction(function () use ($project, $record, $plans, $inventory, $private, $namespace) {
                IguideLabProject::whereKey($project->id)->update(['updated_at' => now()]); $current = $project->fresh(); abort_unless($current->active(), 401);
                $record->refresh(); if ($record->status === 'completed') return;
                $existing = IguideLabCase::where('project_id', $project->id)->whereIn('id', array_column($plans, 'id'))->get()->keyBy('id');
                $limit = app(LabCaseCapacity::class)->limit($current);
                abort_if(IguideLabCase::where('project_id', $project->id)->count() + count($plans) - $existing->count() > $limit, 429, 'The complete import does not fit the remaining source capacity. No partial import was created.');
                $sources = [];
                foreach ($plans as $plan) {
                    if ($case = $existing->get($plan['id'])) {
                        abort_unless(hash_equals($case->source_sha256, $plan['sha256']) && $case->source_path === $plan['reusePath'] && ($case->metadata['importSourceKey'] ?? null) === $plan['importSourceKey'], 409, 'An imported source identity conflicts with existing data.');
                    } else {
                        $case = IguideLabCase::create(['id' => $plan['id'], 'project_id' => $project->id,
                            'label' => mb_substr(($plan['roomName'] ?? 'Unknown room').' · Panorama '.($plan['panoId'] ?? substr($plan['importSourceKey'], 0, 8)), 0, 160),
                            'room_id' => $plan['roomKey'], 'pano_id' => $plan['panoId'], 'tour_url' => $private['tour']['publicUrl'] ?? null,
                            'projection' => 'equirectangular', 'width' => $plan['width'], 'height' => $plan['height'], 'source_sha256' => $plan['sha256'], 'source_path' => $plan['sourcePath'], 'source_mime' => $plan['mime'],
                            'metadata' => ['role' => 'imported-panorama', 'includeInBatch' => false, 'importId' => $record->id, 'importSourceKey' => $plan['importSourceKey'],
                                'originalSha256' => $plan['sha256'], 'originalDimensions' => [$plan['width'], $plan['height']], 'propertyName' => $inventory['propertyName'],
                                'tourIdentity' => $namespace, 'originalPanoId' => $plan['panoId'], 'originalRoomId' => $plan['roomId'], 'roomName' => $plan['roomName'],
                                'floorId' => $plan['floorId'], 'floorName' => $plan['floorName'], 'buildingId' => $plan['buildingId'], 'camera' => $plan['camera'], 'metadataAuthority' => $plan['authority'],
                                'serverNativeAuthority' => ['kind' => 'scan-import', 'importId' => $record->id, 'sha256' => $plan['sha256']]]]);
                    }
                    $sources[] = ['caseId' => $case->id, 'importSourceKey' => $plan['importSourceKey'], 'sourceSha256' => $case->source_sha256,
                        'width' => $case->width, 'height' => $case->height, 'panoId' => $plan['panoId'], 'roomId' => $plan['roomKey'], 'originalRoomId' => $plan['roomId'],
                        'roomName' => $plan['roomName'], 'floorId' => $plan['floorId'], 'floorName' => $plan['floorName'], 'metadataAuthority' => $plan['authority'],
                        'entry' => $plan['entry'], 'reused' => $existing->has($case->id), 'previewPath' => $plan['previewPath'], 'previewSha256' => $plan['previewSha256'], 'previewDimensions' => [2048, 1024]];
                }
                $record->update(['status' => 'completed', 'error' => null, 'completed_at' => now(), 'sources' => $sources,
                    'audit' => array_merge($record->audit, ['format' => $inventory['format'], 'archiveSha256' => $private['archiveSha256'], 'metadataSha256' => $inventory['metadataSha256'],
                        'expandedBytes' => $inventory['inspection']['expanded_size_bytes'], 'archiveBytes' => $inventory['inspection']['size_bytes'], 'sourceCount' => count($sources), 'warnings' => $inventory['warnings']])]);
            }), 'iguide-lab.import.publish');
            // The exact owned import directory contains only staged assets. Retain case
            // blobs, remove archive/metadata and any crash leftovers not referenced by cases.
            $this->cleanupUnused($project->id, $disk->files($prefix));
        } catch (Throwable $error) {
            $this->cleanupUnused($project->id, $staged);
            $message = $error instanceof ValidationException ? collect($error->errors())->flatten()->first() : ($error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $error->getMessage() : 'The source import could not be completed. No source files were published from this attempt.');
            $this->attention($record, $message);
            $private = $record->fresh()->private_input;
            $disk->delete([$private['archivePath'], $prefix.'/tour.html']);
        }
    }

    private function cleanupUnused(string $projectId, array $paths): void
    {
        if (! $paths) return;
        $used = IguideLabCase::where('project_id', $projectId)->whereIn('source_path', $paths)->pluck('source_path')->all();
        foreach (IguideLabImport::where('project_id', $projectId)->where('status', 'completed')->get(['sources']) as $import) foreach ($import->sources as $source) if (isset($source['previewPath'])) $used[] = $source['previewPath'];
        foreach (array_diff($paths, $used) as $path) Storage::disk(LabService::DISK)->delete($path);
    }
    public function attention(IguideLabImport $record, string $message): void
    {
        LockedWrite::run(fn () => IguideLabImport::whereKey($record->id)->where('status', '!=', 'completed')->update(['status' => 'needs_attention', 'error' => mb_substr($message, 0, 1000), 'completed_at' => now(), 'updated_at' => now()]), 'iguide-lab.import.attention');
        if ($record->fresh()->status !== 'completed') $this->cleanupUnused($record->project_id, Storage::disk(LabService::DISK)->files($this->prefix($record->project_id, $record->id)));
    }
    public function dto(IguideLabImport $record): array
    {
        return ['id' => $record->id, 'requestId' => $record->request_id, 'sourceKind' => $record->source_kind, 'status' => $record->status,
            'error' => $record->error, 'audit' => $record->audit, 'sources' => array_map(function ($source) use ($record) {
                unset($source['previewPath']); $source['previewAssetPath'] = '/api/iguide-lab/imports/'.$record->id.'/sources/'.$source['caseId'].'/preview'; return $source;
            }, $record->sources ?? []), 'createdAt' => $record->created_at?->toIso8601String(), 'completedAt' => $record->completed_at?->toIso8601String()];
    }
    private function prefix(string $project, string $id): string { return 'iguide-lab/'.$project.'/imports/'.$id; }
}
