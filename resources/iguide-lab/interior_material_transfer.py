"""Conservative source-owned material detail transfer. No semantic acceptance.

Source coarse shading is retained. A measured projective correspondence is
required even for visually unchanged silhouettes; identity is never a fallback.
Operational thresholds are engineering holds, not calibrated quality scores.
"""
from __future__ import annotations
import os
for _key in ('OMP_NUM_THREADS', 'OPENBLAS_NUM_THREADS', 'MKL_NUM_THREADS', 'NUMEXPR_NUM_THREADS'):
    os.environ[_key] = '1'
import math
import time
import cv2
import numpy as np
import pano_geometry as geometry

VERSION = 'interior-material-transfer/1'
MAX_NATIVE_PIXELS = 64_000_000
MAX_TARGET_PIXELS = 700_000
MAX_TOTAL_PIXELS = 2_000_000
CHECKS = ('sourceGeometry','completeTargets','opaqueMatte','foreground','offCutOptics','styleNonColour')


class Hold(Exception):
    def __init__(self, code, diagnostics=None):
        super().__init__(code)
        self.code, self.diagnostics = code, diagnostics or {}


def initialize():
    # Before any OpenCV operation, including connected-components statistics.
    cv2.setNumThreads(1)
    cv2.setRNGSeed(0)


def _rgb(a):
    if not isinstance(a, np.ndarray) or a.dtype != np.uint8 or a.ndim != 3 or a.shape[2] != 3:
        raise ValueError('UINT8_RGB_REQUIRED')


def _mask(a, shape):
    if not isinstance(a, np.ndarray) or a.dtype != np.uint8 or a.shape != shape:
        raise ValueError('UINT8_MASK_REQUIRED')


def _holes(binary):
    padded = np.pad(binary.astype(np.uint8), 1)
    exterior = 1 - padded
    cv2.floodFill(exterior, None, (0,0), 2)
    return (exterior[1:-1,1:-1] == 1)


def _topology(mask, protected, label):
    binary = mask >= 128
    n, _, stats, _ = cv2.connectedComponentsWithStats(binary.astype(np.uint8), 8)
    if n != 2 or stats[1,cv2.CC_STAT_AREA] < 128:
        raise Hold(label + '_DISCONNECTED_OR_EMPTY')
    holes = _holes(binary)
    if protected is not None and np.any(holes & (protected < 255)):
        raise Hold(label + '_UNSUPPORTED_HOLE')
    return binary


def _coarse_gray(rgb, mask, sigma):
    gray = cv2.cvtColor(rgb, cv2.COLOR_RGB2GRAY).astype(np.float32)
    blurred = cv2.GaussianBlur(gray, (0,0), sigma)
    values = blurred[mask]
    lo, hi = np.percentile(values, [5,95])
    if hi-lo < 8:
        raise Hold('INSUFFICIENT_COMMON_STRUCTURE')
    return np.rint(np.clip((blurred-lo)/(hi-lo)*220+17,0,255)).astype(np.uint8)


