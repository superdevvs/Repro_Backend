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
    /** One owning budget definition, used for both reservation and tour allowance. */
    public function limits(array $policy,string $preset):array
    {
        $limits=['analysis'=>2,'section'=>8,'image'=>3,'segmentation'=>6,'composition'=>8,'evaluation'=>8];
        if(isset($policy['lightingRepair'])&&in_array($preset,['golden-hour','twilight'],true))$limits=array_replace($limits,['composition'=>5,'evaluation'=>5]);
        if(LabStagingAssessmentPolicy::enabled($policy,$preset)){
            $limits=array_replace($limits,['analysis'=>3,'image'=>1,'segmentation'=>2,'evaluation'=>1]);
            if(isset($policy['stagingLayout']))$limits=array_replace($limits,['analysis'=>4,'section'=>2,'composition'=>1]);
        }
        if(LabLifestyleAssessmentPolicy::enabled($policy,$preset))$limits=array_replace($limits,['analysis'=>3,'image'=>1,'segmentation'=>2,'evaluation'=>1,'section'=>1,'composition'=>1]);
        if(class_exists(LabGrassAssessmentPolicy::class)&&LabGrassAssessmentPolicy::enabled($policy,$preset)){
            $limits=array_replace($limits,['analysis'=>2,'image'=>1,'segmentation'=>6,'evaluation'=>1]);
            if(LabGrassSourceAdmissionPolicy::enabled($policy,$preset))$limits['analysis']=4;
        }
        if(LabDeclutterRemovalPolicy::enabled($policy,$preset))$limits=array_replace($limits,['analysis'=>2,'image'=>1,'segmentation'=>2,'evaluation'=>1,'section'=>1,'composition'=>1]);
        if(LabInteriorDesignPolicy::enabled($policy,$preset))$limits=array_replace($limits,['analysis'=>3,'image'=>1,'segmentation'=>15,'evaluation'=>1,'section'=>1,'composition'=>1]);
        return $limits;
    }
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
            $limit=$this->limits($workflow->policy,$item->preset)[$kind];
            if (isset($workflow->policy['lightingRepair']) && in_array($item->preset, ['golden-hour','twilight'], true)) {
                app(LabLightingRepairs::class)->validate($workflow->policy);
                if (str_contains($key, '-global-')) abort_unless(in_array($key, ['composition-global-1','evaluation-global-1','composition-global-2','evaluation-global-2'], true), 422, 'At most two local lighting repairs are allowed.');
            }
            if (LabStagingAssessmentPolicy::enabled($workflow->policy, $item->preset) && isset($workflow->policy['stagingLayout'])) {
                if ($kind === 'section') abort_unless(in_array($key,['staging-section','staging-reframed-section'],true),422,'Unsupported source section step.');
                if ($kind === 'analysis') abort_unless(in_array($key,['staging-framing','staging-layout-initial','staging-layout-followup'],true)
                    || (($input['protocolVersion']??null)===LabStagingAssessmentPolicy::VERSION&&$key==='placement-'.($input['candidateRunId']??'')),422,'Unsupported bounded staging analysis step.');
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
