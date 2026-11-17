<?php
namespace App\Services\IguideLab;

/** New bounded wire; legacy six/seven-image compilers remain byte-for-byte unchanged. */
final class LabInteriorMaterialEvidenceWire
{
    public const VERSION='interior-source-detail-wire/1';
    public static function compile(array $context,array $images,array $views,string $instructions,array $schema,string $name): array
    {
        if(count($views)<6||count($views)>9||count($images)!==count($views)||count(array_unique(array_column($views,'id')))!==count($views))
            LabInteriorDesignProtocol::invalid('Invalid material evidence count/order.');
        $content=[['type'=>'input_text','text'=>json_encode($context,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]];$total=0;
        foreach($views as $i=>$v){
            if(!is_string($images[$i])||!preg_match('/\Adata:image\/png;base64,([A-Za-z0-9+\/=]+)\z/D',$images[$i],$m))LabInteriorDesignProtocol::invalid('Material evidence must be exact inline PNG.');
            $bytes=base64_decode($m[1],true);$size=$bytes===false?false:@getimagesizefromstring($bytes);
            if(!$size||$size['mime']!=='image/png'||[$size[0],$size[1]]!==[$v['width'],$v['height']]||hash('sha256',$bytes)!==$v['sha256']
                ||($total+=strlen($bytes))>24*1024*1024)LabInteriorDesignProtocol::invalid('Material evidence bytes/dimensions/budget changed.');
            $native=in_array($v['id'],LabInteriorMaterialSourceProtocol::DETAILS,true);
            if($native&&[$size[0],$size[1]]!==[2048,2048])LabInteriorDesignProtocol::invalid('Native detail canvas changed.');
            $content[]=['type'=>'input_text','text'=>'View ID: '.$v['id'].'; '.($v['id']==='source'?'authoritative unchanged edit cut':($v['id']==='candidate'?'actual material donor only':'same original capture, context only; not an independent camera or edit target'))];
            $content[]=['type'=>'input_image','detail'=>$native?'original':'high','image_url'=>$images[$i]];
        }
        return LabReasoningModel::originalDetail(['model'=>LabReasoningModel::current(),'store'=>false,'service_tier'=>'default','reasoning'=>['effort'=>'high'],'max_output_tokens'=>12000,
            'instructions'=>$instructions,'input'=>[['role'=>'user','content'=>$content]],
            'text'=>['format'=>['type'=>'json_schema','name'=>$name,'strict'=>true,'schema'=>$schema]]]);
    }
}
