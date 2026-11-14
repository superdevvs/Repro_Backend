"""Original-only admissibility before paid grass images. No segmentation authority."""
from __future__ import annotations
import base64
import hashlib
import io
import json
import math
import re
from PIL import Image
import staging_assessment_protocol as shared

VERSION='grass-original-source-admission/1'
MODEL='gpt-6-astra'
SOURCE_IDS=['source_panorama','source_front','source_right','source_back','source_left','source_zenith','source_nadir']
ROLES=['paving','plants','loose_debris','non_turf_ground','capture_artifact']
BINDINGS=['workflowItemId','captureId','nativeSourceSha256','sourceSha256','sourcePreparationSha256','sourceAuthoritySha256']
POLICY={'scope':'all_visible_established_turf','operation':'recolor_existing_turf','sourceMaskWidth':2048,'sourceMaskHeight':1024,
    'maximumRounds':2,'maximumDetailViews':2,'detailWidth':1024,'detailHeight':1024,'minimumDetailHfov':30,'maximumDetailHfov':70}
INSTRUCTIONS='''Screen the immutable ORIGINAL source for a bounded turf-color edit BEFORE any image is generated. There is no generated candidate in this request. The panorama, directional views and any native detail views are projections of one capture, not independent cameras. Preserve original pixels, luminance, blades, sparse variation, leaves, stems, planting beds, paths, structures and the capture artifact. This recipe changes existing turf chroma only; it cannot create blades, replace missing turf, fill bare soil, remove debris or certify parcel boundaries.

Established dormant grass with visible retained blade texture is eligible for natural green recoloring; do not mistake brown color alone for missing turf. Sparse/missing turf, bare soil, unclear grass-versus-soil support, unresolved reflection dependencies, or protected debris too fine/dense for exact 2048x1024 source masks must hold. Native details can clarify material boundaries but cannot increase the segmentation mask resolution. Never use an attractive hypothetical green result as evidence of original material or absence of objects.

Assess all visible turf and each listed exclusion from original evidence. none_observed requires the panorama and all six directions, not an absent mask. Explain uncertainty and preserve it. If at most two original native detail views could resolve a boundary/material ambiguity, request bounded yaw/pitch/hFov poses with their purpose and source evidence. Yaw follows supplied camera convention (0 front,90 right,180 back,270 left), pitch is positive up. Width/height are fixed by the server. Detail requests are diagnostic views, not edit regions, masks, measured calibration or permission to expand the edit. Do not request detail for known missing turf or unsegmentable dense/fine debris. The initial round may request one follow-up only. A detail round must decide from the same seven overview images plus the exact requested original crops; unresolved findings still hold and no further view request is allowed.

Return the strict schema. All findings cite original evidence IDs. Full coverage, reflections and none_observed exclusions cite all seven base views. Preserve relevant prior findings when details are supplied; a small crop cannot clear unobserved whole-sphere concerns. This report is screening, not final quality, segmentation or acceptance. Do not invent a successful result or a human review.'''

digest=shared.digest
_hash=shared._hash
obj=shared._obj
enum=shared._enum
string=shared._string
array=shared._list

def pose_schema():
    return obj({'yaw':{'type':'number','minimum':0,'maximum':360},'pitch':{'type':'number','minimum':-85,'maximum':85},
        'hFov':{'type':'number','minimum':30,'maximum':70}})

