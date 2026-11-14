<?php
namespace App\Services\IguideLab;

use App\Models\IguideLabWorkflowItem;

/** Synchronous read-tree deduplication only; never a job, request or paid-attempt authority cache. */
final class LabInteriorRoomReadScope
{
    private static array $frames=[];
    private static int $freshDepth=0;

    public static function completed(IguideLabWorkflowItem $item,callable $read): mixed
    {
        // The legacy route remains unchanged. This marker permits reuse, never establishes authority:
        // the first read still revalidates the complete persisted source/route/receipt chain.
        if(self::$freshDepth>0||($item->data['interiorRoomRoute']['profileId']??null)!==LabInteriorRoomProfiles::MATERIALS)return $read();
        $owner=self::owner($item);$last=array_key_last(self::$frames);
        if($last!==null&&self::$frames[$last]['owner']===$owner)return $read();
        self::$frames[]=['owner'=>$owner,'verified'=>[]];
        try{return $read();}finally{array_pop(self::$frames);}
    }

    public static function verified(IguideLabWorkflowItem $item,string $key,callable $read): mixed
    {
        $last=array_key_last(self::$frames);
        if(self::$freshDepth>0||$last===null||self::$frames[$last]['owner']!==self::owner($item))return $read();
        if(!array_key_exists($key,self::$frames[$last]['verified']))self::$frames[$last]['verified'][$key]=$read();
        $value=self::$frames[$last]['verified'][$key];
        return is_object($value)?clone $value:$value;
    }

    /** A primary/fallback verifier must always see current files even if called from a read scope. */
    public static function fresh(callable $verify): mixed
    {
        foreach(self::$frames as &$frame)$frame['verified']=[];unset($frame);
        self::$freshDepth++;
        try{return $verify();}finally{
            self::$freshDepth--;
            foreach(self::$frames as &$frame)$frame['verified']=[];unset($frame);
        }
    }

    private static function owner(IguideLabWorkflowItem $item): string
    {return $item->project_id.':'.$item->id.':'.LabMachineSegmentationPlan::canonicalHash([
        'source'=>$item->source,'preset'=>$item->preset,'style'=>$item->style,'instructions'=>$item->instructions,
        'route'=>$item->data['interiorRoomRoute']??null]);}
}
