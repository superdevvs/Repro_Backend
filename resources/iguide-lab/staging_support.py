"""Machine support proposal for empty-room staging. No network or acceptance.

Contacts are geometry hypotheses, not detected physical feet. Complex bases,
unknown occlusion, missing floor, and optical dependencies fail closed.
"""
from __future__ import annotations
import hashlib
import json
import math
import os
from pathlib import Path
import time
for _thread_key in ('OMP_NUM_THREADS','OPENBLAS_NUM_THREADS','MKL_NUM_THREADS','NUMEXPR_NUM_THREADS'):
    os.environ[_thread_key]='1'
import cv2
import numpy as np
cv2.setNumThreads(1)
cv2.setRNGSeed(0)

VERSION='single-section-staging-support/1'
PARAMETERS={'boundaryBandAt2048':6,'grabCutIterations':5,'rngSeed':0,'opencvThreads':1,
    'floorInsetAt2048':6,'cropGuardFraction':.10,'maximumObjects':3,
    'contactStrength':.25,'shadowKind':'tiny_local_contact_only','minimumContactHypothesesPerObject':2,
    'maximumContactHypothesesPerObject':4,'maximumFloorShadowFraction':.04,
    'maximumRefinementRoiPixels':3000000,'maximumBoundaryPoints':32768,'maximumContactCandidates':4096}


def runtime():
    return {'opencv':cv2.__version__,'numpy':np.__version__,
            'opencvBuildSha256':hashlib.sha256(cv2.getBuildInformation().encode()).hexdigest(),
            'codeSha256':hashlib.sha256(Path(__file__).read_bytes()).hexdigest()}


def check_array(a, shape, name, binary=False):
    if not isinstance(a,np.ndarray) or a.dtype!=np.uint8 or a.shape!=shape:
        raise ValueError(name+'_SHAPE_OR_TYPE_INVALID')
    if binary and not np.isin(a,[0,255]).all():
        raise ValueError(name+'_MUST_BE_BINARY')


def stats(mask):
    yy,xx=np.nonzero(mask)
    if not len(xx):return {'positivePixels':0,'components':0,'bounds':None,'minimumMarginFraction':0}
    h,w=mask.shape
    n,_,cc,_=cv2.connectedComponentsWithStats(np.uint8(mask>0),8)
    _,hierarchy=cv2.findContours(np.uint8(mask>0),cv2.RETR_CCOMP,cv2.CHAIN_APPROX_SIMPLE)
    holes=int(np.count_nonzero(hierarchy[0,:,3]>=0)) if hierarchy is not None else 0
    return {'positivePixels':len(xx),'components':n-1,'holes':holes,'componentAreas':sorted([int(x) for x in cc[1:,4]],reverse=True)[:32],
        'bounds':[int(xx.min()),int(yy.min()),int(xx.max()+1),int(yy.max()+1)],
        'minimumMarginFraction':min(xx.min()/w,yy.min()/h,(w-1-xx.max())/w,(h-1-yy.max())/h)}


def refine_contour(candidate, initial, scale=1.):
    """Fixed far labels, seeded single-thread GrabCut in a bounded ROI."""
    radius=max(1,round(6*scale));kernel=cv2.getStructuringElement(cv2.MORPH_ELLIPSE,(radius*2+1,radius*2+1))
    inside=cv2.erode(initial,kernel);outside=cv2.dilate(initial,kernel)
    bounds=stats(outside)['bounds']
    if bounds is None:raise ValueError('MASK_EMPTY')
    x0,y0,x1,y1=bounds;pad=max(8,round(24*scale));h,w=initial.shape
    x0=max(0,x0-pad);x1=min(w,x1+pad);y0=max(0,y0-pad);y1=min(h,y1+pad)
    if (x1-x0)*(y1-y0)>PARAMETERS['maximumRefinementRoiPixels']:raise ValueError('REFINEMENT_ROI_LIMIT')
    trimap=np.full((y1-y0,x1-x0),cv2.GC_BGD,np.uint8)
    crop=initial[y0:y1,x0:x1]
    trimap[outside[y0:y1,x0:x1]>0]=cv2.GC_PR_BGD
    trimap[crop>0]=cv2.GC_PR_FGD
    trimap[inside[y0:y1,x0:x1]>0]=cv2.GC_FGD
    if not np.any(trimap==cv2.GC_FGD):raise ValueError('NO_FIXED_FOREGROUND_INTERIOR')
    cv2.setRNGSeed(0);cv2.setNumThreads(1)
    cv2.grabCut(candidate[y0:y1,x0:x1],trimap,None,np.zeros((1,65),np.float64),np.zeros((1,65),np.float64),5,cv2.GC_INIT_WITH_MASK)
    refined=np.zeros_like(initial)
    refined[y0:y1,x0:x1]=np.uint8((trimap==cv2.GC_FGD)|(trimap==cv2.GC_PR_FGD))*255
    violation=int(np.count_nonzero((inside>0)&(refined==0))+np.count_nonzero((outside==0)&(refined>0)))
    if violation:raise ValueError('CONTOUR_FIXED_LABEL_VIOLATION')
    return refined,{'roi':[x0,y0,x1,y1],'bandRadiusPixels':radius,'changedPixels':int(np.count_nonzero(refined!=initial)),
        'changedOutsideBandPixels':violation,'before':stats(initial),'after':stats(refined)}


