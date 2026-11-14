"""Pure source-bound lawn/exclusion protocol. No network, masks or acceptance.

Caller supplies trusted persisted context and receipts, never client findings.
Every generated SAM plan requires an existing server reservation before dispatch.
"""
from __future__ import annotations
import base64
import hashlib
import io
import json
import math
from pathlib import Path
import re
import sys
from PIL import Image
try:import staging_assessment_protocol as shared
except ModuleNotFoundError:
    sys.path.insert(0,str(Path(__file__).resolve().parent.parent/'staging-support-v1'))
    import staging_assessment_protocol as shared

VERSION='source-native-grass-assessment/1'
MODEL='gpt-6-astra'
SAM_MODEL='fal-ai/sam-3-1/image'
CLASSES=['paving','plants','loose_debris','non_turf_ground','capture_artifact']
IDS=['source_panorama','candidate_panorama','source_front','source_right','source_back','source_left','source_zenith','source_nadir']
SOURCE_IDS=[x for x in IDS if x!='candidate_panorama']
BINDINGS=['workflowItemId','captureId','nativeSourceSha256','guidanceSourceSha256','candidateSha256',
    'sourcePreparationSha256','sourceAnalysisSha256','generationSettingsSha256']
PROMPTS={'lawn':'grass lawn','paving':'paving','plants':'plants','loose_debris':'fallen leaves and twigs',
    'non_turf_ground':'bare soil and mulch','capture_artifact':'camera and tripod'}
POLICY={'scope':'all_visible_established_turf','operation':'recolor_existing_turf','maximumSegmentationOperations':6,
    'maximumSelectionsPerRole':8,'width':2048,'height':1024}
INSTRUCTIONS='''Assess only the exact bound source and candidate evidence. This is a source-native turf recolor workflow: original pixels and native brightness stay authoritative; candidate supplies color guidance only. Do not propose texture creation, new blades, soil coverage, object removal, parcel-boundary inference or cross-camera consistency. Read source material, lawn extent, exclusions, optical dependencies and uncertainty from ORIGINAL source images only. Use candidate only to judge whether corresponding existing turf provides natural green color guidance. Full panorama and directional views are the SAME capture, never independent cameras.

Identify existing established turf and the source exclusions: paving, living plants, loose leaves/twigs, soil/mulch/non-turf ground and capture camera/tripod artifact. Only declare none_observed when the supplied source panorama and all six source directions support that finding; a missing segmentation mask is never absence evidence. Select complete visible objects/regions with approximate normalized boxes, not verified masks. Split longitude-boundary selections only when each visible segment can be independently selected; mark uncertain otherwise. Do not call thin/dense debris selectable if a 2048 x 1024 mask cannot defensibly retain it. Mark human/operator capture artifacts or a capture form not covered by camera/tripod as uncertain rather than silently protecting only a guessed cap. Preserve original uncertainty, mixed grass/soil, actual turf reflections in glazing, missing context, requested parcel subsets and unresolved material boundaries as review gates. No exact optical physics or unseen-object guarantee.

Use source_panorama plus relevant source views for source findings; none_observed exclusions, full coverage and reflections must cite all seven original source IDs. Candidate guidance must cite source_panorama and candidate_panorama. Return the strict structured schema only. No acceptance, invented segmentation result or calibrated camera claims.'''

digest=shared.digest
_keys=shared._keys
_hash=shared._hash
_uuid=shared._uuid
_obj=shared._obj
_enum=shared._enum
_string=shared._string
_list=shared._list