def register(source, candidate, smask, cmask, protected):
    """Fit source->donor homography from coarse forward/back tracked landmarks.

    A deterministic spatial split keeps held-out landmarks out of the fit.
    Material change often destroys correspondence; that produces a hold.
    """
    h,w = smask.shape
    scale = w/2048
    margin = max(3, int(round(12*scale)))
    sd = cv2.distanceTransform(smask.astype(np.uint8),cv2.DIST_L2,5)
    cd = cv2.distanceTransform(cmask.astype(np.uint8),cv2.DIST_L2,5)
    eligible = (sd > margin) & (protected == 0)
    gray_s = _coarse_gray(source, eligible, max(.8,3*scale))
    gray_c = _coarse_gray(candidate, cd > margin, max(.8,3*scale))
    pts = cv2.goodFeaturesToTrack(gray_s, maxCorners=240, qualityLevel=.025,
        minDistance=max(5,16*scale), mask=eligible.astype(np.uint8)*255, blockSize=5)
    if pts is None or len(pts) < 24:
        raise Hold('INSUFFICIENT_REGISTRATION_LANDMARKS')
    sy,sx=np.nonzero(smask);cy,cx=np.nonzero(cmask)
    initial=pts.copy();initial[...,0]+=float(cx.mean()-sx.mean());initial[...,1]+=float(cy.mean()-sy.mean())
    window=max(25,int(round(61*scale))|1)
    opts=dict(winSize=(window,window),maxLevel=3,criteria=(cv2.TERM_CRITERIA_EPS|cv2.TERM_CRITERIA_COUNT,40,.005))
    forward,ok,_=cv2.calcOpticalFlowPyrLK(gray_s,gray_c,pts,initial,flags=cv2.OPTFLOW_USE_INITIAL_FLOW,**opts)
    back,okback,_=cv2.calcOpticalFlowPyrLK(gray_c,gray_s,forward,pts.copy(),flags=cv2.OPTFLOW_USE_INITIAL_FLOW,**opts)
    p=pts[:,0];q=forward[:,0]
    fb=np.linalg.norm(back[:,0]-p,axis=1)
    qi=np.rint(q).astype(int);inside=(qi[:,0]>=0)&(qi[:,0]<w)&(qi[:,1]>=0)&(qi[:,1]<h)
    safe=np.clip(qi,[0,0],[w-1,h-1])
    good=(ok[:,0]>0)&(okback[:,0]>0)&np.isfinite(q).all(axis=1)&(fb<=max(.5,1.5*scale))&inside&(cd[safe[:,1],safe[:,0]]>margin)
    p,q=p[good],q[good]
    if len(p)<24:raise Hold('REGISTRATION_FORWARD_BACK_FAILURE',{'tracked':int(len(p))})
    # Interleaved spatial order avoids a random split that might omit one edge.
    order=np.lexsort((p[:,0],np.floor(p[:,1]/max(8,h/8))))
    p,q=p[order],q[order];held=np.arange(len(p))%4==0
    cv2.setRNGSeed(0)
    H,inliers=cv2.findHomography(p[~held],q[~held],cv2.RANSAC,max(.7,2*scale),maxIters=2000,confidence=.995)
    if H is None or not np.isfinite(H).all():raise Hold('REGISTRATION_FIT_FAILED')
    projected=cv2.perspectiveTransform(p[:,None],H)[:,0]
    residual=np.linalg.norm(projected-q,axis=1)
    hold_res=residual[held]
    ratio=float(inliers.mean())
    hull=cv2.contourArea(cv2.convexHull(p)) / max(1, int(smask.sum()))
    grid=set(zip(np.minimum(2,((p[:,0]-sx.min())/max(1,sx.max()-sx.min())*3).astype(int)),
        np.minimum(2,((p[:,1]-sy.min())/max(1,sy.max()-sy.min())*3).astype(int))))
    if ratio<.85 or np.percentile(hold_res,95)>max(.9,3*scale) or hull<.3 or len(grid)<5:
        raise Hold('REGISTRATION_RESIDUAL_OR_COVERAGE',{'heldOutP95':float(np.percentile(hold_res,95)), 'inlierFraction':ratio,'coverage':float(hull),'gridCells':len(grid)})
    corners=np.float32([[sx.min(),sy.min()],[sx.max(),sy.min()],[sx.max(),sy.max()],[sx.min(),sy.max()]])
    warped=cv2.perspectiveTransform(corners[:,None],H)[:,0]
    displacement=np.linalg.norm(warped-corners,axis=1)
    area=cv2.contourArea(warped)/max(1,cv2.contourArea(corners))
    edges=np.linalg.norm(np.roll(warped,-1,axis=0)-warped,axis=1)/np.maximum(1,np.linalg.norm(np.roll(corners,-1,axis=0)-corners,axis=1))
    if displacement.max()>max(8,64*scale) or not .85<=area<=1.18 or edges.min()<.88 or edges.max()>1.12 or not cv2.isContourConvex(warped):
        raise Hold('REGISTRATION_GEOMETRY_LIMIT')
    registered_mask=cv2.warpPerspective(cmask.astype(np.uint8),H,(w,h),flags=cv2.INTER_NEAREST|cv2.WARP_INVERSE_MAP)>0
    visible=protected==0
    union=(smask|registered_mask)&visible
    iou=float(((smask&registered_mask)&visible).sum()/max(1,union.sum()))
    if iou<.96:raise Hold('REGISTERED_SILHOUETTE_MISMATCH',{'iou':iou})
    # A separate structural patch correlation guards random or repeated texture fits.
    aligned_gray=cv2.warpPerspective(gray_c,H,(w,h),flags=cv2.INTER_LINEAR|cv2.WARP_INVERSE_MAP)
    inner=(sd>margin)&registered_mask&visible
    a=gray_s[inner].astype(np.float32);b=aligned_gray[inner].astype(np.float32)
    corr=float(np.corrcoef(a,b)[0,1])
    if not math.isfinite(corr) or corr<.85:raise Hold('COARSE_STRUCTURE_DISAGREES',{'correlation':corr})
    registered=cv2.warpPerspective(candidate,H,(w,h),flags=cv2.INTER_LINEAR|cv2.WARP_INVERSE_MAP)
    return registered,registered_mask,{'sourceToCandidateHomography':H.tolist(),'trackedLandmarks':int(len(p)),
        'trainingLandmarks':int((~held).sum()),'heldOutLandmarks':int(held.sum()),'heldOutP95Pixels':float(np.percentile(hold_res,95)),
        'trainingInlierFraction':ratio,'landmarkHullCoverage':float(hull),'gridCells':len(grid),
        'maxCornerDisplacementPixels':float(displacement.max()),'registeredMaskIoU':iou,'coarseCorrelation':corr}


