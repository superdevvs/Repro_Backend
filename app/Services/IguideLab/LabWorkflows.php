<?php
namespace App\Services\IguideLab;

use App\Jobs\ProcessIguideLabWorkflow;
use App\Models\IguideLabAnalysis;
use App\Models\IguideLabCase;
use App\Models\IguideLabComposite;
use App\Models\IguideLabProject;
use App\Models\IguideLabRun;
use App\Models\IguideLabWorkflow;
use App\Models\IguideLabWorkflowEvaluation;
use App\Models\IguideLabWorkflowItem;
use App\Models\IguideLabWorkflowStep;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Durable orchestration. Each child identity exists before dispatch; no implicit paid retries. */
class LabWorkflows
{
    public const TERMINAL = ['ready', 'needs_review', 'failed', 'cancelled'];

    public function start(IguideLabProject $project, array $input): IguideLabWorkflow
    {
        abort_unless($project->active(), 401);
        $items = [];
        foreach ($input['items'] as $entry) {
            $case = IguideLabCase::where('project_id', $project->id)->findOrFail($entry['caseId']);
            $items[] = ['caseId' => $case->id, 'preset' => $entry['preset'],
                'style' => $entry['style'] ?? (in_array($entry['preset'], ['staging', 'interior-design'], true) ? 'modern' : null),
                'instructions' => trim($entry['instructions'] ?? ''), 'source' => app(LabWorkflowSources::class)->capture($case)];
        }
        abort_if(count(array_unique(array_map(fn ($entry) => LabAnalysisRecipes::hash($entry), $items))) !== count($items), 422, 'The same source and choices were selected twice.');
        $identity = LabAnalysisRecipes::hash($items);
        $prior = IguideLabWorkflow::where('project_id', $project->id)->where('request_id', $input['requestId'])->first();
        if ($prior) { abort_unless(hash_equals($prior->request_hash, $identity), 409, 'This workflow request already has different source or choices.'); return $prior; }
        $policy = app(LabWorkflowPolicy::class)->snapshot();
        [$workflow, $created] = LockedWrite::run(fn () => DB::transaction(function () use ($project, $input, $identity, $policy, $items) {
            IguideLabProject::whereKey($project->id)->update(['updated_at' => now()]); abort_unless($project->fresh()->active(), 401);
            $prior = IguideLabWorkflow::where('project_id', $project->id)->where('request_id', $input['requestId'])->first();
            if ($prior) { abort_unless(hash_equals($prior->request_hash, $identity), 409); return [$prior, false]; }
            abort_if(IguideLabWorkflow::where('project_id', $project->id)->count() >= 120, 429, 'The workflow safety ceiling was reached.');
            $record = IguideLabWorkflow::create(['project_id' => $project->id, 'request_id' => $input['requestId'], 'request_hash' => $identity, 'policy' => $policy, 'status' => 'queued']);
            foreach ($items as $position => $entry) IguideLabWorkflowItem::create(['workflow_id' => $record->id, 'project_id' => $project->id,
                'case_id' => $entry['caseId'], 'position' => $position, 'preset' => $entry['preset'], 'style' => $entry['style'],
                'instructions' => $entry['instructions'], 'source' => $entry['source'], 'status' => 'queued', 'data' => ['reviewReasons' => [], 'planRevision' => 1]]);
            return [$record, true];
        }), 'iguide-lab.workflow-reserve');
        if ($created) {
            try { ProcessIguideLabWorkflow::dispatch($workflow->id); }
            catch (Throwable) { $this->stop($workflow, 'queue_unconfirmed', 'The queue receipt is unconfirmed. Existing requests were retained.'); }
        }
        return $workflow->fresh();
    }