def response_schema(wire=False):
    evidence=_list(_string(40),9,1)
    box=_obj({k:{'type':'number','minimum':0,'maximum':1} for k in ['xMin','yMin','xMax','yMax']})
    selection=_obj({'id':_string(60),'box':box})
    finding=lambda status:_obj({'status':status,'evidenceIds':evidence,'notes':_string(700)})
    selection_finding=lambda statuses:_obj({'status':_enum(*statuses),'selections':_list(selection,8),
        'evidenceIds':evidence,'notes':_string(700)})
    result=_obj({'schemaVersion':_enum(VERSION),'contextSha256':_string(64),
        'binding':_obj({key:_string(64 if key not in BINDINGS[:2] else 36) for key in BINDINGS}),
        'summary':_string(700),'material':finding(_enum('established_turf','sparse_or_missing_turf','bare_soil','uncertain')),
        'scope':finding(_enum('all_visible_established_turf','parcel_subset_requested','uncertain')),
        'lawn':selection_finding(['visible_selectable','none_observed','too_fragmented','uncertain']),
        'exclusions':_obj({name:selection_finding(['observed_selectable','none_observed','too_fine_or_dense','uncertain']) for name in CLASSES}),
        'contextCoverage':_obj({'status':_enum('sufficient_same_capture_views','extra_context_required'),
            'requestedDetails':_list(_string(300),3),'evidenceIds':evidence,'notes':_string(700)}),
        'reflections':finding(_enum('no_visible_turf_reflection_observed','visible_or_possible_turf_reflection','uncertain')),
        'candidateGuidance':finding(_enum('natural_green_corresponding_turf','unsupported','uncertain')),
        'uncertainties':_list(_string(500),8)})
    if wire:
        # String size bounds are enforced again by the strict local parser.
        def visit(value):
            if isinstance(value,dict):
                value.pop('minLength',None);value.pop('maxLength',None)
                for child in value.values():visit(child)
            elif isinstance(value,list):
                for child in value:visit(child)
        visit(result)
    return result


def validate_context(context):
    _keys(context,['schemaVersion','binding','policy','evidence'],'GRASS_CONTEXT_FIELDS_INVALID')
    if context['schemaVersion']!=VERSION or context['policy']!=POLICY:raise ValueError('GRASS_POLICY_OR_VERSION_INVALID')
    binding=context['binding'];_keys(binding,BINDINGS,'GRASS_BINDING_FIELDS_INVALID')
    for key in BINDINGS[:2]:_uuid(binding[key])
    for key in BINDINGS[2:]:_hash(binding[key])
    evidence=context['evidence']
    if not isinstance(evidence,list) or len(evidence) not in {8,9} or any(not isinstance(v,dict) for v in evidence):raise ValueError('GRASS_EVIDENCE_COUNT_INVALID')
    if [v.get('id') for v in evidence]!=IDS+(['source_detail_1'] if len(evidence)==9 else []):raise ValueError('GRASS_EVIDENCE_ORDER_INVALID')
    for index,view in enumerate(evidence):
        _keys(view,['id','sha256','captureId','nativeSourceSha256','derivationSha256','width','height','projection','pose'],'GRASS_EVIDENCE_FIELDS_INVALID')
        for name in ['sha256','nativeSourceSha256','derivationSha256']:_hash(view[name])
        if view['captureId']!=binding['captureId'] or view['nativeSourceSha256']!=binding['nativeSourceSha256']:raise ValueError('GRASS_SAME_CAPTURE_BINDING_INVALID')
        if index<2:
            if (view['width'],view['height'],view['projection'],view['pose'])!=(2048,1024,'equirectangular',None):raise ValueError('GRASS_SPHERE_EVIDENCE_INVALID')
            expected=binding['guidanceSourceSha256' if index==0 else 'candidateSha256']
            if view['sha256']!=expected:raise ValueError('GRASS_IMAGE_BINDING_INVALID')
        else:
            if (view['width'],view['height'],view['projection'])!=(1024,1024,'rectilinear'):raise ValueError('GRASS_DIRECTION_EVIDENCE_INVALID')
            if index<8:
                yaw,pitch=[(0,0),(90,0),(180,0),(270,0),(0,90),(0,-90)][index-2]
                if view['pose']!={'yaw':yaw,'pitch':pitch,'hFov':100,'width':1024,'height':1024}:raise ValueError('GRASS_DIRECTION_POSE_INVALID')
            else:
                pose=view['pose'];_keys(pose,['yaw','pitch','hFov','width','height'],'GRASS_DETAIL_POSE_INVALID')
                if pose['width']!=1024 or pose['height']!=1024:raise ValueError('GRASS_DETAIL_POSE_INVALID')
                for key,lo,hi in [('yaw',-180,360),('pitch',-90,90),('hFov',30,120)]:
                    value=pose[key]
                    if isinstance(value,bool) or not isinstance(value,(int,float)) or not math.isfinite(value) or not lo<=value<=hi:raise ValueError('GRASS_DETAIL_POSE_INVALID')
    return digest(context)