def response_schema(wire=False):
    evidence=array(string(40),9,1)
    finding=lambda states:obj({'status':enum(*states),'evidenceIds':evidence,'notes':string(700)})
    schema=obj({'schemaVersion':enum(VERSION),'contextSha256':string(64),
        'binding':obj({key:string(36 if key in BINDINGS[:2] else 64) for key in BINDINGS}),
        'summary':string(700),'material':finding(['established_turf','established_dormant_turf','sparse_or_missing_turf','bare_soil','uncertain']),
        'scope':finding(['all_visible_established_turf','parcel_subset_requested','uncertain']),
        'lawnBoundary':finding(['complete_visible_boundary','uncertain']),
        'exclusions':obj({role:finding(['separable_at_source_mask_resolution','none_observed','too_fine_or_dense','uncertain']) for role in ROLES}),
        'reflections':finding(['no_visible_turf_reflection_observed','visible_or_possible_turf_reflection','uncertain']),
        'contextCoverage':obj({'status':enum('sufficient_same_capture_views','details_may_resolve','insufficient_context'),
            'detailRequests':array(obj({'pose':pose_schema(),'purpose':string(400),'evidenceIds':evidence}),2),
            'evidenceIds':evidence,'notes':string(700)}),'uncertainties':array(string(500),8)})
    if wire:
        def strip(value):
            if isinstance(value,dict):return {k:strip(v) for k,v in value.items() if k not in {'minLength','maxLength'}}
            if isinstance(value,list):return [strip(v) for v in value]
            return value
        return strip(schema)
    return schema

def validate_context(context):
    shared._keys(context,['schemaVersion','binding','policy','round','prior','evidence'],'ADMISSION_CONTEXT_FIELDS_INVALID')
    if context['schemaVersion']!=VERSION or context['policy']!=POLICY or type(context['round']) is not int or context['round'] not in [0,1]:
        raise ValueError('ADMISSION_POLICY_INVALID')
    binding=context['binding'];shared._keys(binding,BINDINGS,'ADMISSION_BINDING_INVALID')
    for key in BINDINGS[:2]:shared._uuid(binding[key])
    for key in BINDINGS[2:]:shared._hash(binding[key])
    prior=context['prior'];details=[]
    if context['round']==0:
        if prior is not None:raise ValueError('ADMISSION_PRIOR_UNEXPECTED')
    else:
        shared._keys(prior,['assessmentId','resultSha256','proposal'],'ADMISSION_PRIOR_INVALID')
        shared._uuid(prior['assessmentId']);shared._hash(prior['resultSha256'])
        shared._validate(prior['proposal'],response_schema())
        if prior['proposal']['binding']!=binding or prior['proposal']['contextCoverage']['status']!='details_may_resolve':
            raise ValueError('ADMISSION_PRIOR_BINDING_INVALID')
        details=prior['proposal']['contextCoverage']['detailRequests']
        if not 1<=len(details)<=2:raise ValueError('ADMISSION_DETAIL_COUNT_INVALID')
    evidence=context['evidence'];expected=SOURCE_IDS+[f'source_detail_{i+1}' for i in range(len(details))]
    if not isinstance(evidence,list) or len(evidence)!=len(expected) or [v.get('id') for v in evidence if isinstance(v,dict)]!=expected:
        raise ValueError('ADMISSION_EVIDENCE_ORDER_INVALID')
    for i,view in enumerate(evidence):
        shared._keys(view,['id','sha256','captureId','nativeSourceSha256','derivationSha256','width','height','projection','pose'],'ADMISSION_EVIDENCE_FIELDS_INVALID')
        for key in ['sha256','nativeSourceSha256','derivationSha256']:shared._hash(view[key])
        if view['captureId']!=binding['captureId'] or view['nativeSourceSha256']!=binding['nativeSourceSha256']:
            raise ValueError('ADMISSION_SOURCE_BINDING_INVALID')
        if i==0:
            if (view['sha256'],view['width'],view['height'],view['projection'],view['pose'])!=(binding['sourceSha256'],2048,1024,'equirectangular',None):
                raise ValueError('ADMISSION_SPHERE_INVALID')
        else:
            if (view['width'],view['height'],view['projection'])!=(1024,1024,'rectilinear'):raise ValueError('ADMISSION_VIEW_INVALID')
            if i<7:
                yaw,pitch=[(0,0),(90,0),(180,0),(270,0),(0,90),(0,-90)][i-1]
                expected_pose={'yaw':yaw,'pitch':pitch,'hFov':100,'width':1024,'height':1024}
            else:expected_pose={**details[i-7]['pose'],'width':1024,'height':1024}
            if view['pose']!=expected_pose:raise ValueError('ADMISSION_VIEW_POSE_INVALID')
    return digest(context)

