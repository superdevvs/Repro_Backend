<?php

namespace App\Services\IguideLab;

use App\Jobs\ProcessIguideLabComposition;
use App\Models\IguideLabCase;
use App\Models\IguideLabComposition;
use App\Models\IguideLabProject;
use App\Models\IguideLabRun;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/** Reuses paid output bytes with a reviewed support mask; this class never calls a provider. */
class LabCompositions
{
    public function start(IguideLabProject $project, string $kind, string $targetId, array $input): IguideLabComposition
    {
        $masks = app(LabMasks::class); $target = $masks->target($project->id, $kind, $targetId);
        $mask = $masks->verified($project->id, $kind, $targetId, $input['maskId']);
        $run = IguideLabRun::where('project_id', $project->id)->where('status', 'completed')->findOrFail($input['runId']);
        $record = $target['record'];
        abort_unless($kind === 'section' ? $run->case_id === $record->derived_case_id : $run->id === $record->child_run_id, 422, 'This output does not belong to the selected edit surface.');
        abort_unless($run->output_width === 2048 && $run->output_height === ($kind === 'section' ? 2048 : 1024), 422);
        $disk = Storage::disk(LabService::DISK); abort_unless($run->output_path && $disk->exists($run->output_path), 422);
        $data = ['sourceSha256' => $target['sourceSha256'], 'maskSha256' => $mask->sha256,
            'candidateSha256' => hash_file('sha256', $disk->path($run->output_path)),
            'geometrySha256' => $kind === 'section' ? $record->data['geometrySha256'] : null];
        $mode = $input['harmonization'] ?? 'none';
        abort_unless(in_array($mode, ['none', LabHarmonization::MODE], true), 422);
        if ($mode !== 'none') {
            abort_unless($kind === 'section', 422, 'Local harmonization is available only for perspective sections.');
            abort_if(($mask->statistics['editablePixels'] ?? PHP_INT_MAX) > LabHarmonization::LIMITS['maxEditablePixels'], 422, 'Local harmonization supports at most 131,072 editable pixels. Keep this mask and use ordinary composition or a smaller reviewed section.');
            // Replays keep their captured implementation identity across future upgrades.
            $prior = IguideLabComposition::where('project_id', $project->id)->where('request_id', $input['requestId'])->first();
            $captured = $prior?->data['harmonizationRecipe'] ?? null;
            if ($prior) abort_unless(($captured['mode'] ?? null) === $mode, 409);
            else abort_unless(app(LabHarmonization::class)->ready(), 503, 'Local harmonization is unavailable.');
            $data['harmonizationRecipe'] = $captured ?? app(LabHarmonization::class)->snapshot($target['sourceSha256']);
        }
        // Missing/none deliberately contributes no new key to the original V5 identity.
        $hash = hash('sha256', json_encode([$kind, $targetId, $mask->id, $run->id, $data], JSON_THROW_ON_ERROR));
        [$composition, $created] = LockedWrite::run(fn () => DB::transaction(function () use ($project, $kind, $targetId, $target, $mask, $run, $input, $hash, $data) {
            IguideLabProject::whereKey($project->id)->update(['updated_at' => now()]); abort_unless($project->fresh()->active(), 401);
            $saved = IguideLabComposition::where('project_id', $project->id)->where('request_id', $input['requestId'])->first();
            if ($saved) { abort_unless(hash_equals($saved->request_hash, $hash), 409); return [$saved, false]; }
            abort_unless(app(LabSectionGeometry::class)->ready(), 503);
            abort_if(IguideLabComposition::where('project_id', $project->id)->count() >= 120, 429, 'Local composition safety ceiling reached.');
            return [IguideLabComposition::create(['project_id' => $project->id, 'case_id' => $target['caseId'], 'target_kind' => $kind, 'target_id' => $targetId,
                'run_id' => $run->id, 'mask_id' => $mask->id, 'request_id' => $input['requestId'], 'request_hash' => $hash,
                'status' => 'queued', 'data' => $data + ['compositeRequestId' => (string) Str::uuid()]]), true];
        }), 'iguide-lab.composition-reserve');
        if ($created) {
            try { ProcessIguideLabComposition::dispatch($composition->id); }
            catch (Throwable) { $this->attention($composition, 'Local composition queue submission needs operator review.'); }
        }
        return $composition->fresh();
    }

