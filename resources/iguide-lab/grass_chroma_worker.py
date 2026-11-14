"""Private no-network wrapper for a source-native grass color candidate."""
from __future__ import annotations
import argparse
import hashlib
import json
import os
from pathlib import Path
import sys
import tempfile
for _thread_key in ('OMP_NUM_THREADS','OPENBLAS_NUM_THREADS','MKL_NUM_THREADS','NUMEXPR_NUM_THREADS'):
    os.environ[_thread_key]='1'
import numpy as np
from PIL import Image
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
try:import workflow_qa as qa
except ModuleNotFoundError:import panorama_worker as qa
import grass_chroma as core

VERSION='native-grass-chroma-adapter/1'


def strict_json(text):
    def pairs(items):
        out={}
        for key,value in items:
            if key in out:raise ValueError('DUPLICATE_JSON_KEY')
            out[key]=value
        return out
    def reject(_):raise ValueError('NONFINITE_JSON')
    return json.loads(text,object_pairs_hook=pairs,parse_constant=reject)


def _json(path):
    if path.stat().st_size>512*1024:raise ValueError('JSON_SIZE_LIMIT')
    value=strict_json(path.read_text(encoding='utf-8-sig'))
    if not isinstance(value,dict):raise ValueError('JSON_OBJECT_REQUIRED')
    return value


def _mask_image(path):
    with Image.open(path) as im:
        if im.format!='PNG' or im.size!=(2048,1024) or im.mode not in {'L','LA','RGB','RGBA'} or getattr(im,'n_frames',1)!=1:raise ValueError('MASK_FORMAT_INVALID')
        if im.mode in {'LA','RGBA'} and im.getchannel('A').getextrema()!=(255,255):raise ValueError('TRANSPARENT_MASK_UNSUPPORTED')
        if im.info.get('icc_profile') or im.getexif().get(274,1)!=1:raise ValueError('MASK_NORMALIZATION_REQUIRED')
        rgb=np.asarray(im.convert('RGB'))
        if not np.array_equal(rgb[...,0],rgb[...,1]) or not np.array_equal(rgb[...,0],rgb[...,2]):raise ValueError('COLORED_MASK_UNSUPPORTED')
        return rgb[...,0].copy()


