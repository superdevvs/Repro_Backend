<?php
namespace Tests\Unit;
use App\Services\IguideLab\{LabLifestyleOpticalProtocol as P,LabLifestyleFramingProtocol as Legacy,LabAnalysisRecipes};
use Illuminate\Support\Str;
use Tests\TestCase;

class IguideLabLifestyleOpticalProtocolTest extends TestCase
{
    public static function sampling(int $w=4096,int $h=2048): array
    {return ['version'=>'native-grid-sampling/1','sourceWidth'=>$w,'sourceHeight'=>$h,'outputWidth'=>2048,'outputHeight'=>2048,'sampleGridSize'=>33,
        'minAxisFootprint'=>['min'=>0.2,'p50'=>0.3,'max'=>0.5],'maxAxisFootprint'=>['min'=>0.3,'p50'=>0.4,'max'=>0.6],
        'upsampledGridFraction'=>1,'samplingMethod'=>'lanczos-mipmap-trilinear-bilinear','independentOpticalResolutionKnown'=>false];}
    public static function fixture(): array
    {
        [$c,$images]=IguideLabLifestyleFramingProtocolTest::fixture();$c['version']=P::VERSION;
        $c['nativeDimensions']=['width'=>4096,'height'=>2048];$c['followupOrdinal']=0;$c['parent']=null;return [$c,$images];
    }
    public static function candidate(array $c): array
    {
        $v=IguideLabLifestyleFramingProtocolTest::candidate($c);$v['version']=P::VERSION;$v['contextSha256']=LabAnalysisRecipes::hash($c);$v['followupOrdinal']=$c['followupOrdinal'];$v['detailRequests']=[];
        $v['constraints']['optical_dependencies']=['status'=>'supported','evidenceViewIds'=>array_merge(['source'],array_slice(array_column($c['views'],'id'),7)),'reason'=>'Inspect visible source and requested detail; no relevant reflection supported by this fixture.'];return $v;
    }
    public static function enquiry(array $c): array
    {
        $v=self::candidate($c);$v['status']='detail_required';$v['view']=null;$v['constraints']['optical_dependencies']['status']='resolution_limited';
        $v['detailRequests']=[['id'=>'source_detail_1','pose'=>['yaw'=>90,'pitch'=>0,'hFov'=>40],'evidenceViewIds'=>['right'],
            'reason'=>'Visible right glazing is undersampled; inspect its surface and adjacent original standing region.']];return $v;
    }
    public static function followup(array $c,array $images): array
    {
        $result=P::parse(IguideLabLifestyleFramingProtocolTest::response(self::enquiry($c)),$c);
        $c['followupOrdinal']=1;$c['parent']=['id'=>(string)Str::uuid(),'resultSha256'=>LabAnalysisRecipes::hash($result),'responseSha256'=>str_repeat('d',64),'result'=>$result];
        $im=imagecreatetruecolor(2048,2048);imagefill($im,0,0,imagecolorallocate($im,110,150,190));ob_start();imagepng($im);$png=ob_get_clean();imagedestroy($im);
        $c['views'][]=['id'=>'source_detail_1','sha256'=>hash('sha256',$png),'width'=>2048,'height'=>2048,'nativeSourceSha256'=>$c['sourceSha256'],'pose'=>$result['detailRequests'][0]['pose'],'sourceSampling'=>self::sampling()];
        $images[]='data:image/png;base64,'.base64_encode($png);return [$c,$images];
    }
    public function test_followup_has_all_original_views_and_true_detail_binding_without_generation_authority(): void
    {
        [$c,$images]=self::fixture();[$c,$images]=self::followup($c,$images);$wire=P::request($c,$images);
        $this->assertSame($c+['contextSha256'=>LabAnalysisRecipes::hash($c)],json_decode($wire['input'][0]['content'][0]['text'],true));$this->assertCount(17,$wire['input'][0]['content']);
        $v=P::parse(IguideLabLifestyleFramingProtocolTest::response(self::candidate($c)),$c);
        $this->assertFalse($v['imageGenerationAuthorized']);$this->assertFalse($v['geometryVerified']);$this->assertFalse($v['manualAcceptance']);
        $this->assertSame(1,$v['followupOrdinal']);$this->assertStringNotContainsString('maxLength',json_encode(P::schema()));
    }
    public function test_physical_dependencies_and_nonoptical_uncertainty_cannot_request_details(): void
    {
        [$c]=self::fixture();$good=self::enquiry($c);
        foreach(['physically_unsupported','unresolved','supported'] as $status){$v=$good;$v['constraints']['optical_dependencies']['status']=$status;$this->reject($v,$c);}
        foreach(['source_floor','circulation','ambient_contact_lighting'] as $key){$v=$good;$v['constraints'][$key]['status']='resolution_limited';$this->reject($v,$c);}
        $v=$good;$v['sourcePeople']='uncertain';$this->reject($v,$c);
    }
    public function test_two_requested_details_keep_all_seven_originals_and_every_detail_must_be_cited(): void
    {
        [$c,$images]=self::fixture();[$c,$images]=self::followup($c,$images);
        $d=$c['parent']['result']['detailRequests'][0];$d['id']='source_detail_2';$d['pose']['yaw']=-90;
        $c['parent']['result']['detailRequests'][]=$d;$c['parent']['resultSha256']=LabAnalysisRecipes::hash($c['parent']['result']);
        $v=$c['views'][7];$v['id']=$d['id'];$v['pose']=$d['pose'];$c['views'][]=$v;$images[]=$images[7];
        $wire=P::request($c,$images);$this->assertCount(19,$wire['input'][0]['content']);
        $result=self::candidate($c);$this->assertSame('framing_candidate',P::parse(IguideLabLifestyleFramingProtocolTest::response($result),$c)['status']);
        $result['constraints']['optical_dependencies']['evidenceViewIds']=['source','source_detail_1'];$this->reject($result,$c);
    }
    public function test_clearer_details_do_not_clear_optics_or_permit_another_followup(): void
    {
        [$c,$images]=self::fixture();[$c]=self::followup($c,$images);$v=self::candidate($c);
        foreach(['resolution_limited','physically_unsupported','unresolved','verify_on_cut'] as $status){$x=$v;$x['constraints']['optical_dependencies']['status']=$status;$this->reject($x,$c);}
        $held=$v;$held['status']='needs_review';$held['view']=null;$held['constraints']['optical_dependencies']['status']='unresolved';
        $this->assertSame('needs_review',P::parse(IguideLabLifestyleFramingProtocolTest::response($held),$c)['status']);
        $this->reject(self::enquiry($c),$c);$v['constraints']['optical_dependencies']['evidenceViewIds']=['source'];$this->reject($v,$c);
    }
    public function test_unknown_constraint_invalid_pose_binding_and_operator_fields_rejected(): void
    {
        [$c]=self::fixture();$v=self::enquiry($c);$bad=[];
        $x=$v;$x['detailRequests'][0]['pose']['hFov']=71;$bad[]=$x;
        $x=$v;$x['detailRequests'][0]['pose']['pitch']=86;$bad[]=$x;
        $x=$v;$x['detailRequests'][0]['evidenceViewIds']=['candidate'];$bad[]=$x;
        $x=$v;$x['detailRequests']=array_fill(0,3,$x['detailRequests'][0]);$bad[]=$x;
        $x=$v;$x['contextSha256']=str_repeat('f',64);$bad[]=$x;
        $x=$v;$x['operatorApproved']=true;$bad[]=$x;
        $x=$v;$x['constraints']['unknown']=$x['constraints']['source_floor'];$bad[]=$x;
        foreach($bad as $x)$this->reject($x,$c);
    }
    private function reject(array $v,array $c): void
    {try{P::parse(IguideLabLifestyleFramingProtocolTest::response($v),$c);$this->fail('Unsafe optical response accepted.');}catch(\InvalidArgumentException $e){$this->assertNotEmpty($e->getMessage());}}
    public function test_context_native_receipt_pose_and_image_tamper_are_rejected(): void
    {
        [$c,$images]=self::fixture();[$c,$images]=self::followup($c,$images);$mutations=[
            fn($v)=>array_replace_recursive($v,['views'=>[7=>['nativeSourceSha256'=>str_repeat('f',64)]]]),
            fn($v)=>array_replace_recursive($v,['views'=>[7=>['pose'=>['yaw'=>12]]]]),
            fn($v)=>array_replace_recursive($v,['parent'=>['resultSha256'=>str_repeat('f',64)]]),
            fn($v)=>array_replace_recursive($v,['nativeDimensions'=>['width'=>2048,'height'=>1024]])];
        foreach($mutations as $change){try{P::request($change($c),$images);$this->fail('Changed native authority accepted.');}catch(\InvalidArgumentException $e){$this->assertNotEmpty($e->getMessage());}}
        $images[7]=$images[1];$this->expectException(\InvalidArgumentException::class);P::request($c,$images);
    }
    public function test_v1_held_record_retains_original_semantics_and_rejects_v2_status(): void
    {
        [$c]=IguideLabLifestyleFramingProtocolTest::fixture();$v=IguideLabLifestyleFramingProtocolTest::candidate($c);$v['status']='needs_review';$v['view']=null;$v['constraints']['optical_dependencies']['status']='blocked';
        $this->assertSame('blocked',Legacy::parse(IguideLabLifestyleFramingProtocolTest::response($v),$c)['constraints']['optical_dependencies']['status']);
        $v['status']='detail_required';$this->expectException(\InvalidArgumentException::class);Legacy::parse(IguideLabLifestyleFramingProtocolTest::response($v),$c);
    }
}
