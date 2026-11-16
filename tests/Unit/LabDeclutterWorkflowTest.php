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
    public function test_material_policy_is_explicit_default_off_and_keeps_the_same_budget(): void
    {
        $this->assertFalse(config('iguide-lab.declutter_materials_enabled'));
        $v1=LabDeclutterRemovalPolicy::snapshot();$v2=LabDeclutterRemovalPolicy::snapshot(true);
        $this->assertSame(LabDeclutterRemovalPolicy::POLICY,$v1['version']);$this->assertSame(LabDeclutterRemovalPolicy::MATERIALS_POLICY,$v2['version']);
        unset($v1['version'],$v2['version']);$this->assertSame($v1,$v2);
        $this->assertSame(LabDeclutterWorkflow::INTENT,LabDeclutterWorkflow::intent());
        $this->assertNotSame(LabDeclutterWorkflow::intent(),LabDeclutterWorkflow::intent(true));
        $this->assertFalse(LabDeclutterRemovalPolicy::materials(['declutterRemoval'=>LabDeclutterRemovalPolicy::snapshot()]));
        $this->assertTrue(LabDeclutterRemovalPolicy::enabled(['declutterRemoval'=>LabDeclutterRemovalPolicy::snapshot(true)],'declutter'));
        $this->assertFalse(LabDeclutterRemovalPolicy::enabled(['declutterRemoval'=>['version'=>LabDeclutterRemovalPolicy::MATERIALS_POLICY]],'declutter'));
    }
    public function test_material_evaluator_receives_portable_scope_and_exact_source_sample_coordinates(): void
    {
        $source=['protectedFeatures'=>[],'uncertainties'=>[],'sourceArtifacts'=>[],'reviewChecks'=>[]];
        $plan=['target'=>['description'=>'opaque knife block'],'shadow'=>['status'=>'none_observed'],'protectedFeatures'=>[],'uncertainties'=>[],
            'receiver'=>['material'=>'stochastic_stone_continuous','samples'=>[['xMin'=>.25,'yMin'=>.35,'xMax'=>.29,'yMax'=>.4],['xMin'=>.46,'yMin'=>.35,'xMax'=>.5,'yMax'=>.4]]]];
        $r=app(LabDeclutterArtifacts::class)->removalReviewContext($source,$plan,[],true);
        $this->assertContains(LabDeclutterArtifacts::MATERIALS_REVIEW_CONSTRAINTS[0],$r['reviewRequirements']);
        $this->assertStringContainsString('[[512,716,594,820],[942,716,1024,820]]',implode(' ',$r['reviewRequirements']));
        $this->assertStringNotContainsString('opaque decorative object',implode(' ',$r['reviewRequirements']));
    }
    public function test_material_runtime_binds_current_php_authority_and_rejects_stale_provider_code(): void
    {
        $bridge=app(\App\Services\IguideLab\LabDeclutterRemovalBridge::class);$path=tempnam(sys_get_temp_dir(),'declutter-runtime-');
        try{
            config(['iguide-lab.pipeline_python_path'=>PHP_BINARY,'iguide-lab.declutter_removal_runtime_manifest'=>$path]);
            $runtime=['validationStatus'=>'passed','pythonVersion'=>'3.12.3','pillowVersion'=>'13.0.0','pythonPath'=>PHP_BINARY];
            foreach($bridge->snapshot() as $key=>$value)if(str_ends_with($key,'Sha256')&&$key!=='runtimeManifestSha256')$runtime['validated'.ucfirst($key)]=$value;
            file_put_contents($path,json_encode($runtime,JSON_THROW_ON_ERROR));$this->assertTrue($bridge->ready());
            $this->assertArrayHasKey('validatedProviderSha256',$runtime);$this->assertArrayHasKey('validatedPolicySha256',$runtime);
            $runtime['validatedProviderSha256']=str_repeat('0',64);file_put_contents($path,json_encode($runtime,JSON_THROW_ON_ERROR));
            $this->assertFalse($bridge->ready());
        }finally{unlink($path);}
    }
    public function test_route_holds_only_missing_target_or_unsupported_geometry(): void
    {
        $driver=app(LabDeclutterWorkflow::class);$this->assertTrue($driver->sourceRoute($this->route()));
        foreach(['extra','count','action','mode','target','pitch','fov','none'] as $kind){
            $p=$this->route();
            match($kind){'extra'=>$p['targets'][]=$p['targets'][0],'count'=>$p['targets'][0]['objectCount']=0,
                'action'=>$p['targets'][0]['action']='restyle',
                'mode'=>$p['workflow']['mode']='manual_review','target'=>$p['workflow']['sections'][0]['targetIds']=['another'],
                'pitch'=>$p['workflow']['sections'][0]['pitchDegrees']=-80,'fov'=>$p['workflow']['sections'][0]['hfovDegrees']=125,
                'none'=>$p['targets']=[]};
            $this->assertFalse($driver->sourceRoute($p),$kind);
        }
    }
    public function test_source_doubts_and_a_small_group_of_loose_items_are_caveats_not_stops(): void
    {
        $driver=app(LabDeclutterWorkflow::class);
        $p=$this->route();$p['uncertainties']=['Unclear reflection.'];$this->assertTrue($driver->sourceRoute($p));
        $p=$this->route();$p['targets'][0]['objectCount']=3;$this->assertTrue($driver->sourceRoute($p));
        $p=$this->route();$p['targets'][0]['objectCount']=null;$this->assertTrue($driver->sourceRoute($p));
    }
    public function test_material_intent_picks_small_loose_items_and_records_doubts_as_caveats(): void
    {
        $intent=LabDeclutterWorkflow::intent(true);
        $this->assertStringContainsString('small loose',$intent);
        $this->assertStringContainsString('uncertainties',$intent);
        $this->assertStringContainsString('manual_review only',$intent);
        $this->assertStringNotContainsString('require manual_review',$intent);
        $this->assertLessThanOrEqual(3200,mb_strlen($intent));
    }
    public function test_material_evaluator_scope_names_the_selected_loose_items(): void
    {
        $this->assertStringContainsString('selected small loose',LabDeclutterArtifacts::MATERIALS_REVIEW_CONSTRAINTS[0]);
        $this->assertStringNotContainsString('exactly one',LabDeclutterArtifacts::MATERIALS_REVIEW_CONSTRAINTS[0]);
    }
    public function test_recorded_caveats_reach_the_final_check_and_overflow_is_packed_not_dropped(): void
    {
        $source=['protectedFeatures'=>[],'uncertainties'=>array_map(fn($i)=>'Source doubt '.$i.' '.str_repeat('s',300),range(1,6)),
            'sourceArtifacts'=>array_map(fn($i)=>'Artifact '.$i,range(1,6)),'reviewChecks'=>[]];
        $plan=['target'=>['description'=>'three canisters'],'shadow'=>['status'=>'single_local_attributable'],'protectedFeatures'=>[],
            'uncertainties'=>array_map(fn($i)=>'Plan doubt '.$i.' '.str_repeat('p',300),range(1,12)),
            'receiver'=>['material'=>'stochastic_stone_continuous','samples'=>[]]];
        $r=app(LabDeclutterArtifacts::class)->removalReviewContext($source,$plan,[],true,['Recorded removal caveats: OPTICAL_DEPENDENCY_REQUIRES_REVIEW.']);
        $this->assertLessThanOrEqual(13,count($r['sourceCaveats']));
        foreach($r['sourceCaveats'] as $text)$this->assertLessThanOrEqual(600,mb_strlen($text));
        $all=implode('',$r['sourceCaveats']); // overflow is split across bounded entries in order
        foreach([...$source['uncertainties'],...$source['sourceArtifacts'],...$plan['uncertainties']] as $text)$this->assertStringContainsString($text,$all);
        $this->assertStringContainsString('OPTICAL_DEPENDENCY_REQUIRES_REVIEW',$all);
    }
    public function test_removal_caveat_line_lists_plan_and_support_codes_and_raw_draft_fallback(): void
    {
        $line=LabDeclutterArtifacts::caveatLines(['caveats'=>['UNRESOLVED_UNCERTAINTY']],['caveats'=>['OBJECT_MASK_DISCONNECTED']],true);
        $text=implode(' ',$line);
        $this->assertStringContainsString('UNRESOLVED_UNCERTAINTY',$text);$this->assertStringContainsString('OBJECT_MASK_DISCONNECTED',$text);
        $this->assertStringContainsString('raw generated draft',$text);
        $this->assertSame([],LabDeclutterArtifacts::caveatLines(['caveats'=>[]],['caveats'=>[]],false));
        $this->assertSame([],LabDeclutterArtifacts::caveatLines([],[],false));
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