    public function process(IguideLabComposition $record): void
    {
        if (in_array($record->status, ['completed', 'needs_attention'], true)) return;
        try {
            $project = IguideLabProject::findOrFail($record->project_id); abort_unless($project->active(), 401);
            $masks = app(LabMasks::class); $target = $masks->target($project->id, $record->target_kind, $record->target_id); $parent = $target['record'];
            $mask = $masks->verified($project->id, $record->target_kind, $record->target_id, $record->mask_id);
            $case = IguideLabCase::where('project_id', $project->id)->findOrFail($record->case_id);
            $run = IguideLabRun::where('project_id', $project->id)->where('status', 'completed')->findOrFail($record->run_id);
            $section = $record->target_kind === 'section'; $disk = Storage::disk(LabService::DISK); $data = $record->data;
            abort_unless(hash_equals($data['sourceSha256'], $target['sourceSha256']) && hash_equals($data['maskSha256'], $mask->sha256)
                && hash_equals($data['candidateSha256'], hash_file('sha256', $disk->path($run->output_path))), 422);
            if ($section) {
                $derived = IguideLabCase::where('project_id', $project->id)->findOrFail($run->case_id);
                $masks->sectionSnapshot($project->id, $parent->id, $derived, $mask->id);
                abort_unless(hash_equals($data['geometrySha256'], $parent->data['geometrySha256']), 422);
            } else abort_unless($run->id === $parent->child_run_id, 422);
            $sourcePath = $section ? $case->source_path : $parent->data['sourcePreparedPath'];
            $sourceHash = $section ? $parent->source_sha256 : $parent->data['sourcePreparedSha256'];
            abort_unless(hash_equals($sourceHash, hash_file('sha256', $disk->path($sourcePath))), 422);
            app(LabStorage::class)->prepareGeometry($project->id, 'compositions', $record->id);
            $base = 'iguide-lab/'.$project->id.'/compositions/'.$record->id;
            $candidatePath = $disk->path($run->output_path); $candidateHash = $data['candidateSha256'];
            if (isset($data['harmonizationRecipe'])) {
                abort_unless($section && $data['harmonizationRecipe']['sourceCutSha256'] === $derived->source_sha256, 422);
                $harmonization = app(LabHarmonization::class); $harmonization->validate($data['harmonizationRecipe']);
                $harmRequest = ['schemaVersion' => 1, 'privateRoot' => $disk->path('iguide-lab/'.$project->id),
                    'source' => ['path' => $disk->path($derived->source_path), 'sha256' => $derived->source_sha256],
                    'candidate' => ['path' => $candidatePath, 'sha256' => $candidateHash],
                    'mask' => ['path' => $disk->path($mask->path), 'sha256' => $mask->sha256],
                    'recipe' => $data['harmonizationRecipe'], 'outputDirectory' => $disk->path($base.'/harmonized')];
                abort_unless($disk->put($base.'/harmonization-request.json', json_encode($harmRequest, JSON_THROW_ON_ERROR), ['visibility' => 'private']), 500);
                $this->save($record, ['status' => 'compositing', 'started_at' => $record->started_at ?? now()]);
                $harmReport = $harmonization->run($disk->path($base.'/harmonization-request.json'), $data['harmonizationRecipe']);
                if (isset($harmReport['clippingMask'])) {
                    $clip = $harmReport['clippingMask'];
                    abort_unless($clip['path'] === $disk->path($base.'/harmonized/clipping.png') && hash_equals($clip['sha256'], hash_file('sha256', $clip['path'])), 422);
                    $data['harmonizationClippingPath'] = $base.'/harmonized/clipping.png';
                }
                $data['harmonizationReport'] = $harmReport; $this->save($record, ['data' => $data]);
                if ($harmReport['status'] !== 'completed') {
                    $code = $harmReport['errorCode'] ?? 'HARMONIZATION_REJECTED';
                    $this->attention($record, 'Local harmonization stopped ('.$code.'). Existing source, provider output and reviewed mask remain unchanged.'); return;
                }
                $candidatePath = $harmReport['candidate']['path']; $candidateHash = $harmReport['candidate']['sha256'];
                abort_unless($candidatePath === $disk->path($base.'/harmonized/candidate.png') && hash_equals($candidateHash, hash_file('sha256', $candidatePath)), 422);
            }
            $request = ['schemaVersion' => 1, 'operation' => $section ? 'compose-section' : 'compose-grass', 'privateRoot' => $disk->path('iguide-lab/'.$project->id),
                'source' => ['path' => $disk->path($sourcePath), 'sha256' => $sourceHash],
                'candidate' => ['path' => $candidatePath, 'sha256' => $candidateHash],
                'mask' => ['path' => $disk->path($mask->path), 'sha256' => $mask->sha256], 'outputDirectory' => $disk->path($base.'/rendered')];
            if ($section) $request['geometry'] = ['path' => $disk->path($parent->data['geometryPath']), 'sha256' => $data['geometrySha256']];
            $this->save($record, ['status' => 'compositing', 'started_at' => $record->started_at ?? now()]);
            abort_unless($disk->put($base.'/request.json', json_encode($request, JSON_THROW_ON_ERROR), ['visibility' => 'private']), 500, 'Private composition request storage failed.');
            $report = app(LabSectionGeometry::class)->run($disk->path($base.'/request.json'));
            foreach (['native', 'preview', 'alpha'] as $kind) {
                $asset = $report['artifacts'][$kind];
                abort_unless($asset['path'] === $disk->path($base.'/rendered/'.$kind.'.png') && hash_equals($asset['sha256'], hash_file('sha256', $asset['path'])), 422);
            }
            $alphaPath = $base.'/alpha.png'; abort_unless($disk->put($alphaPath, file_get_contents($report['artifacts']['alpha']['path']), ['visibility' => 'private']), 500, 'Private alpha proof storage failed.');
            $meta = $case->metadata ?? []; $dims = $meta['originalDimensions'] ?? [$meta['originalWidth'] ?? $case->width, $meta['originalHeight'] ?? $case->height];
            $native = $section && $case->width > 2048;
            $assetMetadata = fn (array $asset) => array_intersect_key($asset, array_flip(['sha256', 'width', 'height']));
            $child = ['runId' => $run->id, 'requestId' => $run->request_id, 'outputSha256' => $data['candidateSha256'],
                'projection' => $section ? 'rectilinear' : 'equirectangular', 'editScope' => 'masked', 'maskSha256' => $mask->sha256];
            if ($section) $child['view'] = [...$parent->view, 'vFov' => $parent->view['hFov'], 'coordinateConvention' => $report['coordinateConvention']];
            $manifest = ['schemaVersion' => 1, 'requestId' => $data['compositeRequestId'], 'label' => ($section ? 'Section edit' : 'Green grass').' - reviewed support, output review required',
                'preset' => $run->preset, 'style' => $run->style, 'kind' => 'locally_composited_panorama', 'projection' => 'equirectangular',
                'resolutionStrategy' => $native ? 'native_canvas' : 'preview_only', 'compositionMode' => $section ? 'perspective_sections' : 'masked_whole_panorama',
                'source' => ['caseId' => $case->id, 'sourceSha256' => $case->source_sha256, 'originalSha256' => $meta['originalSha256'] ?? $case->source_sha256, 'originalWidth' => $dims[0], 'originalHeight' => $dims[1]],
                'preview' => $assetMetadata($report['artifacts']['preview']), 'native' => $native ? $assetMetadata($report['artifacts']['native']) : null,
                'generatedDetail' => ['width' => $run->output_width, 'height' => $run->output_height, 'description' => 'Actual provider detail is '.$run->output_width.'x'.$run->output_height.'. The output canvas does not restore native generated detail. Protected decoded source pixels remain exact where the actual exported alpha is zero.'],
                'recipeSha256' => $report['adapterSha256'], 'geometryManifestSha256' => $data['geometrySha256'], 'qaManifestSha256' => hash('sha256', json_encode($report, JSON_THROW_ON_ERROR)),
                'children' => [$child], 'lineage' => ['executionContext' => 'studio_queue_reviewed_mask', 'compositionId' => $record->id,
                    'targetKind' => $record->target_kind, 'targetId' => $record->target_id, 'reviewedMaskId' => $mask->id, 'reviewedMaskSha256' => $mask->sha256,
                    'appliedAlphaSha256' => $report['artifacts']['alpha']['sha256'], 'sourcePreparedSha256' => $sourceHash,
                    'sourceOutsideAlphaChangedPixels' => 0, 'preservationVerifiedByWorker' => true, 'automaticAcceptance' => false,
                    'providerCalls' => 0, 'statistics' => $report['statistics']]];
            if (isset($data['harmonizationRecipe'])) $manifest['lineage']['harmonization'] = app(LabHarmonization::class)->dto($data['harmonizationRecipe'], $data['harmonizationReport'], $record->id);
            [$composite] = app(LabComposites::class)->add($project, $case, $report['artifacts']['preview']['path'], $manifest, $native ? $report['artifacts']['native']['path'] : null);
            $data += ['alphaPath' => $alphaPath, 'alphaSha256' => $report['artifacts']['alpha']['sha256'], 'statistics' => $report['statistics']];
            $this->save($record, ['status' => 'completed', 'composite_id' => $composite->id, 'data' => $data, 'completed_at' => now(), 'error' => null]);
        } catch (Throwable) { $this->attention($record, 'Local composition failed source, mask or geometry checks. No provider request was sent.'); }
    }

