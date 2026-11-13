<?php

namespace App\Services\IguideLab;

use App\Models\IguideLabCase;
use App\Models\IguideLabComposite;
use App\Models\IguideLabProject;
use App\Models\IguideLabRun;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Stores derived pixels and their lineage; never dispatches a provider job. */
class LabComposites
{
    public function add(IguideLabProject $project, IguideLabCase $case, string $previewFile, array $input, ?string $nativeFile = null): array
    {
        abort_unless($project->active(), 401);
        abort_unless($case->project_id === $project->id, 404);
        abort_unless($case->projection === 'equirectangular', 422, 'The parent must be a full panorama.');
        abort_if(strlen(json_encode($input, JSON_THROW_ON_ERROR)) > 65536, 422, 'The manifest must be at most 64 KB.');
        $sha = ['required', 'regex:/^[a-f0-9]{64}$/'];
        $rules = [
            'requestId' => ['required', 'uuid'], 'label' => ['required', 'string', 'max:160'],
            'preset' => ['required', Rule::in(array_keys(LabCatalog::PRESETS))], 'style' => ['nullable', Rule::in(array_keys(LabCatalog::STYLES))],
            'schemaVersion' => ['required', 'integer', Rule::in([1])],
            'kind' => ['required', Rule::in(['locally_composited_panorama'])],
            'projection' => ['required', Rule::in(['equirectangular'])],
            'resolutionStrategy' => ['required', Rule::in(['preview_only', 'native_canvas'])],
            'compositionMode' => ['required', Rule::in(['masked_whole_panorama', 'perspective_sections', 'hybrid'])],
            'source' => ['required', 'array:caseId,sourceSha256,originalSha256,originalWidth,originalHeight'],
            'source.caseId' => ['required', 'uuid'], 'source.sourceSha256' => $sha,
            'source.originalSha256' => $sha, 'source.originalWidth' => ['required', 'integer', 'min:64', 'max:16000'],
            'source.originalHeight' => ['required', 'integer', 'min:64', 'max:8000'],
            'preview' => ['required', 'array:sha256,width,height'], 'preview.sha256' => $sha,
            'preview.width' => ['required', 'integer', Rule::in([2048])], 'preview.height' => ['required', 'integer', Rule::in([1024])],
            'native' => ['nullable', 'array:sha256,width,height'], 'native.sha256' => ['required_with:native', 'regex:/^[a-f0-9]{64}$/'],
            'native.width' => ['required_with:native', 'integer', 'min:64', 'max:16000'], 'native.height' => ['required_with:native', 'integer', 'min:64', 'max:8000'],
            'generatedDetail' => ['required', 'array:width,height,description'],
            'generatedDetail.width' => ['required', 'integer', 'min:64', 'max:4096'], 'generatedDetail.height' => ['required', 'integer', 'min:64', 'max:4096'],
            'generatedDetail.description' => ['required', 'string', 'max:1000'],
            'recipeSha256' => $sha, 'geometryManifestSha256' => ['nullable', 'regex:/^[a-f0-9]{64}$/'],
            'scenePlanSha256' => ['nullable', 'regex:/^[a-f0-9]{64}$/'], 'qaManifestSha256' => ['nullable', 'regex:/^[a-f0-9]{64}$/'],
            'children' => ['required', 'array', 'min:1', 'max:24'],
            'children.*' => ['required', 'array:runId,requestId,outputSha256,projection,editScope,maskSha256,view'],
            'children.*.runId' => ['required', 'uuid'], 'children.*.requestId' => ['required', 'uuid'], 'children.*.outputSha256' => $sha,
            'children.*.projection' => ['required', Rule::in(['equirectangular', 'rectilinear'])],
            'children.*.editScope' => ['required', Rule::in(['masked', 'entire_view'])],
            'children.*.maskSha256' => ['nullable', 'regex:/^[a-f0-9]{64}$/'],
            'children.*.view' => ['nullable', 'array:yaw,pitch,hFov,vFov,coordinateConvention'],
            'children.*.view.yaw' => ['required_with:children.*.view', 'numeric', 'between:-360,360'],
            'children.*.view.pitch' => ['required_with:children.*.view', 'numeric', 'between:-90,90'],
            'children.*.view.hFov' => ['required_with:children.*.view', 'numeric', 'between:1,179'],
            'children.*.view.vFov' => ['required_with:children.*.view', 'numeric', 'between:1,179'],
            'children.*.view.coordinateConvention' => ['required_with:children.*.view', 'string', 'max:500'],
            'lineage' => ['sometimes', 'array'],
        ];
        // Unknown top-level claims (including accepted/provider/model) must not become trusted DTO fields.
        abort_if(array_diff(array_keys($input), array_filter(array_keys($rules), fn ($key) => ! str_contains($key, '.'))), 422, 'Unsupported manifest field.');
        $manifest = Validator::make($input, $rules)->validate();
        $original = $case->metadata ?? [];
        $dims = $original['originalDimensions'] ?? [$original['originalWidth'] ?? $case->width, $original['originalHeight'] ?? $case->height];
        abort_if(($dims[0] !== $case->width || $dims[1] !== $case->height) && empty($original['originalSha256']), 422, 'The original scan fingerprint must be recorded before importing a derived panorama.');
        $originalSha = $original['originalSha256'] ?? $case->source_sha256;
        abort_unless($manifest['source']['caseId'] === $case->id && hash_equals($case->source_sha256, $manifest['source']['sourceSha256'])
            && hash_equals($originalSha, $manifest['source']['originalSha256']) && $manifest['source']['originalWidth'] === $dims[0]
            && $manifest['source']['originalHeight'] === $dims[1], 422, 'The original panorama provenance does not match this case.');
        $this->verifyPng($previewFile, $manifest['preview'], 30 * 1024 * 1024);
        $native = $manifest['native'] ?? null;
        if ($manifest['resolutionStrategy'] === 'native_canvas') {
            abort_unless($nativeFile && $native, 422, 'Native canvas files require the private operator import.');
            abort_unless($native['width'] === $dims[0] && $native['height'] === $dims[1], 422, 'Native canvas dimensions must match the recorded original.');
            $this->verifyPng($nativeFile, $native, 128 * 1024 * 1024);
        } else {
            abort_if($nativeFile || $native, 422, 'A preview-only artifact cannot claim a native canvas.');
        }
        $detailWidth = $detailHeight = 0;
        $primaryPresetFound = false;
        foreach ($manifest['children'] as $child) {
            $run = IguideLabRun::where('project_id', $project->id)->where('status', 'completed')->findOrFail($child['runId']);
            abort_unless($run->request_id === $child['requestId'] && $run->output_path
                && Storage::disk(LabService::DISK)->exists($run->output_path)
                && hash_equals(hash_file('sha256', Storage::disk(LabService::DISK)->path($run->output_path)), $child['outputSha256']), 422, 'A child provider result does not match its recorded bytes.');
            $source = IguideLabCase::where('project_id', $project->id)->findOrFail($run->case_id);
            if ($run->preset === $manifest['preset']) $primaryPresetFound = true;
            $detailWidth = max($detailWidth, $run->output_width);
            $detailHeight = max($detailHeight, $run->output_height);
            abort_unless($source->projection === $child['projection'], 422, 'Child projection does not match its source.');
            // A child must actually descend from this panorama, including generated-derived helper sources.
            abort_unless($source->id === $case->id || ($source->metadata['parentCaseId'] ?? null) === $case->id, 422, 'A child source must name this parent panorama.');
            abort_if($child['editScope'] === 'masked' && empty($child['maskSha256']), 422, 'Masked edits require a mask fingerprint.');
            abort_if($child['projection'] === 'rectilinear' && (empty($child['view']) || empty($manifest['geometryManifestSha256'])), 422, 'Perspective sections require projection geometry.');
            abort_if($manifest['compositionMode'] === 'masked_whole_panorama' && ($child['projection'] !== 'equirectangular' || $child['editScope'] !== 'masked'), 422, 'Whole-panorama composition requires masked panorama children.');
            abort_if($manifest['compositionMode'] === 'perspective_sections' && $child['projection'] !== 'rectilinear', 422, 'Perspective composition requires perspective children.');
        }
        abort_unless($primaryPresetFound, 422, 'The artifact preset must match at least one child provider run.');
        abort_unless($manifest['generatedDetail']['width'] === $detailWidth && $manifest['generatedDetail']['height'] === $detailHeight, 422, 'Generated detail dimensions must describe the actual child outputs.');
        $manifest = $this->canonical($manifest);
        $hash = hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR));
        $existing = IguideLabComposite::where('project_id', $project->id)->where('request_id', $manifest['requestId'])->first();
        if ($existing) return $this->replay($existing, $hash);
        app(LabStorage::class)->prepareProject($project->id);
        $id = (string) Str::uuid();
        $previewPath = 'iguide-lab/'.$project->id.'/composites/'.$id.'-preview.png';
        $nativePath = $nativeFile ? 'iguide-lab/'.$project->id.'/composites/'.$id.'-native.png' : null;
        try {
            $this->store($previewFile, $previewPath);
            if ($nativeFile) $this->store($nativeFile, $nativePath);
            $result = LockedWrite::run(fn () => DB::transaction(function () use ($project, $case, $manifest, $hash, $id, $previewPath, $nativePath) {
                IguideLabProject::whereKey($project->id)->update(['updated_at' => now()]);
                abort_unless($project->fresh()->active(), 401);
                $existing = IguideLabComposite::where('project_id', $project->id)->where('request_id', $manifest['requestId'])->first();
                if ($existing) return $this->replay($existing, $hash);
                abort_if(IguideLabComposite::where('project_id', $project->id)->count() >= 120, 429, 'This lab has reached its derived artifact limit.');
                return [IguideLabComposite::create(['id' => $id, 'project_id' => $project->id, 'case_id' => $case->id,
                    'request_id' => $manifest['requestId'], 'request_hash' => $hash, 'label' => $manifest['label'], 'status' => 'completed',
                    'preview_path' => $previewPath, 'native_path' => $nativePath, 'manifest' => $manifest]), true];
            }), 'iguide-lab.composite');
        } finally {
            if (! IguideLabComposite::whereKey($id)->exists()) Storage::disk(LabService::DISK)->delete(array_filter([$previewPath, $nativePath]));
        }
        return $result;
    }

    public function dto(IguideLabComposite $record): array
    {
        $manifest = $record->manifest;
        return ['id' => $record->id, 'requestId' => $record->request_id, 'caseId' => $record->case_id,
            'roomId' => IguideLabCase::find($record->case_id)?->room_id, 'label' => $record->label,
            'preset' => $manifest['preset'], 'style' => $manifest['style'] ?? null,
            'kind' => 'locally_composited_panorama', 'status' => $record->status, 'singleProviderOutput' => false,
            'resolutionStrategy' => $manifest['resolutionStrategy'],
            'preview' => $manifest['preview'] + ['assetPath' => '/api/iguide-lab/assets/composite-preview/'.$record->id, 'mime' => 'image/png', 'preview' => true],
            'native' => $record->native_path ? $manifest['native'] + ['assetPath' => '/api/iguide-lab/assets/composite-native/'.$record->id, 'mime' => 'image/png', 'preview' => false] : null,
            'manifest' => $manifest, 'displayMetadata' => app(LabDisplayMetadata::class)->latest($record),
            'latestReview' => app(LabReviews::class)->latest($record), 'createdAt' => $record->created_at->toIso8601String()];
    }

    private function verifyPng(string $file, array $expected, int $maxBytes): void
    {
        $size = is_file($file) ? @getimagesize($file) : false;
        abort_unless($size && filesize($file) <= $maxBytes && $size['mime'] === 'image/png' && $size[0] === 2 * $size[1]
            && $size[0] * $size[1] <= 64_000_000 && $size[0] === $expected['width'] && $size[1] === $expected['height']
            && hash_equals(hash_file('sha256', $file), $expected['sha256']), 422, 'The PNG dimensions or fingerprint do not match the manifest.');
    }

    private function store(string $file, string $path): void
    {
        $stream = fopen($file, 'rb');
        try {
            if (! Storage::disk(LabService::DISK)->put($path, $stream, ['visibility' => 'private'])) throw new \RuntimeException('The derived artifact could not be stored.');
        }
        finally { if (is_resource($stream)) fclose($stream); }
    }

    private function replay(IguideLabComposite $record, string $hash): array
    {
        abort_unless(hash_equals($record->request_hash, $hash), 409, 'This artifact request ID was already used with different content.');
        return [$record, false];
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) return $value;
        if (! array_is_list($value)) ksort($value);
        return array_map(fn ($item) => $this->canonical($item), $value);
    }
}
