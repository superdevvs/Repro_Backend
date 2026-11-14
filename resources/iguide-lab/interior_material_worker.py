"""Private, bounded material transfer adapter. No network or acceptance authority.

The caller owns provider receipt verification and actual native->cut extraction
authority. This adapter validates their supplied immutable machine associations,
not arbitrary client flags. Trusted root/runtime are separate CLI arguments.
"""
from __future__ import annotations
import argparse
import hashlib
import json
import os
from pathlib import Path
import platform
import shutil
import tempfile
import time
import uuid
for _key in ('OMP_NUM_THREADS','OPENBLAS_NUM_THREADS','MKL_NUM_THREADS','NUMEXPR_NUM_THREADS'):
    os.environ[_key]='1'
import cv2
import numpy as np
from PIL import Image, __version__ as PILLOW_VERSION
import interior_material_transfer as core
import pano_geometry

VERSION='interior-material-file-adapter/1'
RUNTIME_VERSION='interior-material-runtime/1'
PINS={'python':'3.12.3','numpy':'2.5.4','pillow':'13.0.0','opencv':'5.0.0'}
CONVENTION='raw-yaw-zero-panorama-centre-positive-right-pitch-up'
MAX_BYTES=192*1024*1024
Image.MAX_IMAGE_PIXELS=64_000_000


def canonical(value):return json.dumps(value,sort_keys=True,separators=(',',':'),allow_nan=False).encode('utf-8')


def sha(path):
    digest=hashlib.sha256()
    with Path(path).open('rb') as f:
        for block in iter(lambda:f.read(1024*1024),b''):digest.update(block)
    return digest.hexdigest()


def keys(value,expected,code):
    if not isinstance(value,dict) or set(value)!=set(expected):raise ValueError(code)


def hash_value(value):
    if not isinstance(value,str) or len(value)!=64 or any(c not in '0123456789abcdef' for c in value):raise ValueError('INVALID_SHA256')
    return value


def safe_path(value,root,exists=True):
    path=Path(value)
    if not path.is_absolute():raise ValueError('ABSOLUTE_PATH_REQUIRED')
    for parent in (path,*path.parents):
        if parent.is_symlink() or (hasattr(parent,'is_junction') and parent.is_junction()):raise ValueError('LINK_PATH_REJECTED')
    resolved=path.resolve()
    if not resolved.is_relative_to(root) or resolved==root:raise ValueError('PATH_OUTSIDE_TRUSTED_ROOT')
    if exists and (not resolved.is_file() or resolved.stat().st_size>MAX_BYTES):raise ValueError('FILE_MISSING_OR_LIMIT')
    return resolved


def read_json(path):
    if path.stat().st_size>1024*1024:raise ValueError('JSON_LIMIT')
    def pairs(items):
        d={}
        for k,v in items:
            if k in d:raise ValueError('DUPLICATE_JSON_KEY')
            d[k]=v
        return d
    return json.loads(path.read_text(encoding='utf-8'),object_pairs_hook=pairs,parse_constant=lambda _:(_ for _ in ()).throw(ValueError('NONFINITE_JSON')))


def asset(desc,root,inputs):
    keys(desc,('path','sha256'),'ASSET_DESCRIPTOR_INVALID');expected=hash_value(desc['sha256'])
    path=safe_path(desc['path'],root)
    if sha(path)!=expected:raise ValueError('INPUT_HASH_MISMATCH')
    inputs[str(path)]=expected
    return path


def json_asset(desc,root,inputs):return read_json(asset(desc,root,inputs))


def image(path,kind):
    with Image.open(path) as im:
        if getattr(im,'n_frames',1)!=1 or im.info.get('icc_profile') or im.getexif().get(274,1)!=1:raise ValueError('UNSUPPORTED_IMAGE_METADATA')
        if kind=='native':
            if im.format not in {'JPEG','PNG'} or im.width!=2*im.height or im.width*im.height>core.MAX_NATIVE_PIXELS:raise ValueError('NATIVE_SPHERE_LIMIT')
        elif im.format!='PNG' or im.size!=(2048,2048):raise ValueError('EXACT_SECTION_PNG_REQUIRED')
        if im.mode not in ({'L','LA','RGB','RGBA'} if kind=='mask' else {'RGB','RGBA'}):raise ValueError('IMAGE_MODE_UNSUPPORTED')
        if im.mode in {'RGBA','LA'} and im.getchannel('A').getextrema()!=(255,255):raise ValueError('TRANSPARENT_IMAGE_REJECTED')
        rgb=np.array(im.convert('RGB'))
        if kind=='mask':
            if not np.array_equal(rgb[...,0],rgb[...,1]) or not np.array_equal(rgb[...,0],rgb[...,2]):raise ValueError('COLORED_MASK_REJECTED')
            return rgb[...,0].copy()
        return rgb


