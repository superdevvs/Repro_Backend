<?php
/** Virtual staging on the room engine: one paid draft always ends with a viewable result. Disposable local Docker, network disabled, synthetic provider responses only. */
declare(strict_types=1);
$code='/app';$root=(getenv('ROOM_PROOF_ROOT')?:'/proof').'/'.($argv[1]??'attempt-1');$scenario=$argv[2]??'bedroom';$floorUnion=getenv('ROOM_FLOOR_UNION_PROOF')==='1';
if(!in_array($scenario,['staging-ready','staging-held-draft','staging-three-masks','staging-mask-fragment','staging-no-mask','staging-furnished','staging-no-capacity'],true))throw new RuntimeException('Unknown scenario');
if(is_dir($root))throw new RuntimeException('Immutable proof directory already exists');mkdir($root,02770,true);chmod($root,02770);$db=$root.'/test.sqlite';touch($db);
foreach(['APP_ENV'=>'testing','APP_KEY'=>'base64:'.base64_encode(str_repeat('k',32)),'DB_CONNECTION'=>'sqlite','DB_DATABASE'=>$db,'CACHE_STORE'=>'array','QUEUE_CONNECTION'=>'sync','SESSION_DRIVER'=>'array','MAIL_MAILER'=>'array','LOG_CHANNEL'=>'single','CLAMAV_SCAN_ON_UPLOAD'=>'false'] as $k=>$v){putenv($k.'='.$v);$_ENV[$k]=$v;$_SERVER[$k]=$v;}
$loader=require $code.'/vendor/autoload.php';$app=require $code.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\{DB,Http,Queue,Storage};use Illuminate\Support\Str;
use App\Services\IguideLab\{LabService,LabImports,LabWorkflows,LabWorkflowSources,LabWorkflowArtifacts,LabWorkflowEvaluations,LabVisualQualityProtocol,LabSectionGeometry,LabSections,LabAnalysisRecipes,
    LabInteriorRoomAssessments,LabInteriorRoomWorkflow,LabInteriorRoomProfiles,LabInteriorRoomPolicy,LabRoomFurnishingSupport,LabInteriorDesignAssessments,LabInteriorDesignMaterials,LabInteriorDesignPolicy,
    LabInteriorRoomRouteProtocol,LabInteriorDesignProtocol,LabRoomFurnishingFramingProtocol,LabRoomEvidenceLayoutProtocol,LabRoomEvidenceCandidateProtocol,LabRoomEvidenceDraftPolicy,LabRoomFloorWitness,LabMachineSegmentationPlan,LabMachineSegmentations,LabMachineSegmentationTransport};
use App\Models\{IguideLabProject,IguideLabCase,IguideLabRoomAssessment,IguideLabWorkflowItem,IguideLabSection,IguideLabRun,IguideLabMachineSegmentation,IguideLabWorkflowEvaluation};
function check($ok,$msg):void{if(!$ok)throw new RuntimeException($msg);}
function saveJson($path,$value):void{file_put_contents($path,json_encode($value,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));chmod($path,0660);}
function png($im):string{ob_start();imagepng($im);$b=ob_get_clean();imagedestroy($im);return $b;}
check(DB::connection()->getDatabaseName()===$db,'Private DB mismatch');Http::preventStrayRequests();Queue::fake();
foreach(glob($code.'/database/migrations/*iguide_lab*.php') as $migration)(require $migration)->up();
(require $code.'/database/migrations/2026_10_10_050000_create_ai_provider_usage_table.php')->up();
$cfg=config('filesystems.disks.'.LabService::DISK);$cfg['root']=$root.'/private';$cfg['shared_group']=posix_getgrgid(posix_getegid())['name'];mkdir($cfg['root'],02770);chmod($cfg['root'],02770);config(['filesystems.disks.'.LabService::DISK=>$cfg]);Storage::forgetDisk(LabService::DISK);
$key=$root.'/fake-primary';file_put_contents($key,'synthetic-no-real-key');chmod($key,0600);$python='/usr/local/bin/python3.12';
config(['iguide-lab.openai_primary_key_path'=>$key,'services.openai.api_key'=>'synthetic-fallback','services.fal.key'=>'synthetic-fal',
    'iguide-lab.pipeline_python_path'=>$python,'iguide-lab.interior_material_python_path'=>$python,'iguide-lab.interior_room_support_python_path'=>$python,
    'iguide-lab.interior_design_workflow_enabled'=>true,'iguide-lab.interior_room_routing_enabled'=>true,
    'iguide-lab.interior_floor_witness_enabled'=>true,'iguide-lab.interior_room_evidence_drafts_enabled'=>true,'iguide-lab.interior_floor_witness_union_enabled'=>$floorUnion,
    'iguide-lab-staging-room.staging_room_alias_enabled'=>true]);
