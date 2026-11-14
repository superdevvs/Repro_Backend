"""Versioned D2 source-native illumination transfer; no scene coordinates/network.

Frozen prototype math. Native spatial detail remains source-owned. Mutual bright
luminance/channel confirmation is a heuristic, not semantic lamp segmentation.
Numerical invariants do not replace the independent seven-view quality gate.
"""
import math
import numpy as np
import lighting_transfer as baseline

RECIPE = 'source-detail-linear-dimming-mutual-highlights/2'
PARAMETERS = {**baseline.PARAMETERS,
    'negativeField': 'bounded log donor/source linear-light ratio',
    'positiveField': 'bounded source/donor log-odds delta',
    'negativeApplication': 'linear source times exp(field)',
    'positiveApplication': 'source log-odds shift',
    'mutualHighlightLowLinear': .65, 'mutualHighlightHighLinear': .90,
    'mutualLuminanceWeights': [.2126,.7152,.0722],
    'donorConfirmationBlurPixels': 1.0,
    'confirmationPoleCollapseDegrees': 4.0,
    'highlightProtection': 'source and donor per-channel AND luminance; negative fields only',
    'semanticLuminaireDetection': False}

def high(x):
    t=np.clip((x-.65)/.25,0,1)
    return t*t*(3-2*t)

def curve(linear,field):
    gain=np.exp(field)
    return np.where(field<0,linear*gain,linear*gain/(1+linear*(gain-1)))

def derive_field(source,donor,to_linear):
    if source.shape!=donor.shape or source.ndim!=3 or source.shape[2]!=3 or source.dtype!=np.uint8 or donor.dtype!=np.uint8:
        raise ValueError('GUIDANCE_SHAPE_OR_TYPE')
    h,w=source.shape[:2]
    if w!=2*h or w>2048 or h<64: raise ValueError('GUIDANCE_LIMIT')
    s=to_linear(source).astype(np.float32);r=to_linear(donor).astype(np.float32);eps=.002
    def logit(x):
        x=np.clip(x,eps,1-eps);return np.log(x/(1-x))
    negative=r<s
    delta=np.clip(np.where(negative,np.log(np.maximum(r,eps)/np.maximum(s,eps)),logit(r)-logit(s)),-2.4,2.4)
    dark=np.clip(np.minimum(s.min(axis=2)/.025,1),.03,1)
    old=np.clip(dark*np.minimum((1-s.max(axis=2))/.08,1),.03,1)
    field=np.empty_like(delta);sigma=24*w/2048
    for channel in range(3):
        weight=np.where(negative[:,:,channel],dark,old)
        field[:,:,channel]=baseline.sphere_blur(delta[:,:,channel]*weight,sigma)/np.maximum(baseline.sphere_blur(weight,sigma),.03)
    ring=max(1,math.ceil(h/180));top=field[:ring].mean(axis=(0,1));bottom=field[-ring:].mean(axis=(0,1))
    distance=np.arange(h,dtype=np.float32)*180/h
    factor=np.clip(distance/4,0,1);field=field*factor[:,None,None]+top[None,None,:]*(1-factor[:,None,None])
    factor=np.clip(distance[::-1]/4,0,1);field=field*factor[:,None,None]+bottom[None,None,:]*(1-factor[:,None,None])
    if not np.isfinite(field).all() or np.abs(field).max()>2.400001: raise ValueError('FIELD_BOUNDS')
    return field.astype(np.float32)

def luminance(x):return x[:,:,0]*.2126+x[:,:,1]*.7152+x[:,:,2]*.0722

