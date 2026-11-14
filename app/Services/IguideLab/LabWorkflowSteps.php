<?php
namespace App\Services\IguideLab;
use App\Models\IguideLabProject;
use App\Models\IguideLabWorkflow;
use App\Models\IguideLabWorkflowItem;
use App\Models\IguideLabWorkflowStep;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Stable child identity is persisted before any dispatcher or paid executor is called. */
class LabWorkflowSteps
{
    public function reserve(IguideLabWorkflowItem $item, string $key, string $kind, array $input): IguideLabWorkflowStep
    {
        abort_unless(in_array($kind, ['analysis', 'section', 'image', 'segmentation', 'composition', 'evaluation'], true)
            && preg_match('/\A[a-z0-9_-]{1,80}\z/D', $key), 422);
        $hash = LabAnalysisRecipes::hash($input);
        return LockedWrite::run(fn () => DB::transaction(function () use ($item, $key, $kind, $input, $hash) {
            IguideLabProject::whereKey($item->project_id)->update(['updated_at' => now()]);
            $workflow = IguideLabWorkflow::where('project_id', $item->project_id)->findOrFail($item->workflow_id);
            abort_if($workflow->cancelled_at, 409, 'The workflow is cancelled.');
            $prior = IguideLabWorkflowStep::where('item_id', $item->id)->where('step_key', $key)->first();
            if ($prior) { abort_unless($prior->kind === $kind && hash_equals($prior->input_sha256, $hash), 409, 'A workflow step changed.'); return $prior; }
            $limit = match ($kind) { 'image' => 3, 'analysis' => 2, 'segmentation' => 6, default => 8 };
            if (isset($workflow->policy['lightingRepair']) && in_array($item->preset, ['golden-hour','twilight'], true)) {
                app(LabLightingRepairs::class)->validate($workflow->policy);
                $limit = match ($kind) { 'evaluation','composition' => 5, 'image' => 3, 'analysis' => 2, default => $limit };
                if (str_contains($key, '-global-')) abort_unless(in_array($key, ['composition-global-1','evaluation-global-1','composition-global-2','evaluation-global-2'], true), 422, 'At most two local lighting repairs are allowed.');
            }
            if (LabStagingAssessmentPolicy::enabled($workflow->policy, $item->preset)) {
                $limit = match ($kind) { 'analysis' => 3, 'image' => 1, 'segmentation' => 2, 'evaluation' => 1, default => $limit };
                if (isset($workflow->policy['stagingLayout'])) {
                    $limit = match ($kind) { 'analysis' => 4, 'section' => 2, 'composition' => 1, default => $limit };
                    if ($kind === 'section') abort_unless(in_array($key,['staging-section','staging-reframed-section'],true),422,'Unsupported source section step.');
                    if ($kind === 'analysis') abort_unless(in_array($key,['staging-framing','staging-layout-initial','staging-layout-followup'],true)
                        || (($input['protocolVersion']??null)===LabStagingAssessmentPolicy::VERSION&&$key==='placement-'.($input['candidateRunId']??'')),422,'Unsupported bounded staging analysis step.');
                }
            }
            if (LabLifestyleAssessmentPolicy::enabled($workflow->policy, $item->preset)) {
                $limit = match ($kind) { 'analysis' => 3, 'image' => 1, 'segmentation' => 2, 'evaluation','section','composition' => 1, default => $limit };
            }
            if (class_exists(LabGrassAssessmentPolicy::class) && LabGrassAssessmentPolicy::enabled($workflow->policy,$item->preset)) {
                $limit = match ($kind) { 'analysis' => 2, 'image' => 1, 'segmentation' => 6, 'evaluation' => 1, default => $limit };
                if (LabGrassSourceAdmissionPolicy::enabled($workflow->policy, $item->preset)) {
                    $limit = match ($kind) { 'analysis' => 4, default => $limit };
                }
            }
            if (LabDeclutterRemovalPolicy::enabled($workflow->policy,$item->preset)) {
                $limit = match ($kind) { 'analysis' => 2, 'image' => 1, 'segmentation' => 2, 'evaluation' => 1, 'section','composition' => 1, default => $limit };
            }
            if (LabInteriorDesignPolicy::enabled($workflow->policy,$item->preset)) {
                $limit = match ($kind) { 'analysis' => 3, 'image' => 1, 'segmentation' => 15, 'evaluation','section','composition' => 1, default => $limit };
            }
            if (LabInteriorRoomPolicy::enabled($workflow->policy,$item->preset)) {
                // The whole branch budget exists before the classifier is dispatched; it never resets on a route change.
                $limit=LabInteriorRoomProfiles::BUDGET[$kind];
                if($kind==='analysis') abort_unless(in_array($key,['room-route','room-framing','room-layout','room-candidate','interior-framing','interior-cut','interior-candidate'],true),422,'Unknown bounded Interior room analysis.');
                if($key!=='room-route') {
                    $route=$item->fresh()->data['interiorRoomRoute']??null;
                    abort_unless(is_array($route)&&($route['version']??null)===LabInteriorRoomProfiles::VERSION,422,'Select the immutable source room route before reserving branch work.');
                    $profile=LabInteriorRoomProfiles::get($route['profileId']);
                    if($kind==='analysis') abort_unless(($profile['kind']==='existing_materials')===str_starts_with($key,'interior-'),422,'The reserved Interior branch cannot change.');
                    if($profile['kind']==='new_furnishings'&&$kind==='segmentation') $limit=2;
                }
            }
            abort_if(IguideLabWorkflowStep::where('item_id', $item->id)->where('kind', $kind)->count() >= $limit, 422, 'The bounded workflow attempt limit was reached.');
            return IguideLabWorkflowStep::create(['project_id' => $item->project_id, 'item_id' => $item->id, 'step_key' => $key,
                'kind' => $kind, 'request_id' => (string) Str::uuid(), 'input' => $input, 'input_sha256' => $hash, 'status' => 'reserved']);
        }), 'iguide-lab.workflow-step');
    }
    public function attach(IguideLabWorkflowStep $step, string $childId): void
    {
        LockedWrite::run(function () use ($step, $childId) {
            $fresh = $step->fresh(); abort_unless($fresh->child_id === null || $fresh->child_id === $childId, 409, 'The child receipt changed.');
            $step->update(['child_id' => $childId, 'status' => 'running']);
        }, 'iguide-lab.workflow-child');
    }
    public function invocation(IguideLabWorkflowStep $step): array
    {
        $item = IguideLabWorkflowItem::findOrFail($step->item_id); $workflow = IguideLabWorkflow::findOrFail($item->workflow_id);
        return ['version' => 'workflow-invocation/1', 'workflowId' => $workflow->id, 'itemId' => $item->id, 'stepId' => $step->id,
            'requestId' => $step->request_id, 'kind' => $step->kind, 'sourceSha256' => $item->source['sha256'],
            'policySha256' => LabAnalysisRecipes::hash($workflow->policy), 'automaticGeneration' => true, 'operatorInterventions' => 0];
    }
}
