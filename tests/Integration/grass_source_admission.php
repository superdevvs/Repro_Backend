<?php
/** Private disposable database; fake HTTP only; actual pinned Linux image workers. */
declare(strict_types=1);
$root = $argv[1] ?? '';
if (!str_starts_with($root, '/') || is_dir($root)) throw new RuntimeException('Use a new absolute private fixture directory.');
$code = dirname(__DIR__, 2);
$scenario = $argv[2] ?? 'success';
if (!in_array($scenario, ['success', 'source-hold', 'detail-success', 'detail-hold', 'source-receipt-drift', 'queued-image-receipt-drift', 'image-quota-receipt-drift'], true)) throw new RuntimeException('Unsupported test scenario.');
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

use App\Services\IguideLab\{LabAnalysisRecipes,LabGrassWorkflow,LabGrassWorkflowArtifacts,LabGrassAssessmentBridge,LabGrassAssessments,LabGrassAssessmentPolicy,LabGrassSegmentationAuthority,LabMachineSegmentationCapabilities,LabMachineSegmentations,LabMachineSegmentationTransport};
use App\Models\{IguideLabGrassAssessment,IguideLabMachineSegmentation,IguideLabWorkflowItem,IguideLabWorkflowStep};
$proof=[];register_shutdown_function(function()use(&$proof,$root){$proof['lastError']=error_get_last();file_put_contents($root.'/shutdown-proof.json',json_encode($proof,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));});
config(['services.fal.key'=>'fixture-not-real','iguide-lab.grass_workflow_enabled'=>true,'iguide-lab.grass_source_admission_enabled'=>true]);
$assessmentSnapshot=app(LabGrassAssessmentBridge::class)->snapshot();
$assessmentRuntime=['validationStatus'=>'passed','pythonPath'=>$python,'pythonVersion'=>'3.12.3','pillowVersion'=>'13.0.0','fixtureOnly'=>true];
foreach(['adapterSha256','protocolSha256','sharedProtocolSha256','bridgeSha256','contextSha256','preparerSha256','viewAdapterSha256','geometrySha256'] as $key) $assessmentRuntime['validated'.ucfirst($key)]=$assessmentSnapshot[$key];
file_put_contents($root.'/assessment-runtime.json',json_encode($assessmentRuntime));config(['iguide-lab.grass_assessment_runtime_manifest'=>$root.'/assessment-runtime.json']);
$runtime['validatedGrassCoreSha256']=hash_file('sha256',resource_path('iguide-lab/grass_chroma.py'));
$runtime['validatedGrassWorkerSha256']=hash_file('sha256',resource_path('iguide-lab/grass_chroma_worker.py'));
$runtime['validatedArtifactAdapterSha256']=hash_file('sha256',(new ReflectionClass(LabGrassWorkflowArtifacts::class))->getFileName());
$runtime['fixtureOnly']=true;file_put_contents($root.'/grass-runtime.json',json_encode($runtime));config(['iguide-lab.grass_runtime_manifest'=>$root.'/grass-runtime.json']);
$admissionSnapshot=app(App\Services\IguideLab\LabGrassSourceAdmissionBridge::class)->snapshot();
$admissionRuntime=['validationStatus'=>'passed','pythonPath'=>$python,'pythonVersion'=>'3.12.3','pillowVersion'=>'13.0.0','numpyVersion'=>'2.5.4','fixtureOnly'=>true];
foreach($admissionSnapshot as $key=>$value) if(str_ends_with($key,'Sha256')&&$key!=='runtimeManifestSha256') $admissionRuntime['validated'.ucfirst($key)]=$value;
file_put_contents($root.'/admission-runtime.json',json_encode($admissionRuntime));config(['iguide-lab.grass_source_admission_runtime_manifest'=>$root.'/admission-runtime.json']);
app()->instance(LabMachineSegmentationTransport::class,new class extends LabMachineSegmentationTransport {protected function addresses(string $host):array{return ['1.1.1.1'];}});
check(app(LabGrassWorkflow::class)->ready(),'Grass test runtime is not ready.');
$project=IguideLabProject::create(['name'=>'Synthetic imported grass integration','token_hash'=>hash('sha256',Str::random(64)), 'expires_at'=>now()->addDay(),'max_runs'=>20,'max_cases'=>160]);
function png($image):string{ob_start();imagepng($image);$bytes=ob_get_clean();imagedestroy($image);return $bytes;}
$fixtures=$code.'/tests/Fixtures/iguide-lab/grass-integration';
$small=imagecreatefrompng($fixtures.'/images/source.png');$native=imagecreatetruecolor(4096,2048);imagecopyresized($native,$small,0,0,0,0,4096,2048,2048,1024);imagedestroy($small);$source=png($native);
$zipPath=$root.'/original.zip';$zip=new ZipArchive;$zip->open($zipPath,ZipArchive::CREATE|ZipArchive::EXCL);$zip->addFromString('original.png',$source);$zip->close();
$upload=new Illuminate\Http\UploadedFile($zipPath,'original.zip','application/zip',null,true);$imports=app(LabImports::class);$importInput=['requestId'=>(string)Str::uuid()];
$import=$imports->start($project,$importInput,$upload,null);$imports->process($import);$import->refresh();check($import->status==='completed','Import failed');
$case=IguideLabCase::findOrFail($import->sources[0]['caseId']);check(app(LabWorkflowSources::class)->capture($case)['authority']['importId']===$import->id,'Import authority absent');
$fakeCalls=[];$submittedSourceHashes=[];$maskBytes=[];
foreach(json_decode(file_get_contents($fixtures.'/masks-index.json'),true) as $entry){$maskBytes[$entry['role']][]=file_get_contents($fixtures.'/masks/'.basename($entry['path']));}
$preparedSha=hash('sha256',App\Services\IguideLab\LabImages::prepared($source));
Http::fake(function($request)use(&$fakeCalls,&$submittedSourceHashes,$maskBytes,$fixtures,$preparedSha,$scenario){
    $url=$request->url();$fakeCalls[]=['url'=>$url,'method'=>$request->method()];
    if($url==='https://api.openai.com/v1/images/edits') {
        if($scenario==='image-quota-receipt-drift') {
            $admission=App\Models\IguideLabGrassSourceAdmission::where('status','completed')->sole();
            Storage::disk(LabService::DISK)->delete($admission->data['providerReceipt']['response']['path']);
            return Http::response(['error'=>['code'=>'insufficient_quota']],429,['x-request-id'=>'fake-image-quota']);
        }
        return Http::response(['data'=>[['b64_json'=>base64_encode(file_get_contents($fixtures.'/images/candidate.png'))]],'usage'=>['input_tokens'=>10,'output_tokens'=>10]],200,['x-request-id'=>'fake-image']);
    }
    if($url==='https://queue.fal.run/fal-ai/sam-3-1/image'){
        $role=array_search($request['prompt'],LabGrassAssessmentPolicy::PROMPTS,true);check(is_string($role),'Unknown source role prompt');
        $raw=base64_decode(explode(',',$request['image_url'],2)[1],true);check($raw!==false&&hash('sha256',$raw)===$preparedSha,'SAM did not receive the exact original prepared sphere');
        $submittedSourceHashes[$role]=hash('sha256',$raw);return Http::response(['request_id'=>'fake-'.$role],200);
    }
    if(str_starts_with($url,'https://queue.fal.run/')){
        if(str_ends_with($url,'/status'))return Http::response(['status'=>'COMPLETED'],200);
        preg_match('~/requests/fake-([^/]+)/~',$url,$match);$role=$match[1];$indexes=array_keys($maskBytes[$role]);
        if($scenario==='empty-'.$role)return Http::response(['masks'=>[]],200);
        if($role==='lawn')$indexes=array_reverse($indexes); // Returned order is not semantic identity.
        return Http::response(['masks'=>array_map(fn($i)=>['url'=>'https://fixture.fal.media/'.$role.'-'.$i.'.png'],$indexes)],200);
    }
    if(str_starts_with($url,'https://fixture.fal.media/')){
        preg_match('~^(.+)-(\d+)$~',pathinfo($url,PATHINFO_FILENAME),$match);
        return Http::response($maskBytes[$match[1]][(int)$match[2]],200,['Content-Type'=>'image/png']);
    }
    check($url==='https://api.openai.com/v1/responses','Unexpected external endpoint');$body=json_decode($request->body(),true);$context=json_decode($body['input'][0]['content'][0]['text'],true);
    if(($body['text']['format']['name']??null)==='iguide_grass_source_admission') {
        $ids=['source_panorama','source_front','source_right','source_back','source_left','source_zenith','source_nadir'];
        $finding=fn($status)=>['status'=>$status,'evidenceIds'=>$ids,'notes'=>'Synthetic original material screening.'];
        $value=['schemaVersion'=>'grass-original-source-admission/1','contextSha256'=>$context['contextSha256'],'binding'=>$context['binding'],'summary'=>'Synthetic source-only screening.',
            'material'=>$finding('established_dormant_turf'),'scope'=>$finding('all_visible_established_turf'),'lawnBoundary'=>$finding('complete_visible_boundary'),
            'exclusions'=>array_fill_keys(['paving','plants','loose_debris','non_turf_ground','capture_artifact'],$finding('separable_at_source_mask_resolution')),
            'reflections'=>$finding('no_visible_turf_reflection_observed'),'contextCoverage'=>$finding('sufficient_same_capture_views')+['detailRequests'=>[]],'uncertainties'=>[]];
        if($scenario==='source-hold') {
            $value['material']['status']='sparse_or_missing_turf';$value['exclusions']['loose_debris']['status']='too_fine_or_dense';
        }
        if(in_array($scenario,['detail-success','detail-hold'],true)&&$context['round']===0) {
            $value['material']['status']='uncertain';$value['contextCoverage']['status']='details_may_resolve';
            $value['contextCoverage']['detailRequests']=[['pose'=>['yaw'=>10,'pitch'=>-40,'hFov'=>45],'purpose'=>'Resolve original lawn boundary.','evidenceIds'=>['source_panorama']]];
            $value['uncertainties']=['Original material boundary is unclear.'];
        }
        if($context['round']===1) {
            check(count($context['evidence'])===8&&$context['evidence'][7]['id']==='source_detail_1','Native requested detail missing');
            check(($context['prior']['proposal']['contextCoverage']['detailRequests'][0]['pose']??null)===['hFov'=>45,'pitch'=>-40,'yaw'=>10] ||
                ($context['prior']['proposal']['contextCoverage']['detailRequests'][0]['pose']??null)===['yaw'=>10,'pitch'=>-40,'hFov'=>45],'Requested original pose changed');
            if($scenario==='detail-hold'){$value['material']['status']='uncertain';$value['uncertainties']=['Native detail does not resolve mixed grass and soil.'];}
            else $value['material']['evidenceIds'][]='source_detail_1';
        }
    }elseif(isset($context['binding'])){
        $template=json_decode(file_get_contents($fixtures.'/response.SYNTHETIC.json'),true);$value=json_decode($template['output'][0]['content'][0]['text'],true);
        $value['contextSha256']=$context['contextSha256'];$value['binding']=$context['binding'];
        // Descriptive model labels must not become SAM object identities.
        foreach (LabGrassAssessmentPolicy::ROLES as $role) {
            $finding = $role === 'lawn' ? $value['lawn'] : $value['exclusions'][$role];
            foreach ($finding['selections'] as $i => &$selection) $selection['id'] = 'Visible '.$role.' patch '.($i + 1);
            unset($selection);
            if ($role === 'lawn') $value['lawn'] = $finding; else $value['exclusions'][$role] = $finding;
        }
    }elseif(isset($context['candidateSha256'])){
        $checks=[];foreach(LabVisualQualityProtocol::CHECKS as $name)$checks[$name]=['status'=>'pass','evidenceViewIds'=>[match($name){'seam'=>'wrap','poles'=>'poles',default=>'front'}],'reason'=>'Synthetic mechanics fixture only.'];
        $value=array_intersect_key($context,array_flip(['sourceSha256','candidateSha256','intentSha256','evidenceManifestSha256']))+['protocolVersion'=>LabVisualQualityProtocol::VERSION,'checks'=>$checks,'unresolvedDependencies'=>[],'recommendedAction'=>'none','summary'=>'Fake evaluator, no real quality claim.'];
    }else{
        $value=['schemaVersion'=>'scene-plan-v1','sourceSha256'=>$context['sourceSha256'],'projection'=>$context['projection'],'preset'=>'grass','style'=>null,'summary'=>'Synthetic source-native turf recolor mechanics.',
            'protectedFeatures'=>[['id'=>'non-turf','description'=>'Original paving, plants, debris, non-turf ground and capture artifact','evidenceViewIds'=>['source'],'confidence'=>'high']],
            'targets'=>[['id'=>'lawn','description'=>'All visible established turf','action'=>'restyle','objectCount'=>null,'evidenceViewIds'=>['source']]],
            'sourceArtifacts'=>[],'uncertainties'=>[],'workflow'=>['mode'=>'whole_panorama','reason'=>'One same-capture color guidance sphere.','sections'=>[]],
            'generationPrompt'=>'Make all visible established turf naturally green while preserving exact source geometry, every paving edge, plant, loose object, sky and capture artifact. Keep the complete same-camera sphere.',
            'reviewChecks'=>array_map(fn($n)=>['check'=>$n,'instruction'=>'Compare source and candidate, especially source exclusions and both sphere boundaries.'],['edit','geometry','seam','appearance'])];
    }
    return Http::response(['id'=>'resp_fake_'.count($fakeCalls),'model'=>'gpt-6-astra','status'=>'completed','usage'=>['input_tokens'=>10,'output_tokens'=>10],'output'=>[['type'=>'message','role'=>'assistant','status'=>'completed','content'=>[['type'=>'output_text','text'=>json_encode($value,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION)]]]]],200,['x-request-id'=>'fake-response-'.count($fakeCalls)]);
});
app()->instance(LabGrassWorkflow::class,new class($root) extends LabGrassWorkflow {
 public function __construct(private string $proofRoot){}
 public function advance(\App\Models\IguideLabWorkflow $workflow,IguideLabWorkflowItem $item,IguideLabProject $project):void {
  try { parent::advance($workflow,$item,$project); } catch(\Throwable $e){file_put_contents($this->proofRoot.'/diagnostic.json',json_encode(['class'=>get_class($e),'message'=>$e->getMessage(),'trace'=>$e->getTraceAsString()],JSON_PRETTY_PRINT));throw $e;}
 }
});
$service=app(LabWorkflows::class);$input=['requestId'=>(string)Str::uuid(),'items'=>[['caseId'=>$case->id,'preset'=>'grass','instructions'=>'']]];$workflow=$service->start($project,$input);$history=[];
for($tick=0;$tick<40;$tick++){
    $service->process($workflow->fresh());$item=IguideLabWorkflowItem::where('workflow_id',$workflow->id)->sole();
    $history[]=['tick'=>$tick,'status'=>$workflow->fresh()->status,'itemStatus'=>$item->status,'data'=>$item->data,'fakeCalls'=>count($fakeCalls)];
    $proof=['status'=>'running','externalProviderCalls'=>0,'history'=>$history];
    file_put_contents($root.'/history.json',json_encode($history,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    if(in_array($workflow->fresh()->status,LabWorkflows::TERMINAL,true))break;
    foreach(App\Models\IguideLabGrassSourceAdmission::whereIn('status',['queued','received'])->get() as $r)app(App\Services\IguideLab\LabGrassSourceAdmissions::class)->process($r);
    if($scenario==='source-receipt-drift'&&!isset($proof['driftInjected'])) {
        $r=App\Models\IguideLabGrassSourceAdmission::where('status','completed')->first();
        if($r){Storage::disk(LabService::DISK)->delete($r->data['providerReceipt']['response']['path']);$proof['driftInjected']=true;}
    }
    foreach(IguideLabAnalysis::where('status','queued')->get() as $r)app(LabAnalyses::class)->process($r);
    if($scenario==='queued-image-receipt-drift'&&IguideLabRun::where('status','queued')->exists()) {
        $admission=App\Models\IguideLabGrassSourceAdmission::where('status','completed')->sole();
        Storage::disk(LabService::DISK)->delete($admission->data['providerReceipt']['response']['path']);
    }
    foreach(IguideLabRun::where('status','queued')->get() as $r)app(LabService::class)->process($r);
    foreach(IguideLabGrassAssessment::whereIn('status',['queued','received'])->get() as $r)app(LabGrassAssessments::class)->process($r);
    foreach(IguideLabMachineSegmentation::whereIn('status',['queued','processing'])->get() as $r)app(LabMachineSegmentations::class)->process($r);
    foreach(IguideLabWorkflowEvaluation::where('status','queued')->get() as $r)app(LabWorkflowEvaluations::class)->process($r);
}
$workflow->refresh();$item->refresh();$proof=['status'=>$workflow->status==='ready'?'passed_synthetic_fake_provider_chain':'needs_diagnosis','externalProviderCalls'=>0,
    'syntheticQualityOnly'=>true,'automaticRealSceneSuccess'=>false,'workflowId'=>$workflow->id,'sourceCaseId'=>$case->id,'importId'=>$import->id,
    'fakeHttpCalls'=>$fakeCalls,'sourceRoleHashes'=>$submittedSourceHashes,'usedRuns'=>$project->fresh()->used_runs,'item'=>$item->toArray(),'history'=>$history];
file_put_contents($root.'/proof.json',json_encode($proof,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
if(in_array($scenario,['queued-image-receipt-drift','image-quota-receipt-drift'],true)) {
    check($workflow->status==='needs_review','Queued image authority did not hold');
    check(IguideLabRun::count()===1&&IguideLabAnalysis::count()===1&&IguideLabMachineSegmentation::count()===0,'Unexpected drift children');
    check(IguideLabGrassAssessment::count()===0&&App\Models\IguideLabComposite::count()===0&&IguideLabWorkflowEvaluation::count()===0,'Drift reached material/final stages');
    $run=IguideLabRun::sole();check($run->status==='needs_attention','Image did not hold');
    $expected=$scenario==='image-quota-receipt-drift'?['quota_rejected']:[];
    check(array_column($run->options['providerAttempts']??[],'status')===$expected,'Unexpected image credential attempt');
    $before=count($fakeCalls);$service->process($workflow->fresh());check(count($fakeCalls)===$before,'Drift replay submitted HTTP');
    $proof['status']='passed_expected_image_authority_hold';$proof['scenario']=$scenario;$proof['terminalReplayNoHttp']=true;
    $proof['fakePaidPosts']=count(array_filter($fakeCalls,fn($r)=>$r['method']==='POST'));
    check($proof['fakePaidPosts']===($scenario==='image-quota-receipt-drift'?3:2),'Unexpected drift HTTP count');
    file_put_contents($root.'/proof.json',json_encode($proof,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));echo json_encode(['status'=>$proof['status'],'scenario'=>$scenario,'externalCalls'=>0]).PHP_EOL;exit;
}
if(in_array($scenario,['source-hold','detail-hold','source-receipt-drift'],true)) {
    check($workflow->status==='needs_review','Source gate did not hold');
    check(IguideLabRun::count()===0&&IguideLabMachineSegmentation::count()===0&&IguideLabAnalysis::count()===0,'Source hold spent an image/SAM/scene plan');
    check(App\Models\IguideLabComposite::count()===0&&IguideLabWorkflowEvaluation::count()===0,'Source hold reached final stages');
    $before=count($fakeCalls);$service->process($workflow->fresh());check(count($fakeCalls)===$before,'Source hold retried HTTP');
    $proof['status']='passed_expected_original_source_hold';$proof['scenario']=$scenario;$proof['terminalReplayNoHttp']=true;
    $proof['fakePaidPosts']=count(array_filter($fakeCalls,fn($r)=>$r['method']==='POST'));
    check($proof['fakePaidPosts']===($scenario==='detail-hold'?2:1),'Unexpected source admission spend');
    $proof['admissions']=App\Models\IguideLabGrassSourceAdmission::get()->map(fn($r)=>['id'=>$r->id,'status'=>$r->status,'result'=>$r->result])->all();
    file_put_contents($root.'/proof.json',json_encode($proof,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));echo json_encode(['status'=>$proof['status'],'scenario'=>$scenario,'externalCalls'=>0]).PHP_EOL;exit;
}
check($workflow->status==='ready','Workflow did not reach synthetic checked ready.');
$before=count($fakeCalls);$service->start($project,$input);$service->process($workflow->fresh());check(count($fakeCalls)===$before,'Terminal replay submitted HTTP.');
check(IguideLabAnalysis::count()===1&&IguideLabGrassAssessment::count()===1&&IguideLabRun::count()===1&&IguideLabMachineSegmentation::count()===6&&IguideLabWorkflowEvaluation::count()===1,'Unexpected paid child count.');
check(count($submittedSourceHashes)===6,'Not all required source roles were exercised.');
check(App\Models\IguideLabGrassSourceAdmission::count()===($scenario==='detail-success'?2:1),'Source admission budget drift');
$proof['terminalReplayNoHttp']=true;$proof['fakePaidPosts']=count(array_filter($fakeCalls,fn($r)=>$r['method']==='POST'));
$proof['admissions']=App\Models\IguideLabGrassSourceAdmission::get()->map(fn($r)=>['id'=>$r->id,'status'=>$r->status,'result'=>$r->result])->all();
// Isolated diagnostic checkpoint only: terminal items correctly reject all child
// access with409. Reopen the disposable records to exercise the deeper422 guards.
$item->update(['status'=>'preparing']);$workflow->update(['status'=>'preparing']);
$lawn=IguideLabMachineSegmentation::where('provider_request_id','fake-lawn')->sole();$lawnStep=IguideLabWorkflowStep::findOrFail($lawn->step_id);
$authority=app(LabGrassSegmentationAuthority::class);$assessment=IguideLabGrassAssessment::sole();$disk=Storage::disk(LabService::DISK);
$proof['driftRejections']=[];
function mustReject(callable $operation,string $name,array &$proof):void{
    try{$operation();throw new RuntimeException('Unexpected pass: '.$name);}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){check($e->getStatusCode()===422,'Unexpected guard status: '.$name);$proof['driftRejections'][]=$name;}
}
$rawRun=IguideLabRun::sole();
$maskPath=$lawn->result['artifacts'][0]['path'];$rawReceipt='iguide-lab/'.$project->id.'/machine-segmentations/'.$lawn->id.'/provider-result.json';
$preparedPath=collect($assessment->data['contextSnapshot']['assets'])->firstWhere('id','source_panorama')['path'];
foreach(['native'=>$case->source_path,'candidate'=>$rawRun->output_path,'prepared-source'=>$preparedPath,'mask'=>$maskPath,'provider-response'=>$rawReceipt,
    'assessment-provider-response'=>$assessment->data['providerReceipt']['response']['path']] as $name=>$path){
    $bytes=$disk->get($path);$disk->put($path,$bytes.'tamper');mustReject(fn()=>$authority->verifiedReceipt($lawnStep),$name,$proof);$disk->put($path,$bytes);
}
$assessmentData=$assessment->data;$responsePath=$assessmentData['providerReceipt']['response']['path'];$bytes=$disk->get($responsePath);
$disk->delete($responsePath);mustReject(fn()=>$authority->verifiedReceipt($lawnStep),'missing-assessment-provider-response',$proof);$disk->put($responsePath,$bytes);
$changed=$assessmentData;$changed['providerReceipt']['providerResponseId']='resp-other';$assessment->update(['data'=>$changed]);
mustReject(fn()=>$authority->verifiedReceipt($lawnStep),'assessment-provider-id',$proof);$assessment->update(['data'=>$assessmentData]);
$changed=$assessmentData;$changed['execution']['requestId']=(string)Str::uuid();$assessment->update(['data'=>$changed]);
mustReject(fn()=>$authority->verifiedReceipt($lawnStep),'assessment-execution',$proof);$assessment->update(['data'=>$assessmentData]);
$result=$lawn->result;$changed=$result;$changed['receipt']['masks'][0]['positivePixels']--;$lawn->update(['result'=>$changed]);
mustReject(fn()=>$authority->verifiedReceipt($lawnStep),'persisted-mask-descriptor',$proof);$lawn->update(['result'=>$result]);
$changed=$result;$changed['association']['associations'][0]['selectionIoU']=.01;$lawn->update(['result'=>$changed]);
mustReject(fn()=>$authority->verifiedReceipt($lawnStep),'persisted-association',$proof);$lawn->update(['result'=>$result]);
check($authority->verifiedReceipt($lawnStep)['recordId']===$lawn->id,'Restored exact receipt did not replay');
$composite=App\Models\IguideLabComposite::findOrFail($item->data['compositeId']);$compositionStep=IguideLabWorkflowStep::where('item_id',$item->id)->where('kind','composition')->sole();
$artifact=app(LabGrassWorkflowArtifacts::class);
foreach(['native-artifact'=>$composite->native_path,'effective-alpha'=>$composite->manifest['lineage']['effectiveAlpha']['path'],
    'composition-report'=>'iguide-lab/'.$project->id.'/workflow-items/'.$item->id.'/grass-'.$compositionStep->id.'/report.json'] as $name=>$path){
    $bytes=$disk->get($path);$disk->put($path,$bytes.'tamper');mustReject(fn()=>$artifact->verifyComposite($item,$composite,$compositionStep,$rawRun),$name,$proof);$disk->put($path,$bytes);
}
$artifact->verifyComposite($item,$composite,$compositionStep,$rawRun);check(count($fakeCalls)===$before,'A local drift check submitted HTTP');
$evaluationStep=IguideLabWorkflowStep::where('item_id',$item->id)->where('kind','evaluation')->sole();
check(array_column($evaluationStep->input['context']['views'],'id')===LabVisualQualityProtocol::VIEW_IDS,'Seven-board order changed');
check(app(App\Services\IguideLab\LabReviews::class)->latest($composite)===null,'A fake machine check created human acceptance');
$proof['composition']=array_intersect_key($composite->manifest,array_flip(['preview','native','lineage','qaManifestSha256']));
$proof['evidence']=$evaluationStep->input;$proof['manualAcceptance']=false;$proof['crossViewVerified']=false;
$item->update(['status'=>'ready']);$workflow->update(['status'=>'ready']);$proof['diagnosticReopenedDisposableRecords']=true;
file_put_contents($root.'/proof.json',json_encode($proof,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));echo json_encode(['status'=>$proof['status'],'fakePaidPosts'=>$proof['fakePaidPosts'],'externalCalls'=>0,'proof'=>$root.'/proof.json']).PHP_EOL;