    public function cancel(IguideLabWorkflow $workflow): IguideLabWorkflow
    {
        LockedWrite::run(fn () => DB::transaction(function () use ($workflow) {
            IguideLabProject::whereKey($workflow->project_id)->update(['updated_at' => now()]);
            if ($workflow->fresh()->cancelled_at) return;
            $workflow->update(['cancelled_at' => now(), 'status' => 'cancelled', 'completed_at' => now()]);
            IguideLabWorkflowItem::where('workflow_id', $workflow->id)->whereNotIn('status', self::TERMINAL)->update(['status' => 'cancelled', 'completed_at' => now(), 'updated_at' => now()]);
        }), 'iguide-lab.workflow-cancel');
        return $workflow->fresh();
    }

    public function process(IguideLabWorkflow $workflow): bool
    {
        if ($workflow->cancelled_at) return true; // Child GET receipts remain available; no new work is claimed.
        if (in_array($workflow->status, self::TERMINAL, true)) return true;
        $project = IguideLabProject::find($workflow->project_id);
        if (! $project?->active()) { $this->stop($workflow, 'session_expired', 'This session expired before processing finished.'); return true; }
        foreach (IguideLabWorkflowItem::where('workflow_id', $workflow->id)->orderBy('position')->get() as $item) {
            if (in_array($item->status, self::TERMINAL, true)) continue;
            try { $this->advance($workflow, $item, $project); }
            catch (Throwable $error) {
                if ($workflow->fresh()->cancelled_at) break;
                $this->diagnostic($item, $error);
                $this->finish($item, 'needs_review', 'processing_guard', 'Processing stopped at a source, capability or receipt check. Existing results were retained.');
            }
            // Initial rollout is sequential within each workflow; never perform 20 native jobs in one queue claim.
            break;
        }
        return $this->refreshStatus($workflow);
    }

