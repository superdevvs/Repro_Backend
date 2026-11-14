"""Hash-bound, no-network lifestyle support proposal. It never composes or accepts.

Run in an isolated worker with the trusted allowed root passed separately from
the untrusted JSON. A needs_review report is not permission to use its masks.
"""
from __future__ import annotations
import argparse
import hashlib
import json
import math
import os
from pathlib import Path
import platform
import stat
import tempfile
import time
for _thread_key in ('OMP_NUM_THREADS','OPENBLAS_NUM_THREADS','MKL_NUM_THREADS','NUMEXPR_NUM_THREADS'):
    os.environ[_thread_key]='1'
import numpy as np
from PIL import Image, __version__ as PILLOW_VERSION
import lifestyle_support as core
import lifestyle_assessment_protocol as protocol
import staging_assessment_protocol as receipt_protocol

VERSION='lifestyle-support-file-adapter/1'
MAX_FILE=128*1024*1024


def sha(path):
    h=hashlib.sha256()
    with Path(path).open('rb') as f:
        for b in iter(lambda:f.read(1024*1024),b''):h.update(b)
    return h.hexdigest()


def canonical(value):return json.dumps(value,sort_keys=True,separators=(',',':'),allow_nan=False).encode()


def safe_path(value,root,exists=True):
    path=Path(value)
    if not path.is_absolute():raise ValueError('ABSOLUTE_PATH_REQUIRED')
    for part in [path,*path.parents]:
        if part.is_symlink() or (hasattr(part,'is_junction') and part.is_junction()):raise ValueError('LINK_PATH_REJECTED')
    resolved=path.resolve()
    if not resolved.is_relative_to(root):raise ValueError('PATH_OUTSIDE_TRUSTED_ROOT')
    if exists and not resolved.is_file():raise ValueError('INPUT_FILE_MISSING')
    if exists and resolved.stat().st_size>MAX_FILE:raise ValueError('INPUT_FILE_LIMIT')
    return resolved


def asset(desc,root,inputs):
    if not isinstance(desc,dict) or set(desc)!={'path','sha256'}:raise ValueError('ASSET_DESCRIPTOR_INVALID')
    expected=desc['sha256']
    if not isinstance(expected,str) or len(expected)!=64 or any(c not in '0123456789abcdef' for c in expected):raise ValueError('SHA256_INVALID')
    path=safe_path(desc['path'],root)
    if sha(path)!=expected:raise ValueError('INPUT_HASH_MISMATCH')
    inputs[str(path)]=expected
    return path


def read_image(path,mode):
    with Image.open(path) as im:
        if im.format!='PNG' or im.size!=(2048,2048) or getattr(im,'n_frames',1)!=1:raise ValueError('EXACT_SECTION_PNG_REQUIRED')
        if mode=='L':
            if im.mode not in {'L','LA','RGB','RGBA'}:raise ValueError('EXACT_MASK_FORMAT_UNSUPPORTED')
            if im.mode in {'LA','RGBA'} and im.getchannel('A').getextrema()!=(255,255):raise ValueError('TRANSPARENT_MASK_UNSUPPORTED')
        elif mode=='RGB' and im.mode=='RGBA':
            if im.getchannel('A').getextrema()!=(255,255):raise ValueError('TRANSPARENT_SECTION_UNSUPPORTED')
        elif im.mode!=mode:raise ValueError('EXACT_SECTION_PNG_REQUIRED')
        if im.info.get('icc_profile') or im.getexif().get(274,1)!=1:raise ValueError('UNSUPPORTED_IMAGE_METADATA')
        im.load()
        if mode=='L':
            rgb=np.asarray(im.convert('RGB'))
            if not np.array_equal(rgb[...,0],rgb[...,1]) or not np.array_equal(rgb[...,0],rgb[...,2]):raise ValueError('COLORED_MASK_UNSUPPORTED')
            return rgb[...,0].copy()
        # Opaque provider RGBA is decoded in memory; original file/hash stay exact.
        return np.array(im.convert(mode))


def json_asset(desc,root,inputs):
    path=asset(desc,root,inputs)
    if path.stat().st_size>512*1024:raise ValueError('JSON_LIMIT')
    value=json.loads(path.read_text(encoding='utf-8-sig'))
    if not isinstance(value,dict):raise ValueError('JSON_OBJECT_REQUIRED')
    return value


