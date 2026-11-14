<?php
namespace Tests\Feature;

use App\Models\{IguideLabAnalysis,IguideLabCase,IguideLabImport,IguideLabProject,IguideLabSection,IguideLabWorkflowItem};
use App\Services\IguideLab\{LabAnalysisRecipes,LabDeclutterRemovalContext,LabDeclutterRemovalPolicy,LabService,LabStorage,LabWorkflows,LabWorkflowSteps};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http,Queue,Storage};
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Real source/import/section/analysis authority. Only diagnostic rendering is synthetic. */
class IguideLabDeclutterRemovalContextTest extends TestCase
{
    use RefreshDatabase;
    private IguideLabProject $project;
    private IguideLabCase $native;
    private IguideLabCase $cut;
    private IguideLabImport $import;
    private IguideLabSection $section;
    private IguideLabAnalysis $analysis;
    private IguideLabWorkflowItem $item;
    private LabDeclutterRemovalContext $context;

    protected function setUp(): void
    {
        parent::setUp(); Queue::fake(); Http::preventStrayRequests();
        config(['app.key'=>'base64:'.base64_encode(str_repeat('k',32))]);
        if(function_exists('posix_getegid'))config(['filesystems.disks.'.LabService::DISK.'.shared_group'=>posix_getgrgid(posix_getegid())['name']]);
        $disk=Storage::fake(LabService::DISK,config('filesystems.disks.'.LabService::DISK));$disk->makeDirectory('');
        $this->project=IguideLabProject::create(['name'=>'Removal context','token_hash'=>hash('sha256',Str::random(64)),'expires_at'=>now()->addDay(),'max_runs'=>20]);
        app(LabStorage::class)->prepareProject($this->project->id);
        $importId=(string)Str::uuid();$nativeId=(string)Str::uuid();
        $this->native=$this->image($nativeId,4096,2048,'imports/'.$importId.'/'.$nativeId.'.png');
        $this->native->update(['metadata'=>['role'=>'imported-panorama','importId'=>$importId]]);
        $this->import=IguideLabImport::create(['id'=>$importId,'project_id'=>$this->project->id,'request_id'=>(string)Str::uuid(),
            'request_hash'=>str_repeat('a',64),'source_kind'=>'zip','private_input'=>[],'status'=>'completed',
            'sources'=>[['caseId'=>$nativeId,'sourceSha256'=>$this->native->source_sha256,'width'=>4096,'height'=>2048,'importSourceKey'=>'one-source']]]);
        $cutId=(string)Str::uuid();$this->cut=$this->image($cutId,2048,2048,'cases/'.$cutId.'.png');
        $this->section=IguideLabSection::create(['project_id'=>$this->project->id,'case_id'=>$nativeId,'derived_case_id'=>$cutId,
            'request_id'=>(string)Str::uuid(),'request_hash'=>str_repeat('b',64),'source_sha256'=>$this->native->source_sha256,'status'=>'completed',
            'view'=>['yaw'=>0,'pitch'=>-25,'hFov'=>80]]);
        $this->cut->update(['metadata'=>['role'=>'section-source','parentCaseId'=>$nativeId,'sectionId'=>$this->section->id]]);
        $base='iguide-lab/'.$this->project->id.'/sections/'.$this->section->id.'/geometry/cut/';
        $disk->put($base.'views/section.png',$disk->get($this->cut->source_path));
        $geometry=['source'=>['path'=>$disk->path($this->native->source_path),'sha256'=>$this->native->source_sha256,'width'=>4096,'height'=>2048],
            'views'=>[['id'=>'section','path'=>$disk->path($base.'views/section.png'),'sha256'=>$this->cut->source_sha256,'width'=>2048,'height'=>2048,
                'projection'=>'rectilinear','yaw_degrees'=>0,'pitch_degrees'=>-25,'hfov_degrees'=>80]]];
        $json=json_encode($geometry,JSON_THROW_ON_ERROR);$disk->put($base.'manifest.json',$json);
        $this->section->update(['data'=>['cutSha256'=>$this->cut->source_sha256,'geometryPath'=>$base.'manifest.json','geometrySha256'=>hash('sha256',$json)]]);
        $workflow=app(LabWorkflows::class)->start($this->project,['requestId'=>(string)Str::uuid(),'items'=>[['caseId'=>$nativeId,'preset'=>'declutter']]]);
        $policy=$workflow->policy;$policy['declutterRemoval']=LabDeclutterRemovalPolicy::snapshot();$workflow->update(['policy'=>$policy]);
        $this->item=IguideLabWorkflowItem::where('workflow_id',$workflow->id)->sole();$steps=app(LabWorkflowSteps::class);
        $sectionStep=$steps->reserve($this->item,'section-1','section',['view'=>$this->section->view]);$steps->attach($sectionStep,$this->section->id);
        $analysisStep=$steps->reserve($this->item,'source-plan','analysis',['sourceSha256'=>$this->native->source_sha256]);
        $proposal=['summary'=>'Remove one compact decoration on a plain matte table.','targets'=>[['id'=>'decoration','action'=>'remove']],
            'protectedFeatures'=>[['id'=>'table','description'=>'Preserve the original table outline and all unrelated items.']], 'sourceArtifacts'=>[],'uncertainties'=>[]];
        $this->analysis=IguideLabAnalysis::create(['project_id'=>$this->project->id,'case_id'=>$nativeId,'request_id'=>$analysisStep->request_id,
            'request_hash'=>str_repeat('c',64),'source_sha256'=>$this->native->source_sha256,'status'=>'completed','settings'=>['preset'=>'declutter'],
            'proposal'=>$proposal,'proposal_sha256'=>LabAnalysisRecipes::hash($proposal),'data'=>['workflowInvocation'=>$steps->invocation($analysisStep)]]);
        $steps->attach($analysisStep,$this->analysis->id);
        $this->context=new class extends LabDeclutterRemovalContext {
            protected function diagnostics(string $requestPath): array
            {
                $r=json_decode(file_get_contents($requestPath),true);$out=$r['outputDirectory'];@mkdir($out.'/views',0770,true);$views=[];
                foreach(['front'=>[0,0],'right'=>[90,0],'back'=>[180,0],'left'=>[270,0],'zenith'=>[0,90],'nadir'=>[0,-90]] as $id=>[$yaw,$pitch]){
                    $bytes=(string)ImageManager::gd()->create(1024,1024)->fill('dddddd')->toPng();$path=$out.'/views/'.$id.'.png';file_put_contents($path,$bytes);
                    $views[]=['id'=>$id,'path'=>$path,'sha256'=>hash('sha256',$bytes),'width'=>1024,'height'=>1024,'yaw_degrees'=>$yaw,'pitch_degrees'=>$pitch,'hfov_degrees'=>100];
                }
                $m=['source'=>$r['source'],'views'=>$views,'adapterSha256'=>hash_file('sha256',resource_path('iguide-lab/analysis_views.py')),
                    'geometrySha256'=>hash_file('sha256',resource_path('iguide-lab/pano_geometry.py'))];
                file_put_contents($out.'/manifest.json',json_encode($m,JSON_THROW_ON_ERROR));return $m;
            }
        };
    }
    private function image(string $id,int $width,int $height,string $suffix): IguideLabCase
    {
        $bytes=(string)ImageManager::gd()->create($width,$height)->fill('888888')->toPng();$path='iguide-lab/'.$this->project->id.'/'.$suffix;
        Storage::disk(LabService::DISK)->put($path,$bytes);
        return IguideLabCase::create(['id'=>$id,'project_id'=>$this->project->id,'label'=>'Synthetic source','projection'=>$width===$height?'rectilinear':'equirectangular',
            'width'=>$width,'height'=>$height,'source_path'=>$path,'source_sha256'=>hash('sha256',$bytes),'source_mime'=>'image/png','metadata'=>[]]);
    }
    private function capture(): array{return $this->context->capture($this->item->fresh(),$this->section->id,$this->analysis->id);}
    private function rejected(): void
    {
        try{$this->capture();$this->fail('Changed machine source authority accepted.');}
        catch(HttpException $e){$this->assertContains($e->getStatusCode(),[404,422]);}
    }
    public function test_material_context_uses_reserved_version_and_requires_its_exact_source_intent(): void
    {
        config(['iguide-lab.declutter_materials_enabled'=>true]);
        $this->assertSame(LabDeclutterRemovalPolicy::VERSION,$this->capture()['context']['schemaVersion']);
        $workflow=\App\Models\IguideLabWorkflow::findOrFail($this->item->workflow_id);$policy=$workflow->policy;
        $policy['declutterRemoval']=LabDeclutterRemovalPolicy::snapshot(true);$workflow->update(['policy'=>$policy]);
        $this->rejected();
        $step=\App\Models\IguideLabWorkflowStep::where('child_id',$this->analysis->id)->sole();$input=$step->input;
        $input['instructions']=\App\Services\IguideLab\LabDeclutterWorkflow::MATERIALS_INTENT;
        $step->update(['input'=>$input,'input_sha256'=>LabAnalysisRecipes::hash($input)]);
        $data=$this->analysis->data;$data['workflowInvocation']=app(LabWorkflowSteps::class)->invocation($step->fresh());$this->analysis->update(['data'=>$data]);
        $context=$this->capture();$this->assertSame(LabDeclutterRemovalPolicy::MATERIALS_VERSION,$context['context']['schemaVersion']);
        $this->assertSame($this->cut->source_sha256,$context['context']['binding']['sourceCutSha256']);
        $input['instructions']=\App\Services\IguideLab\LabDeclutterWorkflow::INTENT;$step->update(['input'=>$input,'input_sha256'=>LabAnalysisRecipes::hash($input)]);
        $this->rejected();Http::assertNothingSent();
    }
    public function test_actual_imported_native_and_source_plan_bind_six_inputs_without_forged_attachment(): void
    {
        $a=$this->capture();$b=$this->capture();$this->assertSame(LabAnalysisRecipes::hash($a),LabAnalysisRecipes::hash($b));
        $this->assertSame('completed_scan_import',$a['sourceContextSnapshot']['binding']['sourceOrigin']);
        $this->assertNull($this->native->native_source);$this->assertSame($this->native->source_sha256,$a['context']['binding']['nativeSourceSha256']);
        $this->assertSame($this->cut->source_sha256,$a['context']['binding']['sourceCutSha256']);
        $this->assertSame(['source_cut','parent_overview','parent_front','parent_right','parent_back','parent_left'],array_column($a['assets'],'id'));
        $this->assertSame($this->analysis->proposal,$a['context']['sourcePlan']);Http::assertNothingSent();
    }
    public function test_import_mapping_cut_bytes_and_pose_drift_are_rejected(): void
    {
        $mapping=$this->import->sources;$changed=$mapping;$changed[0]['sourceSha256']=str_repeat('e',64);$this->import->update(['sources'=>$changed]);$this->rejected();$this->import->update(['sources'=>$mapping]);
        $view=$this->section->view;$this->section->update(['view'=>array_replace($view,['yaw'=>0.01])]);$this->rejected();$this->section->update(['view'=>$view]);
        $disk=Storage::disk(LabService::DISK);$bytes=$disk->get($this->cut->source_path);$disk->put($this->cut->source_path,$bytes.'drift');$this->rejected();$disk->put($this->cut->source_path,$bytes);
        Http::assertNothingSent();
    }
    public function test_changed_parent_plan_or_wrong_semantic_preset_cannot_authorize_removal(): void
    {
        $p=$this->analysis->proposal;$changed=$p;$changed['uncertainties']=['Unresolved mirror dependency.'];$this->analysis->update(['proposal'=>$changed]);$this->rejected();$this->analysis->update(['proposal'=>$p]);
        $this->analysis->update(['settings'=>['preset'=>'staging']]);$this->rejected();$this->analysis->update(['settings'=>['preset'=>'declutter']]);
        $this->item->update(['instructions'=>'Remove all furniture']);$this->rejected();Http::assertNothingSent();
    }
    public function test_complete_parent_caveats_are_retained_or_held_never_truncated(): void
    {
        $p=$this->analysis->proposal;$p['uncertainties']=['A distant reflective surface remains uncertain.'];
        $this->analysis->update(['proposal'=>$p,'proposal_sha256'=>LabAnalysisRecipes::hash($p)]);
        $this->assertSame($p['uncertainties'],$this->capture()['context']['sourcePlan']['uncertainties']);
        $p['uncertainties']=[str_repeat('x',33000)];$this->analysis->update(['proposal'=>$p,'proposal_sha256'=>LabAnalysisRecipes::hash($p)]);$this->rejected();Http::assertNothingSent();
    }
}