    private function advance(IguideLabWorkflow $workflow, IguideLabWorkflowItem $item, IguideLabProject $project): void
    {
        $case = app(LabWorkflowSources::class)->verify($project->id, $item->source);
        if ($item->preset === 'grass' && isset($workflow->policy['grassAssessment'])) {
            app(LabGrassWorkflow::class)->advance($workflow,$item,$project); return;
        }
        if ($item->preset === 'staging' && LabStagingAssessmentPolicy::enabled($workflow->policy,$item->preset)) {
            app(LabStagingWorkflow::class)->advance($workflow,$item,$project); return;
        }
        if (! in_array($item->preset, ['twilight', 'golden-hour'], true)) {
            $this->finish($item, 'needs_review', 'recipe_unavailable', 'Automatic support and composition for this preset are not available yet.'); return;
        }
        app(LabAnalysisRecipes::class)->validate($workflow->policy['analysisRecipe']);
        abort_unless($workflow->policy['version'] === LabWorkflowPolicy::VERSION && $workflow->policy['modelId'] === 'gpt-image-2.6-sunburst'
            && $workflow->policy['quality'] === 'high' && $workflow->policy['complexity'] === 'low'
            && $workflow->policy['maxImageAttemptsPerItem'] === 3 && $workflow->policy['maxPlanRevisionsPerItem'] === 2, 422, 'The workflow policy changed.');
        abort_unless($workflow->policy['evaluationRecipeSha256'] === LabVisualQualityProtocol::recipeSha256(), 422);
        abort_unless($workflow->policy['evaluationParserSha256'] === hash_file('sha256', (new \ReflectionClass(LabVisualQualityProtocol::class))->getFileName()), 422);
        app(LabWorkflowArtifacts::class)->validateSnapshot($workflow->policy['artifacts']);
        $model = collect(LabCatalog::models())->firstWhere('id', $workflow->policy['modelId']);
        if (! ($model['ready'] ?? false) || ! app(LabAnalyses::class)->ready() || ! app(LabWorkflowEvaluations::class)->ready() || ! app(LabWorkflowArtifacts::class)->ready()) {
            $this->finish($item, 'needs_review', 'checking_unavailable', 'A required preparation or checking service is unavailable.'); return;
        }
        $steps = app(LabWorkflowSteps::class); $data = $item->data;
        app(LabWorkflowArtifacts::class)->preflight($item, $workflow->policy['artifacts']);
        if (isset($workflow->policy['lightingRepair'])) app(LabLightingRepairs::class)->validate($workflow->policy);
        if (app(LabLightingRepairs::class)->advance($workflow, $item, $project)) return;
        $planIndex = $data['planRevision'] ?? 1;
        $instructions = $item->instructions;
        if (isset($data['planningCorrection'])) $instructions .= "\nAutomatic quality feedback to resolve without expanding the edit: ".$data['planningCorrection'];
        abort_if(mb_strlen($instructions) > 3200, 422);
        $analysisInput = ['caseId' => $case->id, 'preset' => $item->preset, 'style' => $item->style,
            'instructions' => $instructions, 'options' => LabAnalyses::EDIT_DEFAULTS];
        if (isset($data['repairContext'])) $analysisInput['repairContext'] = $data['repairContext'];
        $analysisStep = $steps->reserve($item, 'analysis-'.$planIndex, 'analysis', $analysisInput);
        $analysis = IguideLabAnalysis::where('project_id', $project->id)->where('request_id', $analysisStep->request_id)->first();
        if (! $analysis) $analysis = app(LabAnalyses::class)->start($project, $analysisInput + ['requestId' => $analysisStep->request_id], $steps->invocation($analysisStep));
        else $this->reconcileQueuedChild($analysisStep, $analysis);
        $steps->attach($analysisStep, $analysis->id);
        $data['analysisId'] = $analysis->id;
        if ($analysis->status !== 'completed') {
            if (in_array($analysis->status, ['failed', 'needs_attention'], true)) $this->finish($item, 'needs_review', 'analysis_incomplete', 'The scene proposal could not be confirmed; no image was submitted.', $data);
            else $this->save($item, 'analyzing', $data);
            return;
        }
        $proposal = $analysis->proposal;
        if (($proposal['workflow']['mode'] ?? '') === 'manual_review') {
            if ($planIndex < $workflow->policy['maxPlanRevisionsPerItem']) {
                $data['planRevision'] = $planIndex + 1;
                $data['planningCorrection'] = 'Resolve this explicit routing concern within the same source and authorized lighting intent, or keep manual_review: '.$proposal['workflow']['reason'];
                $this->save($item, 'repairing', $data); return;
            }
            $this->finish($item, 'needs_review', 'plan_requires_manual_review', 'The scene proposal still requires manual review.', $data); return;
        }
        if (($proposal['workflow']['mode'] ?? '') !== 'whole_panorama') {
            $this->finish($item, 'needs_review', 'plan_requires_other_route', 'The source proposal requires an editing route not implemented for this preset.', $data); return;
        }
        // Ordinary source caveats are retained for independent evaluation, not mistaken for actionable failure.
        $data['sourceCaveats'] = $proposal['uncertainties'] ?? [];
        $attempt = $data['currentAttempt'] ?? 1;
        $prompt = $proposal['generationPrompt'];
        if (isset($data['imageCorrection'])) $prompt .= "\nResolve this observed problem while preserving the same source geometry and requested scope: ".$data['imageCorrection'];
        abort_if(mb_strlen($prompt) > 4000, 422);
        $imageInput = ['caseId' => $case->id, 'preset' => $item->preset, 'style' => $item->style, 'modelId' => $workflow->policy['modelId'], 'prompt' => $prompt,
            'options' => $analysis->settings['options'] + ['analysisId' => $analysis->id, 'promptMode' => 'scene-plan', 'complexity' => $workflow->policy['complexity']]];
        $imageStep = $steps->reserve($item, 'image-'.$attempt, 'image', $imageInput);
        $run = IguideLabRun::where('project_id', $project->id)->where('request_id', $imageStep->request_id)->first();
        if (! $run) $run = app(LabService::class)->start($project, $imageInput + ['requestId' => $imageStep->request_id], $steps->invocation($imageStep));
        else $this->reconcileQueuedChild($imageStep, $run);
        $steps->attach($imageStep, $run->id); $data['runId'] = $run->id;
        $item->attempt_count = IguideLabWorkflowStep::where('item_id', $item->id)->where('kind', 'image')->count();
        if ($run->status !== 'completed') {
            if (in_array($run->status, ['failed', 'needs_attention'], true)) $this->finish($item, 'needs_review', 'image_unconfirmed', 'The image result could not be confirmed. No duplicate request was sent.', $data);
            else $this->save($item, 'editing', $data);
            return;
        }
        $runHash = app(LabWorkflowArtifacts::class)->runHash($run);
        $compositionStep = $steps->reserve($item, 'composition-'.$attempt, 'composition', ['runId' => $run->id, 'outputSha256' => $runHash, 'recipe' => $workflow->policy['artifacts']]);
        if (! $compositionStep->child_id) {
            $this->save($item, 'preparing', $data);
            $composite = app(LabWorkflowArtifacts::class)->compose($item, $compositionStep, $run, $analysis);
            $steps->attach($compositionStep, $composite->id); $data['compositeId'] = $composite->id;
            $this->save($item, 'preparing', $data);
            return; // At most one expensive native phase in this job claim.
        }
        $composite = IguideLabComposite::where('project_id', $project->id)->findOrFail($compositionStep->child_id);
        app(LabWorkflowArtifacts::class)->verifyComposite($item, $composite, $compositionStep, $run);
        $data['compositeId'] = $composite->id;
        $evaluationStep = IguideLabWorkflowStep::where('item_id', $item->id)->where('step_key', 'evaluation-'.$attempt)->first();
        $evidence = $evaluationStep ? $evaluationStep->input : app(LabWorkflowArtifacts::class)->evidence($item, $composite, $analysis);
        if (! $evidence['context']['mechanicalQa']['passed']) {
            $this->finish($item, 'needs_review', 'mechanical_checks_failed', 'The composed panorama did not pass its numerical checks.', $data); return;
        }
        $evaluationStep ??= $steps->reserve($item, 'evaluation-'.$attempt, 'evaluation', $evidence);
        $evaluation = IguideLabWorkflowEvaluation::where('project_id', $project->id)->where('request_id', $evaluationStep->request_id)->first();
        if (! $evaluation) $evaluation = app(LabWorkflowEvaluations::class)->start($evaluationStep);
        else $this->reconcileQueuedChild($evaluationStep, $evaluation);
        $steps->attach($evaluationStep, $evaluation->id); $data['evaluationId'] = $evaluation->id;
        if ($evaluation->status !== 'completed') {
            if (in_array($evaluation->status, ['failed', 'needs_attention'], true)) $this->finish($item, 'needs_review', 'evaluation_unconfirmed', 'The independent quality evaluation could not be confirmed.', $data);
            else $this->save($item, 'checking', $data);
            return;
        }
        if ($evaluation->result['qualityStatus'] === 'passed_automated_checks') {
            if (isset($workflow->policy['lightingRepair'])) app(LabWorkflowEvaluations::class)->verifiedCompleted($item, $evaluation);
            app(LabWorkflowEvaluations::class)->payload($evidence, $project->id, $item->id);
            $data['qualityStatus'] = 'passed_automated_checks'; $this->finish($item, 'ready', null, null, $data); return;
        }
        if (app(LabLightingRepairs::class)->begin($workflow, $item, $run, $analysis, $evaluation, $data)) return;
        $this->repairAfterEvaluation($workflow, $item, $analysis, $evaluation, $data);
    }

