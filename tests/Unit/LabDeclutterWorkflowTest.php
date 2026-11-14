<?php
namespace Tests\Unit;
use App\Models\IguideLabWorkflowStep;
use App\Services\IguideLab\{LabDeclutterArtifacts,LabDeclutterRemovalPolicy,LabDeclutterSupport,LabDeclutterWorkflow,LabMachineSegmentationCapabilities,LabWorkflowPolicy};
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class LabDeclutterWorkflowTest extends TestCase
{
    private function route(): array{return ['targets'=>[['id'=>'target_object','action'=>'remove','objectCount'=>1]],'uncertainties'=>[],
        'workflow'=>['mode'=>'perspective_sections','sections'=>[['targetIds'=>['target_object'],'pitchDegrees'=>-30,'hfovDegrees'=>65]]]];}
    public function test_route_holds_expansion_uncertainty_and_unsupported_geometry(): void
    {
        $driver=app(LabDeclutterWorkflow::class);$this->assertTrue($driver->sourceRoute($this->route()));
        foreach(['extra','count','action','uncertainty','mode','target','pitch','fov'] as $kind){
            $p=$this->route();
            match($kind){'extra'=>$p['targets'][]=$p['targets'][0],'count'=>$p['targets'][0]['objectCount']=2,
                'action'=>$p['targets'][0]['action']='restyle','uncertainty'=>$p['uncertainties']=['Unclear reflection.'],
                'mode'=>$p['workflow']['mode']='manual_review','target'=>$p['workflow']['sections'][0]['targetIds']=['another'],
                'pitch'=>$p['workflow']['sections'][0]['pitchDegrees']=-80,'fov'=>$p['workflow']['sections'][0]['hfovDegrees']=125};
            $this->assertFalse($driver->sourceRoute($p),$kind);
        }
    }
    public function test_disabled_or_unvalidated_recipe_is_never_advertised(): void
    {
        $this->assertFalse(config('iguide-lab.declutter_workflow_enabled'));
        $this->assertFalse(collect(app(LabWorkflowPolicy::class)->capabilities()['recipes'])->firstWhere('preset','declutter')['ready']);
        config(['iguide-lab.declutter_workflow_enabled'=>true,'iguide-lab.declutter_support_runtime_manifest'=>null]);
        $this->assertFalse(app(LabDeclutterSupport::class)->ready());
        $this->assertFalse(collect(app(LabWorkflowPolicy::class)->capabilities()['recipes'])->firstWhere('preset','declutter')['ready']);
    }
    public function test_closed_router_requires_explicit_server_enablement(): void
    {
        $step=new IguideLabWorkflowStep(['input'=>['protocolVersion'=>LabDeclutterRemovalPolicy::SEGMENTATION]]);
        $this->expectException(HttpException::class);app(LabMachineSegmentationCapabilities::class)->forStep($step);
    }
    public function test_evaluator_receives_exact_target_and_hidden_surface_limit_without_discarding_source_context(): void
    {
        $source=['protectedFeatures'=>[['description'=>'Original table edge.']],'uncertainties'=>[],
            'sourceArtifacts'=>['Original camera artifact.'],'reviewChecks'=>[['instruction'=>'Inspect target boundary and reflection context.']]];
        $plan=['target'=>['description'=>'one opaque stone'],'shadow'=>['status'=>'none_observed'],
            'protectedFeatures'=>[['id'=>'near cup','notes'=>'Retain exact outline.']],'uncertainties'=>[]];
        $context=app(LabDeclutterArtifacts::class)->removalReviewContext($source,$plan,[]);
        $this->assertContains('Original table edge.',$context['protectedFeatures']);$this->assertContains('near cup: Retain exact outline.',$context['protectedFeatures']);
        $this->assertContains('Original camera artifact.',$context['sourceCaveats']);
        $this->assertContains(LabDeclutterArtifacts::REVIEW_CONSTRAINTS[1],$context['reviewRequirements']);
        $this->assertContains('Selected target: one opaque stone. Source shadow finding: none_observed.',$context['reviewRequirements']);
    }
    public function test_complete_context_overflow_holds_instead_of_truncating(): void
    {
        $source=['protectedFeatures'=>[],'uncertainties'=>[],'sourceArtifacts'=>[],
            'reviewChecks'=>array_map(fn($i)=>['instruction'=>'Distinct required check '.$i],range(1,20))];
        $plan=['target'=>['description'=>'opaque stone'],'shadow'=>['status'=>'none_observed'],'protectedFeatures'=>[],'uncertainties'=>[]];
        $this->expectException(HttpException::class);app(LabDeclutterArtifacts::class)->removalReviewContext($source,$plan,[]);
    }
}
