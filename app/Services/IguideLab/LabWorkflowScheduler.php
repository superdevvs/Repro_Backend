<?php
namespace App\Services\IguideLab;

use App\Models\{IguideLabProject,IguideLabRun,IguideLabWorkflow,IguideLabWorkflowItem};
use App\Support\LockedWrite;
use Illuminate\Support\Facades\DB;

/** Bounded polling and admission, independent from all preset algorithms. */
class LabWorkflowScheduler
{
    public const VERSION = 'bounded-sequential-tour/1';
    public const MAX_ITEMS = 80;
    public const ACTIVE_SECONDS = 7200;
    public const CAPACITY_WAIT_SECONDS = 90000;
    public static function enabled(): bool { return config('iguide-lab.tour_scheduler_enabled', false) === true; }
    public static function maxItems(): int { return self::enabled() ? self::MAX_ITEMS : 20; }

    public function snapshot(IguideLabProject $project, array $items,array $policy): array
    {
        $count=count($items);abort_unless($count >= 1 && $count <= self::MAX_ITEMS && $project->active(), 422);
        $records=array_fill_keys(['analysis','section','image','segmentation','composition','evaluation'],0);
        foreach($items as $item)foreach(app(LabWorkflowSteps::class)->limits($policy,$item['preset']) as $kind=>$limit)$records[$kind]+=$limit;
        $created = now()->getTimestamp();
        return ['version'=>self::VERSION,'maxItems'=>self::MAX_ITEMS,'maxActiveItems'=>1,
            'activeItemSeconds'=>self::ACTIVE_SECONDS,'capacityWaitSeconds'=>self::CAPACITY_WAIT_SECONDS,
            'itemCount'=>$count,'createdAt'=>$created,'recordAllowances'=>$records,
            'expiresAt'=>min($project->expires_at->getTimestamp(),$created+$count*self::ACTIVE_SECONDS+self::CAPACITY_WAIT_SECONDS)];
    }
    public function policy(IguideLabWorkflow $workflow): ?array
    {
        $s=$workflow->policy['scheduler']??null;
        if($s===null)return null;
        abort_unless(is_array($s)&&count($s)===9&&!array_diff(array_keys($s),['version','maxItems','maxActiveItems','activeItemSeconds','capacityWaitSeconds','itemCount','createdAt','recordAllowances','expiresAt'])
            &&$s['version']===self::VERSION&&$s['maxItems']===self::MAX_ITEMS&&$s['maxActiveItems']===1
            &&$s['activeItemSeconds']===self::ACTIVE_SECONDS&&$s['capacityWaitSeconds']===self::CAPACITY_WAIT_SECONDS
            &&is_int($s['itemCount'])&&$s['itemCount']>=1&&$s['itemCount']<=self::MAX_ITEMS
            &&is_int($s['createdAt'])&&is_int($s['expiresAt'])&&$s['expiresAt']>$s['createdAt']
            &&$s['expiresAt']<=$s['createdAt']+$s['itemCount']*self::ACTIVE_SECONDS+self::CAPACITY_WAIT_SECONDS,422,'The tour scheduler policy changed.');
        $expected=array_fill_keys(['analysis','section','image','segmentation','composition','evaluation'],0);
        $items=IguideLabWorkflowItem::where('workflow_id',$workflow->id)->get();abort_unless($items->count()===$s['itemCount'],422,'The capture count changed.');
        foreach($items as $item)foreach(app(LabWorkflowSteps::class)->limits($workflow->policy,$item->preset) as $kind=>$limit)$expected[$kind]+=$limit;
        abort_unless(LabAnalysisRecipes::hash($expected)===LabAnalysisRecipes::hash($s['recordAllowances']??[]),422,'The frozen record allowance changed.');
        app(LabTourProcessingBudget::class)->verify($workflow,$expected);
        return $s;
    }
    public function verifyJob(IguideLabWorkflow $workflow, ?int $deadline, ?string $policyHash): void
    {
        $s=$this->policy($workflow);
        abort_unless($s===null ? $deadline===null&&$policyHash===null
            : $deadline===$s['expiresAt']&&is_string($policyHash)&&hash_equals(LabAnalysisRecipes::hash($workflow->policy),$policyHash),422,'The queued tour deadline or policy changed.');
    }
    public function assertWindow(IguideLabWorkflow $workflow, IguideLabWorkflowItem $item): void
    {
        $s=$this->policy($workflow);if($s===null)return;
        abort_if(now()->getTimestamp()>=$s['expiresAt'],409,'The bounded tour processing window expired.');
        $state=$item->scheduler;
        abort_unless(is_array($state)&&($state['version']??null)===self::VERSION&&is_int($state['admittedAt']??null)
            &&is_int($state['deadlineAt']??null),409,'The workflow item has not been admitted.');
        abort_if(($state['waitingSince']??null)!==null,409,'This item is waiting for processing capacity.');
        abort_if(now()->getTimestamp()>=$state['deadlineAt'],409,'The bounded item processing window expired.');
    }

    /** Also called inside the existing locked image reservation; it is authoritative there. */
    public function assertImageCapacity(IguideLabProject $project,?IguideLabWorkflow $workflow=null): void
    {
        if($project->used_runs >= $project->max_runs)throw new LabWorkflowCapacity('project_image_capacity',null);
        if($workflow!==null&&$this->policy($workflow)!==null&&app(LabTourProcessingBudget::class)->verify($workflow,$workflow->policy['scheduler']['recordAllowances'])){
            $items=IguideLabWorkflowItem::where('workflow_id',$workflow->id)->pluck('id');
            $requests=\App\Models\IguideLabWorkflowStep::whereIn('item_id',$items)->where('kind','image')->pluck('request_id');
            abort_unless($requests->count()<=$workflow->policy['scheduler']['recordAllowances']['image'],422);
            if(IguideLabRun::where('project_id',$project->id)->whereIn('request_id',$requests)->count()>=$workflow->policy['scheduler']['recordAllowances']['image'])
                throw new LabWorkflowCapacity('tour_image_capacity',null);
            return;
        }
        $query=IguideLabRun::where('created_at','>=',now()->subDay());
        if((clone $query)->count()>=120){
            $oldest=(clone $query)->oldest('created_at')->firstOrFail();
            throw new LabWorkflowCapacity('daily_image_capacity',$oldest->created_at->addDay()->getTimestamp()+1);
        }
    }

