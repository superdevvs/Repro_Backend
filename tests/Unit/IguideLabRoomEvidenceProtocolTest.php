<?php
namespace Tests\Unit;
use App\Services\IguideLab\{LabInteriorDesignProtocol as V,LabInteriorRoomProfiles as R,LabMachineSegmentationPlan as H,
    LabRoomEvidenceDraftPolicy as P,LabRoomEvidenceLayoutProtocol as L,LabRoomEvidenceCandidateProtocol as C,LabRoomFloorWitness as W};
use Tests\TestCase;
class IguideLabRoomEvidenceProtocolTest extends TestCase
{
    public static function layout(): array
    {
        $c=IguideLabRoomFurnishingProtocolTest::context('layout',R::LIVING);$c['version']=L::VERSION;$c['imageOrdinal']=null;$c['correctionParent']=null;
        $c['route']['binding']['version']=P::ROUTE_VERSION;$c['route']['binding']['budget']=P::BUDGET;
        $v=IguideLabRoomFurnishingProtocolTest::value(L::class,$c);$v['sourceFloor']['prompt']=W::LABEL;foreach($v['objects'] as &$o)$o['supportForm']='narrow_terminals';unset($o);
        $v['checks']['frame_margin']['status']='uncertain';$v['checks']['frame_margin']['reason']='Actual extent must be assessed after a visually plausible bounded draft.';
        $v['draftEvidence']=['version'=>P::VERSION,'sourceBindingSha256'=>H::canonicalHash($c),
            'sourceChecks'=>array_fill_keys(P::SOURCE_CHECKS,['status'=>'pass','evidenceViewIds'=>L::ids(),'reason'=>'Synthetic visible original source constraint; not real fit evidence.']),
            'anchors'=>array_map(fn($id)=>['objectId'=>$id,'sourceAnchor'=>'Visible fixture wall and floor.','orientation'=>'Toward the fixture table.','knownExclusions'=>'Keep the original doorway approach clear.'],R::get(R::LIVING)['roles']),
            'candidateQuestions'=>[['id'=>'frame_margin','reason'=>'Actual generated extents are not yet observed.']]];
        $v['obligationClosures']=[];$v['correction']=['decision'=>'none','defects'=>[]];return [$c,$v];
    }
    public static function candidate(): array
    {
        [$c,$v]=self::layout();$layout=L::parse(V::response($v),$c);$c['version']=C::VERSION;$c['phase']='candidate';$c['imageOrdinal']=1;
        $c['layout']=['id'=>'25735e25-bcc6-4ff4-9764-d2718fd12aeb','resultSha256'=>H::canonicalHash($layout),'responseSha256'=>str_repeat('e',64),'result'=>$layout];
        $c['candidateRunId']='90997bff-8268-4646-b876-b83d141f35b7';$c['candidateSha256']=str_repeat('7',64);
        array_splice($c['views'],1,0,[['id'=>'candidate','sha256'=>$c['candidateSha256'],'width'=>2048,'height'=>2048]]);
        $v=IguideLabRoomFurnishingProtocolTest::value(C::class,$c);$v['sourceFloor']=$layout['sourceFloor'];foreach($v['objects'] as &$o)$o['supportForm']='narrow_terminals';unset($o);$v['draftEvidence']=null;
        $v['obligationClosures']=[['id'=>'verify_frame_margin','status'=>'pass','evidenceViewIds'=>['source','candidate'],'reason'=>'Synthetic current candidate fits unchanged guard.']];
        $v['correction']=['decision'=>'none','defects'=>[]];return [$c,$v];
    }
    private function rejects(callable $f): void
    {try{$f();$this->fail('Invalid evidence was accepted.');}catch(\InvalidArgumentException|\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertTrue(true);}}
    public function test_source_plausibility_is_draft_eligibility_without_realized_fit_claim(): void
    {[$c,$v]=self::layout();$r=L::parse(V::response($v),$c);$this->assertSame('ready_for_image',$r['status']);$this->assertSame('evidence_draft',$r['draftDisposition']['disposition']);
        $this->assertFalse($r['draftDisposition']['sourceGeometryFitProven']);$this->assertFalse($r['automaticAcceptance']);$this->assertSame(['verify_frame_margin'],$r['draftDisposition']['obligationIds']);}
    public function test_missing_source_evidence_or_known_failure_cannot_be_relabelled_question(): void
    {[$c,$v]=self::layout();foreach(['source_floor','receiver_observability','known_obstruction'] as $id){$b=$v;$b['draftEvidence']['sourceChecks'][$id]['status']='uncertain';$this->rejects(fn()=>L::parse(V::response($b),$c));}
        $b=$v;$b['checks']['circulation']['status']='fail';$this->rejects(fn()=>L::parse(V::response($b),$c));
        $b=$v;$b['checks']['protected_features']['status']='uncertain';$this->rejects(fn()=>L::parse(V::response($b),$c));}
    public function test_observable_optical_question_can_be_inherited_but_never_supported_by_absent_context(): void
    {[$c,$v]=self::layout();$v['opticalStatus']='uncertain';$v['checks']['optical_dependencies']['status']='uncertain';
        $v['draftEvidence']['candidateQuestions'][]=['id'=>'optical_dependencies','reason'=>'Actual group effect on an observable receiver needs candidate evidence.'];
        $r=L::parse(V::response($v),$c);$this->assertContains('verify_optical_dependencies',$r['draftDisposition']['obligationIds']);
        $v['draftEvidence']['sourceChecks']['receiver_observability']['evidenceViewIds']=['source'];$this->rejects(fn()=>L::parse(V::response($v),$c));}
    public function test_held_source_keeps_no_executable_boxes(): void
    {[$c,$v]=self::layout();$v['status']='needs_review';$v['objects']=[];$v['sourceFloor']=null;$v['styleFeatures']=[];
        $v['draftEvidence']['sourceChecks']['known_obstruction']['status']='fail';$v['draftEvidence']['anchors']=[];$v['draftEvidence']['candidateQuestions']=[];
        $this->assertSame('blocked',L::parse(V::response($v),$c)['draftDisposition']['disposition']);}
    public function test_all_final_candidate_checks_and_exact_closures_remain_mandatory(): void
    {[$c,$v]=self::candidate();$r=C::parse(V::response($v),$c);$this->assertSame('ready_for_masks',$r['status']);$this->assertFalse($r['repairDecision']['eligible']);
        foreach(['geometry','visible_contacts','source_floor','optical_dependencies'] as $id){$b=$v;$b['checks'][$id]['status']='uncertain';$this->rejects(fn()=>C::parse(V::response($b),$c));}
        $b=$v;$b['obligationClosures']=[];$this->rejects(fn()=>C::parse(V::response($b),$c));
        $b=$v;$b['obligationClosures'][0]['status']='uncertain';$this->rejects(fn()=>C::parse(V::response($b),$c));}
    public function test_completed_guard_failure_can_propose_one_correction_but_no_pass_mixing(): void
    {[$c,$v]=self::candidate();$v['status']='needs_review';$v['objects']=[];$v['sourceFloor']=null;$v['styleFeatures']=[];$v['checks']['geometry']['status']='fail';$v['obligationClosures'][0]['status']='fail';
        $v['correction']=['decision'=>'one_correction','defects'=>[['checkId'=>'geometry','kind'=>'frame_margin','objectIds'=>['sofa'],'sourceAlternative'=>'Synthetic typed alternative within unchanged source-floor witness.']]];
        $first=C::parse(V::response($v),$c);$this->assertTrue($first['repairDecision']['eligible']);
        $c['imageOrdinal']=2;$c['correctionParent']=['id'=>'c2f8fef1-4676-4db9-a2c0-1b5be785bd63','resultSha256'=>H::canonicalHash($first),'responseSha256'=>str_repeat('c',64),'result'=>$first];
        $v['contextSha256']=H::canonicalHash($c);$v['correction']=['decision'=>'none','defects'=>[]];$second=C::parse(V::response($v),$c);
        $this->assertFalse($second['repairDecision']['eligible']);$this->assertContains('IMAGE_LIMIT_REACHED',$second['repairDecision']['holdReasons']);}
    public function test_bohemian_like_contact_uncertainty_and_nonlocal_rendering_do_not_authorize_correction(): void
    {[$c,$v]=self::candidate();$v['status']='needs_review';$v['objects']=[];$v['sourceFloor']=null;$v['styleFeatures']=[];$v['checks']['geometry']['status']='fail';$v['checks']['visible_contacts']['status']='uncertain';
        $v['checks']['optical_dependencies']['status']='fail';$v['contactShadowStatus']='nonlocal_required';$v['obligationClosures'][0]['status']='fail';
        $r=C::parse(V::response($v),$c);$this->assertFalse($r['repairDecision']['eligible']);$this->assertFalse($r['automaticAcceptance']);}
    public function test_legacy_or_drifted_layout_cannot_enter_new_candidate_authority(): void
    {[$c,$v]=self::candidate();$c['layout']['result']['version']='interior-room-source-layout/1';$c['layout']['resultSha256']=H::canonicalHash($c['layout']['result']);$this->rejects(fn()=>C::context($c));}
    public function test_living_cannot_select_bedroom_plinth_or_assume_every_physical_leg(): void
    {[$c,$v]=self::layout();$v['objects'][0]['supportForm']='visible_plinth';$this->rejects(fn()=>L::parse(V::response($v),$c));
        $this->assertStringContainsString('ordinary',$s=strtolower(C::instructions()));$this->assertStringContainsString('does not verify every physical leg',$s);
        $this->assertStringContainsString('Broad shading visible only in donor floor/background',C::instructions());}

}
