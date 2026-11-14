<?php

namespace App\Services\IguideLab;

use App\Jobs\ProcessIguideLabSection;
use App\Models\IguideLabCase;
use App\Models\IguideLabProject;
use App\Models\IguideLabSection;
use App\Support\LockedWrite;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/** Immutable native bytes and camera cuts; neither operation invokes an AI provider. */
class LabSections
{
    public function attachNative(IguideLabProject $project, IguideLabCase $parent, UploadedFile $image, string $requestId): IguideLabCase
    {
        abort_unless($project->active() && $parent->project_id === $project->id, 404);
        $hash = hash_file('sha256', $image->getRealPath()); $size = @getimagesize($image->getRealPath());
        $expected = $parent->metadata['originalDimensions'] ?? [$parent->metadata['originalWidth'] ?? 0, $parent->metadata['originalHeight'] ?? 0];
        abort_unless(is_string($parent->metadata['originalSha256'] ?? null) && hash_equals($parent->metadata['originalSha256'], $hash)
            && $size && [$size[0], $size[1]] === $expected && $size[0] === 2 * $size[1], 422, 'The native file must exactly match the recorded original scan fingerprint and dimensions.');
        abort_if($image->getSize() > 30 * 1024 * 1024 || $size[0] * $size[1] > 64_000_000
            || ! in_array($size['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp']), 422, 'Native source exceeds the image limits.');
        if ($parent->native_source) return $this->attached($project, $parent, $hash, $requestId);
        app(LabStorage::class)->prepareProject($project->id);
        $id = (string) Str::uuid(); $extension = match ($size['mime']) { 'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg' };
        $path = 'iguide-lab/'.$project->id.'/cases/'.$id.'.'.$extension; $disk = Storage::disk(LabService::DISK);
        try {
            $stream = fopen($image->getRealPath(), 'rb');
            try { abort_unless($disk->put($path, $stream, ['visibility' => 'private']), 500); }
            finally { if (is_resource($stream)) fclose($stream); }
            return LockedWrite::run(fn () => DB::transaction(function () use ($project, $parent, $hash, $requestId, $id, $path, $size) {
                IguideLabProject::whereKey($project->id)->update(['updated_at' => now()]); abort_unless($project->fresh()->active(), 401);
                $current = $parent->fresh();
                if ($current->native_source) return $this->attached($project, $current, $hash, $requestId);
                abort_if(IguideLabCase::where('project_id', $project->id)->count() >= 80, 429);
                $case = IguideLabCase::create(['id' => $id, 'project_id' => $project->id, 'label' => mb_substr($parent->label, 0, 140).' - native source',
                    'room_id' => $parent->room_id, 'pano_id' => $parent->pano_id, 'tour_url' => $parent->tour_url, 'projection' => 'equirectangular',
                    'width' => $size[0], 'height' => $size[1], 'source_sha256' => $hash, 'source_path' => $path, 'source_mime' => $size['mime'],
                    'metadata' => ['role' => 'native-source', 'parentCaseId' => $parent->id, 'includeInBatch' => false, 'originalSha256' => $hash, 'originalDimensions' => [$size[0], $size[1]], 'serverVerifiedNativeAttachment' => true]]);
                $current->update(['native_source' => ['caseId' => $case->id, 'requestId' => $requestId, 'sha256' => $hash, 'width' => $size[0], 'height' => $size[1]]]);
                return $case;
            }), 'iguide-lab.native-attach');
        } finally { if (! IguideLabCase::whereKey($id)->exists()) $disk->delete($path); }
    }

    private function attached(IguideLabProject $project, IguideLabCase $parent, string $hash, string $requestId): IguideLabCase
    {
        $saved = $parent->native_source;
        abort_unless($saved['requestId'] === $requestId && hash_equals($saved['sha256'], $hash), 409, 'This source already has an immutable native attachment.');
        return IguideLabCase::where('project_id', $project->id)->findOrFail($saved['caseId']);
    }

    public function start(IguideLabProject $project, array $input): IguideLabSection
    {
        $case = IguideLabCase::where('project_id', $project->id)->findOrFail($input['caseId']);
        abort_unless($case->projection === 'equirectangular' && $case->width >= 2048 && $case->width === 2 * $case->height, 422, 'Use an actual uploaded full panorama at least2048 pixels wide.');
        // Metadata about a high-resolution original cannot stand in for its actual uploaded pixels.
        abort_if(isset($case->metadata['originalSha256']) && ! hash_equals($case->metadata['originalSha256'], $case->source_sha256), 422, 'Attach the hash-matched original scan before native section editing.');
        $view = ['yaw' => (float) $input['view']['yaw'], 'pitch' => (float) $input['view']['pitch'], 'hFov' => (float) $input['view']['hFov']];
        $hash = hash('sha256', json_encode([$case->id, $case->source_sha256, $view], JSON_THROW_ON_ERROR));
        [$record, $created] = LockedWrite::run(fn () => DB::transaction(function () use ($project, $case, $input, $view, $hash) {
            IguideLabProject::whereKey($project->id)->update(['updated_at' => now()]); abort_unless($project->fresh()->active(), 401);
            $saved = IguideLabSection::where('project_id', $project->id)->where('request_id', $input['requestId'])->first();
            if ($saved) { abort_unless(hash_equals($saved->request_hash, $hash), 409); return [$saved, false]; }
            abort_unless(app(LabSectionGeometry::class)->ready(), 503, 'Section geometry is unavailable.');
            abort_if(IguideLabSection::where('project_id', $project->id)->count() >= 120, 429, 'Section safety ceiling reached.');
            return [IguideLabSection::create(['project_id' => $project->id, 'case_id' => $case->id, 'request_id' => $input['requestId'],
                'request_hash' => $hash, 'source_sha256' => $case->source_sha256, 'view' => $view, 'status' => 'queued']), true];
        }), 'iguide-lab.section-reserve');
        if ($created) {
            try { ProcessIguideLabSection::dispatch($record->id); }
            catch (Throwable) { $this->attention($record, 'Section queue submission needs operator review.'); }
        }
        return $record->fresh();
    }

