<?php
namespace App\Services\IguideLab;

/** Versioned wire-only policy. Original pixels do not establish optical clearance. */
final class LabLifestyleOriginalDetailTransport
{
    public const VERSION = 'lifestyle-native-original-details/1';

    public static function snapshot(): array
    {
        return ['version'=>self::VERSION,'implementationSha256'=>hash_file('sha256',__FILE__),
            'model'=>'gpt-6-astra','detailIds'=>LabLifestyleOpticalProtocol::DETAILS,
            'nativeDetail'=>'original','otherDetail'=>'high','patchSize'=>32,'maxPatchesPerDetail'=>30000,
            'maxDimension'=>65535,'maxRawImageBytes'=>24*1024*1024];
    }

    public static function validate(array $snapshot): void
    {
        if (LabAnalysisRecipes::hash($snapshot)!==LabAnalysisRecipes::hash(self::snapshot())) {
            throw new \InvalidArgumentException('Reserved native detail transport changed.');
        }
    }

    public static function patches(mixed $width,mixed $height): int
    {
        if (!is_int($width)||!is_int($height)||$width<1||$height<1||$width>65535||$height>65535) {
            throw new \InvalidArgumentException('Native original detail dimension exceeds its wire limit.');
        }
        $patches=intdiv($width+31,32)*intdiv($height+31,32);
        if ($patches>30000) throw new \InvalidArgumentException('Native original detail exceeds its patch limit.');
        return $patches;
    }

    public static function framing(array $context,array $images,?array $policy): array
    {
        $payload=LabLifestyleOpticalProtocol::request($context,$images);
        if ($policy===null) return $payload; // Exact retained v2 builder, without silent conversion.
        self::validate($policy);
        return self::apply($payload,array_column($context['views'],'id'),$context['views']);
    }

    public static function apply(array $payload,array $ids,array $descriptors): array
    {
        if (($payload['model']??null)!=='gpt-6-astra') throw new \InvalidArgumentException('Original detail model changed.');
        $byId=[];
        foreach($descriptors as $v){if(isset($byId[$v['id']]))throw new \InvalidArgumentException('Duplicate detail descriptor.');$byId[$v['id']]=$v;}
        $index=0;$bytesTotal=0;
        foreach($payload['input'][0]['content'] as &$part){
            if(($part['type']??null)!=='input_image')continue;
            $id=$ids[$index++]??null;
            if(!is_string($id)||($part['detail']??null)!=='high'
                ||!preg_match('/\Adata:image\/png;base64,([A-Za-z0-9+\/=]+)\z/D',$part['image_url']??'',$m)){
                throw new \InvalidArgumentException('Native detail wire order or encoding changed.');
            }
            $bytes=base64_decode($m[1],true);
            if($bytes===false||($bytesTotal+=strlen($bytes))>24*1024*1024)throw new \InvalidArgumentException('Native detail wire byte limit exceeded.');
            if(in_array($id,LabLifestyleOpticalProtocol::DETAILS,true)){
                $v=$byId[$id]??null;$size=@getimagesizefromstring($bytes);
                if(!$v||!$size||$size['mime']!=='image/png'||[$size[0],$size[1]]!==[$v['width'],$v['height']]
                    ||hash('sha256',$bytes)!==$v['sha256'])throw new \InvalidArgumentException('Native detail wire source binding changed.');
                self::patches($size[0],$size[1]);$part['detail']='original';
            }elseif(str_starts_with($id,'source_detail_'))throw new \InvalidArgumentException('Unknown native detail ID.');
        }
        unset($part);
        if($index!==count($ids))throw new \InvalidArgumentException('Native detail wire image count changed.');
        return $payload;
    }
}
