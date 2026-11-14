"""At most two immutable native-source detail projections; never edited imagery."""
from __future__ import annotations
import argparse,hashlib,json,os
from pathlib import Path
import platform,shutil,tempfile
for key in ['OMP_NUM_THREADS','OPENBLAS_NUM_THREADS','MKL_NUM_THREADS','NUMEXPR_NUM_THREADS']:os.environ[key]='1'
from PIL import Image, __version__ as PILLOW_VERSION
import numpy as np
import pano_geometry as geometry

VERSION='staging-original-native-details/1'

# Keep the reviewed path/JSON rules local: importing a preset bridge would also
# execute its unrelated semantic protocol and create hidden runtime dependencies.
def sha(path):
    h=hashlib.sha256()
    with Path(path).open('rb') as stream:
        for block in iter(lambda:stream.read(1024**2),b''):h.update(block)
    return h.hexdigest()
def canonical(value):return json.dumps(value,sort_keys=True,separators=(',',':'),ensure_ascii=True,allow_nan=False).encode()
def safe_path(value,root,must_exist=True):
    if not isinstance(value,str):raise ValueError('PATH_STRING_REQUIRED')
    raw=Path(value)
    if not raw.is_absolute() or any(p.is_symlink() or (hasattr(p,'is_junction') and p.is_junction()) for p in [raw,*raw.parents]):raise ValueError('RELATIVE_OR_LINK_PATH_REJECTED')
    path=raw.resolve(strict=must_exist)
    if path==root or not path.is_relative_to(root):raise ValueError('PATH_OUTSIDE_TRUSTED_ROOT')
    return path
def decode_json(raw):
    if not isinstance(raw,bytes):raise ValueError('JSON_BYTES_REQUIRED')
    def fail():raise ValueError('INVALID_JSON')
    def pairs(items):
        result={}
        for key,value in items:
            if key in result:raise ValueError('DUPLICATE_JSON_KEY')
            result[key]=value
        return result
    try:return json.loads(raw.decode('utf-8'),object_pairs_hook=pairs,parse_constant=lambda _:fail())
    except (UnicodeDecodeError,json.JSONDecodeError,RecursionError) as exc:raise ValueError('INVALID_JSON') from exc

def keys(value,names,code):
    if not isinstance(value,dict) or set(value)!=set(names):raise ValueError(code)
def hash_value(value):
    if not isinstance(value,str) or len(value)!=64 or any(c not in '0123456789abcdef' for c in value):raise ValueError('DETAIL_HASH_INVALID')
def validate_detail(view,index):
    keys(view,['id','pose','evidenceViewIds','reason'],'DETAIL_FIELDS_INVALID')
    if view['id']!='source_detail_'+str(index+1):raise ValueError('DETAIL_ORDER_INVALID')
    keys(view['pose'],['yaw','pitch','hFov'],'DETAIL_POSE_INVALID')
    for key,bounds in {'yaw':(-180,180),'pitch':(-80,80),'hFov':(30,100)}.items():
        value=view['pose'][key]
        if type(value) not in (int,float) or not np.isfinite(value) or not bounds[0]<=value<=bounds[1]:raise ValueError('DETAIL_POSE_INVALID')
    ids=view['evidenceViewIds']
    if not isinstance(ids,list) or not 1<=len(ids)<=6 or len(set(ids))!=len(ids) or any(v not in ['source','parent_source','parent_front','parent_right','parent_back','parent_left'] for v in ids):raise ValueError('DETAIL_EVIDENCE_INVALID')
    if not isinstance(view['reason'],str) or not 1<=len(view['reason'].strip())<=550:raise ValueError('DETAIL_REASON_INVALID')

def runtime():
    return {'version':VERSION,'python':platform.python_version(),'pillow':PILLOW_VERSION,'numpy':np.__version__,
        'workerSha256':sha(__file__),'geometrySha256':sha(geometry.__file__)}