$pins=['validationStatus'=>'passed','pythonPath'=>$python,'pythonSha256'=>hash_file('sha256',$python),'pythonVersion'=>'3.12.3','numpyVersion'=>'2.5.4','pillowVersion'=>'13.0.0'];
$wr=$pins+['validatedPreparerSha256'=>hash_file('sha256',app_path('Services/IguideLab/LabImages.php'))];
foreach(['validatedWorkerSha256'=>'workflow_qa.py','validatedLightingSha256'=>'lighting_transfer.py','validatedGlobalLightingSha256'=>'global_lighting.py','validatedGeometrySha256'=>'pano_geometry.py','validatedSourceAdapterSha256'=>'workflow_source.py'] as $key=>$file)$wr[$key]=hash_file('sha256',resource_path('iguide-lab/'.$file));
saveJson($root.'/workflow-runtime.json',$wr);config(['iguide-lab.workflow_runtime_manifest'=>$root.'/workflow-runtime.json']);
foreach(['interior_design_runtime_manifest'=>[LabInteriorDesignPolicy::VERSION,LabInteriorDesignAssessments::class],'interior_room_runtime_manifest'=>[LabInteriorRoomPolicy::VERSION,LabInteriorRoomAssessments::class]] as $key=>[$v,$class]){
    saveJson($root.'/'.$key.'.json',$pins+['version'=>$v,'implementation'=>app($class)->implementation()]);config(['iguide-lab.'.$key=>$root.'/'.$key.'.json']);}
