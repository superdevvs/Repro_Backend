"""Source-native low-frequency lighting transform. No scene coordinates or network.

The generated panorama supplies only a smooth RGB log-odds field. This is an
artistic transfer, not recovered physical illumination. Pixel detail comes from
the immutable source. Numerical stability never implies visual acceptance.
"""
from __future__ import annotations
import math
import numpy as np

RECIPE = 'source-detail-log-odds-lighting/1'
PARAMETERS = {'guidanceDimensions':[2048,1024], 'sigmaGuidancePixels':24.0,
    'logitEpsilon':.002, 'maximumAbsoluteLogOddsDelta':2.4,
    'darkReliabilityLevel':.025, 'highlightReliabilityLevel':.08, 'minimumReliabilityWeight':.03,
    'poleCollapseDegrees':4.0, 'nadirPreserveBelowElevationDegrees':-80.0,
    'nadirFadeBeginsElevationDegrees':-70.0,
    'filter':'Separable Gaussian in equirectangular coordinates, FFT implementation; periodic longitude, four-sigma opposite-meridian latitude padding.',
    'nadirPolicy':'Fixed angular source cap, not a detected capture-artifact mask.'}


def sphere_blur(array, sigma):
    """Bounded 2D Gaussian. Process one channel at a time, never float64 native RGB."""
    if array.ndim != 2 or not np.isfinite(array).all() or not 0 < sigma <= array.shape[0]/8:
        raise ValueError('INVALID_SPHERICAL_FILTER_INPUT')
    h,w=array.shape
    if w != 2*h:
        raise ValueError('FILTER_EXPECTS_2_TO_1')
    n=math.ceil(4*sigma)
    extended=np.concatenate([np.roll(array[:n][::-1],w//2,axis=1),array,
                             np.roll(array[-n:][::-1],w//2,axis=1)],axis=0)
    fx=np.fft.rfftfreq(w)
    fy=np.fft.fftfreq(extended.shape[0])
    kernel=np.exp(-2*(math.pi*sigma)**2*(fy[:,None]**2+fx[None,:]**2))
    result=np.fft.irfft2(np.fft.rfft2(extended)*kernel,s=extended.shape)
    return result[n:n+h].astype(np.float32)


def derive_field(source_guidance, candidate, to_linear):
    if source_guidance.shape != candidate.shape or source_guidance.ndim != 3 or source_guidance.shape[2] != 3:
        raise ValueError('GUIDANCE_SHAPE_MISMATCH')
    if source_guidance.dtype != np.uint8 or candidate.dtype != np.uint8:
        raise ValueError('GUIDANCE_EXPECTS_UINT8')
    h,w=source_guidance.shape[:2]
    if w != 2*h or w > 2048 or h < 64:
        raise ValueError('GUIDANCE_DIMENSION_LIMIT')
    sl=to_linear(source_guidance).astype(np.float32)
    rl=to_linear(candidate).astype(np.float32)
    epsilon=PARAMETERS['logitEpsilon']
    def logit(v):
        c=np.clip(v,epsilon,1-epsilon)
        return np.log(c/(1-c))
    delta=np.clip(logit(rl)-logit(sl),-2.4,2.4)
    weight=np.clip(np.minimum(sl.min(axis=2)/.025,1)*np.minimum((1-sl.max(axis=2))/.08,1),.03,1)
    sigma=24*w/2048
    divisor=np.maximum(sphere_blur(weight,sigma),.03)
    field=np.empty_like(delta)
    for channel in range(3):
        field[:,:,channel]=sphere_blur(delta[:,:,channel]*weight,sigma)/divisor
    # A sphere has one value at either pole; explicitly collapse first/last rows.
    ring=max(1,math.ceil(h*1/180))
    top=field[:ring].mean(axis=(0,1)); bottom=field[-ring:].mean(axis=(0,1))
    angular_distance=np.arange(h,dtype=np.float32)*180/h
    factor=np.clip(angular_distance/4,0,1)
    field=field*factor[:,None,None]+top[None,None,:]*(1-factor[:,None,None])
    factor=np.clip(angular_distance[::-1]/4,0,1)
    field=field*factor[:,None,None]+bottom[None,None,:]*(1-factor[:,None,None])
    if not np.isfinite(field).all():
        raise ValueError('NONFINITE_LIGHTING_FIELD')
    return field.astype(np.float32)


def apply_field(source, field, geometry, strip=96):
    h,w=source.shape[:2]; fh,fw=field.shape[:2]
    if source.dtype != np.uint8 or source.shape[2] != 3 or w != 2*h or w*h > 64_000_000:
        raise ValueError('NATIVE_SOURCE_LIMIT')
    if field.dtype != np.float32 or field.shape != (fh,fw,3) or fw != 2*fh or fw > 2048 or not np.isfinite(field).all():
        raise ValueError('LIGHTING_FIELD_INVALID')
    result=np.empty_like(source)
    protected=protected_changed=changed=nonfinite=clipped=0
    minimum,maximum=float('inf'),float('-inf')
    for y0 in range(0,h,strip):
        y1=min(h,y0+strip)
        u,v=np.meshgrid((np.arange(w,dtype=np.float32)+.5)*fw/w-.5,
                        (np.arange(y0,y1,dtype=np.float32)+.5)*fh/h-.5)
        local=geometry.bilinear(field,u,v,wrap_x=True)
        elevation=90-(np.arange(y0,y1,dtype=np.float32)+.5)*180/h
        strength=np.clip((elevation+80)/10,0,1)
        strength=strength*strength*(3-2*strength)
        local*=strength[:,None,None]
        original=source[y0:y1]
        linear=geometry.to_linear(original).astype(np.float32)
        gain=np.exp(local)
        relit=linear*gain/(1+linear*(gain-1))
        nonfinite+=int(np.count_nonzero(~np.isfinite(relit)))
        if nonfinite:
            raise ValueError('NONFINITE_RELIT_PIXELS')
        minimum=min(minimum,float(relit.min())); maximum=max(maximum,float(relit.max()))
        clipped+=int(np.any((relit < 0) | (relit > 1),axis=-1).sum())
        part=np.rint(geometry.from_linear(relit)).clip(0,255).astype(np.uint8)
        fixed=np.broadcast_to(strength[:,None] == 0,(y1-y0,w))
        part[fixed]=original[fixed]
        protected+=int(fixed.sum())
        protected_changed+=int(np.any(part[fixed] != original[fixed],axis=-1).sum())
        changed+=int(np.any(part != original,axis=-1).sum())
        result[y0:y1]=part
    return result, {'policyProtectedNadirPixels':protected,'policyProtectedNadirChangedPixels':protected_changed,
        'changedNativePixels':changed,'nonfiniteChannels':nonfinite,'outOfRangeLinearPixels':clipped,
        'preclipLinearMinMax':[minimum,maximum],'nativeCanvasDimensions':[w,h],
        'lightingGuidanceDimensions':[fw,fh],'generatedSpatialDetailCopied':False,
        'nativeSourceSpatiallyWarped':False,'nativeRowBlockHeight':strip,
        'wholeImageSourcePreservation':'not_applicable: global illumination intentionally changes source RGB.',
        'policyProtectionMeaning':'Only the fixed nadir cap is byte-preserved; this is not semantic capture-artifact detection.'}
