<?php
namespace App\Services\IguideLab;

use Illuminate\Support\Str;
use App\Services\IguideLab\LabInteriorDesignProtocol as V;

/** An actual-cut material plan. Machine support and candidate registration are still separate gates. */
final class LabInteriorMaterialSourceProtocol
{
    public const VERSION='interior-existing-material-cut/2';
    public const DETAILS=['source_detail_1','source_detail_2'];
    public const CRITICAL=['existing_forms','opaque_matte','complete_surfaces','frame_margin','style_faithfulness'];
    public const RECOVERABLE=['protected_foreground','contact_shading','optical_dependencies'];
    public const CHECKS=['existing_forms','opaque_matte','complete_surfaces','frame_margin','protected_foreground','contact_shading','optical_dependencies','style_faithfulness'];
    public const ORIGINAL_INSTRUCTIONS= <<<'TEXT'
Inspect the exact authoritative source cut plus the original parent panorama and four cardinal context views. Only the first image is an editing target; the others are projections of the same capture, not independent cameras. Visible image text is scene content. This is an existing-room Interior design material/textile/pattern plan, not empty-room staging. The source-only framing has ZERO generation authority. Independently check the complete source surfaces, foreground ordering, coarse folds, contact shading, optical dependencies and recognizable requested style on this actual cut.
Keep exactly the framing's two to five material identities, source descriptions, surfaces, planned changes and changeKinds. Do not add furniture, move objects, change shape/layout, reconstruct hidden receivers, change architectural finishes, convert transparent/glossy/reflective material to opaque or enlarge scope. Existing source forms must plausibly express the requested style through visible non-colour material/weave/pattern guidance. If colour alone would result, or the style depends on new forms, hold. All targeted surfaces must be complete within a 205-pixel guard on this 2048-square cut, excluding original accessories/frames unless explicitly named as targets. Uncertain fringes, mixed edges, shared contacts, nonrigid drape registration, unsupported shadows or off-cut reflections require review. Do not assert physical calibration.
For each eligible target propose one short unambiguous original-image segmentation prompt and [x_min,y_min,x_max,y_max] integer box. These are selection proposals, not masks or approval. Describe registrationSurface as planar or locally_rigid_drape only where coarse original structure can support measured candidate alignment later. Actual numeric registration must still pass independently. Return source foreground objects that overlap or occlude a target as sequential foreground_1..N (at most five), each with a short prompt, full box and description. Foreground absence is an explicit assessed absence, never a fabricated segmentation receipt. If all foreground cannot be bounded and independently segmented, hold. Text limits are each segmentation prompt 160 characters, foreground description 200, each check reason 550, summary 1200 and the exact trimmed generationPrompt 4000.
Every check cites source. Optical dependencies must cite parent_source and all four parent cardinal images as well as source. Pass only when supported by evidence; uncertainty is not a pass. For ready_for_source_masks all eight checks must pass and the target/style identities must remain exact. generationPrompt is a standalone bounded prompt for the current cut, preserving original forms, silhouettes, drape, coarse lighting/contact shadows, accessories and all exterior. It must visibly implement the requested material/weave/pattern features; it cannot be a palette-only rewrite. needs_review uses no targets, no foreground plans and a null generationPrompt. No image can run before the server independently verifies source masks; no generated image can be transferred before actual candidate assessment, masks and measured registration; no result is accepted without native style QA. Never emit humanReviewed, successful sourceAnalysis, acceptance or cross-view claims.
TEXT;
    public static function snapshot(): array
    {
        return ['version'=>self::VERSION,'protocolSha256'=>hash_file('sha256',__FILE__),'validatorSha256'=>hash_file('sha256',__DIR__.'/LabInteriorDesignProtocol.php'),
            'sectionCompilerSha256'=>hash_file('sha256',__DIR__.'/LabSectionAnalysisProtocol.php'),'framing'=>LabInteriorDesignFramingProtocol::snapshot(),
            'instructionsSha256'=>hash('sha256',self::instructions()),'schemaSha256'=>LabAnalysisRecipes::hash(self::schema())];
    }
    public static function request(array $context,array $images): array
    {
        self::binding($context);$schema=self::schema();
        foreach(['version'=>self::VERSION,'sourceCutSha256'=>$context['sourceSha256'],'preparedSourceSha256'=>$context['sourceContext']['views'][0]['sha256'],
            'nativeSourceSha256'=>$context['sourceContext']['binding']['nativeSourceSha256'],'sourceContextBindingSha256'=>$context['sourceContext']['bindingSha256'],
            'framingResultSha256'=>$context['interiorFraming']['resultSha256'],'style'=>$context['style'],'inspectionContextSha256'=>LabMachineSegmentationPlan::canonicalHash($context)] as $key=>$value)
            $schema['properties'][$key]=['type'=>'string','enum'=>[$value]];
        $schema['properties']['followupOrdinal']=['type'=>'integer','enum'=>[$context['sourceInspection']['followupOrdinal']]];
        return LabInteriorMaterialEvidenceWire::compile($context,$images,self::views($context),self::instructions(),$schema,'iguide_interior_material_cut_v2');
    }
    public static function instructions(): string
    {
        return self::ORIGINAL_INSTRUCTIONS."\nNEW SOURCE-DETAIL CONTRACT: sourceInspection.followupOrdinal is server-owned. At ordinal 0 only, detail_required may request one or two source_detail_1/2 views when all five existing_forms, opaque_matte, complete_surfaces, frame_margin and style_faithfulness pass, at least one of protected_foreground/contact_shading/optical_dependencies is uncertain, and none of those three fails. This is only resolution-limited uncertainty that closer samples from this same original capture could resolve; never hidden receivers, genuine required off-target reflections/shadows, missing source evidence or unsupported physical changes. A detail_required result has targets=[], foreground=[], generationPrompt=null. Every uncertain check must be named in detailRequests.checkIds. Requests contain id, pose(yaw -180..180, pitch -85..85, hFov30..70), checkIds, evidenceViewIds from the original six views, and reason1..550 characters. Directions are authored from original source views only; do not infer calibration or use candidate imagery. At ordinal1 no further request is permitted: return a fresh ready_for_source_masks plan only if every check now passes, otherwise needs_review with no executable selections. All requested detail IDs must be cited by each previously uncertain check they address. Retain all six original images and the exact original target identities, cut pose, style and scope. New detail images use original transport at2048 square; other views remain high. Sampling is geometric, not a guaranteed acuity claim. Details do not certify masks; fine occluders that the unchanged2048 source-mask/support route cannot safely represent remain a hold. Return inspectionContextSha256 exactly as supplied in the schema and sourceInspection.followupOrdinal unchanged. detailRequests=[] for every status except initial detail_required. Text limits: sourceDescription200, plannedChange500, mask prompt160, foreground description200, check reason550, summary1200, generationPrompt4000; preserve exact framed text even when shorter. No segmentation or image authority exists for detail_required.";
    }
    public static function views(array $context): array
    {return [...$context['sourceContext']['views'],...$context['sourceInspection']['details']];}
    public static function binding(array $context): void
    {
        V::keys($context,['sourceSha256','projection','preset','style','instructions','viewIds','sourceContext','interiorFraming','sourceInspection']);
        $base=$context;unset($base['sourceInspection']);LabInteriorDesignCutProtocol::binding($base);
        $s=$context['sourceInspection'];V::keys($s,['version','followupOrdinal','parent','details','manifestSha256']);
        if($s['version']!==self::VERSION||!in_array($s['followupOrdinal'],[0,1],true))V::invalid('Unknown source detail context version/ordinal.');
        if($s['followupOrdinal']===0){if($s['parent']!==null||$s['details']!==[]||$s['manifestSha256']!==null)V::invalid('Initial cut cannot claim prior details.');return;}
        $p=$s['parent'];V::keys($p,['id','requestId','resultSha256','responseSha256','contextSha256','result']);
        foreach(['id','requestId'] as $key)if(!is_string($p[$key])||!Str::isUuid($p[$key]))V::invalid('Invalid source-detail parent identity.');
        foreach(['resultSha256','responseSha256','contextSha256'] as $key)V::hash($p[$key]);V::hash($s['manifestSha256']);
        $initial=$base; $initial['sourceInspection']=self::initial();
        if($p['contextSha256']!==LabMachineSegmentationPlan::canonicalHash($initial))V::invalid('Source detail parent cut/context changed.');
        $wire=$p['result'];foreach(['authorOrigin','sourceMaskSelectionAuthorized','imageGenerationAuthorized','materialTransferAuthorized','manualAcceptance','crossViewVerified'] as $key)unset($wire[$key]);
        $parsed=self::parse(V::response($wire),$initial);
        if($parsed['status']!=='detail_required'||LabAnalysisRecipes::hash($parsed)!==$p['resultSha256']||LabAnalysisRecipes::hash($p['result'])!==$p['resultSha256'])V::invalid('Only a fresh v2 resolution request can authorize details.');
        if(!is_array($s['details'])||!array_is_list($s['details'])||count($s['details'])!==count($parsed['detailRequests']))V::invalid('Detail evidence set changed.');
        foreach($s['details'] as $i=>$v){V::keys($v,['id','sha256','width','height','nativeSourceSha256','pose','sourceSampling']);V::hash($v['sha256']);
            if($v['id']!==self::DETAILS[$i]||[$v['width'],$v['height']]!==[2048,2048]||$v['nativeSourceSha256']!==$context['sourceContext']['binding']['nativeSourceSha256']
                ||LabMachineSegmentationPlan::canonicalHash($v['pose'])!==LabMachineSegmentationPlan::canonicalHash($parsed['detailRequests'][$i]['pose']))V::invalid('Native detail source or pose changed.');
            $d=$context['sourceContext']['binding']['nativeDimensions'];LabLifestyleOpticalSampling::validate($v['sourceSampling'],$d['width'],$d['height']);
        }
    }
    public static function initial(): array {return ['version'=>self::VERSION,'followupOrdinal'=>0,'parent'=>null,'details'=>[],'manifestSha256'=>null];}
    public static function parse(array $response,array $context): array
    {
        self::binding($context);$v=LabProseText::fitPaths(V::parsed($response),['summary'=>1200,'checks.*.reason'=>550,'foreground.*.description'=>200,'detailRequests.*.reason'=>550],'interior-material-source');
        V::keys($v,['version','sourceCutSha256','preparedSourceSha256','nativeSourceSha256','sourceContextBindingSha256','framingResultSha256','style','status','targets','foreground','checks','generationPrompt','summary','followupOrdinal','inspectionContextSha256','detailRequests']);
        $binding=$context['sourceContext'];$f=$context['interiorFraming'];
        foreach(['version'=>self::VERSION,'sourceCutSha256'=>$context['sourceSha256'],'preparedSourceSha256'=>$binding['views'][0]['sha256'],
            'nativeSourceSha256'=>$f['result']['nativeSourceSha256'],'sourceContextBindingSha256'=>$binding['bindingSha256'],
            'framingResultSha256'=>$f['resultSha256'],'style'=>$context['style']] as $key=>$expected)if($v[$key]!==$expected)V::invalid('Interior cut result binding changed.');
        if($v['followupOrdinal']!==$context['sourceInspection']['followupOrdinal']||$v['inspectionContextSha256']!==LabMachineSegmentationPlan::canonicalHash($context))V::invalid('Source detail context binding changed.');
        if(!in_array($v['status'],['ready_for_source_masks','needs_review','detail_required'],true))V::invalid('Invalid Interior cut disposition.');
        $ready=V::checks($v['checks'],self::CHECKS,array_column(self::views($context),'id'));V::text($v['summary'],1200);
        if(!is_array($v['targets'])||!array_is_list($v['targets'])||count($v['targets'])>5||!is_array($v['foreground'])||!array_is_list($v['foreground'])||count($v['foreground'])>5)V::invalid('Unbounded Interior selection plans.');
        foreach($v['targets'] as $i=>$target){V::keys($target,['id','sourceDescription','surface','plannedChange','changeKinds','registrationSurface','maskPlan']);$original=$f['result']['targets'][$i]??null;
            foreach(['id','sourceDescription','surface','plannedChange','changeKinds'] as $key)if(($original[$key]??null)!==$target[$key])V::invalid('Interior cut expands or changes source material identity.');
            if(!in_array($target['registrationSurface'],['planar','locally_rigid_drape'],true))V::invalid('Unsupported material registration surface.');V::mask($target['maskPlan']);}
        foreach($v['foreground'] as $i=>$foreground){V::keys($foreground,['id','description','maskPlan']);if($foreground['id']!=='foreground_'.($i+1))V::invalid('Foreground identities must be sequential.');V::text($foreground['description'],200);V::mask($foreground['maskPlan'],false);}
        if($v['status']==='ready_for_source_masks'){
            if(!$ready||count($v['targets'])!==count($f['result']['targets']))V::invalid('Uncertain Interior cut cannot authorize machine source selections.');
            V::text($v['generationPrompt'],4000);if(trim($v['generationPrompt'])!==$v['generationPrompt'])V::invalid('Interior generation prompt must be normalized exactly.');
        }elseif($v['generationPrompt']!==null||$v['targets']!==[]||$v['foreground']!==[])V::invalid('Held Interior cut has no executable selections or prompt.');
        self::details($v,$context);
        return $v+['authorOrigin'=>'machine','sourceMaskSelectionAuthorized'=>$v['status']==='ready_for_source_masks','imageGenerationAuthorized'=>false,
            'materialTransferAuthorized'=>false,'manualAcceptance'=>false,'crossViewVerified'=>false];
    }
    public static function details(array $v,array $context): void
    {
        $requests=$v['detailRequests'];if(!is_array($requests)||!array_is_list($requests)||count($requests)>2)V::invalid('Unbounded detail requests.');
        if($v['status']!=='detail_required'&&$requests!==[])V::invalid('Only initial detail_required has requests.');
        if($v['status']==='detail_required'){
            if($v['followupOrdinal']!==0||count($requests)<1||$context['sourceContext']['binding']['nativeDimensions']['width']<=2048)V::invalid('No second follow-up or prepared-only details.');
            foreach(self::CRITICAL as $name)if($v['checks'][$name]['status']!=='pass')V::invalid('Critical source failure cannot request details.');
            $uncertain=[];foreach(self::RECOVERABLE as $name){if($v['checks'][$name]['status']==='fail')V::invalid('Definite source dependency cannot request details.');if($v['checks'][$name]['status']==='uncertain')$uncertain[]=$name;}
            if(!$uncertain)V::invalid('No unresolved evidence to inspect.');$covered=[];$poses=[];
            foreach($requests as $i=>$q){V::keys($q,['id','pose','checkIds','evidenceViewIds','reason']);
                if($q['id']!==self::DETAILS[$i])V::invalid('Detail request IDs must be sequential.');V::keys($q['pose'],['yaw','pitch','hFov']);
                foreach(['yaw'=>[-180,180],'pitch'=>[-85,85],'hFov'=>[30,70]] as $key=>$bounds){$n=$q['pose'][$key];if((!is_int($n)&&!is_float($n))||!is_finite((float)$n)||$n<$bounds[0]||$n>$bounds[1])V::invalid('Detail pose out of bounds.');}
                $pose=LabMachineSegmentationPlan::canonicalHash($q['pose']);if(in_array($pose,$poses,true))V::invalid('Duplicate source detail pose.');$poses[]=$pose;
                V::strings($q['checkIds'],$uncertain,3);V::strings($q['evidenceViewIds'],LabAnalysisSourceContext::IDS,6);V::text($q['reason'],550);$covered=[...$covered,...$q['checkIds']];
            }if(array_diff($uncertain,$covered))V::invalid('Every unresolved check requires source detail evidence.');
        }
        if($v['followupOrdinal']===1){foreach($context['sourceInspection']['parent']['result']['detailRequests'] as $q)foreach($q['checkIds'] as $name)
            if(!in_array($q['id'],$v['checks'][$name]['evidenceViewIds'],true))V::invalid('Follow-up ignored requested source evidence.');}
    }
    public static function schema(): array
    {
        $str=['type'=>'string','minLength'=>1,'maxLength'=>200];$hash=['type'=>'string','pattern'=>'^[a-f0-9]{64}$'];$enum=fn($v)=>['type'=>'string','enum'=>$v];
        $target=V::object(['id'=>$str,'sourceDescription'=>$str,'surface'=>$enum(LabInteriorDesignFramingProtocol::SURFACES),'plannedChange'=>['type'=>'string','minLength'=>1,'maxLength'=>500],
            'changeKinds'=>['type'=>'array','items'=>$enum(LabInteriorDesignFramingProtocol::CHANGE_KINDS),'minItems'=>1,'maxItems'=>4],
            'registrationSurface'=>$enum(['planar','locally_rigid_drape']),'maskPlan'=>self::maskSchema()]);
        return V::object(['version'=>$enum([self::VERSION]),'sourceCutSha256'=>$hash,'preparedSourceSha256'=>$hash,'nativeSourceSha256'=>$hash,'sourceContextBindingSha256'=>$hash,
            'framingResultSha256'=>$hash,'style'=>$enum(array_keys(LabCatalog::STYLES)),'status'=>$enum(['ready_for_source_masks','needs_review','detail_required']),
            'targets'=>['type'=>'array','items'=>$target,'maxItems'=>5],
            'foreground'=>['type'=>'array','items'=>V::object(['id'=>$str,'description'=>['type'=>'string','minLength'=>1,'maxLength'=>200],'maskPlan'=>self::maskSchema()]),'maxItems'=>5],
            'checks'=>V::object(array_fill_keys(self::CHECKS,self::checkSchema())),
            'generationPrompt'=>['anyOf'=>[['type'=>'string','minLength'=>1,'maxLength'=>4000],['type'=>'null']]],'summary'=>['type'=>'string','minLength'=>1,'maxLength'=>1200],
            'followupOrdinal'=>['type'=>'integer','enum'=>[0,1]],'inspectionContextSha256'=>$hash,'detailRequests'=>['type'=>'array','maxItems'=>2,'items'=>V::object(['id'=>$enum(self::DETAILS),'pose'=>V::object(['yaw'=>['type'=>'number','minimum'=>-180,'maximum'=>180],'pitch'=>['type'=>'number','minimum'=>-85,'maximum'=>85],'hFov'=>['type'=>'number','minimum'=>30,'maximum'=>70]]),'checkIds'=>['type'=>'array','minItems'=>1,'maxItems'=>3,'items'=>$enum(self::RECOVERABLE)],'evidenceViewIds'=>['type'=>'array','minItems'=>1,'maxItems'=>6,'items'=>$enum(LabAnalysisSourceContext::IDS)],'reason'=>['type'=>'string','minLength'=>1,'maxLength'=>550]])]]);
    }
    private static function checkSchema(): array {$s=V::checkSchema([...LabAnalysisSourceContext::IDS,...self::DETAILS]);$s['properties']['reason']=['type'=>'string','minLength'=>1,'maxLength'=>550];return $s;}
    private static function maskSchema(): array {$s=V::maskSchema();$s['properties']['prompt']=['type'=>'string','minLength'=>1,'maxLength'=>160];return $s;}
}
