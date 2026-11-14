<?php
namespace Tests\Unit;
use App\Services\IguideLab\{LabAnalysisRecipes,LabInteriorDesignCutProtocol as C,LabInteriorDesignCandidateProtocol as P};
use Illuminate\Support\Str;
use Tests\TestCase;
class IguideLabInteriorDesignCandidateProtocolTest extends TestCase
{
    public static function fixture(): array
    {
        [$cut,$images]=IguideLabInteriorDesignCutProtocolTest::fixture();$cutResult=C::parse(IguideLabInteriorDesignFramingProtocolTest::response(IguideLabInteriorDesignCutProtocolTest::candidate($cut)),$cut);
        $im=imagecreatetruecolor(2048,2048);imagefill($im,0,0,imagecolorallocate($im,80,105,130));ob_start();imagepng($im);$bytes=ob_get_clean();imagedestroy($im);
        array_splice($images,1,0,['data:image/png;base64,'.base64_encode($bytes)]);
        $views=[];foreach($cut['sourceContext']['views'] as $v)$views[]=array_intersect_key($v,array_flip(['id','sha256','width','height']));
        array_splice($views,1,0,[['id'=>'candidate','sha256'=>hash('sha256',$bytes),'width'=>2048,'height'=>2048]]);
        return [['version'=>P::VERSION,'cutContext'=>$cut,'cutResult'=>$cutResult,'cutResultSha256'=>LabAnalysisRecipes::hash($cutResult),
            'candidateSha256'=>hash('sha256',$bytes),'candidateRunId'=>(string)Str::uuid(),'views'=>$views],$images];
    }
    public static function candidate(array $c): array
    {
        return ['version'=>P::VERSION,'sourceSha256'=>$c['cutResult']['preparedSourceSha256'],'candidateSha256'=>$c['candidateSha256'],
            'nativeSourceSha256'=>$c['cutResult']['nativeSourceSha256'],'cutResultSha256'=>$c['cutResultSha256'],'style'=>$c['cutResult']['style'],
            'status'=>'ready_for_candidate_masks','foregroundFinding'=>'none_observed',
            'targets'=>array_map(fn($t)=>array_intersect_key($t,array_flip(['id','sourceDescription','registrationSurface','maskPlan'])),$c['cutResult']['targets']),
            'checks'=>array_fill_keys(P::CHECKS,['status'=>'pass','evidenceViewIds'=>P::IDS,'reason'=>'Inspect actual retained material evidence, unchanged geometry and parent context.']),
            'summary'=>'The actual donor is suitable for machine mask selection; registration and style QA remain mandatory.'];
    }
    public function test_actual_candidate_and_original_context_are_bound_with_no_transfer_authority(): void
    {
        [$c,$images]=self::fixture();$wire=P::request($c,$images);$p=P::parse(IguideLabInteriorDesignFramingProtocolTest::response(self::candidate($c)),$c);
        $this->assertSame($c,json_decode($wire['input'][0]['content'][0]['text'],true));$this->assertTrue($p['candidateMaskSelectionAuthorized']);
        $this->assertFalse($p['materialTransferAuthorized']);$this->assertFalse($p['imageGenerationAuthorized']);$this->assertFalse($p['manualAcceptance']);
        [$images[0],$images[1]]=[$images[1],$images[0]];$this->rejects(fn()=>P::request($c,$images));
    }
    public function test_style_colour_geometry_and_optical_uncertainty_hold_before_candidate_masks(): void
    {
        [$c]=self::fixture();$good=self::candidate($c);
        foreach(P::CHECKS as $key){$bad=$good;$bad['checks'][$key]['status']='uncertain';$this->rejects(fn()=>P::parse(IguideLabInteriorDesignFramingProtocolTest::response($bad),$c));}
        $bad=$good;$bad['foregroundFinding']='source_unchanged';$this->rejects(fn()=>P::parse(IguideLabInteriorDesignFramingProtocolTest::response($bad),$c));
        $bad=$good;$bad['status']='needs_review';$bad['targets']=[];$bad['checks']['non_colour_detail']['status']='fail';
        $this->assertFalse(P::parse(IguideLabInteriorDesignFramingProtocolTest::response($bad),$c)['candidateMaskSelectionAuthorized']);
    }
    public function test_changed_candidate_cut_or_targets_and_missing_candidate_evidence_reject(): void
    {
        [$c]=self::fixture();$good=self::candidate($c);
        foreach(['sourceSha256','candidateSha256','nativeSourceSha256','cutResultSha256'] as $key){$bad=$good;$bad[$key]=str_repeat('f',64);$this->rejects(fn()=>P::parse(IguideLabInteriorDesignFramingProtocolTest::response($bad),$c));}
        $bad=$good;$bad['checks']['style_faithfulness']['evidenceViewIds']=['source'];$this->rejects(fn()=>P::parse(IguideLabInteriorDesignFramingProtocolTest::response($bad),$c));
        $bad=$good;$bad['targets'][0]['registrationSurface']='planar';$this->rejects(fn()=>P::parse(IguideLabInteriorDesignFramingProtocolTest::response($bad),$c));
        $bad=$c;$bad['cutResult']['generationPrompt']='Changed intent';$this->rejects(fn()=>P::parse(IguideLabInteriorDesignFramingProtocolTest::response($good),$bad));
        $bad=$c;$bad['views'][2]['sha256']=str_repeat('f',64);$this->rejects(fn()=>P::parse(IguideLabInteriorDesignFramingProtocolTest::response($good),$bad));
    }
    private function rejects(callable $fn): void
    {try{$fn();$this->fail('Unsupported Interior donor accepted.');}catch(\InvalidArgumentException $e){$this->assertNotEmpty($e->getMessage());}}
}