$material=app(LabInteriorDesignMaterials::class)->snapshot();saveJson($root.'/material-runtime.json',['version'=>'interior-material-runtime/1','validationStatus'=>'passed','python'=>'3.12.3','numpy'=>'2.5.4','pillow'=>'13.0.0','opencv'=>'5.0.0','files'=>$material['files']]);config(['iguide-lab.interior_material_runtime_manifest'=>$root.'/material-runtime.json']);
$probe=new Symfony\Component\Process\Process([$python,'-c','import cv2,hashlib,json,sys,numpy,PIL; print(json.dumps({"python":sys.version.split()[0],"numpy":numpy.__version__,"pillow":PIL.__version__,"opencv":cv2.__version__,"build":hashlib.sha256(cv2.getBuildInformation().encode()).hexdigest()}))']);$probe->mustRun();$runtime=json_decode($probe->getOutput(),true);check([$runtime['python'],$runtime['numpy'],$runtime['pillow'],$runtime['opencv']]===['3.12.3','2.5.4','13.0.0','5.0.0'],'Pins mismatch');
saveJson($root.'/room-support-runtime.json',$pins+['version'=>LabRoomFurnishingSupport::VERSION,'opencvVersion'=>'5.0.0','opencvBuildSha256'=>$runtime['build'],'implementation'=>app(LabRoomFurnishingSupport::class)->implementation()]);config(['iguide-lab.interior_room_support_runtime_manifest'=>$root.'/room-support-runtime.json']);
app()->instance(LabMachineSegmentationTransport::class,new class extends LabMachineSegmentationTransport{protected function addresses(string $host):array{return ['1.1.1.1'];}});
check(app(LabInteriorRoomWorkflow::class)->ready(),'Room runtime not ready');
$capability=bin2hex(random_bytes(32));$project=IguideLabProject::create(['name'=>'Synthetic room profile '.$scenario,'token_hash'=>hash('sha256',$capability),'expires_at'=>now()->addDay(),'max_runs'=>10,'max_cases'=>160]);
$nativeWidth=(int)(getenv('ROOM_NATIVE_WIDTH')?:4096);check(in_array($nativeWidth,[4096,11008],true),'Unknown resource fixture');$nativeHeight=intdiv($nativeWidth,2);$im=imagecreatetruecolor($nativeWidth,$nativeHeight);imagefilledrectangle($im,0,0,$nativeWidth-1,intdiv($nativeHeight,2)-1,0xd2d2d2);imagefilledrectangle($im,0,intdiv($nativeHeight,2),$nativeWidth-1,$nativeHeight-1,0x969696);$original=png($im);
$zipPath=$root.'/original.zip';$zip=new ZipArchive;$zip->open($zipPath,ZipArchive::CREATE|ZipArchive::EXCL);$zip->addFromString('original.png',$original);$zip->close();
$import=app(LabImports::class)->start($project,['requestId'=>(string)Str::uuid()],new Illuminate\Http\UploadedFile($zipPath,'original.zip',null,null,true),null);app(LabImports::class)->process($import);$import->refresh();check($import->status==='completed','Import failed: '.$import->error_code);
$case=IguideLabCase::findOrFail($import->sources[0]['caseId']);check(app(LabWorkflowSources::class)->capture($case)['authority']['kind']==='scan-import','Missing import authority');
$calls=[];$maskBytes=[];$profile=LabInteriorRoomProfiles::LIVING;
$roles=LabInteriorRoomProfiles::get($profile)['roles'];$boxes=$profile===LabInteriorRoomProfiles::LIVING?[[300,650,599,1549],[1400,650,1699,1549],[850,650,1149,1549]]:[[720,840,1323,1563],[360,880,643,1123],[1400,880,1683,1123]];
$rectangles=[];foreach($boxes as $i=>$b){$x=$b[0];$rectangles[$i]=$profile===LabInteriorRoomProfiles::LIVING?[[$x,650,$x+299,1149],[$x+35,1100,$x+50,1549],[$x+249,1100,$x+264,1549]]:[$b];}
foreach($roles as $i=>$id){$mask=imagecreatetruecolor(2048,2048);imagefill($mask,0,0,0);foreach($rectangles[$i] as $rect)imagefilledrectangle($mask,...[...$rect,0xffffff]);$maskBytes[$id]=png($mask);}
$floor=imagecreatetruecolor(2048,2048);imagefill($floor,0,0,0);imagefilledrectangle($floor,0,600,2047,2047,0xffffff);$maskBytes['floor']=png($floor);
$union=imagecreatetruecolor(2048,2048);imagefill($union,0,0,0);foreach($rectangles as $object)foreach($object as $rect)imagefilledrectangle($union,...[...$rect,0xffffff]);$maskBytes['furniture']=png($union);
$fragment=imagecreatetruecolor(2048,2048);imagefill($fragment,0,0,0);imagefilledrectangle($fragment,1000,300,1005,305,0xffffff);$maskBytes['fragment']=png($fragment);
Http::fake(function($request)use(&$calls,$maskBytes,$scenario,$profile,$roles,$boxes,$rectangles,$floorUnion){
    $url=$request->url();$calls[]=['url'=>$url,'method'=>$request->method()];
    if($url==='https://queue.fal.run/fal-ai/sam-3-1/image'){check($request['prompt']==='floor'||($request['prompt']==='furniture'&&$request['box_prompts']===[]&&$request['max_masks']===3),'Staging furniture mask must be text-only');return Http::response(['request_id'=>$request['prompt']==='floor'?'fake-floor':'fake-furniture'],200);}
    if(str_starts_with($url,'https://queue.fal.run/')){if(str_ends_with($url,'/status'))return Http::response(['status'=>'COMPLETED'],200);
        $ids=str_contains($url,'fake-floor')?['floor']:match($scenario){'staging-three-masks'=>array_reverse($roles),'staging-mask-fragment'=>['furniture','fragment'],'staging-no-mask'=>[],default=>['furniture']};return Http::response(['masks'=>array_map(fn($id)=>['url'=>'https://fixture.fal.media/'.$id.'.png'],$ids)],200);}
    if(str_starts_with($url,'https://fixture.fal.media/'))return Http::response($maskBytes[pathinfo($url,PATHINFO_FILENAME)],200,['Content-Type'=>'image/png']);
    if($url==='https://api.openai.com/v1/images/edits'){$run=IguideLabRun::where('status','submitting')->sole();$ordinal=$run->options['sourceMachinePlan']['evidenceDraft']['ordinal'];
        check(in_array($ordinal,[1,2],true),'Third image attempted');check(str_contains($run->prompt,'separated narrow legs'),'Living support form missing');
        if($floorUnion){check(($run->options['sourceMachinePlan']['version']??null)===App\Services\IguideLab\LabRoomFloorUnionImageDraft::VERSION,'Union image version missing');check(str_contains($run->prompt,'Original floor placement limits')&&str_contains($run->prompt,'contact-plus-attenuation envelopes'),'Numeric source limits missing');}
        if($ordinal===2&&in_array($scenario,['correction-quota-drift','correction-cancel','union-quota-floor-drift'],true)){
            if($scenario==='correction-quota-drift'){$prior=IguideLabRoomAssessment::where('phase','candidate')->where('status','completed')->sole();Storage::disk(LabService::DISK)->delete($prior->data['providerReceipt']['response']['path']);}
            elseif($scenario==='union-quota-floor-drift'){check($floorUnion,'Union drift scenario requires new contract');$floorRecord=IguideLabMachineSegmentation::where('status','completed')->sole();$asset=$floorRecord->result['artifacts'][0];$disk=Storage::disk(LabService::DISK);$disk->put($asset['path'],$disk->get($asset['path'])."\0");}
            else{$item=IguideLabWorkflowItem::sole();app(LabWorkflows::class)->cancel(\App\Models\IguideLabWorkflow::findOrFail($item->workflow_id));}
            return Http::response(['error'=>['code'=>'insufficient_quota']],429,['x-request-id'=>'fake-explicit-quota']);
        }
        $source=IguideLabCase::findOrFail($run->case_id);$image=imagecreatefromstring(Storage::disk(LabService::DISK)->get($source->source_path));foreach($rectangles as $object)foreach($object as $b)imagefilledrectangle($image,...[...$b,0x505f6e]);return Http::response(['data'=>[['b64_json'=>base64_encode(png($image))]],'usage'=>['input_tokens'=>10,'output_tokens'=>10]],200,['x-request-id'=>'fake-image-'.$ordinal]);}
    check($url==='https://api.openai.com/v1/responses','Unexpected endpoint');$body=json_decode($request->body(),true);$c=json_decode($body['input'][0]['content'][0]['text'],true);
    if(in_array($scenario,['quota-route-drift','cancel'],true)&&($c['phase']??null)==='framing'){
        $routeRecord=IguideLabRoomAssessment::where('phase','route')->where('status','completed')->sole();
        if($scenario==='quota-route-drift')Storage::disk(LabService::DISK)->delete($routeRecord->data['providerReceipt']['response']['path']);
        else{$item=IguideLabWorkflowItem::sole();app(LabWorkflows::class)->cancel(\App\Models\IguideLabWorkflow::findOrFail($item->workflow_id));}
        return Http::response(['error'=>['code'=>'insufficient_quota','message'=>'synthetic explicit quota']],429,['x-request-id'=>'fake-quota']);
    }
    if(($c['version']??null)===LabInteriorRoomRouteProtocol::VERSION){$value=Tests\Unit\IguideLabInteriorRoomRouteProtocolTest::candidate($c);$value['room']['function']=$profile===LabInteriorRoomProfiles::BEDROOM?'bedroom':'living';$value['metadataAgreement']['status']='unknown';if($scenario==='route-hold')$value['occupancy']['status']='uncertain';
        if($scenario==='staging-furnished'){$value['room']['function']='living';$value['occupancy']['status']='furnished';$value['occupancy']['existingFurnishings']=[['description'=>'Original sofa and rug.','evidenceViewIds'=>['source','front']]];
            $value['materialPotential']['status']='supported';$value['materialPotential']['eligibleSurfaces']=[['description'=>'Complete matte sofa cloth.','evidenceViewIds'=>['source','front']],['description'=>'Existing complete rug.','evidenceViewIds'=>['source','front']]];
            $value['materialPotential']['styleFeatures']=[['kind'=>'texture','description'=>'Recognizable woven cloth.'],['kind'=>'pattern','description'=>'Style-appropriate rug pattern.']];}}
    elseif(isset($c['phase'])){$p=match($c['phase']){'framing'=>LabRoomFurnishingFramingProtocol::class,'layout'=>$floorUnion?App\Services\IguideLab\LabRoomFloorUnionLayoutProtocol::class:LabRoomEvidenceLayoutProtocol::class,'candidate'=>$floorUnion?App\Services\IguideLab\LabRoomFloorUnionCandidateProtocol::class:LabRoomEvidenceCandidateProtocol::class};$value=Tests\Unit\IguideLabRoomFurnishingProtocolTest::value($p,$c);
        if($c['phase']==='framing')$value['view']=['yaw'=>0.,'pitch'=>-20.,'hFov'=>100.];
        else{foreach($value['objects'] as $i=>&$object){$b=$boxes[$i];$object['box']=$b;$object['supportForm']='narrow_terminals';$object['visibleBaseContacts']=[];}unset($object);$value['sourceFloor']=['status'=>'opaque_matte','prompt'=>LabRoomFloorWitness::LABEL,'box'=>[0,600,2047,2047]];
            if($floorUnion)$value['sourceFloor']=['status'=>'opaque_matte','prompt'=>App\Services\IguideLab\LabRoomFloorWitnessUnion::LABEL,'regions'=>[[$scenario==='union-outside-contact'?400:250,1300,1200,1800],[1100,1250,1750,1800]],'planeEvidence'=>['status'=>'same_visible_target_floor','evidenceViewIds'=>App\Services\IguideLab\LabRoomFloorWitnessUnion::IDS,'reason'=>'Synthetic original same target-floor plane only.'],'contactEnvelopes'=>[['objectId'=>'sofa','box'=>[$scenario==='union-outside-contact'?425:325,1500,575,1600]],['objectId'=>'coffee_table','box'=>[1425,1500,1675,1600]],['objectId'=>'chair','box'=>[875,1500,1125,1600]]]];
            $value['draftEvidence']=null;$value['obligationClosures']=[];$value['correction']=['decision'=>'none','defects'=>[]];
            if($c['phase']==='layout'){
                $value['checks']['frame_margin']['status']='uncertain';
                $value['draftEvidence']=['version'=>LabRoomEvidenceDraftPolicy::VERSION,'sourceBindingSha256'=>LabMachineSegmentationPlan::canonicalHash($c),
                    'sourceChecks'=>array_fill_keys(LabRoomEvidenceDraftPolicy::SOURCE_CHECKS,['status'=>'pass','evidenceViewIds'=>$p::ids(),'reason'=>'Synthetic visible source constraint, no real calibration claim.']),
                    'anchors'=>array_map(fn($id)=>['objectId'=>$id,'sourceAnchor'=>'Visible synthetic original wall and floor.','orientation'=>'Toward the original centre.','knownExclusions'=>'Keep the source doorway approach clear.'],$roles),
                    'candidateQuestions'=>[['id'=>'frame_margin','reason'=>'Actual candidate extents still need independent review.']]];
                if($scenario==='source-hold'){$value['status']='needs_review';$value['objects']=[];$value['sourceFloor']=null;$value['styleFeatures']=[];$value['draftEvidence']['sourceChecks']['receiver_observability']['status']='uncertain';$value['draftEvidence']['anchors']=[];$value['draftEvidence']['candidateQuestions']=[];}
            }else{
                $value['sourceFloor']=$c['layout']['result']['sourceFloor'];$value['obligationClosures']=[['id'=>'verify_frame_margin','status'=>'pass','evidenceViewIds'=>['source','candidate'],'reason'=>'Synthetic fully observed candidate extent.']];
                if($c['imageOrdinal']===1&&$scenario!=='staging-ready'){
                    $value['status']='needs_review';$value['objects']=[];$value['sourceFloor']=null;$value['styleFeatures']=[];$value['checks']['geometry']['status']='fail';$value['obligationClosures'][0]['status']='fail';
                    if($c['imageOrdinal']===1)$value['correction']=['decision'=>'one_correction','defects'=>[['checkId'=>'geometry','kind'=>'frame_margin','objectIds'=>['sofa'],'sourceAlternative'=>'Synthetic observed source-supported inward placement; unchanged pose and floor.']]];
                    if($scenario==='candidate-uncertain')$value['checks']['visible_contacts']['status']='uncertain';
                }
            }
        }
        if(($scenario==='cut-optics-hold'&&$c['phase']==='layout')||($scenario==='placement-hold'&&$c['phase']==='candidate')){$value['status']='needs_review';$value['objects']=[];$value['sourceFloor']=null;$value['styleFeatures']=[];foreach($value['checks'] as &$check)$check['status']='uncertain';unset($check);}
    }else{$intent=json_decode($c['intent'],true);check(($intent['sourceRoomProfile']['profileId']??null)===$profile,'Original profile intent missing');check(count($c['views'])===8,'Native evidence incomplete');
        if($scenario==='evaluation-quota-drift'){$candidate=IguideLabRoomAssessment::where('phase','candidate')->where('status','completed')->sole();Storage::disk(LabService::DISK)->delete($candidate->data['providerReceipt']['response']['path']);return Http::response(['error'=>['code'=>'insufficient_quota','message'=>'synthetic explicit quota']],429,['x-request-id'=>'fake-evaluation-quota']);}
        $checks=[];foreach(LabVisualQualityProtocol::CHECKS as $name)$checks[$name]=['status'=>'pass','evidenceViewIds'=>[match($name){'seam'=>'wrap','poles'=>'poles',default=>'detail_1'}],'reason'=>'Synthetic fake quality response for mechanics only, no scene acceptance.'];
        if($scenario==='staging-held-draft')$checks['geometry']['status']='fail';
        check(str_contains($c['context']??json_encode($c),'Automatic draft check')||$scenario==='staging-ready','Draft check caveat missing from the final check');
        $value=array_intersect_key($c,array_flip(['sourceSha256','candidateSha256','intentSha256','evidenceManifestSha256']))+['protocolVersion'=>LabVisualQualityProtocol::VERSION,'checks'=>$checks,'unresolvedDependencies'=>[],'recommendedAction'=>$scenario==='staging-held-draft'?'manual_review':'none','summary'=>'Synthetic mechanics only.'];}
    return Http::response(LabInteriorDesignProtocol::response($value)+['id'=>'resp_fake_'.count($calls),'usage'=>['input_tokens'=>10,'output_tokens'=>10]],200,['x-request-id'=>'fake-'.count($calls)]);
});
app()->instance(LabInteriorRoomWorkflow::class,new class($root) extends LabInteriorRoomWorkflow{public function __construct(private string $proofRoot){} public function advance($w,$i,$p):void{try{parent::advance($w,$i,$p);}catch(Throwable $e){saveJson($this->proofRoot.'/diagnostic.json',['class'=>get_class($e),'message'=>$e->getMessage(),'trace'=>$e->getTraceAsString()]);throw $e;}}});
$public=$root.'/public-controller';mkdir($public,02770);chmod($public,02770);
function controller(string $method,string $path,?array $body,?string $capability,string $save,int $expected):array{
    $request=Illuminate\Http\Request::create('http://localhost'.$path,$method,[],[],[],['HTTP_ACCEPT'=>'application/json','CONTENT_TYPE'=>'application/json','HTTP_AUTHORIZATION'=>$capability?'Bearer '.$capability:''], $body===null?null:json_encode($body,JSON_THROW_ON_ERROR));
    $kernel=app(Illuminate\Contracts\Http\Kernel::class);$response=$kernel->handle($request);$bytes=$response->getContent();file_put_contents($save,$bytes);chmod($save,0660);$status=$response->getStatusCode();$kernel->terminate($request,$response);
    check($status===$expected,'Controller '.$path.' status '.$status.': '.substr($bytes,0,500));return json_decode($bytes,true,512,JSON_THROW_ON_ERROR);
}
$body=['requestId'=>(string)Str::uuid(),'items'=>[['caseId'=>$case->id,'preset'=>'staging','style'=>'scandinavian']]];
$dto=controller('POST','/api/iguide-lab/workflows',$body,$capability,$public.'/00-workflow-created.json',202);
$workflow=App\Models\IguideLabWorkflow::findOrFail($dto['data']['id']);$history=[];
if($scenario==='staging-no-capacity')$project->update(['used_runs'=>$project->max_runs]);
controller('GET','/api/iguide-lab/workflows/'.$workflow->id,null,null,$public.'/negative-unauthorized.json',401);
$foreignToken=bin2hex(random_bytes(32));IguideLabProject::create(['name'=>'Foreign fixture','token_hash'=>hash('sha256',$foreignToken),'expires_at'=>now()->addDay(),'max_runs'=>1]);
controller('GET','/api/iguide-lab/workflows/'.$workflow->id,null,$foreignToken,$public.'/negative-cross-project.json',404);
foreach(['roomRouting'=>['profileId'=>'empty-bedroom-platform-three-piece/1'],'classifier'=>['status'=>'ready'],'manualAcceptance'=>true] as $key=>$value){$invalid=$body;$invalid[$key]=$value;controller('POST','/api/iguide-lab/workflows',$invalid,$capability,$public.'/negative-write-'.$key.'.json',422);}
controller('POST','/api/iguide-lab/runs',['requestId'=>(string)Str::uuid(),'caseId'=>$case->id,'modelId'=>'gpt-image-2.6-sunburst','preset'=>'interior-design','options'=>['promptMode'=>'machine-interior-furnishing-plan']],$capability,$public.'/negative-machine-prompt.json',422);

