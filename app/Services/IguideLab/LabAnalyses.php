<?php

namespace App\Services\IguideLab;

use App\Exceptions\OpenAiImageException;
use App\Jobs\ProcessIguideLabAnalysis;
use App\Models\IguideLabAnalysis;
use App\Models\IguideLabCase;
use App\Models\IguideLabProject;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class LabAnalyses
{
    public const EDIT_DEFAULTS = ['roomType' => 'auto', 'furnitureDensity' => 'balanced', 'lightingIntensity' => 'natural',
        'preserveArchitecture' => true, 'preserveFixedFinishes' => true, 'allowFinishChanges' => false,
        'declutterMode' => 'generic', 'selectedObjects' => [], 'preserveObjects' => []];

    public function ready(): bool { return app(LabCredentials::class)->ready() && app(LabAnalysisViews::class)->ready(); }

    public function editSettings(array $input): array
    {
        $options = array_replace(self::EDIT_DEFAULTS, array_intersect_key($input, self::EDIT_DEFAULTS));
        foreach (['preserveArchitecture', 'preserveFixedFinishes', 'allowFinishChanges'] as $flag) $options[$flag] = (bool) $options[$flag];
        return $options;
    }

    public function start(IguideLabProject $project, array $input, ?array $workflowInvocation = null): IguideLabAnalysis
    {
        app(LabWorkflowInvocation::class)->verifyInput($workflowInvocation, $project->id, $input, 'analysis');
        $case = IguideLabCase::where('project_id', $project->id)->findOrFail($input['caseId']);
        abort_if($case->projection === 'equirectangular' && $case->width < 2048, 422, 'Use a panorama at least 2048 pixels wide for diagnostic views.');
        $options = $this->editSettings($input['options'] ?? []);
        abort_if($options['allowFinishChanges'] && $input['preset'] !== 'interior-design', 422, 'Finish changes require interior design.');
        abort_if($options['declutterMode'] === 'selected-object' && ($input['preset'] !== 'declutter' || empty($options['selectedObjects'])), 422, 'Selected-object analysis requires explicit targets.');
        abort_if($options['declutterMode'] === 'generic' && ($options['selectedObjects'] || $options['preserveObjects']), 422, 'Object lists require selected-object mode.');
        $style = $input['style'] ?? (in_array($input['preset'], ['staging', 'interior-design']) ? 'modern' : null);
        $settings = ['preset' => $input['preset'], 'style' => $style, 'instructions' => trim($input['instructions'] ?? ''), 'options' => $options,
            'protocolVersion' => LabAnalysisRecipes::LEGACY_PROTOCOL, 'viewRecipe' => LabAnalysisRecipes::LEGACY_VIEW_RECIPE];
        $repairContext = $input['repairContext'] ?? null;
        if ($repairContext !== null) {
            abort_unless(is_array($workflowInvocation) && is_array($repairContext) && $case->projection === 'equirectangular'
                && in_array($input['preset'], ['golden-hour','twilight'], true), 422, 'Repair context is server-owned lighting evidence.');
            $item = \App\Models\IguideLabWorkflowItem::where('project_id', $project->id)->findOrFail($workflowInvocation['itemId']);
            app(LabWorkflowRepairContext::class)->verify($item, $repairContext);
        }
        abort_if(mb_strlen($this->contextInstructions($settings)) > 4000, 422, 'Combined analysis instructions and object lists are too long.');
        $hash = hash('sha256', json_encode([$case->id, $case->source_sha256, $case->projection, $settings], JSON_THROW_ON_ERROR));
        if ($repairContext !== null) $hash = LabAnalysisRecipes::hash([$hash, $repairContext]);
        if ($workflowInvocation !== null) $hash = LabAnalysisRecipes::hash([$hash, $workflowInvocation]);
        // Keep normalized intent identity across recipe upgrades; validate bytes outside the write lock.
        $prior = IguideLabAnalysis::where('project_id', $project->id)->where('request_id', $input['requestId'])->exists();
        $sourceContext = ($prior || $repairContext !== null) ? null : app(LabAnalysisSourceContext::class)->capture($case);
        [$record, $created] = LockedWrite::run(fn () => DB::transaction(function () use ($project, $case, $input, $hash, $settings, $sourceContext, $repairContext, $workflowInvocation) {
            IguideLabProject::whereKey($project->id)->update(['updated_at' => now()]); abort_unless($project->fresh()->active(), 401);
            $saved = IguideLabAnalysis::where('project_id', $project->id)->where('request_id', $input['requestId'])->first();
            if ($saved) { abort_unless(hash_equals($saved->request_hash, $hash), 409, 'This analysis request already has different source or settings.'); return [$saved, false]; }
            abort_unless($this->ready(), 503, 'Scene analysis is unavailable.');
            abort_if(IguideLabAnalysis::where('project_id', $project->id)->count() >= 120 || IguideLabAnalysis::where('created_at', '>=', now()->subDay())->count() >= 120, 429, 'The analysis safety ceiling has been reached.');
            if ($workflowInvocation !== null) app(LabWorkflowInvocation::class)->verify($workflowInvocation, $project->id, $input['requestId'], 'analysis', false);
            if ($workflowInvocation !== null) {
                $policy = app(LabWorkflowInvocation::class)->policy($workflowInvocation);
                $snapshot = $repairContext !== null ? ($policy['lightingRepair']['analysisRecipe'] ?? null)
                    : ($sourceContext ? ($policy['sectionAnalysisRecipe'] ?? null) : $policy['analysisRecipe']);
                abort_unless(is_array($snapshot), 422, 'The workflow did not reserve a section-context recipe.');
            } else $snapshot = $sourceContext ? app(LabAnalysisRecipes::class)->snapshot(LabAnalysisRecipes::SECTION_CONTEXT) : app(LabAnalysisRecipes::class)->active();
            app(LabAnalysisRecipes::class)->validate($snapshot);
            $executionSettings = $settings;
            if ($sourceContext) $executionSettings['viewRecipe'] = LabAnalysisSourceContext::VIEW_RECIPE;
            if ($repairContext !== null) $executionSettings['viewRecipe'] = LabWorkflowRepairContext::VIEW_RECIPE;
            abort_unless($snapshot['recipe']['protocolVersion'] === $executionSettings['protocolVersion'] && $snapshot['recipe']['viewRecipe'] === $executionSettings['viewRecipe'], 503, 'This analysis protocol is unavailable.');
            return [IguideLabAnalysis::create(['project_id' => $project->id, 'case_id' => $case->id, 'request_id' => $input['requestId'], 'request_hash' => $hash,
                'source_sha256' => $case->source_sha256, 'settings' => $executionSettings, 'status' => 'queued',
                'data' => ['originalSourceSha256' => $sourceContext['binding']['nativeSourceSha256'] ?? $case->metadata['originalSha256'] ?? null,
                    'executionRecipe' => $snapshot, 'recipeCaptureMode' => 'reservation_snapshot', ...($sourceContext ? ['sourceContext' => $sourceContext] : []),
                    ...($repairContext === null ? [] : ['repairContext' => $repairContext]),
                    ...($workflowInvocation === null ? [] : ['workflowInvocation' => $workflowInvocation])]]), true];
        }), 'iguide-lab.analysis-reserve');
        if ($created) {
            try { ProcessIguideLabAnalysis::dispatch($record->id); }
            catch (Throwable) { $this->attention($record, 'The analysis queue submission could not be confirmed. No automatic duplicate will be sent.'); }
        }
        return $record->fresh();
    }

    public function process(IguideLabAnalysis $record): void
    {
        if (in_array($record->status, ['completed', 'failed', 'needs_attention'], true)) return;
        $project = IguideLabProject::find($record->project_id);
        if (! $project?->active() || $record->started_at?->lt(now()->subMinutes(30))) { $this->attention($record, 'This analysis expired before completion.'); return; }
        if ($record->status === 'submitting') { $this->attention($record, 'A prior paid analysis response is unconfirmed and will not be repeated.'); return; }
        try {
            $recipes = app(LabAnalysisRecipes::class); $savedData = $record->data ?? [];
            abort_if(! array_key_exists('executionRecipe', $savedData) && isset($savedData['recipeCaptureMode']), 422, 'Reserved analysis recipe is missing.');
            $snapshot = array_key_exists('executionRecipe', $savedData) ? $savedData['executionRecipe'] : $recipes->legacy($record->settings);
            $recipe = $recipes->validate($snapshot);
            abort_unless($recipe['protocolVersion'] === $record->settings['protocolVersion'] && $recipe['viewRecipe'] === $record->settings['viewRecipe'], 422, 'Analysis recipe and source preparation differ.');
            if (! isset($savedData['executionRecipe'])) {
                $this->save($record, ['data' => array_merge($savedData, ['executionRecipe' => $snapshot, 'recipeCaptureMode' => 'legacy_registry_resolution'])]);
            }
            $this->save($record, ['status' => 'preparing', 'started_at' => $record->started_at ?? now()]);
            $case = IguideLabCase::where('project_id', $record->project_id)->findOrFail($record->case_id);
            abort_unless(hash_equals($record->source_sha256, $case->source_sha256), 422, 'The source association changed.');
            $hasContext = $recipe['compilerVersion'] === LabSectionAnalysisProtocol::VERSION;
            $hasRepairContext = $recipe['compilerVersion'] === LabRepairAnalysisProtocol::VERSION;
            abort_unless($hasContext === isset($record->data['sourceContext']), 422, 'The analysis context recipe association changed.');
            abort_unless($hasRepairContext === isset($record->data['repairContext']), 422, 'The repair recipe association changed.');
            $prepared = $hasRepairContext ? app(LabWorkflowRepairContext::class)->prepare($record, $case)
                : ($hasContext ? app(LabSectionAnalysisViews::class)->prepare($record, $case, $record->data['sourceContext'])
                    : app(LabAnalysisViews::class)->prepare($record, $case));
            $data = array_merge($record->data ?? [], $prepared); $this->save($record, ['data' => $data]);
            $settings = $record->settings;
            $context = ['sourceSha256' => $record->source_sha256, 'projection' => $case->projection, 'preset' => $settings['preset'], 'style' => $settings['style'],
                'viewIds' => array_column($data['views'], 'id'), 'instructions' => $this->contextInstructions($settings)];
            if ($hasContext) $context['sourceContext'] = $data['sourceContextManifest'];
            if ($hasRepairContext) $context['repairContext'] = $data['repairContextManifest'];
            $disk = Storage::disk(LabService::DISK); $images = [];
            foreach ($data['views'] as $view) {
                $bytes = $disk->get($view['path']); abort_unless(hash_equals($view['sha256'], hash('sha256', $bytes)), 422, 'Analysis view fingerprint changed.');
                $images[] = 'data:image/png;base64,'.base64_encode($bytes);
            }
            $payload = $recipes->request($snapshot, $context, $images);
            $bodyHash = LabAnalysisRecipes::hash($payload);
            $this->save($record, ['data' => array_merge($record->data, ['providerBodySha256' => $bodyHash])]);
            // Persist source, pinned instruction recipe and exact body hash before any paid claim.
            $disk->put('iguide-lab/'.$record->project_id.'/analyses/'.$record->id.'/input-manifest.json', json_encode(['context' => $context, 'views' => $data['views'],
                'executionRecipeSha256' => $snapshot['recipeSha256'], 'providerBodySha256' => $bodyHash], JSON_THROW_ON_ERROR), ['visibility' => 'private']);
            $claimed = app(LabWorkflowInvocation::class)->claim($record->data['workflowInvocation'] ?? null, $record->project_id, $record->request_id, 'analysis',
                fn () => LockedWrite::run(fn () => IguideLabAnalysis::whereKey($record->id)->where('status', 'preparing')->update(['status' => 'submitting', 'updated_at' => now()]), 'iguide-lab.analysis-claim'));
            if (! $claimed) { $this->attention($record, 'A previous analysis submission is unconfirmed.'); return; }
            $record->refresh(); $response = app(LabAnalysisProvider::class)->analyze($record, $payload);
            $parsed = $recipes->parse($snapshot, $response, $context);
            $proposal = $parsed['proposal']; $data = $record->data;
            $data['responseModel'] = is_string($response['model'] ?? null) ? $response['model'] : null;
            $this->save($record, ['status' => 'completed', 'proposal' => $proposal, 'proposal_sha256' => hash('sha256', json_encode($proposal, JSON_THROW_ON_ERROR)),
                'data' => $data, 'duration_ms' => (int) $record->started_at->diffInMilliseconds(now()), 'completed_at' => now(), 'error' => null]);
        } catch (OpenAiImageException $error) {
            $this->attention($record, $error->ambiguous ? 'The analysis response could not be confirmed. No automatic retry was sent.' : 'The analysis provider declined this request.', $error->ambiguous ? 'needs_attention' : 'failed');
        } catch (Throwable) {
            $this->attention($record, 'The source analysis or structured proposal needs operator review. No new paid request will be sent.');
        }
    }

    /** Explicitly applied drafts are bound to source and semantic edit controls, not the selected image model. */
    public function appliedDraft(string $projectId, string $id, IguideLabCase $case, string $preset, ?string $style, array $options, string $prompt): array
    {
        $record = IguideLabAnalysis::where('project_id', $projectId)->where('status', 'completed')->findOrFail($id);
        abort_unless($record->case_id === $case->id && hash_equals($record->source_sha256, $case->source_sha256)
            && $record->settings['preset'] === $preset && $record->settings['style'] === $style
            && $record->settings['options'] === $this->editSettings($options), 422, 'Source or edit settings changed; analyze again or detach this proposal.');
        abort_unless($prompt !== '', 422, 'Apply a proposed draft explicitly before generating.');
        $contextProvenance = [];
        if (isset($record->data['sourceContext'])) {
            app(LabAnalysisSourceContext::class)->verify($case, $record->data['sourceContext']);
            app(LabSectionAnalysisViews::class)->verifyStored($record);
            $contextProvenance = ['sourceContextBindingSha256' => $record->data['sourceContext']['bindingSha256'], 'sourceContextManifestSha256' => $record->data['sourceContextManifestSha256']];
        }
        if (isset($record->data['repairContext'])) {
            $verifiedRepair = app(LabWorkflowRepairContext::class)->prepare($record, $case);
            abort_unless(($verifiedRepair['repairContextManifestSha256'] ?? null) === ($record->data['repairContextManifestSha256'] ?? null), 422, 'The saved repair context changed.');
            // Full feedback and receipt references remain in the immutable analysis record.
            // Public image provenance keeps the existing analysis ID/proposal/source binding.
        }
        return ['id' => $record->id, 'proposalSha256' => $record->proposal_sha256, 'sourceSha256' => $record->source_sha256,
            'preparedSourceSha256' => $record->data['preparedSourceSha256'], 'appliedDraftSha256' => hash('sha256', $prompt),
            'editedFromProposal' => $prompt !== $record->proposal['generationPrompt'], 'automaticGeneration' => false, ...$contextProvenance];
    }

    public function dto(IguideLabAnalysis $record): array
    {
        $data = $record->data ?? []; $views = [];
        $provenance = app(LabAnalysisRecipes::class)->provenance($data['executionRecipe'] ?? null, $data['recipeCaptureMode'] ?? null, $data['providerBodySha256'] ?? null);
        foreach ($data['views'] ?? [] as $view) {
            unset($view['path']); $view['assetPath'] = '/api/iguide-lab/analyses/'.$record->id.'/views/'.$view['id']; $views[] = $view;
        }
        if (isset($data['repairContext'])) $views = []; // Private paired failure evidence is not a public source diagnostic.
        return ['id' => $record->id, 'requestId' => $record->request_id, 'caseId' => $record->case_id, 'sourceSha256' => $record->source_sha256,
            'originalSourceSha256' => $data['originalSourceSha256'] ?? null, 'preparedSourceSha256' => $data['preparedSourceSha256'] ?? null,
            'settings' => $record->settings, 'protocolVersion' => $record->settings['protocolVersion'] ?? LabAnalysisRecipes::LEGACY_PROTOCOL,
            'requestedModelId' => $provenance ? $data['executionRecipe']['recipe']['model'] : ($record->provider_attempts[0]['requestedModelId'] ?? 'gpt-6-astra'),
            'instructionProvenance' => $provenance,
            'sourceContext' => isset($data['sourceContext']) ? [...$data['sourceContext']['binding'], 'bindingSha256' => $data['sourceContext']['bindingSha256'],
                'manifestSha256' => $data['sourceContextManifestSha256'] ?? null] : null,
            'responseModel' => $data['responseModel'] ?? null, 'providerRequestId' => $record->provider_request_id, 'providerAttempts' => $record->provider_attempts ?? [],
            'status' => $record->status, 'views' => $views, 'coordinateConvention' => $data['coordinateConvention'] ?? null,
            'proposal' => $record->proposal, 'proposalSha256' => $record->proposal_sha256, 'reviewStatus' => 'unreviewed',
            'geometryVerified' => false, 'maskVerified' => false, 'crossViewVerified' => false, 'automaticGeneration' => false,
            // No verified Astra rate is configured for this release. Unknown is never represented as zero.
            'usage' => $record->usage, 'estimatedCostUsd' => null,
            'pricingVersion' => null, 'durationMs' => $record->duration_ms, 'error' => $record->error,
            'createdAt' => $record->created_at->toIso8601String(), 'completedAt' => $record->completed_at?->toIso8601String()];
    }

    public function attention(IguideLabAnalysis $record, string $message, string $status = 'needs_attention'): void
    {
        LockedWrite::run(fn () => IguideLabAnalysis::whereKey($record->id)->whereNotIn('status', ['completed', 'failed'])->update([
            'status' => $status, 'error' => $message, 'completed_at' => now(), 'duration_ms' => $record->started_at ? (int) $record->started_at->diffInMilliseconds(now()) : null, 'updated_at' => now()]), 'iguide-lab.analysis-attention');
    }
    private function save(IguideLabAnalysis $record, array $values): void { LockedWrite::run(fn () => $record->update($values), 'iguide-lab.analysis-state'); }
    private function contextInstructions(array $settings): string
    {
        return $settings['instructions']."\nSelected edit controls: ".json_encode($settings['options'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
