<?php
namespace App\Services\IguideLab;

/** Closed server-owned scope. A route is never an image or composition authority. */
final class LabInteriorRoomProfiles
{
    public const VERSION = 'interior-room-routing/1';
    public const MATERIALS = 'existing-materials/1';
    public const LIVING = 'empty-living-three-piece/1';
    public const BEDROOM = 'empty-bedroom-platform-three-piece/1';
    public const BUDGET = ['analysis'=>5,'image'=>1,'segmentation'=>15,'section'=>2,'composition'=>1,'evaluation'=>1];

    public static function all(): array
    {
        return [
            self::MATERIALS => ['kind'=>'existing_materials','rooms'=>['living','bedroom','other'],
                'roles'=>[], 'semanticRecipe'=>LabInteriorDesignPolicy::VERSION,
                'scope'=>'Two to five existing opaque matte furnishing surfaces, with recognizable non-colour style changes. Preserve every original form, placement and contact.'],
            self::LIVING => ['kind'=>'new_furnishings','rooms'=>['living'],
                'roles'=>['sofa','coffee_table','chair'], 'semanticRecipe'=>'empty-living-three-piece-staging/1',
                'scope'=>'Exactly one sofa, one low coffee table and one accent chair on verified empty floor. No rug, lamps, art, plants or accessories.'],
            self::BEDROOM => ['kind'=>'new_furnishings','rooms'=>['bedroom'],
                'roles'=>['bed','nightstand_left','nightstand_right'], 'semanticRecipe'=>'empty-bedroom-platform-layout/1',
                'scope'=>'Exactly one low platform bed with integral headboard and simple bedding, plus two compact bedside tables, one at each side of the head. No lamps, rug, artwork, plants, loose accessories or additional furniture.'],
        ];
    }

    public static function get(string $id): array
    {
        if (!isset(self::all()[$id])) throw new \InvalidArgumentException('Unsupported Interior room profile.');
        return ['id'=>$id]+self::all()[$id];
    }

    public static function snapshot(): array
    {
        return ['version'=>self::VERSION,'profiles'=>self::all(),'budget'=>self::BUDGET,
            'classifier'=>['model'=>LabReasoningModel::SETTING,'reasoning'=>'medium','maxOutputTokens'=>4000],
            'sourceInspection'=>['mode'=>'initial_source_and_actual_cut_only','nativeDetailFollowup'=>false,'imageDetail'=>'high',
                'declaredSourceOverviewSize'=>[2048,1024],'declaredSourceDirectionalSize'=>[1024,1024],'declaredActualCutSize'=>[2048,2048],
                'implementedAnalysesPerBranch'=>4,'analysisCeilingIsNotFollowupAuthority'=>true,
                'limitation'=>'Provider high transport may downsample actual 2048-square cuts. No original-native detail or guaranteed acuity claim. Resolution-only or physical optical uncertainty remains held; the spare analysis ceiling is not an implemented recovery step.'],
            'routeSwitchAfterDispatch'=>false,'publicPreset'=>'interior-design',
            'manualAcceptance'=>false,'crossViewVerified'=>false];
    }

    /** Called only after the wire parser validates all source-bound findings. */
    public static function select(array $v): array
    {
        $reasons=[];
        if ($v['metadataAgreement']['status']==='conflict') $reasons[]='ROOM_METADATA_CONFLICT';
        if ($v['room']['status']!=='identified') $reasons[]='ROOM_FUNCTION_UNRESOLVED';
        if (!in_array($v['occupancy']['status'],['empty','furnished'],true)) $reasons[]='ROOM_OCCUPANCY_UNRESOLVED';
        if ($v['intentCompatibility']['status']!=='within_bounded_profile') $reasons[]='REQUEST_REQUIRES_UNSUPPORTED_SCOPE';
        if ($v['opticalDependencies']['status']==='unsupported') $reasons[]='SOURCE_OPTICAL_DEPENDENCY_UNSUPPORTED';
        $profile=null;
        if (!$reasons && $v['occupancy']['status']==='furnished') {
            if ($v['materialPotential']['status']==='supported') $profile=self::MATERIALS;
            else $reasons[]='EXISTING_MATERIAL_RESTYLE_UNSUPPORTED';
        } elseif (!$reasons) {
            $profile=match($v['room']['function']) {'living'=>self::LIVING,'bedroom'=>self::BEDROOM,default=>null};
            if ($profile===null) $reasons[]='EMPTY_ROOM_PROFILE_UNAVAILABLE';
        }
        return ['status'=>$profile===null?'needs_review':'route_candidate','profileId'=>$profile,
            'reviewReasons'=>$reasons,'imageGenerationAuthorized'=>false,'segmentationAuthorized'=>false,
            'nativeCompositionAuthorized'=>false,'manualAcceptance'=>false,'crossViewVerified'=>false];
    }
}
