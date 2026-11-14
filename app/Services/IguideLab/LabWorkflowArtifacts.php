<?php
namespace App\Services\IguideLab;

use App\Models\IguideLabAnalysis;
use App\Models\IguideLabComposite;
use App\Models\IguideLabRun;
use App\Models\IguideLabWorkflowItem;
use App\Models\IguideLabWorkflowStep;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/** Machine-created support/composition is separate from the user-reviewed mask API. */
class LabWorkflowArtifacts
{
    public const VERSION = 'source-detail-log-odds-lighting/1';

    public function snapshot(): array
    {
        $worker = resource_path('iguide-lab/workflow_qa.py');
        return ['recipeVersion' => self::VERSION, 'adapterSha256' => hash_file('sha256', __FILE__), 'workerSha256' => is_file($worker) ? hash_file('sha256', $worker) : null,
            'lightingSha256' => is_file(resource_path('iguide-lab/lighting_transfer.py')) ? hash_file('sha256', resource_path('iguide-lab/lighting_transfer.py')) : null,
            'sourceAdapterSha256' => hash_file('sha256', resource_path('iguide-lab/workflow_source.py')),
            'geometrySha256' => hash_file('sha256', resource_path('iguide-lab/pano_geometry.py')),
            'preparerSha256' => hash_file('sha256', (new \ReflectionClass(LabImages::class))->getFileName()),
            'runtimeManifestSha256' => is_file((string) config('iguide-lab.workflow_runtime_manifest')) ? hash_file('sha256', config('iguide-lab.workflow_runtime_manifest')) : null];
    }
    public function validateSnapshot(array $snapshot): void
    {
        // Composite manifests canonicalize object-key order before storage.
        $current = $this->snapshot(); ksort($snapshot); ksort($current);
        abort_unless($snapshot === $current, 422, 'The reserved automatic composition implementation changed.');
    }
    public function ready(): bool
    {
        try { $this->runtime(); return true; } catch (\Throwable) { return false; }
    }

    public function preflight(IguideLabWorkflowItem $item, array $snapshot): void
    {
        $this->validateSnapshot($snapshot); $this->runtime();
        $case = app(LabWorkflowSources::class)->verify($item->project_id, $item->source);
        $disk = Storage::disk(LabService::DISK); app(LabStorage::class)->prepareWorkflow($item->project_id, $item->id);
        $base = 'iguide-lab/'.$item->project_id.'/workflow-items/'.$item->id;
        $request = ['source' => ['path' => $disk->path($case->source_path), 'sha256' => $case->source_sha256], 'privateRoot' => $disk->path('iguide-lab/'.$item->project_id)];
        $this->putOnce($base.'/source-preflight-request.json', json_encode($request, JSON_THROW_ON_ERROR));
        if ($disk->exists($base.'/source-preflight.json')) $report = json_decode($disk->get($base.'/source-preflight.json'), true, flags: JSON_THROW_ON_ERROR);
        else {
            $process = new Process([(string) config('iguide-lab.pipeline_python_path'), resource_path('iguide-lab/workflow_source.py'), $disk->path($base.'/source-preflight-request.json')], null, $this->environment(), null, 40);
            $process->run(); $report = json_decode($process->getOutput(), true);
            abort_unless($process->isSuccessful() && ($report['status'] ?? null) === 'completed', 422, 'Normalize unsupported orientation, color profile or encoding before automatic processing.');
            $this->putOnce($base.'/source-preflight.json', json_encode($report, JSON_THROW_ON_ERROR));
        }
        abort_unless(($report['status'] ?? null) === 'completed' && ($report['sourceSha256'] ?? null) === $case->source_sha256
            && ($report['dimensions'] ?? null) === [$case->width, $case->height]
            && ($report['sourceAdapterSha256'] ?? null) === $snapshot['sourceAdapterSha256']
            && $this->runtimeMatches($report['runtime'] ?? [], $snapshot), 422, 'Source encoding preflight changed.');
    }