for($tick=0;$tick<45;$tick++){
    app(LabWorkflows::class)->process($workflow->fresh());$item=IguideLabWorkflowItem::where('workflow_id',$workflow->id)->sole();$history[]=['tick'=>$tick,'state'=>$workflow->fresh()->status,'item'=>$item->data,'fakeHttp'=>count($calls)];saveJson($root.'/history.json',$history);
    controller('GET','/api/iguide-lab/workflows/'.$workflow->id,null,$capability,$public.'/tick-'.str_pad((string)$tick,2,'0',STR_PAD_LEFT).'-workflow.json',200);
    if(in_array($workflow->fresh()->status,LabWorkflows::TERMINAL,true))break;
    foreach(IguideLabRoomAssessment::whereIn('status',['queued','received'])->get() as $r)app(LabInteriorRoomAssessments::class)->process($r);
    foreach(IguideLabSection::where('status','queued')->get() as $r)app(LabSections::class)->process($r);
    foreach(IguideLabMachineSegmentation::whereIn('status',['queued','processing'])->get() as $r)app(LabMachineSegmentations::class)->process($r);
    foreach(IguideLabRun::where('status','queued')->get() as $r){
        if($scenario==='correction-prior-drift'&&($r->options['sourceMachinePlan']['evidenceDraft']['ordinal']??null)===2&&!isset($tamper)){
            $prior=IguideLabRoomAssessment::where('phase','candidate')->where('status','completed')->sole();Storage::disk(LabService::DISK)->delete($prior->data['providerReceipt']['response']['path']);$tamper=true;
        }
        app(LabService::class)->process($r);
    }
    foreach(IguideLabWorkflowEvaluation::where('status','queued')->get() as $r)app(LabWorkflowEvaluations::class)->process($r);
    if($scenario==='route-receipt-drift'&&!isset($tamper)&&($r=IguideLabRoomAssessment::where('phase','route')->where('status','completed')->first())){Storage::disk(LabService::DISK)->delete($r->data['providerReceipt']['response']['path']);$tamper=true;}
    if($scenario==='floor-receipt-drift'&&!isset($tamper)&&($r=IguideLabMachineSegmentation::where('status','completed')->first())){Storage::disk(LabService::DISK)->delete('iguide-lab/'.$project->id.'/machine-segmentations/'.$r->id.'/provider-result.json');$tamper=true;}
}
$workflow->refresh();$item->refresh();$before=count($calls);app(LabWorkflows::class)->process($workflow);check(count($calls)===$before,'Terminal replay performed HTTP');
$proof=['scenario'=>$scenario,'status'=>$workflow->status,'externalProviderCalls'=>0,'syntheticQualityOnly'=>true,'automaticRealSceneSuccess'=>false,'runtime'=>$runtime,'calls'=>$calls,'usedRuns'=>$project->fresh()->used_runs,'workflow'=>app(LabWorkflows::class)->dto($workflow),'source'=>$item->source,'history'=>$history,
    'assessments'=>IguideLabRoomAssessment::all()->map(fn($r)=>app(LabInteriorRoomAssessments::class)->dto($r))->all(),'runs'=>IguideLabRun::all()->toArray(),'segmentations'=>IguideLabMachineSegmentation::all()->toArray(),'evaluations'=>IguideLabWorkflowEvaluation::all()->toArray()];