def confirmation(donor,to_linear):
    linear=to_linear(donor).astype(np.float32)
    raw=high(linear)*high(luminance(linear))[:,:,None]
    field=np.stack([baseline.sphere_blur(raw[:,:,c],1.) for c in range(3)],axis=2).clip(0,1).astype(np.float32)
    h,w=field.shape[:2];ring=max(1,math.ceil(h/180));top=field[:ring].mean(axis=(0,1));bottom=field[-ring:].mean(axis=(0,1))
    distance=np.arange(h,dtype=np.float32)*180/h
    factor=np.clip(distance/4,0,1);field=field*factor[:,None,None]+top[None,None,:]*(1-factor[:,None,None])
    factor=np.clip(distance[::-1]/4,0,1);field=field*factor[:,None,None]+bottom[None,None,:]*(1-factor[:,None,None])
    return field.astype(np.float32)

def guarded_curve(linear,field,donor_confirmation):
    guard=high(linear)*high(luminance(linear))[:,:,None]*donor_confirmation
    return curve(linear,np.where(field<0,field*(1-guard),field))

def apply_field(source,field,donor_confirmation,geometry,strip=96):
    h,w=source.shape[:2];fh,fw=field.shape[:2]
    if source.dtype!=np.uint8 or source.shape[2]!=3 or w!=2*h or w*h>64_000_000:raise ValueError('NATIVE_SOURCE_LIMIT')
    if field.dtype!=np.float32 or field.shape!=(fh,fw,3) or fw!=2*fh or fw>2048 or not np.isfinite(field).all():raise ValueError('FIELD_INVALID')
    if donor_confirmation.dtype!=np.float32 or donor_confirmation.shape!=field.shape or not np.isfinite(donor_confirmation).all() or donor_confirmation.min()<0 or donor_confirmation.max()>1:raise ValueError('CONFIRMATION_INVALID')
    result=np.empty_like(source);protected=protected_changed=changed=nonfinite=clipped=0;lo=float('inf');hi=float('-inf')
    for y0 in range(0,h,strip):
        y1=min(h,y0+strip)
        u,v=np.meshgrid((np.arange(w,dtype=np.float32)+.5)*fw/w-.5,(np.arange(y0,y1,dtype=np.float32)+.5)*fh/h-.5)
        local=geometry.bilinear(field,u,v,wrap_x=True);confirm=geometry.bilinear(donor_confirmation,u,v,wrap_x=True)
        elevation=90-(np.arange(y0,y1,dtype=np.float32)+.5)*180/h
        strength=np.clip((elevation+80)/10,0,1);strength=strength*strength*(3-2*strength);local*=strength[:,None,None]
        original=source[y0:y1];linear=geometry.to_linear(original).astype(np.float32);relit=guarded_curve(linear,local,confirm)
        nonfinite+=int(np.count_nonzero(~np.isfinite(relit)));lo=min(lo,float(relit.min()));hi=max(hi,float(relit.max()))
        clipped+=int(np.any((relit < -1e-6)|(relit > 1+1e-6),axis=-1).sum())
        if nonfinite or clipped:raise ValueError('RELIT_BOUNDS')
        part=np.rint(geometry.from_linear(relit)).clip(0,255).astype(np.uint8)
        fixed=np.broadcast_to(strength[:,None]==0,(y1-y0,w));part[fixed]=original[fixed]
        protected+=int(fixed.sum());protected_changed+=int(np.any(part[fixed]!=original[fixed],axis=-1).sum())
        changed+=int(np.any(part!=original,axis=-1).sum());result[y0:y1]=part
    return result,{'policyProtectedNadirPixels':protected,'policyProtectedNadirChangedPixels':protected_changed,'changedNativePixels':changed,
        'nonfiniteChannels':nonfinite,'outOfRangeLinearPixelsBeyondRoundoff':clipped,'preclipLinearMinMax':[lo,hi],
        'nativeCanvasDimensions':[w,h],'lightingGuidanceDimensions':[fw,fh],'generatedSpatialDetailCopied':False,'nativeSourceSpatiallyWarped':False,
        'wholeImageSourcePreservation':'not_applicable: global illumination intentionally changes RGB','linearRoundoffTolerance':1e-6}
