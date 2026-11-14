<?php
namespace App\Services\IguideLab;
use App\Jobs\ProcessIguideLabLifestyleFraming;
use App\Models\{IguideLabProject,IguideLabLifestyleFraming,IguideLabWorkflow,IguideLabWorkflowItem,IguideLabWorkflowStep};
use App\Support\LockedWrite;
use Illuminate\Support\Facades\{DB,Storage};

/** Internal durable framing authority. No client can assert a candidate or dispatch an image through this service. */
class LabLifestyleFramings
{
    public function implementation(): array
    {
        $out=app(LabLifestyleFramingContext::class)->snapshot();
        foreach(['service'=>self::class,'protocol'=>LabLifestyleFramingProtocol::class,'provider'=>LabLifestyleFramingProvider::class,
            'cutProtocol'=>LabLifestyleCutProtocol::class,'cutReadiness'=>LabLifestyleCutReadiness::class,'analysisService'=>LabAnalyses::class,
            'analysisProvider'=>LabAnalysisProvider::class,'invocation'=>LabWorkflowInvocation::class,'driver'=>LabLifestyleWorkflow::class,
            'artifactAdapter'=>LabLifestyleArtifacts::class,'recipeRegistry'=>LabAnalysisRecipes::class,'job'=>ProcessIguideLabLifestyleFraming::class] as $key=>$class)$out[$key.'Sha256']=hash_file('sha256',(new \ReflectionClass($class))->getFileName());
        return $out;
    }
    public function snapshot(): array
    {
        $path=(string)config('iguide-lab.lifestyle_framing_runtime_manifest');
        abort_unless($path!==''&&is_file($path)&&!is_link($path)&&filesize($path)<=65536,503,'Validated framing runtime is unavailable.');
        $manifest=json_decode(file_get_contents($path),true,flags:JSON_THROW_ON_ERROR);$implementation=$this->implementation();
        abort_unless(($manifest['version']??null)===LabLifestyleFramingProtocol::VERSION&&($manifest['validationStatus']??null)==='passed'
            &&($manifest['pythonPath']??null)===(string)config('iguide-lab.pipeline_python_path')
            &&($manifest['pythonVersion']??null)==='3.12.3'&&($manifest['numpyVersion']??null)==='2.5.4'&&($manifest['pillowVersion']??null)==='13.0.0'
            &&($manifest['implementation']??null)===$implementation,503,'Framing implementation is not validated.');
        return ['version'=>LabLifestyleFramingProtocol::VERSION,'implementation'=>$implementation,'runtimeManifestSha256'=>hash_file('sha256',$path),
            'protocol'=>LabLifestyleFramingProtocol::snapshot(),'cutRecipe'=>app(LabAnalysisRecipes::class)->snapshot(LabAnalysisRecipes::LIFESTYLE_CUT)];
    }
    public function ready(): bool
    {try{$this->snapshot();return app(LabCredentials::class)->ready()&&app(LabAnalysisViews::class)->ready();}catch(\Throwable){return false;}}
    public function validate(array $snapshot): void
    {abort_unless(LabAnalysisRecipes::hash($snapshot)===LabAnalysisRecipes::hash($this->snapshot()),422,'Reserved framing implementation changed.');}
    public function reserve(IguideLabWorkflowItem $item): IguideLabWorkflowStep
    {
        $workflow=IguideLabWorkflow::where('project_id',$item->project_id)->findOrFail($item->workflow_id);
        abort_unless($item->preset==='lifestyle'&&isset($workflow->policy['lifestyleFraming'])&&LabLifestyleAssessmentPolicy::enabled($workflow->policy,$item->preset),422);
        $this->validate($workflow->policy['lifestyleFraming']);
        return app(LabWorkflowSteps::class)->reserve($item,'lifestyle-framing','analysis',['protocolVersion'=>LabLifestyleFramingProtocol::VERSION,
            'contextSnapshot'=>app(LabLifestyleFramingContext::class)->capture($item),'recipe'=>$workflow->policy['lifestyleFraming']]);
    }
    private function verifyStep(IguideLabWorkflowStep $step): IguideLabWorkflowItem
    {
        abort_unless(config('iguide-lab.lifestyle_framing_enabled',false)===true,503,'Source framing is disabled.');
        abort_unless($step->kind==='analysis'&&$step->step_key==='lifestyle-framing'&&($step->input['protocolVersion']??null)===LabLifestyleFramingProtocol::VERSION
            &&$step->input_sha256===LabAnalysisRecipes::hash($step->input),422,'Framing reservation changed.');
        $item=IguideLabWorkflowItem::where('project_id',$step->project_id)->findOrFail($step->item_id);
        $workflow=IguideLabWorkflow::where('project_id',$step->project_id)->findOrFail($item->workflow_id);
        abort_unless($item->preset==='lifestyle'&&isset($workflow->policy['lifestyleFraming'])&&LabLifestyleAssessmentPolicy::enabled($workflow->policy,$item->preset),422);
        app(LabWorkflowInvocation::class)->verify(app(LabWorkflowSteps::class)->invocation($step),$step->project_id,$step->request_id,'analysis');
        $this->validate($step->input['recipe']);abort_unless(LabAnalysisRecipes::hash($step->input['recipe'])===LabAnalysisRecipes::hash($workflow->policy['lifestyleFraming']),422);
        abort_unless(LabAnalysisRecipes::hash(app(LabLifestyleFramingContext::class)->capture($item))===LabAnalysisRecipes::hash($step->input['contextSnapshot']),422,'Framing source/evidence changed.');return $item;
    }
    private function payload(IguideLabWorkflowStep $step): string
    {
        $images=[];$snapshot=$step->input['contextSnapshot'];
        foreach($snapshot['assets'] as $asset){$path=app(LabLifestyleFramingContext::class)->file($step->project_id,$asset['path'],$asset['sha256']);$images[]='data:image/png;base64,'.base64_encode(file_get_contents($path));}
        return json_encode(LabLifestyleFramingProtocol::request($snapshot['context'],$images),JSON_THROW_ON_ERROR);
    }
    /** Revalidate retained source evidence before each primary or explicit-quota attempt. */
    public function verifyForAttempt(IguideLabLifestyleFraming $record,string $payload): void
    {
        $record->refresh();$step=IguideLabWorkflowStep::where('project_id',$record->project_id)->findOrFail($record->step_id);
        $this->verifyStep($step);$this->verifyRecord($record,$step);
        abort_unless($record->status==='submitting'&&empty($record->data['providerReceipt'])
            &&hash('sha256',$payload)===$record->data['providerBodySha256']
            &&hash('sha256',$this->payload($step))===$record->data['providerBodySha256'],422,'Framing attempt authority changed.');
    }
    public function start(IguideLabWorkflowStep $step): IguideLabLifestyleFraming
    {
        if($prior=IguideLabLifestyleFraming::where('project_id',$step->project_id)->where('request_id',$step->request_id)->first()){
            abort_unless($prior->step_id===$step->id&&$prior->request_hash===$step->input_sha256,409);return $this->reconcile($step,$prior);}
        $this->verifyStep($step);$bytes=$this->payload($step);$id=$step->request_id;
        app(LabStorage::class)->prepareLifestyleFraming($step->project_id,$id);$path='iguide-lab/'.$step->project_id.'/lifestyle-framings/'.$id.'/payload.json';
        app(LabLifestyleFramingContext::class)->put($path,$bytes);
        $record=LockedWrite::run(fn()=>DB::transaction(function()use($step,$id,$path,$bytes){
            IguideLabProject::whereKey($step->project_id)->update(['updated_at'=>now()]);$invocation=app(LabWorkflowSteps::class)->invocation($step);
            app(LabWorkflowInvocation::class)->verify($invocation,$step->project_id,$step->request_id,'analysis',false);
            if($prior=IguideLabLifestyleFraming::where('project_id',$step->project_id)->where('request_id',$step->request_id)->first()){abort_unless($prior->request_hash===$step->input_sha256&&$prior->step_id===$step->id,409);return $prior;}
            abort_if(IguideLabLifestyleFraming::where('project_id',$step->project_id)->count()>=120||IguideLabLifestyleFraming::where('created_at','>=',now()->subDay())->count()>=120,429);
            return IguideLabLifestyleFraming::create(['id'=>$id,'project_id'=>$step->project_id,'step_id'=>$step->id,'request_id'=>$step->request_id,'request_hash'=>$step->input_sha256,
                'status'=>'queued','data'=>['workflowInvocation'=>$invocation,'payloadPath'=>$path,'providerBodySha256'=>hash('sha256',$bytes),'contextSnapshot'=>$step->input['contextSnapshot'],'recipe'=>$step->input['recipe']]]);
        }),'iguide-lab.framing-reserve');return $this->reconcile($step,$record);
    }
    private function reconcile(IguideLabWorkflowStep $step,IguideLabLifestyleFraming $record): IguideLabLifestyleFraming
    {
        $record->refresh();$queue=$record->status==='queued'&&$record->started_at===null&&$record->provider_request_id===null&&empty($record->provider_attempts)&&empty($record->data['providerReceipt']);
        if($queue)$this->verifyStep($step);app(LabWorkflowSteps::class)->attach($step,$record->id);
        if($queue){try{ProcessIguideLabLifestyleFraming::dispatch($record->id);}catch(\Throwable){$this->attention($record,'queue_receipt_unconfirmed');}}
        return $record->fresh();
    }
    public function process(IguideLabLifestyleFraming $record): void
    {
        $record->refresh();if(in_array($record->status,['completed','needs_attention'],true))return;
        if($record->status==='submitting'&&empty($record->data['providerReceipt'])){$this->attention($record,'submission_outcome_unconfirmed');return;}
        try{$step=IguideLabWorkflowStep::where('project_id',$record->project_id)->findOrFail($record->step_id);$this->verifyStep($step);$this->verifyRecord($record,$step);$bytes=$this->payload($step);
            abort_unless(hash('sha256',$bytes)===$record->data['providerBodySha256'],422);
            if(empty($record->data['providerReceipt'])){
                app(LabWorkflowInvocation::class)->claim($record->data['workflowInvocation'],$record->project_id,$record->request_id,'analysis',function()use($record){abort_unless($record->fresh()->status==='queued',409);$record->update(['status'=>'submitting','started_at'=>now()]);});
                app(LabLifestyleFramingProvider::class)->assess($record,$bytes);return;
            }
            $result=$this->parseReceipt($record,$step);$data=$record->data;
            LockedWrite::run(fn()=>$record->update(['status'=>'completed','result'=>$result,'result_sha256'=>LabAnalysisRecipes::hash($result),'completed_at'=>now(),'error_code'=>null]),'iguide-lab.framing-complete');
        }catch(\Throwable){$this->attention($record,in_array($record->fresh()->status,['submitting','received'],true)?'submitted_framing_unconfirmed_or_rejected':'framing_preflight_rejected');}
    }
    private function verifyRecord(IguideLabLifestyleFraming $record,IguideLabWorkflowStep $step): void
    {
        abort_unless($record->project_id===$step->project_id&&$record->request_id===$step->request_id&&$record->request_hash===$step->input_sha256&&$record->step_id===$step->id
            &&$step->child_id===$record->id&&$record->data['workflowInvocation']===app(LabWorkflowSteps::class)->invocation($step)
            &&LabAnalysisRecipes::hash($record->data['contextSnapshot'])===LabAnalysisRecipes::hash($step->input['contextSnapshot'])
            &&LabAnalysisRecipes::hash($record->data['recipe'])===LabAnalysisRecipes::hash($step->input['recipe']),422,'Framing record association changed.');
        $path='iguide-lab/'.$record->project_id.'/lifestyle-framings/'.$record->id.'/payload.json';abort_unless($record->data['payloadPath']===$path,422);
        app(LabLifestyleFramingContext::class)->file($record->project_id,$path,$record->data['providerBodySha256'],40*1024*1024);
    }
    private function parseReceipt(IguideLabLifestyleFraming $record,IguideLabWorkflowStep $step): array
    {
        $attempts=$record->provider_attempts??[];$index=count($attempts)-1;$attempt=$attempts[$index]??[];$receipt=$record->data['providerReceipt']??[];
        abort_unless(in_array(count($attempts),[1,2],true)&&($attempts[0]['credentialSource']??null)==='lab-primary'&&($attempt['status']??null)==='received'
            &&($attempt['requestedModelId']??null)==='gpt-6-astra'&&($attempt['providerRequestId']??null)===$record->provider_request_id&&is_string($record->provider_request_id)&&$record->provider_request_id!==''
            &&($attempt['httpStatus']??0)>=200&&($attempt['httpStatus']??0)<300
            &&($index===0||(($attempts[0]['status']??null)==='quota_rejected'&&($attempts[0]['httpStatus']??null)===429&&($attempt['credentialSource']??null)==='dashboard-fallback')),422,'Framing provider receipt is not confirmed.');
        $path='iguide-lab/'.$record->project_id.'/lifestyle-framings/'.$record->id.'/response-'.$index.'.json';abort_unless(($receipt['response']['path']??null)===$path
            &&($receipt['providerRequestId']??null)===$record->provider_request_id&&($receipt['payloadSha256']??null)===$record->data['providerBodySha256'],422);
        $file=app(LabLifestyleFramingContext::class)->file($record->project_id,$path,$receipt['response']['sha256']??'',128*1024);
        $raw=json_decode(file_get_contents($file),true,flags:JSON_THROW_ON_ERROR);
        abort_unless(($raw['id']??null)===($receipt['providerResponseId']??null)&&is_string($raw['id']??null)&&$raw['id']!==''&&($raw['model']??null)==='gpt-6-astra'&&$receipt['model']==='gpt-6-astra',422);
        return LabLifestyleFramingProtocol::parse($raw,$step->input['contextSnapshot']['context']);
    }
    public function completed(IguideLabWorkflowItem $item,string $id): IguideLabLifestyleFraming
    {
        $record=IguideLabLifestyleFraming::where('project_id',$item->project_id)->findOrFail($id);$step=IguideLabWorkflowStep::where('project_id',$item->project_id)->where('item_id',$item->id)->findOrFail($record->step_id);
        $this->verifyStep($step);$this->verifyRecord($record,$step);abort_unless($record->status==='completed',422);
        abort_unless(hash('sha256',$this->payload($step))===$record->data['providerBodySha256'],422);
        $result=$this->parseReceipt($record,$step);abort_unless(LabAnalysisRecipes::hash($result)===$record->result_sha256&&LabAnalysisRecipes::hash($record->result)===$record->result_sha256,422,'Framing result changed.');return $record;
    }
    public function attention(IguideLabLifestyleFraming $record,string $code): void
    {LockedWrite::run(fn()=>IguideLabLifestyleFraming::whereKey($record->id)->where('status','!=','completed')->update(['status'=>'needs_attention','error_code'=>$code,'completed_at'=>now(),'updated_at'=>now()]),'iguide-lab.framing-attention');}
    public function dto(IguideLabLifestyleFraming $record): array
    {return ['version'=>LabLifestyleFramingProtocol::VERSION,'id'=>$record->id,'status'=>$record->status,'sourceSha256'=>$record->data['contextSnapshot']['context']['sourceSha256'],
        'resultSha256'=>$record->result_sha256,'result'=>$record->result,'imageGenerationAuthorized'=>false,'errorCode'=>$record->error_code,'createdAt'=>$record->created_at->toIso8601String(),'completedAt'=>$record->completed_at?->toIso8601String()];}
}