    public function repairAfterEvaluation(IguideLabWorkflow $workflow, IguideLabWorkflowItem $item, IguideLabAnalysis $analysis, IguideLabWorkflowEvaluation $evaluation, array $data): void
    {
        $planIndex = $data['planRevision'] ?? 1; $attempt = $data['currentAttempt'] ?? 1;
        $proposal = $analysis->proposal;
        $result = isset($workflow->policy['lightingRepair']) ? app(LabWorkflowEvaluations::class)->verifiedCompleted($item, $evaluation) : $evaluation->result;
        $feedback = $this->repairFeedback($result); $action = $result['recommendedAction'];
        if (isset($workflow->policy['lightingRepair'])) {
            app(LabLightingRepairs::class)->validate($workflow->policy);
            $data['lastQualityFeedback'] = $result; // Complete bounded check reasons, dependencies and summary.
            if (in_array($action, ['replan','regenerate'], true) && $planIndex < $workflow->policy['maxPlanRevisionsPerItem'] && $attempt < $workflow->policy['maxImageAttemptsPerItem']) {
                $data['repairContext'] = app(LabWorkflowRepairContext::class)->reference($item, $evaluation);
                $data['planRevision'] = $planIndex + 1; $data['currentAttempt'] = $attempt + 1;
                unset($data['imageCorrection'], $data['planningCorrection']);
                $this->save($item, 'repairing', $data); return;
            }
            // No semantic fallback to a cropped feedback string when the full repair context cannot run.
            $this->finish($item, 'needs_review', 'quality_checks_failed', $result['summary'], $data); return;
        }
        // Only explicit evidence-backed repair actions can authorize a new bounded child, never transport retries.
        if ($action === 'regenerate' && $attempt < $workflow->policy['maxImageAttemptsPerItem'] && mb_strlen($proposal['generationPrompt']."\nResolve this observed problem while preserving the same source geometry and requested scope: ".$feedback) <= 4000) {
            $data['currentAttempt'] = $attempt + 1; $data['imageCorrection'] = $feedback;
            $this->save($item, 'repairing', $data); return;
        }
        if ($action === 'replan' && $planIndex < $workflow->policy['maxPlanRevisionsPerItem'] && $attempt < $workflow->policy['maxImageAttemptsPerItem']) {
            $data['planRevision'] = $planIndex + 1; $data['currentAttempt'] = $attempt + 1; $data['planningCorrection'] = $feedback; unset($data['imageCorrection']);
            $this->save($item, 'repairing', $data); return;
        }
        $this->finish($item, 'needs_review', 'quality_checks_failed', $feedback, $data);
    }

