<?php
namespace Tests\Unit;

use App\Models\IguideLabWorkflowItem;
use App\Services\IguideLab\{LabInteriorRoomProfiles,LabInteriorRoomReadScope as Scope};
use Tests\TestCase;

class IguideLabInteriorRoomReadScopeTest extends TestCase
{
    private function item(string $id='one',bool $room=true): IguideLabWorkflowItem
    {return new IguideLabWorkflowItem(['id'=>$id,'project_id'=>'project','preset'=>'interior-design','style'=>'modern','instructions'=>'',
        'source'=>['sha256'=>str_repeat('a',64)],'data'=>$room?['interiorRoomRoute'=>['profileId'=>LabInteriorRoomProfiles::MATERIALS]]:[]]);}

    public function test_nested_read_reuses_only_within_one_synchronous_tree_and_returns_independent_model_instances(): void
    {
        $item=$this->item();$count=0;$read=function()use(&$count){$count++;return new IguideLabWorkflowItem(['style'=>'modern']);};
        Scope::completed($item,function()use($item,$read){
            $first=Scope::verified($item,'parent',$read);$first->style='changed-local-copy';
            $second=Scope::completed($item,fn()=>Scope::verified($item,'parent',$read));
            $this->assertSame('modern',$second->style);
        });
        $this->assertSame(1,$count);Scope::completed($item,fn()=>Scope::verified($item,'parent',$read));$this->assertSame(2,$count);
    }

    public function test_exception_and_fresh_attempt_both_clear_prior_authority_before_later_use(): void
    {
        $item=$this->item(); // The real-file check is covered by the feature receipt regression.
        try{Scope::completed($item,function()use($item){Scope::verified($item,'parent',fn()=>'original');throw new \RuntimeException('held');});}catch(\RuntimeException){}
        $this->assertSame('changed',Scope::completed($item,fn()=>Scope::verified($item,'parent',fn()=>'changed')));
        Scope::completed($item,function()use($item){
            $this->assertSame('old',Scope::verified($item,'parent',fn()=>'old'));
            $this->assertSame('current',Scope::fresh(fn()=>Scope::verified($item,'parent',fn()=>'current')));
            $this->assertSame('after-attempt',Scope::verified($item,'parent',fn()=>'after-attempt'));
        });
    }

    public function test_legacy_and_different_item_or_intent_never_reuse_a_verified_parent(): void
    {
        $legacy=$this->item('one',false);$count=0;$read=function()use(&$count){return ++$count;};
        Scope::completed($legacy,function()use($legacy,$read){Scope::verified($legacy,'p',$read);Scope::verified($legacy,'p',$read);});
        $this->assertSame(2,$count);$room=$this->item();
        Scope::completed($room,function()use($room,$read){
            Scope::verified($room,'p',$read);Scope::verified($this->item('different'),'p',$read);
            $changed=clone $room;$changed->style='farmhouse';Scope::verified($changed,'p',$read);
        });$this->assertSame(5,$count);
    }
}
