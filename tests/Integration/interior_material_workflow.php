<?php
/** Private disposable database; fake HTTP only; actual pinned Linux image workers. */
declare(strict_types=1);
$root = $argv[1] ?? '';
if (!str_starts_with($root, '/') || is_dir($root)) throw new RuntimeException('Use a new absolute private fixture directory.');
$code = dirname(__DIR__, 2);
$scenario = $argv[2] ?? 'success';
if (!in_array($scenario, ['success', 'source-hold', 'cut-hold', 'source-mask-hole', 'source-receipt-drift', 'source-view-drift', 'source-manifest-drift', 'source-prepared-drift', 'candidate-hold', 'palette-only'], true)) throw new RuntimeException('Unsupported test scenario.');
if (!is_dir($root)) mkdir($root,02770);
chmod($root,02770);
$db = $root.'/test.sqlite';
if (is_file($db)) throw new RuntimeException('This immutable test directory already ran.');
foreach (['storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache'] as $dir) {
    if (! is_dir($code.'/'.$dir)) mkdir($code.'/'.$dir, 02770, true);
}
touch($db); chmod($db, 0660);
foreach (['APP_ENV'=>'testing','APP_KEY'=>'base64:'.base64_encode(str_repeat('k',32)), 'DB_CONNECTION'=>'sqlite','DB_DATABASE'=>$db,
    'APP_PACKAGES_CACHE'=>$root.'/packages.php','APP_SERVICES_CACHE'=>$root.'/services.php','APP_CONFIG_CACHE'=>$root.'/config.php','APP_ROUTES_CACHE'=>$root.'/routes.php','APP_EVENTS_CACHE'=>$root.'/events.php','CACHE_STORE'=>'array','QUEUE_CONNECTION'=>'sync','SESSION_DRIVER'=>'array','MAIL_MAILER'=>'array','LOG_CHANNEL'=>'single','CLAMAV_SCAN_ON_UPLOAD'=>'false'] as $k=>$v) {
    putenv($k.'='.$v); $_ENV[$k]=$v; $_SERVER[$k]=$v;
}
$loader = require (getenv('IGUIDE_TEST_AUTOLOAD') ?: $code.'/vendor/autoload.php');
$classes = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($code.'/app', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension()==='php') $classes['App\\'.str_replace('/', '\\', substr($file->getPathname(), strlen($code.'/app/'), -4))]=$file->getPathname();
}
$loader->addClassMap($classes);
$app = require $code.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Services\IguideLab\LabService;
use App\Services\IguideLab\LabImports;
use App\Services\IguideLab\LabWorkflows;
use App\Services\IguideLab\LabWorkflowArtifacts;
use App\Services\IguideLab\LabWorkflowSources;
use App\Services\IguideLab\LabAnalyses;
use App\Services\IguideLab\LabWorkflowEvaluations;
use App\Services\IguideLab\LabVisualQualityProtocol;
use App\Models\IguideLabProject;
use App\Models\IguideLabCase;
use App\Models\IguideLabAnalysis;
use App\Models\IguideLabRun;
use App\Models\IguideLabWorkflowEvaluation;
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
check(DB::connection()->getDatabaseName()===$db && str_starts_with(realpath($db), $root.'/'), 'Private database guard failed.');
Http::preventStrayRequests(); Queue::fake();
foreach (glob($code.'/database/migrations/*iguide_lab*.php') as $migration) (require $migration)->up();
(require $code.'/database/migrations/2026_10_10_050000_create_ai_provider_usage_table.php')->up();
$diskName = LabService::DISK; $cfg=config('filesystems.disks.'.$diskName); $cfg['root']=$root.'/private';
$cfg['shared_group']=posix_getgrgid(posix_getegid())['name']; mkdir($cfg['root'],02770); chmod($cfg['root'],02770);
config(['filesystems.disks.'.$diskName=>$cfg]); Storage::forgetDisk($diskName);
$key=$root.'/fake-primary.key'; file_put_contents($key,'fixture-not-a-real-credential'); chmod($key,0600);
config(['iguide-lab.openai_primary_key_path'=>$key,'services.openai.api_key'=>'fixture-no-fallback-needed']);
$python=(string)getenv('IGUIDE_TEST_PYTHON'); check(is_executable($python), 'Set IGUIDE_TEST_PYTHON to the pinned Python runtime.');
$runtime=['validationStatus'=>'passed','pythonPath'=>$python,'pythonVersion'=>'3.12.3','numpyVersion'=>'2.5.4','pillowVersion'=>'13.0.0',
    'validatedPreparerSha256'=>hash_file('sha256',(new ReflectionClass(App\Services\IguideLab\LabImages::class))->getFileName()),
    'fixtureOnly'=>true, 'evidence'=>['This manifest binds the exact code for this disposable fake-provider test only.']];
