<?php

namespace App\Services\IguideLab;

use App\Exceptions\FalTerminalException;
use App\Exceptions\IguideLabSegmentationException;
use App\Jobs\ProcessIguideLabPipeline;
use App\Models\IguideLabCase;
use App\Models\IguideLabPipeline;
use App\Models\IguideLabProject;
use App\Models\IguideLabRun;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Throwable;

class LabPipelines
{
    public const RECIPE = 'grass-masked-replacement';
    public const VERSION = 'grass-masked-replacement/1';

    public function ready(): bool
    {
        return app(LabCompositor::class)->ready() && filled(config('services.fal.key'));
    }

    public function start(IguideLabProject $project, array $input): IguideLabPipeline
    {
        $case = IguideLabCase::where('project_id', $project->id)->findOrFail($input['caseId']);
        abort_unless($case->projection === 'equirectangular' && $case->width === 2 * $case->height && $case->width >= 2048, 422, 'The grass pipeline requires a full panorama at least 2048 pixels wide.');
        $source = $this->sourceDescriptor($case);
        $existing = null;
        if (! empty($input['existingRunId'])) {
            $existing = IguideLabRun::where('project_id', $project->id)->where('case_id', $case->id)->where('preset', 'grass')->where('status', 'completed')->findOrFail($input['existingRunId']);
            abort_unless($existing->output_width === 2048 && $existing->output_height === 1024 && ! empty($existing->options['preparedInputSha256'][0]), 422, 'The existing run must retain a matching prepared-input fingerprint.');
            abort_if(isset($input['modelId']) || isset($input['prompt']) || ! empty($input['options']), 422, 'Existing outputs do not accept new generation settings.');
        }
        $options = $input['options'] ?? [];
        if (isset($options['complexity'])) abort_unless(LabCatalog::supportsComplexity($input['modelId'] ?? ''), 422, 'This exact model does not support complexity.');
        $settings = ['caseId' => $case->id, 'recipe' => self::RECIPE, 'recipeVersion' => self::VERSION,
            'existingRunId' => $existing?->id, 'modelId' => $existing?->model_id ?? $input['modelId'],
            'prompt' => trim($input['prompt'] ?? ''), 'options' => $options, 'source' => $source];
        $hash = hash('sha256', json_encode($settings, JSON_THROW_ON_ERROR));
        [$pipeline, $created] = LockedWrite::run(fn () => DB::transaction(function () use ($project, $case, $input, $existing, $settings, $hash) {
            IguideLabProject::whereKey($project->id)->update(['updated_at' => now()]);
            $current = $project->fresh(); abort_unless($current->active(), 401);
            $saved = IguideLabPipeline::where('project_id', $project->id)->where('request_id', $input['requestId'])->first();
            if ($saved) { abort_unless(hash_equals($saved->request_hash, $hash), 409, 'This pipeline request ID already has different settings.'); return [$saved, false]; }
            abort_unless($this->ready(), 503, 'The grass pipeline runtime is unavailable.');
            abort_if(! $existing && $current->used_runs >= $current->max_runs, 429, 'This lab has reached its image generation limit.');
            abort_if(IguideLabPipeline::where('created_at', '>=', now()->subDay())->count() >= 120
                || IguideLabPipeline::where('project_id', $project->id)->count() >= 120, 429, 'The segmentation pipeline limit has been reached.');
            return [IguideLabPipeline::create(['project_id' => $project->id, 'case_id' => $case->id,
                'request_id' => $input['requestId'], 'request_hash' => $hash, 'status' => 'queued', 'child_run_id' => $existing?->id,
                'support_request_id' => (string) Str::uuid(), 'support_status' => 'pending',
                'data' => ['settings' => $settings, 'editRequestId' => (string) Str::uuid(), 'compositeRequestId' => (string) Str::uuid(),
                    'plannedCalls' => ['newImages' => $existing ? 0 : 1, 'segmentations' => 1]]]), true];
        }), 'iguide-lab.pipeline-reserve');
        if ($created) {
            try { ProcessIguideLabPipeline::dispatch($pipeline->id); }
            catch (Throwable) { $this->attention($pipeline, 'The pipeline queue submission could not be confirmed. No automatic duplicate will be sent.'); }
        }
        return $pipeline->fresh();
    }