def module_hashes():
    return {Path(module.__file__).name:sha(module.__file__) for module in (core,pano_geometry)}|{Path(__file__).name:sha(__file__)}


def runtime_check(path,expected):
    path=Path(path)
    if not path.is_absolute() or path.is_symlink() or sha(path)!=expected:raise ValueError('RUNTIME_MANIFEST_HASH_MISMATCH')
    value=read_json(path)
    keys(value,('version','validationStatus','python','numpy','pillow','opencv','files'),'RUNTIME_MANIFEST_SCHEMA')
    actual={'python':platform.python_version(),'numpy':np.__version__,'pillow':PILLOW_VERSION,'opencv':cv2.__version__}
    if value['version']!=RUNTIME_VERSION or value['validationStatus']!='passed' or any(value[k]!=v or actual[k]!=v for k,v in PINS.items()):raise ValueError('PINNED_RUNTIME_REQUIRED')
    if value['files']!=module_hashes():raise ValueError('VALIDATED_CODE_HASH_MISMATCH')
    return value


def machine_mask(record,role,target_id,input_sha,assessment_sha,root,inputs):
    keys(record,('id','role','image','evidence'),'MASK_RECORD_SCHEMA')
    if record['id']!=target_id or record['role']!=role:raise ValueError('MASK_ID_ROLE_MISMATCH')
    mask=image(asset(record['image'],root,inputs),'mask');ev=json_asset(record['evidence'],root,inputs)
    keys(ev,('version','targetId','role','inputSha256','maskSha256','generationOrigin','selectionOrigin','assessmentSha256','providerReceiptSha256'),'MASK_EVIDENCE_SCHEMA')
    wanted={'version':'interior-machine-mask-association/1','targetId':target_id,'role':role,'inputSha256':input_sha,
        'maskSha256':record['image']['sha256'],'generationOrigin':'model_segmentation','selectionOrigin':'machine','assessmentSha256':assessment_sha}
    if any(ev.get(k)!=v for k,v in wanted.items()):raise ValueError('MASK_ASSOCIATION_MISMATCH')
    hash_value(ev['providerReceiptSha256'])
    return mask


def foreground(record,source_sha,assessment_sha,assessment,root,inputs,source_only=False):
    keys(record,('id','role','image','evidence'),'FOREGROUND_RECORD_SCHEMA')
    if record['id']!='protected_foreground' or record['role']!='source-protected-foreground':raise ValueError('FOREGROUND_ROLE_INVALID')
    mask=image(asset(record['image'],root,inputs),'mask');ev=json_asset(record['evidence'],root,inputs)
    common=('version','targetId','role','inputSha256','maskSha256','generationOrigin','selectionOrigin','assessmentSha256')
    expected={'version':'interior-machine-mask-association/1','targetId':'protected_foreground','role':'source-protected-foreground',
        'inputSha256':source_sha,'maskSha256':record['image']['sha256'],'selectionOrigin':'machine','assessmentSha256':assessment_sha}
    if any(ev.get(k)!=v for k,v in expected.items()):raise ValueError('FOREGROUND_BINDING_MISMATCH')
    findings=assessment['foregroundFindings']
    keys(findings,('source',) if source_only else ('source','candidate'),'FOREGROUND_FINDINGS_SCHEMA')
    if ev.get('generationOrigin')=='machine_assessed_absence':
        keys(ev,common,'FOREGROUND_ABSENCE_SCHEMA')
        wanted={'source':'none_observed'} if source_only else {'source':'none_observed','candidate':'none_observed'}
        if findings!=wanted or mask.any():raise ValueError('FOREGROUND_ABSENCE_CONTRADICTION')
    elif ev.get('generationOrigin')=='machine_union':
        keys(ev,(*common,'members'),'FOREGROUND_UNION_SCHEMA')
        wanted={'source':'observed'} if source_only else {'source':'observed','candidate':'observed'}
        if findings!=wanted:raise ValueError('FOREGROUND_OBSERVATION_CONTRADICTION')
        members=ev['members']
        if not isinstance(members,list) or not 1<=len(members)<=8:raise ValueError('FOREGROUND_MEMBER_LIMIT')
        union=np.zeros_like(mask);ids=set()
        for item in members:
            mid=item.get('id')
            if not isinstance(mid,str) or mid in ids:raise ValueError('FOREGROUND_MEMBER_ID')
            ids.add(mid);union=np.maximum(union,machine_mask(item,'source-foreground',mid,source_sha,assessment_sha,root,inputs))
        if not mask.any() or not np.array_equal(mask,union):raise ValueError('FOREGROUND_UNION_MISMATCH')
    else:raise ValueError('FOREGROUND_ORIGIN_UNSUPPORTED')
    return mask


