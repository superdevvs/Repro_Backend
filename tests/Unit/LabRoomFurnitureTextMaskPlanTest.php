<?php
namespace Tests\Unit;

use App\Services\IguideLab\{LabInteriorRoomProfiles,LabMachineSegmentationPlan,LabMachineSegmentationTransport,LabRoomFurnitureTextMaskPlan as P};
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class LabRoomFurnitureTextMaskPlanTest extends TestCase
{
    private function plan(): array
    {return app(P::class)->bind((string)Str::uuid(),LabInteriorRoomProfiles::LIVING,str_repeat('a',64),str_repeat('b',64),str_repeat('c',64));}
    private function mask(int $pixels=5000,array $bounds=[3,900,400,1100]): array
    {return ['sha256'=>hash('sha256',Str::random()),'width'=>2048,'height'=>2048,'bounds'=>$bounds,'positivePixels'=>$pixels];}
    private function receipt(array $plan,array $masks): array
    {
        return ['requestId'=>$plan['requestId'],'providerRequestId'=>'sam-1','model'=>LabMachineSegmentationTransport::MODEL,'inputFingerprint'=>$plan['inputFingerprint'],
            'inputSha256'=>$plan['inputSha256'],'rawResponseSha256'=>str_repeat('d',64),'status'=>'completed','masks'=>$masks];
    }
    private function rejects(callable $f): void {try{$f();$this->fail('Invalid furniture mask plan accepted.');}catch(HttpException $e){$this->assertSame(422,$e->getStatusCode());}}

    public function test_the_draft_furniture_mask_is_a_text_only_request_for_up_to_three_masks(): void
    {
        $plan=$this->plan();app(P::class)->validate($plan,$plan['requestId']);
        $this->assertSame(P::ROLE,$plan['stepKey']);$this->assertSame(['furniture'],$plan['expectedIdentities']);
        $this->assertSame(['prompt'=>'furniture','box_prompts'=>[],'apply_mask'=>false,'output_format'=>'png','return_multiple_masks'=>true,
            'max_masks'=>3,'include_scores'=>true,'include_boxes'=>true],$plan['payload']);
        foreach(['payload'=>['prompt'=>'sofa']+$plan['payload'],'expectedIdentities'=>['sofa'],'stepKey'=>'candidate-furniture','inputSha256'=>str_repeat('e',64)] as $key=>$value){
            $changed=$plan;$changed[$key]=$value;$this->rejects(fn()=>app(P::class)->validate($changed,$plan['requestId']));}
        $this->rejects(fn()=>app(P::class)->validate($plan,(string)Str::uuid()));
    }
    public function test_one_mask_near_the_draft_border_is_usable_without_the_old_wide_clearance(): void
    {
        $plan=$this->plan();$association=app(P::class)->associate($plan,$this->receipt($plan,[$this->mask()]));
        $this->assertSame('ready_for_room_support',$association['status']);$this->assertSame('furniture',$association['associations'][0]['targetId']);
        $this->assertFalse($association['automaticAcceptance']);
        $this->assertSame('needs_review',app(P::class)->associate($plan,$this->receipt($plan,[$this->mask(12)]))['status']);
        $wrong=$this->receipt($plan,[$this->mask()]);$wrong['inputSha256']=str_repeat('f',64);$this->rejects(fn()=>app(P::class)->associate($plan,$wrong));
    }
    public function test_one_to_three_valid_masks_are_usable_and_anything_else_is_not(): void
    {
        $p=app(P::class);
        $this->assertTrue($p->usable([$this->mask()]));$this->assertTrue($p->usable([$this->mask(),$this->mask(),$this->mask()]));
        $this->assertFalse($p->usable([]));$this->assertFalse($p->usable([$this->mask(),$this->mask(),$this->mask(),$this->mask()]));
        $this->assertFalse($p->usable([$this->mask(),$this->mask(100)]));
        $this->assertFalse($p->usable([['width'=>1024]+$this->mask()]));
        $this->assertFalse($p->usable([$this->mask(5000,[0,0,0,10])]));
    }
}
