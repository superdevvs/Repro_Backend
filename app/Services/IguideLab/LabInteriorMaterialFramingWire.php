<?php
namespace App\Services\IguideLab;

/** New wire binding only. The original framing result grammar/parser remain unchanged. */
final class LabInteriorMaterialFramingWire
{
    public const VERSION=LabInteriorDesignFramingProtocol::VERSION;
    public static function snapshot(): array
    {return ['version'=>self::VERSION,'requestVersion'=>'interior-material-framing-bound-request/2','compilerSha256'=>hash_file('sha256',__FILE__),'original'=>LabInteriorDesignFramingProtocol::snapshot()];}
    public static function request(array $context,array $images): array
    {
        $wire=LabInteriorDesignFramingProtocol::request($context,$images);
        foreach(['version','nativeSourceSha256','preparedSourceSha256','sourceAuthoritySha256','intentSha256','style'] as $key)
            $wire['text']['format']['schema']['properties'][$key]=['type'=>'string','enum'=>[$context[$key]]];
        $schema=&$wire['text']['format']['schema'];
        foreach(['sourceDescription'=>200,'plannedChange'=>500] as $field=>$max)$schema['properties']['targets']['items']['properties'][$field]=['type'=>'string','minLength'=>1,'maxLength'=>$max];
        $schema['properties']['styleFeatures']['items']['properties']['description']=['type'=>'string','minLength'=>1,'maxLength'=>500];
        foreach($schema['properties']['checks']['properties'] as &$check)$check['properties']['reason']=['type'=>'string','minLength'=>1,'maxLength'=>550];unset($check);
        $schema['properties']['summary']=['type'=>'string','minLength'=>1,'maxLength'=>1200];unset($schema);
        $wire['text']['format']['name']='iguide_interior_material_framing_bound_v2';return $wire;
    }
    public static function parse(array $response,array $context): array {return LabInteriorDesignFramingProtocol::parse($response,$context);}
}