def compile_request(context,images):
    context_sha=validate_context(context)
    if not isinstance(images,list) or len(images)!=len(context['evidence']) or any(not isinstance(raw,bytes) for raw in images) or sum(map(len,images))>24*1024**2:
        raise ValueError('ADMISSION_IMAGE_BYTES_INVALID')
    content=[{'type':'input_text','text':json.dumps({'contextSha256':context_sha,**context},sort_keys=True)}]
    for view,raw in zip(context['evidence'],images):
        if hashlib.sha256(raw).hexdigest()!=view['sha256']:raise ValueError('ADMISSION_IMAGE_HASH_INVALID')
        with Image.open(io.BytesIO(raw)) as im:
            if im.format!='PNG' or im.mode not in {'RGB','RGBA'} or im.size!=(view['width'],view['height']) or getattr(im,'n_frames',1)!=1:
                raise ValueError('ADMISSION_IMAGE_ENCODING_INVALID')
            if im.mode=='RGBA' and im.getchannel('A').getextrema()!=(255,255):raise ValueError('ADMISSION_IMAGE_ALPHA_INVALID')
            if im.info.get('icc_profile') or im.getexif().get(274,1)!=1:raise ValueError('ADMISSION_IMAGE_NORMALIZATION_REQUIRED')
            im.load()
        content.extend([{'type':'input_text','text':'Evidence ID: '+view['id']},{'type':'input_image','detail':'high','image_url':'data:image/png;base64,'+base64.b64encode(raw).decode()}])
    return {'model':MODEL,'store':False,'service_tier':'default','reasoning':{'effort':'high'},'max_output_tokens':10000,
        'instructions':INSTRUCTIONS,'input':[{'role':'user','content':content}],
        'text':{'format':{'type':'json_schema','name':'iguide_grass_source_admission','strict':True,'schema':response_schema(True)}}}

