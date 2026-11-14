<?php
namespace Tests\Unit;

use App\Services\IguideLab\{LabCatalog,LabInteriorDesignProtocol,LabInteriorRoomProfiles as Profiles,LabInteriorRoomRouteProtocol as P,LabMachineSegmentationPlan};
use Tests\TestCase;

class IguideLabInteriorRoomRouteProtocolTest extends TestCase
{
    public static function fixture(): array
    {
        [$c,$images]=IguideLabInteriorDesignFramingProtocolTest::fixture();$c['version']=P::VERSION;
        $c['roomMetadata']=['roomId'=>'source-room-41','roomName'=>'BEDROOM','floorId'=>'1','floorName'=>null,'panoId'=>'10003'];
        $c['metadataSha256']=LabMachineSegmentationPlan::canonicalHash($c['roomMetadata']);return [$c,$images];
    }
    public static function candidate(array $c): array
    {
        $f=fn($status)=>['status'=>$status,'evidenceViewIds'=>P::IDS,'reason'=>'Inspect the bound original room; actual placement remains a separate gate.'];
        return array_intersect_key($c,array_flip(P::BINDINGS))+[
            'room'=>$f('identified')+['function'=>'bedroom'],
            'occupancy'=>$f('empty')+['existingFurnishings'=>[]],
            'materialPotential'=>$f('unavailable')+['eligibleSurfaces'=>[],'styleFeatures'=>[]],
            'metadataAgreement'=>$f('consistent'),'intentCompatibility'=>$f('within_bounded_profile'),
            'opticalDependencies'=>$f('needs_actual_cut'),'summary'=>'Empty bedroom: add only the bounded profile after source/cut/placement checks.'];
    }
    private static function response(array $v): array {return LabInteriorDesignProtocol::response($v);}
    private function parsed(array $c,array $v): array {return P::parse(self::response($v),$c);}
    public function test_empty_bedroom_routes_to_real_bed_roles_without_repurposing_furniture_or_accepting_placement(): void
    {
        [$c,$images]=self::fixture();$v=$this->parsed($c,self::candidate($c));
        $this->assertSame(Profiles::BEDROOM,$v['route']['profileId']);
        $this->assertSame(['bed','nightstand_left','nightstand_right'],Profiles::get($v['route']['profileId'])['roles']);
        foreach(['imageGenerationAuthorized','segmentationAuthorized','nativeCompositionAuthorized','manualAcceptance','crossViewVerified'] as $key)$this->assertFalse($v['route'][$key]);
        $wire=P::request($c,$images);$this->assertSame(4000,$wire['max_output_tokens']);$this->assertSame('medium',$wire['reasoning']['effort']);
        $this->assertFalse($wire['store']);$this->assertSame($c,json_decode($wire['input'][0]['content'][0]['text'],true));
        $this->assertArrayNotHasKey('view',$v);$this->assertArrayNotHasKey('generationPrompt',$v);
    }
    public function test_living_and_existing_material_routes_preserve_all_five_user_styles(): void
    {
        [$c]=self::fixture();
        foreach(array_keys(LabCatalog::STYLES) as $style){$c['style']=$style;$v=self::candidate($c);$v['room']['function']='living';
            $this->assertSame(Profiles::LIVING,$this->parsed($c,$v)['route']['profileId']);
            $v['occupancy']['status']='furnished';$v['occupancy']['existingFurnishings']=[['description'=>'Original sofa and rug.','evidenceViewIds'=>['source','front']]];
            $v['materialPotential']['status']='supported';$v['materialPotential']['eligibleSurfaces']=[['description'=>'Complete matte sofa cloth.','evidenceViewIds'=>['source','front']],['description'=>'Existing complete rug.','evidenceViewIds'=>['source','front']]];
            $v['materialPotential']['styleFeatures']=[['kind'=>'texture','description'=>'Recognizable woven cloth.'],['kind'=>'pattern','description'=>'Style-appropriate rug pattern.']];
            $this->assertSame(Profiles::MATERIALS,$this->parsed($c,$v)['route']['profileId']);$this->assertSame($style,$v['style']);}
    }
    public function test_semantic_holds_remain_completed_diagnostics_not_parser_failure_or_branch_switch(): void
    {
        [$c]=self::fixture();$v=self::candidate($c);
        $changes=[fn($v)=>array_replace_recursive($v,['metadataAgreement'=>['status'=>'conflict']]),
            fn($v)=>array_replace_recursive($v,['room'=>['status'=>'uncertain']]),
            fn($v)=>array_replace_recursive($v,['occupancy'=>['status'=>'uncertain']]),
            fn($v)=>array_replace_recursive($v,['opticalDependencies'=>['status'=>'unsupported']]),
            fn($v)=>array_replace_recursive($v,['intentCompatibility'=>['status'=>'unsupported']]),
            fn($v)=>array_replace_recursive($v,['room'=>['function'=>'other']])];
        foreach($changes as $change){$held=$this->parsed($c,$change($v));$this->assertSame('needs_review',$held['route']['status']);$this->assertNull($held['route']['profileId']);$this->assertNotEmpty($held['route']['reviewReasons']);}
        $this->assertSame(5,Profiles::BUDGET['analysis']);$this->assertSame(1,Profiles::BUDGET['image']);$this->assertFalse(Profiles::snapshot()['routeSwitchAfterDispatch']);
    }
    public function test_unknown_metadata_does_not_invent_function_or_override_source_pixels(): void
    {
        [$c]=self::fixture();$c['roomMetadata']=array_fill_keys(array_keys($c['roomMetadata']),null);$c['metadataSha256']=LabMachineSegmentationPlan::canonicalHash($c['roomMetadata']);
        $v=self::candidate($c);$this->rejects(fn()=>$this->parsed($c,$v));$v['metadataAgreement']['status']='unknown';
        $this->assertSame(Profiles::BEDROOM,$this->parsed($c,$v)['route']['profileId']);
        $v['room']['function']='unknown';$this->rejects(fn()=>$this->parsed($c,$v));
    }
    public function test_all_bindings_images_and_no_client_route_claim_are_enforced(): void
    {
        [$c,$images]=self::fixture();$v=self::candidate($c);
        foreach(array_diff(P::BINDINGS,['version','style']) as $key){$bad=$v;$bad[$key]=str_repeat('0',64);$this->rejects(fn()=>$this->parsed($c,$bad));}
        $this->rejects(fn()=>$this->parsed($c,$v+['profileId'=>Profiles::BEDROOM]));
        $bad=$v;$bad['opticalDependencies']['evidenceViewIds']=['source'];$this->rejects(fn()=>$this->parsed($c,$bad));
        [$images[1],$images[2]]=[$images[2],$images[1]];$this->rejects(fn()=>P::request($c,$images));
        $bad=$c;$bad['roomMetadata']['roomName']='LIVING';$this->rejects(fn()=>P::context($bad));
    }
    public function test_each_text_limit_is_in_schema_and_producer_prompt_and_at_limit_is_parseable(): void
    {
        [$c]=self::fixture();$v=self::candidate($c);$schema=P::schema()['properties'];
        foreach(['room','occupancy','materialPotential','metadataAgreement','intentCompatibility','opticalDependencies'] as $key){
            $this->assertSame(550,$schema[$key]['properties']['reason']['maxLength']);$v[$key]['reason']=str_repeat('x',550);}
        $this->assertSame(900,$schema['summary']['maxLength']);$v['summary']=str_repeat('x',900);
        $this->assertSame(220,$schema['occupancy']['properties']['existingFurnishings']['items']['properties']['description']['maxLength']);
        $this->assertSame(220,$schema['materialPotential']['properties']['eligibleSurfaces']['items']['properties']['description']['maxLength']);
        $this->assertSame(220,$schema['materialPotential']['properties']['styleFeatures']['items']['properties']['description']['maxLength']);
        $this->assertSame('route_candidate',$this->parsed($c,$v)['route']['status']);
        $v['summary'].='x';$this->rejects(fn()=>$this->parsed($c,$v));
        foreach(['550','220','900'] as $bound)$this->assertStringContainsString($bound,P::INSTRUCTIONS);
    }
    public function test_empty_room_cannot_claim_material_restylability_and_furnished_palette_is_not_full_style(): void
    {
        [$c]=self::fixture();$v=self::candidate($c);$v['materialPotential']['status']='supported';$this->rejects(fn()=>$this->parsed($c,$v));
        $v=self::candidate($c);$v['occupancy']['existingFurnishings']=[['description'=>'Existing bed.','evidenceViewIds'=>['source']]];$this->rejects(fn()=>$this->parsed($c,$v));
        $v['occupancy']['status']='furnished';$held=$this->parsed($c,$v);$this->assertSame('EXISTING_MATERIAL_RESTYLE_UNSUPPORTED',$held['route']['reviewReasons'][0]);
        $v['occupancy']['status']='partial';$this->assertSame('needs_review',$this->parsed($c,$v)['route']['status']);
    }
    private function rejects(callable $f): void
    {try{$f();$this->fail('Invalid room authority accepted.');}catch(\InvalidArgumentException $e){$this->assertNotEmpty($e->getMessage());}}
}
