<?php
namespace App\Services\IguideLab;

/** Exact cut scene plan plus an explicit pre-image gate; a source framing candidate is never a pass. */
final class LabStagingCutProtocol
{
    public const VERSION='responses-staging-cut-gate-v1';
    public const GATE_VERSION='staging-cut-readiness/1';
    public const CHECKS=['group_coverage','frame_margin','circulation','fixed_features','optical_dependencies'];
    public static function compile(array $recipe,array $context,array $images): array
    {
        self::binding($context);$base=$context;unset($base['stagingFraming']);
        $payload=LabSectionAnalysisProtocol::compile($recipe,$base,$images);
        $payload['input'][0]['content'][0]['text']=json_encode($context,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        return $payload;
    }
    public static function parse(array $response,array $context): array
    {
        self::binding($context);$structured=LabStagingFramingProtocol::structured($response);$gate=$structured['stagingReadiness']??null;unset($structured['stagingReadiness']);
        LabStagingFramingProtocol::keys($gate,['version','sourceSha256','nativeSourceSha256','framingResultSha256','checks']);
        $binding=$context['stagingFraming'];
        if($gate['version']!==self::GATE_VERSION||$gate['sourceSha256']!==$context['sourceSha256']||$gate['nativeSourceSha256']!==$binding['nativeSourceSha256']
            ||$gate['framingResultSha256']!==$binding['resultSha256'])throw new \InvalidArgumentException('Cut gate belongs to another source or framing.');
        LabStagingFramingProtocol::keys($gate['checks'],self::CHECKS);$ready=true;
        foreach($gate['checks'] as $name=>$check){LabStagingFramingProtocol::keys($check,['status','evidenceViewIds','reason']);
            if(!in_array($check['status'],['pass','fail','uncertain'],true))throw new \InvalidArgumentException('Invalid cut readiness status.');
            LabStagingFramingProtocol::evidence($check,LabAnalysisSourceContext::IDS);$ready=$ready&&$check['status']==='pass';
            if(!in_array('source',$check['evidenceViewIds'],true))throw new \InvalidArgumentException('Cut gate must inspect the actual cut.');
            if(in_array($name,['circulation','optical_dependencies'],true)&&!array_intersect(array_slice(LabAnalysisSourceContext::IDS,1),$check['evidenceViewIds']))throw new \InvalidArgumentException('Cut gate must inspect native-parent context.');}
        $base=$context;unset($base['stagingFraming']);$plain=$response;
        $plain['output']=[['type'=>'message','role'=>'assistant','status'=>'completed','content'=>[['type'=>'output_text','text'=>json_encode($structured,JSON_THROW_ON_ERROR)]]]];
        $parsed=LabSectionAnalysisProtocol::parse($plain,$base);
        $gate+=['allRequiredChecksPass'=>$ready,'sourceFramingId'=>$binding['id'],'generationAuthority'=>'actual_cut_checks_only','manualAcceptance'=>false];
        return $parsed+['stagingReadiness'=>$gate];
    }
    private static function binding(array $context): void
    {
        $b=$context['stagingFraming']??null;LabStagingFramingProtocol::keys($b,['version','id','resultSha256','nativeSourceSha256','view','constraints']);
        if($b['version']!==LabStagingFramingProtocol::VERSION||!\Illuminate\Support\Str::isUuid($b['id'])
            ||!preg_match('/\A[a-f0-9]{64}\z/D',$b['resultSha256'])||$b['nativeSourceSha256']!==($context['sourceContext']['binding']['nativeSourceSha256']??null)
            ||$context['preset']!=='staging')throw new \InvalidArgumentException('Invalid cut framing authority.');
        LabStagingFramingProtocol::keys($b['view'],['yaw','pitch','hFov']);
        foreach(['yaw'=>[-180,180],'pitch'=>[-60,15],'hFov'=>[30,120]] as $key=>[$low,$high]) {
            $n=$b['view'][$key];
            if((!is_int($n)&&!is_float($n))||!is_finite((float)$n)||$n<$low||$n>$high
                ||(float)$n!==(float)($context['sourceContext']['binding']['view'][$key]??NAN))throw new \InvalidArgumentException('Cut is not the proposed free framing.');
        }
        LabStagingFramingProtocol::keys($b['constraints'],LabStagingFramingProtocol::CONSTRAINTS);
        foreach($b['constraints'] as $check) {
            LabStagingFramingProtocol::keys($check,['status','evidenceViewIds','reason']);
            if(!in_array($check['status'],['supported','verify_on_cut'],true))throw new \InvalidArgumentException('Blocked source framing cannot authorize a cut gate.');
            LabStagingFramingProtocol::evidence($check,LabStagingFramingProtocol::IDS);LabStagingFramingProtocol::text($check['reason'],550);
        }
    }
}