def run(request,allowed_root):
    identity=runtime()
    if identity['python']!='3.12.3' or identity['pillow']!='13.0.0' or identity['numpy']!='2.5.4':raise ValueError('DETAIL_RUNTIME_UNVALIDATED')
    raw_root=Path(allowed_root)
    if not raw_root.is_absolute() or any(p.is_symlink() or (hasattr(p,'is_junction') and p.is_junction()) for p in [raw_root,*raw_root.parents]):
        raise ValueError('DETAIL_ROOT_INVALID')
    root=raw_root.resolve(strict=True)
    keys(request,['schemaVersion','source','requests','outputDirectory'],'DETAIL_REQUEST_FIELDS_INVALID')
    if type(request['schemaVersion']) is not int or request['schemaVersion']!=1:raise ValueError('DETAIL_REQUEST_VERSION_INVALID')
    descriptor=request['source'];keys(descriptor,['path','sha256'],'DETAIL_SOURCE_INVALID');hash_value(descriptor['sha256'])
    source=safe_path(descriptor['path'],root);out=safe_path(request['outputDirectory'],root,False)
    if source==out or source.is_relative_to(out):raise ValueError('DETAIL_OUTPUT_COLLIDES')
    if not source.is_file() or source.stat().st_size>128*1024**2 or sha(source)!=descriptor['sha256']:raise ValueError('DETAIL_SOURCE_BYTES_INVALID')
    with Image.open(source) as image:
        width,height=image.size
        if image.format not in {'PNG','JPEG','WEBP'} or image.mode not in {'RGB','RGBA'} or width!=height*2 or width<2048 or width*height>64_000_000 or getattr(image,'n_frames',1)!=1:
            raise ValueError('DETAIL_SOURCE_FORMAT_INVALID')
        if image.info.get('icc_profile') or image.getexif().get(274,1)!=1:raise ValueError('DETAIL_SOURCE_NORMALIZATION_REQUIRED')
        if image.mode=='RGBA' and image.getchannel('A').getextrema()!=(255,255):raise ValueError('DETAIL_SOURCE_TRANSPARENT')
        image.load()
    requests=request['requests']
    if not isinstance(requests,list) or not 1<=len(requests)<=2:raise ValueError('DETAIL_COUNT_INVALID')
    for index,view in enumerate(requests):validate_detail(view,index)
    if len({hashlib.sha256(canonical(v['pose'])).hexdigest() for v in requests})!=len(requests):raise ValueError('DETAIL_DUPLICATE_POSE')
    fingerprint=hashlib.sha256(canonical({'request':request,'runtime':identity})).hexdigest()
    expected_names=['source_detail_'+str(i+1) for i in range(len(requests))]
    def verify():
        if {path.name for path in out.iterdir()}!={'manifest.json','report.json','views'} or {path.name for path in (out/'views').iterdir()}!={name+'.png' for name in expected_names}:
            raise ValueError('DETAIL_UNEXPECTED_ASSET')
        report_path=safe_path(str(out/'report.json'),root)
        if report_path.stat().st_size>64*1024:raise ValueError('DETAIL_REPORT_TOO_LARGE')
        report=decode_json(report_path.read_bytes())
        if report.get('requestFingerprint')!=fingerprint or report.get('runtime')!=identity or report.get('source')!=descriptor:
            raise ValueError('DETAIL_REPLAY_BINDING_CHANGED')
        if report.get('sameCaptureOnly') is not True or report.get('editScopeExpanded') is not False or report.get('providerCalls')!=0:
            raise ValueError('DETAIL_REPLAY_AUTHORITY_CHANGED')
        manifest_desc=report.get('manifest',{})
        manifest_path=safe_path(manifest_desc.get('path'),root)
        if manifest_path!=out/'manifest.json' or sha(manifest_path)!=manifest_desc.get('sha256'):raise ValueError('DETAIL_MANIFEST_CHANGED')
        manifest=decode_json(manifest_path.read_bytes())
        if manifest['source']['sha256']!=descriptor['sha256'] or Path(manifest['source']['path'])!=source or (manifest['source']['width'],manifest['source']['height'])!=(width,height):
            raise ValueError('DETAIL_NATIVE_BINDING_CHANGED')
        views=report.get('views',[])
        if len(views)!=len(requests) or [v.get('id') for v in views]!=expected_names or len(manifest['views'])!=len(views):raise ValueError('DETAIL_SET_CHANGED')
        for i,view in enumerate(views):
            expected_pose={**requests[i]['pose'],'width':1024,'height':1024}
            path=safe_path(view.get('path'),root)
            if path!=out/'views'/(expected_names[i]+'.png') or sha(path)!=view.get('sha256') or view.get('pose')!=expected_pose:
                raise ValueError('DETAIL_IMAGE_OR_POSE_CHANGED')
            actual=manifest['views'][i]
            if actual['id']!=view['id'] or actual['path']!=str(path) or actual['sha256']!=view['sha256'] or (actual['yaw_degrees'],actual['pitch_degrees'],actual['hfov_degrees'])!=(expected_pose['yaw'],expected_pose['pitch'],expected_pose['hFov']):
                raise ValueError('DETAIL_GEOMETRY_CHANGED')
            with Image.open(path) as im:
                if im.format!='PNG' or im.size!=(1024,1024) or im.mode!='RGB' or getattr(im,'n_frames',1)!=1:raise ValueError('DETAIL_IMAGE_FORMAT_CHANGED')
                im.load()
        if sha(source)!=descriptor['sha256']:raise ValueError('DETAIL_SOURCE_CHANGED')
        return report
    if out.exists():return verify()
    out.parent.mkdir(parents=True,exist_ok=True)
    temporary=Path(tempfile.mkdtemp(prefix='.staging-detail-',dir=out.parent));os.chmod(temporary,0o2770)
    try:
        specs=[{'id':expected_names[i],'yaw_degrees':v['pose']['yaw'],'pitch_degrees':v['pose']['pitch'],'hfov_degrees':v['pose']['hFov'],'width':1024,'height':1024} for i,v in enumerate(requests)]
        manifest=geometry.extract(source,temporary,view_specs=specs,provenance={'purpose':'prospective_staging_optical_context','sameCaptureOnly':True,'editScopeExpanded':False})
        views=[]
        for i,view in enumerate(manifest['views']):
            view['path']=str(out/'views'/(expected_names[i]+'.png'))
            views.append({'id':expected_names[i],'path':view['path'],'sha256':view['sha256'],'pose':{**requests[i]['pose'],'width':1024,'height':1024}})
        (temporary/'manifest.json').write_bytes(canonical(manifest))
        report={'schemaVersion':1,'status':'completed','runtime':identity,'requestFingerprint':fingerprint,'source':descriptor,
            'manifest':{'path':str(out/'manifest.json'),'sha256':sha(temporary/'manifest.json')},'views':views,
            'sameCaptureOnly':True,'editScopeExpanded':False,'providerCalls':0,'independentCameraEvidence':False}
        (temporary/'report.json').write_bytes(canonical(report))
        for directory,_,files in os.walk(temporary):
            os.chmod(directory,0o2770)
            for file in files:os.chmod(Path(directory)/file,0o660)
        if sha(source)!=descriptor['sha256']:raise ValueError('DETAIL_SOURCE_CHANGED_DURING_EXTRACTION')
        os.rename(temporary,out)
        return verify()
    finally:
        if temporary.is_dir() and temporary.parent==out.parent and temporary.name.startswith('.staging-detail-'):shutil.rmtree(temporary)

def main():
    parser=argparse.ArgumentParser();parser.add_argument('--request',required=True);parser.add_argument('--allowed-root',required=True);args=parser.parse_args()
    try:
        import resource
        resource.setrlimit(resource.RLIMIT_AS,(3*1024**3,3*1024**3));resource.setrlimit(resource.RLIMIT_CPU,(120,120))
        path=Path(args.request)
        if path.stat().st_size>64*1024:raise ValueError('DETAIL_REQUEST_LIMIT')
        result=run(decode_json(path.read_bytes()),args.allowed_root);print(json.dumps(result,separators=(',',':')));return 0
    except (OSError,ValueError,KeyError,TypeError,MemoryError):print(json.dumps({'status':'failed','code':'source_detail_preflight_failed'}));return 1

if __name__=='__main__':raise SystemExit(main())
