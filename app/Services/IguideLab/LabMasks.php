<?php

namespace App\Services\IguideLab;

use App\Models\IguideLabCase;
use App\Models\IguideLabMask;
use App\Models\IguideLabPipeline;
use App\Models\IguideLabProject;
use App\Models\IguideLabSection;
use App\Support\LockedWrite;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

class LabMasks
{
    /** Target identities come from scoped server records; callers cannot supply filesystem paths. */
    public function target(string $projectId, string $kind, string $id): array
    {
        if ($kind === 'section') {
            $section = IguideLabSection::where('project_id', $projectId)->where('status', 'completed')->findOrFail($id);
            return ['caseId' => $section->case_id, 'sourceSha256' => $section->data['cutSha256'], 'width' => 2048, 'height' => 2048, 'record' => $section];
        }
        abort_unless($kind === 'pipeline', 404);
        $pipeline = IguideLabPipeline::where('project_id', $projectId)->where('status', 'completed')->findOrFail($id);
        abort_unless(! empty($pipeline->data['sourcePreparedSha256']) && $pipeline->child_run_id, 422);
        return ['caseId' => $pipeline->case_id, 'sourceSha256' => $pipeline->data['sourcePreparedSha256'], 'width' => 2048, 'height' => 1024, 'record' => $pipeline];
    }

    public function add(IguideLabProject $project, string $kind, string $targetId, UploadedFile $image, string $requestId): array
    {
        $target = $this->target($project->id, $kind, $targetId);
        abort_if($image->getSize() > 8 * 1024 * 1024, 422, 'Mask exceeds8MiB.');
        $bytes = file_get_contents($image->getRealPath()); $uploaded = hash('sha256', $bytes);
        $hash = hash('sha256', json_encode([$kind, $targetId, $target['sourceSha256'], $uploaded], JSON_THROW_ON_ERROR));
        if ($saved = IguideLabMask::where('project_id', $project->id)->where('request_id', $requestId)->first()) {
            abort_unless($project->fresh()->active(), 401); abort_unless(hash_equals($saved->request_hash, $hash), 409); return [$saved, false];
        }
        try { $pixels = LabMaskPixels::normalize($bytes, $target['width'], $target['height'], $kind === 'section'); }
        catch (InvalidArgumentException $error) { abort(422, $error->getMessage()); }
        $id = (string) Str::uuid(); app(LabStorage::class)->prepareGeometry($project->id, 'masks', $id);
        $disk = Storage::disk(LabService::DISK); $base = 'iguide-lab/'.$project->id.'/masks/'.$id;
        $path = $base.'/support.png'; $providerPath = $pixels['providerPng'] !== null ? $base.'/provider-mask.png' : null;
        try {
            abort_unless($disk->put($path, $pixels['png'], ['visibility' => 'private']), 500);
            if ($providerPath) abort_unless($disk->put($providerPath, $pixels['providerPng'], ['visibility' => 'private']), 500);
            return LockedWrite::run(fn () => DB::transaction(function () use ($project, $id, $kind, $targetId, $target, $requestId, $hash, $uploaded, $pixels, $path, $providerPath) {
                IguideLabProject::whereKey($project->id)->update(['updated_at' => now()]); abort_unless($project->fresh()->active(), 401);
                if ($saved = IguideLabMask::where('project_id', $project->id)->where('request_id', $requestId)->first()) {
                    abort_unless(hash_equals($saved->request_hash, $hash), 409); return [$saved, false];
                }
                abort_if(IguideLabMask::where('project_id', $project->id)->count() >= 240, 429, 'Mask safety ceiling reached.');
                return [IguideLabMask::create(['id' => $id, 'project_id' => $project->id, 'case_id' => $target['caseId'], 'target_kind' => $kind, 'target_id' => $targetId,
                    'request_id' => $requestId, 'request_hash' => $hash, 'source_sha256' => $target['sourceSha256'], 'uploaded_sha256' => $uploaded,
                    'sha256' => hash('sha256', $pixels['png']), 'path' => $path, 'provider_path' => $providerPath,
                    'provider_sha256' => $providerPath ? hash('sha256', $pixels['providerPng']) : null,
                    'width' => $target['width'], 'height' => $target['height'], 'statistics' => $pixels['statistics'], 'reviewed_at' => now()]), true];
            }), 'iguide-lab.mask-reserve');
        } finally {
            if (! IguideLabMask::whereKey($id)->exists()) { $disk->delete($path); if ($providerPath) $disk->delete($providerPath); }
        }
    }