def mask_record(record,role,input_sha,root,inputs,origin_records):
    required={'id','role','image','evidence'}
    if not isinstance(record,dict) or set(record)!=required or record['role']!=role:raise ValueError('MASK_ROLE_INVALID')
    if not isinstance(record['id'],str) or not 1<=len(record['id'])<=80:raise ValueError('MASK_ID_INVALID')
    path=asset(record['image'],root,inputs);mask=read_image(path,'L')
    evidence=json_asset(record['evidence'],root,inputs)
    if evidence.get('inputSha256')!=input_sha or evidence.get('maskSha256')!=record['image']['sha256']:
        raise ValueError('MASK_SOURCE_ROLE_MISMATCH')
    if evidence.get('generationOrigin') not in {'model_segmentation','manual','synthetic'}:raise ValueError('MASK_ORIGIN_UNKNOWN')
    if evidence.get('selectionOrigin') not in {'machine','human','unknown'}:raise ValueError('SELECTION_ORIGIN_UNKNOWN')
    origin_records.append({'id':record['id'],'role':role,'imageSha256':record['image']['sha256'],
        'evidenceSha256':record['evidence']['sha256'],'generationOrigin':evidence['generationOrigin'],
        'selectionOrigin':evidence['selectionOrigin'],'evidenceTrust':'supplied record; provider authenticity is verified by caller'})
    return mask


def _jsonable(value):
    if isinstance(value,dict):return {k:_jsonable(v) for k,v in value.items()}
    if isinstance(value,list):return [_jsonable(v) for v in value]
    if isinstance(value,np.generic):return value.item()
    return value


def validate_cached_report(out,root,request,inputs,origins,identity,fingerprint,has_floor):
    report_path=safe_path(str(out/'report.json'),root)
    if report_path.stat().st_size>2*1024*1024:raise ValueError('CACHE_REPORT_LIMIT')
    report=json.loads(report_path.read_text(encoding='utf-8'))
    if not isinstance(report,dict) or report.get('requestFingerprint')!=fingerprint or report.get('requestSha256')!=hashlib.sha256(canonical(request)).hexdigest():
        raise ValueError('CACHE_REQUEST_OR_RUNTIME_CHANGED')
    if report.get('adapter')!=VERSION or report.get('adapterSha256')!=identity['adapterSha256'] or report.get('runtime')!=identity['core'] or report.get('workerRuntime')!=identity['worker'] or report.get('protocolSha256')!=identity['protocolSha256'] or report.get('receiptProtocolSha256')!=identity['receiptProtocolSha256']:
        raise ValueError('CACHE_CODE_OR_RUNTIME_CHANGED')
    if report.get('schemaVersion')!=1 or report.get('recipe')!=core.VERSION or report.get('freshAutonomousSuccess') is not False:
        raise ValueError('CACHE_RECIPE_INVALID')
    if report.get('status') not in {'needs_review','ready_for_unreviewed_composition'} or report.get('automaticAcceptance') is not False or report.get('nativeCompositionPerformed') is not False or report.get('providerCalls')!=0:
        raise ValueError('CACHE_STATUS_INVALID')
    reasons=report.get('reviewReasons')
    if not isinstance(reasons,list) or any(not isinstance(x,str) for x in reasons) or (report['status']=='needs_review')!=bool(reasons) or report.get('diagnosticOnly')!=(report['status']=='needs_review'):
        raise ValueError('CACHE_REVIEW_STATE_INVALID')
    if report.get('inputAssets')!=inputs or report.get('maskProvenance')!=origins:raise ValueError('CACHE_INPUT_BINDING_CHANGED')
    for key,desc in [('sourceCutSha256','sourceCut'),('candidateCutSha256','candidateCut'),('sourceContextSha256','sourceContext'),('sourceGeometrySha256','sourceGeometry'),('assessmentSha256','assessment')]:
        if report.get(key)!=request[desc]['sha256']:raise ValueError('CACHE_SOURCE_BINDING_CHANGED')
    expected={'objectAlpha':'object-alpha.png','scalarShadow':'scalar-shadow.png'}
    if has_floor:expected['safeFloorAlpha']='safe-floor-alpha.png'
    if not isinstance(report.get('assets'),dict) or set(report['assets'])!=set(expected):raise ValueError('CACHE_ASSET_SET_INVALID')
    for key,name in expected.items():
        desc=report['assets'][key]
        if not isinstance(desc,dict) or set(desc)!={'file','sha256','width','height','mode'} or desc['file']!=name or (desc['width'],desc['height'],desc['mode'])!=(2048,2048,'L'):
            raise ValueError('CACHE_ASSET_DESCRIPTOR_INVALID')
        path=safe_path(str(out/name),root)
        if sha(path)!=desc['sha256']:raise ValueError('CACHE_OUTPUT_HASH_CHANGED')
        pixels=read_image(path,'L')
        if key=='scalarShadow' and report['status']=='needs_review' and np.any(pixels):raise ValueError('CACHE_SHADOW_GATE_INVALID')
    if any(sha(path)!=expected_sha for path,expected_sha in inputs.items()):raise ValueError('INPUT_CHANGED_DURING_REPLAY')
    return report


