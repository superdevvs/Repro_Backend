<?php
namespace Tests\Unit;
use App\Services\IguideLab\{LabStagingLayoutProtocol as P,LabMachineSegmentationPlan};
use InvalidArgumentException;
use Tests\TestCase;

class IguideLabStagingLayoutPriorTest extends TestCase
{
    private function details():array {
        $fixture=new IguideLabStagingLayoutProtocolTest('test_initial_explicit_plan_is_not_actual_placement_or_mask_authority');
        [$recipe,$context,$images]=(new \ReflectionMethod($fixture,'fixture'))->invoke($fixture);
        (new \ReflectionMethod($fixture,'details'))->invokeArgs($fixture,[&$context,&$images]);
        return [$recipe,$context,$images];
    }
    public function test_followup_transmits_complete_bound_questions_without_truncation():void {
        [$recipe,$context,$images]=$this->details();$wire=P::compile($recipe,$context,$images);
        $sent=json_decode($wire['input'][0]['content'][0]['text'],true);
        $this->assertSame($context['stagingLayout']['priorResult'],$sent['stagingLayout']['priorResult']);
        $this->assertSame(LabMachineSegmentationPlan::canonicalHash($sent['stagingLayout']['priorResult']),$sent['stagingLayout']['followup']['previousResultSha256']);
    }
    public function test_prior_question_or_requested_detail_pose_drift_rejects_followup():void {
        [$recipe,$context,$images]=$this->details();
        foreach(['question','pose','source','ordinal'] as $change){$bad=$context;
            if($change==='question')$bad['stagingLayout']['priorResult']['layout']['detailRequests'][0]['reason']='A different optical question.';
            if($change==='pose')$bad['stagingLayout']['detailViews'][0]['pose']['yaw']=45;
            if($change==='source')$bad['stagingLayout']['priorResult']['layout']['nativeSourceSha256']=str_repeat('0',64);
            if($change==='ordinal')$bad['stagingLayout']['priorResult']['layout']['followupOrdinal']=1;
            // A rewritten hash cannot make wrong native ancestry or a second follow-up legitimate.
            if(in_array($change,['source','ordinal'],true))$bad['stagingLayout']['followup']['previousResultSha256']=LabMachineSegmentationPlan::canonicalHash($bad['stagingLayout']['priorResult']);
            try{P::compile($recipe,$bad,$images);$this->fail('Changed prior authority accepted: '.$change);}catch(InvalidArgumentException){$this->addToAssertionCount(1);}
        }
    }
}
