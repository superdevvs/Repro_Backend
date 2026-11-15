<?php
namespace App\Services\IguideLab;

/** Pure decisions for new typed records only. No record loading, spending or acceptance authority. */
final class LabRoomEvidenceDraftPolicy
{
    public const VERSION='interior-room-evidence-drafts/1';
    public const ROUTE_VERSION='interior-room-routing/2';
    public const READ_VERSION='interior-room-assessment-read/2';
    public const BUDGET=['analysis'=>5,'image'=>2,'segmentation'=>2,'section'=>2,'composition'=>1,'evaluation'=>1];
    public const SOURCE_CHECKS=['room_scope','source_floor','receiver_observability','known_obstruction','foreground_support','shadow_scope'];
    public const QUESTIONS=['complete_group','frame_margin','circulation','protected_features','optical_dependencies','style_faithfulness','visible_contacts'];
    public const CANDIDATE_CHECKS=['exact_object_count','geometry','visible_contacts','source_floor','circulation','protected_features','optical_dependencies','style_faithfulness'];
    public const DEFECTS=[
        'exact_object_count'=>['object_count'],
        'geometry'=>['object_extent','frame_margin','placement'],
        'visible_contacts'=>['visible_contact'],
        'circulation'=>['placement'],
        'protected_features'=>['placement'],
        'style_faithfulness'=>['style'],
    ];
    public static function snapshot(): array
    {return ['version'=>self::VERSION,'implementationSha256'=>hash_file('sha256',__FILE__),'budget'=>self::BUDGET,
        'sourceCriterion'=>'visible_plausibility_with_known_constraints','maximumCompletedCandidateCorrections'=>1,
        'sourcePoseFixed'=>true,'materialBranchUnchanged'=>true,'finalReadyPredicateUnchanged'=>true];}
    public static function selected(array $recipe): ?array
    {
        $contract=$recipe['implementation']['workflow']['evidenceDrafts']??null;if($contract===null)return null;
        abort_unless(config('iguide-lab.interior_room_evidence_drafts_enabled',false)===true
            &&LabMachineSegmentationPlan::canonicalHash($contract)===LabMachineSegmentationPlan::canonicalHash(self::snapshot())
            &&LabRoomFloorWitness::selected($recipe)!==null,422,'Reserved evidence draft contract changed or disabled.');return $contract;
    }

    public static function schema(string $phase): array
    {
        $p=LabInteriorDesignProtocol::class;$text=fn($n)=>['type'=>'string','minLength'=>1,'maxLength'=>$n];$enum=fn($a)=>['type'=>'string','enum'=>$a];
        $roles=$enum(['sofa','coffee_table','chair','bed','nightstand_left','nightstand_right']);
        $finding=$p::object(['status'=>$enum(['pass','fail','uncertain']),'evidenceViewIds'=>['type'=>'array','items'=>$enum(['source','candidate','parent_source','parent_front','parent_right','parent_back','parent_left']),'minItems'=>1,'maxItems'=>7],'reason'=>$text(550)]);
        $source=$p::object(['version'=>$enum([self::VERSION]),'sourceBindingSha256'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$'],
            'sourceChecks'=>$p::object(array_fill_keys(self::SOURCE_CHECKS,$finding)),
            'anchors'=>['type'=>'array','maxItems'=>3,'items'=>$p::object(['objectId'=>$roles,'sourceAnchor'=>$text(300),'orientation'=>$text(160),'knownExclusions'=>$text(300)])],
            'candidateQuestions'=>['type'=>'array','maxItems'=>count(self::QUESTIONS),'items'=>$p::object(['id'=>$enum(self::QUESTIONS),'reason'=>$text(550)])]]);
        $closure=$p::object(['id'=>$enum(array_map(fn($q)=>'verify_'.$q,self::QUESTIONS))]+$finding['properties']);
        $repair=$p::object(['decision'=>$enum(['none','one_correction']),'defects'=>['type'=>'array','maxItems'=>count(self::DEFECTS),'items'=>$p::object([
            'checkId'=>$enum(array_keys(self::DEFECTS)),'kind'=>$enum(['object_count','object_extent','frame_margin','placement','visible_contact','style']),
            'objectIds'=>['type'=>'array','items'=>$roles,'minItems'=>1,'maxItems'=>3],'sourceAlternative'=>$text(300)])]]);
        return ['draftEvidence'=>$phase==='layout'?$source:['type'=>'null'],
            'obligationClosures'=>['type'=>'array','items'=>$closure,'maxItems'=>$phase==='candidate'?count(self::QUESTIONS):0],'correction'=>$repair];
    }

