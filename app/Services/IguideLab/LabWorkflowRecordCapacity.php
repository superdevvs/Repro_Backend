<?php
namespace App\Services\IguideLab;

use App\Models\{IguideLabWorkflow,IguideLabWorkflowItem,IguideLabWorkflowStep};

/** Explicit server invocation permits bounded tour records, never a client lineage claim. */
class LabWorkflowRecordCapacity
{
    private const KINDS=[
        \App\Models\IguideLabAnalysis::class=>'analysis',\App\Models\IguideLabStagingFraming::class=>'analysis',
        \App\Models\IguideLabStagingAssessment::class=>'analysis',\App\Models\IguideLabGrassAssessment::class=>'analysis',
        \App\Models\IguideLabGrassSourceAdmission::class=>'analysis',\App\Models\IguideLabLifestyleFraming::class=>'analysis',
        \App\Models\IguideLabLifestyleAssessment::class=>'analysis',\App\Models\IguideLabDeclutterRemovalPlan::class=>'analysis',
        \App\Models\IguideLabInteriorAssessment::class=>'analysis',
        \App\Models\IguideLabRoomAssessment::class=>'analysis',
        \App\Models\IguideLabWorkflowEvaluation::class=>'evaluation',\App\Models\IguideLabSection::class=>'section',
        \App\Models\IguideLabComposite::class=>'composition',
    ];
    /** Only an owned extraction may create one additional cut helper per section. */
    public function sectionHelper(string $projectId,?array $invocation,array $input):bool
    {
        if($invocation===null)return false;
        app(LabWorkflowInvocation::class)->verify($invocation,$projectId,$invocation['requestId'],'section',false);
        $workflow=IguideLabWorkflow::where('project_id',$projectId)->findOrFail($invocation['workflowId']);
        if(app(LabWorkflowScheduler::class)->policy($workflow)===null)return false;
        $meta=$input['metadata']??[];
        abort_unless(($meta['role']??null)==='section-source'&&($input['projection']??null)==='rectilinear',422,'Only an extracted source helper uses the section allowance.');
        $section=\App\Models\IguideLabSection::where('project_id',$projectId)->where('request_id',$invocation['requestId'])->findOrFail($meta['sectionId']??'');
        abort_unless($section->case_id===($meta['parentCaseId']??null)&&$section->source_sha256===($meta['nativeSourceSha256']??null)
            &&$section->derived_case_id===null&&($section->data['workflowInvocation']??null)===$invocation,422,'The source helper does not belong to this extraction.');
        abort_if(\App\Models\IguideLabCase::where('project_id',$projectId)->where('metadata->sectionId',$section->id)->exists(),422,'This section already owns a different helper source.');
        $step=IguideLabWorkflowStep::where('project_id',$projectId)->findOrFail($invocation['stepId']);
        abort_unless(($step->input['caseId']??null)===$section->case_id,422);
        return true;
    }
    /** Called after exact replay checks, inside the existing project write lock. */
    public function assertRoom(string $model,string $projectId,?array $invocation,?int $dailyLimit=120,int $legacyLimit=120):void
    {
        $kind=self::KINDS[$model]??null;abort_unless($kind!==null,422,'Unsupported record capacity role.');
        $scheduler=null;
        if($invocation!==null){
            app(LabWorkflowInvocation::class)->verify($invocation,$projectId,$invocation['requestId'],$kind,false);
            $workflow=IguideLabWorkflow::where('project_id',$projectId)->findOrFail($invocation['workflowId']);
            $scheduler=app(LabWorkflowScheduler::class)->policy($workflow);
        }
        if($scheduler===null){
            abort_if($model::where('project_id',$projectId)->count()>=$legacyLimit
                ||($dailyLimit!==null&&$model::where('created_at','>=',now()->subDay())->count()>=$dailyLimit),429,'The processing safety ceiling was reached.');return;
        }
        // UUIDs are allocated only by the existing per-item bounded step service.
        // Thus history outside this explicit tour cannot exhaust its allowance.
        $itemIds=IguideLabWorkflowItem::where('workflow_id',$workflow->id)->pluck('id');
        $requests=IguideLabWorkflowStep::whereIn('item_id',$itemIds)->where('kind',$kind)->pluck('request_id');
        abort_unless($requests->contains($invocation['requestId'])&&$requests->count()<=$scheduler['recordAllowances'][$kind],422,'The reserved tour step allowance changed.');
        if($model::where('project_id',$projectId)->whereIn('request_id',$requests)->count()>=$scheduler['recordAllowances'][$kind])throw new LabWorkflowCapacity('tour_record_capacity',null);
        if($dailyLimit!==null){
            $query=$model::where('created_at','>=',now()->subDay());
            if((clone $query)->count()>=$dailyLimit){$oldest=(clone $query)->oldest('created_at')->firstOrFail();
                throw new LabWorkflowCapacity('daily_'.$kind.'_capacity',$oldest->created_at->addDay()->timestamp+1);}
        }
    }
}
