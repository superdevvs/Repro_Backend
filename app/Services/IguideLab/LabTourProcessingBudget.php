<?php
namespace App\Services\IguideLab;

use App\Models\{IguideLabProject,IguideLabTourBudget,IguideLabWorkflow};
use App\Support\LockedWrite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Finite operator-granted session capacity, separate from legacy rolling limits. */
class LabTourProcessingBudget
{
    public const VERSION='project-tour-processing-budget/1';
    // Absolute per-item ceilings across existing recipes, not execution permission.
    public const MAX_PER_ITEM=['analysis'=>5,'section'=>8,'image'=>3,'segmentation'=>15,'composition'=>8,'evaluation'=>8];
    public static function enabled():bool {return config('iguide-lab.tour_processing_budget_enabled',false)===true;}

    private function authority(IguideLabTourBudget $grant):array
    {
        $implementation=[];
        foreach([self::class,LabWorkflowScheduler::class,LabWorkflowRecordCapacity::class,LabWorkflowSteps::class,LabWorkflowInvocation::class,LabService::class,LabWorkflows::class] as $class)
            $implementation[$class]=hash_file('sha256',(new \ReflectionClass($class))->getFileName());
        return ['version'=>self::VERSION,'id'=>$grant->id,'projectId'=>$grant->project_id,'maxItems'=>$grant->max_items,
            'limits'=>$grant->limits,'expiresAt'=>$grant->expires_at->timestamp,'implementation'=>$implementation];
    }
    private function validateGrant(IguideLabTourBudget $grant,IguideLabProject $project):void
    {
        abort_unless($grant->project_id===$project->id&&$project->active()&&!$grant->revoked_at&&$grant->expires_at->isFuture()
            &&$grant->expires_at->timestamp<=$project->expires_at->timestamp,409,'The private tour budget is unavailable or expired.');
        abort_unless(is_int($grant->max_items)&&$grant->max_items>=1&&$grant->max_items<=80
            &&is_array($grant->limits)&&array_keys($grant->limits)===array_keys(self::MAX_PER_ITEM),422,'The private tour budget changed.');
        foreach(self::MAX_PER_ITEM as $kind=>$max)abort_unless(is_int($grant->limits[$kind])&&$grant->limits[$kind]>=0
            &&$grant->limits[$kind]<=$grant->max_items*$max,422,'The private tour budget exceeds recipe bounds.');
        abort_unless(hash_equals($grant->authority_sha256,LabAnalysisRecipes::hash($this->authority($grant))),422,'The private budget authority changed.');
    }
    /** Operator-only; no HTTP field or public capability grants processing budget. */
    public function create(string $projectId,int $maxItems,array $limits,int $expiresAt):array
    {
        abort_unless($maxItems>=1&&$maxItems<=80&&array_keys($limits)===array_keys(self::MAX_PER_ITEM),422);
        foreach(self::MAX_PER_ITEM as $kind=>$max)abort_unless(is_int($limits[$kind])&&$limits[$kind]>=0&&$limits[$kind]<=$maxItems*$max,422,'Use finite recipe-bounded limits.');
        return LockedWrite::run(fn()=>DB::transaction(function()use($projectId,$maxItems,$limits,$expiresAt){
            IguideLabProject::whereKey($projectId)->update(['updated_at'=>now()]);$project=IguideLabProject::findOrFail($projectId);
            abort_unless($project->active()&&$expiresAt>now()->timestamp&&$expiresAt<=$project->expires_at->timestamp,422,'Budget expiry must fit the active session.');
            abort_if(IguideLabTourBudget::where('project_id',$projectId)->whereNull('revoked_at')->where('expires_at','>',now())->exists(),409,'A live session budget already exists.');
            abort_unless($project->max_runs-$project->used_runs>=$limits['image'],422,'Explicit project image capacity is insufficient; no quota was changed.');
            $grant=new IguideLabTourBudget(['id'=>(string)Str::uuid(),'project_id'=>$projectId,'max_items'=>$maxItems,'limits'=>$limits,
                'reserved_items'=>0,'reserved'=>array_fill_keys(array_keys(self::MAX_PER_ITEM),0),'expires_at'=>\Carbon\Carbon::createFromTimestampUTC($expiresAt)]);
            $grant->authority_sha256=LabAnalysisRecipes::hash($this->authority($grant));$grant->save();
            return $this->authority($grant)+['authoritySha256'=>$grant->authority_sha256,'projectMaxRuns'=>$project->max_runs,'usedRuns'=>$project->used_runs,'providerCalls'=>0];
        }),'iguide-lab.tour-budget-create');
    }
    public function revoke(string $id):void
    {
        $grant=IguideLabTourBudget::findOrFail($id);
        LockedWrite::run(fn()=>DB::transaction(function()use($grant){
            IguideLabProject::whereKey($grant->project_id)->update(['updated_at'=>now()]);
            IguideLabTourBudget::whereKey($grant->id)->whereNull('revoked_at')->update(['revoked_at'=>now()]);
        }),'iguide-lab.tour-budget-revoke');
    }
    /** Inside the workflow's existing project write transaction, after replay reconciliation. */
    public function reserve(IguideLabProject $project,string $workflowId,array $items,array $policy):?array
    {
        if(!self::enabled())return null;
        abort_unless(isset($policy['scheduler']),422,'A tour processing budget requires the bounded scheduler.');
        $grants=IguideLabTourBudget::where('project_id',$project->id)->whereNull('revoked_at')->where('expires_at','>',now())->limit(2)->get();
        abort_unless($grants->count()===1,429,'An explicit unambiguous active project tour budget is required.');$grant=$grants->sole();
        $this->validateGrant($grant,$project);$this->assertLedger($grant);$needed=array_fill_keys(array_keys(self::MAX_PER_ITEM),0);
        foreach($items as $item)foreach(app(LabWorkflowSteps::class)->limits($policy,$item['preset']) as $kind=>$limit)$needed[$kind]+=$limit;
        abort_unless($needed===$policy['scheduler']['recordAllowances'],422,'The requested tour allowance changed.');
        $count=count($items);$reserved=$grant->reserved;
        abort_unless(is_int($grant->reserved_items)&&$grant->reserved_items>=0&&$grant->reserved_items+$count<=$grant->max_items,429,'The session capture allowance is exhausted.');
        abort_unless(is_array($reserved)&&array_keys($reserved)===array_keys($needed),422);
        foreach($needed as $kind=>$limit){
            abort_unless(is_int($reserved[$kind])&&$reserved[$kind]>=0&&$reserved[$kind]+$limit<=$grant->limits[$kind],429,'The session processing allowance is exhausted.');
            $reserved[$kind]+=$limit;
        }
        // This is not an image charge. Existing used_runs/max_runs remains authoritative at image reservation.
        abort_unless($project->max_runs-$project->used_runs>=$needed['image'],429,'Project image capacity cannot cover this bounded tour.');
        $grant->update(['reserved_items'=>$grant->reserved_items+$count,'reserved'=>$reserved]);
        return ['version'=>self::VERSION,'budgetId'=>$grant->id,'authoritySha256'=>$grant->authority_sha256,'projectId'=>$project->id,
            'workflowId'=>$workflowId,'itemCount'=>$count,'recordAllowances'=>$needed,'expiresAt'=>$grant->expires_at->timestamp];
    }
    /** Rechecked at claims and locked record reservations; disabling new admission does not rewrite history. */
    public function verify(IguideLabWorkflow $workflow,array $expected):bool
    {
        $a=$workflow->policy['tourProcessingAllowance']??null;if($a===null)return false;
        abort_unless(is_array($a)&&array_keys($a)===['version','budgetId','authoritySha256','projectId','workflowId','itemCount','recordAllowances','expiresAt']
            &&$a['version']===self::VERSION&&$a['projectId']===$workflow->project_id&&$a['workflowId']===$workflow->id
            &&$a['recordAllowances']===$expected&&$a['itemCount']===($workflow->policy['scheduler']['itemCount']??null),422,'The reserved tour budget binding changed.');
        $grant=IguideLabTourBudget::findOrFail($a['budgetId']);$project=IguideLabProject::findOrFail($workflow->project_id);$this->validateGrant($grant,$project);
        abort_unless($a['authoritySha256']===$grant->authority_sha256&&$a['expiresAt']===$grant->expires_at->timestamp,422,'The reserved budget authority changed.');
        $this->assertLedger($grant);
        return true;
    }
    private function assertLedger(IguideLabTourBudget $grant):void
    {
        $sum=array_fill_keys(array_keys(self::MAX_PER_ITEM),0);$count=0;
        foreach(IguideLabWorkflow::where('project_id',$grant->project_id)->get(['id','policy']) as $w){
            $other=$w->policy['tourProcessingAllowance']??null;if(($other['budgetId']??null)!==$grant->id)continue;
            abort_unless($other['version']===self::VERSION&&$other['expiresAt']===$grant->expires_at->timestamp
                &&$other['workflowId']===$w->id&&$other['projectId']===$grant->project_id&&$other['authoritySha256']===$grant->authority_sha256
                &&is_int($other['itemCount'])&&$other['itemCount']>=1&&$other['itemCount']<=80&&is_array($other['recordAllowances'])
                &&array_keys($other['recordAllowances'])===array_keys($sum),422,'The session allocation ledger changed.');
            $count+=$other['itemCount'];foreach($sum as $kind=>$unused){$n=$other['recordAllowances'][$kind];abort_unless(is_int($n)&&$n>=0&&$n<=$other['itemCount']*self::MAX_PER_ITEM[$kind],422);$sum[$kind]+=$n;}
        }
        abort_unless($count===$grant->reserved_items&&$count<=$grant->max_items&&$sum===$grant->reserved,422,'The private allocation ledger changed.');
        foreach($sum as $kind=>$n)abort_unless($n<=$grant->limits[$kind],422,'The private session allowance changed.');
    }
}
