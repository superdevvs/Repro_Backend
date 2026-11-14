<?php
namespace Tests\Unit;

use App\Services\IguideLab\{LabAnalysisRecipes,LabRepairAnalysisProtocol,LabWorkflowRepairContext,LabVisualQualityProtocol,LabLightingRepairs};
use Illuminate\Support\Str;
use Tests\TestCase;

class IguideLabRepairAnalysisProtocolTest extends TestCase
{
    private function fixture(): array
    {
        $png=(string)\Intervention\Image\ImageManager::gd()->create(2048,1024)->fill('888888')->toPng();
        $sha=hash('sha256',$png); $intent=['sourceSha256'=>$sha,'preset'=>'twilight','style'=>null,'instructions'=>'Preserve the source and make visible blue-hour lighting.'];
        $checks=[];
        foreach(LabVisualQualityProtocol::CHECKS as $key) $checks[$key]=['status'=>$key==='optical_dependencies'?'fail':'pass','evidenceViewIds'=>[$key==='seam'?'wrap':($key==='poles'?'poles':'front')], 'reason'=>str_repeat('Complete evidence. ',30).' FINAL_CRITICAL_CLAUSE'];
        $feedback=['protocolVersion'=>LabVisualQualityProtocol::VERSION,'qualityStatus'=>'needs_review','checks'=>$checks,'unresolvedDependencies'=>[str_repeat('Dependency detail. ',20).' END_DEPENDENCY'], 'recommendedAction'=>'replan','summary'=>'Preserve optical consistency and visible requested lighting.', 'manualAcceptance'=>false,'crossViewVerified'=>false];
        $authority=['version'=>LabWorkflowRepairContext::VERSION,'evaluationId'=>(string)Str::uuid(),'resultSha256'=>LabAnalysisRecipes::hash($feedback),'evidenceSha256'=>str_repeat('e',64),'responseSha256'=>str_repeat('f',64)];
        $views=[];foreach(LabWorkflowRepairContext::IDS as $i=>$id)$views[]=['id'=>$id,'evidenceViewId'=>LabVisualQualityProtocol::VIEW_IDS[$i], 'sha256'=>$sha,'width'=>2048,'height'=>1024,'role'=>'source_left_failed_candidate_right'];
        $context=['sourceSha256'=>$sha,'projection'=>'equirectangular','preset'=>'twilight','style'=>null,'viewIds'=>LabWorkflowRepairContext::IDS,'instructions'=>$intent['instructions'],
            'repairContext'=>['version'=>LabWorkflowRepairContext::VERSION,'sourceSha256'=>$sha,'candidateSha256'=>str_repeat('c',64),'intentSha256'=>LabAnalysisRecipes::hash($intent),'evidenceManifestSha256'=>str_repeat('e',64),'authority'=>$authority,'originalIntent'=>$intent,'feedback'=>$feedback,'sameCaptureOnly'=>true,'candidateIsGeometryAuthority'=>false,'views'=>$views]];
        return [$context,array_fill(0,7,'data:image/png;base64,'.base64_encode($png))];
    }
    public function test_full_feedback_and_seven_pairs_are_preserved_with_new_recipe_only(): void
    {
        [$context,$images]=$this->fixture();$recipes=app(LabAnalysisRecipes::class);
        $snapshot=$recipes->snapshot(LabAnalysisRecipes::LIGHTING_REPAIR);
        $payload=$recipes->request($snapshot,$context,$images);
        $this->assertSame($context,json_decode($payload['input'][0]['content'][0]['text'],true));
        $this->assertCount(15,$payload['input'][0]['content']);
        $this->assertStringContainsString('FINAL_CRITICAL_CLAUSE',$payload['input'][0]['content'][0]['text']);
        $this->assertStringContainsString('END_DEPENDENCY',$payload['input'][0]['content'][0]['text']);
        foreach(range(0,6) as $i)$this->assertStringContainsString('SOURCE LEFT is geometry authority',$payload['input'][0]['content'][1+2*$i]['text']);
        $this->assertSame('responses-scene-input-v1',$recipes->snapshot(LabAnalysisRecipes::BASELINE)['recipe']['compilerVersion']);
    }
    public function test_changed_source_intent_feedback_order_hash_and_missing_board_are_rejected(): void
    {
        [$context,$images]=$this->fixture();$recipe=app(LabAnalysisRecipes::class)->snapshot(LabAnalysisRecipes::LIGHTING_REPAIR)['recipe'];
        foreach(['source','intent','feedback','order','hash','missing','authority','unseen'] as $case){
            $bad=$context;$pics=$images;
            match($case){
                'source'=>$bad['sourceSha256']=str_repeat('1',64),
                'intent'=>$bad['repairContext']['originalIntent']['instructions']='Replace windows.',
                'feedback'=>$bad['repairContext']['feedback']['summary']='Changed.',
                'order'=>$bad['repairContext']['views']=array_reverse($bad['repairContext']['views']),
                'hash'=>$bad['repairContext']['views'][1]['sha256']=str_repeat('2',64),
                'missing'=>array_pop($pics),
                'authority'=>$bad['repairContext']['authority']['responseSha256']='invalid',
                'unseen'=>$bad['repairContext']['candidateIsGeometryAuthority']=true,
            };
            try{LabRepairAnalysisProtocol::compile($recipe,$bad,$pics);$this->fail('Accepted bad '.$case);}catch(\InvalidArgumentException){$this->addToAssertionCount(1);}
        }
    }
    public function test_optical_uncertainty_or_geometry_failure_never_triggers_color_fallback(): void
    {
        [$context]=$this->fixture();$r=$context['repairContext']['feedback'];$service=new LabLightingRepairs;
        $this->assertTrue($service->eligible($r));
        foreach(['geometry','seam','poles','protected_features'] as $key){$bad=$r;$bad['checks'][$key]['status']='fail';$this->assertFalse($service->eligible($bad));}
        $r['checks']['optical_dependencies']['status']='uncertain';$this->assertFalse($service->eligible($r));
        $r['checks']['optical_dependencies']['status']='fail';$r['recommendedAction']='manual_review';$this->assertFalse($service->eligible($r));
    }
}