    public function runHash(IguideLabRun $run): string
    {
        abort_unless($run->status === 'completed' && $run->output_path === 'iguide-lab/'.$run->project_id.'/runs/'.$run->id.'.png', 422);
        $disk = Storage::disk(LabService::DISK); $path = $disk->path($run->output_path); $root = realpath($disk->path('iguide-lab/'.$run->project_id.'/runs'));
        abort_unless($root && realpath($path) === $root.DIRECTORY_SEPARATOR.$run->id.'.png' && ! is_link($path) && filesize($path) <= 32 * 1024 * 1024, 422);
        $size = @getimagesize($path);
        abort_unless($size && [$size[0], $size[1]] === [2048, 1024] && $size['mime'] === 'image/png', 422, 'The lighting candidate must be an exact 2K sphere.');
        $hash = hash_file('sha256', $path);
        abort_unless(is_string($run->output_sha256) && preg_match('/\A[a-f0-9]{64}\z/D', $run->output_sha256)
            && hash_equals($run->output_sha256, $hash), 422, 'The completed image differs from its verified stored output.');
        return $hash;
    }
    public function compose(IguideLabWorkflowItem $item, IguideLabWorkflowStep $step, IguideLabRun $run, IguideLabAnalysis $analysis): IguideLabComposite
    {
        $this->validateSnapshot($step->input['recipe']); $this->runtime();
        $case = app(LabWorkflowSources::class)->verify($item->project_id, $item->source);
        abort_unless($run->project_id === $item->project_id && $run->case_id === $case->id && $analysis->case_id === $case->id
            && $analysis->project_id === $item->project_id && $run->options['sourceAnalysis']['id'] === $analysis->id
            && hash_equals($step->input['outputSha256'], $this->runHash($run)), 422, 'Automatic composition lineage changed.');
        $disk = Storage::disk(LabService::DISK); app(LabStorage::class)->prepareWorkflow($item->project_id, $item->id);
        $base = 'iguide-lab/'.$item->project_id.'/workflow-items/'.$item->id;
        $prepared = LabImages::prepared($disk->get($case->source_path)); $preparedHash = hash('sha256', $prepared);
        abort_unless(($run->options['preparedInputSha256'][0] ?? '') === $preparedHash
            && ($analysis->data['preparedSourceSha256'] ?? '') === $preparedHash, 422, 'The exact submitted source preparation differs.');
        $this->putOnce($base.'/source-prepared.png', $prepared);
        $out = $base.'/lighting-'.$step->id;
        $request = ['schemaVersion' => 1, 'operation' => 'transfer-lighting', 'privateRoot' => $disk->path('iguide-lab/'.$item->project_id),
            'source' => ['path' => $disk->path($case->source_path), 'sha256' => $case->source_sha256],
            'guidanceSource' => ['path' => $disk->path($base.'/source-prepared.png'), 'sha256' => $preparedHash],
            'candidate' => ['path' => $disk->path($run->output_path), 'sha256' => $step->input['outputSha256']],
            'preset' => $item->preset, 'outputDirectory' => $disk->path($out), 'editScope' => 'entire_view', 'recipe' => self::VERSION,
            'sourcePreparation' => ['nativeSourceSha256' => $case->source_sha256, 'guidanceSourceSha256' => $preparedHash,
                'preparerSha256' => $step->input['recipe']['preparerSha256'], 'method' => 'LabImages::prepared/GD', 'verifiedByCaller' => true]];
        $report = $this->execute($base.'/lighting-'.$step->id.'-request.json', $request, $step->input['recipe']);
        $preview = $this->asset($report['artifacts']['preview'], $disk->path($out), [2048, 1024]);
        $native = $this->asset($report['artifacts']['native'], $disk->path($out), [$case->width, $case->height]);
        abort_unless(($report['telemetry']['nonfiniteChannels'] ?? -1) === 0 && ($report['telemetry']['policyProtectedNadirChangedPixels'] ?? -1) === 0, 422, 'Lighting transfer failed its numerical invariants.');
        // Existing metadata about an external native original does not promote a preview upload into a native source.
        $original = $case->metadata ?? []; $dims = $original['originalDimensions'] ?? [$original['originalWidth'] ?? $case->width, $original['originalHeight'] ?? $case->height];
        $nativeCanvas = $case->width > 2048 && $dims === [$case->width, $case->height] && ($original['originalSha256'] ?? $case->source_sha256) === $case->source_sha256;
        $manifest = ['requestId' => $step->request_id, 'label' => LabCatalog::PRESETS[$item->preset].' - automatic lighting candidate',
            'schemaVersion' => 1, 'kind' => 'locally_composited_panorama', 'projection' => 'equirectangular', 'preset' => $item->preset, 'style' => $item->style,
            'resolutionStrategy' => $nativeCanvas ? 'native_canvas' : 'preview_only', 'compositionMode' => 'hybrid',
            'source' => ['caseId' => $case->id, 'sourceSha256' => $case->source_sha256, 'originalSha256' => $original['originalSha256'] ?? $case->source_sha256, 'originalWidth' => $dims[0], 'originalHeight' => $dims[1]],
            'preview' => ['sha256' => $preview['sha256'], 'width' => 2048, 'height' => 1024],
            'native' => $nativeCanvas ? ['sha256' => $native['sha256'], 'width' => $case->width, 'height' => $case->height] : null,
            'generatedDetail' => ['width' => 2048, 'height' => 1024, 'description' => '2K generated lighting guidance; original source detail is transformed locally. Native canvas is not native generated detail.'],
            'recipeSha256' => LabAnalysisRecipes::hash($step->input['recipe']), 'geometryManifestSha256' => null,
            'scenePlanSha256' => $analysis->proposal_sha256, 'qaManifestSha256' => hash_file('sha256', $disk->path($out.'/report.json')),
            'children' => [['runId' => $run->id, 'requestId' => $run->request_id, 'outputSha256' => $step->input['outputSha256'], 'projection' => 'equirectangular', 'editScope' => 'entire_view', 'maskSha256' => null, 'view' => null]],
            'lineage' => ['executionContext' => 'unattended_workflow', 'workflowInvocation' => app(LabWorkflowSteps::class)->invocation($step),
                'recipe' => $step->input['recipe'], 'sourcePreparation' => $request['sourcePreparation'], 'parameters' => $report['parameters'],
                'telemetry' => $report['telemetry'], 'limitations' => $report['limitations'], 'operatorInterventions' => 0, 'manualAcceptance' => false,
                'guidanceRole' => 'low_frequency_lighting_only', 'sourceCaveats' => $analysis->proposal['uncertainties'] ?? []]];
        [$composite] = app(LabComposites::class)->add(\App\Models\IguideLabProject::findOrFail($item->project_id), $case, $preview['path'], $manifest, $nativeCanvas ? $native['path'] : null);
        return $composite;
    }
    public function evidence(IguideLabWorkflowItem $item, IguideLabComposite $composite, IguideLabAnalysis $analysis): array
    {
        $case = app(LabWorkflowSources::class)->verify($item->project_id, $item->source);
        abort_unless($composite->case_id === $case->id && $composite->project_id === $item->project_id, 422);
        $this->validateEvidenceAnalysis($item,$composite,$analysis);
        $snapshot = $composite->manifest['lineage']['recipe']; $this->validateSnapshot($snapshot);
        $disk = Storage::disk(LabService::DISK); $base = 'iguide-lab/'.$item->project_id.'/workflow-items/'.$item->id;
        $kind = $composite->native_path ? 'native' : 'preview'; $candidate = $kind === 'native' ? $composite->native_path : $composite->preview_path;
        $out = $base.'/evidence-'.$composite->id;
        $request = ['schemaVersion' => 1, 'operation' => 'build-evidence', 'privateRoot' => $disk->path('iguide-lab/'.$item->project_id),
            'source' => ['path' => $disk->path($case->source_path), 'sha256' => $case->source_sha256],
            'candidate' => ['path' => $disk->path($candidate), 'sha256' => $composite->manifest[$kind]['sha256']],
            'preset' => $item->preset, 'outputDirectory' => $disk->path($out)] + $this->evidenceSupport($item,$composite);
        $report = $this->execute($base.'/evidence-'.$composite->id.'-request.json', $request, $snapshot);
        abort_unless(array_column($report['evidence'] ?? [], 'id') === LabVisualQualityProtocol::VIEW_IDS, 422, 'Automatic evidence coverage changed.');
        $views = $assets = []; $total = 0;
        foreach ($report['evidence'] as $board) {
            $checked = $this->asset($board, $disk->path($out), [2048, 1024]); $total += filesize($checked['path']);
            abort_unless(($board['sourceLeftCandidateRight'] ?? false) === true && ($board['halves'][0]['originalAssetSha256'] ?? null) === $case->source_sha256
                && ($board['halves'][1]['originalAssetSha256'] ?? null) === $composite->manifest[$kind]['sha256'], 422, 'Automatic evidence pair changed.');
            $views[] = ['id' => $board['id'], 'sha256' => $checked['sha256'], 'width' => 2048, 'height' => 1024];
            $assets[] = ['path' => $out.'/'.$board['id'].'.png', 'sha256' => $checked['sha256']];
        }
        abort_if($total > 24 * 1024 * 1024, 422, 'Automatic visual evidence exceeds its bounded request.');
        $blockers = array_values(array_map(fn ($reason) => $reason['code'], array_filter($report['qa']['reviewReasons'] ?? [], fn ($reason) => $reason['blocking'] ?? false)));
        $intent = ['sourceSha256' => $item->source['sha256'], 'preset' => $item->preset, 'style' => $item->style, 'instructions' => $item->instructions];
        $context = ['sourceSha256' => $case->source_sha256, 'candidateSha256' => $composite->manifest[$kind]['sha256'],
            'intentSha256' => LabAnalysisRecipes::hash($intent), 'evidenceManifestSha256' => hash_file('sha256', $disk->path($out.'/report.json')),
            'projection' => 'equirectangular', 'preset' => $item->preset, 'style' => $item->style, 'intent' => json_encode($intent, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'protectedFeatures' => array_column($analysis->proposal['protectedFeatures'], 'description'),
            'sourceCaveats' => [...($analysis->proposal['uncertainties'] ?? []), 'The scene plan has no separate structured optical dependency inventory; independently inspect reflections, shadows and glazing.'],
            'opticalDependencies' => [], 'reviewRequirements' => $this->evaluationReviewRequirements($analysis->proposal, $report['qa']),
            'mechanicalQa' => ['passed' => ($report['qa']['numericIntegrity'] ?? null) === 'pass' && ! $blockers, 'blockingReasons' => $blockers], 'views' => $views];
        return ['compositeId' => $composite->id, 'manifestPath' => $out.'/report.json', 'context' => $context, 'assets' => $assets];
    }

    protected function validateEvidenceAnalysis(IguideLabWorkflowItem $item,IguideLabComposite $composite,IguideLabAnalysis $analysis): void
    {
        $case = app(LabWorkflowSources::class)->verify($item->project_id,$item->source);
        abort_unless($analysis->status === 'completed' && $analysis->project_id === $item->project_id && $analysis->case_id === $case->id
            && $analysis->source_sha256 === $case->source_sha256
            && $analysis->proposal_sha256 === ($composite->manifest['scenePlanSha256'] ?? null)
            && $analysis->proposal_sha256 === LabAnalysisRecipes::hash($analysis->proposal), 422, 'The evidence scene proposal does not match this artifact.');
    }
    protected function evidenceSupport(IguideLabWorkflowItem $item,IguideLabComposite $composite): array { return ['editScope'=>'entire_view']; }

    public function evaluationReviewRequirements(array $proposal, array $qa): array
    {
        $requirements = array_column($proposal['reviewChecks'], 'instruction');
        $flags = array_column(array_filter($qa['reviewReasons'] ?? [], fn ($reason) => ($reason['kind'] ?? null) === 'visual_flag' && ($reason['blocking'] ?? true) === false), 'code');
        $descriptions = [
            'WRAP_CHANGE_REVIEW' => 'Inspect the wrap board: source-relative seam contrast changed.',
            'SUPPORT_RIM_REVIEW' => 'Inspect edit boundaries: source-relative gradient increased at the support rim.',
            'EDGE_CHANGE_REVIEW' => 'Inspect geometry and protected features: many source edges differ from the candidate.',
        ];
        foreach ($descriptions as $code => $description) {
            if (in_array($code, $flags, true)) $requirements[] = $code.': '.$description.' This is a diagnostic flag, not a defect verdict; judge against the requested edit and paired evidence.';
        }
        return $requirements;
    }

    public function verifyComposite(IguideLabWorkflowItem $item, IguideLabComposite $composite, IguideLabWorkflowStep $step, IguideLabRun $run): void
    {
        abort_unless($composite->project_id === $item->project_id && $composite->case_id === $item->case_id
            && $composite->request_id === $step->request_id && $composite->status === 'completed'
            && ($composite->manifest['children'][0]['runId'] ?? null) === $run->id
            && ($composite->manifest['children'][0]['outputSha256'] ?? null) === $this->runHash($run), 422, 'Stored automatic artifact lineage changed.');
        $this->validateSnapshot($composite->manifest['lineage']['recipe']); $disk = Storage::disk(LabService::DISK);
        foreach (['preview' => $composite->preview_path, 'native' => $composite->native_path] as $kind => $path) {
            if ($path === null) continue;
            abort_unless($path === 'iguide-lab/'.$item->project_id.'/composites/'.$composite->id.'-'.$kind.'.png'
                && $disk->exists($path) && ! is_link($disk->path($path))
                && hash_equals($composite->manifest[$kind]['sha256'], hash_file('sha256', $disk->path($path))), 422, 'Stored automatic artifact bytes changed.');
        }
    }

    protected function runtime(): array
    {
        $path = (string) config('iguide-lab.workflow_runtime_manifest'); $python = (string) config('iguide-lab.pipeline_python_path');
        abort_unless(is_executable($python) && is_file($path) && filesize($path) <= 65536, 503, 'Validated automatic runtime is unavailable.');
        $manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        abort_unless(($manifest['validationStatus'] ?? null) === 'passed' && ($manifest['pythonPath'] ?? null) === $python
            && ($manifest['pythonVersion'] ?? null) === '3.12.3' && ($manifest['numpyVersion'] ?? null) === '2.5.4'
            && ($manifest['pillowVersion'] ?? null) === '13.0.0', 503, 'Automatic runtime version changed.');
        foreach (['validatedWorkerSha256' => 'workflow_qa.py', 'validatedLightingSha256' => 'lighting_transfer.py', 'validatedGeometrySha256' => 'pano_geometry.py', 'validatedSourceAdapterSha256' => 'workflow_source.py'] as $key => $file) {
            abort_unless(is_file(resource_path('iguide-lab/'.$file)) && ($manifest[$key] ?? null) === hash_file('sha256', resource_path('iguide-lab/'.$file)), 503, 'Automatic code was not validated with this runtime.');
        }
        abort_unless(($manifest['validatedPreparerSha256'] ?? null) === hash_file('sha256', (new \ReflectionClass(LabImages::class))->getFileName()), 503, 'Automatic source preparation was not validated.');
        return $manifest;
    }
    protected function execute(string $path, array $request, array $snapshot): array
    {
        $this->validateSnapshot($snapshot); $this->runtime(); $disk = Storage::disk(LabService::DISK);
        $this->putOnce($path, json_encode($request, JSON_THROW_ON_ERROR));
        $process = new Process([(string) config('iguide-lab.pipeline_python_path'), resource_path('iguide-lab/workflow_qa.py'), '--request', $disk->path($path)], null, $this->environment(), null, 240);
        $process->run(); $report = json_decode($process->getOutput(), true);
        abort_unless($process->isSuccessful() && is_array($report) && ($report['status'] ?? null) === 'completed'
            && ($report['automaticAcceptance'] ?? true) === false && ($report['providerCalls'] ?? -1) === 0
            && ($report['reviewStatus'] ?? null) === 'unreviewed' && ($report['operation'] ?? null) === $request['operation']
            && ($report['sourceSha256'] ?? null) === $request['source']['sha256'] && ($report['candidateSha256'] ?? null) === $request['candidate']['sha256']
            && $this->runtimeMatches($report['runtime'] ?? [], $snapshot), 422, 'Automatic local processing failed its execution checks.');
        $this->validateSnapshot($snapshot);
        return $report;
    }
    protected function runtimeMatches(array $runtime, array $snapshot): bool
    {
        return ($runtime['python'] ?? null) === '3.12.3' && ($runtime['numpy'] ?? null) === '2.5.4' && ($runtime['pillow'] ?? null) === '13.0.0'
            && ($runtime['workerSha256'] ?? null) === $snapshot['workerSha256']
            && ($runtime['lightingCodeSha256'] ?? null) === $snapshot['lightingSha256']
            && ($runtime['geometrySha256'] ?? null) === $snapshot['geometrySha256'];
    }
    protected function environment(): array
    {
        $env = array_fill_keys(array_keys(array_merge($_SERVER, $_ENV, getenv() ?: [])), false);
        return array_merge($env, ['PATH' => '/usr/bin:/bin', 'LANG' => 'C.UTF-8', 'PYTHONNOUSERSITE' => '1', 'OMP_NUM_THREADS' => '1', 'OPENBLAS_NUM_THREADS' => '1']);
    }
    protected function putOnce(string $path, string $bytes): void
    {
        $disk = Storage::disk(LabService::DISK);
        if ($disk->exists($path)) { abort_unless(hash_equals(hash('sha256', $bytes), hash_file('sha256', $disk->path($path))), 422, 'An immutable local input changed.'); return; }
        abort_unless($disk->put($path, $bytes, ['visibility' => 'private']), 500, 'A private local input could not be saved.');
    }
    protected function asset(array $descriptor, string $outputDirectory, array $dimensions): array
    {
        $path = $descriptor['path'] ?? ''; $real = realpath($path); $root = realpath($outputDirectory);
        abort_unless($real && $root && ! is_link($path) && str_starts_with($real, $root.DIRECTORY_SEPARATOR)
            && filesize($real) <= 128 * 1024 * 1024 && hash_equals($descriptor['sha256'] ?? '', hash_file('sha256', $real)), 422, 'Automatic output bytes changed.');
        $size = @getimagesize($real); abort_unless($size && [$size[0], $size[1]] === $dimensions && $size['mime'] === 'image/png', 422, 'Automatic output dimensions changed.');
        return ['path' => $real, 'sha256' => $descriptor['sha256']];
    }
}