def parse_response(response_text,context,execution):
    context_sha=validate_context(context)
    shared._keys(execution,['state','requestId','providerResponseId','model','contextSha256','requestPayloadSha256','responseSha256'],'ADMISSION_EXECUTION_INVALID')
    shared._uuid(execution['requestId']);shared._hash(execution['responseSha256']);shared._hash(execution['requestPayloadSha256'])
    if execution['state']!='completed' or execution['model']!=MODEL or execution['contextSha256']!=context_sha:raise ValueError('ADMISSION_EXECUTION_BINDING_INVALID')
    if not isinstance(execution['providerResponseId'],str) or not re.fullmatch(r'[A-Za-z0-9_-]{1,200}',execution['providerResponseId']):raise ValueError('ADMISSION_PROVIDER_ID_INVALID')
    if not isinstance(response_text,str) or len(response_text.encode())>128*1024 or hashlib.sha256(response_text.encode()).hexdigest()!=execution['responseSha256']:
        raise ValueError('ADMISSION_RESPONSE_BYTES_INVALID')
    raw=shared._loads(response_text)
    if not isinstance(raw,dict) or raw.get('status')!='completed' or raw.get('model')!=MODEL or raw.get('id')!=execution['providerResponseId']:
        raise ValueError('ADMISSION_RESPONSE_ENVELOPE_INVALID')
    if not isinstance(raw.get('output'),list):raise ValueError('ADMISSION_OUTPUT_INVALID')
    texts=[]
    for item in raw['output']:
        if not isinstance(item,dict):raise ValueError('ADMISSION_OUTPUT_INVALID')
        if item.get('type')=='reasoning':continue
        if item.get('type')!='message' or item.get('role')!='assistant' or item.get('status')!='completed' or not isinstance(item.get('content'),list):
            raise ValueError('ADMISSION_OUTPUT_INVALID')
        for part in item['content']:
            if not isinstance(part,dict) or part.get('type')!='output_text' or not isinstance(part.get('text'),str):raise ValueError('ADMISSION_OUTPUT_INVALID')
            texts.append(part['text'])
    if len(texts)!=1 or len(texts[0].encode())>64*1024:raise ValueError('ADMISSION_SINGLE_OUTPUT_REQUIRED')
    proposal=shared._loads(texts[0]);shared._validate(proposal,response_schema())
    if proposal['contextSha256']!=context_sha or proposal['binding']!=context['binding']:raise ValueError('ADMISSION_PROPOSAL_BINDING_INVALID')
    known={v['id'] for v in context['evidence']}
    def evidence(finding,required):
        ids=finding['evidenceIds']
        if len(ids)!=len(set(ids)) or not set(required)<=set(ids) or not set(ids)<=known:raise ValueError('ADMISSION_FINDING_EVIDENCE_INVALID')
    for field in ['material','scope','lawnBoundary']:evidence(proposal[field],['source_panorama'])
    for field in ['reflections','contextCoverage']:evidence(proposal[field],SOURCE_IDS)
    reasons=[];hard=[]
    def hold(condition,reason,permanent=False):
        if condition:
            reasons.append(reason)
            if permanent:hard.append(reason)
    material=proposal['material']['status']
    hold(material not in {'established_turf','established_dormant_turf'},'SOURCE_TURF_MATERIAL_UNRESOLVED',material in {'sparse_or_missing_turf','bare_soil'})
    hold(proposal['scope']['status']!='all_visible_established_turf','SOURCE_SCOPE_UNRESOLVED',proposal['scope']['status']=='parcel_subset_requested')
    hold(proposal['lawnBoundary']['status']!='complete_visible_boundary','SOURCE_LAWN_BOUNDARY_UNRESOLVED')
    for role in ROLES:
        finding=proposal['exclusions'][role]
        evidence(finding,SOURCE_IDS if finding['status']=='none_observed' else ['source_panorama'])
        hold(finding['status'] not in {'separable_at_source_mask_resolution','none_observed'},'SOURCE_EXCLUSION_UNRESOLVED:'+role,finding['status']=='too_fine_or_dense')
    hold(proposal['reflections']['status']!='no_visible_turf_reflection_observed','SOURCE_REFLECTION_UNRESOLVED',proposal['reflections']['status']=='visible_or_possible_turf_reflection')
    coverage=proposal['contextCoverage'];requests=coverage['detailRequests']
    hold(coverage['status']!='sufficient_same_capture_views','SOURCE_CONTEXT_UNRESOLVED',coverage['status']=='insufficient_context')
    hold(bool(proposal['uncertainties']),'SOURCE_ADMISSION_UNCERTAINTY')
    if coverage['status']=='details_may_resolve' and not requests:raise ValueError('ADMISSION_DETAIL_REQUEST_REQUIRED')
    if coverage['status']!='details_may_resolve' and requests:raise ValueError('ADMISSION_UNEXPECTED_DETAIL_REQUEST')
    for detail in requests:evidence(detail,['source_panorama'])
    if len({digest(request['pose']) for request in requests})!=len(requests):raise ValueError('ADMISSION_DUPLICATE_DETAIL_POSE')
    if context['round']==1 and requests:hold(True,'SOURCE_DETAIL_BUDGET_EXHAUSTED',True)
    status='eligible_for_image' if not reasons else 'needs_review'
    if reasons and not hard and context['round']==0 and requests:status='needs_detail'
    return {'schemaVersion':VERSION,'status':status,'contextSha256':context_sha,'binding':context['binding'],'policy':dict(POLICY),
        'round':context['round'],'proposal':proposal,'proposalSha256':digest(proposal),'execution':dict(execution),
        'reviewReasons':reasons,'detailPlan':requests if status=='needs_detail' else [],'authorOrigin':'machine',
        'automaticAcceptance':False,'segmentationAuthority':False,'crossViewVerified':False,'providerCallsPerformed':0}