def inputs_unchanged(inputs):
    for path,expected in inputs.items():
        if sha(path)!=expected:raise ValueError('INPUT_DRIFT_DURING_WORK')


def save_image(array,path):
    Image.fromarray(array).save(path,format='PNG');os.chmod(path,0o660)


def replay(output,identity):
    report_path=output/'report.json';commit_path=output/'commit.json'
    if report_path.is_symlink() or commit_path.is_symlink():raise ValueError('CACHED_LINK_REJECTED')
    if not report_path.is_file() or not commit_path.is_file():raise ValueError('OUTPUT_EXISTS_INCOMPLETE')
    commit=read_json(commit_path)
    if commit!={'version':VERSION,'identitySha256':identity,'reportSha256':sha(report_path)}:raise ValueError('CACHED_REPORT_DRIFT')
    report=read_json(report_path)
    if report.get('identitySha256')!=identity or report.get('version')!=VERSION or report.get('manualAcceptance') is not False or report.get('crossViewVerified') is not False:raise ValueError('CACHED_REPORT_BINDING')
    assets=report.get('outputs');status=report.get('status')
    expected={'native','preview','effectiveAlpha','section','sectionAlpha'} if status=='unreviewed' else set()
    if status not in {'unreviewed','needs_review','eligible_for_donor_request'} or not isinstance(assets,dict) or set(assets)!=expected:raise ValueError('CACHED_OUTPUT_SHAPE')
    if status=='eligible_for_donor_request' and (report.get('operation')!='source-support' or report.get('imageGenerationAuthorized') is not False or report.get('materialTransferAuthorized') is not False):raise ValueError('CACHED_SOURCE_AUTHORITY')
    files={'report.json','commit.json'}
    for name,desc in assets.items():
        keys(desc,('file','sha256','width','height'),'CACHED_ASSET_SCHEMA')
        if Path(desc['file']).name!=desc['file'] or desc['file'] in files:raise ValueError('CACHED_ASSET_PATH')
        path=output/desc['file'];files.add(desc['file'])
        if path.is_symlink() or sha(path)!=desc['sha256']:raise ValueError('CACHED_ASSET_DRIFT')
        with Image.open(path) as im:
            if im.format!='PNG' or list(im.size)!=[desc['width'],desc['height']]:raise ValueError('CACHED_ASSET_DIMENSIONS')
    if {p.name for p in output.iterdir()}!=files:raise ValueError('CACHED_EXTRA_ASSETS')
    return report