$proof['nativeDimensions']=['width'=>$nativeWidth,'height'=>$nativeHeight];$proof['phpPeakBytes']=memory_get_peak_usage(true);$proof['actualAuthenticatedController']=true;
controller('GET','/api/iguide-lab',null,$capability,$public.'/project-index.json',200);
controller('GET','/api/iguide-lab/workflows',null,$capability,$public.'/workflow-index.json',200);
foreach(IguideLabRun::where('project_id',$project->id)->get() as $r)controller('GET','/api/iguide-lab/runs/'.$r->id,null,$capability,$public.'/run-'.$r->id.'.json',200);
foreach(IguideLabSection::where('project_id',$project->id)->get() as $r)controller('GET','/api/iguide-lab/sections/'.$r->id,null,$capability,$public.'/section-'.$r->id.'.json',200);
foreach(App\Models\IguideLabComposite::where('project_id',$project->id)->get() as $r)controller('GET','/api/iguide-lab/composites/'.$r->id,null,$capability,$public.'/composite-'.$r->id.'.json',200);
saveJson($root.'/proof.json',$proof);
$expected=in_array($scenario,['staging-ready','staging-three-masks','staging-mask-fragment'],true)?'ready':'needs_review';check($workflow->status===$expected,'Unexpected terminal: '.$workflow->status.' '.json_encode($item->data['reviewReasons']??null));
$publicItem=app(LabWorkflows::class)->dto($workflow)['items'][0];check($publicItem['preset']==='staging','Staging must keep its public preset');
check(array_keys($publicItem)===['id','caseId','preset','style','status','stageLabel','attemptCount','result','reviewReasons','automation','createdAt','updatedAt'],'Staging item shape changed: '.implode(',',array_keys($publicItem)));
$stops=['staging-furnished'=>'staging_room_not_empty','staging-no-capacity'=>'generation_capacity_unavailable'];
if(isset($stops[$scenario])){check($publicItem['result']===null&&$publicItem['reviewReasons'][0]['code']===$stops[$scenario],'Expected a clear stop before any image: '.json_encode($publicItem['reviewReasons']));check(IguideLabRun::count()===0,'No image may be bought');}
else{check(($publicItem['result']['kind']??null)==='composite'&&$publicItem['result']['native']!==null,'A paid staging draft must end with a viewable result');check(App\Models\IguideLabComposite::count()===1,'Exactly one composite');
    check(IguideLabWorkflowEvaluation::where('status','completed')->count()===1,'The final check must run on the composite');
    $composite=App\Models\IguideLabComposite::sole();$objects=json_decode(Storage::disk(LabService::DISK)->get($composite->manifest['lineage']['supportReport']['path']),true)['objects'];
    if($scenario==='staging-no-mask'){check($objects['fallback']==='draft_region'&&$objects['maskCount']===0,'No usable mask must composite the draft inside the planned region');
        check($publicItem['reviewReasons'][0]['code']==='staging_furniture_outline_unavailable','A region-blended draft must end Needs review: '.json_encode($publicItem['reviewReasons']));}
    if($scenario==='staging-mask-fragment')check($objects['fallback']==='objects'&&$objects['maskCount']===1,'The valid mask subset must be kept: '.json_encode($objects));}