    /** Known receipt polling and deterministic local stages may resume; paid POST ambiguity never does. */
    public function process(IguideLabPipeline $pipeline): bool
    {
        if (in_array($pipeline->status, ['completed', 'needs_attention', 'failed'], true)) return true;
        $project = IguideLabProject::find($pipeline->project_id);
        if (! $project?->active() || $pipeline->started_at?->lt(now()->subMinutes(30))) {
            $this->attention($pipeline, 'This pipeline expired before completion; no new paid request was sent.'); return true;
        }
        $polling = false;
        try {
            if (! $pipeline->started_at) $this->save($pipeline, ['started_at' => now()]);
            $data = $pipeline->data; $settings = $data['settings'];
            if (! $pipeline->child_run_id) {
                $run = app(LabService::class)->start($project, ['caseId' => $pipeline->case_id, 'requestId' => $data['editRequestId'],
                    'modelId' => $settings['modelId'], 'preset' => 'grass', 'prompt' => $settings['prompt'], 'options' => $settings['options']]);
                $this->save($pipeline, ['child_run_id' => $run->id, 'status' => 'editing']);
            }
            $run = IguideLabRun::where('project_id', $project->id)->where('case_id', $pipeline->case_id)->findOrFail($pipeline->child_run_id);
            if (in_array($run->status, ['queued', 'submitting', 'processing'], true)) { $this->save($pipeline, ['status' => 'editing']); return false; }
            if ($run->status !== 'completed') { $this->attention($pipeline, 'The child edit needs review; no segmentation request was sent.'); return true; }
            $case = IguideLabCase::where('project_id', $project->id)->findOrFail($pipeline->case_id);
            $disk = Storage::disk(LabService::DISK);
            $base = 'iguide-lab/'.$project->id.'/pipelines/'.$pipeline->id;
            app(LabStorage::class)->preparePipeline($project->id, $pipeline->id);
            // Validate the saved child pixels before spending a segmentation call.
            abort_unless($run->output_path && $disk->exists($run->output_path), 422, 'The child output is unavailable.');
            $candidate = ImageManager::gd()->read($disk->path($run->output_path));
            abort_unless($candidate->width() === 2048 && $candidate->height() === 1024, 422, 'The child output dimensions are invalid.');
            unset($candidate);
            $outputHash = hash_file('sha256', $disk->path($run->output_path));
            if (isset($data['candidateSha256'])) abort_unless(hash_equals($data['candidateSha256'], $outputHash), 422, 'The child output changed during this pipeline.');
            else { $data['candidateSha256'] = $outputHash; $this->save($pipeline, ['data' => $data]); }
            if (empty($data['sourcePreparedSha256'])) {
                $png = LabImages::prepared($disk->get($case->source_path));
                $hash = hash('sha256', $png);
                abort_unless(hash_equals($run->options['preparedInputSha256'][0] ?? '', $hash), 422, 'The exact submitted source pixels cannot be reproduced.');
                $size = @getimagesizefromstring($png);
                abort_unless($size && $size[0] === 2048 && $size[1] === 1024, 422);
                $data['sourcePreparedPath'] = $base.'/source.png'; $data['sourcePreparedSha256'] = $hash;
                $disk->put($data['sourcePreparedPath'], $png, ['visibility' => 'private']);
                $this->save($pipeline, ['data' => $data]);
            }
            abort_unless(hash_equals($data['sourcePreparedSha256'], hash_file('sha256', $disk->path($data['sourcePreparedPath']))), 422);
            $segmentation = app(LabSegmentation::class);
            if (! $pipeline->support_provider_request_id) {
                $claimed = LockedWrite::run(fn () => IguideLabPipeline::whereKey($pipeline->id)->where('support_status', 'pending')
                    ->update(['support_status' => 'submitting', 'status' => 'segmenting', 'updated_at' => now()]), 'iguide-lab.segmentation-claim');
                if (! $claimed) { $this->attention($pipeline, 'A previous segmentation submission is unconfirmed and will not be repeated.'); return true; }
                $pipeline->refresh();
                $id = $segmentation->submit($disk->get($data['sourcePreparedPath']));
                $this->save($pipeline, ['support_provider_request_id' => $id, 'support_status' => 'processing', 'status' => 'segmenting']);
            }
            if ($pipeline->support_status !== 'completed') {
                $polling = true;
                $result = $segmentation->poll($pipeline->support_provider_request_id);
                $polling = false;
                if ($result === null) return false;
                $masks = [];
                foreach ($result['masks'] as $index => $bytes) {
                    $path = $base.'/mask-'.$index.'.png'; $disk->put($path, $bytes, ['visibility' => 'private']);
                    $masks[] = ['path' => $path, 'sha256' => hash('sha256', $bytes)];
                }
                $disk->put($base.'/support-result.json', json_encode($result['metadata'], JSON_THROW_ON_ERROR), ['visibility' => 'private']);
                $data['masks'] = $masks;
                $this->save($pipeline, ['support_status' => 'completed', 'support_metadata' => ['maskCount' => count($masks),
                    'usage' => is_array($result['metadata']['usage'] ?? null) ? $result['metadata']['usage'] : null, 'costUsd' => null], 'data' => $data]);
            }
            $this->save($pipeline, ['status' => 'compositing']);
            abort_unless(hash_equals($data['candidateSha256'], hash_file('sha256', $disk->path($run->output_path))), 422, 'The child output changed before composition.');
            $request = ['schemaVersion' => 1, 'recipe' => self::RECIPE,
                'source' => ['path' => $disk->path($data['sourcePreparedPath']), 'sha256' => $data['sourcePreparedSha256']],
                'candidate' => ['path' => $disk->path($run->output_path), 'sha256' => $outputHash],
                'masks' => array_map(fn ($mask) => ['path' => $disk->path($mask['path']), 'sha256' => $mask['sha256']], $data['masks']),
                'outputDirectory' => $disk->path($base.'/composed'), 'options' => ['erodeRadius' => 2, 'blurRadius' => 2]];
            $disk->put($base.'/request.json', json_encode($request, JSON_THROW_ON_ERROR), ['visibility' => 'private']);
            $report = app(LabCompositor::class)->run($disk->path($base.'/request.json'));
            abort_unless($report['preview']['path'] === $disk->path($base.'/composed/preview.png')
                && $report['alpha']['path'] === $disk->path($base.'/composed/applied-alpha.png')
                && hash_equals($report['alpha']['sha256'], hash_file('sha256', $report['alpha']['path'])), 422, 'Unexpected compositor artifacts.');
            $data['alphaPath'] = $base.'/alpha.png';
            $disk->put($data['alphaPath'], file_get_contents($report['alpha']['path']), ['visibility' => 'private']);
            $data['diagnostics'] = $report['diagnostics']; $data['statistics'] = $report['statistics'];
            $manifest = ['schemaVersion' => 1, 'requestId' => $data['compositeRequestId'], 'label' => 'Green grass - automatic composition, review required',
                'preset' => 'grass', 'style' => null, 'kind' => 'locally_composited_panorama', 'projection' => 'equirectangular',
                'resolutionStrategy' => 'preview_only', 'compositionMode' => 'masked_whole_panorama', 'source' => $settings['source'],
                'preview' => ['sha256' => $report['preview']['sha256'], 'width' => 2048, 'height' => 1024],
                'generatedDetail' => ['width' => 2048, 'height' => 1024, 'description' => '2048x1024 generated lawn pixels inside automatic SAM support; unchanged pixels outside alpha are verified against the exact submitted PNG.'],
                'recipeSha256' => $report['scriptSha256'], 'qaManifestSha256' => hash('sha256', json_encode($report, JSON_THROW_ON_ERROR)),
                'children' => [['runId' => $run->id, 'requestId' => $run->request_id, 'outputSha256' => $outputHash,
                    'projection' => 'equirectangular', 'editScope' => 'masked', 'maskSha256' => $report['alpha']['sha256']]],
                'lineage' => ['executionContext' => 'studio_queue_pipeline', 'pipelineId' => $pipeline->id, 'sourcePreparedSha256' => $data['sourcePreparedSha256'],
                    'supportRequestId' => $pipeline->support_request_id, 'supportProviderRequestId' => $pipeline->support_provider_request_id,
                    'supportModel' => LabSegmentation::MODEL, 'segmentationMaskSha256' => array_column($data['masks'], 'sha256'),
                    'sourceOutsideAlphaMaxDifference' => 0, 'preservationVerifiedByWorker' => true, 'automaticAcceptance' => false,
                    'maskPolicy' => 'periodic feathering only; no fixture-specific hole repair', 'statistics' => $report['statistics']]];
            [$composite] = app(LabComposites::class)->add($project, $case, $report['preview']['path'], $manifest);
            $this->save($pipeline, ['status' => 'completed', 'composite_id' => $composite->id, 'data' => $data, 'completed_at' => now(), 'error' => null]);
        } catch (FalTerminalException|IguideLabSegmentationException) {
            $pipeline->refresh();
            $this->save($pipeline, ['support_status' => 'failed']);
            $this->attention($pipeline, 'Segmentation returned a terminal rejection or unusable result. No new paid request will be sent.');
        } catch (Throwable) {
            $pipeline->refresh();
            if ($polling && $pipeline->support_provider_request_id && $pipeline->support_status === 'processing' && $pipeline->started_at?->gt(now()->subMinutes(30))) return false;
            $this->attention($pipeline, 'The pipeline needs operator review. No unconfirmed paid request will be repeated.');
        }
        return true;
    }

