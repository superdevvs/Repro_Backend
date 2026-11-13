"""Private worker grass compositor. numpy + Pillow only; no network/API calls.

Usage: python composite_grass.py --request /private/request.json
The caller owns paths, permissions and provider/job idempotency. Input hashes
are required. Successful storage is NOT an image-quality acceptance.
"""
from __future__ import annotations
import argparse
import hashlib
import json
import math
import os
from pathlib import Path
import sys
import tempfile

import numpy as np
from PIL import Image, ImageFilter

VERSION='iguide-grass-composite/1.0.0'
Image.MAX_IMAGE_PIXELS=64_000_000

class CompositeError(ValueError): pass

def sha(path):
    with Path(path).open('rb') as stream:
        return hashlib.file_digest(stream,'sha256').hexdigest()

def checked_image(spec,mode):
    path=Path(spec['path']).resolve(strict=True)
    expected=spec.get('sha256','')
    if len(expected)!=64 or sha(path)!=expected: raise CompositeError('INPUT_HASH_MISMATCH')
    if path.stat().st_size>32*1024*1024: raise CompositeError('INPUT_TOO_LARGE')
    with Image.open(path) as im:
        if im.size!=(2048,1024): raise CompositeError('INPUT_DIMENSIONS_MUST_BE_2048x1024')
        if im.format not in ('JPEG','PNG','WEBP'): raise CompositeError('UNSUPPORTED_IMAGE_FORMAT')
        im.load()
        result=np.asarray(im.convert(mode)).copy()
    return result,path

