<?php
namespace Tests\Feature;

use App\Models\{IguideLabProject,IguideLabWorkflow,IguideLabWorkflowItem,IguideLabWorkflowStep,IguideLabRoomAssessment};
use App\Services\IguideLab\{LabService,LabWorkflowSources,LabWorkflowSteps,LabInteriorRoomPolicy,LabInteriorRoomProfiles as Profiles,
    LabInteriorRoomRouteProtocol as Route,LabRoomFurnishingFramingProtocol as Framing,LabRoomFurnishingLayoutProtocol as LegacyLayout,
    LabRoomFurnishingCandidateProtocol as LegacyCandidate,LabRoomEvidenceLayoutProtocol as Layout,LabRoomEvidenceCandidateProtocol as Candidate,
    LabRoomEvidenceDraftPolicy as Draft,LabMachineSegmentationPlan as H,LabInteriorDesignProtocol as V,LabAnalysisSourceContext};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Http,Queue,Storage};
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Unit\{IguideLabInteriorRoomRouteProtocolTest as RouteFixture,IguideLabRoomFurnishingProtocolTest as LegacyFixture,
    IguideLabRoomEvidenceProtocolTest as EvidenceFixture};

/** Synthetic persisted read states, parsed by owning protocols and read through the authenticated HTTP kernel.
 * These fixtures do not claim an executed provider, image, support, native composition or quality pipeline. */
