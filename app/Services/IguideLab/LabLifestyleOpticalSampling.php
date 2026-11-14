<?php
namespace App\Services\IguideLab;

/** Projection-grid diagnostics only; never optical resolution or visibility authority. */
final class LabLifestyleOpticalSampling
{
    public static function validate(mixed $v,int $width,int $height): void
    {
        LabLifestyleFramingProtocol::keys($v,['version','sourceWidth','sourceHeight','outputWidth','outputHeight','sampleGridSize','minAxisFootprint','maxAxisFootprint','upsampledGridFraction','samplingMethod','independentOpticalResolutionKnown']);
        if($v['version']!=='native-grid-sampling/1'||[$v['sourceWidth'],$v['sourceHeight'],$v['outputWidth'],$v['outputHeight'],$v['sampleGridSize']]!==[$width,$height,2048,2048,33]
            ||$v['samplingMethod']!=='lanczos-mipmap-trilinear-bilinear'||$v['independentOpticalResolutionKnown']!==false)throw new \InvalidArgumentException('Native sampling identity changed.');
        foreach(['minAxisFootprint','maxAxisFootprint'] as $key){LabLifestyleFramingProtocol::keys($v[$key],['min','p50','max']);$last=0;
            foreach(['min','p50','max'] as $stat){$n=$v[$key][$stat];if((!is_int($n)&&!is_float($n))||!is_finite((float)$n)||$n<$last||$n>2*$width)throw new \InvalidArgumentException('Invalid source-grid footprint.');$last=$n;}}
        foreach(['min','p50','max'] as $key)if($v['minAxisFootprint'][$key]>$v['maxAxisFootprint'][$key])throw new \InvalidArgumentException('Source-grid axes changed.');
        $n=$v['upsampledGridFraction'];if((!is_int($n)&&!is_float($n))||!is_finite((float)$n)||$n<0||$n>1)throw new \InvalidArgumentException('Invalid interpolation fraction.');
    }
}