def run(request,allowed_root,runtime_manifest):
    if request.get('operation')=='source-support':return run_source_support(request,allowed_root,runtime_manifest)
    core.initialize();start=time.monotonic();root=Path(allowed_root).resolve()
    if not Path(allowed_root).is_absolute() or not root.is_dir():raise ValueError('TRUSTED_ROOT_REQUIRED')
    keys(request,('version','requestId','outputDirectory','runtimeManifestSha256','sourceCut','source','candidate','native','geometry','assessment','protectedForeground','targets'),'REQUEST_SCHEMA')
    if request['version']!=VERSION or str(uuid.UUID(request['requestId']))!=request['requestId']:raise ValueError('REQUEST_ID_VERSION')
    runtime_sha=hash_value(request['runtimeManifestSha256']);runtime=runtime_check(runtime_manifest,runtime_sha)
    inputs={};source_cut_path=asset(request['sourceCut'],root,inputs);source_path=asset(request['source'],root,inputs);candidate_path=asset(request['candidate'],root,inputs);native_path=asset(request['native'],root,inputs)
    g=json_asset(request['geometry'],root,inputs)
    keys(g,('version','nativeSourceSha256','sourceCutSha256','preparedSourceSha256','projection','width','height','view','convention'),'GEOMETRY_SCHEMA')
    if g['version']!='interior-source-geometry/1' or g['nativeSourceSha256']!=request['native']['sha256'] or g['sourceCutSha256']!=request['sourceCut']['sha256'] or g['preparedSourceSha256']!=request['source']['sha256'] or g['projection']!='rectilinear' or (g['width'],g['height'])!=(2048,2048) or g['convention']!=CONVENTION:raise ValueError('SOURCE_GEOMETRY_BINDING')
    keys(g['view'],('yaw','pitch','hFov'),'GEOMETRY_VIEW_SCHEMA')
    # Reuse the composition contract's strict pose checks before expensive decoding.
    for key,lo,hi in (('yaw',-180,180),('pitch',-60,30),('hFov',30,120)):
        value=g['view'][key]
        if isinstance(value,bool) or not isinstance(value,(int,float)) or not np.isfinite(value) or not lo<=value<=hi:raise ValueError('GEOMETRY_VIEW_INVALID')
    assessment=json_asset(request['assessment'],root,inputs)
    keys(assessment,('version','status','authorOrigin','nativeSourceSha256','sourceCutSha256','sourceSha256','candidateSha256','sourceGeometrySha256','protectedForegroundSha256','foregroundFindings','checks','targets'),'ASSESSMENT_SCHEMA')
    bind={'version':'interior-material-assessment/1','authorOrigin':'machine','nativeSourceSha256':request['native']['sha256'],'sourceCutSha256':request['sourceCut']['sha256'],
        'sourceSha256':request['source']['sha256'],'candidateSha256':request['candidate']['sha256'],'sourceGeometrySha256':request['geometry']['sha256'],
        'protectedForegroundSha256':request['protectedForeground']['image']['sha256']}
    if any(assessment.get(k)!=v for k,v in bind.items()):raise ValueError('ASSESSMENT_BINDING_MISMATCH')
    keys(assessment['checks'],core.CHECKS,'ASSESSMENT_CHECKS_SCHEMA')
    if assessment['status'] not in {'eligible_for_transfer','needs_review'} or any(v not in {'supported','uncertain','blocked'} for v in assessment['checks'].values()):raise ValueError('ASSESSMENT_STATUS_INVALID')
    held=assessment['status']!='eligible_for_transfer' or any(v!='supported' for v in assessment['checks'].values())
    records=request['targets'];assessed=assessment['targets']
    if not isinstance(records,list) or not isinstance(assessed,list) or not 2<=len(records)<=5 or len(records)!=len(assessed):raise ValueError('TARGET_COUNT')
    am={}
    for t in assessed:
        keys(t,('id','sourceMaskSha256','candidateMaskSha256','registrationSurface'),'ASSESSED_TARGET_SCHEMA')
        if not isinstance(t['id'],str) or not 1<=len(t['id'])<=80 or t['id'] in am:raise ValueError('TARGET_ID_INVALID')
        if t['registrationSurface'] not in {'planar','locally_rigid_drape'}:held=True
        am[t['id']]=t
    protected=foreground(request['protectedForeground'],request['source']['sha256'],request['assessment']['sha256'],assessment,root,inputs)
    targets=[];seen=set()
    # Sort explicit stable IDs: provider response or dictionary order cannot affect composition.
    for rec in sorted(records,key=lambda r:r.get('id','')):
        keys(rec,('id','sourceMask','candidateMask'),'TARGET_RECORD_SCHEMA');tid=rec['id']
        if tid not in am or tid in seen:raise ValueError('TARGET_ASSOCIATION_MISMATCH')
        seen.add(tid);target=am[tid]
        if rec['sourceMask']['image']['sha256']!=target['sourceMaskSha256'] or rec['candidateMask']['image']['sha256']!=target['candidateMaskSha256']:raise ValueError('TARGET_MASK_HASH_MISMATCH')
        targets.append({'id':tid,'sourceMask':machine_mask(rec['sourceMask'],'source-material',tid,request['source']['sha256'],request['assessment']['sha256'],root,inputs),
            'candidateMask':machine_mask(rec['candidateMask'],'candidate-material',tid,request['candidate']['sha256'],request['assessment']['sha256'],root,inputs),'registrationSurface':target['registrationSurface']})
    output=safe_path(request['outputDirectory'],root,exists=False)
    if any(Path(p).is_relative_to(output) for p in inputs):raise ValueError('OUTPUT_CONTAINS_INPUT')
    identity=hashlib.sha256(canonical({'request':request,'runtime':runtime,'inputHashes':inputs})).hexdigest()
    if output.exists():
        if not output.is_dir():raise ValueError('OUTPUT_PATH_NOT_DIRECTORY')
        return replay(output,identity)
    # Extraction and production preparation are distinct immutable byte assets.
    # Their derivation is caller-verified, not inferred via a different resampler.
    image(source_cut_path,'section')
    source=image(source_path,'section');candidate=image(candidate_path,'section')
    result={'status':'needs_review','reasons':['TYPED_ASSESSMENT_HOLD'],'diagnostics':{}} if held else core.derive_transfer(source,candidate,targets,protected)
    report={'version':VERSION,'requestId':request['requestId'],'identitySha256':identity,'requestSha256':hashlib.sha256(canonical(request)).hexdigest(),
        'runtimeManifestSha256':runtime_sha,'codeHashes':runtime['files'],'inputHashes':inputs,'status':result['status'],'reasons':result['reasons'],
        'diagnostics':result['diagnostics'],'outputs':{},'manualAcceptance':False,'crossViewVerified':False,'generatedDetail':[2048,2048],
        'sourceGeometrySha256':request['geometry']['sha256'],'assessmentSha256':request['assessment']['sha256'],
        'authorityLimit':'Caller must verify retained machine receipts and exact immutable native-to-cut extraction. Numerical success is not semantic acceptance.'}
    output.parent.mkdir(parents=True,exist_ok=True)
    temporary=Path(tempfile.mkdtemp(prefix='.interior-material-',dir=output.parent));os.chmod(temporary,0o2770)
    try:
        if result['status']=='unreviewed':
            native=image(native_path,'native')
            try:
                final,alpha,stats=core.compose_native(native,result['deltaLab'],result['effectiveAlpha'],g['view'])
            except core.Hold as exc:
                report['status']='needs_review';report['reasons']=[exc.code];report['diagnostics']['nativeHold']=exc.diagnostics
            else:
                report['diagnostics']['native']=stats;report['nativeCanvas']=[native.shape[1],native.shape[0]]
                section=core.apply_section(source,result['deltaLab'],result['effectiveAlpha'])
                preview=np.array(Image.fromarray(final).resize((2048,1024),Image.Resampling.LANCZOS))
                arrays={'native':('native.png',final),'preview':('preview.png',preview),'effectiveAlpha':('effective-alpha.png',alpha),
                    'section':('section.png',section),'sectionAlpha':('section-alpha.png',result['effectiveAlpha'])}
                for name,(file,array) in arrays.items():
                    path=temporary/file;save_image(array,path)
                    report['outputs'][name]={'file':file,'sha256':sha(path),'width':array.shape[1],'height':array.shape[0]}
        inputs_unchanged(inputs)
        if sha(runtime_manifest)!=runtime_sha or module_hashes()!=runtime['files']:raise ValueError('RUNTIME_DRIFT_DURING_WORK')
        report['elapsedMs']=round((time.monotonic()-start)*1000,3)
        report_path=temporary/'report.json';report_path.write_bytes(canonical(report));os.chmod(report_path,0o660)
        commit={'version':VERSION,'identitySha256':identity,'reportSha256':sha(report_path)}
        (temporary/'commit.json').write_bytes(canonical(commit));os.chmod(temporary/'commit.json',0o660)
        # No existing directory is ever overwritten, including an incomplete attempt.
        if output.exists():raise ValueError('OUTPUT_APPEARED_DURING_WORK')
        temporary.rename(output)
        return report
    finally:
        if temporary.exists():shutil.rmtree(temporary)


