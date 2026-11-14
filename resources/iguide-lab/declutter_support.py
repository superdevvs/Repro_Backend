"""Machine-source removal support, never a human-reviewed mask or acceptance."""
from __future__ import annotations
import argparse,hashlib,json,os,platform,shutil,tempfile
from pathlib import Path
for name in ['OMP_NUM_THREADS','OPENBLAS_NUM_THREADS','MKL_NUM_THREADS','NUMEXPR_NUM_THREADS']:os.environ[name]='1'
import numpy as np
import scipy
from scipy import ndimage
from PIL import Image,__version__ as PILLOW_VERSION
import declutter_removal_protocol as protocol
from declutter_removal_bridge import safe_path,canonical,decode_json,sha

VERSION='automatic-declutter-support/1'
LIMITS={'maxEditablePixels':131072,'minimumObjectPixels':64,'guardPixels':205,'dilationPixels':4,'featherPixels':2}
def runtime():return {'python':platform.python_version(),'numpy':np.__version__,'pillow':PILLOW_VERSION,'scipy':scipy.__version__,
    'workerSha256':sha(__file__),'protocolSha256':sha(protocol.__file__),'sharedProtocolSha256':sha(protocol.shared.__file__)}

def image(path,mask=False):
    with Image.open(path) as im:
        if im.format!='PNG' or im.size!=(2048,2048) or getattr(im,'n_frames',1)!=1 or im.info.get('icc_profile') or im.getexif().get(274,1)!=1:raise ValueError('IMAGE_UNSUPPORTED')
        im.load()
        if im.mode in {'RGBA','LA'} and im.getchannel('A').getextrema()!=(255,255):raise ValueError('TRANSPARENCY_UNSUPPORTED')
        if mask:
            if im.mode in {'RGB','RGBA'}:
                a=np.asarray(im.convert('RGB'))
                if not np.array_equal(a[:,:,0],a[:,:,1]) or not np.array_equal(a[:,:,0],a[:,:,2]):raise ValueError('COLORED_MASK')
                a=a[:,:,0].copy()
            elif im.mode in {'L','LA'}:a=np.asarray(im.getchannel('L')).copy()
            else:raise ValueError('MASK_MODE_UNSUPPORTED')
            if not np.all((a==0)|(a==255)):raise ValueError('SOFT_PROVIDER_MASK_UNSUPPORTED')
            return a>0
        if im.mode not in {'RGB','RGBA'}:raise ValueError('SOURCE_MODE_UNSUPPORTED')
        return np.asarray(im.convert('RGB')).copy()

def derive(masks,proposal):
    reasons=[];obj=masks['target_object'];count=int(obj.sum())
    if count<LIMITS['minimumObjectPixels']:reasons.append('OBJECT_MASK_EMPTY_OR_TOO_SMALL')
    if ndimage.label(obj,np.ones((3,3),bool))[1]!=1:reasons.append('OBJECT_MASK_DISCONNECTED')
    if np.any(ndimage.binary_fill_holes(obj)&~obj):reasons.append('OBJECT_MASK_HAS_UNRESOLVED_HOLES')
    union=obj.copy()
    if 'target_local_shadow' in masks:
        shadow=masks['target_local_shadow']
        if not shadow.any() or ndimage.label(shadow,np.ones((3,3),bool))[1]!=1:reasons.append('SHADOW_MASK_EMPTY_OR_DISCONNECTED')
        if not np.any(shadow&ndimage.binary_dilation(obj,iterations=12)):reasons.append('SHADOW_NOT_LOCAL_TO_OBJECT')
        union|=shadow
    expanded=ndimage.binary_dilation(union,iterations=LIMITS['dilationPixels'])
    if int(expanded.sum())>LIMITS['maxEditablePixels']:reasons.append('EDIT_DOMAIN_TOO_LARGE')
    guard=LIMITS['guardPixels']
    if any(np.any(v) for v in [expanded[:guard],expanded[-guard:],expanded[:,:guard],expanded[:,-guard:]]):reasons.append('SUPPORT_TOUCHES_GUARD')
    for feature in proposal['protectedFeatures']:
        b=feature['box']
        if b is None:reasons.append('PROTECTED_FEATURE_UNLOCATED');continue
        x1,y1=int(b['xMin']*2048),int(b['yMin']*2048);x2,y2=int(np.ceil(b['xMax']*2048)),int(np.ceil(b['yMax']*2048))
        if np.any(expanded[y1:y2,x1:x2]):reasons.append('PROTECTED_FEATURE_OVERLAP')
    alpha=np.rint(np.minimum(ndimage.distance_transform_edt(expanded)/LIMITS['featherPixels'],1)*255).astype(np.uint8)
    if np.any(alpha[union]!=255):reasons.append('ORIGINAL_TARGET_NOT_FULLY_REPLACED')
    return alpha,sorted(set(reasons)),{'objectPixels':count,'shadowPixels':int(masks.get('target_local_shadow',np.zeros_like(obj)).sum()),
        'rawUnionPixels':int(union.sum()),'positiveSupportPixels':int((alpha>0).sum()),'opaqueOriginalTargetPixels':int((alpha[union]==255).sum())}