def compile_request(context,image_bytes):
    context_hash=validate_context(context)
    if not isinstance(image_bytes,list) or len(image_bytes)!=len(context['evidence']) or any(not isinstance(raw,bytes) for raw in image_bytes):raise ValueError('GRASS_IMAGE_BYTES_INVALID')
    if sum(map(len,image_bytes))>24*1024**2:raise ValueError('GRASS_IMAGE_BYTES_LIMIT')
    content=[{'type':'input_text','text':json.dumps({'contextSha256':context_hash,**context},sort_keys=True)}]
    for view,raw in zip(context['evidence'],image_bytes):
        if hashlib.sha256(raw).hexdigest()!=view['sha256']:raise ValueError('GRASS_IMAGE_HASH_INVALID')
        with Image.open(io.BytesIO(raw)) as im:
            if im.format not in {'PNG','JPEG'} or im.mode not in {'RGB','RGBA'} or im.size!=(view['width'],view['height']) or getattr(im,'n_frames',1)!=1:raise ValueError('GRASS_IMAGE_ENCODING_INVALID')
            if im.mode=='RGBA' and im.getchannel('A').getextrema()!=(255,255):raise ValueError('GRASS_TRANSPARENT_EVIDENCE')
            if im.info.get('icc_profile') or im.getexif().get(274,1)!=1:raise ValueError('GRASS_IMAGE_NORMALIZATION_REQUIRED')
            mime='png' if im.format=='PNG' else 'jpeg';im.load()
        content.extend([{'type':'input_text','text':'Evidence ID: '+view['id']},{'type':'input_image','detail':'high',
            'image_url':'data:image/'+mime+';base64,'+base64.b64encode(raw).decode()}])
    return {'model':MODEL,'store':False,'service_tier':'default','reasoning':{'effort':'high'},'max_output_tokens':12000,
        'instructions':INSTRUCTIONS,'input':[{'role':'user','content':content}],
        'text':{'format':{'type':'json_schema','name':'iguide_native_grass_assessment','strict':True,'schema':response_schema(True)}}}