    /** Returns false for a persisted wait/hold. Only the first nonterminal item is eligible. */
    public function admit(IguideLabWorkflow $workflow, IguideLabWorkflowItem $item, IguideLabProject $project): bool
    {
        $s=$this->policy($workflow);if($s===null)return true;
        if(now()->getTimestamp()>=$s['expiresAt']){
            app(LabWorkflows::class)->stop($workflow,'tour_deadline','The bounded tour processing window expired. Existing results were retained.');return false;
        }
        $state=$item->scheduler;
        if(is_array($state)&&($state['waitingSince']??null)!==null){
            if(now()->getTimestamp()-$state['waitingSince']+($state['waitedSeconds']??0)>=self::CAPACITY_WAIT_SECONDS){
                app(LabWorkflows::class)->finish($item,'needs_review','capacity_wait_expired','The bounded capacity wait expired. Existing results were retained.');return false;
            }
            if(now()->getTimestamp()<($state['nextEligibleAt']??0))return false;
        }
        if($state===null||($state['waitingSince']??null)!==null){
            // A completed image may have consumed the last project allowance.
            // Its pending analysis/evaluation must resume against that role's
            // locked capacity guard, not require allowance for another image.
            if($state===null||($state['admittedAt']??null)===null||($state['capacityReason']??null)==='daily_image_capacity'){
                try{$this->assertImageCapacity($project->fresh(),$workflow);}
                catch(LabWorkflowCapacity $hold){$this->hold($workflow,$item,$hold);return false;}
            }
            LockedWrite::run(fn()=>DB::transaction(function()use($workflow,$item,$s){
                IguideLabProject::whereKey($item->project_id)->update(['updated_at'=>now()]);
                abort_if($workflow->fresh()->cancelled_at,409);
                $first=IguideLabWorkflowItem::where('workflow_id',$workflow->id)->whereNotIn('status',LabWorkflows::TERMINAL)->orderBy('position')->first();
                abort_unless($first?->id===$item->id,409,'Only the first queued capture may be admitted.');
                $old=$item->fresh()->scheduler;$now=now()->getTimestamp();
                if($old===null)$next=['version'=>self::VERSION,'admittedAt'=>$now,'deadlineAt'=>min($s['expiresAt'],$now+self::ACTIVE_SECONDS),
                    'waitingSince'=>null,'nextEligibleAt'=>null,'waitedSeconds'=>0,'capacityReason'=>null];
                else{
                    $elapsed=$now-($old['waitingSince']??$now);
                    $next=$old;$next['admittedAt']??=$now;
                    $next['deadlineAt']=min($s['expiresAt'],($old['deadlineAt']??($now+self::ACTIVE_SECONDS))+$elapsed);
                    // No active allowance existed before initial capacity admission.
                    if(($old['admittedAt']??null)===null)$next['deadlineAt']=min($s['expiresAt'],$now+self::ACTIVE_SECONDS);
                    $next['waitedSeconds']=($old['waitedSeconds']??0)+$elapsed;$next['waitingSince']=null;$next['nextEligibleAt']=null;$next['capacityReason']=null;
                }
                $item->update(['scheduler'=>$next]);
            }),'iguide-lab.tour-admit');
            $item->refresh();
        }
        $state=$item->scheduler;
        if(now()->getTimestamp()>=($state['deadlineAt']??0)){
            app(LabWorkflows::class)->finish($item,'needs_review','item_deadline','This capture exceeded its bounded processing window. Existing requests and results were retained.');return false;
        }
        $this->assertWindow($workflow,$item);return true;
    }
    public function hold(IguideLabWorkflow $workflow,IguideLabWorkflowItem $item,LabWorkflowCapacity $hold): void
    {
        $s=$this->policy($workflow);abort_unless($s!==null,422);
        if($hold->nextEligibleAt===null){
            app(LabWorkflows::class)->finish($item,'needs_review',$hold->reason,$hold->getMessage());return;
        }
        LockedWrite::run(fn()=>DB::transaction(function()use($workflow,$item,$hold,$s){
            IguideLabProject::whereKey($item->project_id)->update(['updated_at'=>now()]);
            if($workflow->fresh()->cancelled_at)return;
            $item->refresh();$state=$item->scheduler??['version'=>self::VERSION,'admittedAt'=>null,'deadlineAt'=>null,'waitedSeconds'=>0];
            $state['waitingSince']??=now()->getTimestamp();$state['nextEligibleAt']=min($hold->nextEligibleAt,$s['expiresAt']);$state['capacityReason']=$hold->reason;
            $item->update(['scheduler'=>$state,'status'=>'queued']);
        }),'iguide-lab.tour-capacity-wait');
    }
    public function releaseDelay(IguideLabWorkflow $workflow): int
    {
        if($this->policy($workflow)===null)return 15;
        $item=IguideLabWorkflowItem::where('workflow_id',$workflow->id)->whereNotIn('status',LabWorkflows::TERMINAL)->orderBy('position')->first();
        return max(15,min(60,($item?->scheduler['nextEligibleAt']??0)-now()->getTimestamp()));
    }
}
