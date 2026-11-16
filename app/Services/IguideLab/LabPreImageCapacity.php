<?php
namespace App\Services\IguideLab;

use App\Models\{IguideLabComposite,IguideLabLifestyleAssessment,IguideLabProject,IguideLabRun,IguideLabWorkflowEvaluation,IguideLabWorkflowItem,IguideLabWorkflowStep};

/**
 * Checked before a declutter or lifestyle image is bought, as staging does (LabStagingRoomAlias::capacityStop):
 * the paid image must be able to reach a viewable result. Ceilings mirror the record services that run after
 * the image: LabService (runs), LabComposites (120 per project), LabWorkflowEvaluations and
 * LabLifestyleAssessments (120 per project and 120 per day across the lab). Machine segmentation has no
 * record ceiling; its per-item allowance is fixed by LabWorkflowSteps for each recipe.
 */
class LabPreImageCapacity
{
    /** Records each preset creates after its image, with whether a rolling daily lab ceiling also applies. */
    private const AFTER_IMAGE=[
        'declutter'=>[IguideLabComposite::class=>false,IguideLabWorkflowEvaluation::class=>true],
        'lifestyle'=>[IguideLabLifestyleAssessment::class=>true,IguideLabComposite::class=>false,IguideLabWorkflowEvaluation::class=>true],
    ];
    private const LIMIT=120;

    /** Null when there is room or the image step already exists (resume never stops a bought image). */
    public function stop(IguideLabWorkflowItem $item,string $imageStepKey): ?array
    {
        $records=self::AFTER_IMAGE[$item->preset]??null;abort_unless($records!==null,422,'No pre-image capacity recipe for this preset.');
        if(IguideLabWorkflowStep::where('item_id',$item->id)->where('step_key',$imageStepKey)->exists())return null;
        $project=IguideLabProject::findOrFail($item->project_id);
        $full=$project->used_runs>=$project->max_runs||$this->count(IguideLabRun::class,null)>=self::LIMIT;
        foreach($records as $model=>$daily)$full=$full||$this->count($model,$project->id)>=self::LIMIT||($daily&&$this->count($model,null)>=self::LIMIT);
        return $full?['generation_capacity_unavailable','No image was made: this lab has no generation or result capacity left for a '.$item->preset.' image.']:null;
    }
    /** Lifetime count for one project, or the rolling daily count across the lab when $projectId is null. */
    public function count(string $model,?string $projectId): int
    {return $projectId===null?$model::where('created_at','>=',now()->subDay())->count():$model::where('project_id',$projectId)->count();}
}