class IguideLabRoomEvidenceDraftReadTest extends TestCase
{
    use RefreshDatabase;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp(); Queue::fake(); Http::preventStrayRequests();
        config(['app.key'=>'base64:'.base64_encode(str_repeat('r',32))]);
        $this->flags(false);
        if(function_exists('posix_getegid')) config(['filesystems.disks.'.LabService::DISK.'.shared_group'=>posix_getgrgid(posix_getegid())['name']]);
        Storage::fake(LabService::DISK,config('filesystems.disks.'.LabService::DISK))->makeDirectory('');
    }

    private function flags(bool $enabled): void
    {
        config(['iguide-lab.interior_room_evidence_drafts_enabled'=>$enabled,
            'iguide-lab.interior_floor_witness_enabled'=>$enabled,'iguide-lab.interior_room_routing_enabled'=>$enabled]);
    }

    private function ref(IguideLabRoomAssessment $record): array
    {return ['id'=>$record->id,'resultSha256'=>$record->result_sha256,'responseSha256'=>$record->data['providerReceipt']['response']['sha256'],'result'=>$record->result];}

    private function record(IguideLabWorkflowItem $item,string $key,array $context,?array $result,array $bundle=[]): IguideLabRoomAssessment
    {
        $phase=$key==='room-candidate-2'?'candidate':substr($key,5);
        $requestId=(string)Str::uuid(); $input=['phase'=>$phase,'contextSnapshot'=>['context'=>$context]+$bundle];
        $step=IguideLabWorkflowStep::create(['project_id'=>$item->project_id,'item_id'=>$item->id,'step_key'=>$key,'kind'=>'analysis',
            'request_id'=>$requestId,'input'=>$input,'input_sha256'=>H::canonicalHash($input),'data'=>[]]);
        $data=['contextSnapshot'=>$input['contextSnapshot'],'workflowInvocation'=>app(LabWorkflowSteps::class)->invocation($step),
            'recipe'=>['syntheticReadFixture'=>true],'privateFixtureMarker'=>'must-not-appear-in-public-dto'];
        if(in_array($context['version'],[Layout::VERSION,Candidate::VERSION],true)||($context['route']['binding']['version']??null)===Draft::ROUTE_VERSION){
            // Deliberately stale execution hash: display must not reauthorize or reject historical recipes.
            $snapshot=Draft::snapshot();$snapshot['implementationSha256']=str_repeat('f',64);
            $data['recipe']['implementation']['workflow']['evidenceDrafts']=$snapshot;
        }
        if($result!==null)$data['providerReceipt']=['response'=>['sha256'=>H::canonicalHash(V::response($result)),'path'=>'private/synthetic-response.json']];
        $record=IguideLabRoomAssessment::create(['id'=>$requestId,'project_id'=>$item->project_id,'step_id'=>$step->id,'request_id'=>$requestId,
            'request_hash'=>$step->input_sha256,'phase'=>$phase,'status'=>$result===null?'queued':'completed','data'=>$data,
            'result'=>$result,'result_sha256'=>$result===null?null:H::canonicalHash($result),'completed_at'=>$result===null?null:now()]);
        $step->update(['child_id'=>$record->id,'status'=>$result===null?'running':'completed']);return $record;
    }

    private function fixture(bool $draft): array
    {
        $this->token=Str::random(64);
        $project=IguideLabProject::create(['name'=>'Synthetic persisted furnishing read fixture','token_hash'=>hash('sha256',$this->token),
            'expires_at'=>now()->addDay(),'max_runs'=>10,'used_runs'=>0]);
        [$rc,$images]=RouteFixture::fixture();$bytes=base64_decode(explode(',',$images[0],2)[1]);
        $case=app(LabService::class)->addCase($project,UploadedFile::fake()->createWithContent('source.png',$bytes),
            ['label'=>'Synthetic original for read-only protocol fixtures','projection'=>'equirectangular','metadata'=>['fixture'=>'persisted-read-only']]);
        $source=app(LabWorkflowSources::class)->capture($case);
        $rc['nativeSourceSha256']=$case->source_sha256;
        $rc['sourceAuthoritySha256']=H::canonicalHash($source);
        $rc['roomMetadata']['roomName']='LIVING';$rc['metadataSha256']=H::canonicalHash($rc['roomMetadata']);
        $workflow=IguideLabWorkflow::create(['project_id'=>$project->id,'request_id'=>(string)Str::uuid(),'request_hash'=>hash('sha256','synthetic-read'),
            'status'=>'checking','policy'=>['interiorRoomRouting'=>LabInteriorRoomPolicy::snapshot()]]);
        $item=IguideLabWorkflowItem::create(['workflow_id'=>$workflow->id,'project_id'=>$project->id,'case_id'=>$case->id,'position'=>0,
            'preset'=>'interior-design','style'=>$rc['style'],'instructions'=>$rc['instructions'],'source'=>$source,'status'=>'checking','data'=>[]]);
        $routeValue=RouteFixture::candidate($rc);$routeValue['room']['function']='living';
        $route=$this->record($item,'room-route',$rc,Route::parse(V::response($routeValue),$rc));
        $binding=['version'=>$draft?Draft::ROUTE_VERSION:Profiles::VERSION,'assessmentId'=>$route->id,'requestId'=>$route->request_id,
            'resultSha256'=>$route->result_sha256,'responseSha256'=>$this->ref($route)['responseSha256'],'profileId'=>Profiles::LIVING,
            'profileSha256'=>H::canonicalHash(Profiles::get(Profiles::LIVING)),'sourceSha256'=>$case->source_sha256,
            'sourceAuthoritySha256'=>$rc['sourceAuthoritySha256'],'intentSha256'=>$rc['intentSha256'],'metadataSha256'=>$rc['metadataSha256'],
            'publicPreset'=>'interior-design','style'=>$rc['style'],'budget'=>$draft?Draft::BUDGET:Profiles::BUDGET,'operatorInterventions'=>0,'branchSwitchAllowed'=>false];
        $item->update(['data'=>['interiorRoomRoute'=>$binding]]);
        $fc=LegacyFixture::context('framing',Profiles::LIVING);
        foreach(['nativeSourceSha256','preparedSourceSha256','sourceAuthoritySha256','intentSha256','style','instructions'] as $k)$fc[$k]=$rc[$k];
        $fc['sourceSha256']=$case->source_sha256;$fc['route']=['binding'=>$binding,'result'=>$route->result];
        $framing=$this->record($item,'room-framing',$fc,Framing::parse(V::response(LegacyFixture::value(Framing::class,$fc)),$fc));
        if($draft)[$lc,$lv]=EvidenceFixture::layout();else{$lc=LegacyFixture::context('layout',Profiles::LIVING);$lv=LegacyFixture::value(LegacyLayout::class,$lc);}
        foreach(['nativeSourceSha256','sourceAuthoritySha256','intentSha256','style','instructions','route'] as $k)$lc[$k]=$fc[$k];
        $lc['framing']=$this->ref($framing);$lv['contextSha256']=H::canonicalHash($lc);
        if($draft){$lv['draftEvidence']['sourceBindingSha256']=$lv['contextSha256'];
            $lv['opticalStatus']='uncertain';$lv['checks']['optical_dependencies']['status']='uncertain';
            $lv['draftEvidence']['candidateQuestions'][]=['id'=>'optical_dependencies','reason'=>'Synthetic observable receiver question requiring actual-candidate closure.'];}
        $sectionId=(string)Str::uuid();$cutId=(string)Str::uuid();$geometry=hash('sha256','synthetic geometry descriptor only');
        $parent=['version'=>LabAnalysisSourceContext::VERSION,'sectionId'=>$sectionId,'nativeCaseId'=>$case->id,'visibleParentCaseId'=>$case->id,
            'sourceSha256'=>$lc['sourceSha256'],'nativeSourceSha256'=>$case->source_sha256,'nativeDimensions'=>['width'=>2048,'height'=>1024],
            'geometrySha256'=>$geometry,'view'=>$framing->result['view']+['width'=>2048,'height'=>2048],
            'sourceOrigin'=>'uploaded_native_attachment','sourceRole'=>'authoritative_edit_cut','contextRole'=>'same_capture_context_only',
            'scopeExpansion'=>false,'independentCameraEvidence'=>false,'dedicatedPoleViews'=>false,'viewRecipe'=>LabAnalysisSourceContext::VIEW_RECIPE,
            'preparationHashes'=>app(LabAnalysisSourceContext::class)->preparationHashes()];
        $bundle=['cutCaseId'=>$cutId,'sectionId'=>$sectionId,'geometry'=>['sha256'=>$geometry],
            'sourceContextSnapshot'=>['binding'=>$parent,'bindingSha256'=>H::canonicalHash($parent)]];
        $lp=$draft?Layout::class:LegacyLayout::class;$layout=$this->record($item,'room-layout',$lc,$lp::parse(V::response($lv),$lc),$bundle);
        $cc=$lc;$cc['version']=$draft?Candidate::VERSION:LegacyCandidate::VERSION;$cc['phase']='candidate';$cc['layout']=$this->ref($layout);
        $cc['candidateRunId']=(string)Str::uuid();$cc['candidateSha256']=hash('sha256','synthetic first candidate read identity');
        array_splice($cc['views'],1,0,[['id'=>'candidate','sha256'=>$cc['candidateSha256'],'width'=>2048,'height'=>2048]]);
        if($draft){$cc['imageOrdinal']=1;$cc['correctionParent']=null;}
        $cv=$this->candidateValue($cc,$draft);$cp=$draft?Candidate::class:LegacyCandidate::class;
        if($draft){$cv['status']='needs_review';$cv['objects']=[];$cv['sourceFloor']=null;$cv['styleFeatures']=[];$cv['checks']['geometry']['status']='fail';
            $cv['obligationClosures'][0]['status']='fail';$cv['correction']=['decision'=>'one_correction','defects'=>[['checkId'=>'geometry','kind'=>'frame_margin',
                'objectIds'=>['sofa'],'sourceAlternative'=>'Use a smaller supported sofa inside the same original source envelope.']]];}
        $first=$this->record($item,'room-candidate',$cc,$cp::parse(V::response($cv),$cc),$bundle);
        $item->update(['attempt_count'=>1]);
        return compact('project','workflow','item','route','framing','layout','first','cc','bundle');
    }

    private function candidateValue(array $c,bool $draft): array
    {
        $p=$draft?Candidate::class:LegacyCandidate::class;$v=LegacyFixture::value($p,$c);$v['sourceFloor']=$c['layout']['result']['sourceFloor'];
        if($draft){foreach($v['objects'] as &$o)$o['supportForm']='narrow_terminals';unset($o);$v['draftEvidence']=null;
            $v['obligationClosures']=array_map(fn($id)=>['id'=>$id,'status'=>'pass',
                'evidenceViewIds'=>$id==='verify_optical_dependencies'?Candidate::ids():['source','candidate'],
                'reason'=>'Synthetic current-candidate observation for read compatibility only.'],$c['layout']['result']['draftDisposition']['obligationIds']);
            $v['correction']=['decision'=>'none','defects'=>[]];}
        return $v;
    }

    private function read(IguideLabWorkflow $workflow,string $name): array
    {
        $response=$this->withToken($this->token)->getJson('/api/iguide-lab/workflows/'.$workflow->id)->assertOk();
        $this->export($name,$response->json());return $response->json('data');
    }
    private function export(string $name,array $value): void
    {
        $json=json_encode($value,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
        $this->assertStringNotContainsString($this->token,$json);$this->assertStringNotContainsString('must-not-appear-in-public-dto',$json);
        $this->assertStringNotContainsString('private/synthetic-response.json',$json);
        if($directory=getenv('IGUIDE_ROOM_READ_EXPORT_DIR')){
            if(!is_dir($directory)&&!mkdir($directory,0770,true)&&!is_dir($directory))throw new \RuntimeException('Cannot create read fixture export directory.');
            file_put_contents($directory.'/'.$name.'.json',$json);
            file_put_contents($directory.'/scope.json',json_encode(['version'=>1,'scope'=>'Synthetic persisted protocol states through authenticated actual controllers. No executed generation, support, native composition or quality claim.',
                'providerCalls'=>0,'publicWriteAuthority'=>false],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
        }
    }

    public function test_corrected_candidate_history_remains_readable_with_flags_off_and_never_implies_ready(): void
    {
        $f=$this->fixture(true);$first=$f['first'];$c=$f['cc'];$c['imageOrdinal']=2;$c['correctionParent']=$this->ref($first);
        $c['candidateRunId']=(string)Str::uuid();$c['candidateSha256']=hash('sha256','synthetic second candidate read identity');$c['views'][1]['sha256']=$c['candidateSha256'];
        $second=$this->record($f['item'],'room-candidate-2',$c,null,$f['bundle']);
        $f['item']->update(['attempt_count'=>2]);
        $historicalRecords=IguideLabRoomAssessment::orderBy('id')->get()->toArray();
        $this->flags(true);$before=$this->read($f['workflow'],'correction-queued-enabled');$this->flags(false);
        $queued=$this->read($f['workflow'],'correction-queued-disabled');$this->assertSame($before,$queued);
        $this->assertSame($historicalRecords,IguideLabRoomAssessment::orderBy('id')->get()->toArray());
        $routing=$queued['items'][0]['roomRouting'];$this->assertSame(Draft::ROUTE_VERSION,$routing['version']);$this->assertCount(5,$routing['assessments']);
        $dto=collect($routing['assessments'])->firstWhere('id',$second->id);$this->assertSame('queued',$dto['status']);$this->assertNull($dto['result']);
        $this->assertSame(2,$dto['provenance']['imageOrdinal']);$this->assertSame(array_diff_key($this->ref($first),['result'=>true]),$dto['provenance']['correctionParent']);
        $this->export('assessment-correction-queued',['data'=>$dto]);
        $framing=collect($routing['assessments'])->firstWhere('phase','framing');$this->assertSame(Framing::VERSION,$framing['version']);
        $this->assertSame(Draft::READ_VERSION,$framing['provenance']['version']);$this->assertNull($framing['provenance']['imageOrdinal']);$this->assertNull($framing['provenance']['correctionParent']);
        $this->export('assessment-framing-route2',['data'=>$framing]);
        $result=Candidate::parse(V::response($this->candidateValue($c,true)),$c);$data=$second->data;$data['providerReceipt']=['response'=>['sha256'=>H::canonicalHash(V::response($result))]];
        $second->update(['status'=>'completed','result'=>$result,'result_sha256'=>H::canonicalHash($result),'data'=>$data,'completed_at'=>now()]);
        $completed=$this->read($f['workflow'],'correction-completed-disabled');$dto=collect($completed['items'][0]['roomRouting']['assessments'])->firstWhere('id',$second->id);
        $this->assertSame('ready_for_masks',$dto['result']['status']);$this->assertSame(Draft::READ_VERSION,$dto['provenance']['version']);
        foreach(['imageGenerationAuthorized','nativeCompositionAuthorized','automaticAcceptance'] as $key)$this->assertFalse($dto[$key]);
        $this->assertSame('checking',$completed['items'][0]['status']);$this->assertSame(0,$completed['counts']['ready']);$this->assertNull($completed['items'][0]['result']);
        $this->export('assessment-correction-completed',['data'=>$dto]);
        $bad=$dto;$bad['result']['summary'].=' Changed without updating the digest.';$this->export('negative-changed-result-hash',['data'=>$bad]);
        $bad=$dto;$bad['provenance']['imageOrdinal']=1;$this->export('negative-correction-ordinal',['data'=>$bad]);
        $bad=$dto;foreach($bad['result']['obligationClosures'] as &$closure)if($closure['id']==='verify_optical_dependencies')$closure['evidenceViewIds']=['source','candidate'];unset($closure);
        $bad['resultSha256']=H::canonicalHash($bad['result']);$this->export('negative-optical-closure-membership',['data'=>$bad]);
        $held=$this->candidateValue($c,true);$held['status']='needs_review';$held['objects']=[];$held['sourceFloor']=null;$held['styleFeatures']=[];
        $held['checks']['optical_dependencies']['status']='uncertain';$held['opticalStatus']='uncertain';$held=Candidate::parse(V::response($held),$c);
        $data=$second->fresh()->data;$data['providerReceipt']['response']['sha256']=H::canonicalHash(V::response($held));
        $second->update(['result'=>$held,'result_sha256'=>H::canonicalHash($held),'data'=>$data]);$f['item']->update(['status'=>'needs_review']);$f['workflow']->update(['status'=>'needs_review']);
        $heldRead=$this->read($f['workflow'],'correction-held-disabled');$this->assertSame(0,$heldRead['counts']['ready']);
        $this->export('assessment-correction-held',['data'=>collect($heldRead['items'][0]['roomRouting']['assessments'])->firstWhere('id',$second->id)]);
        $index=$this->withToken($this->token)->getJson('/api/iguide-lab/workflows')->assertOk()->json();$this->export('workflow-index-disabled',$index);
        $project=$this->withToken($this->token)->getJson('/api/iguide-lab')->assertOk()->json();$this->export('project-index-disabled',$project);
        $this->assertSame(0,$project['data']['project']['usedRuns']);Http::assertNothingSent();Queue::assertNothingPushed();
    }

    public function test_legacy_read_shape_and_authorized_project_boundary_remain_closed(): void
    {
        $f=$this->fixture(false);$before=$this->read($f['workflow'],'legacy-disabled');$this->flags(true);$after=$this->read($f['workflow'],'legacy-enabled');$this->assertSame($before,$after);
        $routing=$after['items'][0]['roomRouting'];$this->assertSame(Profiles::VERSION,$routing['version']);$this->assertCount(4,$routing['assessments']);
        foreach($routing['assessments'] as $a){$this->assertSame('interior-room-assessment-read/1',$a['provenance']['version']);
            $this->assertArrayNotHasKey('imageOrdinal',$a['provenance']);$this->assertArrayNotHasKey('correctionParent',$a['provenance']);}
        $this->withHeader('Authorization','')->getJson('/api/iguide-lab/workflows/'.$f['workflow']->id)->assertUnauthorized();
        $foreign=Str::random(64);IguideLabProject::create(['name'=>'Foreign','token_hash'=>hash('sha256',$foreign),'expires_at'=>now()->addDay(),'max_runs'=>1]);
        $this->withToken($foreign)->getJson('/api/iguide-lab/workflows/'.$f['workflow']->id)->assertNotFound();
        $this->withToken($this->token)->postJson('/api/iguide-lab/workflows',['requestId'=>(string)Str::uuid(),'items'=>[],
            'roomRouting'=>['version'=>Draft::ROUTE_VERSION,'automaticAcceptance'=>true]])->assertUnprocessable();
        $data=$f['first']->data;$data['contextSnapshot']['context']['version']='interior-room-actual-candidate/unknown';$f['first']->update(['data'=>$data]);
        $this->withToken($this->token)->getJson('/api/iguide-lab/workflows/'.$f['workflow']->id)->assertUnprocessable();
        Http::assertNothingSent();Queue::assertNothingPushed();
    }
}
