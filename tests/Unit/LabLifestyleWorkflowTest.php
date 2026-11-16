<?php
namespace Tests\Unit;
use App\Models\IguideLabWorkflowStep;
use App\Services\IguideLab\{LabLifestyleWorkflow,LabLifestyleCutReadiness,LabLifestyleSupport,LabLifestyleAssessments,LabLifestyleSegmentationAuthority,LabMachineSegmentationCapabilities,LabWorkflowPolicy};
use Tests\TestCase;

class LabLifestyleWorkflowTest extends TestCase
{
    public function test_the_initial_recipe_requires_two_individual_additions_and_rejects_scope_expansion(): void
    {
        $driver=app(LabLifestyleWorkflow::class);
        $proposal=['targets'=>array_map(fn($id)=>['id'=>$id,'action'=>'add','objectCount'=>1],['adult_1','adult_2'])];
        $this->assertTrue($driver->twoTargets($proposal));
        $extra=$proposal;$extra['targets'][]=['id'=>'rug','action'=>'add','objectCount'=>1];$this->assertFalse($driver->twoTargets($extra));
        $extra=$proposal;$extra['targets'][0]['objectCount']=2;$this->assertFalse($driver->twoTargets($extra));
        $extra=$proposal;$extra['targets'][0]['action']='restyle';$this->assertFalse($driver->twoTargets($extra));
    }
    public function test_only_a_source_floor_fail_blocks_the_image_and_soft_checks_become_caveats(): void
    {
        $check=fn($status,$reason='Visible evidence.')=>['status'=>$status,'evidenceViewIds'=>['source','parent_front'],'reason'=>$reason];
        $gate=['checks'=>array_fill_keys(\App\Services\IguideLab\LabLifestyleOpticalCutProtocol::CHECKS,$check('pass')),'allRequiredChecksPass'=>true];
        $this->assertSame([],LabLifestyleCutReadiness::hardBlockers($gate));$this->assertSame([],LabLifestyleCutReadiness::caveats($gate));
        $gate['checks']['optical_dependencies']=$check('fail','Patio glazing faces the standing floor.');
        $gate['checks']['frame_margin']=$check('uncertain','Margin around the right adult is tight.');
        $gate['checks']['source_floor']=$check('uncertain','Floor edge near the hearth is unclear.');$gate['allRequiredChecksPass']=false;
        $this->assertSame([],LabLifestyleCutReadiness::hardBlockers($gate));
        $this->assertSame(['frame_margin (uncertain): Margin around the right adult is tight.','source_floor (uncertain): Floor edge near the hearth is unclear.',
            'optical_dependencies (fail): Patio glazing faces the standing floor.'],LabLifestyleCutReadiness::caveats($gate));
        $gate['checks']['source_floor']=$check('fail','No visible level floor.');
        $this->assertSame(['source_floor'],LabLifestyleCutReadiness::hardBlockers($gate));
    }
    public function test_intent_records_soft_doubts_as_caveats_instead_of_manual_review(): void
    {
        $this->assertStringNotContainsString('require manual_review',LabLifestyleWorkflow::INTENT);
        $this->assertStringContainsString('caveat',LabLifestyleWorkflow::INTENT);
        $this->assertStringContainsString('adult_1 and adult_2',LabLifestyleWorkflow::INTENT);
    }
    public function test_capability_dispatch_cannot_select_a_client_named_class_or_sphere_as_square(): void
    {
        $step=new IguideLabWorkflowStep(['input'=>['protocolVersion'=>'unknown','handler'=>LabLifestyleSegmentationAuthority::class]]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(LabMachineSegmentationCapabilities::class)->forStep($step);
    }
    public function test_the_concrete_authority_reloads_typed_assessment_before_plan_and_association(): void
    {
        $step=new IguideLabWorkflowStep(['input'=>['assessmentStepId'=>'saved-typed-step']]);
        $this->mock(LabLifestyleAssessments::class,function($mock)use($step){
            $mock->shouldReceive('forSegmentation')->once()->with($step)->andReturn(['boundPlan'=>['requestId'=>'saved-step-uuid']]);
            $mock->shouldReceive('associate')->once()->with($step,['savedReceipt'=>true])->andReturn(['status'=>'needs_review']);
        });
        $authority=app(LabLifestyleSegmentationAuthority::class);
        $this->assertSame(['requestId'=>'saved-step-uuid'],$authority->plan($step));
        $this->assertSame(['status'=>'needs_review'],$authority->associate($step,['savedReceipt'=>true]));
    }
    public function test_default_candidate_cannot_advertise_automatic_lifestyle_or_grass(): void
    {
        $this->assertFalse(config('iguide-lab.lifestyle_workflow_enabled'));
        $this->assertFalse(config('iguide-lab.grass_workflow_enabled'));
        $recipes=collect(app(LabWorkflowPolicy::class)->capabilities()['recipes']);
        $this->assertFalse($recipes->firstWhere('preset','lifestyle')['ready']);
        $this->assertFalse($recipes->firstWhere('preset','grass')['ready']);
    }
    public function test_support_refuses_an_unvalidated_runtime_before_using_masks(): void
    {
        config(['iguide-lab.lifestyle_support_runtime_manifest'=>null,'iguide-lab.lifestyle_support_python_path'=>null]);
        $this->assertFalse(app(LabLifestyleSupport::class)->ready());
    }
    public function test_explicit_flag_without_runtime_or_installed_handler_still_cannot_advertise_ready(): void
    {
        config(['iguide-lab.lifestyle_workflow_enabled'=>true,'iguide-lab.grass_workflow_enabled'=>true,
            'iguide-lab.lifestyle_support_runtime_manifest'=>null]);
        $recipes=collect(app(LabWorkflowPolicy::class)->capabilities()['recipes']);
        $this->assertFalse($recipes->firstWhere('preset','lifestyle')['ready']);
        $this->assertFalse($recipes->firstWhere('preset','grass')['ready']);
    }
    public function test_evaluator_retains_fixed_recipe_and_original_source_constraints(): void
    {
        $source=['protectedFeatures'=>[['description'=>'Retain original far doorway.']],'uncertainties'=>['Mirror outside cut requires inspection.'],
            'sourceArtifacts'=>['Original scanner remains.'],'reviewChecks'=>[['instruction'=>'Check original far doorway.']]];
        $section=['protectedFeatures'=>[['description'=>'Retain foreground vent.']],'uncertainties'=>[],
            'sourceArtifacts'=>[],'reviewChecks'=>[['instruction'=>'Check all furniture legs.']]];
        $artifacts=app(\App\Services\IguideLab\LabLifestyleArtifacts::class);$context=$artifacts->lifestyleReviewContext($source,$section,[]);
        $this->assertContains('Retain original far doorway.',$context['protectedFeatures']);
        $this->assertContains('Retain foreground vent.',$context['protectedFeatures']);
        $this->assertContains('Mirror outside cut requires inspection.',$context['sourceCaveats']);
        $this->assertContains('Original scanner remains.',$context['sourceCaveats']);
        $this->assertContains(\App\Services\IguideLab\LabLifestyleArtifacts::REVIEW_CONSTRAINTS[0],$context['reviewRequirements']);
        $this->assertContains('Check all furniture legs.',$context['reviewRequirements']);
    }
    public function test_many_caveats_are_packed_into_the_bounded_final_check_instead_of_aborting(): void
    {
        // 7 framing claims + 4 section uncertainties + 2 artifacts used to abort after the composite existed.
        $claims=array_map(fn($i)=>'claim_'.$i.' (verify_on_cut): '.str_repeat('framing reason '.$i.' ',20),range(1,7));
        $source=['protectedFeatures'=>[],'uncertainties'=>[...$claims,'Recorded lifestyle caveats: PERSON_FADED_AT_SECTION_EDGE, '.str_repeat('X_REQUIRES_REVIEW, ',60)],
            'sourceArtifacts'=>[],'reviewChecks'=>[]];
        $section=['protectedFeatures'=>[],'uncertainties'=>array_map(fn($i)=>'section doubt '.$i,range(1,6)),'sourceArtifacts'=>['artifact a','artifact b'],
            'reviewChecks'=>array_map(fn($i)=>['instruction'=>'Check '.$i.' '.str_repeat('detail ',30)],range(1,8))];
        $context=app(\App\Services\IguideLab\LabLifestyleArtifacts::class)->lifestyleReviewContext($source,$section,['flags'=>['f1','f2','f3']]);
        foreach(['sourceCaveats'=>13,'reviewRequirements'=>12] as $key=>$limit){
            $this->assertLessThanOrEqual($limit,count($context[$key]));
            foreach($context[$key] as $text)$this->assertLessThanOrEqual(600,mb_strlen($text));
        }
        $joined=implode(' | ',$context['sourceCaveats']);
        $this->assertStringContainsString('claim_1 (verify_on_cut)',$joined);
        $this->assertStringContainsString('PERSON_FADED_AT_SECTION_EDGE',$joined);
        $this->assertStringContainsString(\App\Services\IguideLab\LabLifestyleArtifacts::REVIEW_CONSTRAINTS[0],implode(' | ',$context['reviewRequirements']));
    }
}