    /** Recover a record committed before queue dispatch; never retry a provider submission. */
    public function reconcileQueuedChild(IguideLabWorkflowStep $step, IguideLabAnalysis|IguideLabRun|IguideLabWorkflowEvaluation $child): void
    {
        $kind = match (true) { $child instanceof IguideLabAnalysis => 'analysis', $child instanceof IguideLabRun => 'image', default => 'evaluation' };
        $invocation = app(LabWorkflowSteps::class)->invocation($step);
        $savedInvocation = $child instanceof IguideLabRun ? ($child->options['workflowInvocation'] ?? null) : ($child->data['workflowInvocation'] ?? null);
        abort_unless($step->kind === $kind && $child->project_id === $step->project_id && $child->request_id === $step->request_id
            && $savedInvocation === $invocation && $step->input_sha256 === LabAnalysisRecipes::hash($step->input), 422, 'Queued workflow child ownership changed.');
        abort_unless($child instanceof IguideLabWorkflowEvaluation ? $child->step_id === $step->id
            : $child->case_id === ($step->input['caseId'] ?? null), 422, 'Queued child source association changed.');
        if (! $this->neverSubmitted($child) || $child->created_at->gt(now()->subMinute())) return;
        $claimed = app(LabWorkflowInvocation::class)->claim($invocation, $step->project_id, $step->request_id, $kind, function () use ($step, $child) {
            $fresh = $child->fresh(); $currentStep = $step->fresh(); $data = $currentStep->data ?? [];
            if (! $this->neverSubmitted($fresh) || (int) ($data['lastQueueReconciledAt'] ?? 0) > now()->timestamp - 60) return false;
            $data['lastQueueReconciledAt'] = now()->timestamp;
            $currentStep->update(['data' => $data]);
            return true;
        });
        if (! $claimed) return;
        // Dispatch after the project/cancellation claim commits. The original job
        // lock and queued→submitting claim make duplicate queued jobs harmless.
        match ($kind) {
            'analysis' => \App\Jobs\ProcessIguideLabAnalysis::dispatch($child->id),
            'image' => \App\Jobs\ProcessIguideLabRun::dispatch($child->id),
            'evaluation' => \App\Jobs\ProcessIguideLabWorkflowEvaluation::dispatch($child->id),
        };
    }
    private function neverSubmitted(IguideLabAnalysis|IguideLabRun|IguideLabWorkflowEvaluation $child): bool
    {
        $attempts = $child instanceof IguideLabRun ? ($child->options['providerAttempts'] ?? []) : ($child->provider_attempts ?? []);
        return $child->status === 'queued' && $child->started_at === null && $child->provider_request_id === null
            && $child->completed_at === null && empty($attempts);
    }
    private function repairFeedback(array $evaluation): string
    {
        $lines = [];
        foreach ($evaluation['checks'] as $name => $check) {
            if ($check['status'] === 'pass') continue;
            $views = implode(',', array_slice($check['evidenceViewIds'], 0, 2));
            $lines[] = $name.'='.$check['status'].' ['.$views.']: '.mb_substr($check['reason'], 0, 100);
        }
        foreach (array_slice($evaluation['unresolvedDependencies'], 0, 3) as $dependency) $lines[] = 'Unresolved: '.mb_substr($dependency, 0, 120);
        return implode("\n", $lines) ?: 'Requested action '.$evaluation['recommendedAction'].': '.mb_substr($evaluation['summary'], 0, 400);
    }

