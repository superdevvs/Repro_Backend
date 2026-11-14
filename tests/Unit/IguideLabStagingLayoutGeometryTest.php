<?php
namespace Tests\Unit;

use App\Services\IguideLab\LabStagingLayoutGeometry as Geometry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class IguideLabStagingLayoutGeometryTest extends TestCase
{
    private function quad(float $x, float $y, float $w, float $h): array { return [[$x,$y],[$x+$w,$y],[$x+$w,$y+$h],[$x,$y+$h]]; }
    private function layout(): array
    {
        return ['visibleFloor'=>[['id'=>'visible_floor','polygon'=>$this->quad(.1,.4,.8,.5)]], 'forbiddenFloor'=>[], 'protectedFeatures'=>[],
            'routes'=>[['id'=>'opening_route','polygon'=>$this->quad(.82,.42,.06,.45)]],
            'targets'=>[
                ['id'=>'sofa','footprint'=>$this->quad(.15,.52,.15,.2),'editEnvelope'=>[.12,.3,.32,.75]],
                ['id'=>'coffee_table','footprint'=>$this->quad(.4,.6,.13,.12),'editEnvelope'=>[.37,.48,.55,.76]],
                ['id'=>'chair','footprint'=>$this->quad(.65,.56,.12,.16),'editEnvelope'=>[.61,.36,.8,.76]],
            ]];
    }
    public function test_valid_proxies_do_not_claim_source_or_realized_truth(): void
    {
        $r=Geometry::inspect($this->layout());$this->assertTrue($r['numericallyConsistent']);
        foreach(['sourceFloorCertified','metricClearanceVerified','realizedPlacementVerified','supportMaskAuthority'] as $key)$this->assertFalse($r[$key]);
    }
    public function test_ten_percent_margin_and_contact_envelope_are_enforced(): void
    {
        $v=$this->layout();$v['targets'][0]['editEnvelope'][0]=.099;$r=Geometry::inspect($v);
        $this->assertContains('sofa:outer_ten_percent_guard',$r['reasons']);
        $v=$this->layout();$v['targets'][0]['editEnvelope'][3]=.65;$this->assertContains('sofa:footprint_outside_edit_envelope',Geometry::inspect($v)['reasons']);
    }
    public function test_floor_hole_and_route_touch_do_not_pass(): void
    {
        $v=$this->layout();$v['forbiddenFloor']=[['id'=>'hearth','polygon'=>$this->quad(.2,.6,.02,.02)]];
        $this->assertContains('sofa:floor_clearance_overlap:hearth',Geometry::inspect($v)['reasons']);
        $v=$this->layout();$v['routes'][0]['polygon']=$this->quad(.77,.4,.1,.4);
        $this->assertContains('chair:floor_clearance_overlap:opening_route',Geometry::inspect($v)['reasons']);
    }
    public function test_fixed_feature_and_other_footprint_overlap_hold(): void
    {
        $v=$this->layout();$v['protectedFeatures']=[['id'=>'panel','polygon'=>$this->quad(.2,.35,.05,.1)]];
        $this->assertContains('sofa:protected_feature_overlap:panel',Geometry::inspect($v)['reasons']);
        $v=$this->layout();$v['targets'][1]['footprint']=$v['targets'][0]['footprint'];
        $this->assertContains('sofa:footprint_overlap:coffee_table',Geometry::inspect($v)['reasons']);
    }
    public function test_hidden_floor_and_unobserved_route_hold(): void
    {
        $v=$this->layout();$v['visibleFloor'][0]['polygon']=$this->quad(.25,.4,.65,.5);$r=Geometry::inspect($v);
        $this->assertContains('sofa:footprint_not_inside_one_visible_floor_region',$r['reasons']);
        $v=$this->layout();$v['routes'][0]['polygon']=$this->quad(.82,.2,.06,.5);
        $this->assertContains('route_not_inside_visible_floor:opening_route',Geometry::inspect($v)['reasons']);
    }
    public function test_reversed_valid_polygon_and_target_order_are_semantically_stable(): void
    {
        $v=$this->layout();$expected=Geometry::inspect($v);$v['targets']=array_reverse($v['targets']);
        foreach($v['targets'] as &$target)$target['footprint']=array_reverse($target['footprint']);
        $v['visibleFloor'][0]['polygon']=array_reverse($v['visibleFloor'][0]['polygon']);
        $this->assertEquals($expected,Geometry::inspect($v));
    }
    public function test_malformed_self_crossing_nonfinite_and_duplicate_ids_rejected(): void
    {
        $cases=[];$v=$this->layout();$v['targets'][0]['footprint']=[[.1,.5],[.3,.7],[.3,.5],[.1,.7]];$cases[]=$v;
        $v=$this->layout();$v['targets'][0]['editEnvelope'][0]=NAN;$cases[]=$v;
        $v=$this->layout();$v['targets'][1]['id']='sofa';$cases[]=$v;
        foreach($cases as $case){try{Geometry::inspect($case);$this->fail('Malformed proxy accepted');}catch(InvalidArgumentException){$this->addToAssertionCount(1);}}
    }
}