    public function attention(IguideLabPipeline $pipeline, string $error): void
    {
        LockedWrite::run(fn () => IguideLabPipeline::whereKey($pipeline->id)->where('status', '!=', 'completed')
            ->update(['status' => 'needs_attention', 'error' => $error, 'completed_at' => now(), 'updated_at' => now()]), 'iguide-lab.pipeline-attention');
    }

    public function dto(IguideLabPipeline $pipeline): array
    {
        $data = $pipeline->data;
        return ['id' => $pipeline->id, 'requestId' => $pipeline->request_id, 'caseId' => $pipeline->case_id,
            'recipe' => self::RECIPE, 'recipeVersion' => self::VERSION, 'status' => $pipeline->status, 'childRunId' => $pipeline->child_run_id,
            'support' => ['requestId' => $pipeline->support_request_id, 'modelId' => LabSegmentation::MODEL, 'status' => $pipeline->support_status,
                'providerRequestId' => $pipeline->support_provider_request_id, 'maskCount' => $pipeline->support_metadata['maskCount'] ?? null,
                'usage' => $pipeline->support_metadata['usage'] ?? null, 'costUsd' => null],
            'compositeId' => $pipeline->composite_id, 'sourcePreparedSha256' => $data['sourcePreparedSha256'] ?? null,
            'plannedCalls' => $data['plannedCalls'], 'diagnostics' => $data['diagnostics'] ?? [], 'statistics' => $data['statistics'] ?? null,
            'alphaAssetPath' => isset($data['alphaPath']) ? '/api/iguide-lab/assets/pipeline-alpha/'.$pipeline->id : null,
            'error' => $pipeline->error, 'createdAt' => $pipeline->created_at->toIso8601String(), 'completedAt' => $pipeline->completed_at?->toIso8601String()];
    }

