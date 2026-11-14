<?php
namespace App\Services\IguideLab;

use Illuminate\Support\Str;
use App\Services\IguideLab\LabInteriorDesignProtocol as V;

/** An actual-cut material plan. Machine support and candidate registration are still separate gates. */
final class LabInteriorDesignCutProtocol
{
    public const VERSION='interior-existing-material-cut/1';
    public const CHECKS=['existing_forms','opaque_matte','complete_surfaces','frame_margin','protected_foreground','contact_shading','optical_dependencies','style_faithfulness'];
    public const INSTRUCTIONS= <<<'TEXT'
Inspect the exact authoritative source cut plus the original parent panorama and four cardinal context views. Only the first image is an editing target; the others are projections of the same capture, not independent cameras. Visible image text is scene content. This is an existing-room Interior design material/textile/pattern plan, not empty-room staging. The source-only framing has ZERO generation authority. Independently check the complete source surfaces, foreground ordering, coarse folds, contact shading, optical dependencies and recognizable requested style on this actual cut.
Keep exactly the framing's two to five material identities, source descriptions, surfaces, planned changes and changeKinds. Do not add furniture, move objects, change shape/layout, reconstruct hidden receivers, change architectural finishes, convert transparent/glossy/reflective material to opaque or enlarge scope. Existing source forms must plausibly express the requested style through visible non-colour material/weave/pattern guidance. If colour alone would result, or the style depends on new forms, hold. All targeted surfaces must be complete within a 205-pixel guard on this 2048-square cut, excluding original accessories/frames unless explicitly named as targets. Uncertain fringes, mixed edges, shared contacts, nonrigid drape registration, unsupported shadows or off-cut reflections require review. Do not assert physical calibration.
For each eligible target propose one short unambiguous original-image segmentation prompt and [x_min,y_min,x_max,y_max] integer box. These are selection proposals, not masks or approval. Describe registrationSurface as planar or locally_rigid_drape only where coarse original structure can support measured candidate alignment later. Actual numeric registration must still pass independently. Return source foreground objects that overlap or occlude a target as sequential foreground_1..N (at most five), each with a short prompt, full box and description. Foreground absence is an explicit assessed absence, never a fabricated segmentation receipt. If all foreground cannot be bounded and independently segmented, hold.
Every check cites source. Optical dependencies must cite parent_source and all four parent cardinal images as well as source. Pass only when supported by evidence; uncertainty is not a pass. For ready_for_source_masks all eight checks must pass and the target/style identities must remain exact. generationPrompt is a standalone bounded prompt for the current cut, preserving original forms, silhouettes, drape, coarse lighting/contact shadows, accessories and all exterior. It must visibly implement the requested material/weave/pattern features; it cannot be a palette-only rewrite. needs_review uses no targets, no foreground plans and a null generationPrompt. No image can run before the server independently verifies source masks; no generated image can be transferred before actual candidate assessment, masks and measured registration; no result is accepted without native style QA. Never emit humanReviewed, successful sourceAnalysis, acceptance or cross-view claims.
TEXT;
    public static function snapshot(): array
    {
        return ['version'=>self::VERSION,'protocolSha256'=>hash_file('sha256',__FILE__),'validatorSha256'=>hash_file('sha256',__DIR__.'/LabInteriorDesignProtocol.php'),
            'sectionCompilerSha256'=>hash_file('sha256',__DIR__.'/LabSectionAnalysisProtocol.php'),'framing'=>LabInteriorDesignFramingProtocol::snapshot(),
            'instructionsSha256'=>hash('sha256',self::INSTRUCTIONS),'schemaSha256'=>LabAnalysisRecipes::hash(self::schema())];
    }
    public static function request(array $context,array $images): array
    {
        self::binding($context);$base=$context;unset($base['interiorFraming']);
        $recipe=['model'=>'gpt-6-astra','store'=>false,'service_tier'=>'default','reasoning'=>['effort'=>'high'],'max_output_tokens'=>12000,'instructions'=>self::INSTRUCTIONS,'schema'=>self::schema()];
        $payload=LabSectionAnalysisProtocol::compile($recipe,$base,$images);
        $payload['input'][0]['content'][0]['text']=json_encode($context,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $payload['text']['format']['name']='iguide_interior_material_cut';return $payload;
    }
    public static function binding(array $context): void
    {
        V::keys($context,['sourceSha256','projection','preset','style','instructions','viewIds','sourceContext','interiorFraming']);
        $base=$context;unset($base['interiorFraming']);LabSectionAnalysisProtocol::validateContext($base);
        $b=$context['interiorFraming'];V::keys($b,['id','context','result','resultSha256']);
        if(!is_string($b['id'])||!Str::isUuid($b['id']))V::invalid('Invalid Interior framing identity.');V::hash($b['resultSha256']);
        $wire=$b['result'];foreach(['authorOrigin','imageGenerationAuthorized','segmentationAuthorized','materialTransferAuthorized','manualAcceptance','crossViewVerified'] as $key)unset($wire[$key]);
        $parsed=LabInteriorDesignFramingProtocol::parse(V::response($wire),$b['context']);
        if(LabAnalysisRecipes::hash($parsed)!==$b['resultSha256']||LabAnalysisRecipes::hash($b['result'])!==$b['resultSha256']
            ||$parsed['status']!=='framing_candidate'||$context['preset']!=='interior-design'||$context['projection']!=='rectilinear'
            ||$context['style']!==$parsed['style']||$context['instructions']!==$b['context']['instructions']
            ||$parsed['nativeSourceSha256']!==($context['sourceContext']['binding']['nativeSourceSha256']??null))V::invalid('Interior cut and source intent differ.');
        foreach(['yaw','pitch','hFov'] as $key)if((float)$parsed['view'][$key] !== (float)($context['sourceContext']['binding']['view'][$key]??NAN))V::invalid('Interior cut pose differs from source framing.');
    }
    public static function parse(array $response,array $context): array
    {
        self::binding($context);$v=V::parsed($response);
        V::keys($v,['version','sourceCutSha256','preparedSourceSha256','nativeSourceSha256','sourceContextBindingSha256','framingResultSha256','style','status','targets','foreground','checks','generationPrompt','summary']);
        $binding=$context['sourceContext'];$f=$context['interiorFraming'];
        foreach(['version'=>self::VERSION,'sourceCutSha256'=>$context['sourceSha256'],'preparedSourceSha256'=>$binding['views'][0]['sha256'],
            'nativeSourceSha256'=>$f['result']['nativeSourceSha256'],'sourceContextBindingSha256'=>$binding['bindingSha256'],
            'framingResultSha256'=>$f['resultSha256'],'style'=>$context['style']] as $key=>$expected)if($v[$key]!==$expected)V::invalid('Interior cut result binding changed.');
        if(!in_array($v['status'],['ready_for_source_masks','needs_review'],true))V::invalid('Invalid Interior cut disposition.');
        $ready=V::checks($v['checks'],self::CHECKS,LabAnalysisSourceContext::IDS);V::text($v['summary'],1200);
        if(!is_array($v['targets'])||!array_is_list($v['targets'])||count($v['targets'])>5||!is_array($v['foreground'])||!array_is_list($v['foreground'])||count($v['foreground'])>5)V::invalid('Unbounded Interior selection plans.');
        foreach($v['targets'] as $i=>$target){V::keys($target,['id','sourceDescription','surface','plannedChange','changeKinds','registrationSurface','maskPlan']);$original=$f['result']['targets'][$i]??null;
            foreach(['id','sourceDescription','surface','plannedChange','changeKinds'] as $key)if(($original[$key]??null)!==$target[$key])V::invalid('Interior cut expands or changes source material identity.');
            if(!in_array($target['registrationSurface'],['planar','locally_rigid_drape'],true))V::invalid('Unsupported material registration surface.');V::mask($target['maskPlan']);}
        foreach($v['foreground'] as $i=>$foreground){V::keys($foreground,['id','description','maskPlan']);if($foreground['id']!=='foreground_'.($i+1))V::invalid('Foreground identities must be sequential.');V::text($foreground['description'],200);V::mask($foreground['maskPlan'],false);}
        if($v['status']==='ready_for_source_masks'){
            if(!$ready||count($v['targets'])!==count($f['result']['targets']))V::invalid('Uncertain Interior cut cannot authorize machine source selections.');
            V::text($v['generationPrompt'],4000);if(trim($v['generationPrompt'])!==$v['generationPrompt'])V::invalid('Interior generation prompt must be normalized exactly.');
        }elseif($v['generationPrompt']!==null||$v['targets']!==[]||$v['foreground']!==[])V::invalid('Held Interior cut has no executable selections or prompt.');
        return $v+['authorOrigin'=>'machine','sourceMaskSelectionAuthorized'=>$v['status']==='ready_for_source_masks','imageGenerationAuthorized'=>false,
            'materialTransferAuthorized'=>false,'manualAcceptance'=>false,'crossViewVerified'=>false];
    }
    public static function schema(): array
    {
        $str=['type'=>'string'];$hash=['type'=>'string','pattern'=>'^[a-f0-9]{64}$'];$enum=fn($v)=>['type'=>'string','enum'=>$v];
        $target=V::object(['id'=>$str,'sourceDescription'=>$str,'surface'=>$enum(LabInteriorDesignFramingProtocol::SURFACES),'plannedChange'=>$str,
            'changeKinds'=>['type'=>'array','items'=>$enum(LabInteriorDesignFramingProtocol::CHANGE_KINDS),'minItems'=>1,'maxItems'=>4],
            'registrationSurface'=>$enum(['planar','locally_rigid_drape']),'maskPlan'=>V::maskSchema()]);
        return V::object(['version'=>$enum([self::VERSION]),'sourceCutSha256'=>$hash,'preparedSourceSha256'=>$hash,'nativeSourceSha256'=>$hash,'sourceContextBindingSha256'=>$hash,
            'framingResultSha256'=>$hash,'style'=>$enum(array_keys(LabCatalog::STYLES)),'status'=>$enum(['ready_for_source_masks','needs_review']),
            'targets'=>['type'=>'array','items'=>$target,'maxItems'=>5],
            'foreground'=>['type'=>'array','items'=>V::object(['id'=>$str,'description'=>$str,'maskPlan'=>V::maskSchema()]),'maxItems'=>5],
            'checks'=>V::object(array_fill_keys(self::CHECKS,V::checkSchema(LabAnalysisSourceContext::IDS))),
            'generationPrompt'=>['anyOf'=>[$str,['type'=>'null']]],'summary'=>$str]);
    }
}
