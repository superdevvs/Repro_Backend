<?php
namespace Tests\Unit;
use App\Services\IguideLab\LabDeclutterSegmentationPlan;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class LabDeclutterSegmentationPlanTest extends TestCase
{
    private function plans(): array{return json_decode(file_get_contents(base_path('tests/Fixtures/iguide-lab/declutter-removal/bound-plans.json')),true,flags:JSON_THROW_ON_ERROR);}
    public function test_python_reserved_source_only_roles_and_fingerprints_match_php(): void
    {
        $service=new LabDeclutterSegmentationPlan;
        foreach($this->plans() as $plan){$service->validate($plan,$plan['requestId']);$copy=$plan;unset($copy['inputFingerprint']);
            $this->assertSame($plan['inputFingerprint'],LabDeclutterSegmentationPlan::canonicalHash($copy));
            $this->assertSame(1,$plan['payload']['max_masks']);$this->assertSame(1,$plan['maximumPostAttempts']);}
    }
    public function test_unknown_candidate_or_non_square_and_expanded_scope_are_rejected_even_when_rehashed(): void
    {
        $service=new LabDeclutterSegmentationPlan;$base=$this->plans()[0];
        $changes=[['stepKey'=>'candidate-furniture'],['width'=>2048,'height'=>1024],['maximumPostAttempts'=>2],['automaticRetry'=>true],['selectionOrigin'=>'human']];
        foreach($changes as $change){$plan=array_replace($base,$change);unset($plan['inputFingerprint']);$plan['inputFingerprint']=LabDeclutterSegmentationPlan::canonicalHash($plan);
            try{$service->validate($plan,$plan['requestId']);$this->fail('Unsupported role or settings passed.');}
            catch(HttpException $e){$this->assertSame(422,$e->getStatusCode());}}
    }
    public function test_payload_uses_exact_original_png_and_rejects_hash_drift(): void
    {
        $image=imagecreatetruecolor(2048,2048);ob_start();imagepng($image);$bytes=ob_get_clean();imagedestroy($image);
        $plan=$this->plans()[0];$plan['inputSha256']=hash('sha256',$bytes);unset($plan['inputFingerprint']);$plan['inputFingerprint']=LabDeclutterSegmentationPlan::canonicalHash($plan);
        $service=new LabDeclutterSegmentationPlan;$payload=$service->payload($plan,$bytes);
        $this->assertSame('data:image/png;base64,'.base64_encode($bytes),$payload['image_url']);$this->assertSame('object',$payload['prompt']);
        $this->expectException(HttpException::class);$service->payload($plan,$bytes.'drift');
    }
}
