<?php
namespace Tests\Unit;

use App\Models\IguideLabWorkflowStep;
use App\Services\IguideLab\{LabLifestyleSegmentationPlan,LabLifestyleSegmentationAuthority,LabMachineSegmentationCapabilities};
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class LabLifestyleSegmentationPlanTest extends TestCase
{
    private function plans(): array { return json_decode(file_get_contents(base_path('tests/Fixtures/iguide-lab/lifestyle/plans.json')),true,flags:JSON_THROW_ON_ERROR); }
    public function test_actual_python_fingerprints_and_exact_people_input_are_accepted(): void
    {
        $validator=app(LabLifestyleSegmentationPlan::class);
        foreach($this->plans() as $plan)$validator->validate($plan,$plan['requestId']);
        $plan=$this->plans()[1];$bytes=file_get_contents(base_path('tests/Fixtures/iguide-lab/lifestyle/candidate.png'));
        $payload=$validator->payload($plan,$bytes);
        $this->assertSame('person',$payload['prompt']);$this->assertSame(2,$payload['max_masks']);
        $this->assertSame($bytes,base64_decode(substr($payload['image_url'],strlen('data:image/png;base64,'))));
    }
    public function test_furniture_roles_expanded_counts_wrong_shape_and_changed_bytes_cannot_be_claimed(): void
    {
        $validator=app(LabLifestyleSegmentationPlan::class);$original=$this->plans()[1];
        foreach(['furniture','third-person','wrong-role','sphere','retry'] as $mutation){
            $p=$original;
            if($mutation==='furniture')$p['expectedIdentities']=['sofa','chair'];
            if($mutation==='third-person')$p['payload']['max_masks']=3;
            if($mutation==='wrong-role')$p['stepKey']='candidate-furniture';
            if($mutation==='sphere')$p['height']=1024;
            if($mutation==='retry')$p['maximumPostAttempts']=2;
            unset($p['inputFingerprint']);$p['inputFingerprint']=LabLifestyleSegmentationPlan::canonicalHash($p);
            try{$validator->validate($p,$p['requestId']);$this->fail($mutation.' relaxed the closed recipe');}
            catch(HttpException $e){$this->assertSame(422,$e->getStatusCode());}
        }
        $this->expectException(HttpException::class);$validator->payload($original,'different exact bytes');
    }
    public function test_disabled_protocol_cannot_dispatch_even_with_a_named_handler(): void
    {
        config(['iguide-lab.lifestyle_workflow_enabled'=>false]);
        $step=new IguideLabWorkflowStep(['input'=>['protocolVersion'=>'automatic-lifestyle-segmentation/1','handler'=>LabLifestyleSegmentationAuthority::class]]);
        $this->expectException(HttpException::class);app(LabMachineSegmentationCapabilities::class)->forStep($step);
    }
    public function test_the_only_enabled_protocol_maps_to_the_fixed_people_authority(): void
    {
        config(['iguide-lab.lifestyle_workflow_enabled'=>true]);
        $step=new IguideLabWorkflowStep(['input'=>['protocolVersion'=>'automatic-lifestyle-segmentation/1']]);
        $this->assertInstanceOf(LabLifestyleSegmentationAuthority::class,app(LabMachineSegmentationCapabilities::class)->forStep($step));
    }
}