def gravity_vectors(points, shape, pitch, hfov):
    """Projection derivative of world-down; not an assumed screen vertical."""
    h,w=shape;f=w/(2*math.tan(math.radians(hfov/2)));p=math.radians(pitch)
    x,y=points[:,0]-(w-1)/2,points[:,1]-(h-1)/2
    vec=np.stack([math.sin(p)*x,f*math.cos(p)+math.sin(p)*y],axis=-1)
    length=np.linalg.norm(vec,axis=1)
    return vec/np.maximum(length[:,None],1e-9),length


def gravity_potential(points,shape,pitch,hfov):
    h,w=shape;p=math.radians(pitch);f=w/(2*math.tan(math.radians(hfov/2)))
    if abs(math.sin(p))<1e-5:return points[:,1].astype(float)
    vp=np.array([(w-1)/2,(h-1)/2-f/math.tan(p)])
    return math.copysign(1,math.sin(p))*np.linalg.norm(points-vp,axis=1)


def _sample(mask,points):
    xy=np.rint(points).astype(int);h,w=mask.shape
    valid=(xy[:,0]>=0)&(xy[:,0]<w)&(xy[:,1]>=0)&(xy[:,1]<h)
    out=np.zeros(len(points),bool)
    out[valid]=mask[xy[valid,1],xy[valid,0]]>0
    return out


def contact_hypotheses(mask,safe_floor,pitch,hfov,scale=1.):
    """Conservative lower-contour extremum + narrow-support hypotheses."""
    report={'hypotheses':[],'rejected':{},'physicalContactsVerified':False}
    if not -60<=pitch<=15 or not 30<=hfov<=130:
        report['reason']='CONTACT_VIEW_UNSUPPORTED';return report
    edge=(mask>0)&(cv2.erode(mask,np.ones((3,3),np.uint8))==0)
    yy,xx=np.nonzero(edge);points=np.stack([xx,yy],axis=-1).astype(float)
    if not len(points):report['reason']='NO_VISIBLE_CONTOUR';return report
    if len(points)>PARAMETERS['maximumBoundaryPoints']:
        report['reason']='CONTACT_BOUNDARY_COMPLEXITY_LIMIT';return report
    direction,norm=gravity_vectors(points,mask.shape,pitch,hfov)
    potential=gravity_potential(points,mask.shape,pitch,hfov)
    bottom_threshold=np.quantile(potential,.55)
    step=max(1.,2*scale)
    down_outside=~_sample(mask,points+direction*step)
    valid=down_outside&(potential>=bottom_threshold)&(norm>1e-6)
    indices=np.flatnonzero(valid)
    if len(indices)>PARAMETERS['maximumContactCandidates']:
        report['reason']='CONTACT_CANDIDATE_COMPLEXITY_LIMIT';return report
    indices=indices[np.argsort(potential[indices])[::-1]]
    bounds=stats(mask)['bounds'];width=bounds[2]-bounds[0]
    max_width=max(3.,min(48*scale,max(8*scale,.06*width)))
    cluster=max(5.,max_width*1.8)
    chosen=[]
    for index in indices:
        point=points[index];down=direction[index]
        if any(np.linalg.norm(point-p)<cluster for p in chosen):continue
        # Must be a local extremum in projected gravity, not an arbitrary side edge.
        nearby=np.linalg.norm(points-point,axis=1)<max_width
        if np.any(potential[nearby]>potential[index]+.25*scale):continue
        perp=np.array([-down[1],down[0]])
        widths=[];coherent=True
        for depth in [max(2.,6*scale),max(4.,12*scale)]:
            offsets=np.arange(-math.ceil(max_width),math.ceil(max_width)+1)
            samples=_sample(mask,point-down*depth+offsets[:,None]*perp)
            centre=len(offsets)//2
            if not samples[centre]:coherent=False;break
            left=centre;right=centre
            while left>0 and samples[left-1]:left-=1
            while right<len(samples)-1 and samples[right+1]:right+=1
            if left==0 or right==len(samples)-1:coherent=False;break
            widths.append(right-left+1)
        if not coherent or max(widths,default=0)>max_width:
            report['rejected']['wide_or_nonterminal_support']=report['rejected'].get('wide_or_nonterminal_support',0)+1;continue
        floor_point=point+down*step
        if not _sample(safe_floor,np.array([point,floor_point])).all():
            report['rejected']['not_on_safe_source_floor']=report['rejected'].get('not_on_safe_source_floor',0)+1;continue
        chosen.append(point)
        report['hypotheses'].append({'pixel':point.tolist(),'floorSamplePixel':floor_point.tolist(),
            'projectedDownDirection':down.tolist(),'upstreamWidthsPixels':widths,
            'sourceFloorSupport':'inward-safe supplied floor mask; not a calibrated world-space footprint'})
        if len(chosen)>4:break
    report['reason']='CONTACT_HYPOTHESES_AVAILABLE' if 2<=len(chosen)<=4 else 'CONTACT_ANCHOR_UNRESOLVED'
    return report