    public function verified(string $projectId, string $kind, string $targetId, string $maskId): IguideLabMask
    {
        $target = $this->target($projectId, $kind, $targetId);
        $mask = IguideLabMask::where('project_id', $projectId)->where('target_kind', $kind)->where('target_id', $targetId)->findOrFail($maskId);
        $disk = Storage::disk(LabService::DISK);
        abort_unless(hash_equals($mask->source_sha256, $target['sourceSha256']) && $disk->exists($mask->path)
            && hash_equals($mask->sha256, hash_file('sha256', $disk->path($mask->path))), 422, 'Reviewed support changed.');
        if ($mask->provider_path) abort_unless($disk->exists($mask->provider_path) && hash_equals($mask->provider_sha256, hash_file('sha256', $disk->path($mask->provider_path))), 422, 'Provider mask changed.');
        return $mask;
    }

    public function sectionSnapshot(string $projectId, string $sectionId, IguideLabCase $case, ?string $maskId): array
    {
        $target = $this->target($projectId, 'section', $sectionId); $section = $target['record'];
        abort_unless($section->derived_case_id === $case->id && hash_equals($target['sourceSha256'], $case->source_sha256), 422, 'The selected section does not belong to this source.');
        $disk = Storage::disk(LabService::DISK); $native = IguideLabCase::where('project_id', $projectId)->findOrFail($section->case_id);
        abort_unless(hash_equals($section->data['adapterSha256'] ?? '', hash_file('sha256', resource_path('iguide-lab/section_geometry.py')))
            && hash_equals($section->data['geometryCodeSha256'] ?? '', hash_file('sha256', resource_path('iguide-lab/pano_geometry.py'))), 422, 'The geometry implementation changed; extract a fresh section.');
        abort_unless($disk->exists($case->source_path) && hash_equals($case->source_sha256, hash_file('sha256', $disk->path($case->source_path)))
            && $disk->exists($native->source_path) && hash_equals($section->source_sha256, hash_file('sha256', $disk->path($native->source_path)))
            && $disk->exists($section->data['geometryPath']) && hash_equals($section->data['geometrySha256'], hash_file('sha256', $disk->path($section->data['geometryPath']))), 422, 'Section source or geometry changed.');
        $mask = $maskId ? $this->verified($projectId, 'section', $sectionId, $maskId) : null;
        return ['id' => $section->id, 'nativeCaseId' => $section->case_id, 'nativeSourceSha256' => $section->source_sha256,
            'cutSha256' => $case->source_sha256, 'geometrySha256' => $section->data['geometrySha256'], 'view' => $section->view,
            'maskId' => $mask?->id, 'maskSha256' => $mask?->sha256, 'providerMaskSha256' => $mask?->provider_sha256,
            'providerMaskGuarantee' => $mask ? 'soft_guidance_only' : null];
    }

    public function dto(IguideLabMask $mask): array
    {
        return ['id' => $mask->id, 'requestId' => $mask->request_id, 'targetKind' => $mask->target_kind, 'targetId' => $mask->target_id,
            'sourceSha256' => $mask->source_sha256, 'sha256' => $mask->sha256, 'uploadedSha256' => $mask->uploaded_sha256,
            'width' => $mask->width, 'height' => $mask->height, 'assetPath' => '/api/iguide-lab/masks/'.$mask->id.'/asset',
            'reviewedAt' => $mask->reviewed_at->toIso8601String(), 'reviewStatus' => 'user_confirmed_support', 'statistics' => $mask->statistics,
            'providerMaskSha256' => $mask->provider_sha256, 'providerMaskGuarantee' => $mask->provider_path ? 'soft_guidance_only' : null];
    }
}
