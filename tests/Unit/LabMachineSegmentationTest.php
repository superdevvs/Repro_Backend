<?php

namespace Tests\Unit;

use App\Services\IguideLab\LabMachineMaskPixels;
use App\Services\IguideLab\LabMachineSegmentationPlan;
use App\Services\IguideLab\LabMachineSegmentationTransport;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class LabMachineSegmentationTest extends TestCase
{
    protected function setUp(): void { parent::setUp(); config(['services.fal.key'=>'test-only-placeholder']); Http::preventStrayRequests(); }

    public function test_independent_python_protocol_fingerprints_match_both_php_plan_roles(): void
    {
        $fixture=json_decode(file_get_contents(base_path('tests/Fixtures/iguide-lab/machine-segmentation-python-plans.json')),true,flags:JSON_THROW_ON_ERROR);
        $this->assertTrue($fixture['synthetic']); $this->assertSame(0,$fixture['providerCalls']);
        foreach ($fixture['plans'] as $plan) { app(LabMachineSegmentationPlan::class)->validate($plan,$plan['requestId']); $this->assertSame('server_reserved',$plan['reservationState']); }
        Http::assertNothingSent();
    }

    public function test_exact_machine_plan_rejects_extra_parameters_before_transport(): void
    {
        $plan=$this->plan(); $bytes=$this->png(); $plan['inputSha256']=hash('sha256',$bytes); $plan=$this->fingerprint($plan);
        $compiled=app(LabMachineSegmentationPlan::class)->payload($plan,$bytes);
        $this->assertSame('floor',$compiled['prompt']); $this->assertSame(1,$compiled['max_masks']);
        $this->assertSame($bytes,base64_decode(substr($compiled['image_url'],22)));
        $plan['payload']['sync_mode']=true; $plan=$this->fingerprint($plan);
        try { app(LabMachineSegmentationPlan::class)->validate($plan,$plan['requestId']); $this->fail('An unsupported option passed.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(422,$e->getStatusCode()); }
        Http::assertNothingSent();
    }

    public function test_source_hash_and_unreserved_identity_fail_before_transport(): void
    {
        $plan=$this->plan();
        foreach (['unreserved','changed_source'] as $kind) {
            $candidate=$plan; if ($kind==='unreserved') { $candidate['reservationState']='unreserved'; $candidate=$this->fingerprint($candidate); }
            try { app(LabMachineSegmentationPlan::class)->payload($candidate,$this->png()); $this->fail('Unsupported binding passed.'); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(422,$e->getStatusCode()); }
        }
        Http::assertNothingSent();
    }

    public function test_lost_submission_is_unknown_and_never_transport_retried(): void
    {
        Http::fake(['queue.fal.run/*'=>Http::failedConnection()]);
        $result=app(LabMachineSegmentationTransport::class)->submit(['image_url'=>'data:image/png;base64,fixture']);
        $this->assertSame('unknown',$result['status']); $this->assertNull($result['providerRequestId']); $this->assertNull($result['raw']);
        Http::assertSentCount(1);
    }

    public function test_valid_receipt_then_get_only_base_result_recovery(): void
    {
        Http::fakeSequence()->push(['request_id'=>'known-receipt'],200)->push(['status'=>'COMPLETED'],200)->push([],405)->push(['masks'=>[]],200);
        $transport=app(LabMachineSegmentationTransport::class); $receipt=$transport->submit(['image_url'=>'fixture']);
        $this->assertSame('received',$receipt['status']); $this->assertSame('known-receipt',$receipt['providerRequestId']);
        $result=$transport->poll($receipt['providerRequestId']); $this->assertSame('completed',$result['status']);
        $this->assertSame([],$result['result']['masks']); Http::assertSentCount(4);
        $requests=Http::recorded()->map(fn($pair)=>$pair[0]); $this->assertSame(['POST','GET','GET','GET'],$requests->map->method()->all());
        $this->assertStringEndsWith('/requests/known-receipt',$requests->last()->url());
    }

    public function test_response_size_and_redirect_are_not_accepted_as_receipts(): void
    {
        foreach ([Http::response(['request_id'=>'x'],302,['Location'=>'http://127.0.0.1']),Http::response(str_repeat('x',LabMachineSegmentationTransport::MAX_RESPONSE_BYTES+1),200)] as $response) {
            Http::fake(['queue.fal.run/*'=>$response]); $result=app(LabMachineSegmentationTransport::class)->submit(['image_url'=>'fixture']);
            $this->assertSame('unknown',$result['status']); $this->assertNull($result['providerRequestId']);
        }
    }

    public function test_asset_host_and_private_dns_are_rejected_before_get(): void
    {
        $transport=new class extends LabMachineSegmentationTransport { protected function addresses(string $host): array { return ['127.0.0.1']; } };
        foreach (['https://evil.example/mask.png','https://fal.media.evil.example/mask.png','https://user@fal.media/mask.png','https://fal.media:443/mask.png','https://v3b.fal.media/mask.png'] as $url) {
            try { $transport->mask($url); $this->fail('Unsafe asset passed.'); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(422,$e->getStatusCode()); }
        }
        Http::assertNothingSent();
    }

    public function test_bounded_public_asset_download_omits_provider_credentials(): void
    {
        $transport=new class extends LabMachineSegmentationTransport { protected function addresses(string $host): array { return ['8.8.8.8']; } };
        $png=$this->png(); Http::fake(['v3b.fal.media/*'=>Http::response($png,200)]);
        $this->assertSame($png,$transport->mask('https://v3b.fal.media/mask.png'));
        Http::assertSent(fn($r)=>!$r->hasHeader('Authorization'));
        Http::fake(['v3b.fal.media/*'=>Http::response('small',200,['Content-Length'=>(string)(LabMachineSegmentationTransport::MAX_MASK_BYTES+1)])]);
        $this->expectException(\RuntimeException::class); $transport->mask('https://v3b.fal.media/mask.png');
    }

    public function test_real_pixel_bounds_are_exclusive_and_empty_masks_remain_empty(): void
    {
        $bytes=$this->png(); $result=app(LabMachineMaskPixels::class)->describe($bytes);
        $this->assertSame(hash('sha256',$bytes),$result['sha256']); $this->assertSame([100,200,103,205],$result['bounds']); $this->assertSame(15,$result['positivePixels']);
        $empty=app(LabMachineMaskPixels::class)->describe($this->png(false)); $this->assertSame(0,$empty['positivePixels']); $this->assertNull($empty['bounds']);
    }

    public function test_malformed_wrong_sized_color_and_transparent_masks_are_rejected(): void
    {
        foreach ([substr($this->png(),0,-12),$this->png(true,1024),$this->png(true,2048,true),$this->png(true,2048,false,true)] as $bytes) {
            try { app(LabMachineMaskPixels::class)->describe($bytes); $this->fail('Invalid mask passed.'); }
            catch (\InvalidArgumentException $e) { $this->assertStringContainsString('PNG mask',$e->getMessage()); }
        }
    }

    private function png(bool $target=true,int $height=2048,bool $color=false,bool $transparent=false): string
    {
        $image=imagecreatetruecolor(2048,$height); imagefill($image,0,0,imagecolorallocate($image,0,0,0));
        if ($target) imagefilledrectangle($image,100,200,102,204,imagecolorallocate($image,255,$color?0:255,255));
        if ($transparent) { imagealphablending($image,false); imagesavealpha($image,true); imagesetpixel($image,0,0,imagecolorallocatealpha($image,0,0,0,127)); }
        ob_start(); imagepng($image); $bytes=ob_get_clean(); imagedestroy($image); return $bytes;
    }
    private function plan(): array
    {
        return $this->fingerprint(['stepKey'=>'source-floor','requestId'=>(string)Str::uuid(),'reservationState'=>'server_reserved','model'=>LabMachineSegmentationTransport::MODEL,
            'inputSha256'=>str_repeat('a',64),'width'=>2048,'height'=>2048,'payload'=>['prompt'=>'floor','box_prompts'=>[['x_min'=>20,'y_min'=>20,'x_max'=>2000,'y_max'=>2000,'object_id'=>1]],
                'apply_mask'=>false,'output_format'=>'png','return_multiple_masks'=>true,'max_masks'=>1,'include_scores'=>true,'include_boxes'=>true],
            'expectedIdentities'=>['floor'],'selectionOrigin'=>'machine','assessmentProposalSha256'=>str_repeat('b',64),'contextSha256'=>str_repeat('c',64),
            'maximumPostAttempts'=>1,'automaticRetry'=>false,'identityCaveat'=>'Returned order is not identity.']);
    }
    private function fingerprint(array $plan): array { unset($plan['inputFingerprint']); $plan['inputFingerprint']=LabMachineSegmentationPlan::canonicalHash($plan); return $plan; }
}