def parse_response(response_text,context,execution):
    context_hash=validate_context(context)
    _keys(execution,['state','requestId','providerResponseId','model','contextSha256','requestPayloadSha256','responseSha256'],'GRASS_EXECUTION_FIELDS_INVALID')
    _uuid(execution['requestId']);_hash(execution['responseSha256']);_hash(execution['requestPayloadSha256'])
    if execution['state']!='completed' or execution['model']!=MODEL or execution['contextSha256']!=context_hash:raise ValueError('GRASS_EXECUTION_BINDING_INVALID')
    if not isinstance(execution['providerResponseId'],str) or not re.fullmatch(r'[A-Za-z0-9_-]{1,200}',execution['providerResponseId']):raise ValueError('GRASS_PROVIDER_ID_INVALID')
    if not isinstance(response_text,str) or len(response_text.encode())>128*1024 or hashlib.sha256(response_text.encode()).hexdigest()!=execution['responseSha256']:raise ValueError('GRASS_RESPONSE_BYTES_INVALID')
    raw=shared._loads(response_text)
    if not isinstance(raw,dict) or raw.get('status')!='completed' or raw.get('id')!=execution['providerResponseId'] or raw.get('model')!=MODEL:raise ValueError('GRASS_RESPONSE_ENVELOPE_INVALID')
    items=raw.get('output');texts=[]
    if not isinstance(items,list):raise ValueError('GRASS_OUTPUT_INVALID')
    for item in items:
        if not isinstance(item,dict):raise ValueError('GRASS_OUTPUT_INVALID')
        if item.get('type')=='reasoning':continue
        if item.get('type')!='message' or item.get('role')!='assistant' or item.get('status')!='completed' or not isinstance(item.get('content'),list):raise ValueError('GRASS_UNEXPECTED_OUTPUT')
        for part in item['content']:
            if not isinstance(part,dict) or part.get('type')!='output_text' or not isinstance(part.get('text'),str):raise ValueError('GRASS_REFUSAL_OR_UNEXPECTED_CONTENT')
            texts.append(part['text'])
    if len(texts)!=1 or len(texts[0].encode())>64*1024:raise ValueError('GRASS_SINGLE_RESPONSE_REQUIRED')
    proposal=shared._loads(texts[0]);shared._validate(proposal,response_schema())
    if proposal['contextSha256']!=context_hash or proposal['binding']!=context['binding']:raise ValueError('GRASS_PROPOSAL_BINDING_INVALID')
    known={v['id'] for v in context['evidence']}
    def evidence(finding,required,source_only=True):
        ids=finding['evidenceIds']
        if len(ids)!=len(set(ids)) or not set(ids)<=known or not set(required)<=set(ids):raise ValueError('GRASS_FINDING_EVIDENCE_INVALID')
        if source_only and 'candidate_panorama' in ids:raise ValueError('GRASS_SOURCE_FINDING_USES_CANDIDATE')
    for name in ['material','scope','lawn']:evidence(proposal[name],['source_panorama'])
    for name in ['contextCoverage','reflections']:evidence(proposal[name],SOURCE_IDS)
    evidence(proposal['candidateGuidance'],['source_panorama','candidate_panorama'],False)
    reasons=[]
    def gate(condition,reason):
        if condition and reason not in reasons:reasons.append(reason)
    gate(proposal['material']['status']!='established_turf','TEXTURE_OR_MATERIAL_REQUIRES_REVIEW')
    gate(proposal['scope']['status']!='all_visible_established_turf','PARCEL_OR_EDIT_SCOPE_UNRESOLVED')
    gate(proposal['lawn']['status']!='visible_selectable','SOURCE_LAWN_SELECTION_UNRESOLVED')
    gate(proposal['contextCoverage']['status']!='sufficient_same_capture_views' or bool(proposal['contextCoverage']['requestedDetails']),'EXTRA_SOURCE_CONTEXT_REQUIRED')
    gate(proposal['reflections']['status']!='no_visible_turf_reflection_observed','TURF_REFLECTION_DEPENDENCY_UNRESOLVED')
    gate(proposal['candidateGuidance']['status']!='natural_green_corresponding_turf','CANDIDATE_CHROMA_GUIDANCE_UNRESOLVED')
    gate(bool(proposal['uncertainties']),'SOURCE_ASSESSMENT_UNCERTAINTY')
    for role in ['lawn',*CLASSES]:
        finding=proposal['lawn'] if role=='lawn' else proposal['exclusions'][role]
        if role!='lawn':
            evidence(finding,SOURCE_IDS if finding['status']=='none_observed' else ['source_panorama'])
            gate(finding['status'] not in {'observed_selectable','none_observed'},'EXCLUSION_UNRESOLVED:'+role)
        selected=finding['status'] in {'visible_selectable','observed_selectable'}
        if selected!=(len(finding['selections'])>0):raise ValueError('GRASS_SELECTION_STATUS_MISMATCH')
        for index,selection in enumerate(finding['selections']):
            if selection['id']!=role+'_'+str(index+1):raise ValueError('GRASS_SELECTION_ID_INVALID')
            box=selection['box'];width=(box['xMax']-box['xMin'])*2048;height=(box['yMax']-box['yMin'])*1024
            if width<=0 or height<=0:raise ValueError('GRASS_BOX_DEGENERATE_OR_WRAP_UNSPLIT')
            gate(min(width,height)<8,'SELECTION_TOO_FINE_FOR_GUIDANCE:'+role)
    report={'schemaVersion':VERSION,'status':'needs_review' if reasons else 'ready_for_source_segmentation',
        'contextSha256':context_hash,'binding':context['binding'],'policy':dict(POLICY),'proposal':proposal,'proposalSha256':digest(proposal),
        'execution':dict(execution),'reviewReasons':reasons,'authorOrigin':'machine','automaticAcceptance':False,
        'maskSemanticsVerified':False,'opticsPhysicallyVerified':False,'crossViewVerified':False,'providerCallsPerformed':0}
    report['segmentationPlan']=segmentation_plan(report) if not reasons else []
    return report


def segmentation_plan(assessment):
    if assessment['status']!='ready_for_source_segmentation' or assessment['reviewReasons'] or assessment['policy']!=POLICY:raise ValueError('GRASS_ASSESSMENT_NOT_ELIGIBLE')
    proposal=assessment['proposal'];binding=assessment['binding']
    if digest(proposal)!=assessment['proposalSha256'] or proposal['binding']!=binding or proposal['contextSha256']!=assessment['contextSha256']:raise ValueError('GRASS_ASSESSMENT_CHANGED')
    plans=[]
    for role in ['lawn',*CLASSES]:
        finding=proposal['lawn'] if role=='lawn' else proposal['exclusions'][role]
        if role!='lawn' and finding['status']=='none_observed':continue
        selections=finding['selections'];boxes=[]
        for index,selection in enumerate(selections):
            box=selection['box'];boxes.append({'x_min':math.floor(box['xMin']*2048),'y_min':math.floor(box['yMin']*1024),
                'x_max':min(2047,math.ceil(box['xMax']*2048)),'y_max':min(1023,math.ceil(box['yMax']*1024)),'object_id':index+1})
        payload={'prompt':PROMPTS[role],'box_prompts':boxes,'apply_mask':False,'output_format':'png','return_multiple_masks':True,
            'max_masks':len(boxes),'include_scores':True,'include_boxes':True}
        step={'stepKey':'source-grass-'+role,'maskRole':role,'requestId':None,'reservationState':'unreserved','model':SAM_MODEL,
            'inputSha256':binding['guidanceSourceSha256'],'width':2048,'height':1024,'payload':payload,
            'expectedIdentities':[x['id'] for x in selections],'selectionOrigin':'machine',
            'assessmentProposalSha256':assessment['proposalSha256'],'contextSha256':assessment['contextSha256'],
            'maximumPostAttempts':1,'automaticRetry':False,'semanticClassVerified':False}
        step['inputFingerprint']=digest(step);plans.append(step)
    if not 1<=len(plans)<=6:raise ValueError('GRASS_SEGMENTATION_OPERATION_LIMIT')
    return plans


