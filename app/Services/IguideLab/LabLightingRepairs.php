<?php
namespace App\Services\IguideLab;

use App\Models\IguideLabAnalysis;
use App\Models\IguideLabComposite;
use App\Models\IguideLabProject;
use App\Models\IguideLabRun;
use App\Models\IguideLabWorkflow;
use App\Models\IguideLabWorkflowEvaluation;
use App\Models\IguideLabWorkflowItem;
use App\Models\IguideLabWorkflowStep;

/** One local fallback per retained image, at most two per item; never dispatches an image provider. */
class LabLightingRepairs
{
    public const VERSION = 'bounded-lighting-repair/1';
    public const RECIPE = 'source-detail-global-log-odds-lighting/1';
    public function snapshot(): array
    {
        return ['version' => self::VERSION, 'maxLocalRepairsPerItem' => 2, 'maxLocalRepairsPerImage' => 1, 'maxEvaluationsPerItem' => 5,
            'recipe' => self::RECIPE, 'driverSha256' => hash_file('sha256', __FILE__),
            'contextSha256' => hash_file('sha256', (new \ReflectionClass(LabWorkflowRepairContext::class))->getFileName()),
            'compilerSha256' => hash_file('sha256', (new \ReflectionClass(LabRepairAnalysisProtocol::class))->getFileName()),
            'analysisRecipe' => app(LabAnalysisRecipes::class)->snapshot(LabAnalysisRecipes::LIGHTING_REPAIR)];
    }
    public function validate(array $policy): void
    {
        abort_unless(isset($policy['lightingRepair']) && LabAnalysisRecipes::hash($policy['lightingRepair']) === LabAnalysisRecipes::hash($this->snapshot()), 422, 'Reserved lighting repair policy changed.');
    }
    public function eligible(array $result): bool
    {
        if (($result['qualityStatus'] ?? null) !== 'needs_review' || ($result['checks']['optical_dependencies']['status'] ?? null) !== 'fail'
            || ! in_array($result['recommendedAction'] ?? null, ['replan','regenerate','recompose'], true)) return false;
        foreach (['geometry','seam','poles','protected_features'] as $check) if (($result['checks'][$check]['status'] ?? null) !== 'pass') return false;
        return true;
    }
    public function begin(IguideLabWorkflow $workflow, IguideLabWorkflowItem $item, IguideLabRun $run, IguideLabAnalysis $analysis, IguideLabWorkflowEvaluation $evaluation, array $data): bool
    {
        if (! isset($workflow->policy['lightingRepair'])) return false;
        $this->validate($workflow->policy);
        $previous = $data['globalRepair'] ?? null; $history = $data['globalRepairHistory'] ?? [];
        $attempts = [...$history, ...($previous ? [$previous] : [])];
        $reserved = IguideLabWorkflowStep::where('item_id', $item->id)->where('kind', 'composition')->where('step_key', 'like', 'composition-global-%')->get();
        if (count($attempts) >= 2 || $reserved->count() >= 2 || in_array($run->id, array_column($attempts, 'runId'), true)
            || $reserved->contains(fn ($step) => ($step->input['runId'] ?? null) === $run->id)) return false;
        abort_unless($reserved->count() === count($attempts), 422, 'The retained global repair history changed.');
        abort_if($previous && ($previous['state'] ?? null) !== 'failed', 422, 'The preceding global repair is not a confirmed failure.');
        $result = app(LabWorkflowEvaluations::class)->verifiedCompleted($item, $evaluation);
        if (! $this->eligible($result)) return false;
        $this->latestRun($item, $run);
        $reference = app(LabWorkflowRepairContext::class)->reference($item, $evaluation);
        abort_unless(($evaluation->data['evidence']['compositeId'] ?? null) === ($data['compositeId'] ?? null), 422);
        if ($previous) $data['globalRepairHistory'] = [...$history, $previous];
        $data['globalRepair'] = ['index' => count($attempts) + 1, 'state' => 'pending', 'runId' => $run->id, 'analysisId' => $analysis->id,
            'sourceImageAttempt' => $data['currentAttempt'] ?? 1, 'failedCompositeId' => $data['compositeId'], 'failedEvaluation' => $reference];
        app(LabWorkflows::class)->save($item, 'repairing', $data);
        return true;
    }
    public function verifyComposition(IguideLabWorkflowItem $item, IguideLabWorkflowStep $step, IguideLabRun $run): void
    {
        $workflow = IguideLabWorkflow::findOrFail($item->workflow_id); $this->validate($workflow->policy);
        $repair = $item->data['globalRepair'] ?? [];
        $expected = $this->compositionBinding($repair);
        abort_unless(in_array($repair['index'] ?? null, [1,2], true)
            && $step->item_id === $item->id && $step->kind === 'composition' && $step->step_key === 'composition-global-'.$repair['index']
            && LabAnalysisRecipes::hash($step->input['repair'] ?? []) === LabAnalysisRecipes::hash($expected)
            && $run->id === ($repair['runId'] ?? null), 422, 'Global repair source association changed.');
        $this->latestRun($item, $run);
        $evaluation = app(LabWorkflowRepairContext::class)->verify($item, $repair['failedEvaluation']);
        abort_unless($this->eligible($evaluation->result) && $evaluation->data['evidence']['compositeId'] === $repair['failedCompositeId'], 422);
    }
    private function compositionBinding(array $repair): array
    {
        return ['version' => self::VERSION, 'recipe' => self::RECIPE, 'repairIndex' => $repair['index'], 'retainedRunId' => $repair['runId'],
            'sourceImageAttempt' => $repair['sourceImageAttempt'], 'failedCompositeId' => $repair['failedCompositeId'],
            'failedEvaluation' => $repair['failedEvaluation'], 'newPaidImage' => false];
    }
    private function latestRun(IguideLabWorkflowItem $item, IguideLabRun $run): void
    {
        $latest = IguideLabWorkflowStep::where('item_id', $item->id)->where('kind', 'image')->orderByDesc('step_key')->first();
        abort_unless($latest && $latest->child_id === $run->id && $latest->request_id === $run->request_id
            && $latest->step_key === 'image-'.($item->data['currentAttempt'] ?? 1)
            && $run->status === 'completed' && $run->project_id === $item->project_id && $run->case_id === $item->case_id, 422, 'The repair must reuse the latest confirmed paid image.');
        app(LabWorkflowArtifacts::class)->runHash($run);
    }
    public function advance(IguideLabWorkflow $workflow, IguideLabWorkflowItem $item, IguideLabProject $project): bool
    {
        $data = $item->data; $repair = $data['globalRepair'] ?? null;
        if (! is_array($repair) || ($repair['state'] ?? null) !== 'pending') return false;
        $this->validate($workflow->policy);
        $run = IguideLabRun::where('project_id', $project->id)->findOrFail($repair['runId']);
        $analysis = IguideLabAnalysis::where('project_id', $project->id)->findOrFail($repair['analysisId']);
        $this->latestRun($item, $run);
        $steps = app(LabWorkflowSteps::class); $artifacts = app(LabWorkflowArtifacts::class); $workflows = app(LabWorkflows::class);
        $step = $steps->reserve($item, 'composition-global-'.$repair['index'], 'composition', ['runId' => $run->id,
            'outputSha256' => $artifacts->runHash($run), 'recipe' => $workflow->policy['artifacts'], 'repair' => $this->compositionBinding($repair)]);
        if (! $step->child_id) {
            $workflows->save($item, 'preparing', $data);
            $composite = $artifacts->compose($item, $step, $run, $analysis); $steps->attach($step, $composite->id);
            $data['compositeId'] = $composite->id; $data['globalRepair']['compositeId'] = $composite->id;
            $workflows->save($item, 'checking', $data); return true;
        }
        $composite = IguideLabComposite::where('project_id', $project->id)->findOrFail($step->child_id);
        $artifacts->verifyComposite($item, $composite, $step, $run);
        $this->verifyComposition($item, $step, $run);
        $data['compositeId'] = $composite->id; $data['globalRepair']['compositeId'] = $composite->id;
        $evaluationKey = 'evaluation-global-'.$repair['index'];
        $evaluationStep = IguideLabWorkflowStep::where('item_id', $item->id)->where('step_key', $evaluationKey)->first();
        $evidence = $evaluationStep ? $evaluationStep->input : $artifacts->evidence($item, $composite, $analysis);
        if (! ($evidence['context']['mechanicalQa']['passed'] ?? false)) {
            $data['globalRepair']['state'] = 'failed';
            $workflows->finish($item, 'needs_review', 'repair_mechanical_checks_failed', 'The local repair did not pass its numerical checks.', $data); return true;
        }
        $evaluationStep ??= $steps->reserve($item, $evaluationKey, 'evaluation', $evidence);
        $evaluation = IguideLabWorkflowEvaluation::where('project_id', $project->id)->where('request_id', $evaluationStep->request_id)->first();
        if (! $evaluation) $evaluation = app(LabWorkflowEvaluations::class)->start($evaluationStep);
        else $workflows->reconcileQueuedChild($evaluationStep, $evaluation);
        $steps->attach($evaluationStep, $evaluation->id);
        $data['evaluationId'] = $evaluation->id; $data['globalRepair']['evaluationId'] = $evaluation->id;
        if ($evaluation->status !== 'completed') {
            if (in_array($evaluation->status, ['failed','needs_attention'], true)) $workflows->finish($item, 'needs_review', 'repair_evaluation_unconfirmed', 'The independent repair evaluation is unconfirmed. No duplicate was sent.', $data);
            else $workflows->save($item, 'checking', $data);
            return true;
        }
        $result = app(LabWorkflowEvaluations::class)->verifiedCompleted($item, $evaluation);
        $data['globalRepair']['state'] = $result['qualityStatus'] === 'passed_automated_checks' ? 'passed' : 'failed';
        if ($result['qualityStatus'] === 'passed_automated_checks') {
            $data['qualityStatus'] = 'passed_automated_checks'; $workflows->finish($item, 'ready', null, null, $data); return true;
        }
        // A source-preserving near-no-op is still a failed edit if the independent checker says so.
        $workflows->repairAfterEvaluation($workflow, $item, $analysis, $evaluation, $data);
        return true;
    }
}