def run_source_support(request,allowed_root,runtime_manifest):
    """No candidate field or donor receipt can enter the pre-image source gate."""
    core.initialize();start=time.monotonic();root=Path(allowed_root).resolve()
    if not Path(allowed_root).is_absolute() or not root.is_dir():raise ValueError('TRUSTED_ROOT_REQUIRED')
    keys(request,('version','operation','requestId','outputDirectory','runtimeManifestSha256','sourceCut','source','native','geometry','assessment','protectedForeground','targets'),'SOURCE_REQUEST_SCHEMA')
    if request['version']!=VERSION or request['operation']!='source-support' or str(uuid.UUID(request['requestId']))!=request['requestId']:raise ValueError('REQUEST_ID_VERSION')
    runtime_sha=hash_value(request['runtimeManifestSha256']);runtime=runtime_check(runtime_manifest,runtime_sha)
    inputs={};source_path=asset(request['source'],root,inputs);cut_path=asset(request['sourceCut'],root,inputs);asset(request['native'],root,inputs)
    g=json_asset(request['geometry'],root,inputs)
    keys(g,('version','nativeSourceSha256','sourceCutSha256','preparedSourceSha256','projection','width','height','view','convention'),'GEOMETRY_SCHEMA')
    if g['version']!='interior-source-geometry/1' or g['nativeSourceSha256']!=request['native']['sha256'] or g['sourceCutSha256']!=request['sourceCut']['sha256'] or g['preparedSourceSha256']!=request['source']['sha256'] or g['projection']!='rectilinear' or (g['width'],g['height'])!=(2048,2048) or g['convention']!=CONVENTION:raise ValueError('SOURCE_GEOMETRY_BINDING')
    keys(g['view'],('yaw','pitch','hFov'),'GEOMETRY_VIEW_SCHEMA')
    for key,lo,hi in (('yaw',-180,180),('pitch',-60,30),('hFov',30,120)):
        v=g['view'][key]
        if isinstance(v,bool) or not isinstance(v,(int,float)) or not np.isfinite(v) or not lo<=v<=hi:raise ValueError('GEOMETRY_VIEW_INVALID')
    assessment=json_asset(request['assessment'],root,inputs)
    keys(assessment,('version','status','authorOrigin','nativeSourceSha256','sourceCutSha256','sourceSha256','sourceGeometrySha256','protectedForegroundSha256','foregroundFindings','checks','targets'),'SOURCE_ASSESSMENT_SCHEMA')
    wanted={'version':'interior-material-source-assessment/1','authorOrigin':'machine','nativeSourceSha256':request['native']['sha256'],
        'sourceCutSha256':request['sourceCut']['sha256'],'sourceSha256':request['source']['sha256'],'sourceGeometrySha256':request['geometry']['sha256'],
        'protectedForegroundSha256':request['protectedForeground']['image']['sha256']}
    if any(assessment.get(k)!=v for k,v in wanted.items()):raise ValueError('SOURCE_ASSESSMENT_BINDING')
    keys(assessment['checks'],core.CHECKS,'ASSESSMENT_CHECKS_SCHEMA')
    if assessment['status'] not in {'eligible_for_source_support','needs_review'} or any(v not in {'supported','uncertain','blocked'} for v in assessment['checks'].values()):raise ValueError('ASSESSMENT_STATUS_INVALID')
    held=assessment['status']!='eligible_for_source_support' or any(v!='supported' for v in assessment['checks'].values())
    records=request['targets'];assessed=assessment['targets']
    if not isinstance(records,list) or not isinstance(assessed,list) or not 2<=len(records)<=5 or len(records)!=len(assessed):raise ValueError('TARGET_COUNT')
    by_id={}
    for t in assessed:
        keys(t,('id','sourceMaskSha256','registrationSurface'),'SOURCE_ASSESSED_TARGET_SCHEMA')
        if not isinstance(t['id'],str) or not 1<=len(t['id'])<=80 or t['id'] in by_id:raise ValueError('TARGET_ID_INVALID')
        hash_value(t['sourceMaskSha256']);by_id[t['id']]=t
    protected=foreground(request['protectedForeground'],request['source']['sha256'],request['assessment']['sha256'],assessment,root,inputs,source_only=True)
    targets=[];seen=set()
    for rec in sorted(records,key=lambda r:r.get('id','')):
        keys(rec,('id','sourceMask'),'SOURCE_TARGET_RECORD_SCHEMA');tid=rec['id']
        if tid not in by_id or tid in seen:raise ValueError('TARGET_ASSOCIATION_MISMATCH')
        seen.add(tid);t=by_id[tid]
        if rec['sourceMask']['image']['sha256']!=t['sourceMaskSha256']:raise ValueError('TARGET_MASK_HASH_MISMATCH')
        targets.append({'id':tid,'sourceMask':machine_mask(rec['sourceMask'],'source-material',tid,request['source']['sha256'],request['assessment']['sha256'],root,inputs),'registrationSurface':t['registrationSurface']})
    output=safe_path(request['outputDirectory'],root,exists=False)
    if any(Path(p).is_relative_to(output) for p in inputs):raise ValueError('OUTPUT_CONTAINS_INPUT')
    identity=hashlib.sha256(canonical({'request':request,'runtime':runtime,'inputHashes':inputs})).hexdigest()
    if output.exists():
        if not output.is_dir():raise ValueError('OUTPUT_PATH_NOT_DIRECTORY')
        return replay(output,identity)
    source=image(source_path,'section');extracted=image(cut_path,'section')
    if not np.array_equal(source,extracted):raise ValueError('PREPARED_CUT_PIXEL_DRIFT')
    result={'status':'needs_review','reasons':['TYPED_SOURCE_ASSESSMENT_HOLD'],'diagnostics':{}} if held else core.inspect_source_support(source,targets,protected)
    report={'version':VERSION,'operation':'source-support','requestId':request['requestId'],'identitySha256':identity,'requestSha256':hashlib.sha256(canonical(request)).hexdigest(),
        'runtimeManifestSha256':runtime_sha,'codeHashes':runtime['files'],'inputHashes':inputs,'status':result['status'],'reasons':result['reasons'],'diagnostics':result['diagnostics'],
        'outputs':{},'imageGenerationAuthorized':False,'materialTransferAuthorized':False,'manualAcceptance':False,'crossViewVerified':False,
        'sourceGeometrySha256':request['geometry']['sha256'],'assessmentSha256':request['assessment']['sha256'],
        'authorityLimit':'Source support only. Caller must verify original typed scope, retained machine receipts and current workflow authority before any image claim.'}
    output.parent.mkdir(parents=True,exist_ok=True);temporary=Path(tempfile.mkdtemp(prefix='.interior-source-',dir=output.parent));os.chmod(temporary,0o2770)
    try:
        inputs_unchanged(inputs)
        if sha(runtime_manifest)!=runtime_sha or module_hashes()!=runtime['files']:raise ValueError('RUNTIME_DRIFT_DURING_WORK')
        report['elapsedMs']=round((time.monotonic()-start)*1000,3);path=temporary/'report.json';path.write_bytes(canonical(report));os.chmod(path,0o660)
        (temporary/'commit.json').write_bytes(canonical({'version':VERSION,'identitySha256':identity,'reportSha256':sha(path)}));os.chmod(temporary/'commit.json',0o660)
        if output.exists():raise ValueError('OUTPUT_APPEARED_DURING_WORK')
        temporary.rename(output);return report
    finally:
        if temporary.exists():shutil.rmtree(temporary)


def main():
    parser=argparse.ArgumentParser();parser.add_argument('--request');parser.add_argument('--allowed-root');parser.add_argument('--runtime-manifest',required=True);parser.add_argument('--check-runtime')
    args=parser.parse_args()
    if os.name=='posix':
        import resource
        resource.setrlimit(resource.RLIMIT_AS,(3*1024**3,3*1024**3));resource.setrlimit(resource.RLIMIT_CPU,(180,180))
    os.umask(0o007)
    if args.check_runtime:
        if args.request or args.allowed_root:raise ValueError('RUNTIME_PROBE_FIELDS')
        value=runtime_check(args.runtime_manifest,hash_value(args.check_runtime))
        print(json.dumps({'status':'ready','runtimeManifestSha256':args.check_runtime,'files':value['files']}));return
    if not args.request or not args.allowed_root:raise ValueError('REQUEST_ROOT_REQUIRED')
    request_path=safe_path(args.request,Path(args.allowed_root).resolve())
    report=run(read_json(request_path),args.allowed_root,args.runtime_manifest)
    print(json.dumps({'status':report['status'],'requestId':report['requestId'],'outputDirectory':read_json(request_path)['outputDirectory']}))


if __name__=='__main__':main()
