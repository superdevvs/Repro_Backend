"""Native source chroma only. No generated spatial pixels, network or acceptance."""
from __future__ import annotations
import math
import time
import numpy as np
from PIL import Image,ImageFilter

VERSION='native-source-grass-chroma/1'
CLASSES=['paving','plants','loose_debris','non_turf_ground','capture_artifact']
TURF_MATERIALS={'established_turf','established_dormant_turf','patchy_turf','sparse_or_missing_turf','uncertain'}
PARAMETERS={'guidanceDimensions':[2048,1024],'maximumNativePixels':64000000,'rowBlock':64,
    'lawnErodePixelsAt2048':2,'exclusionDilatePixelsAt2048':2,'featherSigmaPixelsAt2048':2,
    'minimumSamplesAt2K':1024,'maximumChromaDeltaNorm':40.,'gamutIterations':8,
    'nadirFadeStartElevation':-70.,'nadirProtectedBelowElevation':-80.}
M=np.array([[.4124564,.3575761,.1804375],[.2126729,.7151522,.0721750],[.0193339,.1191920,.9503041]],np.float32)
INV=np.linalg.inv(M).astype(np.float32);WHITE=np.array([.95047,1.,1.08883],np.float32)


def to_lab(rgb):
    v=rgb.astype(np.float32)/255
    linear=np.where(v<=.04045,v/12.92,((v+.055)/1.055)**2.4)
    xyz=(linear@M.T)/WHITE
    d=6/29;f=np.where(xyz>d**3,np.cbrt(xyz),xyz/(3*d*d)+4/29)
    return np.stack([116*f[...,1]-16,500*(f[...,0]-f[...,1]),200*(f[...,1]-f[...,2])],-1)


def from_lab(lab):
    y=(lab[...,0]+16)/116;x=y+lab[...,1]/500;z=y-lab[...,2]/200
    f=np.stack([x,y,z],-1);d=6/29
    xyz=np.where(f>d,f**3,3*d*d*(f-4/29))*WHITE
    linear=xyz@INV.T
    return np.where(linear<=.0031308,12.92*linear,1.055*np.maximum(linear,0)**(1/2.4)-.055)