def _masked_blur(lab, support, sigma):
    weight=cv2.GaussianBlur(support.astype(np.float32),(0,0),sigma)
    numerator=cv2.GaussianBlur(lab*support[...,None],(0,0),sigma)
    return numerator/np.maximum(weight[...,None],1e-6)


def lab_to_rgb_unclipped(lab):
    # Explicit conversion keeps gamut loss observable; cv2 LAB2RGB clamps it.
    fy=(lab[...,0]+16)/116;fx=fy+lab[...,1]/500;fz=fy-lab[...,2]/200
    f=np.stack([fx,fy,fz],axis=-1)
    xyz=np.where(f>6/29,f**3,3*(6/29)**2*(f-4/29))*np.float32([.95047,1,1.08883])
    matrix=np.float32([[3.2404542,-1.5371385,-.4985314],[-.969266,1.8760108,.041556],[.0556434,-.2040259,1.0572252]])
    linear=xyz@matrix.T
    return np.where(linear<=.0031308,12.92*linear,1.055*np.maximum(linear,0)**(1/2.4)-.055)*255


def _clip_stats(values, selected):
    v=values[selected]
    if not len(v):return {'editablePixels':0,'clippedPixels':0,'clippedChannels':0,'clippedPixelFraction':0.,'maxOvershoot':0.}
    invalid=(v<0)|(v>255)
    if not np.isfinite(v).all():raise Hold('NONFINITE_MATERIAL_OUTPUT')
    return {'editablePixels':int(len(v)),'clippedPixels':int(invalid.any(axis=1).sum()),'clippedChannels':int(invalid.sum()),
        'clippedPixelFraction':float(invalid.any(axis=1).mean()),'maxOvershoot':float(max(0,-v.min(),v.max()-255))}


