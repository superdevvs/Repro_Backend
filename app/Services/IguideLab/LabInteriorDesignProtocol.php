<?php
namespace App\Services\IguideLab;

/** Closed scalar/list validation shared only by the experimental Interior protocols. */
final class LabInteriorDesignProtocol
{
    public static function keys(mixed $value,array $keys): void
    {
        if(!is_array($value)||array_diff(array_keys($value),$keys)||array_diff($keys,array_keys($value)))self::invalid('Interior protocol keys differ.');
    }
    public static function text(mixed $value,int $max): void
    {
        if(!is_string($value)||trim($value)===''||mb_strlen($value)>$max)self::invalid('Invalid bounded Interior text.');
    }
    public static function hash(mixed $value): void
    {
        if(!is_string($value)||!preg_match('/\A[a-f0-9]{64}\z/D',$value))self::invalid('Invalid Interior fingerprint.');
    }
    public static function strings(mixed $values,array $allowed,int $max,bool $empty=false): void
    {
        if(!is_array($values)||!array_is_list($values)||(!$values&&!$empty)||count($values)>$max)self::invalid('Invalid bounded Interior list.');
        foreach($values as $value)if(!is_string($value)||!in_array($value,$allowed,true))self::invalid('Unknown Interior identity.');
        if(count(array_unique($values))!==count($values))self::invalid('Duplicate Interior identity.');
    }
    public static function box(mixed $box,bool $guard=true): void
    {
        if(!is_array($box)||!array_is_list($box)||count($box)!==4)self::invalid('Invalid Interior source box.');
        foreach($box as $n)if(!is_int($n))self::invalid('Interior boxes require actual integer pixels.');
        $low=$guard?205:0;$high=$guard?1842:2047;
        if($box[0]<$low||$box[1]<$low||$box[2]>$high||$box[3]>$high||$box[0]>=$box[2]||$box[1]>=$box[3])self::invalid('Interior target lacks the required frame margin.');
    }
    public static function checks(mixed $checks,array $names,array $views,bool $parentRequired=true): bool
    {
        self::keys($checks,$names);$ready=true;
        foreach($checks as $name=>$check){self::keys($check,['status','evidenceViewIds','reason']);
            if(!in_array($check['status'],['pass','fail','uncertain'],true))self::invalid('Invalid Interior check status.');
            self::text($check['reason'],550);self::strings($check['evidenceViewIds'],$views,count($views));
            if(!in_array('source',$check['evidenceViewIds'],true))self::invalid('Every Interior check must inspect the authoritative cut.');
            if($parentRequired&&$name==='optical_dependencies'&&array_diff(array_filter($views,fn($v)=>str_starts_with($v,'parent_')),$check['evidenceViewIds']))self::invalid('Interior optical checks must inspect every supplied parent view.');
            $ready=$ready&&$check['status']==='pass';
        }
        return $ready;
    }
    public static function object(array $properties): array
    {return ['type'=>'object','properties'=>$properties,'required'=>array_keys($properties),'additionalProperties'=>false];}
    public static function checkSchema(array $views): array
    {return self::object(['status'=>['type'=>'string','enum'=>['pass','fail','uncertain']],'evidenceViewIds'=>['type'=>'array','items'=>['type'=>'string','enum'=>$views],'minItems'=>1,'maxItems'=>count($views)],'reason'=>['type'=>'string']]);}
    public static function maskSchema(): array
    {return self::object(['prompt'=>['type'=>'string'],'box'=>['type'=>'array','items'=>['type'=>'integer','minimum'=>0,'maximum'=>2047],'minItems'=>4,'maxItems'=>4]]);}
    public static function mask(mixed $plan,bool $guard=true): void
    {self::keys($plan,['prompt','box']);self::text($plan['prompt'],160);self::box($plan['box'],$guard);}
    public static function response(array $value): array
    {return ['status'=>'completed','model'=>'gpt-6-astra','output'=>[['type'=>'message','role'=>'assistant','status'=>'completed','content'=>[['type'=>'output_text','text'=>json_encode($value,JSON_THROW_ON_ERROR)]]]]];}
    public static function parsed(array $response): array
    {if(($response['model']??null)!=='gpt-6-astra')self::invalid('Interior provider model changed.');return LabStagingFramingProtocol::structured($response);}
    public static function invalid(string $message): never {throw new \InvalidArgumentException($message);}
}
