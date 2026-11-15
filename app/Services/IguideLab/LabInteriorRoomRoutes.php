<?php
namespace App\Services\IguideLab;

use App\Models\{IguideLabProject,IguideLabRoomAssessment,IguideLabWorkflow,IguideLabWorkflowItem,IguideLabWorkflowStep};
use App\Support\LockedWrite;
use Illuminate\Support\Facades\DB;

/** One immutable route for one original source and user intent, before any branch is dispatched. */
class LabInteriorRoomRoutes
{
    public function bind(IguideLabWorkflowItem $item,IguideLabRoomAssessment $record): array
    {
        $record=app(LabInteriorRoomAssessments::class)->completed($item,$record->id,'route');
        abort_unless(($record->result['route']['status']??null)==='route_candidate',422,'The source classifier did not select a supported route.');
        $binding=$this->binding($item,$record);
        return LockedWrite::run(fn()=>DB::transaction(function()use($item,$record,$binding){
            IguideLabProject::whereKey($item->project_id)->update(['updated_at'=>now()]);
            $workflow=IguideLabWorkflow::where('project_id',$item->project_id)->findOrFail($item->workflow_id);
            $fresh=IguideLabWorkflowItem::where('project_id',$item->project_id)->findOrFail($item->id);
            abort_if($workflow->cancelled_at||in_array($fresh->status,['cancelled','ready','failed','needs_review'],true),409);
            abort_unless(LabInteriorRoomPolicy::enabled($workflow->policy,$fresh->preset),422);
            $current=$record->fresh();abort_unless($current->status==='completed'&&$current->result_sha256===$binding['resultSha256'],422);
            $data=$fresh->data??[];
            if(isset($data['interiorRoomRoute'])) {
                abort_unless(LabMachineSegmentationPlan::canonicalHash($data['interiorRoomRoute'])===LabMachineSegmentationPlan::canonicalHash($binding),409,'An Interior branch cannot be replaced.');
                return $data['interiorRoomRoute'];
            }
            abort_if(IguideLabWorkflowStep::where('item_id',$item->id)->where('step_key','!=','room-route')->exists(),409,'Interior route must be fixed before branch reservations.');
            $data['interiorRoomRoute']=$binding;$fresh->update(['data'=>$data]);return $binding;
        }),'iguide-lab.interior-room-route');
    }

    public function verify(IguideLabWorkflowItem $item,?string $profile=null): array
    {
        return LabInteriorRoomReadScope::verified($item,'route:'.($profile??'*'),fn()=>$this->verifyCurrent($item,$profile));
    }

    private function verifyCurrent(IguideLabWorkflowItem $item,?string $profile): array
    {
        $fresh=$item->fresh();$binding=$fresh->data['interiorRoomRoute']??null;
        abort_unless(is_array($binding),422,'No immutable source room route exists.');
        $record=app(LabInteriorRoomAssessments::class)->completed($fresh,$binding['assessmentId']??'','route');
        abort_unless(LabMachineSegmentationPlan::canonicalHash($binding)===LabMachineSegmentationPlan::canonicalHash($this->binding($fresh,$record)),422,'The retained room route changed.');
        abort_unless($profile===null||$binding['profileId']===$profile,422,'The source selected a different Interior profile.');
        return $binding;
    }

    private function binding(IguideLabWorkflowItem $item,IguideLabRoomAssessment $record): array
    {
        $profile=LabInteriorRoomProfiles::get($record->result['route']['profileId']);
        $draft=$profile['kind']==='new_furnishings'&&LabRoomEvidenceDraftPolicy::selected($record->data['recipe']??[])!==null;
        return ['version'=>$draft?LabRoomEvidenceDraftPolicy::ROUTE_VERSION:LabInteriorRoomProfiles::VERSION,'assessmentId'=>$record->id,'requestId'=>$record->request_id,
            'resultSha256'=>$record->result_sha256,'responseSha256'=>$record->data['providerReceipt']['response']['sha256'],
            'profileId'=>$profile['id'],'profileSha256'=>LabMachineSegmentationPlan::canonicalHash($profile),
            'sourceSha256'=>$item->source['sha256'],'sourceAuthoritySha256'=>$record->result['sourceAuthoritySha256'],
            'intentSha256'=>$record->result['intentSha256'],'metadataSha256'=>$record->result['metadataSha256'],
            'publicPreset'=>'interior-design','style'=>$item->style,'budget'=>$draft?LabRoomEvidenceDraftPolicy::BUDGET:LabInteriorRoomProfiles::BUDGET,
            'operatorInterventions'=>0,'branchSwitchAllowed'=>false];
    }
}