foreach (['validatedWorkerSha256'=>'workflow_qa.py','validatedLightingSha256'=>'lighting_transfer.py','validatedGlobalLightingSha256'=>'global_lighting.py','validatedGeometrySha256'=>'pano_geometry.py','validatedSourceAdapterSha256'=>'workflow_source.py'] as $k=>$f) $runtime[$k]=hash_file('sha256',resource_path('iguide-lab/'.$f));
$manifest=$root.'/runtime-manifest.json'; file_put_contents($manifest,json_encode($runtime,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)); chmod($manifest,0660);
config(['iguide-lab.workflow_runtime_manifest'=>$manifest,'iguide-lab.pipeline_python_path'=>$python]);

use App\Services\IguideLab\{LabAnalysisRecipes,LabInteriorDesignWorkflow,LabInteriorDesignPolicy,LabInteriorDesignAssessments,LabInteriorDesignFramingProtocol as F,LabInteriorDesignCutProtocol as C,LabInteriorDesignCandidateProtocol as D,LabInteriorDesignMaterials,LabImages,LabSections,LabMachineSegmentations,LabMachineSegmentationTransport};
use App\Models\{IguideLabInteriorAssessment,IguideLabWorkflowItem,IguideLabSection,IguideLabMachineSegmentation};
$materialPython=(string)getenv('IGUIDE_TEST_MATERIAL_PYTHON');check(is_executable($materialPython),'Pinned material Python required.');
config(['iguide-lab.interior_design_workflow_enabled'=>true,'iguide-lab.interior_material_python_path'=>$materialPython,'services.fal.key'=>'fixture-not-real']);
$assessmentRuntime=['version'=>LabInteriorDesignPolicy::VERSION,'validationStatus'=>'passed','implementation'=>app(LabInteriorDesignAssessments::class)->implementation(),
    'pythonPath'=>$python,'pythonVersion'=>'3.12.3','numpyVersion'=>'2.5.4','pillowVersion'=>'13.0.0'];
