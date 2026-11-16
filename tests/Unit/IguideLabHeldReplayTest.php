<?php
namespace Tests\Unit;
use App\Services\IguideLab\{LabDeclutterWorkflow,LabLifestyleOpticalProtocol as P};
use Tests\TestCase;

/**
 * Replays the stored real answers that ended with nothing (scratch/iguide-held.json, 14 Nov 2026).
 * Only the stored model answers are replayed: no provider, network or image call is made.
 */
class IguideLabHeldReplayTest extends TestCase
{
    private static function stored(string $name): array
    {return json_decode(file_get_contents(base_path('tests/Fixtures/iguide-lab/held-replay/'.$name.'.json')),true,flags:JSON_THROW_ON_ERROR);}
    private static function response(array $answer): array
    {return ['id'=>'resp-replay','model'=>'gpt-6-astra','status'=>'completed','output'=>[['type'=>'message','role'=>'assistant','status'=>'completed',
        'content'=>[['type'=>'output_text','text'=>json_encode($answer,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]]]]];}

    public static function declutterHolds(): array {return ['d0e5f0'=>['declutter-d0e5f0'],'f94e91'=>['declutter-f94e91']];}
    #[\PHPUnit\Framework\Attributes\DataProvider('declutterHolds')]
    public function test_stored_declutter_manual_review_with_a_removable_target_gets_a_section_from_its_cited_view(string $name): void
    {
        $proposal=self::stored($name)['proposal'];
        $this->assertSame('manual_review',$proposal['workflow']['mode']);$this->assertSame([],$proposal['workflow']['sections']);
        $this->assertFalse(app(LabDeclutterWorkflow::class)->sourceRoute($proposal));
        // Both stored targets cite the front overview (yaw 0); the cut is wide enough that the whole overview sits inside the 10% guard.
        $this->assertSame(['yaw'=>0.0,'pitch'=>-15.0,'hFov'=>120.0],app(LabDeclutterWorkflow::class)->route($proposal));
    }
    public function test_a_model_section_is_still_used_unchanged(): void
    {
        $p=['targets'=>[['id'=>'target_object','action'=>'remove','objectCount'=>1,'evidenceViewIds'=>['source','left']]],
            'workflow'=>['mode'=>'perspective_sections','sections'=>[['id'=>'s','targetIds'=>['target_object'],'yawDegrees'=>10,'pitchDegrees'=>-30,'hfovDegrees'=>65]]]];
        $this->assertSame(['yaw'=>10.0,'pitch'=>-30.0,'hFov'=>65.0],app(LabDeclutterWorkflow::class)->route($p));
    }
    public function test_declutter_with_nothing_to_remove_or_no_direction_still_stops(): void
    {
        $base=self::stored('declutter-f94e91')['proposal'];$w=app(LabDeclutterWorkflow::class);
        $p=$base;$p['targets'][0]['action']='add';$this->assertNull($w->route($p),'no removal target');
        $p=$base;$p['targets'][0]['id']='other';$this->assertNull($w->route($p),'not the selected target');
        $p=$base;$p['targets'][]=$p['targets'][0];$p['targets'][1]['id']='second';$this->assertNull($w->route($p),'more than one target');
        $p=$base;$p['targets'][0]['evidenceViewIds']=['source','zenith','nadir'];$this->assertNull($w->route($p),'no cited overview direction');
        $p=$base;$p['workflow']['mode']='whole_panorama';$this->assertNull($w->route($p),'whole panorama is not a removal route');
        // The cited overview order decides the direction.
        $p=$base;$p['targets'][0]['evidenceViewIds']=['source','left','front'];$this->assertSame(-90.0,$w->route($p)['yaw']);
        $p=$base;$p['targets'][0]['evidenceViewIds']=['back'];$this->assertSame(180.0,$w->route($p)['yaw']);
    }

    public function test_stored_lifestyle_09a2fc_followup_hold_without_a_hard_blocker_becomes_a_caveated_candidate(): void
    {
        $stored=self::stored('lifestyle-09a2fc');[$initial,$followup]=$stored['framings'];
        // The initial enquiry still asks for its native details, unchanged.
        $this->assertSame('detail_required',P::parse(self::response($initial['answer']),$initial['context'])['status']);
        $answer=$followup['answer'];$this->assertSame('needs_review',$answer['status']);$this->assertNull($answer['view']);
        $parsed=P::parse(self::response($answer),$followup['context']);
        $this->assertSame('framing_candidate',$parsed['status']);
        // No overview in the floor evidence (nadir and glazing details only), so the pair's cited front overview sets the direction.
        $this->assertSame(['yaw'=>0.0,'pitch'=>-10.0,'hFov'=>100.0],$parsed['view']);
        $this->assertFalse($parsed['imageGenerationAuthorized']);$this->assertSame([],$parsed['detailRequests']);
        $this->assertSame('verify_on_cut',$parsed['constraints']['optical_dependencies']['status']);
        $this->assertStringContainsString('unresolved',$parsed['constraints']['optical_dependencies']['reason']);
        $this->assertStringContainsString('needs_review',$parsed['constraints']['frame_margin']['reason']);
        foreach($parsed['constraints'] as $c){$this->assertContains($c['status'],['supported','verify_on_cut']);$this->assertLessThanOrEqual(550,mb_strlen($c['reason']));}
        $this->assertLessThanOrEqual(1200,mb_strlen($parsed['summary']));$this->assertStringContainsString('held',$parsed['summary']);
    }
    public function test_lifestyle_holds_with_a_hard_blocker_or_no_floor_direction_stay_held(): void
    {
        [,$followup]=self::stored('lifestyle-09a2fc')['framings'];$base=$followup['answer'];$c=$followup['context'];
        foreach(['people present'=>fn($v)=>['sourcePeople'=>'present']+$v,'people uncertain'=>fn($v)=>['sourcePeople'=>'uncertain']+$v,
            'no plausible pair'=>fn($v)=>['coherentGroup'=>'not_plausible']+$v,
            'no level floor'=>function($v){$v['constraints']['source_floor']['status']='physically_unsupported';return $v;},
            'unresolved floor'=>function($v){$v['constraints']['source_floor']['status']='unresolved';return $v;},
            'no overview direction'=>function($v){foreach(['source_floor','group_coverage','frame_margin'] as $k)$v['constraints'][$k]['evidenceViewIds']=['source','nadir'];return $v;}] as $label=>$change){
            $parsed=P::parse(self::response($change($base)),$c);
            $this->assertSame('needs_review',$parsed['status'],$label);$this->assertNull($parsed['view'],$label);
        }
    }
}