    private function save(IguideLabPipeline $pipeline, array $values): void
    {
        LockedWrite::run(fn () => $pipeline->update($values), 'iguide-lab.pipeline-state');
    }

    private function sourceDescriptor(IguideLabCase $case): array
    {
        $meta = $case->metadata ?? [];
        $dims = $meta['originalDimensions'] ?? [$meta['originalWidth'] ?? $case->width, $meta['originalHeight'] ?? $case->height];
        abort_unless(is_array($dims) && count($dims) === 2 && isset($dims[0], $dims[1]) && is_int($dims[0]) && is_int($dims[1])
            && $dims[0] >= 64 && $dims[0] <= 16000 && $dims[1] >= 64 && $dims[1] <= 8000 && $dims[0] === 2 * $dims[1]
            && $dims[0] * $dims[1] <= 64_000_000, 422, 'Original panorama dimensions are invalid.');
        abort_if(($dims[0] !== $case->width || $dims[1] !== $case->height) && empty($meta['originalSha256']), 422, 'Record the original scan fingerprint before starting this pipeline.');
        abort_unless(is_string($meta['originalSha256'] ?? $case->source_sha256) && preg_match('/^[a-f0-9]{64}$/', $meta['originalSha256'] ?? $case->source_sha256), 422, 'Original panorama fingerprint is invalid.');
        return ['caseId' => $case->id, 'sourceSha256' => $case->source_sha256, 'originalSha256' => $meta['originalSha256'] ?? $case->source_sha256,
            'originalWidth' => $dims[0], 'originalHeight' => $dims[1]];
    }
}