def sphere_pad(value,pad):
    """Periodicity at longitude, opposite meridian after crossing either pole."""
    if pad==0: return value
    vertical=np.concatenate([np.roll(value[:pad][::-1],value.shape[1]//2,axis=1),value,
                             np.roll(value[-pad:][::-1],value.shape[1]//2,axis=1)],axis=0)
    return np.pad(vertical,((0,0),(pad,pad)),mode='wrap')

def feather_support(mask,erode_radius=2,blur_radius=2.0):
    if not 0<=erode_radius<=8 or not 0<=blur_radius<=8: raise CompositeError('INVALID_FEATHER')
    hard=np.where(mask>=128,255,0).astype('uint8')
    pad=max(1,erode_radius+math.ceil(blur_radius*4)+2)
    im=Image.fromarray(sphere_pad(hard,pad))
    if erode_radius: im=im.filter(ImageFilter.MinFilter(2*erode_radius+1))
    if blur_radius: im=im.filter(ImageFilter.GaussianBlur(blur_radius))
    alpha=np.asarray(im)[pad:-pad,pad:-pad].copy()
    alpha[hard==0]=0
    return alpha

def compose_pixels(source,candidate,alpha):
    if source.shape!=candidate.shape or source.shape[:2]!=alpha.shape: raise CompositeError('SHAPE_MISMATCH')
    a=alpha.astype('float32')[:,:,None]/255
    result=np.rint(source.astype('float32')*(1-a)+candidate.astype('float32')*a).clip(0,255).astype('uint8')
    result[alpha==0]=source[alpha==0]
    return result

def write_png_once(path,pixels):
    # Atomic output; never replace an existing artifact with different pixels.
    path=Path(path)
    handle,name=tempfile.mkstemp(prefix='.compose-',suffix='.png',dir=path.parent)
    os.close(handle)
    tmp=Path(name)
    try:
        Image.fromarray(pixels).save(tmp,compress_level=3)
        if path.exists():
            if sha(path)!=sha(tmp): raise CompositeError('OUTPUT_ALREADY_EXISTS_WITH_DIFFERENT_BYTES')
        else:
            os.replace(tmp,path)
    finally:
        if tmp.exists(): tmp.unlink()

def run(request):
    if request.get('schemaVersion')!=1 or request.get('recipe')!='grass-masked-replacement':
        raise CompositeError('UNSUPPORTED_RECIPE')
    if not isinstance(request.get('masks'),list) or not 1<=len(request['masks'])<=8:
        raise CompositeError('REQUIRES_ONE_TO_EIGHT_MASKS')
    source,source_path=checked_image(request['source'],'RGB')
    candidate,candidate_path=checked_image(request['candidate'],'RGB')
    masks=[checked_image(spec,'L') for spec in request['masks']]
    hard=np.maximum.reduce([m for m,_ in masks])
    options=request.get('options',{})
    erode=int(options.get('erodeRadius',2))
    blur=float(options.get('blurRadius',2.0))
    alpha=feather_support(hard,erode,blur)
    if np.count_nonzero(alpha)<64: raise CompositeError('EMPTY_EDIT_SUPPORT')
    result=compose_pixels(source,candidate,alpha)
    outside=alpha==0
    drift=int(np.max(np.abs(result[outside].astype('int16')-source[outside].astype('int16')),initial=0))
    if drift: raise CompositeError('SOURCE_PRESERVATION_FAILED')
    out=Path(request['outputDirectory']).resolve()
    out.mkdir(parents=True,exist_ok=True)
    preview=out/'preview.png'; mask_file=out/'applied-alpha.png'; report_file=out/'report.json'
    inputs={source_path,candidate_path,*[p for _,p in masks]}
    if any(p in inputs for p in [preview,mask_file,report_file]): raise CompositeError('OUTPUT_COLLIDES_WITH_INPUT')
    write_png_once(preview,result)
    write_png_once(mask_file,alpha)
    fingerprint=hashlib.sha256(json.dumps({k:v for k,v in request.items() if k!='outputDirectory'},sort_keys=True,separators=(',',':')).encode()).hexdigest()
    report={'schemaVersion':1,'status':'completed','reviewStatus':'unreviewed','recipe':request['recipe'],
            'recipeVersion':VERSION,'requestFingerprint':fingerprint,'scriptSha256':sha(__file__),
            'sourceSha256':request['source']['sha256'],'candidateSha256':request['candidate']['sha256'],
            'segmentationMaskSha256':[s['sha256'] for s in request['masks']],
            'preview':{'path':str(preview),'sha256':sha(preview),'width':2048,'height':1024,'mime':'image/png'},
            'alpha':{'path':str(mask_file),'sha256':sha(mask_file),'width':2048,'height':1024},
            'statistics':{'outsideAlphaMaxChannelDifference':drift,'outsideAlphaPixels':int(outside.sum()),
                          'changedPixelFraction':float(np.mean(np.any(result!=source,axis=-1))),
                          'maskPixelFraction':float(np.mean(hard>=128)),
                          'wrapMaskDisagreementRows':int(np.count_nonzero((hard[:,0]>=128)!=(hard[:,-1]>=128)))},
            'options':{'erodeRadius':erode,'blurRadius':blur},
            'diagnostics':['Review semantic lawn boundaries, small objects, wrap, poles and generated details before accepting.',
                           'No fixture-specific mask-hole repair has been applied.',
                           'This is a 2048×1024 preview; generated pixels can change grass and leaf texture inside the mask.'],
            'providerCalls':0,'automaticAcceptance':False}
    if report_file.exists():
        previous=json.loads(report_file.read_text(encoding='utf-8'))
        if previous!=report: raise CompositeError('REPORT_ALREADY_EXISTS_WITH_DIFFERENT_CONTENT')
    else:
        temporary=out/'report.json.tmp'
        temporary.write_text(json.dumps(report,indent=2),encoding='utf-8')
        os.replace(temporary,report_file)
    return report

def main():
    parser=argparse.ArgumentParser(); parser.add_argument('--request',required=True)
    args=parser.parse_args()
    try:
        path=Path(args.request)
        if path.stat().st_size>65536: raise CompositeError('REQUEST_TOO_LARGE')
        request=json.loads(path.read_text(encoding='utf-8-sig'))
        report=run(request)
        print(json.dumps(report,separators=(',',':')))
    except (CompositeError,OSError,KeyError,TypeError,ValueError) as exc:
        # Server logs may retain code only. Do not expose file contents/secrets.
        code=str(exc) if isinstance(exc,CompositeError) else type(exc).__name__
        print(json.dumps({'status':'failed','code':code,'providerCalls':0}))
        return 1
    return 0

if __name__=='__main__': sys.exit(main())
