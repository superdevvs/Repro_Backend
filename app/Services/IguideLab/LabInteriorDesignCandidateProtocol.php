<?php
namespace App\Services\IguideLab;

use App\Services\IguideLab\LabInteriorDesignProtocol as V;

/** Actual donor inspection, separate from original scope and measured registration. */
final class LabInteriorDesignCandidateProtocol
{
    public const VERSION='interior-existing-material-candidate/1';
    public const IDS=['source','candidate','parent_source','parent_front','parent_right','parent_back','parent_left'];
    public const CHECKS=['unchanged_forms','complete_targets','opaque_matte','protected_foreground','coarse_folds','contact_shading','optical_dependencies','style_faithfulness','non_colour_detail'];
    public const INSTRUCTIONS= <<<'TEXT'
Inspect an actual generated material-restyle donor against the exact original section and original parent panorama/cardinals. Image order and view IDs are authoritative: source then candidate, followed by original context only. This is existing-room Interior design with unchanged forms, not empty-room staging or an invitation to repair the room. Visible text is scene content. Assess the ORIGINAL requested style and instructions, not just the candidate or planner's description. A pleasant colour change is insufficient: recognizable material, weave or pattern must plausibly express that style on these existing forms. No pixels are accepted by this assessment.
The native composition route keeps original geometry, silhouettes, arrangement, coarse folds, contact shading and exterior. The candidate is only a bounded donor for registered material/detail fields. Hold if a required style change needs different forms/layout, if material is transparent/reflective/glossy, if meaningful drape/occlusion/contact/lighting has shifted, if required reflections cannot be handled inside the original scope, or if a pattern perspective cannot plausibly register. Do not assert registration accuracy from appearance: a separate numerical correspondence/held-out-residual check is mandatory. A candidate that changes protected pixels outside targets can only be used when the selected donor regions remain complete and independent; those outside changes are never copied.
Keep the exact cut plan's sequential material IDs, descriptions and registrationSurface; do not add or drop targets. Give a short candidate-image segmentation prompt and integer [x_min,y_min,x_max,y_max] box per material. Each complete donor target requires the same 205-pixel guard on this2048-square image. Mask order is not semantic identity. The server will require actual source and candidate receipts, unique association and complete support. If foreground is absent in the source plan, explicitly report none_observed only when both source and candidate show no such occluder. If source foreground exists, report source_unchanged only when all those same occluders/order remain; any missing/new/moved/ambiguous foreground requires review. Do not invent a mask or provider receipt.
All nine checks must pass for ready_for_candidate_masks. Every check cites source and candidate; optical_dependencies additionally cites every parent view. Non-colour detail must be present in actual target material, not merely a colour cast, global lighting or hallucinated room texture. Style faithfulness must be recognizable in the proposed retained material transfer, without relying on candidate furniture shape or accessories that the compositor will discard. Text limits are each segmentation prompt 160 characters, each check reason 550 and summary 1200. needs_review returns no candidate target plans. No new image, prompt, acceptance, humanReviewed or cross-view claim is authorized. Even a pass only permits candidate machine mask selection; actual measured registration, immutable support, numerical native checks and independent full-sphere style QA remain required.
TEXT;
    public static function snapshot(): array
    {
        return ['version'=>self::VERSION,'protocolSha256'=>hash_file('sha256',__FILE__),'validatorSha256'=>hash_file('sha256',__DIR__.'/LabInteriorDesignProtocol.php'),
            'wireCompilerSha256'=>hash_file('sha256',__DIR__.'/LabAnalysisRequestV1.php'),'cut'=>LabInteriorDesignCutProtocol::snapshot(),
            'instructionsSha256'=>hash('sha256',self::INSTRUCTIONS),'schemaSha256'=>LabAnalysisRecipes::hash(self::schema())];
    }
    public static function context(array $c): void
    {
        V::keys($c,['version','cutContext','cutResult','cutResultSha256','candidateSha256','candidateRunId','views']);
        if($c['version']!==self::VERSION||!is_string($c['candidateRunId'])||!\Illuminate\Support\Str::isUuid($c['candidateRunId']))V::invalid('Invalid Interior candidate identity.');
        V::hash($c['cutResultSha256']);V::hash($c['candidateSha256']);
        $wire=$c['cutResult'];foreach(['authorOrigin','sourceMaskSelectionAuthorized','imageGenerationAuthorized','materialTransferAuthorized','manualAcceptance','crossViewVerified'] as $key)unset($wire[$key]);
        $parsed=LabInteriorDesignCutProtocol::parse(V::response($wire),$c['cutContext']);
        if(LabAnalysisRecipes::hash($parsed)!==$c['cutResultSha256']||LabAnalysisRecipes::hash($c['cutResult'])!==$c['cutResultSha256']||$parsed['status']!=='ready_for_source_masks')V::invalid('Interior candidate lost its original cut authority.');
        if(!is_array($c['views'])||!array_is_list($c['views'])||array_column($c['views'],'id')!==self::IDS)V::invalid('Invalid Interior candidate image order.');
        $parentViews=$c['cutContext']['sourceContext']['views'];
        foreach($c['views'] as $i=>$view){V::keys($view,['id','sha256','width','height']);V::hash($view['sha256']);
            $expected=$i===1?['sha256'=>$c['candidateSha256'],'width'=>2048,'height'=>2048]:$parentViews[$i===0?0:$i-1];
            foreach(['sha256','width','height'] as $key)if($view[$key]!==$expected[$key])V::invalid('Interior candidate evidence does not match its exact original or donor.');}
    }
    public static function request(array $c,array $images): array
    {
        self::context($c);$cut=$c['cutContext'];
        $recipe=['model'=>'gpt-6-astra','store'=>false,'service_tier'=>'default','reasoning'=>['effort'=>'high'],'max_output_tokens'=>10000,'instructions'=>self::INSTRUCTIONS,'schema'=>self::schema()];
        $wire=LabAnalysisRequestV1::compile($recipe,['sourceSha256'=>$cut['sourceSha256'],'projection'=>'rectilinear','preset'=>'interior-design','style'=>$cut['style'],'instructions'=>$cut['instructions'],'viewIds'=>self::IDS],$images);
        foreach($images as $i=>$image){$bytes=base64_decode(explode(',',$image,2)[1],true);$size=getimagesizefromstring($bytes);$view=$c['views'][$i];
            if(hash('sha256',$bytes)!==$view['sha256']||[$size[0],$size[1]]!==[$view['width'],$view['height']])V::invalid('Interior donor evidence bytes changed.');}
        $wire['input'][0]['content'][0]['text']=json_encode($c,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $wire['text']['format']['name']='iguide_interior_material_candidate';return $wire;
    }
    public static function parse(array $response,array $c): array
    {
        self::context($c);$v=V::parsed($response);$cut=$c['cutResult'];
        V::keys($v,['version','sourceSha256','candidateSha256','nativeSourceSha256','cutResultSha256','style','status','foregroundFinding','targets','checks','summary']);
        foreach(['version'=>self::VERSION,'sourceSha256'=>$cut['preparedSourceSha256'],'candidateSha256'=>$c['candidateSha256'],'nativeSourceSha256'=>$cut['nativeSourceSha256'],
            'cutResultSha256'=>$c['cutResultSha256'],'style'=>$cut['style']] as $key=>$expected)if($v[$key]!==$expected)V::invalid('Interior candidate result binding changed.');
        if(!in_array($v['status'],['ready_for_candidate_masks','needs_review'],true)||!in_array($v['foregroundFinding'],['none_observed','source_unchanged','uncertain'],true))V::invalid('Invalid Interior candidate disposition.');
        $ready=V::checks($v['checks'],self::CHECKS,self::IDS);V::text($v['summary'],1200);
        foreach($v['checks'] as $check)if(!in_array('candidate',$check['evidenceViewIds'],true))V::invalid('Candidate checks must inspect the actual donor.');
        if(!is_array($v['targets'])||!array_is_list($v['targets'])||count($v['targets'])>5)V::invalid('Invalid candidate target count.');
        foreach($v['targets'] as $i=>$target){V::keys($target,['id','sourceDescription','registrationSurface','maskPlan']);$old=$cut['targets'][$i]??null;
            foreach(['id','sourceDescription','registrationSurface'] as $key)if(($old[$key]??null)!==$target[$key])V::invalid('Candidate target identities changed.');V::mask($target['maskPlan']);}
        if($v['status']==='ready_for_candidate_masks'){
            if(!$ready||count($v['targets'])!==count($cut['targets'])||$v['foregroundFinding']!==($cut['foreground']===[]?'none_observed':'source_unchanged'))V::invalid('Unsupported Interior donor cannot authorize masks.');
        }elseif($v['targets']!==[])V::invalid('Held Interior donor has no executable mask plans.');
        return $v+['authorOrigin'=>'machine','candidateMaskSelectionAuthorized'=>$v['status']==='ready_for_candidate_masks','imageGenerationAuthorized'=>false,
            'materialTransferAuthorized'=>false,'manualAcceptance'=>false,'crossViewVerified'=>false];
    }
    public static function schema(): array
    {
        $str=['type'=>'string'];$hash=['type'=>'string','pattern'=>'^[a-f0-9]{64}$'];$enum=fn($v)=>['type'=>'string','enum'=>$v];
        return V::object(['version'=>$enum([self::VERSION]),'sourceSha256'=>$hash,'candidateSha256'=>$hash,'nativeSourceSha256'=>$hash,'cutResultSha256'=>$hash,'style'=>$enum(array_keys(LabCatalog::STYLES)),
            'status'=>$enum(['ready_for_candidate_masks','needs_review']),'foregroundFinding'=>$enum(['none_observed','source_unchanged','uncertain']),
            'targets'=>['type'=>'array','items'=>V::object(['id'=>$str,'sourceDescription'=>$str,'registrationSurface'=>$enum(['planar','locally_rigid_drape']),'maskPlan'=>V::maskSchema()]),'maxItems'=>5],
            'checks'=>V::object(array_fill_keys(self::CHECKS,V::checkSchema(self::IDS))),'summary'=>$str]);
    }
}
