<?php
namespace Tests\Unit;
use App\Services\IguideLab\{LabInteriorMaterialSourceProtocol as P,LabInteriorMaterialCandidateProtocol as C,LabInteriorDesignProtocol as V,LabInteriorDesignCutProtocol as Old,LabAnalysisRecipes,LabMachineSegmentationPlan as H,LabAnalysisSourceContext};
use Illuminate\Support\Str;
use Tests\TestCase;

class IguideLabInteriorMaterialSourceProtocolTest extends TestCase
{
    public static function fixture(): array
    {
        [$c,$images]=IguideLabInteriorDesignCutProtocolTest::fixture();
        $c['sourceContext']['binding']['nativeDimensions']=['width'=>4096,'height'=>2048];
        $c['sourceContext']['bindingSha256']=LabAnalysisRecipes::hash($c['sourceContext']['binding']);
        $c['sourceInspection']=P::initial();return [$c,$images];
    }
    public static function proposal(array $c,bool $details=false): array
    {
        $v=IguideLabInteriorDesignCutProtocolTest::candidate($c);$v['version']=P::VERSION;
        $v+=['followupOrdinal'=>$c['sourceInspection']['followupOrdinal'],'inspectionContextSha256'=>H::canonicalHash($c),'detailRequests'=>[]];
        if($details){$v['status']='detail_required';$v['targets']=[];$v['foreground']=[];$v['generationPrompt']=null;
            foreach(P::RECOVERABLE as $key)$v['checks'][$key]['status']='uncertain';
            $v['detailRequests']=[['id'=>'source_detail_1','pose'=>['yaw'=>30,'pitch'=>-15,'hFov'=>40],'checkIds'=>P::RECOVERABLE,'evidenceViewIds'=>['source','parent_front'],'reason'=>'Closer original samples may separate contact boundaries and resolve the original reflective region.']];}
        if($v['followupOrdinal']===1)foreach(P::RECOVERABLE as $key)$v['checks'][$key]['evidenceViewIds'][]='source_detail_1';
        return $v;
    }
    public static function followup(array $c,array $images): array
    {
        $parent=P::parse(V::response(self::proposal($c,true)),$c);
        $d=['version'=>'native-grid-sampling/1','sourceWidth'=>4096,'sourceHeight'=>2048,'outputWidth'=>2048,'outputHeight'=>2048,'sampleGridSize'=>33,
            'minAxisFootprint'=>['min'=>.2,'p50'=>.3,'max'=>.4],'maxAxisFootprint'=>['min'=>.3,'p50'=>.4,'max'=>.5],
            'upsampledGridFraction'=>1,'samplingMethod'=>'lanczos-mipmap-trilinear-bilinear','independentOpticalResolutionKnown'=>false];
        $c['sourceInspection']=['version'=>P::VERSION,'followupOrdinal'=>1,'parent'=>['id'=>(string)Str::uuid(),'requestId'=>(string)Str::uuid(),
            'resultSha256'=>LabAnalysisRecipes::hash($parent),'responseSha256'=>str_repeat('c',64),'contextSha256'=>H::canonicalHash($c),'result'=>$parent],
            'details'=>[['id'=>'source_detail_1','sha256'=>$c['sourceContext']['views'][0]['sha256'],'width'=>2048,'height'=>2048,
                'nativeSourceSha256'=>$c['sourceContext']['binding']['nativeSourceSha256'],'pose'=>$parent['detailRequests'][0]['pose'],'sourceSampling'=>$d]],'manifestSha256'=>str_repeat('d',64)];
        $images[]=$images[0];return [$c,$images];
    }
    public function test_resolution_request_has_no_mask_image_or_material_authority_and_old_hold_is_not_reinterpreted(): void
    {
        [$c,$images]=self::fixture();$v=self::proposal($c,true);$parsed=P::parse(V::response($v),$c);
        foreach(['sourceMaskSelectionAuthorized','imageGenerationAuthorized','materialTransferAuthorized','manualAcceptance','crossViewVerified'] as $key)$this->assertFalse($parsed[$key]);
        $this->assertSame([],$parsed['targets']);$this->assertNull($parsed['generationPrompt']);
        $wire=P::request($c,$images);$this->assertSame([H::canonicalHash($c)],$wire['text']['format']['schema']['properties']['inspectionContextSha256']['enum']);
        $old=$v;$old['version']=Old::VERSION;$this->rejects(fn()=>P::parse(V::response($old),$c));
        $base=$c;unset($base['sourceInspection']);$this->rejects(fn()=>Old::parse(V::response($v),$base));
    }
    public function test_each_critical_nonpass_or_recoverable_failure_cannot_spend_detail_followup(): void
    {
        [$c]=self::fixture();$v=self::proposal($c,true);
        foreach(P::CRITICAL as $key)foreach(['fail','uncertain'] as $status){$x=$v;$x['checks'][$key]['status']=$status;$this->rejects(fn()=>P::parse(V::response($x),$c));}
        foreach(P::RECOVERABLE as $key){$x=$v;$x['checks'][$key]['status']='fail';$this->rejects(fn()=>P::parse(V::response($x),$c));}
        $x=$v;$x['detailRequests'][0]['checkIds']=['optical_dependencies'];$this->rejects(fn()=>P::parse(V::response($x),$c));
        $c['sourceContext']['binding']['nativeDimensions']=['width'=>2048,'height'=>1024];$c['sourceContext']['bindingSha256']=LabAnalysisRecipes::hash($c['sourceContext']['binding']);
        $this->rejects(fn()=>P::parse(V::response(self::proposal($c,true)),$c));
    }
    public function test_new_evidence_can_yield_fresh_plan_but_uncertainty_still_holds_and_no_second_followup(): void
    {
        [$c,$images]=self::followup(...self::fixture());$v=self::proposal($c);$parsed=P::parse(V::response($v),$c);
        $this->assertTrue($parsed['sourceMaskSelectionAuthorized']);$this->assertFalse($parsed['imageGenerationAuthorized']);
        $wire=P::request($c,$images);$parts=array_values(array_filter($wire['input'][0]['content'],fn($p)=>$p['type']==='input_image'));
        $this->assertCount(7,$parts);$this->assertSame('original',$parts[6]['detail']);$this->assertSame(array_fill(0,6,'high'),array_column(array_slice($parts,0,6),'detail'));
        $held=$v;$held['status']='needs_review';$held['targets']=[];$held['foreground']=[];$held['generationPrompt']=null;$held['checks']['optical_dependencies']['status']='uncertain';
        $this->assertFalse(P::parse(V::response($held),$c)['sourceMaskSelectionAuthorized']);
        $this->rejects(fn()=>P::parse(V::response(self::proposal($c,true)),$c));
        $v['checks']['optical_dependencies']['evidenceViewIds']=LabAnalysisSourceContext::IDS;$this->rejects(fn()=>P::parse(V::response($v),$c));
    }
    public function test_parent_result_pose_scope_and_exact_detail_bytes_cannot_drift(): void
    {
        [$c,$images]=self::followup(...self::fixture());$good=self::proposal($c);
        foreach(['nativeSourceSha256','sha256'] as $key){$bad=$c;$bad['sourceInspection']['details'][0][$key]=str_repeat('0',64);$this->rejects(fn()=>P::request($bad,$images));}
        $bad=$c;$bad['sourceInspection']['details'][0]['pose']['yaw']++;$this->rejects(fn()=>P::request($bad,$images));
        $bad=$c;$bad['sourceInspection']['parent']['resultSha256']=str_repeat('0',64);$this->rejects(fn()=>P::request($bad,$images));
        $bad=$good;$bad['targets'][0]['plannedChange']='A new layout';$this->rejects(fn()=>P::parse(V::response($bad),$c));
        $bad=$good;$bad['inspectionContextSha256']=str_repeat('0',64);$this->rejects(fn()=>P::parse(V::response($bad),$c));
        $badImages=$images;$badImages[6]=$images[1];$this->rejects(fn()=>P::request($c,$badImages));
    }
    public function test_maximum_valid_bound_text_is_exposed_in_new_schema_and_requests_are_source_only(): void
    {
        [$c,$images]=self::fixture();$v=self::proposal($c,true);$v['detailRequests'][0]['reason']=str_repeat('x',550);$v['summary']=str_repeat('s',1200);
        $this->assertSame(550,strlen(P::parse(V::response($v),$c)['detailRequests'][0]['reason']));$wire=P::request($c,$images);
        $this->assertSame(550,$wire['text']['format']['schema']['properties']['checks']['properties']['optical_dependencies']['properties']['reason']['maxLength']);
        $v['detailRequests'][0]['evidenceViewIds']=['candidate'];$this->rejects(fn()=>P::parse(V::response($v),$c));
        $v=self::proposal($c,true);$v['detailRequests'][0]['pose']['pitch']=86;$this->rejects(fn()=>P::parse(V::response($v),$c));
    }
    public function test_actual_donor_request_retains_native_details_and_rejects_missing_detail_citations(): void
    {
        [$cut,$images]=self::followup(...self::fixture());$parsed=P::parse(V::response(self::proposal($cut)),$cut);
        $views=array_map(fn($v)=>array_intersect_key($v,array_flip(['id','sha256','width','height'])),P::views($cut));
        array_splice($views,1,0,[['id'=>'candidate','sha256'=>$views[0]['sha256'],'width'=>2048,'height'=>2048]]);array_splice($images,1,0,[$images[0]]);
        $c=['version'=>C::VERSION,'cutContext'=>$cut,'cutResult'=>$parsed,'cutResultSha256'=>LabAnalysisRecipes::hash($parsed),
            'candidateSha256'=>$views[1]['sha256'],'candidateRunId'=>(string)Str::uuid(),'views'=>$views];
        $v=IguideLabInteriorDesignCandidateProtocolTest::candidate($c);$v['version']=C::VERSION;
        foreach($v['checks'] as &$check)$check['evidenceViewIds']=C::ids($c);unset($check);
        $this->assertTrue(C::parse(V::response($v),$c)['candidateMaskSelectionAuthorized']);
        $wire=C::request($c,$images);$parts=array_values(array_filter($wire['input'][0]['content'],fn($p)=>$p['type']==='input_image'));
        $this->assertCount(8,$parts);$this->assertSame('original',$parts[7]['detail']);$this->assertSame([$c['cutResultSha256']],$wire['text']['format']['schema']['properties']['cutResultSha256']['enum']);
        $v['checks']['protected_foreground']['evidenceViewIds']=\App\Services\IguideLab\LabInteriorDesignCandidateProtocol::IDS;
        $this->rejects(fn()=>C::parse(V::response($v),$c));
    }
    public function test_bound_framing_wire_keeps_parser_and_rejects_mistyped_prepared_identity(): void
    {
        [$c,$images]=IguideLabInteriorDesignFramingProtocolTest::fixture();$wire=\App\Services\IguideLab\LabInteriorMaterialFramingWire::request($c,$images);
        foreach(['version','nativeSourceSha256','preparedSourceSha256','sourceAuthoritySha256','intentSha256','style'] as $key)$this->assertSame([$c[$key]],$wire['text']['format']['schema']['properties'][$key]['enum']);
        $v=IguideLabInteriorDesignFramingProtocolTest::candidate($c);$this->assertSame('framing_candidate',\App\Services\IguideLab\LabInteriorMaterialFramingWire::parse(V::response($v),$c)['status']);
        $v['preparedSourceSha256']='7205e5692f7e7b0413e6abcc76cf9696085341ce75806098ee3ee31d88b9e12e';
        $this->rejects(fn()=>\App\Services\IguideLab\LabInteriorMaterialFramingWire::parse(V::response($v),$c));
    }
    private function rejects(callable $fn): void {try{$fn();$this->fail('Invalid source-detail authority accepted.');}catch(\InvalidArgumentException $e){$this->assertNotEmpty($e->getMessage());}}
}
