<?php
namespace App\Services\IguideLab;

use App\Jobs\ProcessIguideLabRoomAssessment;
use App\Models\{IguideLabRoomAssessment,IguideLabProject,IguideLabWorkflow,IguideLabWorkflowItem,IguideLabWorkflowStep};
use App\Support\LockedWrite;
use Illuminate\Support\Facades\DB;

/** Separate durable room-routing records. Old material assessments are never reclassified. */
class LabInteriorRoomAssessments
{
    public function protocol(string $phase,?array $recipe=null): string
    {
        $draft=$recipe===null?config('iguide-lab.interior_room_evidence_drafts_enabled',false)===true:LabRoomEvidenceDraftPolicy::selected($recipe)!==null;
        return match($phase){'route'=>LabInteriorRoomRouteProtocol::class,'framing'=>LabRoomFurnishingFramingProtocol::class,
            'layout'=>$draft?LabRoomEvidenceLayoutProtocol::class:LabRoomFurnishingLayoutProtocol::class,
            'candidate'=>$draft?LabRoomEvidenceCandidateProtocol::class:LabRoomFurnishingCandidateProtocol::class,
            default=>abort(422,'Unknown Interior room assessment phase.')};
    }
    private function stepKey(string $phase,array $parents,array $recipe): string
    {
        if($phase==='candidate'&&LabRoomEvidenceDraftPolicy::selected($recipe)!==null){
            abort_unless(in_array($parents['imageOrdinal']??null,[1,2],true),422);return $parents['imageOrdinal']===2?'room-candidate-2':'room-candidate';}
        return 'room-'.$phase;
    }
    /** Historical display is a closed persisted version dispatch, never current execution readiness. */
    private function readProtocol(IguideLabRoomAssessment $record): string
    {
        $version=$record->data['contextSnapshot']['context']['version']??null;
        $allowed=['route'=>[LabInteriorRoomRouteProtocol::class],'framing'=>[LabRoomFurnishingFramingProtocol::class],
            'layout'=>[LabRoomFurnishingLayoutProtocol::class,LabRoomEvidenceLayoutProtocol::class],
            'candidate'=>[LabRoomFurnishingCandidateProtocol::class,LabRoomEvidenceCandidateProtocol::class]];
        foreach($allowed[$record->phase]??[] as $protocol)if($version===$protocol::VERSION)return $protocol;
        abort(422,'Unknown persisted room assessment read version.');
    }
    public function implementation(): array
    {
        $out=app(LabInteriorRoomContext::class)->snapshot();
        foreach(['service'=>self::class,'provider'=>LabInteriorRoomProvider::class,'job'=>ProcessIguideLabRoomAssessment::class,'validator'=>LabInteriorDesignProtocol::class,
            'policy'=>LabInteriorRoomPolicy::class,'wireCompiler'=>LabAnalysisRequestV1::class,'sectionCompiler'=>LabSectionAnalysisProtocol::class,
            'invocation'=>LabWorkflowInvocation::class,'steps'=>LabWorkflowSteps::class] as $key=>$class)$out[$key.'Sha256']=hash_file('sha256',(new \ReflectionClass($class))->getFileName());
        foreach(['route','framing','layout','candidate'] as $phase)$out[$phase]=$this->protocol($phase)::snapshot();
        // A passed assessment runtime also validates the complete PHP orchestration/authority adapter.
        // Taking a current hash only at reservation would otherwise admit unvalidated changed code.
        $out['workflow']=app(LabInteriorRoomWorkflow::class)->snapshot();return $out;
    }
    public function snapshot(): array
    {
        $path=(string)config('iguide-lab.interior_room_runtime_manifest');
        abort_unless($path!==''&&is_file($path)&&!is_link($path)&&filesize($path)<=65536,503,'Validated Interior assessment runtime is unavailable.');
        $m=json_decode(file_get_contents($path),true,flags:JSON_THROW_ON_ERROR);$implementation=$this->implementation();
        abort_unless(($m['version']??null)===LabInteriorRoomPolicy::VERSION&&($m['validationStatus']??null)==='passed'
            &&($m['pythonPath']??null)===(string)config('iguide-lab.pipeline_python_path')&&($m['pythonVersion']??null)==='3.12.3'
            &&($m['numpyVersion']??null)==='2.5.4'&&($m['pillowVersion']??null)==='13.0.0'
            &&is_file($m['pythonPath'])&&($m['pythonSha256']??null)===hash_file('sha256',$m['pythonPath'])
            &&LabMachineSegmentationPlan::canonicalHash($m['implementation']??[])===LabMachineSegmentationPlan::canonicalHash($implementation),503,'Interior room assessment implementation is not validated.');
        return ['version'=>LabInteriorRoomPolicy::VERSION,'runtimeManifestSha256'=>hash_file('sha256',$path),'implementation'=>$implementation];
    }
    public function ready(): bool
    {try{$this->snapshot();return app(LabCredentials::class)->ready()&&app(LabAnalysisViews::class)->ready();}catch(\Throwable){return false;}}
    public function validate(array $snapshot): void
    {abort_unless(LabAnalysisRecipes::hash($snapshot)===LabAnalysisRecipes::hash($this->snapshot()),422,'Reserved Interior implementation changed.');}
    public function reserve(IguideLabWorkflowItem $item,string $phase,array $parents=[]): IguideLabWorkflowStep
    {
        $workflow=IguideLabWorkflow::where('project_id',$item->project_id)->findOrFail($item->workflow_id);
        abort_unless(config('iguide-lab.interior_room_routing_enabled',false)===true&&LabInteriorRoomPolicy::enabled($workflow->policy,$item->preset),503,'Interior room routing is disabled.');
        $recipe=$workflow->policy['interiorRoomAssessments']??[];$this->validate($recipe);$protocol=$this->protocol($phase,$recipe);
        $snapshot=app(LabInteriorRoomContext::class)->capture($item,$phase,$parents);
        return app(LabWorkflowSteps::class)->reserve($item,$this->stepKey($phase,$parents,$recipe),'analysis',['protocolVersion'=>$protocol::VERSION,'phase'=>$phase,'parents'=>$parents,'contextSnapshot'=>$snapshot,'recipe'=>$recipe]);
    }
    public function verifyStep(IguideLabWorkflowStep $step): IguideLabWorkflowItem
    {
        $input=$step->input;$phase=$input['phase']??'';$protocol=$this->protocol($phase,$input['recipe']??[]);
        abort_unless(config('iguide-lab.interior_room_routing_enabled',false)===true,503,'Interior room routing is disabled.');
        abort_unless($step->kind==='analysis'&&$step->step_key===$this->stepKey($phase,$input['parents']??[],$input['recipe']??[])&&($input['protocolVersion']??null)===$protocol::VERSION
            &&$step->input_sha256===LabAnalysisRecipes::hash($input),422,'Interior reservation changed.');
        LabInteriorDesignProtocol::keys($input,['protocolVersion','phase','parents','contextSnapshot','recipe']);
        $item=IguideLabWorkflowItem::where('project_id',$step->project_id)->findOrFail($step->item_id);
        $workflow=IguideLabWorkflow::where('project_id',$step->project_id)->findOrFail($item->workflow_id);
        abort_unless(LabInteriorRoomPolicy::enabled($workflow->policy,$item->preset),422);
        app(LabWorkflowInvocation::class)->verify(app(LabWorkflowSteps::class)->invocation($step),$step->project_id,$step->request_id,'analysis');
        $this->validate($input['recipe']);abort_unless(LabAnalysisRecipes::hash($input['recipe'])===LabAnalysisRecipes::hash($workflow->policy['interiorRoomAssessments']??[]),422);
        $current=app(LabInteriorRoomContext::class)->capture($item,$phase,$input['parents']);
        abort_unless(LabAnalysisRecipes::hash($current)===LabAnalysisRecipes::hash($input['contextSnapshot']),422,'Interior source, intent or parent receipt changed.');return $item;
    }
    public function payload(IguideLabWorkflowStep $step): string
    {
        $images=[];$context=app(LabInteriorRoomContext::class);
        foreach($step->input['contextSnapshot']['assets'] as $asset){$file=$context->file($step->project_id,$asset['path'],$asset['sha256']);$images[]='data:image/png;base64,'.base64_encode(file_get_contents($file));}
        $protocol=$this->protocol($step->input['phase'],$step->input['recipe']);$wire=$protocol::request($step->input['contextSnapshot']['context'],$images);
        if($floor=LabRoomFloorWitness::selected($step->input['recipe']))$wire=LabRoomFloorWitness::request($wire,$floor,$step->input['phase']);
        if(($draft=LabRoomEvidenceDraftPolicy::selected($step->input['recipe']))!==null&&in_array($step->input['phase'],['layout','candidate'],true)){
            $binding=json_decode($wire['input'][0]['content'][1]['text'],true,flags:JSON_THROW_ON_ERROR);
            $binding['requestVersion']='interior-room-evidence-floor-witness-request/1';$binding['draftContract']=$draft;
            $wire['input'][0]['content'][1]['text']=json_encode($binding,JSON_THROW_ON_ERROR);
        }
        return json_encode($wire,JSON_THROW_ON_ERROR);
    }
    public function start(IguideLabWorkflowStep $step): IguideLabRoomAssessment
    {
        if($prior=IguideLabRoomAssessment::where('project_id',$step->project_id)->where('request_id',$step->request_id)->first()){
            abort_unless($prior->step_id===$step->id&&$prior->request_hash===$step->input_sha256&&$prior->phase===($step->input['phase']??null),409);return $this->reconcile($step,$prior);}
        $this->verifyStep($step);$bytes=$this->payload($step);$id=$step->request_id;
        app(LabStorage::class)->prepareRoomAssessment($step->project_id,$id);$path='iguide-lab/'.$step->project_id.'/room-assessments/'.$id.'/payload.json';
        app(LabInteriorRoomContext::class)->put($path,$bytes);
        $record=LockedWrite::run(fn()=>DB::transaction(function()use($step,$id,$path,$bytes){
            IguideLabProject::whereKey($step->project_id)->update(['updated_at'=>now()]);$invocation=app(LabWorkflowSteps::class)->invocation($step);
            app(LabWorkflowInvocation::class)->verify($invocation,$step->project_id,$step->request_id,'analysis',false);
            if($prior=IguideLabRoomAssessment::where('project_id',$step->project_id)->where('request_id',$step->request_id)->first()){
                abort_unless($prior->request_hash===$step->input_sha256&&$prior->step_id===$step->id&&$prior->phase===$step->input['phase'],409);return $prior;}
            abort_if(IguideLabRoomAssessment::where('project_id',$step->project_id)->count()>=240||IguideLabRoomAssessment::where('created_at','>=',now()->subDay())->count()>=240,429,'Interior assessment safety ceiling reached.');
            return IguideLabRoomAssessment::create(['id'=>$id,'project_id'=>$step->project_id,'step_id'=>$step->id,'request_id'=>$step->request_id,'request_hash'=>$step->input_sha256,
                'phase'=>$step->input['phase'],'status'=>'queued','data'=>['workflowInvocation'=>$invocation,'payloadPath'=>$path,'providerBodySha256'=>hash('sha256',$bytes),'contextSnapshot'=>$step->input['contextSnapshot'],'recipe'=>$step->input['recipe']]]);
        }),'iguide-lab.interior-reserve');return $this->reconcile($step,$record);
    }
    private function reconcile(IguideLabWorkflowStep $step,IguideLabRoomAssessment $record): IguideLabRoomAssessment
    {
        $record->refresh();$queue=$record->status==='queued'&&$record->started_at===null&&$record->provider_request_id===null&&empty($record->provider_attempts)&&empty($record->data['providerReceipt']);
        if($queue)$this->verifyStep($step);app(LabWorkflowSteps::class)->attach($step,$record->id);
        if($queue){try{ProcessIguideLabRoomAssessment::dispatch($record->id);}catch(\Throwable){$this->attention($record,'queue_receipt_unconfirmed');}}return $record->fresh();
    }
    public function verifyForAttempt(IguideLabRoomAssessment $record,string $payload): void
    {
        $record->refresh();abort_unless($record->status==='submitting'&&empty($record->data['providerReceipt']),409);
        $step=IguideLabWorkflowStep::where('project_id',$record->project_id)->findOrFail($record->step_id);$this->verifyStep($step);$this->verifyRecord($record,$step);
        abort_unless(hash('sha256',$payload)===$record->data['providerBodySha256']&&hash('sha256',$this->payload($step))===$record->data['providerBodySha256'],422,'Interior paid request changed.');
    }
    public function process(IguideLabRoomAssessment $record): void
    {
        $record->refresh();if(in_array($record->status,['completed','needs_attention'],true))return;
        if($record->status==='submitting'&&empty($record->data['providerReceipt'])){$this->attention($record,'submission_outcome_unconfirmed');return;}
        try{$step=IguideLabWorkflowStep::where('project_id',$record->project_id)->findOrFail($record->step_id);$this->verifyStep($step);$this->verifyRecord($record,$step);$bytes=$this->payload($step);
            abort_unless(hash('sha256',$bytes)===$record->data['providerBodySha256'],422);
            if(empty($record->data['providerReceipt'])){
                app(LabWorkflowInvocation::class)->claim($record->data['workflowInvocation'],$record->project_id,$record->request_id,'analysis',function()use($record){abort_unless($record->fresh()->status==='queued',409);$record->update(['status'=>'submitting','started_at'=>now()]);});
                app(LabInteriorRoomProvider::class)->assess($record,$bytes);return;
            }
            $result=$this->parseReceipt($record,$step);
            LockedWrite::run(fn()=>$record->update(['status'=>'completed','result'=>$result,'result_sha256'=>LabMachineSegmentationPlan::canonicalHash($result),'completed_at'=>now(),'error_code'=>null]),'iguide-lab.room-complete');
        }catch(\Throwable){$this->attention($record,in_array($record->fresh()->status,['submitting','received'],true)?'submitted_interior_unconfirmed_or_rejected':'interior_preflight_rejected');}
    }
    private function verifyRecord(IguideLabRoomAssessment $record,IguideLabWorkflowStep $step): void
    {
        abort_unless($record->project_id===$step->project_id&&$record->request_id===$step->request_id&&$record->request_hash===$step->input_sha256&&$record->step_id===$step->id
            &&$record->phase===$step->input['phase']&&$step->child_id===$record->id&&$record->data['workflowInvocation']===app(LabWorkflowSteps::class)->invocation($step)
            &&LabAnalysisRecipes::hash($record->data['contextSnapshot'])===LabAnalysisRecipes::hash($step->input['contextSnapshot'])
            &&LabAnalysisRecipes::hash($record->data['recipe'])===LabAnalysisRecipes::hash($step->input['recipe']),422,'Interior record association changed.');
        $path='iguide-lab/'.$record->project_id.'/room-assessments/'.$record->id.'/payload.json';abort_unless($record->data['payloadPath']===$path,422);
        app(LabInteriorRoomContext::class)->file($record->project_id,$path,$record->data['providerBodySha256'],40*1024*1024);
    }
    private function parseReceipt(IguideLabRoomAssessment $record,IguideLabWorkflowStep $step): array
    {
        $attempts=$record->provider_attempts??[];$index=count($attempts)-1;$a=$attempts[$index]??[];$receipt=$record->data['providerReceipt']??[];
        abort_unless(in_array(count($attempts),[1,2],true)&&($attempts[0]['credentialSource']??null)==='lab-primary'
            &&($attempts[0]['requestedModelId']??null)==='gpt-6-astra'&&($a['status']??null)==='received'
            &&($a['requestedModelId']??null)==='gpt-6-astra'&&($a['providerRequestId']??null)===$record->provider_request_id&&is_string($record->provider_request_id)&&$record->provider_request_id!==''
            &&($a['httpStatus']??0)>=200&&($a['httpStatus']??0)<300
            &&($index===0||(($attempts[0]['status']??null)==='quota_rejected'&&($attempts[0]['httpStatus']??null)===429&&($a['credentialSource']??null)==='dashboard-fallback')),422,'Interior provider receipt is not confirmed.');
        $path='iguide-lab/'.$record->project_id.'/room-assessments/'.$record->id.'/response-'.$index.'.json';
        abort_unless(($receipt['response']['path']??null)===$path&&($receipt['providerRequestId']??null)===$record->provider_request_id&&($receipt['payloadSha256']??null)===$record->data['providerBodySha256'],422);
        $file=app(LabInteriorRoomContext::class)->file($record->project_id,$path,$receipt['response']['sha256']??'',128*1024);
        $raw=json_decode(file_get_contents($file),true,flags:JSON_THROW_ON_ERROR);
        abort_unless(($raw['id']??null)===($receipt['providerResponseId']??null)&&is_string($raw['id']??null)&&$raw['id']!==''&&($raw['model']??null)==='gpt-6-astra'&&($receipt['model']??null)==='gpt-6-astra',422);
        $protocol=$this->protocol($record->phase,$record->data['recipe']??[]);$value=$protocol::parse($raw,$step->input['contextSnapshot']['context']);
        return LabRoomFloorWitness::selected($step->input['recipe'])?LabRoomFloorWitness::result($value,$record->phase):$value;
    }
    public function completed(IguideLabWorkflowItem $item,string $id,string $phase): IguideLabRoomAssessment
    {
        $record=IguideLabRoomAssessment::where('project_id',$item->project_id)->where('phase',$phase)->findOrFail($id);
        $step=IguideLabWorkflowStep::where('project_id',$item->project_id)->where('item_id',$item->id)->findOrFail($record->step_id);
        $this->verifyStep($step);$this->verifyRecord($record,$step);abort_unless($record->status==='completed',422);
        abort_unless(hash('sha256',$this->payload($step))===$record->data['providerBodySha256'],422);$result=$this->parseReceipt($record,$step);
        abort_unless(LabMachineSegmentationPlan::canonicalHash($result)===$record->result_sha256&&LabMachineSegmentationPlan::canonicalHash($record->result)===$record->result_sha256,422,'Interior room structured result changed.');return $record;
    }
    public function attention(IguideLabRoomAssessment $record,string $code): void
    {LockedWrite::run(fn()=>IguideLabRoomAssessment::whereKey($record->id)->where('status','!=','completed')->update(['status'=>'needs_attention','error_code'=>$code,'completed_at'=>now(),'updated_at'=>now()]),'iguide-lab.interior-attention');}
    public function dto(IguideLabRoomAssessment $record): array
    {
        $bundle=$record->data['contextSnapshot']??[];$c=$bundle['context']??[];
        $step=IguideLabWorkflowStep::where('project_id',$record->project_id)->findOrFail($record->step_id);
        $item=IguideLabWorkflowItem::where('project_id',$record->project_id)->findOrFail($step->item_id);
        $readProtocol=$this->readProtocol($record);$draftRead=in_array($readProtocol,[LabRoomEvidenceLayoutProtocol::class,LabRoomEvidenceCandidateProtocol::class],true)
            ||($c['route']['binding']['version']??null)===LabRoomEvidenceDraftPolicy::ROUTE_VERSION;
        $ref=fn($value)=>is_array($value)?array_intersect_key($value,array_flip(['id','resultSha256','responseSha256'])):null;
        return ['version'=>$readProtocol::VERSION,'id'=>$record->id,'requestId'=>$record->request_id,'stepId'=>$record->step_id,
            'phase'=>$record->phase,'status'=>$record->status,'nativeSourceSha256'=>$c['nativeSourceSha256']??null,
            'sourceSha256'=>$c['sourceSha256']??($c['nativeSourceSha256']??null),'preparedSourceSha256'=>$c['preparedSourceSha256']??null,
            'contextSha256'=>$c?LabMachineSegmentationPlan::canonicalHash($c):null,'resultSha256'=>$record->result_sha256,'result'=>$record->result,
            'providerResponseSha256'=>$record->data['providerReceipt']['response']['sha256']??null,
            'workflowInvocation'=>$record->data['workflowInvocation']??null,
            'provenance'=>['version'=>$draftRead?LabRoomEvidenceDraftPolicy::READ_VERSION:'interior-room-assessment-read/1','nativeCaseId'=>$item->case_id,
                'cutCaseId'=>$bundle['cutCaseId']??null,'sectionId'=>$bundle['sectionId']??null,
                'sourceAuthoritySha256'=>$c['sourceAuthoritySha256']??null,'intentSha256'=>$c['intentSha256']??null,
                'route'=>$c['route']['binding']??null,'framing'=>$ref($c['framing']??null),'layout'=>$ref($c['layout']??null),
                'sourceGeometrySha256'=>$bundle['geometry']['sha256']??null,
                'sourceContext'=>$bundle['sourceContextSnapshot']['binding']??null,'sourceContextBindingSha256'=>$bundle['sourceContextSnapshot']['bindingSha256']??null,
                'candidateRunId'=>$c['candidateRunId']??null,'candidateSha256'=>$c['candidateSha256']??null,
                ...($draftRead?['imageOrdinal'=>$c['imageOrdinal']??null,'correctionParent'=>$ref($c['correctionParent']??null)]:[])],
            'imageGenerationAuthorized'=>false,'nativeCompositionAuthorized'=>false,'automaticAcceptance'=>false,
            'errorCode'=>$record->error_code,'createdAt'=>$record->created_at->toIso8601String(),'completedAt'=>$record->completed_at?->toIso8601String()];
    }
}
