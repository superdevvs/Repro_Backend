<?php
namespace App\Services\IguideLab;

/** Exact cut scene plan plus an explicit pre-image gate; a source framing candidate is never a pass. */
final class LabLifestyleCutProtocol
{
    public const VERSION='responses-lifestyle-cut-gate-v1';
    public const GATE_VERSION='lifestyle-cut-readiness/1';
    public const CHECKS=['group_coverage','frame_margin','source_floor','circulation','fixed_features','optical_dependencies','ambient_contact_lighting'];
    public static function compile(array $recipe,array $context,array $images): array
    {
        self::binding($context);$base=$context;unset($base['lifestyleFraming']);
        $payload=LabSectionAnalysisProtocol::compile($recipe,$base,$images);
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
            LabLifestyleFramingProtocol::evidence($check,LabAnalysisSourceContext::IDS);$ready=$ready&&$check['status']==='pass';
            if(!in_array('source',$check['evidenceViewIds'],true))throw new \InvalidArgumentException('Cut gate must inspect the actual cut.');
            if(in_array($name,['circulation','optical_dependencies','ambient_contact_lighting'],true)&&!array_intersect(array_slice(LabAnalysisSourceContext::IDS,1),$check['evidenceViewIds']))throw new \InvalidArgumentException('Cut gate must inspect native-parent context.');}
        $base=$context;unset($base['lifestyleFraming']);$plain=$response;
        $plain['output']=[['type'=>'message','role'=>'assistant','status'=>'completed','content'=>[['type'=>'output_text','text'=>json_encode($structured,JSON_THROW_ON_ERROR)]]]];
        $parsed=LabSectionAnalysisProtocol::parse($plain,$base);
        $gate+=['allRequiredChecksPass'=>$ready,'sourceFramingId'=>$binding['id'],'generationAuthority'=>'actual_cut_checks_only','manualAcceptance'=>false];
        return $parsed+['lifestyleReadiness'=>$gate];
    }
    private static function binding(array $context): void
    {
        $b=$context['lifestyleFraming']??null;LabLifestyleFramingProtocol::keys($b,['version','id','resultSha256','nativeSourceSha256','view','constraints']);
        if($b['version']!==LabLifestyleFramingProtocol::VERSION||!\Illuminate\Support\Str::isUuid($b['id'])
            ||!preg_match('/\A[a-f0-9]{64}\z/D',$b['resultSha256'])||$b['nativeSourceSha256']!==($context['sourceContext']['binding']['nativeSourceSha256']??null)
            ||$context['preset']!=='lifestyle')throw new \InvalidArgumentException('Invalid cut framing authority.');
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
            LabLifestyleFramingProtocol::evidence($check,LabLifestyleFramingProtocol::IDS);LabLifestyleFramingProtocol::text($check['reason'],550);
        }
    }
}