def derive_support(source,candidate,objects,floor,occluders,protected_features,pose,context,refine=True):
    # A fresh CLI must be bounded before connected-components or morphology,
    # not only after entering the later GrabCut refinement function.
    cv2.setNumThreads(1);cv2.setRNGSeed(0)
    start=time.monotonic();h,w=source.shape[:2]
    if cv2.__version__!='5.0.0' or np.__version__!='2.5.4':raise ValueError('UNVALIDATED_SUPPORT_RUNTIME')
    if w!=h or not 128<=w<=2048:raise ValueError('SECTION_DIMENSION_LIMIT')
    check_array(source,(h,w,3),'SOURCE');check_array(candidate,source.shape,'CANDIDATE')
    if not 1<=len(objects)<=3:raise ValueError('OBJECT_COUNT_LIMIT')
    if set(pose)!={'yaw','pitch','hFov'} or not all(isinstance(v,(int,float)) and not isinstance(v,bool) and math.isfinite(v) for v in pose.values()):raise ValueError('POSE_INVALID')
    if not -360<=pose['yaw']<=360 or not -90<=pose['pitch']<=90 or not 1<=pose['hFov']<180:raise ValueError('POSE_INVALID')
    scale=w/2048;reasons=[];details=[];refined=[];all_contacts=[]
    def reason(code):
        if code not in reasons:reasons.append(code)
    if context.get('sourceEmptyLivingRoom') is not True:reason('EMPTY_LIVING_SCOPE_UNCONFIRMED')
    if context.get('foregroundAssessment') not in {'none_observed','supplied'}:reason('FOREGROUND_OCCLUSION_UNKNOWN')
    if context.get('opticalAssessment')!='clear_for_actual_placement':reason('OPTICAL_DEPENDENCY_UNRESOLVED')
    if not context.get('sourceContextSha256'):reason('OPTICAL_CONTEXT_MISSING')
    if context.get('selectionOrigin')!='machine':reason('HUMAN_SELECTION_ASSISTANCE_PRESENT')
    if floor is None:
        safe_floor=None;reason('SOURCE_FLOOR_EVIDENCE_MISSING')
    else:
        check_array(floor,(h,w),'FLOOR')
        radius=max(1,round(6*scale));safe_floor=cv2.erode(np.uint8(floor==255)*255,
            cv2.getStructuringElement(cv2.MORPH_ELLIPSE,(radius*2+1,radius*2+1)))
        if not np.any(safe_floor):reason('SOURCE_FLOOR_EMPTY_OR_UNCERTAIN')
    occlusion=np.zeros((h,w),np.uint8)
    for mask in occluders:
        check_array(mask,(h,w),'OCCLUDER');occlusion=np.maximum(occlusion,mask)
    fixed=np.zeros((h,w),np.uint8)
    for mask in protected_features:
        check_array(mask,(h,w),'FIXED_FEATURE');fixed=np.maximum(fixed,mask)
    if context.get('foregroundAssessment')=='supplied' and not occluders:reason('DECLARED_OCCLUDERS_MISSING')
    for index,mask in enumerate(objects):
        check_array(mask,(h,w),'OBJECT',binary=True)
        initial=stats(mask)
        if not initial['positivePixels']:
            reason('MASK_EMPTY');details.append({'objectIndex':index,'initial':initial});refined.append(mask.copy());continue
        if initial['minimumMarginFraction']<.10:reason('SUPPORT_TOUCHES_SECTION_TAPER')
        try:
            m,refinement=refine_contour(candidate,mask,scale) if refine else (mask.copy(),{'refinementDisabledForSyntheticTest':True})
        except (ValueError,cv2.error) as exc:
            reason('CONTOUR_REFINEMENT_UNRESOLVED');m=mask.copy();refinement={'failed':True,'exceptionType':type(exc).__name__}
        after=stats(m)
        if after['components']!=initial['components'] or after.get('holes')!=initial.get('holes'):reason('MASK_TOPOLOGY_CHANGED')
        if after['components']>16:reason('OBJECT_MASK_FRAGMENTED')
        if np.any((m>0)&(fixed>0)):reason('OBJECT_OVERLAPS_PROTECTED_FEATURE')
        removed=int(np.count_nonzero((m>0)&(occlusion>0)));m[occlusion>0]=0
        contact=contact_hypotheses(m,safe_floor,pose['pitch'],pose['hFov'],scale) if safe_floor is not None else {'reason':'SOURCE_FLOOR_EVIDENCE_MISSING','hypotheses':[]}
        if contact['reason']!='CONTACT_HYPOTHESES_AVAILABLE':reason(contact['reason'])
        details.append({'objectIndex':index,'initial':initial,'refinement':refinement,'after':stats(m),
            'foregroundOccludedPixels':removed,'contacts':contact})
        all_contacts.extend(contact['hypotheses']);refined.append(m)
    overlaps=[]
    for i in range(len(refined)):
        for j in range(i+1,len(refined)):
            overlap=int(np.count_nonzero((refined[i]>0)&(refined[j]>0)))
            overlaps.append({'objectA':i,'objectB':j,'pixels':overlap})
            if overlap:reason('INSTANCE_MASK_OVERLAP_UNRESOLVED')
    union=np.maximum.reduce(refined)
    soft=cv2.GaussianBlur(union,(0,0),max(.125,.5*scale))
    soft[occlusion>0]=0
    if np.any((soft>0)&(fixed>0)):reason('SOFT_OBJECT_OVERLAPS_PROTECTED_FEATURE')
    soft[fixed>0]=0
    if stats(soft)['minimumMarginFraction']<.10:reason('SOFT_SUPPORT_TOUCHES_SECTION_TAPER')
    shadows=np.zeros((h,w),np.float32)
    # No shadow is emitted when any binding/semantic/contact gate is unresolved.
    if not reasons:
        yy,xx=np.mgrid[:h,:w]
        for contact in all_contacts:
            x,y=contact['floorSamplePixel'];width=max(contact['upstreamWidthsPixels'])
            rx=max(2.,min(18*scale,1.1*width));ry=max(1.,min(8*scale,.55*width))
            d=((xx-x)/rx)**2+((yy-y)/ry)**2
            local=.25*np.exp(-4.5*d);local[d>1]=0
            if np.any((local>=1/255)&(safe_floor!=255)):
                reason('CONTACT_SHADOW_WOULD_TRUNCATE_AT_FLOOR_EDGE');break
            if np.any((local>=1/255)&(fixed>0)):
                reason('CONTACT_SHADOW_OVERLAPS_PROTECTED_FEATURE');break
            shadows=np.maximum(shadows,local)
        if reasons:shadows[:]=0
    shadows[occlusion>0]=0
    shadow8=np.rint(shadows*255).astype(np.uint8)
    if np.count_nonzero(shadow8)>h*w*.04:reason('CONTACT_SHADOW_SUPPORT_EXCESSIVE');shadow8[:]=0
    if np.any(shadow8) and stats(shadow8)['minimumMarginFraction']<.10:reason('SHADOW_TOUCHES_SECTION_TAPER');shadow8[:]=0
    status='needs_review' if reasons else 'ready_for_unreviewed_composition'
    return {'objectAlpha':soft,'scalarShadow':shadow8,'safeFloorAlpha':safe_floor,
        'report':{'schemaVersion':1,'recipe':VERSION,'status':status,'automaticAcceptance':False,
            'machineAuthoredSupport':True,'selectionOrigin':context.get('selectionOrigin','unknown'),
            'humanReviewedSupport':False,'parameters':PARAMETERS,'runtime':runtime(),'pose':pose,
            'objects':details,'pairwiseObjectOverlap':overlaps,'objectAlphaStatistics':stats(soft),'scalarShadowStatistics':stats(shadow8),
            'foregroundProtectedChangedSupportPixels':int(np.count_nonzero(soft[occlusion>0])+np.count_nonzero(shadow8[occlusion>0])),
            'shadowOutsideSafeFloorPixels':int(np.count_nonzero((shadow8>0)&(safe_floor!=255))) if safe_floor is not None else None,
            'reviewReasons':reasons,'providerCalls':0,'elapsedMs':round((time.monotonic()-start)*1000),
            'limitations':['Contact extrema are geometric hypotheses, not semantic foot detection or calibrated furniture scale.',
                'Only tiny contact attenuation is proposed. No broad directional/cast shadow is inferred.',
                'Supplied floor, occluder order and optical assessments can be wrong; independent semantic evaluation is mandatory.',
                'No furniture translation, resizing, hidden-leg synthesis or architecture alteration is performed.',
                'Source floor membership does not prove circulation clearance or every world-space furniture footprint.']}}