    public function dto(IguideLabComposition $record): array
    {
        $harmReport = $record->data['harmonizationReport'] ?? null;
        if ($harmReport === null && $record->status === 'needs_attention' && isset($record->data['harmonizationRecipe'])) {
            $harmReport = ['status' => 'needs_attention', 'errorCode' => 'COMPOSITION_PRECHECK_OR_EXECUTION_FAILED'];
        }
        return ['id' => $record->id, 'requestId' => $record->request_id, 'caseId' => $record->case_id, 'targetKind' => $record->target_kind, 'targetId' => $record->target_id,
            'runId' => $record->run_id, 'maskId' => $record->mask_id, 'status' => $record->status, 'compositeId' => $record->composite_id,
            'alphaAssetPath' => isset($record->data['alphaPath']) ? '/api/iguide-lab/compositions/'.$record->id.'/alpha' : null,
            'alphaSha256' => $record->data['alphaSha256'] ?? null, 'statistics' => $record->data['statistics'] ?? null,
            'harmonization' => app(LabHarmonization::class)->dto($record->data['harmonizationRecipe'] ?? null, $harmReport, $record->id),
            'providerCalls' => 0, 'automaticAcceptance' => false, 'error' => $record->error, 'createdAt' => $record->created_at->toIso8601String(), 'completedAt' => $record->completed_at?->toIso8601String()];
    }
    public function attention(IguideLabComposition $record, string $error): void
    {
        LockedWrite::run(fn () => IguideLabComposition::whereKey($record->id)->where('status', '!=', 'completed')->update(['status' => 'needs_attention', 'error' => $error, 'completed_at' => now()]), 'iguide-lab.composition-attention');
    }
    private function save(IguideLabComposition $record, array $values): void { LockedWrite::run(fn () => $record->update($values), 'iguide-lab.composition-state'); }
}
