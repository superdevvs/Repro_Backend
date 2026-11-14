<?php
/** Private disposable database; fake HTTP only; actual pinned Linux image workers. */
declare(strict_types=1);
$root = $argv[1] ?? '';
$scenario = $argv[2] ?? 'global-then-replan';
if (!str_starts_with($root, '/') || is_dir($root) || !in_array($scenario, ['global-pass','global-then-replan','global-then-hold','latest-guidance-global'], true)) throw new RuntimeException('Use a new private path and a supported scenario.');
$code = dirname(__DIR__, 2);
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
$loader = require '/var/www/backend/vendor/autoload.php';
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
$python='/home/maverick/iguide-ai-runtime-v3/venv-py312-np254-pil1300/bin/python';
$runtime=['validationStatus'=>'passed','pythonPath'=>$python,'pythonVersion'=>'3.12.3','numpyVersion'=>'2.5.4','pillowVersion'=>'13.0.0',
    'validatedPreparerSha256'=>hash_file('sha256',(new ReflectionClass(App\Services\IguideLab\LabImages::class))->getFileName()),
    'fixtureOnly'=>true,'evidence'=>['Exact disposable integration code, not a production readiness assertion.']];
foreach (['validatedWorkerSha256'=>'workflow_qa.py','validatedLightingSha256'=>'lighting_transfer.py','validatedGlobalLightingSha256'=>'global_lighting.py','validatedGeometrySha256'=>'pano_geometry.py','validatedSourceAdapterSha256'=>'workflow_source.py'] as $k=>$f) $runtime[$k]=hash_file('sha256',resource_path('iguide-lab/'.$f));
$manifest=$root.'/runtime-manifest.json'; file_put_contents($manifest,json_encode($runtime,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)); chmod($manifest,0660);
config(['iguide-lab.workflow_runtime_manifest'=>$manifest,'iguide-lab.pipeline_python_path'=>$python,'iguide-lab.lighting_repair_enabled'=>true]);
$project=IguideLabProject::create(['name'=>'Disposable fake-provider integration','token_hash'=>hash('sha256',Str::random(64)), 'expires_at'=>now()->addDay(),'max_runs'=>20,'max_cases'=>160]);
$image=imagecreatetruecolor(4096,2048);
for ($y=0;$y<2048;$y++) imageline($image,0,$y,4095,$y,imagecolorallocate($image,80+intdiv($y,40),100+intdiv($y,40),120+intdiv($y,40)));
ob_start(); imagepng($image); $source=ob_get_clean(); imagedestroy($image);
$zipPath=$root.'/original.zip'; $zip=new ZipArchive; $zip->open($zipPath,ZipArchive::CREATE|ZipArchive::EXCL); $zip->addFromString('original.png',$source); $zip->close();
$upload=new Illuminate\Http\UploadedFile($zipPath,'original.zip','application/zip',null,true);
$importInput=['requestId'=>(string)Str::uuid()]; $imports=app(LabImports::class); $import=$imports->start($project,$importInput,$upload,null);
check($imports->start($project,$importInput,$upload,null)->id===$import->id,'Import replay duplicated reservation.');
$imports->process($import); $import->refresh(); check($import->status==='completed','Import did not complete: '.$import->error);
$case=IguideLabCase::findOrFail($import->sources[0]['caseId']);
$snapshot=app(LabWorkflowSources::class)->capture($case); check($snapshot['authority']['importId']===$import->id,'Original import authority missing.');
$prepared=App\Services\IguideLab\LabImages::prepared($source); $fakeCalls=[]; $evaluationCalls=0; $repairContextSeen=false; $history=[];
register_shutdown_function(function()use($root,&$history){file_put_contents($root.'/shutdown.json',json_encode(['error'=>error_get_last(),'history'=>$history],JSON_PRETTY_PRINT));});
Http::fake(function ($request) use (&$fakeCalls,$prepared,&$evaluationCalls,&$repairContextSeen,$scenario) {
    $fakeCalls[]=['endpoint'=>$request->url(),'at'=>now()->toIso8601String()];
    if ($request->url()==='https://api.openai.com/v1/images/edits') return Http::response(['data'=>[['b64_json'=>base64_encode($prepared)]],'usage'=>['input_tokens'=>10,'output_tokens'=>10]],200,['x-request-id'=>'fake-image']);
    check($request->url()==='https://api.openai.com/v1/responses','Unexpected fake HTTP endpoint.');
    $context=json_decode($request['input'][0]['content'][0]['text'],true,flags:JSON_THROW_ON_ERROR);
    if (isset($context['candidateSha256'])) {
        $evaluationCalls++;
        $failed = $evaluationCalls===1||($evaluationCalls===3&&$scenario==='latest-guidance-global') ? 'optical_dependencies' : (($evaluationCalls===2&&$scenario!=='global-pass')||($evaluationCalls===3&&$scenario==='global-then-hold') ? 'edit' : '');
        $checks=[]; foreach(LabVisualQualityProtocol::CHECKS as $name) $checks[$name]=['status'=>$name===$failed?'fail':'pass','evidenceViewIds'=>[match($name){'seam'=>'wrap','poles'=>'poles',default=>'front'}],'reason'=>str_repeat('Complete synthetic failure observations. ',15).' FINAL_CRITICAL_REASON'];
        $value=array_intersect_key($context,array_flip(['sourceSha256','candidateSha256','intentSha256','evidenceManifestSha256']))+['protocolVersion'=>LabVisualQualityProtocol::VERSION,'checks'=>$checks,'unresolvedDependencies'=>$failed===''?[]:[str_repeat('Complete optical dependency evidence. ',12).' END_DEPENDENCY'],'recommendedAction'=>$failed===''?'none':'replan','summary'=>'Fake evaluator only; a source-like global result does not satisfy visible preset intent.'];
    } else {
        if(isset($context['repairContext'])){
            $repairContextSeen=true;
            check(count($request['input'][0]['content'])===15,'Repair must send seven complete pairs.');
            check(str_contains(json_encode($context),'FINAL_CRITICAL_REASON')&&str_contains(json_encode($context),'END_DEPENDENCY'),'Repair feedback was truncated.');
            check(array_column($context['repairContext']['views'],'evidenceViewId')===LabVisualQualityProtocol::VIEW_IDS,'Repair evidence order changed.');
            check($context['repairContext']['candidateIsGeometryAuthority']===false,'Failed candidate became source authority.');
        }
        $value=['schemaVersion'=>'scene-plan-v1','sourceSha256'=>$context['sourceSha256'],'projection'=>$context['projection'],'preset'=>$context['preset'],'style'=>$context['style'],
            'summary'=>'A synthetic lighting test.','protectedFeatures'=>[['id'=>'source','description'=>'All source geometry and detail','evidenceViewIds'=>['source'],'confidence'=>'high']],
            'targets'=>[['id'=>'light','description'=>'Lighting only','action'=>'relight','objectCount'=>null,'evidenceViewIds'=>['source']]],'sourceArtifacts'=>[],
            'uncertainties'=>['Solar alignment is uncalibrated in this synthetic fixture.'],'workflow'=>['mode'=>'whole_panorama','reason'=>'Lighting only across the original sphere.','sections'=>[]],
            'generationPrompt'=>'Adjust the lighting to the requested time of day. Retain the full sphere, original geometry, surface detail and all objects.',
            'reviewChecks'=>array_map(fn($name)=>['check'=>$name,'instruction'=>'Compare original source and output.'],['edit','geometry','seam','appearance'])];
    }
    return Http::response(['id'=>'resp_fixture_'.count($fakeCalls),'model'=>'gpt-6-astra','status'=>'completed','usage'=>['input_tokens'=>10,'output_tokens'=>10],'output'=>[['type'=>'message','role'=>'assistant','status'=>'completed','content'=>[['type'=>'output_text','text'=>json_encode($value,JSON_THROW_ON_ERROR)]]]]],200,['x-request-id'=>'fake-response-'.count($fakeCalls)]);
});
check(app(App\Services\IguideLab\LabWorkflowPolicy::class)->capabilities()['ready'],'Private workflow runtime not ready.');
$input=['requestId'=>(string)Str::uuid(),'items'=>[['caseId'=>$case->id,'preset'=>'golden-hour','instructions'=>'']]];
$service=app(LabWorkflows::class); $workflow=$service->start($project,$input);
check($service->start($project,$input)->id===$workflow->id,'Workflow replay duplicated reservation.');
$history=[];
for($tick=0;$tick<30;$tick++) {
    $service->process($workflow->fresh());
    $history[]=['tick'=>$tick,'status'=>$workflow->fresh()->status,'fakeHttpCalls'=>count($fakeCalls)];
    foreach(IguideLabAnalysis::where('status','queued')->get() as $analysis) app(LabAnalyses::class)->process($analysis);
    foreach(IguideLabRun::where('status','queued')->get() as $run) app(LabService::class)->process($run);
    foreach(IguideLabWorkflowEvaluation::where('status','queued')->get() as $evaluation) app(LabWorkflowEvaluations::class)->process($evaluation);
    if(in_array($workflow->fresh()->status,LabWorkflows::TERMINAL,true)) break;
}
$dto=$service->dto($workflow->fresh()); file_put_contents($root.'/workflow.json',json_encode($dto,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
file_put_contents($root.'/history.json',json_encode($history,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
$expectedStatus=$scenario==='global-then-hold'?'needs_review':'ready';
check($workflow->fresh()->status===$expectedStatus,'Unexpected workflow state: '.json_encode($dto['items']));
$expectedImages=$scenario==='global-pass'?1:2;$expectedEvaluations=$scenario==='global-pass'?2:($scenario==='latest-guidance-global'?4:3);$expectedAnalyses=$scenario==='global-pass'?1:2;
check(IguideLabRun::count()===$expectedImages&&IguideLabAnalysis::count()===$expectedAnalyses&&IguideLabWorkflowEvaluation::count()===$expectedEvaluations,'Unexpected bounded paid child counts.');
check($project->fresh()->used_runs===$expectedImages,'Free local fallback incorrectly charged an image.');
$item=App\Models\IguideLabWorkflowItem::where('workflow_id',$workflow->id)->sole();
$steps=App\Models\IguideLabWorkflowStep::where('item_id',$item->id)->get();
$globalStep=$steps->firstWhere('step_key','composition-global-1');$imageStep=$steps->firstWhere('step_key','image-1');
check($globalStep!==null&&$globalStep->input['runId']===$imageStep->child_id&&$globalStep->input['repair']['newPaidImage']===false,'Global repair did not reuse exact confirmed latest run.');
check($steps->where('step_key','composition-global-1')->count()===1,'Duplicate global composition.');
check(App\Models\IguideLabComposite::count()===$expectedEvaluations,'Original and repaired candidates were not retained separately.');
if($scenario==='latest-guidance-global'){
    $second=$steps->firstWhere('step_key','composition-global-2');
    check($second&&$second->input['runId']===$steps->firstWhere('step_key','image-2')->child_id,'The improved later guidance was not used for the second global candidate.');
}
check($repairContextSeen===($scenario!=='global-pass'),'Full rendered-failure context was not used for replan.');
$before=count($fakeCalls);$service->start($project,$input);$service->process($workflow->fresh());
check(count($fakeCalls)===$before,'Terminal replay submitted HTTP.');
check(App\Models\IguideLabReview::count()===0,'Automated checker created operator acceptance.');
foreach(IguideLabAnalysis::all() as $analysis)if(isset($analysis->data['repairContext']))file_put_contents($root.'/repair-analysis-dto.json',json_encode(['data'=>app(LabAnalyses::class)->dto($analysis)],JSON_PRETTY_PRINT));
$proof=['status'=>'passed_synthetic_fake_provider_chain','scenario'=>$scenario,'externalProviderCalls'=>0,'productionMutations'=>0,
    'syntheticQualityOnly'=>true,'automaticRealSceneSuccess'=>false,'workflow'=>$dto,'history'=>$history,'runtime'=>$runtime,
    'fakeHttpCalls'=>$fakeCalls,'usedRuns'=>$project->fresh()->used_runs,'images'=>$expectedImages,'analyses'=>$expectedAnalyses,'evaluations'=>$expectedEvaluations,
    'globalRepairInput'=>$globalStep->input,'completeRepairContextSeen'=>$repairContextSeen,'terminalReplayNoHttp'=>true,'manualAcceptance'=>false,'crossViewVerified'=>false];
// Reopen only this disposable database to exercise authority rejection before any new spend.
$item->update(['status'=>'preparing']);$workflow->update(['status'=>'preparing']);
$evaluation=IguideLabWorkflowEvaluation::where('step_id',$steps->firstWhere('step_key','evaluation-1')->id)->sole();
$checks=app(LabWorkflowEvaluations::class);$disk=Storage::disk(LabService::DISK);$proof['driftRejected']=[];
function reject(callable $call,string $name,array &$proof):void{try{$call();throw new RuntimeException('Accepted drift '.$name);}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){check($e->getStatusCode()===422,'Wrong rejection '.$name);$proof['driftRejected'][]=$name;}}
$checks->verifiedCompleted($item,$evaluation);
foreach(['raw-response'=>$evaluation->data['responseReceipt']['path'],'paired-board'=>$evaluation->data['evidence']['assets'][1]['path'],'native-source'=>$case->source_path] as $name=>$path){$bytes=$disk->get($path);$disk->put($path,$bytes.'drift');reject(fn()=>$checks->verifiedCompleted($item,$evaluation),$name,$proof);$disk->put($path,$bytes);}
$data=$evaluation->data;$bad=$data;$bad['responseReceipt']['providerRequestId']='wrong';$evaluation->update(['data'=>$bad]);reject(fn()=>$checks->verifiedCompleted($item,$evaluation->fresh()),'provider-id',$proof);$evaluation->update(['data'=>$data]);
$checks->verifiedCompleted($item,$evaluation->fresh());check(count($fakeCalls)===$before,'Drift probes made HTTP calls.');
$item->update(['status'=>$expectedStatus]);$workflow->update(['status'=>$expectedStatus]);
file_put_contents($root.'/proof.json',json_encode($proof,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo json_encode(['status'=>$proof['status'],'scenario'=>$scenario,'externalProviderCalls'=>0,'proof'=>$root.'/proof.json']),PHP_EOL;
