<?php
/** Private disposable database; fake HTTP only; actual pinned Linux image workers. */
declare(strict_types=1);
$root = $argv[1] ?? '';
if (!str_starts_with($root, '/') || is_dir($root)) throw new RuntimeException('Use a new absolute private fixture directory.');
$code = dirname(__DIR__, 2);
$scenario = $argv[2] ?? 'success';
if (!in_array($scenario, ['success', 'empty-lawn', 'empty-paving', 'material-uncertain'], true)) throw new RuntimeException('Unsupported test scenario.');
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
config(['services.fal.key'=>'fixture-not-real','iguide-lab.grass_workflow_enabled'=>true]);
$assessmentSnapshot=app(LabGrassAssessmentBridge::class)->snapshot();
$assessmentRuntime=['validationStatus'=>'passed','pythonPath'=>$python,'pythonVersion'=>'3.12.3','pillowVersion'=>'13.0.0','fixtureOnly'=>true];
foreach(['adapterSha256','protocolSha256','sharedProtocolSha256','bridgeSha256','contextSha256','preparerSha256','viewAdapterSha256','geometrySha256'] as $key) $assessmentRuntime['validated'.ucfirst($key)]=$assessmentSnapshot[$key];
file_put_contents($root.'/assessment-runtime.json',json_encode($assessmentRuntime));config(['iguide-lab.grass_assessment_runtime_manifest'=>$root.'/assessment-runtime.json']);
$runtime['validatedGrassCoreSha256']=hash_file('sha256',resource_path('iguide-lab/grass_chroma.py'));
$runtime['validatedGrassWorkerSha256']=hash_file('sha256',resource_path('iguide-lab/grass_chroma_worker.py'));
$runtime['validatedArtifactAdapterSha256']=hash_file('sha256',(new ReflectionClass(LabGrassWorkflowArtifacts::class))->getFileName());
$runtime['fixtureOnly']=true;file_put_contents($root.'/grass-runtime.json',json_encode($runtime));config(['iguide-lab.grass_runtime_manifest'=>$root.'/grass-runtime.json']);
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
    if($url==='https://api.openai.com/v1/images/edits') return Http::response(['data'=>[['b64_json'=>base64_encode(file_get_contents($fixtures.'/images/candidate.png'))]],'usage'=>['input_tokens'=>10,'output_tokens'=>10]],200,['x-request-id'=>'fake-image']);
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
    if(isset($context['binding'])){
        $template=json_decode(file_get_contents($fixtures.'/response.SYNTHETIC.json'),true);$value=json_decode($template['output'][0]['content'][0]['text'],true);
        $value['contextSha256']=$context['contextSha256'];$value['binding']=$context['binding'];
        // Descriptive model labels must not become SAM object identities.
        foreach (LabGrassAssessmentPolicy::ROLES as $role) {
            $finding = $role === 'lawn' ? $value['lawn'] : $value['exclusions'][$role];
            foreach ($finding['selections'] as $i => &$selection) $selection['id'] = 'Visible '.$role.' patch '.($i + 1);
            unset($selection);
            if ($role === 'lawn') $value['lawn'] = $finding; else $value['exclusions'][$role] = $finding;
        }
        if ($scenario === 'material-uncertain') {
            $value['material']['status'] = 'sparse_or_missing_turf';
            $value['contextCoverage']['status'] = 'extra_context_required';
            $value['contextCoverage']['requestedDetails'] = ['Inspect source turf and mixed-material boundaries.'];
            foreach (['plants', 'loose_debris', 'non_turf_ground', 'capture_artifact'] as $role) {
                $value['exclusions'][$role]['status'] = $role === 'loose_debris' ? 'too_fine_or_dense' : 'uncertain';
            }
            $value['uncertainties'] = ['Sparse grass and fine source exclusions remain unresolved.'];
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
    foreach(IguideLabAnalysis::where('status','queued')->get() as $r)app(LabAnalyses::class)->process($r);
    foreach(IguideLabRun::where('status','queued')->get() as $r)app(LabService::class)->process($r);
    foreach(IguideLabGrassAssessment::whereIn('status',['queued','received'])->get() as $r)app(LabGrassAssessments::class)->process($r);
    foreach(IguideLabMachineSegmentation::whereIn('status',['queued','processing'])->get() as $r)app(LabMachineSegmentations::class)->process($r);
    foreach(IguideLabWorkflowEvaluation::where('status','queued')->get() as $r)app(LabWorkflowEvaluations::class)->process($r);
}
$workflow->refresh();$item->refresh();$proof=['status'=>$workflow->status==='ready'?'passed_synthetic_fake_provider_chain':'needs_diagnosis','externalProviderCalls'=>0,
    'syntheticQualityOnly'=>true,'automaticRealSceneSuccess'=>false,'workflowId'=>$workflow->id,'sourceCaseId'=>$case->id,'importId'=>$import->id,
    'fakeHttpCalls'=>$fakeCalls,'sourceRoleHashes'=>$submittedSourceHashes,'usedRuns'=>$project->fresh()->used_runs,'item'=>$item->toArray(),'history'=>$history];
file_put_contents($root.'/proof.json',json_encode($proof,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
if($scenario==='material-uncertain'){
    $assessment=IguideLabGrassAssessment::sole();
    check($workflow->status==='needs_review'&&$assessment->status==='completed'&&$assessment->result['status']==='needs_review','Semantic hold became parser failure or success');
    check(count($assessment->result['reviewReasons'])===7&&$assessment->result['segmentationPlan']===[],'Semantic reasons or no-plan gate changed');
    check(IguideLabWorkflowStep::where('kind','segmentation')->count()===0&&IguideLabMachineSegmentation::count()===0,'Uncertain material reserved SAM');
    check(App\Models\IguideLabComposite::count()===0&&IguideLabWorkflowEvaluation::count()===0,'Uncertain material reached composition or evaluation');
    $before=count($fakeCalls);$service->process($workflow->fresh());check(count($fakeCalls)===$before,'Held workflow retried a provider');
    $proof['status']='passed_expected_material_hold';$proof['scenario']=$scenario;$proof['terminalReplayNoHttp']=true;
    $proof['assessmentStatus']=$assessment->status;$proof['reviewReasons']=$assessment->result['reviewReasons'];
    $proof['fakePaidPosts']=count(array_filter($fakeCalls,fn($r)=>$r['method']==='POST'));
    check($proof['fakePaidPosts']===3,'Unexpected provider attempt during semantic hold');
    file_put_contents($root.'/proof.json',json_encode($proof,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));echo json_encode(['status'=>$proof['status'],'scenario'=>$scenario,'externalCalls'=>0]).PHP_EOL;exit;
}
if($scenario!=='success'){
    $expected=$scenario==='empty-lawn'?1:2;
    check($workflow->status==='needs_review'&&IguideLabMachineSegmentation::count()===$expected,'Missing mask did not stop later source roles');
    check(App\Models\IguideLabComposite::count()===0&&IguideLabWorkflowEvaluation::count()===0,'Missing mask reached composition or evaluation');
    $before=count($fakeCalls);$service->process($workflow->fresh());check(count($fakeCalls)===$before,'Held workflow retried a provider');
    $proof['status']='passed_expected_source_mask_hold';$proof['scenario']=$scenario;$proof['terminalReplayNoHttp']=true;
    file_put_contents($root.'/proof.json',json_encode($proof,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));echo json_encode(['status'=>$proof['status'],'scenario'=>$scenario,'externalCalls'=>0]).PHP_EOL;exit;
}
check($workflow->status==='ready','Workflow did not reach synthetic checked ready.');
$before=count($fakeCalls);$service->start($project,$input);$service->process($workflow->fresh());check(count($fakeCalls)===$before,'Terminal replay submitted HTTP.');
check(IguideLabAnalysis::count()===1&&IguideLabGrassAssessment::count()===1&&IguideLabRun::count()===1&&IguideLabMachineSegmentation::count()===6&&IguideLabWorkflowEvaluation::count()===1,'Unexpected paid child count.');
check(count($submittedSourceHashes)===6,'Not all required source roles were exercised.');
$proof['terminalReplayNoHttp']=true;$proof['fakePaidPosts']=count(array_filter($fakeCalls,fn($r)=>$r['method']==='POST'));
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