def run(request,allowed_root):
    qa.validate_runtime()
    required={'schemaVersion','operation','privateRoot','source','guidanceSource','candidate','sourcePreparation',
        'lawnMasks','exclusionMasks','assessment','outputDirectory'}
    if not isinstance(request,dict) or set(request)!=required or request['schemaVersion']!=1 or request['operation']!='native-grass-chroma':raise ValueError('REQUEST_INVALID')
    trusted=Path(allowed_root)
    if not trusted.is_absolute() or any(x.is_symlink() or (hasattr(x,'is_junction') and x.is_junction()) for x in [trusted,*trusted.parents]):raise ValueError('TRUSTED_ROOT_INVALID')
    root=trusted.resolve(strict=True)
    if not root.is_dir() or request['privateRoot']!=str(root):raise ValueError('PRIVATE_ROOT_NOT_TRUSTED')
    inputs={}
    def checked(desc):
        raw=Path(desc['path'])
        if not raw.is_absolute() or any(x.is_symlink() or (hasattr(x,'is_junction') and x.is_junction()) for x in [raw,*raw.parents]):raise ValueError('LINK_OR_RELATIVE_PATH_REJECTED')
        path=qa.checked(desc,root);inputs[str(path)]=desc['sha256'];return path
    source_path=checked(request['source']);guide_path=checked(request['guidanceSource']);candidate_path=checked(request['candidate'])
    source=qa.read_image(source_path,panorama=True);guide=qa.read_image(guide_path,expected=(2048,1024));candidate=qa.read_image(candidate_path,expected=(2048,1024))
    if source.shape[1]<2048:raise ValueError('NATIVE_OR_PREVIEW_SOURCE_TOO_SMALL')
    preparation=request['sourcePreparation']
    if not isinstance(preparation,dict) or set(preparation)!={'nativeSourceSha256','guidanceSourceSha256','preparerSha256','method','verifiedByCaller'}:raise ValueError('PREPARATION_INVALID')
    if preparation['nativeSourceSha256']!=request['source']['sha256'] or preparation['guidanceSourceSha256']!=request['guidanceSource']['sha256'] or preparation['method']!='LabImages::prepared/GD' or preparation['verifiedByCaller'] is not True:raise ValueError('PREPARATION_BINDING_INVALID')
    if not isinstance(preparation['preparerSha256'],str) or len(preparation['preparerSha256'])!=64 or any(x not in '0123456789abcdef' for x in preparation['preparerSha256']):raise ValueError('PREPARER_HASH_INVALID')
    assessment_path=checked(request['assessment']);assessment=_json(assessment_path)
    for key,role in [('nativeSourceSha256','source'),('guidanceSourceSha256','guidanceSource'),('candidateSha256','candidate')]:
        if assessment.get(key)!=request[role]['sha256']:raise ValueError('ASSESSMENT_SOURCE_BINDING_INVALID')
    if assessment.get('sourcePreparationSha256')!=hashlib.sha256(qa.canonical(preparation)).hexdigest():raise ValueError('ASSESSMENT_PREPARATION_BINDING_INVALID')
    provenance=[]
    def mask(record,role):
        if not isinstance(record,dict) or set(record)!={'image','evidence'}:raise ValueError('MASK_RECORD_INVALID')
        path=checked(record['image']);evidence_path=checked(record['evidence']);evidence=_json(evidence_path)
        if evidence.get('inputSha256')!=request['guidanceSource']['sha256'] or evidence.get('maskSha256')!=record['image']['sha256']:raise ValueError('MASK_NOT_BOUND_TO_ORIGINAL_SOURCE')
        provenance.append({'role':role,'maskSha256':record['image']['sha256'],'evidenceSha256':record['evidence']['sha256'],
            'generationOrigin':evidence.get('generationOrigin'),'selectionOrigin':evidence.get('selectionOrigin')})
        return _mask_image(path)
    if not isinstance(request['lawnMasks'],list) or not 1<=len(request['lawnMasks'])<=8:raise ValueError('LAWN_MASK_COUNT_INVALID')
    if not isinstance(request['exclusionMasks'],dict) or set(request['exclusionMasks'])!=set(core.CLASSES):raise ValueError('EXCLUSION_CLASSES_INVALID')
    lawn=[mask(x,'lawn') for x in request['lawnMasks']];excluded={}
    for name in core.CLASSES:
        items=request['exclusionMasks'][name]
        if not isinstance(items,list) or len(items)>8:raise ValueError('EXCLUSION_MASK_COUNT_LIMIT')
        excluded[name]=[mask(x,name) for x in items]
    # Provenance can only become more conservative; never relabel human masks.
    if any(x['generationOrigin']!='model_segmentation' or x['selectionOrigin']!='machine' for x in provenance):assessment['maskSelectionOrigin']='human_or_unknown'
    identity={'adapter':VERSION,'adapterSha256':qa.g.sha256(__file__),'coreSha256':qa.g.sha256(core.__file__),'geometryRuntime':qa.runtime()}
    fingerprint=hashlib.sha256(qa.canonical({'request':request,'identity':identity})).hexdigest()
    raw_out=Path(request['outputDirectory'])
    if not raw_out.is_absolute() or any(x.is_symlink() or (hasattr(x,'is_junction') and x.is_junction()) for x in [raw_out,*raw_out.parents]):raise ValueError('OUTPUT_PATH_INVALID')
    out=raw_out.resolve();qa.require(out!=root and out.is_relative_to(root),'OUTPUT_OUTSIDE_PRIVATE_ROOT')
    if any(Path(path).is_relative_to(out) for path in inputs):raise ValueError('OUTPUT_CONTAINS_INPUT')
    if out.exists():
        if not out.is_dir() or (out/'report.json').is_symlink():raise ValueError('CACHE_PATH_INVALID')
        report=_json(out/'report.json')
        if report.get('requestFingerprint')!=fingerprint or report.get('identity')!=identity or report.get('inputAssets')!=inputs:raise ValueError('CACHE_BINDING_CHANGED')
        if report.get('status') not in {'needs_review','completed_unreviewed'} or report.get('automaticAcceptance') is not False or report.get('providerCalls')!=0 or report.get('reviewStatus')!='unreviewed' or report.get('freshAutonomousSuccess') is not False or report.get('recipe')!=core.VERSION:raise ValueError('CACHE_STATUS_INVALID')
        if report.get('sourceSha256')!=request['source']['sha256'] or report.get('guidanceSourceSha256')!=request['guidanceSource']['sha256'] or report.get('candidateGuidanceSha256')!=request['candidate']['sha256'] or report.get('sourcePreparation')!=preparation or report.get('assessmentSha256')!=request['assessment']['sha256'] or report.get('maskProvenance')!=provenance:raise ValueError('CACHE_SOURCE_BINDING_CHANGED')
        expected={'native','preview','effectiveAlpha'} if report['status']=='completed_unreviewed' else set()
        if set(report.get('artifacts',{}))!=expected:raise ValueError('CACHE_ASSETS_INVALID')
        for key,desc in report['artifacts'].items():
            if desc.get('file')!=key+'.png' or desc.get('path')!=str(out/(key+'.png')):raise ValueError('CACHE_FILENAME_INVALID')
            raw=out/desc['file']
            if raw.is_symlink() or (hasattr(raw,'is_junction') and raw.is_junction()):raise ValueError('CACHE_ASSET_LINK_INVALID')
            path=qa.checked({'path':str(out/desc['file']),'sha256':desc['sha256']},root)
            dims=(2048,1024) if key=='preview' else (source.shape[1],source.shape[0])
            if (desc.get('width'),desc.get('height'))!=dims:raise ValueError('CACHE_ASSET_DIMENSIONS_INVALID')
            qa.read_image(path,grayscale=key=='effectiveAlpha',expected=dims)
        if report['status']=='completed_unreviewed' and report.get('checkerRequest')!=_checker_request(root,request,out,report['artifacts']):raise ValueError('CACHE_CHECKER_BINDING_CHANGED')
        if any(qa.g.sha256(path)!=expected for path,expected in inputs.items()):raise ValueError('INPUT_CHANGED_DURING_REPLAY')
        return report
    result=core.compose(source,guide,candidate,lawn,excluded,assessment);report=result['report']
    if any(qa.g.sha256(path)!=expected for path,expected in inputs.items()):raise ValueError('INPUT_CHANGED_DURING_PROCESSING')
    out.parent.mkdir(parents=True,exist_ok=True);temp=Path(tempfile.mkdtemp(prefix='.grass-',dir=out.parent));os.chmod(temp,0o2770)
    artifacts={}
    if 'native' in result:
        image=Image.fromarray(result['native']);preview=np.asarray(image.resize((2048,1024),Image.Resampling.LANCZOS))
        for key,array in [('native',result['native']),('preview',preview),('effectiveAlpha',result['effectiveAlpha'])]:
            path=temp/(key+'.png');Image.fromarray(array).save(path,compress_level=3);os.chmod(path,0o660)
            artifacts[key]={'file':path.name,'path':str(out/path.name),'sha256':qa.g.sha256(path),'width':array.shape[1],'height':array.shape[0]}
        report['checkerRequest']=_checker_request(root,request,out,artifacts)
    report.update({'identity':identity,'requestFingerprint':fingerprint,'sourceSha256':request['source']['sha256'],
        'guidanceSourceSha256':request['guidanceSource']['sha256'],'candidateGuidanceSha256':request['candidate']['sha256'],
        'sourcePreparation':preparation,'assessmentSha256':request['assessment']['sha256'],'maskProvenance':provenance,
        'inputAssets':inputs,'artifacts':artifacts,'reviewStatus':'unreviewed','freshAutonomousSuccess':False})
    path=temp/'report.json';path.write_text(json.dumps(report,indent=2,allow_nan=False));os.chmod(path,0o660);temp.rename(out)
    return report


def _checker_request(root,request,out,artifacts):
    return {'schemaVersion':1,'operation':'build-evidence','privateRoot':str(root),'source':request['source'],
        'candidate':{'path':str(out/'native.png'),'sha256':artifacts['native']['sha256']},
        'effectiveAlpha':{'path':str(out/'effectiveAlpha.png'),'sha256':artifacts['effectiveAlpha']['sha256']},
        'preset':'grass','editScope':'masked','outputDirectory':str(out/'independent-evidence')}


def main():
    if os.name=='posix':
        import resource
        resource.setrlimit(resource.RLIMIT_AS,(3*1024**3,3*1024**3));resource.setrlimit(resource.RLIMIT_CPU,(220,220));os.umask(0o007)
    parser=argparse.ArgumentParser();parser.add_argument('--request',required=True);parser.add_argument('--allowed-root',required=True);args=parser.parse_args()
    request_path=Path(args.request);qa.require(request_path.stat().st_size<=65536,'REQUEST_TOO_LARGE')
    print(json.dumps(run(strict_json(request_path.read_text(encoding='utf-8-sig')),args.allowed_root),separators=(',',':')))


if __name__=='__main__':main()