def inspect_source_support(sourceRGB, targets, protectedForegroundL):
    """Source-only pre-spend support check, with no fabricated donor evidence."""
    initialize();start=time.monotonic();_rgb(sourceRGB)
    h,w=sourceRGB.shape[:2]
    if h!=w or not 256<=w<=2048:raise ValueError('MATCHED_SQUARE_RGB_REQUIRED')
    _mask(protectedForegroundL,(h,w))
    report={'status':'needs_review','reasons':[],'diagnostics':{'version':VERSION,'targets':[]},
        'imageGenerationAuthorized':False,'materialTransferAuthorized':False,'manualAcceptance':False}
    try:
        if not isinstance(targets,list) or not 2<=len(targets)<=5:raise Hold('TWO_TO_FIVE_TARGETS_REQUIRED')
        seen=set();total=np.zeros((h,w),bool);scale=w/2048
        for target in targets:
            if not isinstance(target,dict) or set(target)!={'id','sourceMask','registrationSurface'}:raise ValueError('SOURCE_TARGET_SCHEMA')
            tid=target['id']
            if not isinstance(tid,str) or not tid or tid in seen:raise Hold('DUPLICATE_OR_INVALID_TARGET_ID')
            seen.add(tid)
            if target['registrationSurface'] not in {'planar','locally_rigid_drape'}:raise Hold('NONRIGID_SURFACE_UNSUPPORTED')
            mask=target['sourceMask'];_mask(mask,(h,w));binary=_topology(mask,protectedForegroundL,'SOURCE')
            ys,xs=np.nonzero(mask>0);area=int((mask>0).sum());guard=int(math.ceil(w*.1))
            if min(xs.min(),ys.min(),w-1-xs.max(),h-1-ys.max())<guard:raise Hold('TARGET_OUTER_GUARD')
            if area>MAX_TARGET_PIXELS*scale**2:raise Hold('TARGET_AREA_LIMIT')
            if np.any(total&(mask>0)):raise Hold('TARGET_OVERLAP')
            total|=mask>0
            if total.sum()>MAX_TOTAL_PIXELS*scale**2:raise Hold('TOTAL_AREA_LIMIT')
            distance=cv2.distanceTransform((binary&(protectedForegroundL==0)).astype(np.uint8),cv2.DIST_L2,5)
            interior=distance>max(3,12*scale)
            if interior.sum()<area*.65:raise Hold('INSUFFICIENT_UNOCCLUDED_SUPPORT')
            gray=_coarse_gray(sourceRGB,interior,max(.8,3*scale))
            points=cv2.goodFeaturesToTrack(gray,maxCorners=240,qualityLevel=.025,minDistance=max(5,16*scale),mask=interior.astype(np.uint8)*255,blockSize=5)
            if points is None or len(points)<24:raise Hold('INSUFFICIENT_REGISTRATION_LANDMARKS')
            p=points[:,0];coverage=cv2.contourArea(cv2.convexHull(p))/max(1,area)
            cells=set(zip(np.minimum(2,((p[:,0]-xs.min())/max(1,xs.max()-xs.min())*3).astype(int)),
                np.minimum(2,((p[:,1]-ys.min())/max(1,ys.max()-ys.min())*3).astype(int))))
            if coverage<.3 or len(cells)<5:raise Hold('SOURCE_LANDMARK_COVERAGE')
            report['diagnostics']['targets'].append({'id':tid,'sourceSupportPixels':area,'unoccludedInteriorPixels':int(interior.sum()),
                'coarseLandmarks':len(points),'landmarkHullCoverage':float(coverage),'gridCells':len(cells)})
        report['status']='eligible_for_donor_request'
    except Hold as exc:
        report['reasons']=[exc.code];report['diagnostics']['hold']=exc.diagnostics
    report['diagnostics']['elapsedMs']=round((time.monotonic()-start)*1000,3)
    report['diagnostics']['limitations']=['Only original source support and possible structural registration features were checked.',
        'No donor, measured source-to-donor registration, actual style change or native transfer is certified.']
    return report