    /** Model assertions are parsed evidence, not proof. The owning service must verify receipts and source files. */
    public static function source(array $v,string $bindingSha256,string $profileId): array
    {
        self::keys($v,['version','sourceBindingSha256','sourceChecks','anchors','candidateQuestions']);
        self::hash($bindingSha256);
        if($v['version']!==self::VERSION||$v['sourceBindingSha256']!==$bindingSha256)self::invalid('New source evidence binding required.');
        $roles=self::roles($profileId);self::keys($v['sourceChecks'],self::SOURCE_CHECKS);$blocked=[];
        foreach(self::SOURCE_CHECKS as $id){self::finding($v['sourceChecks'][$id],false);
            if($id==='receiver_observability')self::allViews($v['sourceChecks'][$id]['evidenceViewIds'],false);
            if($v['sourceChecks'][$id]['status']!=='pass')$blocked[]=$id;}
        self::list($v['candidateQuestions']);$ids=[];
        foreach($v['candidateQuestions'] as $q){self::keys($q,['id','reason']);
            if(!in_array($q['id'],self::QUESTIONS,true)||in_array($q['id'],$ids,true))self::invalid('Unknown or duplicate candidate question.');
            self::text($q['reason'],550);$ids[]=$q['id'];}
        self::list($v['anchors']);
        if($blocked){if($v['anchors']!==[]||$v['candidateQuestions']!==[])self::invalid('Blocked source cannot provide executable anchors or questions.');}
        else {
            if(array_column($v['anchors'],'objectId')!==$roles)self::invalid('Exactly the bound profile anchors are required.');
            foreach($v['anchors'] as $a){self::keys($a,['objectId','sourceAnchor','orientation','knownExclusions']);
                self::text($a['sourceAnchor'],300);self::text($a['orientation'],160);self::text($a['knownExclusions'],300);}
        }
        // Canonical obligation ordering depends on the declared enum, never provider row order.
        $ordered=array_values(array_intersect(self::QUESTIONS,$ids));
        return ['version'=>self::VERSION,'disposition'=>$blocked?'blocked':($ordered?'evidence_draft':'standard_draft'),
            'sourceBindingSha256'=>$bindingSha256,'profileId'=>$profileId,'blockedChecks'=>$blocked,
            'obligationIds'=>array_map(fn($id)=>'verify_'.$id,$ordered),
            'sourceGeometryFitProven'=>false,'floorReceiptStillRequired'=>true,'nativeCompositionAuthorized'=>false,'automaticAcceptance'=>false];
    }

    /** Every inherited question must be answered by this candidate; prior candidates cannot contribute passes. */
    public static function closures(array $rows,array $obligationIds): bool
    {
        self::list($rows);self::list($obligationIds);
        $allowed=array_map(fn($id)=>'verify_'.$id,self::QUESTIONS);
        if(count(array_unique($obligationIds))!==count($obligationIds)||array_diff($obligationIds,$allowed))self::invalid('Invalid inherited obligations.');
        if(count($rows)!==count($obligationIds))self::invalid('Missing candidate closure.');
        $seen=[];$all=true;
        foreach($rows as $row){self::keys($row,['id','status','evidenceViewIds','reason']);
            if(!in_array($row['id'],$obligationIds,true)||in_array($row['id'],$seen,true))self::invalid('Duplicate or substituted candidate closure.');
            self::finding(array_diff_key($row,['id'=>true]),true);
            if($row['id']==='verify_optical_dependencies')self::allViews($row['evidenceViewIds'],true);
            $seen[]=$row['id'];$all=$all&&$row['status']==='pass';}
        return $all;
    }

