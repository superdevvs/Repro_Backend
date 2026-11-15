<?php
namespace Tests\Feature;
use App\Models\{IguideLabProject,IguideLabWorkflowItem,IguideLabWorkflowStep,IguideLabInteriorAssessment,IguideLabRun};
use App\Services\IguideLab\{LabService,LabInteriorDesignContext,LabInteriorDesignAssessments,LabInteriorDesignPolicy,LabInteriorMaterialSourceProtocol as P,LabInteriorDesignProtocol as V,LabInteriorDesignMasks,LabWorkflowSteps,LabWorkflows,LabAnalysisRecipes,LabMachineSegmentationPlan as H};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Http,Queue,Storage};
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Unit\IguideLabInteriorMaterialSourceProtocolTest as F;

/** Real persisted reservations/provider claims/receipts; source projection is a separate Python proof. */
class IguideLabInteriorMaterialFollowupTest extends TestCase
{
    use RefreshDatabase;
    private array $temporary=[];
    private MaterialFollowupContextFixture $context;
    protected function setUp(): void
    {
        parent::setUp();Queue::fake();Http::preventStrayRequests();$key=tempnam(sys_get_temp_dir(),'material-key');file_put_contents($key,'fixture-primary');$this->temporary[]=$key;
        config(['app.key'=>'base64:'.base64_encode(str_repeat('k',32)),'services.openai.api_key'=>'fixture-fallback','iguide-lab.openai_primary_key_path'=>$key,'iguide-lab.interior_material_source_details_enabled'=>true]);
        if(function_exists('posix_getegid'))config(['filesystems.disks.'.LabService::DISK.'.shared_group'=>posix_getgrgid(posix_getegid())['name']]);
        Storage::fake(LabService::DISK,config('filesystems.disks.'.LabService::DISK))->makeDirectory('');
        $this->context=new MaterialFollowupContextFixture;$this->app->instance(LabInteriorDesignContext::class,$this->context);
        $manifest=tempnam(sys_get_temp_dir(),'material-runtime');$this->temporary[]=$manifest;
        file_put_contents($manifest,json_encode(['version'=>LabInteriorDesignPolicy::VERSION,'validationStatus'=>'passed','implementation'=>app(LabInteriorDesignAssessments::class)->implementation(),
            'pythonPath'=>(string)config('iguide-lab.pipeline_python_path'),'pythonVersion'=>'3.12.3','numpyVersion'=>'2.5.4','pillowVersion'=>'13.0.0']));
        config(['iguide-lab.interior_design_runtime_manifest'=>$manifest]);
    }
    protected function tearDown(): void {foreach($this->temporary as $path)if(is_file($path))unlink($path);parent::tearDown();}
    private function fixture(): array
    {
        $project=IguideLabProject::create(['name'=>'Material followup','token_hash'=>hash('sha256',Str::random(64)),'expires_at'=>now()->addDay(),'max_runs'=>20]);
        [$c,$images]=F::fixture();$bytes=base64_decode(explode(',',$images[1],2)[1]);
        $case=app(LabService::class)->addCase($project,UploadedFile::fake()->createWithContent('native.png',$bytes),['label'=>'Original','projection'=>'equirectangular']);
        $workflow=app(LabWorkflows::class)->start($project,['requestId'=>(string)Str::uuid(),'items'=>[['caseId'=>$case->id,'preset'=>'interior-design','style'=>'modern']]]);
        $policy=$workflow->policy;$policy['interiorDesign']=LabInteriorDesignPolicy::snapshot();$policy['interiorAssessments']=app(LabInteriorDesignAssessments::class)->snapshot();$workflow->update(['policy'=>$policy]);
        config(['iguide-lab.interior_design_workflow_enabled'=>true]);$item=IguideLabWorkflowItem::where('workflow_id',$workflow->id)->sole();
        $assets=[];foreach($images as $i=>$image){$path='iguide-lab/'.$project->id.'/workflow-items/'.$item->id.'/fixture-'.$i.'.png';Storage::disk(LabService::DISK)->put($path,base64_decode(explode(',',$image,2)[1]));$assets[]=['id'=>$c['sourceContext']['views'][$i]['id'],'path'=>$path,'sha256'=>$c['sourceContext']['views'][$i]['sha256']];}
        $this->context->bundle=['context'=>$c,'assets'=>$assets,'implementation'=>$this->context->snapshot()];
        $service=app(LabInteriorDesignAssessments::class);$step=$service->reserve($item,'cut');$record=$service->start($step);
        return [$project,$workflow,$item,$case,$record];
    }
    private function finish(IguideLabInteriorAssessment $record,array $proposal): IguideLabInteriorAssessment
    {
        Http::swap(new \Illuminate\Http\Client\Factory);Http::preventStrayRequests();Http::fake(['api.openai.com/v1/responses'=>Http::response(V::response($proposal)+['id'=>'resp-material'],200,['x-request-id'=>'req-material'])]);
        $service=app(LabInteriorDesignAssessments::class);$service->process($record);$service->process($record->fresh());return $record->fresh();
    }
    private function followup($item,$initial): IguideLabInteriorAssessment
    {return app(LabInteriorDesignAssessments::class)->start(app(LabInteriorDesignAssessments::class)->reserve($item,'cut-followup',['initialCutId'=>$initial->id]));}
    public function test_fresh_followup_plan_enters_unchanged_source_mask_reservation_and_replay_spends_zero(): void
    {
        [$project,,$item,,$record]=$this->fixture();$initial=$this->finish($record,F::proposal($record->data['contextSnapshot']['context'],true));
        $this->assertSame('completed',$initial->status);$this->assertFalse($initial->result['sourceMaskSelectionAuthorized']);
        $next=$this->followup($item,$initial);$next=$this->finish($next,F::proposal($next->data['contextSnapshot']['context']));$this->assertSame('completed',$next->status);
        $service=app(LabInteriorDesignAssessments::class);$verified=$service->completed($item,$next->id,'cut');$this->assertSame('cut-followup',$verified->phase);
        $masks=app(LabInteriorDesignMasks::class)->reserve($item,$verified);$this->assertCount(2,$masks);
        foreach($masks as $step){$owned=app(LabInteriorDesignMasks::class)->owned($step);$this->assertSame($next->id,$owned['record']->id);$this->assertSame($next->result_sha256,$owned['plan']['assessmentProposalSha256']);}
        Http::swap(new \Illuminate\Http\Client\Factory);Http::preventStrayRequests();Http::fake(function(){$this->fail('Terminal replay sent HTTP.');});$service->process($next);$this->assertSame($next->id,$this->followup($item,$initial)->id);$service->completed($item,$next->id,'cut');Http::assertNothingSent();
        $this->assertSame(0,IguideLabRun::count());$this->assertSame(0,$project->fresh()->used_runs);$this->assertSame(P::VERSION,$service->dto($next)['version']);
    }
    public function test_remaining_uncertainty_has_no_source_masks_and_cannot_request_a_second_followup(): void
    {
        [,,$item,,$r]=$this->fixture();$initial=$this->finish($r,F::proposal($r->data['contextSnapshot']['context'],true));$next=$this->followup($item,$initial);
        $p=F::proposal($next->data['contextSnapshot']['context']);$p['status']='needs_review';$p['targets']=[];$p['foreground']=[];$p['generationPrompt']=null;$p['checks']['optical_dependencies']['status']='uncertain';
        $next=$this->finish($next,$p);$this->assertSame('completed',$next->status);$this->assertFalse($next->result['sourceMaskSelectionAuthorized']);
        $this->rejects(fn()=>app(LabInteriorDesignMasks::class)->reserve($item,$next));$this->rejects(fn()=>$this->followup($item,$next));
        $this->assertSame(0,IguideLabWorkflowStep::where('kind','segmentation')->count());
    }
    public function test_definite_source_failure_is_valid_hold_without_detail_or_mask_authority(): void
    {
        [,,$item,,$r]=$this->fixture();$p=F::proposal($r->data['contextSnapshot']['context']);$p['status']='needs_review';$p['targets']=[];$p['generationPrompt']=null;$p['checks']['optical_dependencies']['status']='fail';
        $r=$this->finish($r,$p);$this->assertSame('completed',$r->status);$this->rejects(fn()=>$this->followup($item,$r));
        $this->assertSame(1,IguideLabInteriorAssessment::count());$this->assertSame(0,IguideLabWorkflowStep::where('kind','segmentation')->count());
    }
    public function test_cancellation_or_parent_receipt_drift_after_explicit_quota_stops_fallback(): void
    {
        foreach(['cancel','receipt','detail','disabled'] as $mode){
            [$project,$workflow,$item,,$r]=$this->fixture();$initial=$this->finish($r,F::proposal($r->data['contextSnapshot']['context'],true));$next=$this->followup($item,$initial);$calls=0;
            Http::swap(new \Illuminate\Http\Client\Factory);Http::preventStrayRequests();Http::fake(function()use($mode,$workflow,$initial,&$calls){$calls++;
                if($mode==='cancel')app(LabWorkflows::class)->cancel($workflow);
                elseif($mode==='receipt'){Storage::disk(LabService::DISK)->put($initial->data['providerReceipt']['response']['path'],'changed');}
                elseif($mode==='detail')$this->context->drift=true;
                else config(['iguide-lab.interior_material_source_details_enabled'=>false]);
                return Http::response(['error'=>['code'=>'insufficient_quota']],429);
            });
            app(LabInteriorDesignAssessments::class)->process($next);$this->assertSame(1,$calls,$mode);$this->assertSame('needs_attention',$next->fresh()->status,$mode);
            $this->context->drift=false;config(['iguide-lab.interior_design_workflow_enabled'=>false,'iguide-lab.interior_material_source_details_enabled'=>true]);
        }
    }
    private function rejects(callable $f): void {try{$f();$this->fail('Held or repeated authority accepted.');}catch(\InvalidArgumentException|HttpException $e){if($e instanceof HttpException)$this->assertContains($e->getStatusCode(),[409,422]);else $this->assertNotEmpty($e->getMessage());}}
}
class MaterialFollowupContextFixture extends LabInteriorDesignContext
{
    public array $bundle=[];public bool $drift=false;
    public function capture(IguideLabWorkflowItem $item,string $phase,array $parents): array
    {
        if($phase==='cut')return $this->bundle;
        if($phase!=='cut-followup')abort(422);
        V::keys($parents,['initialCutId']);$parent=app(LabInteriorDesignAssessments::class)->completed($item,$parents['initialCutId'],'cut');
        abort_unless($parent->phase==='cut'&&$parent->result['status']==='detail_required',422,'Only one initial v2 detail request is eligible.');
        [$c]=F::followup($this->bundle['context'],['fixture']);$p=&$c['sourceInspection']['parent'];
        $p['id']=$parent->id;$p['requestId']=$parent->request_id;$p['result']=$parent->result;$p['resultSha256']=$parent->result_sha256;$p['responseSha256']=$parent->data['providerReceipt']['response']['sha256'];
        if($this->drift)$c['sourceInspection']['manifestSha256']=str_repeat('0',64);
        $bundle=$this->bundle;$bundle['context']=$c;$a=$bundle['assets'][0];$a['id']='source_detail_1';$bundle['assets'][]=$a;return $bundle;
    }
}
