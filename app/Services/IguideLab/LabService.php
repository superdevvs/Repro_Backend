<?php

namespace App\Services\IguideLab;

use App\Exceptions\FalTerminalException;
use App\Exceptions\OpenAiImageException;
use App\Jobs\ProcessIguideLabRun;
use App\Models\IguideLabCase;
use App\Models\IguideLabComposite;
use App\Models\IguideLabProject;
use App\Models\IguideLabRun;
use App\Support\LockedWrite;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Throwable;

class LabService
{
    public const DISK = 'listing_studio_trials';

    public function addCase(IguideLabProject $project, UploadedFile $image, array $input): IguideLabCase
    {
        abort_unless($project->active(), 401, 'This lab session is inactive.');
        abort_if($image->getSize() > 30 * 1024 * 1024, 422, 'The source image must be at most 30 MB.');
        $size = @getimagesize($image->getRealPath());
        abort_unless($size && in_array($size['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)
            && $size[0] >= 64 && $size[1] >= 64 && $size[0] * $size[1] <= 64_000_000, 422, 'Use a JPG, PNG or WebP image between 64 pixels and 64 megapixels.');
        abort_if($input['projection'] === 'equirectangular' && $size[0] !== 2 * $size[1], 422, 'A full equirectangular panorama must have an exact 2:1 aspect ratio.');
        abort_if($size[0] / $size[1] > 3 || $size[1] / $size[0] > 3, 422, 'The image aspect ratio must be between 1:3 and 3:1.');
        $hash = hash_file('sha256', $image->getRealPath());
        $existing = $this->existingCase($project, $hash, $input);
        if ($existing) return $existing;
        app(LabCaseCapacity::class)->assertRoom($project->fresh());
        app(LabStorage::class)->prepareProject($project->id);
        $id = (string) Str::uuid();
        $extension = match ($size['mime']) { 'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg' };
        $path = 'iguide-lab/'.$project->id.'/cases/'.$id.'.'.$extension;
        $disk = Storage::disk(self::DISK);
        try {
            $stream = fopen($image->getRealPath(), 'rb');
            try { abort_unless($disk->put($path, $stream, ['visibility' => 'private']), 500, 'Private source storage failed.'); }
            finally { if (is_resource($stream)) fclose($stream); }
            return LockedWrite::run(fn () => DB::transaction(function () use ($project, $input, $hash, $id, $path, $size) {
                IguideLabProject::whereKey($project->id)->update(['updated_at' => now()]);
                $current = $project->fresh(); abort_unless($current?->active(), 401);
                $existing = $this->existingCase($project, $hash, $input);
                if ($existing) return $existing;
                app(LabCaseCapacity::class)->assertRoom($current);
                return IguideLabCase::create([
                    'id' => $id, 'project_id' => $project->id, 'label' => $input['label'],
                    'room_id' => $input['roomId'] ?? null, 'pano_id' => $input['panoId'] ?? null, 'tour_url' => $input['tourUrl'] ?? null,
                    'projection' => $input['projection'], 'width' => $size[0], 'height' => $size[1],
                    'source_sha256' => $hash, 'source_path' => $path, 'source_mime' => $size['mime'], 'metadata' => $input['metadata'] ?? [],
                ]);
            }), 'iguide-lab.case');
        } finally { if (! IguideLabCase::whereKey($id)->exists()) $disk->delete($path); }
    }

    private function existingCase(IguideLabProject $project, string $hash, array $input): ?IguideLabCase
    {
        $query = IguideLabCase::where('project_id', $project->id)->where('source_sha256', $hash)->where('label', $input['label']);
        // UUIDv7 display prefixes can collide. Recovery belongs to the full section, not its short label.
        if (($input['metadata']['role'] ?? null) === 'section-source') {
            $query->where('metadata->sectionId', $input['metadata']['sectionId'] ?? null)
                ->where('metadata->parentCaseId', $input['metadata']['parentCaseId'] ?? null);
        }
        return $query->first();
    }

    public function start(IguideLabProject $project, array $input, ?array $workflowInvocation = null, ?\App\Models\IguideLabDeclutterRemovalPlan $removalPlan = null, ?\App\Models\IguideLabInteriorAssessment $interiorPlan = null): IguideLabRun
    {
        app(LabWorkflowInvocation::class)->verifyInput($workflowInvocation, $project->id, $input, 'image');
        $case = IguideLabCase::where('project_id', $project->id)->findOrFail($input['caseId']);
        $requested = $input['options'] ?? [];
        $sectionId = $requested['sectionId'] ?? null; $sectionMaskId = $requested['sectionMaskId'] ?? null;
        abort_if(isset($case->metadata['sectionId']) && $case->metadata['sectionId'] !== $sectionId, 422, 'Use the explicit source-bound section workflow for this helper image.');
        abort_if($sectionMaskId && ! $sectionId, 422, 'Provider masks require a source-bound section.');
        abort_if($sectionMaskId && ! LabCatalog::supportsSectionMask($input['modelId']), 422, 'This exact model is not enabled for section masks.');
        unset($requested['sectionId'], $requested['sectionMaskId']);
        $analysisId = $requested['analysisId'] ?? null;
        $scenePlan = ($requested['promptMode'] ?? 'preset') === 'scene-plan';
        // This fourth argument is internal only. Public controllers never pass
        // a typed record and cannot assert machine authority in request options.
        abort_if($removalPlan !== null && $interiorPlan !== null,422,'A run has exactly one typed source plan.');
        $machinePlan = $removalPlan !== null || $interiorPlan !== null;
        abort_if(!$machinePlan && (isset($requested['sourceMachinePlan']) || in_array($requested['promptMode'] ?? null,['machine-removal-plan','machine-interior-material-plan'],true) || isset($input['removalPlanId']) || isset($input['interiorPlanId'])),422,'Machine drafts require internal source authority.');
        abort_if($machinePlan && ($scenePlan || filled($analysisId)),422,'A typed machine plan is distinct from scene analysis.');
        abort_if($scenePlan !== filled($analysisId), 422, 'Scene-plan drafts require a completed analysis association.');
        unset($requested['analysisId'], $requested['promptMode']);
        $complexity = $requested['complexity'] ?? (LabCatalog::is25($input['modelId']) ? null : 'medium');
        $compositeIds = array_values(array_unique($requested['referenceCompositeIds'] ?? []));
        $selected = ($requested['declutterMode'] ?? 'generic') === 'selected-object';
        abort_if(isset($requested['complexity']) && ! LabCatalog::supportsComplexity($input['modelId']), 422, 'This exact model does not support a complexity setting.');
        abort_if($selected && ($input['preset'] !== 'declutter' || empty($requested['selectedObjects'])), 422, 'Selected-object decluttering requires explicit objects to remove.');
        abort_if(! $selected && (! empty($requested['selectedObjects']) || ! empty($requested['preserveObjects'])), 422, 'Object lists require selected-object decluttering.');
        $selection = $selected ? ['declutterMode' => 'selected-object', 'selectedObjects' => $requested['selectedObjects'], 'preserveObjects' => $requested['preserveObjects'] ?? []] : [];
        unset($requested['complexity'], $requested['declutterMode'], $requested['selectedObjects'], $requested['preserveObjects'], $requested['referenceCompositeIds']);
        // Keep the original default option order and recipe for previously reserved request IDs.
        $options = array_merge(['roomType' => 'auto', 'referenceCaseIds' => [], 'referenceRunIds' => [], 'furnitureDensity' => 'balanced', 'lightingIntensity' => 'natural', 'preserveArchitecture' => true, 'preserveFixedFinishes' => true, 'allowFinishChanges' => false], $requested);
        foreach (['preserveArchitecture', 'preserveFixedFinishes', 'allowFinishChanges'] as $flag) $options[$flag] = (bool) $options[$flag];
        $options['referenceCaseIds'] = array_values(array_unique($options['referenceCaseIds']));
        $options['referenceRunIds'] = array_values(array_unique($options['referenceRunIds']));
        abort_if(count($options['referenceCaseIds']) + count($options['referenceRunIds']) + count($compositeIds) > 3, 422, 'Use at most three references.');
        foreach ($options['referenceCaseIds'] as $id) IguideLabCase::where('project_id', $project->id)->findOrFail($id);
        foreach ($options['referenceRunIds'] as $id) {
            $reference = IguideLabRun::where('project_id', $project->id)->where('status', 'completed')->findOrFail($id);
            abort_unless($reference->output_path && Storage::disk(self::DISK)->exists($reference->output_path), 422, 'A reference result is no longer available.');
        }
        abort_if($options['allowFinishChanges'] && $input['preset'] !== 'interior-design', 422, 'Finish changes are available only for interior design.');
        $options['width'] = $case->width >= $case->height ? 2048 : max(16, (int) round(2048 * $case->width / $case->height / 16) * 16);
        $options['height'] = $case->height >= $case->width ? 2048 : max(16, (int) round(2048 * $case->height / $case->width / 16) * 16);
        $options += ['quality' => 'high', 'complexity' => $complexity, 'seedreamMode' => 'standard', 'projection' => $case->projection, 'recipeVersion' => $selected || $complexity !== 'medium' ? 2 : 1];
        if (LabCatalog::is25($input['modelId'])) { unset($options['complexity']); $options['recipeVersion'] = 3; }
        if ($compositeIds) { $options['referenceCompositeIds'] = $compositeIds; $options['recipeVersion'] = 3; }
        $options += $selection;
        $style = $input['style'] ?? (in_array($input['preset'], ['staging', 'interior-design']) ? 'modern' : null);
        $extra = $machinePlan ? (string) ($input['prompt'] ?? '') : trim($input['prompt'] ?? '');
        $options['customInstructions'] = $extra;
        if ($sectionId) {
            $options['sectionId'] = $sectionId;
            if ($sectionMaskId) $options['sectionMaskId'] = $sectionMaskId;
            $options['sourceSection'] = app(LabMasks::class)->sectionSnapshot($project->id, $sectionId, $case, $sectionMaskId);
            $options['recipeVersion'] = 5;
        }
        if ($scenePlan) {
            $options['sourceAnalysis'] = app(LabAnalyses::class)->appliedDraft($project->id, $analysisId, $case, $input['preset'], $style, $options, $extra);
            $options['analysisId'] = $analysisId; $options['promptMode'] = 'scene-plan'; $options['recipeVersion'] = 4;
            if ($sectionId) $options['recipeVersion'] = 5;
        }
        if ($removalPlan !== null) {
            $options['sourceMachinePlan']=app(LabDeclutterImageDraft::class)->apply($removalPlan,$case,$input,$workflowInvocation);
            $options['promptMode']='machine-removal-plan';
        }
        if ($interiorPlan !== null) {
            $options['sourceMachinePlan']=app(LabInteriorDesignImageDraft::class)->apply($interiorPlan,$case,$input,$workflowInvocation);
            $options['promptMode']='machine-interior-material-plan';
        }
        if ($workflowInvocation !== null) {
            abort_unless($scenePlan || $machinePlan, 422, 'Automatic generation requires its reserved machine proposal.');
            $options['workflowInvocation'] = $workflowInvocation;
            if ($scenePlan) $options['sourceAnalysis']['automaticGeneration'] = true;
        }
        $prompt = $scenePlan || $machinePlan ? $extra : LabCatalog::prompt($input['preset'], $style, $case->projection, $extra, $options);
        $hash = hash('sha256', json_encode([$case->id, $case->source_sha256, $input['modelId'], $input['preset'], $style, $prompt, $options], JSON_THROW_ON_ERROR));
        // A later QA revocation blocks queued submission, not reconciliation of a known request.
        if ($compositeIds && ($existing = IguideLabRun::where('project_id', $project->id)->where('request_id', $input['requestId'])->first())) {
            abort_unless($project->fresh()->active(), 401);
            abort_unless(hash_equals($existing->request_hash, $hash), 409, 'This request ID was already used with different settings.');
            return $existing;
        }
        $compositeProvenance = [];
        foreach ($compositeIds as $id) {
            [$reference, $review] = $this->acceptedComposite($project->id, $id);
            $compositeProvenance[] = ['id' => $id, 'sha256' => $reference->manifest['preview']['sha256'], 'reviewId' => $review['id'], 'role' => 'accepted_composite_reference'];
        }
        // Review IDs are an immutable reservation snapshot, not user request identity.
        if ($compositeProvenance) $options['referenceCompositeProvenance'] = $compositeProvenance;
        [$run, $created] = LockedWrite::run(fn () => DB::transaction(function () use ($project, $case, $input, $options, $style, $prompt, $hash, $workflowInvocation) {
            // Writing the quota row first acquires SQLite's write lock before count/read decisions.
            IguideLabProject::whereKey($project->id)->update(['updated_at' => now()]);
            $current = $project->fresh();
            abort_unless($current->active(), 401, 'This lab session has expired.');
            $existing = IguideLabRun::where('project_id', $project->id)->where('request_id', $input['requestId'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'This request ID was already used with different settings.');
                return [$existing, false];
            }
            if ($workflowInvocation !== null) app(LabWorkflowInvocation::class)->verify($workflowInvocation, $project->id, $input['requestId'], 'image', false);
            abort_if($current->used_runs >= $current->max_runs, 429, 'This lab has reached its generation limit.');
            abort_if(IguideLabRun::where('created_at', '>=', now()->subDay())->count() >= 120, 429, 'The lab daily generation limit has been reached.');
            $provider = LabCatalog::MODELS[$input['modelId']]['provider'];
            abort_unless(collect(LabCatalog::models())->firstWhere('id', $input['modelId'])['ready'], 503, 'This model connection is unavailable.');
            $run = IguideLabRun::create([
                'project_id' => $project->id, 'case_id' => $case->id, 'request_id' => $input['requestId'], 'request_hash' => $hash,
                'model_id' => $input['modelId'], 'provider' => $provider, 'preset' => $input['preset'], 'style' => $style,
                'prompt' => $prompt, 'options' => $options, 'status' => 'queued',
            ]);
            $current->increment('used_runs');
            return [$run, true];
        }), 'iguide-lab.reserve');
        if ($created) {
            try { ProcessIguideLabRun::dispatch($run->id); }
            catch (Throwable) { $this->attention($run, 'The queue submission could not be confirmed. This run needs operator review.'); }
        }

        return $run->fresh();
    }

    /** Returns false only while polling a known paid fal request. */
    public function process(IguideLabRun $run): bool
    {
        if (in_array($run->status, ['completed', 'failed', 'needs_attention'], true)) return true;
        $project = IguideLabProject::find($run->project_id);
        if (! $project?->active()) { $this->attention($run, 'The lab session expired before this run completed.'); return true; }
        if ($run->started_at?->lt(now()->subMinutes(30))) { $this->attention($run, 'The provider did not finish within 30 minutes. No new generation was submitted.'); return true; }
        $provider = app(LabProvider::class);
        try {
            if ($run->provider_request_id && $run->provider === 'fal') {
                $result = $provider->poll($run);
                if ($result === null) return false;
            } else {
                $claimed = app(LabWorkflowInvocation::class)->claim($run->options['workflowInvocation'] ?? null, $run->project_id, $run->request_id, 'image',
                    fn () => LockedWrite::run(fn () => IguideLabRun::whereKey($run->id)->where('status', 'queued')->update(['status' => 'submitting', 'started_at' => now(), 'updated_at' => now()]), 'iguide-lab.claim'));
                if (! $claimed) { $this->attention($run, 'A previous submission could not be confirmed. It will not be repeated automatically.'); return true; }
                $run->refresh();
                $case = IguideLabCase::where('project_id', $run->project_id)->findOrFail($run->case_id);
                if (isset($run->options['sourceMachinePlan'])) app(LabMachineImageDrafts::class)->verifyRun($run,$case);
                if ($snapshot = ($run->options['sourceSection'] ?? null)) {
                    $current = app(LabMasks::class)->sectionSnapshot($run->project_id, $snapshot['id'], $case, $snapshot['maskId']);
                    abort_unless($current === $snapshot, 422, 'Section or reviewed support changed before generation.');
                }
                $sources = [$case];
                foreach ($run->options['referenceCaseIds'] ?? [] as $id) $sources[] = IguideLabCase::where('project_id', $run->project_id)->findOrFail($id);
                $bytes = array_map(fn ($source) => Storage::disk(self::DISK)->get($source->source_path), $sources);
                if ($snapshot = ($run->options['sourceAnalysis'] ?? null)) {
                    abort_unless(hash_equals($snapshot['sourceSha256'], hash('sha256', $bytes[0]))
                        && hash_equals($snapshot['preparedSourceSha256'], hash('sha256', LabImages::prepared($bytes[0]))), 422, 'The applied analysis no longer matches the submitted source.');
                }
                foreach ($run->options['referenceRunIds'] ?? [] as $id) {
                    $reference = IguideLabRun::where('project_id', $run->project_id)->where('status', 'completed')->findOrFail($id);
                    $bytes[] = Storage::disk(self::DISK)->get($reference->output_path);
                }
                $options = $run->options;
                foreach ($options['referenceCompositeProvenance'] ?? [] as $index => $snapshot) {
                    [$reference, $review] = $this->acceptedComposite($run->project_id, $snapshot['id']);
                    abort_unless(hash_equals($snapshot['sha256'], $reference->manifest['preview']['sha256']), 422, 'The accepted reference changed.');
                    $options['referenceCompositeProvenance'][$index]['submittedReviewId'] = $review['id'];
                    $bytes[] = Storage::disk(self::DISK)->get($reference->preview_path);
                }
                if ($options !== $run->options) LockedWrite::run(fn () => $run->update(['options' => $options]), 'iguide-lab.reference-provenance');
                $result = $provider->submit($run, $bytes);
                if (! isset($result['bytes'])) {
                    LockedWrite::run(fn () => $run->update(['status' => 'processing', 'provider_request_id' => $result['requestId']]), 'iguide-lab.submitted');
                    return false;
                }
            }
            $size = @getimagesizefromstring($result['bytes']);
            if (! $size || $size[0] !== $run->options['width'] || $size[1] !== $run->options['height']) {
                app(LabStorage::class)->prepareProject($run->project_id);
                $path = 'iguide-lab/'.$run->project_id.'/runs/'.$run->id.'-rejected.bin';
                // Preserve exact rejected bytes for operator diagnosis; ordinary asset routes remain closed.
                Storage::disk(self::DISK)->put($path, $result['bytes'], ['visibility' => 'private']);
                $options = $run->options;
                $options['diagnosticOutput'] = ['stored' => true, 'width' => $size[0] ?? null, 'height' => $size[1] ?? null,
                    'mime' => $size['mime'] ?? null, 'sha256' => hash('sha256', $result['bytes'])];
                LockedWrite::run(fn () => $run->update(['output_path' => $path, 'output_width' => $size[0] ?? null,
                    'output_height' => $size[1] ?? null, 'options' => $options, 'usage' => $result['usage'] ?? null,
                    'provider_request_id' => $result['requestId'] ?? $run->provider_request_id,
                    'duration_ms' => (int) $run->started_at->diffInMilliseconds(now())]), 'iguide-lab.rejected-output');
                $this->attention($run, 'The provider returned an unexpected image size. The panorama was not stretched or published.');
                return true;
            }
            app(LabStorage::class)->prepareProject($run->project_id);
            $path = 'iguide-lab/'.$run->project_id.'/runs/'.$run->id.'.png';
            $png = (string) ImageManager::gd()->read($result['bytes'])->toPng();
            abort_unless(Storage::disk(self::DISK)->put($path, $png, ['visibility' => 'private']), 500, 'Private output storage failed.');
            LockedWrite::run(fn () => $run->update([
                'status' => 'completed', 'output_path' => $path, 'output_sha256' => hash('sha256', $png), 'output_width' => $size[0], 'output_height' => $size[1],
                'usage' => $result['usage'] ?? null, 'provider_request_id' => $result['requestId'] ?? $run->provider_request_id,
                'duration_ms' => (int) $run->started_at->diffInMilliseconds(now()), 'completed_at' => now(), 'error' => null,
            ]), 'iguide-lab.complete');
        } catch (OpenAiImageException $exception) {
            $this->attention($run, $exception->ambiguous ? 'The model response could not be confirmed. No automatic retry was sent.' : 'The model declined this request (HTTP '.($exception->httpStatus ?? 'unavailable').').', $exception->ambiguous ? 'needs_attention' : 'failed');
        } catch (FalTerminalException $exception) {
            $this->attention($run, 'Seedream declined this request (HTTP '.$exception->httpStatus.').', 'failed');
        } catch (Throwable) {
            if ($run->provider === 'fal' && $run->provider_request_id && $run->started_at?->gt(now()->subMinutes(30))) return false;
            $this->attention($run, 'This run needs operator review. A paid request will not be repeated automatically.');
        }

        return true;
    }

    public function attention(IguideLabRun $run, string $message, string $status = 'needs_attention'): void
    {
        LockedWrite::run(fn () => IguideLabRun::whereKey($run->id)->whereNotIn('status', ['completed', 'failed'])->update(['status' => $status, 'error' => $message, 'completed_at' => now(), 'updated_at' => now()]), 'iguide-lab.attention');
    }

    private function acceptedComposite(string $projectId, string $id): array
    {
        $record = IguideLabComposite::where('project_id', $projectId)->where('status', 'completed')->findOrFail($id);
        $review = app(LabReviews::class)->latest($record);
        abort_unless(($review['verdict'] ?? null) === 'accepted', 422, 'A composite reference requires a current accepted review.');
        abort_unless(Storage::disk(self::DISK)->exists($record->preview_path)
            && hash_equals($record->manifest['preview']['sha256'], hash_file('sha256', Storage::disk(self::DISK)->path($record->preview_path))), 422, 'The accepted reference bytes do not match.');
        return [$record, $review];
    }

    public function caseDto(IguideLabCase $case): array
    {
        return ['id' => $case->id, 'label' => $case->label, 'roomId' => $case->room_id, 'panoId' => $case->pano_id, 'tourUrl' => $case->tour_url,
            'projection' => $case->projection, 'width' => $case->width, 'height' => $case->height, 'sourceSha256' => $case->source_sha256,
            'metadata' => $case->metadata ?? [], 'nativeSource' => $case->native_source, 'assetPath' => '/api/iguide-lab/assets/case/'.$case->id, 'createdAt' => $case->created_at->toIso8601String()];
    }

    public function runDto(IguideLabRun $run): array
    {
        return ['id' => $run->id, 'requestId' => $run->request_id, 'caseId' => $run->case_id, 'roomId' => IguideLabCase::find($run->case_id)?->room_id,
            'modelId' => $run->model_id, 'requestedModelId' => $run->model_id, 'provider' => $run->provider, 'providerRequestId' => $run->provider_request_id, 'preset' => $run->preset, 'style' => $run->style,
            'prompt' => $run->prompt, 'customPrompt' => $run->options['customInstructions'] ?? '', 'options' => $run->options, 'status' => $run->status, 'error' => $run->error,
            'output' => $run->status === 'completed' ? ['assetPath' => '/api/iguide-lab/assets/run/'.$run->id, 'width' => $run->output_width, 'height' => $run->output_height, 'sha256' => app(LabOutputDigests::class)->dto($run), 'mime' => 'image/png', 'preview' => true] : null,
            'latestReview' => app(LabReviews::class)->latest($run),
            'usage' => $run->usage, 'durationMs' => $run->duration_ms, 'createdAt' => $run->created_at->toIso8601String(), 'completedAt' => $run->completed_at?->toIso8601String()];
    }
}
