<?php
namespace Tests\Feature;

use App\Models\{IguideLabProject,IguideLabWorkflowItem,IguideLabRoomAssessment,IguideLabAnalysis,IguideLabRun};
use App\Services\IguideLab\{LabService,LabInteriorRoomContext,LabInteriorRoomAssessments,LabInteriorRoomPolicy,LabInteriorRoomProfiles,LabInteriorRoomRoutes,LabWorkflowSteps,LabWorkflows,LabAnalysisRecipes,LabInteriorDesignProtocol};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Http,Queue,Storage};
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Unit\IguideLabInteriorRoomRouteProtocolTest as Fixtures;

class IguideLabInteriorRoomAssessmentTest extends TestCase
{
    use RefreshDatabase;
    private array $temporary=[];
    private RoomContextFixture $context;
    protected function setUp(): void
    {
        parent::setUp();Queue::fake();Http::preventStrayRequests();
        $key=tempnam(sys_get_temp_dir(),'room-key');file_put_contents($key,'fixture-primary');$this->temporary[]=$key;
        config(['app.key'=>'base64:'.base64_encode(str_repeat('k',32)),'services.openai.api_key'=>'fixture-fallback','iguide-lab.openai_primary_key_path'=>$key]);
        if(function_exists('posix_getegid'))config(['filesystems.disks.'.LabService::DISK.'.shared_group'=>posix_getgrgid(posix_getegid())['name']]);
        Storage::fake(LabService::DISK,config('filesystems.disks.'.LabService::DISK))->makeDirectory('');
        $this->context=new RoomContextFixture;$this->app->instance(LabInteriorRoomContext::class,$this->context);
        // Mocked preparation only; this PHP binary is an identity fixture, never executed as Python.
        config(['iguide-lab.pipeline_python_path'=>PHP_BINARY]);
        $manifest=tempnam(sys_get_temp_dir(),'room-runtime');$this->temporary[]=$manifest;
        file_put_contents($manifest,json_encode(['version'=>LabInteriorRoomPolicy::VERSION,'validationStatus'=>'passed',
            'implementation'=>app(LabInteriorRoomAssessments::class)->implementation(),'pythonPath'=>PHP_BINARY,'pythonSha256'=>hash_file('sha256',PHP_BINARY),
            'pythonVersion'=>'3.12.3','numpyVersion'=>'2.5.4','pillowVersion'=>'13.0.0']));
        config(['iguide-lab.interior_room_runtime_manifest'=>$manifest]);
    }
    protected function tearDown(): void {foreach($this->temporary as $path)if(is_file($path))unlink($path);parent::tearDown();}
    private function fixture(): array
    {
        $project=IguideLabProject::create(['name'=>'Room route authority','token_hash'=>hash('sha256',Str::random(64)),'expires_at'=>now()->addDay(),'max_runs'=>20]);
        [$context,$images]=Fixtures::fixture();$bytes=base64_decode(explode(',',$images[0],2)[1]);
        $case=app(LabService::class)->addCase($project,UploadedFile::fake()->createWithContent('native.png',$bytes),['label'=>'Original','projection'=>'equirectangular']);
        $workflow=app(LabWorkflows::class)->start($project,['requestId'=>(string)Str::uuid(),'items'=>[['caseId'=>$case->id,'preset'=>'interior-design','style'=>'modern']]]);
        $policy=$workflow->policy;$policy['interiorRoomRouting']=LabInteriorRoomPolicy::snapshot();$policy['interiorRoomAssessments']=app(LabInteriorRoomAssessments::class)->snapshot();$workflow->update(['policy'=>$policy]);
        config(['iguide-lab.interior_room_routing_enabled'=>true]);$item=IguideLabWorkflowItem::where('workflow_id',$workflow->id)->sole();$context['nativeSourceSha256']=$case->source_sha256;
        $assets=[];foreach($images as $i=>$image){$path='iguide-lab/'.$project->id.'/workflow-items/'.$item->id.'/fixture-'.$i.'.png';Storage::disk(LabService::DISK)->put($path,base64_decode(explode(',',$image,2)[1]));$assets[]=['id'=>$context['views'][$i]['id'],'path'=>$path,'sha256'=>$context['views'][$i]['sha256']];}
        $this->context->bundle=['context'=>$context,'assets'=>$assets,'implementation'=>$this->context->snapshot(),'geometryManifestSha256'=>str_repeat('d',64),'metadataAuthority'=>null];
        $service=app(LabInteriorRoomAssessments::class);$step=$service->reserve($item,'route');$record=$service->start($step);
        return [$project,$workflow,$item,$case,$step,$record];
    }
    private function response(?array $value=null): array
    {return LabInteriorDesignProtocol::response($value??Fixtures::candidate($this->context->bundle['context']))+['id'=>'resp-room-classifier'];}
    private function finish(IguideLabRoomAssessment $record,?array $value=null): IguideLabRoomAssessment
    {
        Http::fake(['api.openai.com/v1/responses'=>Http::response($this->response($value),200,['x-request-id'=>'req-room'])]);
        $service=app(LabInteriorRoomAssessments::class);$service->process($record);$service->process($record->fresh());return $record->fresh();
    }
    public function test_source_classifier_is_durable_exactly_once_and_route_binds_before_branch_with_no_image(): void
    {
        [$project,,$item,$case,$step,$record]=$this->fixture();$record=$this->finish($record);$service=app(LabInteriorRoomAssessments::class);
        $this->assertSame('completed',$record->status);$service->process($record);$this->assertSame($record->id,$service->start($step)->id);
        $route=app(LabInteriorRoomRoutes::class)->bind($item,$record);$this->assertSame(LabInteriorRoomProfiles::BEDROOM,$route['profileId']);
        $this->assertSame($route,app(LabInteriorRoomRoutes::class)->verify($item));$this->assertSame($route,app(LabInteriorRoomRoutes::class)->bind($item,$record));
        Http::assertSentCount(1);$this->assertSame(0,IguideLabAnalysis::count());$this->assertSame(0,IguideLabRun::count());$this->assertSame(0,$project->fresh()->used_runs);
        $this->assertSame('interior-design',$item->fresh()->preset);$this->assertSame('modern',$item->style);
        $this->assertSame(4000,json_decode(Http::recorded()[0][0]->body(),true)['max_output_tokens']);
        $dto=$service->dto($record);$this->assertSame('interior-room-assessment-read/1',$dto['provenance']['version']);
        $this->assertSame($case->id,$dto['provenance']['nativeCaseId']);$this->assertNull($dto['provenance']['cutCaseId']);$this->assertNull($dto['provenance']['candidateRunId']);
        $this->assertSame($item->source['sha256'],$dto['workflowInvocation']['sourceSha256']);
    }
    public function test_semantic_unsupported_remains_completed_and_never_binds_or_falls_back_to_another_branch(): void
    {
        [,,$item,,,$record]=$this->fixture();$v=Fixtures::candidate($this->context->bundle['context']);$v['opticalDependencies']['status']='unsupported';
        $record=$this->finish($record,$v);$this->assertSame('completed',$record->status);$this->assertSame('needs_review',$record->result['route']['status']);
        $this->expectException(HttpException::class);app(LabInteriorRoomRoutes::class)->bind($item,$record);
    }
    public function test_same_queued_unattached_receipt_recovers_but_ambiguous_submission_does_not(): void
    {
        [,,,,$step,$record]=$this->fixture();$step->update(['child_id'=>null]);Queue::fake();
        app(LabInteriorRoomAssessments::class)->start($step->fresh());$this->assertSame($record->id,$step->fresh()->child_id);Queue::assertPushed(\App\Jobs\ProcessIguideLabRoomAssessment::class,1);
        $count=0;Http::fake(function()use(&$count){$count++;throw new \Illuminate\Http\Client\ConnectionException('unconfirmed');});
        $service=app(LabInteriorRoomAssessments::class);$service->process($record);Queue::fake();$service->start($step->fresh());$service->process($record->fresh());
        $this->assertSame(1,$count);$this->assertSame('needs_attention',$record->fresh()->status);$this->assertSame('unknown',$record->fresh()->provider_attempts[0]['status']);Queue::assertNothingPushed();
    }
    public function test_quota_fallback_revalidates_source_metadata_and_payload_before_spend(): void
    {
        [,,,,,$record]=$this->fixture();$count=0;Http::fake(function()use(&$count){$count++;$this->context->bundle['metadataAuthority']=['mappingSha256'=>str_repeat('e',64)];return Http::response(['error'=>['code'=>'insufficient_quota']],429);});
        app(LabInteriorRoomAssessments::class)->process($record);$this->assertSame(1,$count);$this->assertSame(['quota_rejected'],array_column($record->fresh()->provider_attempts,'status'));
    }
    public function test_cancel_after_primary_quota_stops_fallback_and_old_held_records_stay_unchanged(): void
    {
        [,$workflow,,,,$record]=$this->fixture();$count=0;Http::fake(function()use($workflow,&$count){$count++;app(LabWorkflows::class)->cancel($workflow);return Http::response(['error'=>['code'=>'insufficient_quota']],429);});
        app(LabInteriorRoomAssessments::class)->process($record);$this->assertSame(1,$count);$this->assertSame(0,\App\Models\IguideLabInteriorAssessment::count());
    }
    public function test_received_replay_keeps_exact_quota_receipt_and_rejects_model_or_raw_file_drift(): void
    {
        [,,$item,,,$record]=$this->fixture();Http::fakeSequence('api.openai.com/v1/responses')->push(['error'=>['code'=>'insufficient_quota']],429)->push($this->response(),200,['x-request-id'=>'req-room-fallback']);
        $service=app(LabInteriorRoomAssessments::class);$service->process($record);$this->assertSame('received',$record->fresh()->status);$service->process($record->fresh());$record->refresh();
        $this->assertSame('completed',$record->status);Http::assertSentCount(2);$requests=Http::recorded();$this->assertSame($requests[0][0]->body(),$requests[1][0]->body());
        $route=app(LabInteriorRoomRoutes::class)->bind($item,$record);$attempts=$record->provider_attempts;$bad=$attempts;$bad[0]['requestedModelId']='other';$record->update(['provider_attempts'=>$bad]);
        $this->rejects(fn()=>app(LabInteriorRoomRoutes::class)->verify($item));$record->update(['provider_attempts'=>$attempts]);
        $disk=Storage::disk(LabService::DISK);$path=$record->data['providerReceipt']['response']['path'];$raw=$disk->get($path);$disk->put($path,$raw.' ');$this->rejects(fn()=>app(LabInteriorRoomRoutes::class)->verify($item));$disk->put($path,$raw);
        $this->assertSame($route,app(LabInteriorRoomRoutes::class)->verify($item));
    }
    public function test_branch_budget_is_reserved_from_start_and_never_resets_or_accepts_living_roles_for_bedroom(): void
    {
        [,,$item,,,$record]=$this->fixture();$steps=app(LabWorkflowSteps::class);
        $this->rejects(fn()=>$steps->reserve($item,'room-framing','analysis',[]));
        $record=$this->finish($record);app(LabInteriorRoomRoutes::class)->bind($item,$record);
        $this->rejects(fn()=>$steps->reserve($item,'interior-framing','analysis',[]));
        foreach(['room-framing','room-layout','room-candidate'] as $key)$steps->reserve($item,$key,'analysis',[]);
        $this->rejects(fn()=>$steps->reserve($item,'room-followup','analysis',[]));
        $this->rejects(fn()=>$steps->reserve($item,'unknown-sixth','analysis',[]));
        $steps->reserve($item,'room-image','image',[]);$this->rejects(fn()=>$steps->reserve($item,'room-image-retry','image',[]));
        $steps->reserve($item,'room-floor','segmentation',[]);$steps->reserve($item,'room-objects','segmentation',[]);$this->rejects(fn()=>$steps->reserve($item,'room-mask-retry','segmentation',[]));
    }
    public function test_disabled_or_interpreter_drift_stops_ready_and_never_dispatches(): void
    {
        $this->assertFalse(config('iguide-lab.interior_room_routing_enabled'));[,,,,$step]=$this->fixture();
        config(['iguide-lab.interior_room_routing_enabled'=>false]);Queue::fake();$this->rejects(fn()=>app(LabInteriorRoomAssessments::class)->start($step));Queue::assertNothingPushed();
        $path=config('iguide-lab.interior_room_runtime_manifest');$m=json_decode(file_get_contents($path),true);$m['pythonSha256']=str_repeat('0',64);file_put_contents($path,json_encode($m));
        $this->assertFalse(app(LabInteriorRoomAssessments::class)->ready());
    }
    public function test_new_draft_budget_has_exact_second_ordinals_without_new_pose_or_third_image(): void
    {
        config(['iguide-lab.interior_floor_witness_enabled'=>true,'iguide-lab.interior_room_evidence_drafts_enabled'=>true]);
        $path=config('iguide-lab.interior_room_runtime_manifest');$manifest=json_decode(file_get_contents($path),true);
        $manifest['implementation']=app(LabInteriorRoomAssessments::class)->implementation();file_put_contents($path,json_encode($manifest));
        [,,$item,,,$record]=$this->fixture();$record=$this->finish($record);$route=app(LabInteriorRoomRoutes::class)->bind($item,$record);
        $p=\App\Services\IguideLab\LabRoomEvidenceDraftPolicy::class;$steps=app(LabWorkflowSteps::class);
        $this->assertSame($p::ROUTE_VERSION,$route['version']);$this->assertSame($p::BUDGET,$route['budget']);
        foreach(['room-framing','room-layout','room-candidate'] as $key)$steps->reserve($item,$key,'analysis',[]);
        $second=$steps->reserve($item,'room-candidate-2','analysis',['parents'=>['imageOrdinal'=>2],'protocolVersion'=>\App\Services\IguideLab\LabRoomEvidenceCandidateProtocol::VERSION]);
        $this->assertSame($second->id,$steps->reserve($item,'room-candidate-2','analysis',$second->input)->id);
        $this->rejects(fn()=>$steps->reserve($item,'room-candidate-3','analysis',[]));
        foreach([1,2] as $ordinal){$input=['roomEvidenceDraft'=>['version'=>$p::VERSION,'ordinal'=>$ordinal]];$key=$ordinal===1?'room-image':'room-image-2';
            $step=$steps->reserve($item,$key,'image',$input);$this->assertSame($step->request_id,$steps->reserve($item,$key,'image',$input)->request_id);}
        $this->rejects(fn()=>$steps->reserve($item,'room-image-3','image',['roomEvidenceDraft'=>['version'=>$p::VERSION,'ordinal'=>3]]));
        $steps->reserve($item,'room-section','section',[]);$this->rejects(fn()=>$steps->reserve($item,'room-section-2','section',[]));
        $steps->reserve($item,'room-floor','segmentation',[]);$steps->reserve($item,'room-objects','segmentation',[]);
        $this->rejects(fn()=>$steps->reserve($item,'room-extra-mask','segmentation',[]));Http::assertSentCount(1);
    }
    public function test_new_flag_does_not_expand_the_existing_material_branch_budget(): void
    {
        config(['iguide-lab.interior_floor_witness_enabled'=>true,'iguide-lab.interior_room_evidence_drafts_enabled'=>true]);
        $path=config('iguide-lab.interior_room_runtime_manifest');$m=json_decode(file_get_contents($path),true);$m['implementation']=app(LabInteriorRoomAssessments::class)->implementation();file_put_contents($path,json_encode($m));
        [,,$item,,,$record]=$this->fixture();$v=Fixtures::candidate($this->context->bundle['context']);
        $v['occupancy']['status']='furnished';$v['occupancy']['existingFurnishings']=[['description'=>'Existing bed and rug.','evidenceViewIds'=>['source']]];
        $v['materialPotential']['status']='supported';$v['materialPotential']['eligibleSurfaces']=[['description'=>'Original bed cloth.','evidenceViewIds'=>['source']],['description'=>'Original rug.','evidenceViewIds'=>['source']]];
        $v['materialPotential']['styleFeatures']=[['kind'=>'texture','description'=>'Woven fabric.'],['kind'=>'pattern','description'=>'Bounded rug pattern.']];
        $record=$this->finish($record,$v);$route=app(LabInteriorRoomRoutes::class)->bind($item,$record);
        $this->assertSame(LabInteriorRoomProfiles::VERSION,$route['version']);$this->assertSame(LabInteriorRoomProfiles::BUDGET,$route['budget']);
        $steps=app(LabWorkflowSteps::class);$steps->reserve($item,'interior-image','image',[]);
        $this->rejects(fn()=>$steps->reserve($item,'room-image-2','image',[]));$this->rejects(fn()=>$steps->reserve($item,'room-candidate-2','analysis',[]));
        Http::assertSentCount(1);
    }
    public function test_source_detail_policy_does_not_replace_the_furnishing_branch_keys(): void
    {
        [,$workflow,$item,,,$record]=$this->fixture();$record=$this->finish($record);$route=app(LabInteriorRoomRoutes::class)->bind($item,$record);
        $this->assertSame(LabInteriorRoomProfiles::BEDROOM,$route['profileId']);
        $policy=$workflow->fresh()->policy;$policy['interiorDesign']=\App\Services\IguideLab\LabInteriorDesignPolicy::snapshot();
        $policy['interiorAssessments']=['implementation'=>['sourceDetails'=>['version'=>\App\Services\IguideLab\LabInteriorMaterialDetails::VERSION]]];$workflow->update(['policy'=>$policy]);
        $steps=app(LabWorkflowSteps::class);foreach(['room-framing','room-layout','room-candidate'] as $key)$steps->reserve($item,$key,'analysis',[]);
        $this->assertSame(4,\App\Models\IguideLabWorkflowStep::where('item_id',$item->id)->where('kind','analysis')->count());
        $this->rejects(fn()=>$steps->reserve($item,'interior-cut-followup','analysis',['protocolVersion'=>\App\Services\IguideLab\LabInteriorMaterialSourceProtocol::VERSION,'contextSnapshot'=>['context'=>['sourceInspection'=>['followupOrdinal'=>1]]]]));
        $steps->reserve($item,'room-image','image',[]);$this->rejects(fn()=>$steps->reserve($item,'room-image-2','image',[]));Http::assertSentCount(1);
    }
    public function test_source_detail_material_route_has_five_closed_analysis_slots_and_one_image(): void
    {
        [,$workflow,$item,,,$record]=$this->fixture();$v=Fixtures::candidate($this->context->bundle['context']);
        $v['occupancy']['status']='furnished';$v['occupancy']['existingFurnishings']=[['description'=>'Existing bed and rug.','evidenceViewIds'=>['source']]];
        $v['materialPotential']['status']='supported';$v['materialPotential']['eligibleSurfaces']=[['description'=>'Original bed cloth.','evidenceViewIds'=>['source']],['description'=>'Original rug.','evidenceViewIds'=>['source']]];
        $v['materialPotential']['styleFeatures']=[['kind'=>'texture','description'=>'Woven fabric.'],['kind'=>'pattern','description'=>'Bounded rug pattern.']];
        $record=$this->finish($record,$v);$route=app(LabInteriorRoomRoutes::class)->bind($item,$record);
        $this->assertSame(LabInteriorRoomProfiles::MATERIALS,$route['profileId']);
        $policy=$workflow->fresh()->policy;$policy['interiorDesign']=\App\Services\IguideLab\LabInteriorDesignPolicy::snapshot();
        // Budget marker only: this test never sends a material assessment or claims a runtime proof.
        $policy['interiorAssessments']=['implementation'=>['sourceDetails'=>['version'=>\App\Services\IguideLab\LabInteriorMaterialDetails::VERSION]]];$workflow->update(['policy'=>$policy]);
        $steps=app(LabWorkflowSteps::class);foreach(['interior-framing','interior-cut','interior-candidate'] as $key)$steps->reserve($item,$key,'analysis',[]);
        $this->rejects(fn()=>$steps->reserve($item,'interior-cut-followup','analysis',[]));
        $input=['protocolVersion'=>\App\Services\IguideLab\LabInteriorMaterialSourceProtocol::VERSION,'contextSnapshot'=>['context'=>['sourceInspection'=>['followupOrdinal'=>1]]]];
        $step=$steps->reserve($item,'interior-cut-followup','analysis',$input);$this->assertSame($step->id,$steps->reserve($item,'interior-cut-followup','analysis',$input)->id);
        $this->assertSame(5,\App\Models\IguideLabWorkflowStep::where('item_id',$item->id)->where('kind','analysis')->count());
        $this->rejects(fn()=>$steps->reserve($item,'interior-cut-followup-2','analysis',$input));
        $this->rejects(fn()=>$steps->reserve($item,'room-candidate','analysis',[]));
        $steps->reserve($item,'interior-image','image',[]);$this->rejects(fn()=>$steps->reserve($item,'interior-image-2','image',[]));Http::assertSentCount(1);
    }
    public function test_requested_room_runtime_failure_does_not_capture_a_legacy_material_only_policy(): void
    {
        config(['iguide-lab.interior_design_workflow_enabled'=>true,'iguide-lab.interior_room_routing_enabled'=>true]);
        $this->mock(\App\Services\IguideLab\LabInteriorDesignWorkflow::class,function($m){$m->shouldReceive('ready')->andReturn(true);$m->shouldReceive('snapshot')->andReturn([]);});
        $this->mock(\App\Services\IguideLab\LabInteriorRoomWorkflow::class,function($m){$m->shouldReceive('ready')->andReturn(false);});
        $this->mock(\App\Services\IguideLab\LabInteriorDesignAssessments::class,function($m){$m->shouldReceive('snapshot')->andReturn([]);});
        $policy=app(\App\Services\IguideLab\LabWorkflowPolicy::class)->snapshot();
        $this->assertArrayNotHasKey('interiorDesign',$policy);$this->assertArrayNotHasKey('interiorRoomRouting',$policy);
        config(['iguide-lab.interior_room_routing_enabled'=>false]);
        $this->assertArrayHasKey('interiorDesign',app(\App\Services\IguideLab\LabWorkflowPolicy::class)->snapshot());
    }
    public function test_unknown_captured_room_policy_holds_without_dispatching_the_legacy_branch(): void
    {
        [,$workflow,$item]=$this->fixture();$policy=$workflow->policy;
        $policy['interiorDesign']=\App\Services\IguideLab\LabInteriorDesignPolicy::snapshot();
        $policy['interiorRoomRouting']['version']='unknown-room-policy/9';$workflow->update(['policy'=>$policy]);
        $this->mock(\App\Services\IguideLab\LabInteriorDesignWorkflow::class,function($m){$m->shouldNotReceive('advance');});
        app(LabWorkflows::class)->process($workflow->fresh());
        $this->assertSame('needs_review',$item->fresh()->status);Http::assertNothingSent();$this->assertSame(0,IguideLabRun::count());
    }
    public function test_bound_route_rejects_unknown_profile_mixed_source_style_and_receipt_identity(): void
    {
        [,,$item,,,$record]=$this->fixture();$record=$this->finish($record);$service=app(LabInteriorRoomRoutes::class);$binding=$service->bind($item,$record);$original=$item->fresh()->data;
        foreach(['profileId'=>'unknown/1','sourceSha256'=>str_repeat('f',64),'style'=>'farmhouse','requestId'=>(string)Str::uuid(),'responseSha256'=>str_repeat('b',64)] as $key=>$value){
            $data=$original;$data['interiorRoomRoute'][$key]=$value;$item->update(['data'=>$data]);$this->rejects(fn()=>$service->verify($item));
        }
        $item->update(['data'=>$original]);$this->assertSame($binding,$service->verify($item));Http::assertSentCount(1);
    }
    public function test_material_read_scope_rechecks_actual_route_receipt_after_normal_or_exception_exit_and_fresh_attempt(): void
    {
        [,,$item,,,$record]=$this->fixture();$value=Fixtures::candidate($this->context->bundle['context']);
        $value['occupancy']['status']='furnished';$value['occupancy']['existingFurnishings']=[['description'=>'Existing bed and rug.','evidenceViewIds'=>['source']]];
        $value['materialPotential']['status']='supported';$value['materialPotential']['eligibleSurfaces']=[['description'=>'Original bed cloth.','evidenceViewIds'=>['source']],['description'=>'Original rug.','evidenceViewIds'=>['source']]];
        $value['materialPotential']['styleFeatures']=[['kind'=>'texture','description'=>'Visible woven fabric.'],['kind'=>'pattern','description'=>'Bounded existing rug pattern.']];
        $record=$this->finish($record,$value);$routes=app(LabInteriorRoomRoutes::class);$binding=$routes->bind($item,$record);$item->refresh();
        $this->assertSame(LabInteriorRoomProfiles::MATERIALS,$binding['profileId']);
        $disk=Storage::disk(LabService::DISK);$path=$record->data['providerReceipt']['response']['path'];$raw=$disk->get($path);
        $scope=\App\Services\IguideLab\LabInteriorRoomReadScope::class;
        $scope::completed($item,function()use($routes,$item,$binding){$this->assertSame($binding,$routes->verify($item));$this->assertSame($binding,$routes->verify($item));});
        $disk->put($path,$raw.' ');$this->rejects(fn()=>$scope::completed($item,fn()=>$routes->verify($item)));$disk->put($path,$raw);
        try{$scope::completed($item,function()use($routes,$item){$routes->verify($item);throw new \RuntimeException('read aborted');});}catch(\RuntimeException){}
        $disk->put($path,$raw.' ');$this->rejects(fn()=>$routes->verify($item));$disk->put($path,$raw);
        $scope::completed($item,function()use($routes,$item,$scope,$disk,$path,$raw){
            $routes->verify($item);$disk->put($path,$raw.' ');
            $this->rejects(fn()=>$scope::fresh(fn()=>$routes->verify($item)));$disk->put($path,$raw);
        });$this->assertSame($binding,$routes->verify($item));Http::assertSentCount(1);
    }
    public function test_room_evidence_retains_maximum_valid_framing_reasons_and_status_within_quality_bounds(): void
    {
        [$project,,$item,$case]=$this->fixture();$disk=Storage::disk(LabService::DISK);$reason=str_repeat('r',550);
        $framingContext=\Tests\Unit\IguideLabRoomFurnishingProtocolTest::context();
        $v=\Tests\Unit\IguideLabRoomFurnishingProtocolTest::value(\App\Services\IguideLab\LabRoomFurnishingFramingProtocol::class,$framingContext);
        foreach($v['checks'] as &$check)$check['reason']=$reason;unset($check);
        $framing=\App\Services\IguideLab\LabRoomFurnishingFramingProtocol::parse(LabInteriorDesignProtocol::response($v),$framingContext);
        $base='iguide-lab/'.$project->id.'/workflow-items/'.$item->id;
        $disk->put($base.'/geometry.json','{}');$disk->put($base.'/alpha.png','fixture-alpha');
        $section=\App\Models\IguideLabSection::create(['project_id'=>$project->id,'case_id'=>$case->id,'request_id'=>(string)Str::uuid(),
            'request_hash'=>str_repeat('a',64),'source_sha256'=>$case->source_sha256,'view'=>['yaw'=>0,'pitch'=>-25,'hFov'=>100],
            'data'=>['geometryPath'=>$base.'/geometry.json','geometrySha256'=>hash('sha256','{}')]]);
        $candidate=new IguideLabRoomAssessment(['id'=>(string)Str::uuid(),'data'=>['contextSnapshot'=>['context'=>[
            'route'=>['result'=>['opticalDependencies'=>['reason'=>$reason]]],'framing'=>['result'=>$framing]]]]]);
        $composite=new \App\Models\IguideLabComposite(['id'=>(string)Str::uuid(),'preview_path'=>$case->source_path,'manifest'=>[
            'preview'=>['sha256'=>$case->source_sha256],'lineage'=>['candidateAssessmentId'=>$candidate->id,'profileId'=>LabInteriorRoomProfiles::BEDROOM,
                'sectionId'=>$section->id,'recipe'=>[],'effectiveAlpha'=>['path'=>$base.'/alpha.png','sha256'=>hash('sha256','fixture-alpha')]]]]);
        $this->mock(\App\Services\IguideLab\LabWorkflowSources::class,fn($m)=>$m->shouldReceive('verify')->andReturn($case));
        $this->mock(LabInteriorRoomAssessments::class,fn($m)=>$m->shouldReceive('completed')->andReturn($candidate));
        $this->mock(\App\Services\IguideLab\LabWorkflowEvaluations::class,fn($m)=>$m->shouldReceive('intent')->andReturn(['preset'=>'interior-design','style'=>'modern','instructions'=>'']));
        // Isolate text/evidence assembly. Full authority and real native processing have separate chain proofs.
        $artifacts=new class extends \App\Services\IguideLab\LabRoomFurnishingArtifacts {
            public array $report;
            public function verifyComposite(\App\Models\IguideLabWorkflowItem $item,\App\Models\IguideLabComposite $composite,\App\Models\IguideLabWorkflowStep $step,\App\Models\IguideLabRun $run): void {}
            protected function execute(string $path,array $request,array $snapshot): array {return $this->report;}
        };
        $out=$base.'/room-evidence-'.$composite->id;$disk->put($out.'/report.json','{}');$boards=[];$images=[];
        [, $fixtureImages]=Fixtures::fixture();$png=base64_decode(explode(',',$fixtureImages[0],2)[1]);
        foreach([...\App\Services\IguideLab\LabVisualQualityProtocol::VIEW_IDS,'detail_1'] as $id){
            $disk->put($out.'/'.$id.'.png',$png);$boards[]=['id'=>$id,'path'=>$disk->path($out.'/'.$id.'.png'),'sha256'=>hash('sha256',$png),
                'sourceLeftCandidateRight'=>true,'halves'=>[['originalAssetSha256'=>$case->source_sha256],['originalAssetSha256'=>$case->source_sha256]]];
            $images[]='data:image/png;base64,'.base64_encode($png);
        }
        $artifacts->report=['evidence'=>$boards,'qa'=>['numericIntegrity'=>'pass','reviewReasons'=>[]]];
        $evidence=$artifacts->roomEvidence($item,$composite,new \App\Models\IguideLabWorkflowStep,new IguideLabRun);
        $caveats=$evidence['context']['sourceCaveats'];
        foreach($framing['checks'] as $name=>$check)$this->assertContains('Framing '.$name.' ('.$check['status'].'): '.$reason,$caveats);
        $this->assertLessThanOrEqual(600,max(array_map('mb_strlen',$caveats)));
        $wire=\App\Services\IguideLab\LabVisualQualityProtocol::request($evidence['context'],$images);
        $this->assertSame($evidence['context'],json_decode($wire['input'][0]['content'][0]['text'],true));Http::assertNothingSent();
    }
    private function rejects(callable $f): void {try{$f();$this->fail('Invalid room authority accepted.');}catch(HttpException $e){$this->assertContains($e->getStatusCode(),[409,422,503]);}}
}
class RoomContextFixture extends LabInteriorRoomContext
{
    public array $bundle=[];
    public function capture(IguideLabWorkflowItem $item,string $phase,array $parents): array {return $this->bundle;}
}