def derive_transfer(sourceRGB,candidateRGB,targets,protectedForegroundL):
    initialize();start=time.monotonic();_rgb(sourceRGB);_rgb(candidateRGB)
    h,w=sourceRGB.shape[:2]
    if sourceRGB.shape!=candidateRGB.shape or h!=w or not 256<=w<=2048:raise ValueError('MATCHED_SQUARE_RGB_REQUIRED')
    _mask(protectedForegroundL,(h,w))
    delta=np.zeros((h,w,3),np.float32);alpha=np.zeros((h,w),np.uint8);records=[]
    out={'status':'needs_review','reasons':[],'diagnostics':{'version':VERSION,'targets':records},'deltaLab':delta,'effectiveAlpha':alpha}
    try:
        if not isinstance(targets,list) or not 2<=len(targets)<=5:raise Hold('TWO_TO_FIVE_TARGETS_REQUIRED')
        if len({t.get('id') for t in targets})!=len(targets):raise Hold('DUPLICATE_TARGET_ID')
        total=np.zeros((h,w),bool);scale=w/2048
        source_lab=cv2.cvtColor(sourceRGB.astype(np.float32)/255,cv2.COLOR_RGB2LAB)
        for target in targets:
            if set(target)!={'id','sourceMask','candidateMask','registrationSurface'}:raise ValueError('TARGET_SCHEMA')
            if target['registrationSurface'] not in {'planar','locally_rigid_drape'}:raise Hold('NONRIGID_SURFACE_UNSUPPORTED')
            sm,cm=target['sourceMask'],target['candidateMask'];_mask(sm,(h,w));_mask(cm,(h,w))
            binary=_topology(sm,protectedForegroundL,'SOURCE');candidate_binary=_topology(cm,None,'CANDIDATE')
            area=int((sm>0).sum());ys,xs=np.nonzero((sm>0)|(cm>0));guard=int(math.ceil(w*.1))
            if min(xs.min(),ys.min(),w-1-xs.max(),h-1-ys.max())<guard:raise Hold('TARGET_OUTER_GUARD')
            if area>MAX_TARGET_PIXELS*(w/2048)**2:raise Hold('TARGET_AREA_LIMIT')
            if np.any(total&(sm>0)):raise Hold('TARGET_OVERLAP')
            total|=sm>0
            if total.sum()>MAX_TOTAL_PIXELS*(w/2048)**2:raise Hold('TOTAL_AREA_LIMIT')
            donor,valid,registration=register(sourceRGB,candidateRGB,binary,candidate_binary,protectedForegroundL)
            if np.any(_holes(valid)&(protectedForegroundL<255)):
                raise Hold('REGISTERED_CANDIDATE_UNSUPPORTED_HOLE')
            dist=cv2.distanceTransform((binary&valid&(protectedForegroundL==0)).astype(np.uint8),cv2.DIST_L2,5)
            guard_inner=max(1,2*scale);fade=max(2,6*scale)
            strength=np.minimum(sm.astype(np.float32)/255,np.clip((dist-guard_inner)/fade,0,1))
            strength[protectedForegroundL>0]=0
            a=np.floor(strength*255).astype(np.uint8);usable=a==255
            if usable.sum()<area*.65:raise Hold('INSUFFICIENT_UNOCCLUDED_SUPPORT')
            donor_lab=cv2.cvtColor(donor.astype(np.float32)/255,cv2.COLOR_RGB2LAB)
            detail_s=_masked_blur(source_lab, binary, max(.65,1.5*scale))-_masked_blur(source_lab,binary,max(2,12*scale))
            detail_d=_masked_blur(donor_lab, valid, max(.65,1.5*scale))-_masked_blur(donor_lab,valid,max(2,12*scale))
            noncolour=detail_d[...,0]-detail_s[...,0]
            donor_rms=float(np.sqrt(np.mean(detail_d[...,0][usable]**2)))
            difference_rms=float(np.sqrt(np.mean(noncolour[usable]**2)))
            changed_fraction=float((np.abs(noncolour[usable])>.6).mean())
            if donor_rms<.8 or difference_rms<.65 or changed_fraction<.20:
                raise Hold('NON_COLOUR_DETAIL_INSUFFICIENT',{'donorDetailRmsL':donor_rms,'newDetailRmsL':difference_rms,'detailChangedFraction':changed_fraction})
            shift=np.median(donor_lab[usable],axis=0)-np.median(source_lab[usable],axis=0)
            shift=np.clip(shift,[-12,-18,-18],[12,18,18]).astype(np.float32)
            field=np.clip(detail_d-detail_s,[-10,-6,-6],[10,6,6]).astype(np.float32)*np.float32([.75,.65,.65])+shift
            delta[a>0]=field[a>0];alpha=np.maximum(alpha,a)
            records.append({'id':target['id'],'sourceSupportPixels':area,'appliedPixels':int((a>0).sum()),
                'registrationSurface':target['registrationSurface'],'registration':registration,
                'donorDetailRmsL':donor_rms,'newDetailRmsL':difference_rms,'detailChangedFraction':changed_fraction,
                'uniformLabShift':shift.tolist(),'boundaryGuardPixels':guard_inner,'inwardFeatherPixels':fade})
        preview=lab_to_rgb_unclipped(source_lab+delta)
        # The actual alpha blend is checked, rather than the unblended proposal.
        blend=sourceRGB.astype(np.float32)+(preview-sourceRGB)* (alpha.astype(np.float32)/255)[...,None]
        clipping=_clip_stats(blend,alpha>0)
        if clipping['clippedPixelFraction']>.05 or clipping['maxOvershoot']>32:raise Hold('CLIPPING_OPERATIONAL_LIMIT',clipping)
        out['status']='unreviewed';out['effectiveAlpha']=alpha;out['deltaLab']=delta
        out['diagnostics'].update({'clipping':clipping,'sourceCoarseShading':'Source Lab plus bounded uniform shift and registered 1.5-to-12px detail-band difference; no donor low-frequency shading.',
            'foregroundChangedPixels':0,'generatedDetail':[w,h],'nativeGeneratedDetail':False,'crossViewVerified':False,
            'limitations':['Numerical registration is not semantic or optical approval.','Source native fine detail remains and can conflict with added donor pattern.','Boundary/contact pixels remain source; inward transition can retain original material rim.','No new silhouettes, hidden receivers, broad shadows or reflected appearance are produced.']})
    except Hold as exc:
        out['reasons'].append(exc.code);out['diagnostics']['hold']=exc.diagnostics
        out['deltaLab']=np.zeros_like(delta);out['effectiveAlpha']=np.zeros_like(alpha)
    out['diagnostics']['elapsedMs']=round((time.monotonic()-start)*1000,3)
    return out


