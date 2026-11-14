<?php
namespace App\Services\IguideLab;

/** Exact cut scene plan plus an explicit pre-image gate; a source framing candidate is never a pass. */
final class LabLifestyleOpticalCutProtocol
{
    public const VERSION='responses-lifestyle-cut-gate-v2';
    public const GATE_VERSION='lifestyle-cut-readiness/2';
    public const VIEW_RECIPE='section-parent-native-optical8/2';
    public const CHECKS=['group_coverage','frame_margin','source_floor','circulation','fixed_features','optical_dependencies','ambient_contact_lighting'];
    public static function compile(array $recipe,array $context,array $images): array
    {
        self::binding($context);$base=$context;unset($base['lifestyleFraming']);$base['viewIds']=LabAnalysisSourceContext::IDS;
        $views=$context['lifestyleFraming']['opticalDetails']['views'];
        if(count($images)!==6+count($views))throw new \InvalidArgumentException('Native context evidence count changed.');
        $total=0;foreach($images as $image){if(!is_string($image)||!preg_match('/\Adata:image\/png;base64,([A-Za-z0-9+\/=]+)\z/D',$image,$m))throw new \InvalidArgumentException('PNG evidence required.');
            $bytes=base64_decode($m[1],true);if($bytes===false||($total+=strlen($bytes))>24*1024*1024)throw new \InvalidArgumentException('Evidence byte limit exceeded.');}
        $payload=LabSectionAnalysisProtocol::compile($recipe,$base,array_slice($images,0,6));
        foreach($views as $i=>$v){$image=$images[6+$i];$bytes=base64_decode(substr($image,22),true);$size=@getimagesizefromstring($bytes);
            if(!$size||[$size[0],$size[1],$size['mime']]!==[2048,2048,'image/png']||hash('sha256',$bytes)!==$v['sha256'])throw new \InvalidArgumentException('Native context bytes changed.');
            $payload['input'][0]['content'][]=['type'=>'input_text','text'=>'View ID: '.$v['id'].'; original native projection, context only; rendered pixels can interpolate the source grid, not independent optical samples.'];
            $payload['input'][0]['content'][]=['type'=>'input_image','image_url'=>$image,'detail'=>'high'];}
        $payload['input'][0]['content'][0]['text']=json_encode($context,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        return $payload;
    }

    public static function parse(array $response,array $context): array
    {
        self::binding($context);$structured=LabLifestyleFramingProtocol::structured($response);$gate=$structured['lifestyleReadiness']??null;unset($structured['lifestyleReadiness']);
        LabLifestyleFramingProtocol::keys($gate,['version','sourceSha256','nativeSourceSha256','framingResultSha256','checks']);
        $binding=$context['lifestyleFraming'];
        if($gate['version']!==self::GATE_VERSION||$gate['sourceSha256']!==$context['sourceSha256']||$gate['nativeSourceSha256']!==$binding['nativeSourceSha256']
            ||$gate['framingResultSha256']!==$binding['resultSha256'])throw new \InvalidArgumentException('Cut gate belongs to another source or framing.');
        LabLifestyleFramingProtocol::keys($gate['checks'],self::CHECKS);$ready=true;
        foreach($gate['checks'] as $name=>$check){LabLifestyleFramingProtocol::keys($check,['status','evidenceViewIds','reason']);
            if(!in_array($check['status'],['pass','fail','uncertain'],true))throw new \InvalidArgumentException('Invalid cut readiness status.');
            LabLifestyleFramingProtocol::evidence($check,$context['viewIds']);$ready=$ready&&$check['status']==='pass';
            if(!in_array('source',$check['evidenceViewIds'],true))throw new \InvalidArgumentException('Cut gate must inspect the actual cut.');
            if(in_array($name,['circulation','optical_dependencies','ambient_contact_lighting'],true)&&!array_intersect(array_slice(LabAnalysisSourceContext::IDS,1),$check['evidenceViewIds']))throw new \InvalidArgumentException('Cut gate must inspect native-parent context.');
            if($name==='optical_dependencies'&&array_diff(array_column($binding['opticalDetails']['views'],'id'),$check['evidenceViewIds']))throw new \InvalidArgumentException('Optical check must cite every supplied native detail.');}
        $base=$context;unset($base['lifestyleFraming'],$base['sourceContext']);$plain=$response;
        $plain['output']=[['type'=>'message','role'=>'assistant','status'=>'completed','content'=>[['type'=>'output_text','text'=>json_encode($structured,JSON_THROW_ON_ERROR)]]]];
        $parsed=LabScenePlanProtocol::parseWithSourceDetails($plain,$base);
        $gate+=['allRequiredChecksPass'=>$ready,'sourceFramingId'=>$binding['id'],'generationAuthority'=>'actual_cut_checks_only','manualAcceptance'=>false];
        return $parsed+['lifestyleReadiness'=>$gate];
    }
    private static function hash(mixed $v): void {if(!is_string($v)||!preg_match('/\A[a-f0-9]{64}\z/D',$v))throw new \InvalidArgumentException('Invalid optical context digest.');}
    private static function binding(array $context): void
    {
        $b=$context['lifestyleFraming']??null;LabLifestyleFramingProtocol::keys($b,['version','id','resultSha256','nativeSourceSha256','view','constraints','opticalDetails']);
        if($b['version']!==LabLifestyleOpticalProtocol::VERSION||!\Illuminate\Support\Str::isUuid($b['id'])
            ||!preg_match('/\A[a-f0-9]{64}\z/D',$b['resultSha256'])||$b['nativeSourceSha256']!==($context['sourceContext']['binding']['nativeSourceSha256']??null)
            ||$context['preset']!=='lifestyle')throw new \InvalidArgumentException('Invalid cut framing authority.');
        $d=$b['opticalDetails'];LabLifestyleFramingProtocol::keys($d,['version','sourceAuthoritySha256','responseSha256','parent','manifestSha256','views']);
        if($d['version']!=='lifestyle-bound-native-optical-context/1'||!is_array($d['views'])||!array_is_list($d['views'])||count($d['views'])>2)throw new \InvalidArgumentException('Invalid native optical context.');
        foreach(['sourceAuthoritySha256','responseSha256'] as $key)self::hash($d[$key]);
        if(!$d['views']){if($d['parent']!==null||$d['manifestSha256']!==null)throw new \InvalidArgumentException('Initial framing cannot invent details.');}
        else {LabLifestyleFramingProtocol::keys($d['parent'],['id','resultSha256','responseSha256']);if(!\Illuminate\Support\Str::isUuid($d['parent']['id'])||$d['parent']['id']===$b['id'])throw new \InvalidArgumentException('Invalid native context parent.');
            foreach(['resultSha256','responseSha256'] as $key)self::hash($d['parent'][$key]);self::hash($d['manifestSha256']);}
        $dimensions=$context['sourceContext']['binding']['nativeDimensions'];
        foreach($d['views'] as $i=>$v){LabLifestyleFramingProtocol::keys($v,['id','sha256','width','height','nativeSourceSha256','pose','sourceSampling']);self::hash($v['sha256']);
            if($v['id']!==LabLifestyleOpticalProtocol::DETAILS[$i]||[$v['width'],$v['height']]!==[2048,2048]||$v['nativeSourceSha256']!==$b['nativeSourceSha256'])throw new \InvalidArgumentException('Native detail source changed.');
            LabLifestyleOpticalSampling::validate($v['sourceSampling'],$dimensions['width'],$dimensions['height']);
            LabLifestyleOpticalProtocol::detail(['id'=>$v['id'],'pose'=>$v['pose'],'evidenceViewIds'=>['source'],'reason'=>'Validate original detail pose.'],$i);}
        if($context['viewIds']!==[...LabAnalysisSourceContext::IDS,...array_column($d['views'],'id')])throw new \InvalidArgumentException('Native context view order changed.');
        $base=$context;unset($base['lifestyleFraming']);$base['viewIds']=LabAnalysisSourceContext::IDS;LabSectionAnalysisProtocol::validateContext($base);
        LabLifestyleFramingProtocol::keys($b['view'],['yaw','pitch','hFov']);
        foreach(['yaw'=>[-180,180],'pitch'=>[-60,15],'hFov'=>[30,120]] as $key=>[$low,$high]) {
            $n=$b['view'][$key];
            if((!is_int($n)&&!is_float($n))||!is_finite((float)$n)||$n<$low||$n>$high
                ||(float)$n!==(float)($context['sourceContext']['binding']['view'][$key]??NAN))throw new \InvalidArgumentException('Cut is not the proposed free framing.');
        }
        LabLifestyleFramingProtocol::keys($b['constraints'],LabLifestyleFramingProtocol::CONSTRAINTS);
        foreach($b['constraints'] as $check) {
            LabLifestyleFramingProtocol::keys($check,['status','evidenceViewIds','reason']);
            if(!in_array($check['status'],['supported','verify_on_cut'],true))throw new \InvalidArgumentException('Blocked source framing cannot authorize a cut gate.');
            LabLifestyleFramingProtocol::evidence($check,$b['version']===LabLifestyleOpticalProtocol::VERSION?[...LabLifestyleFramingProtocol::IDS,...LabLifestyleOpticalProtocol::DETAILS]:LabLifestyleFramingProtocol::IDS);LabLifestyleFramingProtocol::text($check['reason'],550);
        }
    }
}