def sphere_pad(mask,pad):
    mid=np.concatenate([np.roll(mask[:pad][::-1],mask.shape[1]//2,axis=1),mask,
        np.roll(mask[-pad:][::-1],mask.shape[1]//2,axis=1)],axis=0)
    return np.pad(mid,((0,0),(pad,pad)),mode='wrap')


def source_support(lawn,exclusions):
    h,w=lawn.shape;scale=w/2048;radius=max(1,round(2*scale));sigma=max(.5,2*scale)
    if lawn.dtype!=np.uint8 or not np.isin(lawn,[0,255]).all():raise ValueError('LAWN_MASK_MUST_BE_BINARY')
    protected=np.zeros_like(lawn)
    for mask in exclusions:
        if mask.dtype!=np.uint8 or mask.shape!=lawn.shape:raise ValueError('EXCLUSION_SHAPE_INVALID')
        protected=np.maximum(protected,np.uint8(mask>0)*255)
    pad=radius+math.ceil(sigma*4)+2
    dilated=np.asarray(Image.fromarray(sphere_pad(protected,pad)).filter(ImageFilter.MaxFilter(radius*2+1)))[pad:-pad,pad:-pad].copy()
    hard=lawn.copy();hard[dilated>0]=0
    im=Image.fromarray(sphere_pad(hard,pad)).filter(ImageFilter.MinFilter(radius*2+1)).filter(ImageFilter.GaussianBlur(sigma))
    alpha=np.asarray(im)[pad:-pad,pad:-pad].copy();alpha[hard==0]=0
    return alpha,hard,dilated


def _sample_rows(value,w,h,y0,y1,nearest=False):
    sh,sw=value.shape;x=(np.arange(w,dtype=np.float32)+.5)*sw/w-.5
    y=(np.arange(y0,y1,dtype=np.float32)+.5)*sh/h-.5
    if nearest:return value[np.clip(np.floor(y+.5).astype(int),0,sh-1)[:,None],np.floor(x+.5).astype(int)[None,:]%sw]
    x0=np.floor(x).astype(int);y0s=np.floor(y).astype(int);wx=x-x0;wy=y-y0s
    a=value[np.clip(y0s,0,sh-1)[:,None],(x0%sw)[None,:]].astype(np.float32)
    b=value[np.clip(y0s,0,sh-1)[:,None],((x0+1)%sw)[None,:]].astype(np.float32)
    c=value[np.clip(y0s+1,0,sh-1)[:,None],(x0%sw)[None,:]].astype(np.float32)
    d=value[np.clip(y0s+1,0,sh-1)[:,None],((x0+1)%sw)[None,:]].astype(np.float32)
    return (a*(1-wx)+b*wx)*(1-wy[:,None])+(c*(1-wx)+d*wx)*wy[:,None]


def semantic_gates(assessment,exclusion_by_class):
    reasons=[]
    if assessment.get('authorOrigin')!='machine' or assessment.get('maskSelectionOrigin')!='machine':reasons.append('HUMAN_OR_UNKNOWN_ASSISTANCE')
    # Existing turf in any condition is recolored honestly (soil stays soil); only bare-soil-only ground stops.
    if assessment.get('lawnMaterial') not in TURF_MATERIALS or assessment.get('requestedChange')!='recolor_existing_turf':reasons.append('MATERIAL_OR_TEXTURE_REPLACEMENT_REQUIRES_REVIEW')
    if assessment.get('scope')!='all_visible_established_turf':reasons.append('PROPERTY_BOUNDARY_SCOPE_UNRESOLVED')
    declarations=assessment.get('exclusions',{})
    for name in CLASSES:
        status=declarations.get(name,'unknown');masks=exclusion_by_class.get(name,[])
        # 'unsegmented' is a recorded caveat from the assessment: that role has no mask and protects nothing.
        if status not in {'supplied','none_observed','unsegmented'} or (status=='supplied' and not masks) or (status!='supplied' and masks):reasons.append('EXCLUSION_EVIDENCE_UNRESOLVED:'+name)
    if assessment.get('uncertainties')!=[]:reasons.append('SOURCE_ASSESSMENT_UNCERTAINTY')
    return reasons


def compose(source,guidance_source,guidance_candidate,lawn_masks,exclusion_by_class,assessment):
    start=time.monotonic()
    if source.dtype!=np.uint8 or source.ndim!=3 or source.shape[2]!=3 or source.shape[1]!=2*source.shape[0] or source.shape[0]*source.shape[1]>64000000:raise ValueError('NATIVE_SOURCE_INVALID')
    gh,gw=guidance_source.shape[:2]
    if gw!=2*gh or guidance_source.dtype!=np.uint8 or guidance_source.shape!=guidance_candidate.shape or guidance_candidate.dtype!=np.uint8 or guidance_source.shape[2]!=3:raise ValueError('GUIDANCE_INVALID')
    if not 0<=len(lawn_masks)<=8 or any(m.shape!=(gh,gw) for m in lawn_masks):raise ValueError('LAWN_MASK_COUNT_OR_SHAPE_INVALID')
    if set(exclusion_by_class)!=set(CLASSES) or any(len(x)>8 for x in exclusion_by_class.values()):raise ValueError('EXCLUSION_CLASSES_INVALID')
    blockers=semantic_gates(assessment,exclusion_by_class)
    # finishReview: the paid image is still composited, but the item must end Needs review (never Ready).
    finish=[]
    lawn=np.maximum.reduce(lawn_masks) if lawn_masks else np.zeros((gh,gw),np.uint8)
    exclusions=[m for masks in exclusion_by_class.values() for m in masks]
    if not np.count_nonzero(lawn):finish.append('LAWN_MASK_UNAVAILABLE')
    band=max(1,round(gh*10/180));upper=int(np.count_nonzero(lawn[:band]))
    if upper:
        # A lawn mask reaching the zenith band is trimmed there (never recolored) instead of discarding the paid image.
        lawn=lawn.copy();lawn[:band]=0;finish.append('LAWN_SUPPORT_UPPER_POLE_TRIMMED')
    alpha,hard,protected=source_support(lawn,exclusions)
    report={'schemaVersion':1,'recipe':VERSION,'status':'needs_review','automaticAcceptance':False,'providerCalls':0,
        'editScope':'masked','parameters':PARAMETERS,'semanticBlockers':blockers,'finishReview':finish,
        'support':{'rawLawnPixels':int(np.count_nonzero(lawn)),'protectedPixelsAtGuidance':int(np.count_nonzero(protected)),
            'positiveFeatherPixels':int(np.count_nonzero(alpha)),'maskWrapDisagreementRows':int(np.count_nonzero((hard[:,0]>0)!=(hard[:,-1]>0))),
            'upperPoleLawnPixels':upper,'holeFillingApplied':False},
        'reviewFlags':['LOW_RESOLUTION_SUPPORT_NATIVE_BOUNDARY_REVIEW','SOURCE_LAWN_COVERAGE_REVIEW','CHROMA_ONLY_NO_NEW_GRASS_TEXTURE',
            'SOURCE_LUMINANCE_PRESERVED_BEFORE_RGB_QUANTIZATION','FIXED_NADIR_CAP_IS_NOT_ARTIFACT_DETECTION'],
        'limitations':['Supplied semantic masks can be wrong; exact protected-pixel proof does not certify actual object boundaries.',
            'Soil and missing blades remain source soil and missing blades. Texture replacement is not this recipe.',
            'A single global chroma shift retains source lighting variation; it does not infer physical lighting or match multiple camera positions.']}
    if blockers:
        report['elapsedMs']=round((time.monotonic()-start)*1000);return {'report':report}
    src_lab=to_lab(guidance_source);donor_lab=to_lab(guidance_candidate)
    valid=(src_lab[...,0]>8)&(src_lab[...,0]<95)&(donor_lab[...,0]>8)&(donor_lab[...,0]<95)
    sample=(alpha>=250)&valid
    count=int(sample.sum());report['samplePixels']=count
    if count<max(64,round(1024*gw*gh/(2048*1024))):
        if np.count_nonzero(lawn):finish.append('INSUFFICIENT_SAFE_CHROMA_SAMPLES')
        # Best available lawn region: ground below the horizon that the paid candidate visibly greened,
        # minus every protected mask. Chroma only, from source texture; still no generated pixels pasted.
        elevation=90-180*(np.arange(gh,dtype=np.float32)+.5)/gh
        greened=(donor_lab[...,1]-src_lab[...,1]<=-4)&valid&(elevation<0)[:,None]
        alpha,hard,protected=source_support(np.uint8(greened)*255,exclusions)
        sample=(alpha>=250)&valid;count=int(sample.sum());finish.append('LAWN_REGION_FROM_CANDIDATE_CHANGE')
        report['samplePixels']=count;report['support'].update(fallbackRegionPixels=int(np.count_nonzero(greened)),
            positiveFeatherPixels=int(np.count_nonzero(alpha)),protectedPixelsAtGuidance=int(np.count_nonzero(protected)))
        if count<64:
            blockers.append('NO_GREENED_GROUND_IN_CANDIDATE');return {'report':report}
    source_median=np.median(src_lab[sample],axis=0);donor_median=np.median(donor_lab[sample],axis=0)
    requested=donor_median[1:]-source_median[1:];delta=requested
    if not np.isfinite(requested).all():
        blockers.append('CHROMA_GUIDANCE_OUTSIDE_OPERATIONAL_BOUND');return {'report':report}
    if np.linalg.norm(requested)>PARAMETERS['maximumChromaDeltaNorm']:
        # Clamp an over-strong colour shift to the operational bound instead of discarding the paid image.
        delta=(requested*(PARAMETERS['maximumChromaDeltaNorm']/np.linalg.norm(requested))).astype(requested.dtype)
        report['reviewFlags'].append('CHROMA_GUIDANCE_CLAMPED')
    report['chromaGuidance']={'sourceMedianLab':source_median.tolist(),'candidateMedianLab':donor_median.tolist(),'deltaAB':requested.tolist(),
        'appliedDeltaAB':delta.tolist(),'spatialDonorPixelsCopied':0,'sourceLuminanceDeltaRequested':0}
    del src_lab,donor_lab
    h,w=source.shape[:2];result=np.empty_like(source);effective=np.zeros((h,w),np.uint8)
    stats={'outsideAlphaChangedPixels':0,'protectedMaskChangedPixels':0,'nadirProtectedChangedPixels':0,'gamutAttenuatedPixels':0,
        'maxEncodedLuminanceDifference':0.,'preEncodeClippedChannels':0,'protectedPixels':0,'spatialDonorPixelsCopied':0}
    noop=bool(np.max(np.abs(delta))<1e-5)
    if noop:report['reviewFlags'].append('NO_OP_CHROMA_GUIDANCE')
    for y0 in range(0,h,64):
        y1=min(h,y0+64);original=source[y0:y1]
        a=_sample_rows(alpha,w,h,y0,y1);allowed=_sample_rows(hard,w,h,y0,y1,True)>0
        excluded=_sample_rows(protected,w,h,y0,y1,True)>0
        elevation=90-180*(np.arange(y0,y1,dtype=np.float32)+.5)/h
        t=np.clip((elevation+80)/10,0,1);cap=t*t*(3-2*t)
        a=np.floor(a*cap[:,None]).astype(np.uint8);a[~allowed|excluded]=0
        if noop:a[:]=0
        lab=to_lab(original);weight=a.astype(np.float32)/255
        target=lab.copy();target[...,1:]+=weight[...,None]*delta
        rgb=from_lab(target);bad=np.any((rgb< -1e-5)|(rgb>1.00001),axis=-1)&(a>0)
        stats['gamutAttenuatedPixels']+=int(bad.sum())
        if np.any(bad):
            low=np.zeros_like(weight);high=np.ones_like(weight)
            for _ in range(8):
                mid=(low+high)*.5;attempt=lab.copy();attempt[...,1:]+=(weight*mid)[...,None]*delta
                rr=from_lab(attempt);valid=np.all((rr>=-1e-5)&(rr<=1.00001),axis=-1)
                low=np.where(valid,mid,low);high=np.where(valid,high,mid)
            a[bad]=np.floor(a[bad]*low[bad]).astype(np.uint8)
            target=lab.copy();target[...,1:]+=(a.astype(np.float32)/255)[...,None]*delta;rgb=from_lab(target)
        stats['preEncodeClippedChannels']+=int(np.count_nonzero(((rgb< -1e-5)|(rgb>1.00001))&(a>0)[...,None]))
        block=np.rint(np.clip(rgb,0,1)*255).astype(np.uint8);block[a==0]=original[a==0]
        stats['maxEncodedLuminanceDifference']=max(stats['maxEncodedLuminanceDifference'],float(np.abs(to_lab(block)[...,0]-lab[...,0]).max(initial=0)))
        stats['protectedPixels']+=int(np.count_nonzero(a==0))
        stats['outsideAlphaChangedPixels']+=int(np.any(block!=original,axis=-1)[a==0].sum())
        stats['protectedMaskChangedPixels']+=int(np.any(block!=original,axis=-1)[excluded].sum())
        stats['nadirProtectedChangedPixels']+=int(np.any(block!=original,axis=-1)[np.broadcast_to(elevation[:,None]<=-80,a.shape)].sum())
        result[y0:y1]=block;effective[y0:y1]=a
    if stats['outsideAlphaChangedPixels'] or stats['protectedMaskChangedPixels'] or stats['nadirProtectedChangedPixels'] or stats['preEncodeClippedChannels']:
        raise ValueError('NATIVE_NUMERIC_INVARIANT_FAILED')
    report.update({'status':'completed_unreviewed','statistics':stats,'nativeDimensions':[w,h],
        'guidanceDimensions':[gw,gh],'nativeGeneratedDetail':False,'elapsedMs':round((time.monotonic()-start)*1000)})
    return {'native':result,'effectiveAlpha':effective,'report':report}
