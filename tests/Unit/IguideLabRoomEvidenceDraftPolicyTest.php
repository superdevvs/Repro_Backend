<?php
namespace Tests\Unit;
use App\Services\IguideLab\LabRoomEvidenceDraftPolicy as P;
use PHPUnit\Framework\TestCase;
final class IguideLabRoomEvidenceDraftPolicyTest extends TestCase
{
    private const PROFILE='empty-living-three-piece/1';
    private const SHA='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private function finding(string $status='pass',bool $candidate=false): array
    {return ['status'=>$status,'evidenceViewIds'=>$candidate?['source','candidate','parent_source','parent_front','parent_right','parent_back','parent_left']:['source','parent_source','parent_front','parent_right','parent_back','parent_left'],'reason'=>'Synthetic fixture evidence; no real semantic success claim.'];}
    private function source(): array
    {return ['version'=>P::VERSION,'sourceBindingSha256'=>self::SHA,'sourceChecks'=>array_fill_keys(P::SOURCE_CHECKS,$this->finding()),
        'anchors'=>array_map(fn($id)=>['objectId'=>$id,'sourceAnchor'=>'Visible fixture wall and original empty floor.','orientation'=>'Facing toward the fixture room centre.','knownExclusions'=>'Preserve visible door approach and all fixed features.'],['sofa','coffee_table','chair']),
        'candidateQuestions'=>[['id'=>'frame_margin','reason'=>'Actual generated extent remains to be inspected.'],['id'=>'optical_dependencies','reason'=>'Observable source receiver exists; the actual candidate must resolve its effect.']]];}
    private function candidate(): array
    {$v=['version'=>P::VERSION,'status'=>'needs_review','checks'=>array_fill_keys(P::CANDIDATE_CHECKS,$this->finding('pass',true)),
        'foregroundStatus'=>'none_observed','opticalStatus'=>'no_required_changes','contactShadowStatus'=>'tiny_local_only',
        'correction'=>['decision'=>'one_correction','defects'=>[['checkId'=>'geometry','kind'=>'frame_margin','objectIds'=>['sofa'],'sourceAlternative'=>'Synthetic actual typed finding: reduce extent within the same original visible floor region without changing source pose.']]]];
        $v['checks']['geometry']=$this->finding('fail',true);return $v;}
    public function test_candidate_questions_enable_only_evidence_draft_and_never_geometry_or_composition(): void
    {$r=P::source($this->source(),self::SHA,self::PROFILE);$this->assertSame('evidence_draft',$r['disposition']);$this->assertSame(['verify_frame_margin','verify_optical_dependencies'],$r['obligationIds']);
        $this->assertFalse($r['sourceGeometryFitProven']);$this->assertTrue($r['floorReceiptStillRequired']);$this->assertFalse($r['nativeCompositionAuthorized']);$this->assertFalse($r['automaticAcceptance']);}
    public function test_source_known_invalidity_and_missing_receiver_are_holds_not_questions(): void
    {foreach(['known_obstruction'=>'fail','receiver_observability'=>'uncertain','source_floor'=>'uncertain'] as $id=>$status){$v=$this->source();$v['sourceChecks'][$id]['status']=$status;$v['anchors']=[];$v['candidateQuestions']=[];
        $r=P::source($v,self::SHA,self::PROFILE);$this->assertSame('blocked',$r['disposition']);$this->assertSame([$id],$r['blockedChecks']);}}
    public function test_blocked_source_cannot_smuggle_placement(): void
    {$v=$this->source();$v['sourceChecks']['known_obstruction']['status']='fail';$this->expectException(\InvalidArgumentException::class);P::source($v,self::SHA,self::PROFILE);}
    public function test_old_record_binding_cannot_be_reinterpreted(): void
    {$v=$this->source();$v['version']='interior-room-source-layout/1';$this->expectException(\InvalidArgumentException::class);P::source($v,self::SHA,self::PROFILE);}
    public function test_actual_hash_drift_rejects(): void
    {$this->expectException(\InvalidArgumentException::class);P::source($this->source(),str_repeat('b',64),self::PROFILE);}
    public function test_material_branch_is_not_enlarged(): void
    {$this->expectException(\InvalidArgumentException::class);P::source($this->source(),self::SHA,'existing-materials/1');}
    public function test_question_order_is_canonical_but_duplicate_is_invalid(): void
    {$v=$this->source();$a=P::source($v,self::SHA,self::PROFILE);$v['candidateQuestions']=array_reverse($v['candidateQuestions']);$this->assertSame($a,P::source($v,self::SHA,self::PROFILE));
        $v['candidateQuestions'][]=$v['candidateQuestions'][0];$this->expectException(\InvalidArgumentException::class);P::source($v,self::SHA,self::PROFILE);}
    public function test_optical_source_requires_all_supplied_context(): void
    {$v=$this->source();$v['sourceChecks']['receiver_observability']['evidenceViewIds']=['source'];$this->expectException(\InvalidArgumentException::class);P::source($v,self::SHA,self::PROFILE);}
    public function test_closure_requires_exact_bijection_and_this_actual_candidate(): void
    {$ids=['verify_frame_margin','verify_optical_dependencies'];$rows=array_map(fn($id)=>['id'=>$id]+$this->finding('pass',true),$ids);
        $this->assertTrue(P::closures(array_reverse($rows),$ids));$rows[1]['status']='uncertain';$this->assertFalse(P::closures($rows,$ids));
        $rows[1]=$rows[0];$this->expectException(\InvalidArgumentException::class);P::closures($rows,$ids);}
    public function test_missing_closure_rejects(): void
    {$this->expectException(\InvalidArgumentException::class);P::closures([],['verify_frame_margin']);}
    public function test_optical_closure_requires_every_parent_even_when_main_check_would_pass(): void
    {$row=['id'=>'verify_optical_dependencies']+$this->finding('pass',true);$row['evidenceViewIds']=['source','candidate'];
        $this->expectException(\InvalidArgumentException::class);P::closures([$row],['verify_optical_dependencies']);}
    public function test_source_only_citation_is_not_actual_candidate_closure(): void
    {$this->expectException(\InvalidArgumentException::class);P::closures([['id'=>'verify_frame_margin']+$this->finding()],['verify_frame_margin']);}
    public function test_one_observable_completed_failure_allows_only_ordinal_two(): void
    {$r=P::correction($this->candidate(),1,self::PROFILE,[],[]);$this->assertTrue($r['eligible']);$this->assertSame(2,$r['nextImageOrdinal']);$this->assertFalse($r['priorCandidatePassesReusable']);$this->assertFalse($r['nativeCompositionAuthorized']);
        $again=P::correction($this->candidate(),2,self::PROFILE,[],[]);$this->assertFalse($again['eligible']);$this->assertContains('IMAGE_LIMIT_REACHED',$again['holdReasons']);}
    public function test_actual_uncertainty_or_unsupported_reflection_cannot_retry(): void
    {$v=$this->candidate();$v['checks']['optical_dependencies']['status']='uncertain';$r=P::correction($v,1,self::PROFILE,[],[]);$this->assertFalse($r['eligible']);$this->assertContains('CANDIDATE_UNCERTAINTY_REMAINS',$r['holdReasons']);
        $v=$this->candidate();$v['opticalStatus']='unsupported';$this->assertFalse(P::correction($v,1,self::PROFILE,[],[])['eligible']);}
    public function test_source_floor_failure_is_not_repairable_and_exact_failure_set_required(): void
    {$v=$this->candidate();$v['checks']['source_floor']['status']='fail';$r=P::correction($v,1,self::PROFILE,[],[]);$this->assertFalse($r['eligible']);$this->assertContains('UNSUPPORTED_REPAIR_CLASS',$r['holdReasons']);
        $v=$this->candidate();$v['checks']['circulation']['status']='fail';$this->assertFalse(P::correction($v,1,self::PROFILE,[],[])['eligible']);}
    public function test_duplicate_defect_cannot_look_like_full_coverage(): void
    {$v=$this->candidate();$v['correction']['defects'][]=$v['correction']['defects'][0];$this->expectException(\InvalidArgumentException::class);P::correction($v,1,self::PROFILE,[],[]);}
    public function test_no_partial_pass_mix_to_authorize_masks(): void
    {$v=$this->candidate();$v['status']='ready_for_masks';$v['correction']=['decision'=>'none','defects'=>[]];$this->expectException(\InvalidArgumentException::class);P::correction($v,1,self::PROFILE,[],[]);}
    public function test_final_support_bounds_and_explicit_budget_unchanged_in_helper(): void
    {$this->assertSame(['analysis'=>5,'image'=>2,'segmentation'=>2,'section'=>2,'composition'=>1,'evaluation'=>1],P::BUDGET);
        $v=$this->candidate();$v['status']='ready_for_masks';$v['checks']['geometry']['status']='pass';$v['correction']=['decision'=>'none','defects'=>[]];$this->assertFalse(P::correction($v,1,self::PROFILE,[],[])['eligible']);}
    public function test_inherited_optical_uncertainty_cannot_be_omitted_from_correction(): void
    {$v=$this->candidate();$rows=[['id'=>'verify_optical_dependencies']+$this->finding('uncertain',true)];
        $r=P::correction($v,1,self::PROFILE,$rows,['verify_optical_dependencies']);$this->assertFalse($r['eligible']);
        $this->assertContains('OBLIGATION_UNCERTAINTY_REMAINS',$r['holdReasons']);$this->assertContains('OBLIGATION_CHECK_CONTRADICTION',$r['holdReasons']);}
    public function test_inherited_repairable_guard_failure_needs_the_same_actual_failed_check(): void
    {$v=$this->candidate();$rows=[['id'=>'verify_frame_margin']+$this->finding('fail',true)];
        $this->assertTrue(P::correction($v,1,self::PROFILE,$rows,['verify_frame_margin'])['eligible']);
        $this->expectException(\InvalidArgumentException::class);P::correction($v,1,self::PROFILE,[],['verify_frame_margin']);}

}