def execute(request,allowed_root):
    start=time.monotonic();root=Path(allowed_root).resolve()
    if not root.is_dir():raise ValueError('TRUSTED_ROOT_MISSING')
    expected={'schemaVersion','operation','sourceCut','candidateCut','sourceContext','sourceGeometry','assessment',
        'sourceFloor','candidatePeople','pose','outputDirectory'}
    if not isinstance(request,dict) or set(request)!=expected:raise ValueError('REQUEST_KEYS_INVALID')
    if request['schemaVersion']!=1 or request['operation']!='derive-lifestyle-support':raise ValueError('OPERATION_INVALID')
    if platform.python_version_tuple()[:2]!=('3','12') or PILLOW_VERSION not in {'12.3.0','13.0.0'}:raise ValueError('UNVALIDATED_FILE_RUNTIME')
    out=safe_path(request['outputDirectory'],root,exists=False)
    inputs={};origins=[]
    src=asset(request['sourceCut'],root,inputs);cand=asset(request['candidateCut'],root,inputs)
    context_path=asset(request['sourceContext'],root,inputs)
    with Image.open(context_path) as context_image:
        width,height=context_image.size
        if context_image.format not in {'PNG','JPEG','WEBP'} or width!=2*height or width<2048 or width*height>64000000:
            raise ValueError('SOURCE_CONTEXT_PANORAMA_REQUIRED')
        context_image.verify()
    source=read_image(src,'RGB');candidate=read_image(cand,'RGB')
    geometry=json_asset(request['sourceGeometry'],root,inputs)
    if geometry.get('sourceCutSha256')!=request['sourceCut']['sha256'] or geometry.get('projection')!='rectilinear' or geometry.get('width')!=2048 or geometry.get('height')!=2048 or geometry.get('pose')!=request['pose']:
        raise ValueError('SOURCE_GEOMETRY_BINDING_INVALID')
    native_sha=geometry.get('nativeSourceSha256','')
    if not isinstance(native_sha,str) or len(native_sha)!=64 or any(c not in '0123456789abcdef' for c in native_sha):raise ValueError('NATIVE_SOURCE_HASH_REQUIRED')
    assessment=json_asset(request['assessment'],root,inputs)
    for key,descriptor in [('sourceCutSha256','sourceCut'),('candidateCutSha256','candidateCut'),('sourceContextSha256','sourceContext')]:
        if assessment.get(key)!=request[descriptor]['sha256']:raise ValueError('ACTUAL_PLACEMENT_ASSESSMENT_BINDING_INVALID')
    if assessment.get('nativeSourceSha256')!=native_sha:raise ValueError('ASSESSMENT_NATIVE_SOURCE_BINDING_INVALID')
    if assessment.get('sourceGeometrySha256')!=request['sourceGeometry']['sha256']:raise ValueError('ASSESSMENT_GEOMETRY_BINDING_INVALID')
    items=request['candidatePeople']
    if not isinstance(items,list) or [x.get('id') for x in items]!=['adult_1','adult_2']:
        raise ValueError('EXPECTED_TWO_PERSON_IDENTITIES_REQUIRED')
    people=[mask_record(x,'candidate_person',request['candidateCut']['sha256'],root,inputs,origins) for x in items]
    floor=mask_record(request['sourceFloor'],'source_floor',request['sourceCut']['sha256'],root,inputs,origins)
    if not all(x['selectionOrigin']=='machine' and x['generationOrigin']=='model_segmentation' for x in origins) or assessment.get('authorOrigin')!='machine':
        raise ValueError('MACHINE_ASSESSMENT_REQUIRED')
    typed=assessment.get('typedAssessment',{})
    if typed.get('proposalSha256')!=assessment.get('assessmentProposalSha256') or typed.get('contextSha256')!=assessment.get('contextSha256'):
        raise ValueError('TYPED_PEOPLE_ASSESSMENT_CHANGED')
    if protocol.digest(typed.get('proposal'))!=typed.get('proposalSha256'):raise ValueError('PEOPLE_PROPOSAL_CHANGED')
    for key in ['nativeSourceSha256','sourceCutSha256','candidateCutSha256','sourceGeometrySha256']:
        if typed.get('binding',{}).get(key)!=assessment.get(key):raise ValueError('PEOPLE_ASSESSMENT_BINDING_CHANGED')
    identity={'adapterSha256':sha(__file__),'protocolSha256':sha(protocol.__file__),'receiptProtocolSha256':sha(receipt_protocol.__file__),'core':core.runtime(),
        'worker':{'python':platform.python_version(),'pillow':PILLOW_VERSION}}
    fingerprint=hashlib.sha256(canonical({'request':request,'identity':identity})).hexdigest()
    if out.exists():
        if not out.is_dir():raise ValueError('CACHE_OUTPUT_NOT_DIRECTORY')
        report=validate_cached_report(out,root,request,inputs,origins,identity,fingerprint,floor is not None)
        if report.get('nativeSourceSha256')!=native_sha:raise ValueError('CACHE_NATIVE_SOURCE_BINDING_CHANGED')
        return report
    result=core.derive_support(source,candidate,people,floor,request['pose'],typed)
    report=result['report']
    report.update({'adapter':VERSION,'adapterSha256':sha(__file__),'requestSha256':hashlib.sha256(canonical(request)).hexdigest(),
        'requestFingerprint':fingerprint,'protocolSha256':identity['protocolSha256'],'receiptProtocolSha256':identity['receiptProtocolSha256'],
        'sourceCutSha256':request['sourceCut']['sha256'],'candidateCutSha256':request['candidateCut']['sha256'],
        'sourceContextSha256':request['sourceContext']['sha256'],'assessmentSha256':request['assessment']['sha256'],
        'sourceGeometrySha256':request['sourceGeometry']['sha256'],'nativeSourceSha256':native_sha,
        'inputAssets':inputs,'maskProvenance':origins,'sourceFloorAuthority':'immutable original cut, never generated background',
        'nativeCompositionPerformed':False,'freshAutonomousSuccess':False,
        'diagnosticOnly':report['status']=='needs_review','workerRuntime':identity['worker']})
    # Inputs are checked again after all processing; no silent drift.
    if any(sha(path)!=expected_sha for path,expected_sha in inputs.items()):raise ValueError('INPUT_CHANGED_DURING_PROCESSING')
    out.parent.mkdir(parents=True,exist_ok=True)
    tmp=Path(tempfile.mkdtemp(prefix='.support-',dir=out.parent))
    try:
        os.chmod(tmp,0o2770);assets={}
        for key,filename in [('objectAlpha','object-alpha.png'),('scalarShadow','scalar-shadow.png'),('safeFloorAlpha','safe-floor-alpha.png')]:
            if result[key] is None:continue
            path=tmp/filename;Image.fromarray(result[key]).save(path);os.chmod(path,0o660)
            assets[key]={'file':filename,'sha256':sha(path),'width':2048,'height':2048,'mode':'L'}
        report['assets']=assets;report['elapsedMs']=round((time.monotonic()-start)*1000)
        path=tmp/'report.json';path.write_text(json.dumps(_jsonable(report),indent=2,allow_nan=False),encoding='utf-8');os.chmod(path,0o660)
        tmp.rename(out)
    except Exception:
        # Keep diagnostic work privately; no recursive cleanup on an unverified path.
        raise
    return _jsonable(report)


def main():
    parser=argparse.ArgumentParser();parser.add_argument('request');parser.add_argument('--allowed-root',required=True)
    args=parser.parse_args()
    if os.name=='posix':
        import resource
        resource.setrlimit(resource.RLIMIT_AS,(2*1024**3,2*1024**3))
        resource.setrlimit(resource.RLIMIT_CPU,(90,90))
        os.umask(0o007)
    path=Path(args.request)
    if path.stat().st_size>512*1024:raise ValueError('REQUEST_LIMIT')
    report=execute(json.loads(path.read_text(encoding='utf-8-sig')),args.allowed_root)
    print(json.dumps({'status':report['status'],'reviewReasons':report['reviewReasons'],'providerCalls':0}))


if __name__=='__main__':main()