def bind_reserved_plans(assessment,reservations):
    plans=segmentation_plan(assessment)
    _keys(reservations,[x['stepKey'] for x in plans],'GRASS_RESERVATION_KEYS_INVALID')
    for value in reservations.values():_uuid(value)
    if len(set(reservations.values()))!=len(plans):raise ValueError('GRASS_DUPLICATE_RESERVATION')
    for step in plans:
        step['requestId']=reservations[step['stepKey']];step['reservationState']='server_reserved'
        step['inputFingerprint']=digest({k:v for k,v in step.items() if k!='inputFingerprint'})
    return plans


def bind_segmentation_receipt(step,receipt):
    if step.get('maskRole') not in ['lawn',*CLASSES] or step.get('stepKey')!='source-grass-'+step['maskRole'] or step.get('model')!=SAM_MODEL or (step.get('width'),step.get('height'))!=(2048,1024):raise ValueError('GRASS_ROLE_CAPABILITY_INVALID')
    if step.get('reservationState')!='server_reserved' or digest({k:v for k,v in step.items() if k!='inputFingerprint'})!=step.get('inputFingerprint'):raise ValueError('GRASS_PLAN_NOT_BOUND')
    role=step['maskRole'];payload=step.get('payload');identities=step.get('expectedIdentities')
    if not isinstance(payload,dict) or not isinstance(identities,list) or not 1<=len(identities)<=8:raise ValueError('GRASS_ROLE_PLAN_INVALID')
    boxes=payload.get('box_prompts')
    if not isinstance(boxes,list) or len(boxes)!=len(identities) or identities!=[role+'_'+str(i+1) for i in range(len(identities))]:raise ValueError('GRASS_ROLE_PLAN_INVALID')
    for index,box in enumerate(boxes):
        _keys(box,['x_min','y_min','x_max','y_max','object_id'],'GRASS_ROLE_BOX_INVALID')
        if any(type(v) is not int for v in box.values()) or box['object_id']!=index+1 or not (0<=box['x_min']<box['x_max']<=2047 and 0<=box['y_min']<box['y_max']<=1023):raise ValueError('GRASS_ROLE_BOX_INVALID')
    expected_payload={'prompt':PROMPTS[role],'box_prompts':boxes,'apply_mask':False,'output_format':'png','return_multiple_masks':True,
        'max_masks':len(boxes),'include_scores':True,'include_boxes':True}
    if payload!=expected_payload:raise ValueError('GRASS_ROLE_PROMPT_OR_OPTIONS_CHANGED')
    _uuid(step['requestId'])
    _keys(receipt,['requestId','providerRequestId','model','inputFingerprint','inputSha256','rawResponseSha256','status','masks'],'GRASS_RECEIPT_FIELDS_INVALID')
    for key in ['requestId','model','inputFingerprint','inputSha256']:
        if receipt[key]!=step[key]:raise ValueError('GRASS_RECEIPT_BINDING_INVALID')
    _hash(receipt['rawResponseSha256'])
    if not isinstance(receipt['providerRequestId'],str) or not re.fullmatch(r'[A-Za-z0-9_-]{1,200}',receipt['providerRequestId']):raise ValueError('GRASS_PROVIDER_ID_INVALID')
    masks=receipt['masks'];reasons=[];associations=[]
    if receipt['status']!='completed' or not isinstance(masks,list) or len(masks)!=len(step['expectedIdentities']):reasons.append('MISSING_OR_EXTRA_ROLE_MASKS')
    else:
        for mask in masks:
            _keys(mask,['sha256','width','height','bounds','positivePixels'],'GRASS_MASK_DESCRIPTOR_INVALID');_hash(mask['sha256'])
            box=mask['bounds'];area=mask['positivePixels']
            if (mask['width'],mask['height'])!=(2048,1024) or not isinstance(area,int) or isinstance(area,bool) or not 1<=area<=2048*1024:raise ValueError('GRASS_MASK_DIMENSIONS_OR_AREA_INVALID')
            if not isinstance(box,list) or len(box)!=4 or any(type(v) is not int for v in box) or not (0<=box[0]<box[2]<=2048 and 0<=box[1]<box[3]<=1024) or area>shared._area(box):raise ValueError('GRASS_MASK_BOUNDS_INVALID')
        if len({m['sha256'] for m in masks})!=len(masks):reasons.append('DUPLICATE_ROLE_MASK')
        # Compute at most64 geometric comparisons, then stop once ambiguity is
        # demonstrated. No factorial repeated IoU computation on eight masks.
        matrix=[[shared._match(mask['bounds'],box) for mask in masks] for box in boxes]
        matches=[]
        def visit(chosen,used):
            if len(matches)>1:return
            if len(chosen)==len(masks):
                matches.append((tuple(chosen),[matrix[i][j] for i,j in enumerate(chosen)]));return
            for index,score in enumerate(matrix[len(chosen)]):
                if index not in used and score[0]>=.90 and score[1]>=.40:visit(chosen+[index],used|{index})
        visit([],set())
        if len(matches)!=1:reasons.append('ROLE_MASK_ASSOCIATION_AMBIGUOUS')
        if not reasons:
            permutation,scores=matches[0]
            for index,mask_index in enumerate(permutation):
                associations.append({'identity':step['expectedIdentities'][index],'maskRole':step['maskRole'],
                    'maskIndex':mask_index,'maskSha256':masks[mask_index]['sha256'],'sourceSha256':step['inputSha256'],
                    'generationOrigin':'model_segmentation','selectionOrigin':'machine','providerRequestId':receipt['providerRequestId'],
                    'selectionContainment':scores[index][0],'selectionIoU':scores[index][1]})
    return {'status':'needs_review' if reasons else 'ready_for_source_support','reviewReasons':reasons,'associations':associations,
        'maskSemanticsVerified':False,'automaticRetry':False,'stepKey':step['stepKey'],'maskRole':step['maskRole'],
        'requestId':step['requestId'],'inputFingerprint':step['inputFingerprint'],'inputSha256':step['inputSha256'],
        'contextSha256':step['contextSha256'],'receiptSha256':digest(receipt)}


