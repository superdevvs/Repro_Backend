<?php
namespace App\Services\IguideLab;
use App\Models\IguideLabProject;
use App\Models\IguideLabWorkflow;
use App\Models\IguideLabWorkflowItem;
use App\Models\IguideLabWorkflowStep;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\DB;

/** Server-only orchestration provenance; never accepted from a public run/analysis body. */
class LabWorkflowInvocation
{
    public function verifyInput(?array $invocation, string $projectId, array $input, string $kind): void
    {
        if ($invocation === null) return;
        $this->verify($invocation, $projectId, $input['requestId'], $kind);
        $step = IguideLabWorkflowStep::where('project_id', $projectId)->findOrFail($invocation['stepId']);
        unset($input['requestId']);
        abort_unless(hash_equals($step->input_sha256, LabAnalysisRecipes::hash($input)), 422, 'The reserved child input changed.');
    }

    /** The cancel transaction and paid claim share the same project write lock. */
    public function claim(?array $invocation, string $projectId, string $requestId, string $kind, callable $claim): mixed
    {
        if ($invocation === null) return $claim();
        $this->verify($invocation, $projectId, $requestId, $kind);
        return LockedWrite::run(fn () => DB::transaction(function () use ($invocation, $projectId, $requestId, $kind, $claim) {
            IguideLabProject::whereKey($projectId)->update(['updated_at' => now()]);
            $this->verify($invocation, $projectId, $requestId, $kind, false);
            return $claim();
        }), 'iguide-lab.workflow-paid-claim');
    }

    public function policy(array $invocation): array
    {
        return IguideLabWorkflow::findOrFail($invocation['workflowId'])->policy;
    }
    public function verify(?array $invocation, string $projectId, string $requestId, string $kind, bool $verifyBytes = true): void
    {
        if ($invocation === null) return;
        $step = IguideLabWorkflowStep::where('project_id', $projectId)->findOrFail($invocation['stepId'] ?? '');
        $item = IguideLabWorkflowItem::where('project_id', $projectId)->findOrFail($step->item_id);
        $workflow = IguideLabWorkflow::where('project_id', $projectId)->findOrFail($item->workflow_id);
        abort_unless($step->request_id === $requestId && $step->kind === $kind
            && $invocation === app(LabWorkflowSteps::class)->invocation($step), 422, 'Workflow invocation provenance changed.');
        abort_unless(IguideLabProject::findOrFail($projectId)->active(), 401);
        abort_if($workflow->cancelled_at || $item->status === 'cancelled', 409, 'The workflow was cancelled before submission.');
        abort_if(in_array($item->status, ['ready', 'failed', 'needs_review'], true), 409, 'This workflow item is already terminal.');
        if ($verifyBytes) {
            app(LabWorkflowSources::class)->verify($projectId, $item->source);
            if ($kind === 'image' && $item->preset === 'staging' && isset($workflow->policy['stagingFraming'])) {
                app(LabStagingCutReadiness::class)->verifyImageStep($step);
            }
            if ($kind === 'image' && LabGrassSourceAdmissionPolicy::enabled($workflow->policy, $item->preset)) {
                app(LabGrassSourceAdmissions::class)->verifyImageStep($step);
            }
        }
    }
}
