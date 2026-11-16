<?php
namespace App\Services\IguideLab;

use App\Models\{IguideLabComposite,IguideLabProject,IguideLabRoomAssessment,IguideLabRun,IguideLabWorkflow,IguideLabWorkflowEvaluation,IguideLabWorkflowItem};

/**
 * Virtual staging on the interior room engine (the only route that produced real furniture drafts).
 *
 * A staging request is stored as an interior-design item that remembers its public name, so every
 * room-engine authority check keeps working unchanged, and the editor still sees preset "staging" with
 * the old staging item shape. The alias is captured in the workflow policy at start; switching the
 * setting later never changes a running workflow.
 */
class LabStagingRoomAlias
{
    public const VERSION='staging-room-alias/1';
    public const PUBLIC_PRESET='staging';
    public const ENGINE_PRESET='interior-design';

    public static function enabled(): bool {return config('iguide-lab-staging-room.staging_room_alias_enabled',false)===true;}
    public static function snapshot(): array
    {return ['version'=>self::VERSION,'publicPreset'=>self::PUBLIC_PRESET,'enginePreset'=>self::ENGINE_PRESET,'imagesPerItem'=>1,'emptyRoomOnly'=>true];}
    /** Only when the room engine itself was ready and captured for this workflow. */
    public static function capture(array $policy): ?array
    {
        return self::enabled()&&LabInteriorDesignPolicy::enabled($policy,self::ENGINE_PRESET)&&isset($policy['interiorRoomRouting'])
            &&LabInteriorRoomPolicy::enabled($policy,self::ENGINE_PRESET)?self::snapshot():null;
    }
    public static function selected(array $policy): bool {return ($policy['stagingRoomAlias']??null)===self::snapshot();}
    public static function isAlias(array $policy,IguideLabWorkflowItem $item): bool
    {return self::selected($policy)&&$item->preset===self::ENGINE_PRESET&&(($item->data['publicPreset']??null)===self::PUBLIC_PRESET);}
    public static function publicPreset(IguideLabWorkflowItem $item): string
    {return $item->preset===self::ENGINE_PRESET&&(($item->data['publicPreset']??null)===self::PUBLIC_PRESET)?self::PUBLIC_PRESET:$item->preset;}

    /** Staging readiness mirrors the room engine while the alias is on. */
    public static function capabilities(array $capabilities): array
    {
        if(!self::enabled())return $capabilities;
        $engine=null;foreach($capabilities['recipes']??[] as $recipe)if(($recipe['preset']??null)===self::ENGINE_PRESET)$engine=$recipe;
        if($engine===null)return $capabilities;
        foreach($capabilities['recipes'] as $i=>$recipe)if(($recipe['preset']??null)===self::PUBLIC_PRESET)
            $capabilities['recipes'][$i]=['preset'=>self::PUBLIC_PRESET,'ready'=>$engine['ready'],'reason'=>$engine['reason']];
        return $capabilities;
    }

    /** Staging adds furniture to an empty room only; a furnished room stops before any image. */
    public static function routeStop(IguideLabWorkflow $workflow,IguideLabWorkflowItem $item,string $profileId): ?array
    {
        if(!self::isAlias($workflow->policy,$item)||LabInteriorRoomProfiles::get($profileId)['kind']==='new_furnishings')return null;
        return ['staging_room_not_empty','Virtual staging needs an empty room. This photo already has furniture, so no image was made.'];
    }
    /** One image per staging item: no hidden second correction image. */
    public static function imageLimit(IguideLabWorkflow $workflow,IguideLabWorkflowItem $item,int $default): int
    {return self::isAlias($workflow->policy,$item)?1:$default;}

    /** Checked before buying the image: the paid image must be able to reach a viewable result. */
    public static function capacityStop(IguideLabWorkflow $workflow,IguideLabWorkflowItem $item): ?array
    {
        if(!self::isAlias($workflow->policy,$item))return null;
        $project=IguideLabProject::findOrFail($item->project_id);$self=app(self::class);
        $full=$project->used_runs>=$project->max_runs||$self->recordCount(IguideLabRun::class,null)>=120
            ||$self->recordCount(IguideLabRoomAssessment::class,$project->id)>=240||$self->recordCount(IguideLabRoomAssessment::class,null)>=240
            ||$self->recordCount(IguideLabComposite::class,$project->id)>=120
            ||$self->recordCount(IguideLabWorkflowEvaluation::class,$project->id)>=120||$self->recordCount(IguideLabWorkflowEvaluation::class,null)>=120;
        return $full?['generation_capacity_unavailable','No image was made: this lab has no generation or result capacity left for a staging image.']:null;
    }
    /** Lifetime count for one project, or the rolling daily count across the lab when $projectId is null. */
    public function recordCount(string $model,?string $projectId): int
    {return $projectId===null?$model::where('created_at','>=',now()->subDay())->count():$model::where('project_id',$projectId)->count();}
}