    /** Only a complete first candidate with observable repairable failures can propose one new image. */
    public static function correction(array $candidate,int $ordinal,string $profileId,array $closures,array $obligationIds): array
    {
        $closed=self::closures($closures,$obligationIds);
        self::keys($candidate,['version','status','checks','foregroundStatus','opticalStatus','contactShadowStatus','correction']);
        if($candidate['version']!==self::VERSION||!in_array($candidate['status'],['ready_for_masks','needs_review'],true)||!in_array($ordinal,[1,2],true))self::invalid('Invalid candidate authority or ordinal.');
        $roles=self::roles($profileId);self::keys($candidate['checks'],self::CANDIDATE_CHECKS);$failed=[];$uncertain=[];
        foreach(self::CANDIDATE_CHECKS as $id){self::finding($candidate['checks'][$id],true);
            if($id==='optical_dependencies')self::allViews($candidate['checks'][$id]['evidenceViewIds'],true);
            $s=$candidate['checks'][$id]['status'];if($s==='fail')$failed[]=$id;if($s==='uncertain')$uncertain[]=$id;}
        foreach(['foregroundStatus'=>['none_observed','unsupported','uncertain'],'opticalStatus'=>['no_required_changes','unsupported','uncertain'],
            'contactShadowStatus'=>['tiny_local_only','nonlocal_required','uncertain']] as $id=>$values)if(!in_array($candidate[$id],$values,true))self::invalid('Invalid candidate scope.');
        $proposal=$candidate['correction'];self::keys($proposal,['decision','defects']);self::list($proposal['defects']);
        if(!in_array($proposal['decision'],['none','one_correction'],true))self::invalid('Unknown correction decision.');
        if($proposal['decision']==='none'&&$proposal['defects']!==[])self::invalid('Unrequested correction defects.');
        $seen=[];
        foreach($proposal['defects'] as $d){self::keys($d,['checkId','kind','objectIds','sourceAlternative']);
            if(!isset(self::DEFECTS[$d['checkId']])||!in_array($d['kind'],self::DEFECTS[$d['checkId']],true)||in_array($d['checkId'],$seen,true))self::invalid('Unknown or repeated repair class.');
            self::list($d['objectIds']);if(!$d['objectIds']||count($d['objectIds'])!==count(array_unique($d['objectIds']))||array_diff($d['objectIds'],$roles))self::invalid('Repair targets changed.');
            self::text($d['sourceAlternative'],300);$seen[]=$d['checkId'];}
        $reasons=[];
        $questionChecks=['complete_group'=>'exact_object_count','frame_margin'=>'geometry','circulation'=>'circulation','protected_features'=>'protected_features',
            'optical_dependencies'=>'optical_dependencies','style_faithfulness'=>'style_faithfulness','visible_contacts'=>'visible_contacts'];
        foreach($closures as $closure){$id=substr($closure['id'],7);$check=$questionChecks[$id];
            if($closure['status']==='uncertain')$reasons[]='OBLIGATION_UNCERTAINTY_REMAINS';
            if($closure['status']!=='pass'&&$candidate['checks'][$check]['status']==='pass')$reasons[]='OBLIGATION_CHECK_CONTRADICTION';
            if($closure['status']==='fail'&&!isset(self::DEFECTS[$check]))$reasons[]='UNSUPPORTED_INHERITED_DEPENDENCY';}
        if($ordinal!==1)$reasons[]='IMAGE_LIMIT_REACHED';
        if($candidate['status']!=='needs_review'||!$failed)$reasons[]='NO_COMPLETED_FAILURE';
        if($uncertain)$reasons[]='CANDIDATE_UNCERTAINTY_REMAINS';
        if(array_diff($failed,array_keys(self::DEFECTS)))$reasons[]='UNSUPPORTED_REPAIR_CLASS';
        if($candidate['foregroundStatus']!=='none_observed'||$candidate['opticalStatus']!=='no_required_changes'||$candidate['contactShadowStatus']!=='tiny_local_only')$reasons[]='UNSUPPORTED_ACTUAL_DEPENDENCY';
        if($proposal['decision']!=='one_correction'||array_diff($failed,$seen)||array_diff($seen,$failed))$reasons[]='REPAIR_MUST_ADDRESS_EXACT_FAILURE_SET';
        if($candidate['status']==='ready_for_masks'&&(!$closed||$failed||$uncertain||$proposal['decision']!=='none'
            ||$candidate['foregroundStatus']!=='none_observed'||$candidate['opticalStatus']!=='no_required_changes'||$candidate['contactShadowStatus']!=='tiny_local_only'))self::invalid('A held candidate cannot authorize masks.');
        return ['version'=>self::VERSION,'eligible'=>$reasons===[],'nextImageOrdinal'=>$reasons===[]?2:null,'holdReasons'=>array_values(array_unique($reasons)),
            'sourcePoseMustRemainUnchanged'=>true,'priorCandidatePassesReusable'=>false,'nativeCompositionAuthorized'=>false,'automaticAcceptance'=>false];
    }
    private static function finding(mixed $v,bool $candidate): void
    {
        self::keys($v,['status','evidenceViewIds','reason']);if(!in_array($v['status'],['pass','fail','uncertain'],true))self::invalid('Invalid finding status.');
        self::text($v['reason'],550);self::list($v['evidenceViewIds']);$ids=$v['evidenceViewIds'];
        $allowed=['source','parent_source','parent_front','parent_right','parent_back','parent_left'];if($candidate)$allowed[]='candidate';
        if(count($ids)!==count(array_unique($ids))||array_diff($ids,$allowed)||!in_array('source',$ids,true)||($candidate&&!in_array('candidate',$ids,true)))self::invalid('Actual evidence membership changed.');
    }
    private static function roles(string $id): array
    {return match($id){'empty-living-three-piece/1'=>['sofa','coffee_table','chair'],'empty-bedroom-platform-three-piece/1'=>['bed','nightstand_left','nightstand_right'],default=>throw new \InvalidArgumentException('Only new furnishing profiles are eligible.')};}
    private static function allViews(array $ids,bool $candidate): void
    {$all=['source','parent_source','parent_front','parent_right','parent_back','parent_left'];if($candidate)$all[]='candidate';if(array_diff($all,$ids))self::invalid('All optical context must be cited.');}
    private static function keys(mixed $v,array $keys): void
    {if(!is_array($v)||array_diff(array_keys($v),$keys)||array_diff($keys,array_keys($v)))self::invalid('Unexpected or missing evidence fields.');}
    private static function list(mixed $v): void {if(!is_array($v)||!array_is_list($v))self::invalid('Expected an ordered evidence list.');}
    private static function text(mixed $v,int $max): void {if(!is_string($v)||trim($v)===''||mb_strlen($v)>$max)self::invalid('Unbounded or empty text.');}
    private static function hash(mixed $v): void {if(!is_string($v)||!preg_match('/^[a-f0-9]{64}$/D',$v))self::invalid('Invalid evidence hash.');}
    private static function invalid(string $message): never {throw new \InvalidArgumentException($message);}
}