file_put_contents($root.'/assessment-runtime.json',json_encode($assessmentRuntime,JSON_THROW_ON_ERROR));chmod($root.'/assessment-runtime.json',0660);config(['iguide-lab.interior_design_runtime_manifest'=>$root.'/assessment-runtime.json']);
$materialRuntime=['version'=>'interior-material-runtime/1','validationStatus'=>'passed','python'=>'3.12.3','numpy'=>'2.5.4','pillow'=>'13.0.0','opencv'=>'5.0.0','files'=>app(LabInteriorDesignMaterials::class)->snapshot()['files']];
file_put_contents($root.'/material-runtime.json',json_encode($materialRuntime,JSON_THROW_ON_ERROR));chmod($root.'/material-runtime.json',0660);config(['iguide-lab.interior_material_runtime_manifest'=>$root.'/material-runtime.json']);
app()->instance(LabMachineSegmentationTransport::class,new class extends LabMachineSegmentationTransport {protected function addresses(string $host):array{return ['1.1.1.1'];}});
check(app(LabInteriorDesignWorkflow::class)->ready(),'Interior fixture runtime unavailable.');
function fixtureProcess(array $args):void{$p=new Symfony\Component\Process\Process($args,null,['OMP_NUM_THREADS'=>'1','OPENBLAS_NUM_THREADS'=>'1'],null,100);$p->mustRun();}
fixtureProcess([$materialPython,$code.'/tests/Integration/interior_material_fixture.py','native',$root.'/native.png']);
$project=IguideLabProject::create(['name'=>'Synthetic registered material mechanics','token_hash'=>hash('sha256',Str::random(64)),'expires_at'=>now()->addDay(),'max_runs'=>20,'max_cases'=>160]);
$zipPath=$root.'/source.zip';$zip=new ZipArchive;$zip->open($zipPath,ZipArchive::CREATE|ZipArchive::EXCL);$zip->addFile($root.'/native.png','original.png');$zip->close();
$imports=app(LabImports::class);$import=$imports->start($project,['requestId'=>(string)Str::uuid()],new Illuminate\Http\UploadedFile($zipPath,'source.zip','application/zip',null,true),null);
$imports->process($import);$import->refresh();check($import->status==='completed','Synthetic import failed');$case=IguideLabCase::findOrFail($import->sources[0]['caseId']);
check(app(LabWorkflowSources::class)->capture($case)['authority']['importId']===$import->id,'Native import authority missing.');
$boxes=[[300,400,849,1099],[1150,600,1699,1299]];
function maskBytes(array $box,bool $hole=false):string{$m=imagecreatetruecolor(2048,2048);imagefilledrectangle($m,...[...$box,0xffffff]);if($hole)imagefilledrectangle($m,$box[0]+80,$box[1]+80,$box[0]+150,$box[1]+150,0);ob_start();imagepng($m);$b=ob_get_clean();imagedestroy($m);return $b;}
$calls=[];$samMasks=[];
Http::fake(function($request)use(&$calls,&$samMasks,$boxes,$root,$scenario,$materialPython,$code){
    $url=$request->url();$calls[]=['url'=>$url,'method'=>$request->method()];
    if($url==='https://api.openai.com/v1/images/edits'){
        $run=IguideLabRun::where('status','submitting')->sole();$case=IguideLabCase::findOrFail($run->case_id);$source=$root.'/prepared.png';
        file_put_contents($source,LabImages::prepared(Storage::disk(LabService::DISK)->get($case->source_path)));
        $args=[$materialPython,$code.'/tests/Integration/interior_material_fixture.py','donor',$root.'/donor.png','--source',$source];if($scenario==='palette-only')$args[]='--palette-only';fixtureProcess($args);
        return Http::response(['data'=>[['b64_json'=>base64_encode(file_get_contents($root.'/donor.png'))]],'usage'=>['input_tokens'=>10,'output_tokens'=>10]],200,['x-request-id'=>'fake-material-image']);
    }
    if($url==='https://queue.fal.run/fal-ai/sam-3-1/image'){
        $record=IguideLabMachineSegmentation::where('status','submitting')->sole();$plan=$record->data['plan'];$bytes=base64_decode(explode(',',$request['image_url'],2)[1],true);
        check(hash('sha256',$bytes)===$plan['inputSha256'],'SAM input association changed');$id='fake-'.$record->id;$box=$plan['payload']['box_prompts'][0];
        $samMasks[$id]=maskBytes([$box['x_min'],$box['y_min'],$box['x_max'],$box['y_max']],$scenario==='source-mask-hole'&&$plan['maskRole']==='source-material');
        return Http::response(['request_id'=>$id],200);
    }
    if(str_starts_with($url,'https://queue.fal.run/')){
        if(str_ends_with($url,'/status'))return Http::response(['status'=>'COMPLETED'],200);
        preg_match('~/requests/(fake-[^/]+)/~',$url,$match);check(isset($samMasks[$match[1]??'']),'Missing retained fake SAM');return Http::response(['masks'=>[['url'=>'https://fixture.fal.media/'.$match[1].'.png']]],200);
    }
    if(str_starts_with($url,'https://fixture.fal.media/'))return Http::response($samMasks[pathinfo($url,PATHINFO_FILENAME)],200,['Content-Type'=>'image/png']);
    check($url==='https://api.openai.com/v1/responses','Unexpected endpoint');$body=json_decode($request->body(),true);$c=json_decode($body['input'][0]['content'][0]['text'],true);
    if(($c['version']??null)===F::VERSION){
        $v=array_intersect_key($c,array_flip(['version','nativeSourceSha256','preparedSourceSha256','sourceAuthoritySha256','intentSha256','style']))+[
            'status'=>'framing_candidate','view'=>['yaw'=>0.0,'pitch'=>-15.0,'hFov'=>90.0],
            'targets'=>array_map(fn($i)=>['id'=>'material_'.($i+1),'sourceDescription'=>'Synthetic original opaque material region '.($i+1),'surface'=>$i===0?'upholstery':'rug',
                'sourceOptics'=>'opaque_matte','plannedChange'=>'Apply visible woven material or pattern on the unchanged original surface.','changeKinds'=>['texture','pattern'],'evidenceViewIds'=>['source','front']],[0,1]),
            'styleFeatures'=>[['kind'=>'texture','description'=>'Synthetic material feature, not real style evidence.','targetIds'=>['material_1']],['kind'=>'pattern','description'=>'Synthetic pattern feature, not real style evidence.','targetIds'=>['material_2']]],
            'checks'=>array_fill_keys(F::CHECKS,['status'=>'verify_on_cut','evidenceViewIds'=>F::IDS,'reason'=>'Synthetic candidacy only.']),'summary'=>'Fake framing; no real room/style assertion.'];
        if($scenario==='source-hold'){$v['status']='needs_review';$v['view']=null;$v['checks']['optical_dependencies']['status']='blocked';}
    }elseif(isset($c['interiorFraming'])){
        $targets=[];foreach($c['interiorFraming']['result']['targets'] as $i=>$t){unset($t['sourceOptics'],$t['evidenceViewIds']);$targets[]=$t+['registrationSurface'=>'planar','maskPlan'=>['prompt'=>'synthetic region '.($i+1),'box'=>$boxes[$i]]];}
        $v=['version'=>C::VERSION,'sourceCutSha256'=>$c['sourceSha256'],'preparedSourceSha256'=>$c['sourceContext']['views'][0]['sha256'],'nativeSourceSha256'=>$c['sourceContext']['binding']['nativeSourceSha256'],
            'sourceContextBindingSha256'=>$c['sourceContext']['bindingSha256'],'framingResultSha256'=>$c['interiorFraming']['resultSha256'],'style'=>$c['style'],'status'=>'ready_for_source_masks','targets'=>$targets,'foreground'=>[],
            'checks'=>array_fill_keys(C::CHECKS,['status'=>'pass','evidenceViewIds'=>App\Services\IguideLab\LabAnalysisSourceContext::IDS,'reason'=>'Fake cut scope for algorithm fixture.']),
            'generationPrompt'=>'Restyle the two existing synthetic surfaces with visible woven and patterned materials, retaining forms and all other original pixels.','summary'=>'Synthetic cut mechanics only.'];
        if($scenario==='cut-hold'){$v['status']='needs_review';$v['targets']=[];$v['generationPrompt']=null;$v['checks']['optical_dependencies']['status']='uncertain';}
    }elseif(($c['version']??null)===D::VERSION){
        $v=['version'=>D::VERSION,'sourceSha256'=>$c['cutResult']['preparedSourceSha256'],'candidateSha256'=>$c['candidateSha256'],'nativeSourceSha256'=>$c['cutResult']['nativeSourceSha256'],
            'cutResultSha256'=>$c['cutResultSha256'],'style'=>$c['cutResult']['style'],'status'=>'ready_for_candidate_masks','foregroundFinding'=>'none_observed',
            'targets'=>array_map(fn($t)=>array_intersect_key($t,array_flip(['id','sourceDescription','registrationSurface','maskPlan'])),$c['cutResult']['targets']),
            'checks'=>array_fill_keys(D::CHECKS,['status'=>'pass','evidenceViewIds'=>D::IDS,'reason'=>'Fake donor gate; the real registration/transfer still runs.']),'summary'=>'Synthetic candidate only.' ];
        if($scenario==='candidate-hold'){$v['status']='needs_review';$v['targets']=[];$v['checks']['style_faithfulness']['status']='uncertain';}
    }else{
        check(isset($c['candidateSha256'],$c['intentSha256']),'Unknown analysis context');$checks=[];foreach(LabVisualQualityProtocol::CHECKS as $name)$checks[$name]=[
            'status'=>'pass','evidenceViewIds'=>[match($name){'seam'=>'wrap','poles'=>'poles',default=>'detail_1'}],'reason'=>'Fake numerical integration checker. This is not real style or material quality evidence.'];
        $v=array_intersect_key($c,array_flip(['sourceSha256','candidateSha256','intentSha256','evidenceManifestSha256']))+['protocolVersion'=>LabVisualQualityProtocol::VERSION,'checks'=>$checks,'unresolvedDependencies'=>[],'recommendedAction'=>'none','summary'=>'Fake checker only.'];
    }
    return Http::response(['id'=>'resp_fake_'.count($calls),'model'=>'gpt-6-astra','status'=>'completed','usage'=>['input_tokens'=>10,'output_tokens'=>10],
        'output'=>[['type'=>'message','role'=>'assistant','status'=>'completed','content'=>[['type'=>'output_text','text'=>json_encode($v,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION)]]]]],200,['x-request-id'=>'fake-analysis-'.count($calls)]);
});
app()->instance(LabInteriorDesignWorkflow::class,new class($root) extends LabInteriorDesignWorkflow{
    public function __construct(private string $root){}
    public function advance(\App\Models\IguideLabWorkflow $w,IguideLabWorkflowItem $i,IguideLabProject $p):void{
        try{parent::advance($w,$i,$p);}catch(Throwable $e){file_put_contents($this->root.'/diagnostic.json',json_encode(['class'=>get_class($e),'message'=>$e->getMessage(),'trace'=>$e->getTraceAsString()],JSON_PRETTY_PRINT));throw $e;}}
});
$service=app(LabWorkflows::class);$workflow=$service->start($project,['requestId'=>(string)Str::uuid(),'items'=>[['caseId'=>$case->id,'preset'=>'interior-design','style'=>'modern']]]);$history=[];$drift=false;
for($tick=0;$tick<40;$tick++){
    $phaseStart=microtime(true);$service->process($workflow->fresh());$item=IguideLabWorkflowItem::where('workflow_id',$workflow->id)->sole();$history[]=['tick'=>$tick,'status'=>$workflow->fresh()->status,'item'=>$item->toArray(),'fakeCalls'=>count($calls),'workflowPhaseSeconds'=>round(microtime(true)-$phaseStart,3)];
    file_put_contents($root.'/history.json',json_encode($history,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));if(in_array($workflow->fresh()->status,LabWorkflows::TERMINAL,true))break;
    foreach(IguideLabInteriorAssessment::whereIn('status',['queued','received'])->get() as $r)app(LabInteriorDesignAssessments::class)->process($r);
    if(in_array($scenario,['source-view-drift','source-manifest-drift','source-prepared-drift'],true)&&!$drift&&($r=IguideLabInteriorAssessment::where('phase','framing')->where('status','completed')->first())){
        $bundle=$r->data['contextSnapshot'];$disk=Storage::disk(LabService::DISK);
        if($scenario==='source-view-drift'){$path=$bundle['assets'][1]['path'];$disk->put($path,$disk->get($path).'changed');}
        elseif($scenario==='source-prepared-drift'){$path=$bundle['assets'][0]['path'];$disk->put($path,$disk->get($path).'changed');}
        else{$path=dirname($bundle['assets'][0]['path']).'/views/manifest.json';$v=json_decode($disk->get($path),true);$v['views'][0]['yaw_degrees']=1;$disk->put($path,json_encode($v));}
        $drift=true;
    }
    if($scenario==='source-receipt-drift'&&!$drift&&($r=IguideLabInteriorAssessment::where('phase','cut')->where('status','completed')->first())){Storage::disk(LabService::DISK)->delete($r->data['providerReceipt']['response']['path']);$drift=true;}
    foreach(IguideLabSection::where('status','queued')->get() as $r)app(LabSections::class)->process($r);
    foreach(IguideLabMachineSegmentation::whereIn('status',['queued','processing'])->get() as $r)app(LabMachineSegmentations::class)->process($r);
    foreach(IguideLabRun::where('status','queued')->get() as $r)app(LabService::class)->process($r);
    foreach(IguideLabWorkflowEvaluation::where('status','queued')->get() as $r)app(LabWorkflowEvaluations::class)->process($r);
}
$workflow->refresh();$item->refresh();$expected=$scenario==='success'?'ready':'needs_review';
$before=count($calls);$service->process($workflow->fresh());check(count($calls)===$before,'Terminal replay repeated HTTP');
$posts=count(array_filter($calls,fn($c)=>$c['method']==='POST'));$expectedPosts=['success'=>9,'source-hold'=>1,'cut-hold'=>2,'source-mask-hole'=>4,'source-receipt-drift'=>2,'source-view-drift'=>1,'source-manifest-drift'=>1,'source-prepared-drift'=>1,'candidate-hold'=>6,'palette-only'=>8][$scenario];
$proof=['status'=>$workflow->status===$expected&&$posts===$expectedPosts?'passed':'needs_diagnosis','scenario'=>$scenario,'workflowStatus'=>$workflow->status,'expectedStatus'=>$expected,
    'fakePaidPosts'=>$posts,'expectedFakePaidPosts'=>$expectedPosts,'externalProviderCalls'=>0,'terminalReplayNoHttp'=>true,'realStyleQualityClaim'=>false,'syntheticAlgorithmFixture'=>true,
    'workflowId'=>$workflow->id,'itemId'=>$item->id,'importId'=>$import->id,'usedRuns'=>$project->fresh()->used_runs,'maximumWorkflowPhaseSeconds'=>max(array_column($history,'workflowPhaseSeconds')),'fakeCalls'=>$calls,'data'=>$item->data];
file_put_contents($root.'/proof.json',json_encode($proof,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));check($proof['status']==='passed','Chain did not reach the expected bounded outcome.');
echo json_encode($proof).PHP_EOL;