$imageCalls=count(array_filter($calls,fn($c)=>$c['url']==='https://api.openai.com/v1/images/edits'));
$expectedImages=isset($stops[$scenario])?0:1;
check($imageCalls===$expectedImages,'Unexpected primary image count or fallback');
check(IguideLabRun::count()===$expectedImages,'Staging buys exactly one image');check(IguideLabRoomAssessment::count()<=5,'Analysis ceiling exceeded');
$steps=\App\Models\IguideLabWorkflowStep::where('item_id',$item->id)->get();
check($steps->pluck('request_id')->unique()->count()===$steps->count(),'Duplicate owned request identity');
check($scenario==='staging-furnished'||$item->data['interiorRoomRoute']['budget']===LabRoomEvidenceDraftPolicy::BUDGET,'New budget not persisted');
if(false){
    $records=app(LabWorkflows::class)->dto($workflow)['items'][0]['roomRouting']['assessments'];
    $actual=array_values(array_filter($records,fn($r)=>$r['phase']==='candidate'));check(count($actual)===2,'Corrected public assessment omitted');
    check(array_column(array_column($actual,'provenance'),'imageOrdinal')===[1,2],'Candidate read order changed');
    check($actual[1]['provenance']['correctionParent']['id']===$actual[0]['id'],'Correction parent omitted');
}
config(['iguide-lab.interior_floor_witness_union_enabled'=>false,'iguide-lab.interior_room_evidence_drafts_enabled'=>false,'iguide-lab.interior_floor_witness_enabled'=>false,'iguide-lab.interior_room_routing_enabled'=>false]);
controller('GET','/api/iguide-lab',null,$capability,$public.'/disabled-project-index.json',200);
controller('GET','/api/iguide-lab/workflows/'.$workflow->id,null,$capability,$public.'/disabled-workflow.json',200);
saveJson($root.'/validated.json',['scenario'=>$scenario,'terminal'=>$workflow->status,'imagePosts'=>$imageCalls,'roomAssessments'=>IguideLabRoomAssessment::count(),'noThirdImage'=>true,'terminalReplayNoHttp'=>true,'disabledFlagControllerRead'=>true,'syntheticOnly'=>true]);
echo json_encode(['status'=>'passed','scenario'=>$scenario,'terminal'=>$workflow->status,'externalProviderCalls'=>0,'usedRuns'=>$project->fresh()->used_runs]);