def composition_assessment_record(assessment,reserved_plans,bindings):
    expected=bind_reserved_plans(assessment,{x['stepKey']:x['requestId'] for x in reserved_plans})
    if reserved_plans!=expected or len(bindings)!=len(expected):raise ValueError('GRASS_SUPPORT_PREREQUISITES_NOT_BOUND')
    for step,bound in zip(expected,bindings):
        if bound.get('status')!='ready_for_source_support':raise ValueError('GRASS_SUPPORT_ROLE_NOT_READY')
        for key in ['stepKey','maskRole','requestId','inputFingerprint','inputSha256','contextSha256']:
            if bound.get(key)!=step[key]:raise ValueError('GRASS_SUPPORT_RECEIPT_MIXUP')
        if [x['identity'] for x in bound['associations']]!=step['expectedIdentities']:raise ValueError('GRASS_SUPPORT_SELECTION_MIXUP')
    source=assessment['binding']
    return {'nativeSourceSha256':source['nativeSourceSha256'],'guidanceSourceSha256':source['guidanceSourceSha256'],
        'sourcePreparationSha256':source['sourcePreparationSha256'],
        'candidateSha256':source['candidateSha256'],'authorOrigin':'machine','maskSelectionOrigin':'machine',
        'lawnMaterial':'established_turf','requestedChange':'recolor_existing_turf','scope':'all_visible_established_turf',
        'exclusions':{name:('none_observed' if assessment['proposal']['exclusions'][name]['status']=='none_observed' else 'supplied') for name in CLASSES},
        'uncertainties':[],'assessmentProtocol':VERSION,'assessmentProposalSha256':assessment['proposalSha256'],
        'contextSha256':assessment['contextSha256'],'executionRequestId':assessment['execution']['requestId'],
        'segmentationReceiptSha256':[x['receiptSha256'] for x in bindings],
        'automaticAcceptance':False,'maskSemanticsVerified':False,
        'opticalAssurance':'Source-context model screening only; no physical proof. Independent full-sphere evaluation remains mandatory.'}