def apply_section(source,delta,alpha):
    _rgb(source);_mask(alpha,source.shape[:2])
    lab=cv2.cvtColor(source.astype(np.float32)/255,cv2.COLOR_RGB2LAB)
    material=lab_to_rgb_unclipped(lab+delta)
    out=np.rint(np.clip(source+(material-source)*(alpha.astype(np.float32)/255)[...,None],0,255)).astype(np.uint8)
    out[alpha==0]=source[alpha==0]
    return out


def compose_native(nativeRGB,deltaLab,effectiveAlpha,view,blockRows=64):
    initialize();_rgb(nativeRGB)
    h,w=nativeRGB.shape[:2];ch,cw=effectiveAlpha.shape
    if w!=2*h or h*w>MAX_NATIVE_PIXELS:raise ValueError('NATIVE_SPHERE_LIMIT')
    if ch!=cw or deltaLab.shape!=(ch,cw,3) or deltaLab.dtype!=np.float32 or not np.isfinite(deltaLab).all():raise ValueError('FIELD_INVALID')
    _mask(effectiveAlpha,(ch,cw))
    if set(view)!={'yaw','pitch','hFov'} or any(isinstance(x,bool) or not isinstance(x,(int,float)) or not math.isfinite(x) for x in view.values()):raise ValueError('VIEW_INVALID')
    if not -180<=view['yaw']<=180 or not -60<=view['pitch']<=30 or not 30<=view['hFov']<=120:raise ValueError('VIEW_OUT_OF_RANGE')
    if not 1<=blockRows<=64:raise ValueError('ROW_BLOCK_LIMIT')
    out=nativeRGB.copy();full_alpha=np.zeros((h,w),np.uint8)
    totals={'editablePixels':0,'clippedPixels':0,'clippedChannels':0,'maxOvershoot':0.,'outsideAlphaChangedPixels':0}
    v={'yaw_degrees':view['yaw'],'pitch_degrees':view['pitch'],'hfov_degrees':view['hFov'],'width':cw,'height':ch}
    for y0 in range(0,h,blockRows):
        y1=min(y0+blockRows,h);x,y,z=geometry.sphere_rays(w,h,y0,y1);u,vv,weight=geometry.project_view(x,y,z,v)
        a=geometry.bilinear(effectiveAlpha,u,vv)*weight
        qa=np.floor(np.clip(a,0,255)).astype(np.uint8);full_alpha[y0:y1]=qa
        if not np.any(qa):continue
        d=geometry.bilinear(deltaLab,u,vv)
        src=nativeRGB[y0:y1];lab=cv2.cvtColor(src.astype(np.float32)/255,cv2.COLOR_RGB2LAB)
        material=lab_to_rgb_unclipped(lab+d)
        blend=src.astype(np.float32)+(material-src)*(qa.astype(np.float32)/255)[...,None]
        stats=_clip_stats(blend,qa>0)
        for key in ('editablePixels','clippedPixels','clippedChannels'):totals[key]+=stats[key]
        totals['maxOvershoot']=max(totals['maxOvershoot'],stats['maxOvershoot'])
        dst=np.rint(np.clip(blend,0,255)).astype(np.uint8);dst[qa==0]=src[qa==0];out[y0:y1]=dst
        totals['outsideAlphaChangedPixels']+=int(np.any(dst[qa==0]!=src[qa==0],axis=1).sum())
    totals['clippedPixelFraction']=totals['clippedPixels']/max(1,totals['editablePixels'])
    if totals['clippedPixelFraction']>.05 or totals['maxOvershoot']>32:raise Hold('NATIVE_CLIPPING_OPERATIONAL_LIMIT',totals)
    return out,full_alpha,totals