    private function diagnostic(IguideLabWorkflowItem $item, Throwable $error): void
    {
        $fresh = $item->fresh(); $data = $fresh->data;
        $step = IguideLabWorkflowStep::where('item_id', $item->id)->latest()->first();
        $diagnostic = ['code' => 'workflow_guard_exception', 'phase' => $fresh->status,
            'stepId' => $step?->id, 'exceptionClass' => get_class($error),
            'httpStatus' => $error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $error->getStatusCode() : null,
            'recordedAt' => now()->toIso8601String()];
        // Do not copy exception messages/stack args: HTTP client errors can embed credentials or private paths.
        $data['diagnostics'] = [...array_slice($data['diagnostics'] ?? [], -9), $diagnostic];
        LockedWrite::run(fn () => $fresh->update(['data' => $data]), 'iguide-lab.workflow-diagnostic');
        $item->refresh();
    }

    public function save(IguideLabWorkflowItem $item, string $status, array $data): void
    {
        LockedWrite::run(fn () => DB::transaction(function () use ($item, $status, $data) {
            IguideLabProject::whereKey($item->project_id)->update(['updated_at' => now()]);
            // The project lock serializes real cancel requests. The SQL predicate
            // also prevents a stale guard read from overwriting a cancelled item.
            IguideLabWorkflowItem::whereKey($item->id)->whereExists(function ($query) use ($item) {
                $query->selectRaw('1')->from('iguide_lab_workflows')->where('id', $item->workflow_id)->whereNull('cancelled_at');
            })->update(['status' => $status, 'data' => json_encode($data, JSON_THROW_ON_ERROR), 'attempt_count' => $item->attempt_count]);
            $item->refresh();
        }), 'iguide-lab.workflow-state');
    }
    public function finish(IguideLabWorkflowItem $item, string $status, ?string $code, ?string $message, ?array $data = null): void
    {
        $data ??= $item->data; $data['reviewReasons'] = $code ? [['code' => $code, 'message' => $message]] : [];
        $data['qualityStatus'] = $status === 'ready' ? 'passed_automated_checks' : ($status === 'failed' ? 'failed' : 'needs_review');
        $this->save($item, $status, $data);
        LockedWrite::run(fn () => IguideLabWorkflowItem::whereKey($item->id)->where('status', $status)->update(['completed_at' => now()]), 'iguide-lab.workflow-finish');
    }
    public function stop(IguideLabWorkflow $workflow, string $code, string $message): void
    {
        foreach (IguideLabWorkflowItem::where('workflow_id', $workflow->id)->whereNotIn('status', self::TERMINAL)->get() as $item) $this->finish($item, 'needs_review', $code, $message);
        $this->refreshStatus($workflow);
    }
    private function refreshStatus(IguideLabWorkflow $workflow): bool
    {
        if ($workflow->fresh()->cancelled_at) return true;
        $states = IguideLabWorkflowItem::where('workflow_id', $workflow->id)->pluck('status')->all();
        $active = array_values(array_diff($states, self::TERMINAL)); $done = ! $active;
        $state = $done ? (count(array_unique($states)) === 1 ? $states[0] : 'needs_review') : $active[0];
        LockedWrite::run(fn () => IguideLabWorkflow::whereKey($workflow->id)->whereNull('cancelled_at')->update(['status' => $state, 'completed_at' => $done ? now() : null, 'updated_at' => now()]), 'iguide-lab.workflow-summary');
        return $done;
    }
    public function dto(IguideLabWorkflow $workflow): array
    {
        $items = IguideLabWorkflowItem::where('workflow_id', $workflow->id)->orderBy('position')->get()->map(fn ($item) => $this->itemDto($item))->all();
        return ['id' => $workflow->id, 'requestId' => $workflow->request_id, 'status' => $workflow->status,
            'counts' => ['total' => count($items), 'ready' => count(array_filter($items, fn ($i) => $i['status'] === 'ready')),
                'needsReview' => count(array_filter($items, fn ($i) => $i['status'] === 'needs_review')),
                'failed' => count(array_filter($items, fn ($i) => $i['status'] === 'failed')),
                'cancelled' => count(array_filter($items, fn ($i) => $i['status'] === 'cancelled'))],
            'items' => $items, 'createdAt' => $workflow->created_at->toIso8601String(), 'updatedAt' => $workflow->updated_at->toIso8601String(), 'completedAt' => $workflow->completed_at?->toIso8601String()];
    }
    private function itemDto(IguideLabWorkflowItem $item): array
    {
        $data = $item->data; $result = null;
        if (isset($data['compositeId']) && ($composite = IguideLabComposite::where('project_id', $item->project_id)->find($data['compositeId']))) {
            $dto = app(LabComposites::class)->dto($composite);
            $result = ['kind' => 'composite', 'id' => $composite->id, 'preview' => $dto['preview'], 'native' => $dto['native'], 'sourceSha256' => $item->source['sha256']];
        }
        $label = match ($item->status) { 'queued', 'analyzing', 'preparing' => 'Preparing', 'editing', 'repairing' => 'Editing', 'checking' => 'Checking', 'ready' => 'Ready', 'cancelled' => 'Cancelled', 'failed' => 'Failed', default => 'Needs review' };
        return ['id' => $item->id, 'caseId' => $item->case_id, 'preset' => $item->preset, 'style' => $item->style, 'status' => $item->status,
            'stageLabel' => $label, 'attemptCount' => $item->attempt_count, 'result' => $result, 'reviewReasons' => $data['reviewReasons'] ?? [],
            'automation' => ['mode' => 'unattended', 'operatorInterventions' => 0, 'qualityStatus' => $data['qualityStatus'] ?? 'pending', 'manualAcceptance' => false, 'crossViewVerified' => false],
            'createdAt' => $item->created_at->toIso8601String(), 'updatedAt' => $item->updated_at->toIso8601String()];
    }
}