    public function process(IguideLabSection $record): void
    {
        if (in_array($record->status, ['completed', 'needs_attention'], true)) return;
        try {
            $project = IguideLabProject::findOrFail($record->project_id); abort_unless($project->active(), 401);
            $case = IguideLabCase::where('project_id', $project->id)->findOrFail($record->case_id); $disk = Storage::disk(LabService::DISK);
            abort_unless(hash_equals($record->source_sha256, $case->source_sha256) && hash_equals($record->source_sha256, hash_file('sha256', $disk->path($case->source_path))), 422);
            $this->save($record, ['status' => 'extracting', 'started_at' => $record->started_at ?? now()]);
            app(LabStorage::class)->prepareGeometry($project->id, 'sections', $record->id);
            $base = 'iguide-lab/'.$project->id.'/sections/'.$record->id;
            $request = ['schemaVersion' => 1, 'operation' => 'extract', 'privateRoot' => $disk->path('iguide-lab/'.$project->id),
                'source' => ['path' => $disk->path($case->source_path), 'sha256' => $record->source_sha256], 'view' => $record->view, 'outputDirectory' => $disk->path($base.'/geometry')];
            abort_unless($disk->put($base.'/request.json', json_encode($request, JSON_THROW_ON_ERROR), ['visibility' => 'private']), 500, 'Private geometry request storage failed.');
            $report = app(LabSectionGeometry::class)->run($disk->path($base.'/request.json'));
            $cut = $report['artifacts']['cut']; $geometry = $report['artifacts']['geometry'];
            abort_unless($cut['path'] === $disk->path($base.'/geometry/cut/views/section.png') && $geometry['path'] === $disk->path($base.'/geometry/cut/manifest.json')
                && hash_equals($cut['sha256'], hash_file('sha256', $cut['path'])) && hash_equals($geometry['sha256'], hash_file('sha256', $geometry['path'])), 422);
            $derived = app(LabService::class)->addCase($project, new UploadedFile($cut['path'], 'section.png', null, null, true),
                ['label' => mb_substr($case->label, 0, 100).' - section '.substr($record->id, 0, 8), 'roomId' => $case->room_id, 'panoId' => $case->pano_id,
                    'tourUrl' => $case->tour_url, 'projection' => 'rectilinear', 'metadata' => ['role' => 'section-source', 'includeInBatch' => false,
                        'parentCaseId' => $case->id, 'sectionId' => $record->id, 'nativeSourceSha256' => $case->source_sha256, 'view' => $record->view, 'geometrySha256' => $geometry['sha256']]]);
            $this->save($record, ['status' => 'completed', 'derived_case_id' => $derived->id, 'data' => ['cutSha256' => $cut['sha256'],
                'geometryPath' => $base.'/geometry/cut/manifest.json', 'geometrySha256' => $geometry['sha256'], 'geometryVersion' => $report['geometryVersion'],
                'sourceDimensions' => $report['sourceDimensions'], 'adapterSha256' => $report['adapterSha256'], 'geometryCodeSha256' => $report['geometryCodeSha256']], 'completed_at' => now(), 'error' => null]);
        } catch (Throwable) { $this->attention($record, 'Section extraction failed source, geometry or storage checks. No provider request was sent.'); }
    }

    public function dto(IguideLabSection $record): array
    {
        return ['id' => $record->id, 'requestId' => $record->request_id, 'caseId' => $record->case_id, 'status' => $record->status,
            'sourceSha256' => $record->source_sha256, 'sourceDimensions' => $record->data['sourceDimensions'] ?? null,
            'view' => [...$record->view, 'width' => 2048, 'height' => 2048], 'geometrySha256' => $record->data['geometrySha256'] ?? null,
            'geometryVersion' => $record->data['geometryVersion'] ?? null, 'cutSha256' => $record->data['cutSha256'] ?? null,
            'cutAssetPath' => $record->derived_case_id ? '/api/iguide-lab/assets/case/'.$record->derived_case_id : null, 'derivedCaseId' => $record->derived_case_id,
            'error' => $record->error, 'createdAt' => $record->created_at->toIso8601String(), 'completedAt' => $record->completed_at?->toIso8601String()];
    }

    public function attention(IguideLabSection $record, string $error): void
    {
        LockedWrite::run(fn () => IguideLabSection::whereKey($record->id)->where('status', '!=', 'completed')->update(['status' => 'needs_attention', 'error' => $error, 'completed_at' => now()]), 'iguide-lab.section-attention');
    }
    private function save(IguideLabSection $record, array $values): void { LockedWrite::run(fn () => $record->update($values), 'iguide-lab.section-state'); }
}