def run(request):
    expected={'schemaVersion','privateRoot','source','assessment','plans','receipts','masks','outputDirectory'}
    if set(request)!=expected or request['schemaVersion']!=1:raise ValueError('REQUEST_FIELDS_INVALID')
    identity=runtime()
    if [identity[k] for k in ['python','numpy','pillow','scipy']]!=['3.12.3','2.5.4','13.0.0','1.18.1']:raise ValueError('RUNTIME_UNVALIDATED')
    root=Path(request['privateRoot']).resolve(strict=True);inputs={}
    def asset(spec,limit=1024*1024):
        if set(spec)!={'path','sha256'}:raise ValueError('ASSET_FIELDS_INVALID')
        p=safe_path(spec['path'],root)
        if not p.is_file() or not 0<p.stat().st_size<=limit or sha(p)!=spec['sha256']:raise ValueError('INPUT_CHANGED')
        inputs[str(p)]=spec['sha256'];return p
    source=asset(request['source'],30*1024**2);image(source)
    assessment=decode_json(asset(request['assessment']).read_bytes());plans=decode_json(asset(request['plans']).read_bytes())
    if not isinstance(plans,list) or len(plans) not in [1,2]:raise ValueError('PLAN_COUNT_INVALID')
    reserved={p['stepKey']:p['requestId'] for p in plans}
    if protocol.bind_reserved_plans(assessment,reserved)!=plans or assessment['binding']['sourceCutSha256']!=request['source']['sha256']:raise ValueError('PLAN_SOURCE_BINDING_CHANGED')
    if len(request['receipts'])!=len(plans) or len(request['masks'])!=len(plans):raise ValueError('ROLE_COUNT_CHANGED')
    masks={};associations=[]
    for plan,receipt_asset,spec in zip(plans,request['receipts'],request['masks']):
        receipt=decode_json(asset(receipt_asset).read_bytes());bound=protocol.bind_segmentation_receipt(plan,receipt)
        if bound['status']!='ready_for_support_validation' or len(bound['associations'])!=1:raise ValueError('ASSOCIATION_REQUIRES_REVIEW')
        binding=bound['associations'][0]
        if set(spec)!={'role','image'} or spec['role']!=plan['stepKey'] or spec['image']['sha256']!=binding['maskSha256']:raise ValueError('MASK_ASSOCIATION_CHANGED')
        path=asset(spec['image'],16*1024**2);mask=image(path,True)
        yy,xx=np.where(mask);bounds=None if not len(xx) else [int(xx.min()),int(yy.min()),int(xx.max())+1,int(yy.max())+1]
        descriptor=receipt['masks'][binding['maskIndex']]
        if descriptor['positivePixels']!=int(mask.sum()) or descriptor['bounds']!=bounds:raise ValueError('DECODED_MASK_CHANGED')
        masks[spec['role']]=mask;associations.append(bound)
    out=safe_path(request['outputDirectory'],root,False)
    if any(out==Path(p) or out in Path(p).parents for p in inputs):raise ValueError('OUTPUT_INPUT_COLLISION')
    fingerprint=hashlib.sha256(canonical(request)).hexdigest()
    def verify(report):
        if report.get('requestSha256')!=fingerprint or report.get('runtime')!=identity or report.get('inputAssets')!=inputs or report.get('automaticAcceptance') is not False or report.get('providerCalls')!=0:raise ValueError('CACHED_AUTHORITY_CHANGED')
        if report['status']=='ready_for_generation':
            d=report['support'];p=safe_path(d['path'],root)
            if p!=out/'support.png' or sha(p)!=d['sha256']:raise ValueError('CACHED_SUPPORT_CHANGED')
        elif report['status']!='needs_review' or 'support' in report:raise ValueError('CACHED_STATUS_CHANGED')
        for p,h in inputs.items():
            if sha(p)!=h:raise ValueError('INPUT_CHANGED_DURING_REPLAY')
        return report
    if out.exists():return verify(decode_json((out/'report.json').read_bytes()))
    alpha,reasons,stats=derive(masks,assessment['proposal'])
    report={'version':VERSION,'status':'needs_review' if reasons else 'ready_for_generation','reviewReasons':reasons,'limits':LIMITS,'stats':stats,
        'runtime':identity,'requestSha256':fingerprint,'inputAssets':inputs,'sourceCutSha256':request['source']['sha256'],
        'nativeSourceSha256':assessment['binding']['nativeSourceSha256'],'sourceGeometrySha256':assessment['binding']['sourceGeometrySha256'],
        'maskOrigin':'model_segmentation','selectionOrigin':'machine','automaticAcceptance':False,'humanReviewed':False,'providerCalls':0,
        'associations':associations,'limitations':['Machine masks are approximate, not verified semantic outlines.','Hidden receiver detail is a generated estimate.','Only one panorama is evaluated.']}
    out.parent.mkdir(parents=True,exist_ok=True);temp=Path(tempfile.mkdtemp(prefix='.declutter-support-',dir=out.parent));os.chmod(temp,0o2770)
    try:
        if not reasons:
            Image.fromarray(alpha).save(temp/'support.png');report['support']={'path':str(out/'support.png'),'sha256':sha(temp/'support.png'),'width':2048,'height':2048}
        (temp/'report.json').write_bytes(canonical(report))
        for p in temp.iterdir():os.chmod(p,0o660)
        for p,h in inputs.items():
            if sha(p)!=h:raise ValueError('INPUT_CHANGED_DURING_PROCESSING')
        temp.rename(out);return verify(report)
    finally:
        if temp.exists():shutil.rmtree(temp)

def main():
    if os.name=='posix':
        import resource
        resource.setrlimit(resource.RLIMIT_AS,(1536*1024**2,1536*1024**2));resource.setrlimit(resource.RLIMIT_CPU,(55,55));os.umask(0o007)
    parser=argparse.ArgumentParser();parser.add_argument('--request',required=True);args=parser.parse_args();p=Path(args.request)
    if p.stat().st_size>65536:raise ValueError('REQUEST_TOO_LARGE')
    print(json.dumps(run(decode_json(p.read_bytes())),separators=(',',':')))
if __name__=='__main__':main()
