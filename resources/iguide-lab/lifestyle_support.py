"""Bounded visible-shoe support for two standing adults; never acceptance.

Only generic contour/array/gravity primitives are reused. Furniture contact
extrema are deliberately not imported. Every shoe starts with an image-evidenced
segment from a trusted typed actual-person assessment, then is checked against
the candidate mask and original floor. These are operational checks, not depth.
"""
from __future__ import annotations
import hashlib
import math
import os
from pathlib import Path
import time
for _key in ('OMP_NUM_THREADS','OPENBLAS_NUM_THREADS','MKL_NUM_THREADS','NUMEXPR_NUM_THREADS'):
    os.environ[_key]='1'
import cv2
import numpy as np
from staging_support import check_array, stats, refine_contour, gravity_vectors
cv2.setNumThreads(1);cv2.setRNGSeed(0)

VERSION='single-section-standing-pair-support/1'
PARAMETERS={'people':2,'shoesPerPerson':2,'boundaryBandAt2048':2,'floorInsetAt2048':6,
    'cropGuardFraction':.10,'maximumSmallComponents':8,'maximumSecondaryAreaFraction':.005,
    'maximumHoles':32,'maximumRefinementChangedAreaFraction':.08,'contactStrength':.18,
    'maximumShadowFraction':.003,'maximumSoleLengthAt2048':90,'minimumSoleLengthAt2048':6,
    'maximumDistanceFromSoleToMaskAt2048':3,'shadowKind':'tiny_visible_shoe_contact_only'}

def runtime():
    return {'opencv':cv2.__version__,'numpy':np.__version__,'opencvBuildSha256':hashlib.sha256(cv2.getBuildInformation().encode()).hexdigest(),
        'codeSha256':hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),'contourHelperSha256':hashlib.sha256((Path(__file__).parent/'staging_support.py').read_bytes()).hexdigest()}

def sample(array,points):
    xy=np.rint(points).astype(int);h,w=array.shape
    valid=(xy[:,0]>=0)&(xy[:,0]<w)&(xy[:,1]>=0)&(xy[:,1]<h);result=np.zeros(len(points),array.dtype)
    result[valid]=array[xy[valid,1],xy[valid,0]];return result

def visible_shoe_contact(mask,safe_floor,shoe,pose,scale):
    if shoe.get('status')!='visible_contact' or shoe.get('sourceFloorRelation')!='visible_level_floor':return None,'VISIBLE_SHOE_CONTACT_UNRESOLVED'
    segment=shoe.get('soleSegment')
    if not isinstance(segment,list) or len(segment)!=2:return None,'VISIBLE_SHOE_SEGMENT_MISSING'
    h,w=mask.shape
    try:ends=np.array([[p['x']*w,p['y']*h] for p in segment],np.float64)
    except (KeyError,TypeError,ValueError):return None,'VISIBLE_SHOE_SEGMENT_INVALID'
    if not np.isfinite(ends).all() or np.any(ends<0) or np.any(ends>=w):return None,'VISIBLE_SHOE_SEGMENT_INVALID'
    length=float(np.linalg.norm(ends[1]-ends[0]))
    if not max(2,6*scale)<=length<=90*scale:return None,'SHOE_CONTACT_EXTENT_UNSUPPORTED'
    points=ends[0]+np.linspace(0,1,max(5,round(length)+1))[:,None]*(ends[1]-ends[0])
    down,norm=gravity_vectors(points,mask.shape,pose['pitch'],pose['hFov'])
    if np.any(norm<1e-5):return None,'SHOE_PROJECTED_GRAVITY_DEGENERATE'
    tangent=(ends[1]-ends[0])/length
    if np.max(np.abs(down@tangent))>.65:return None,'SHOE_SOLE_NOT_TRANSVERSE_TO_GRAVITY'
    # A segment must track an actual lower silhouette edge, not a hand or a
    # convenient floor pixel. Two small independent bands bracket that edge.
    outside=cv2.distanceTransform(np.uint8(mask==0),cv2.DIST_L2,5)
    inside=cv2.distanceTransform(np.uint8(mask>0),cv2.DIST_L2,5)
    tolerance=max(1.25,3*scale)
    if np.any(sample(outside,points)>tolerance) or np.any(sample(inside,points)>tolerance):return None,'SHOE_SEGMENT_NOT_ON_MASK_BOUNDARY'
    upstream=points-down*max(2,6*scale)
    downstream=points+down*max(2,6*scale)
    if np.mean(sample(mask,upstream)>0)<.8 or np.any(sample(mask,downstream)>0):return None,'SHOE_CONTACT_NOT_LOWER_VISIBLE_EDGE'
    for distance in [max(2,5*scale),max(3,10*scale),max(4,14*scale)]:
        if np.any(sample(safe_floor,points+down*distance)!=255):return None,'SHOE_CONTACT_LACKS_INWARD_ORIGINAL_FLOOR'
    # Reject a claimed contact at mid-body, even if an arm edge happens to be
    # horizontal. Physical shoe identity still depends on visual assessment.
    bounds=stats(mask)['bounds'];height=bounds[3]-bounds[1]
    if np.mean(points[:,1])<bounds[1]+.70*height:return None,'SHOE_SEGMENT_NOT_IN_LOWER_BODY'
    return {'side':shoe['side'],'soleEndpointsPixels':ends.tolist(),'soleLengthPixels':length,
        'centrePixel':points.mean(axis=0).tolist(),'downVector':down.mean(axis=0).tolist(),
        'sourceFloorChecked':True,'physicalContactVerified':False},None

def derive_support(source,candidate,person_masks,source_floor,pose,assessment):
    start=time.monotonic();cv2.setNumThreads(1);cv2.setRNGSeed(0)
    if not isinstance(source,np.ndarray) or source.ndim!=3:raise ValueError('SOURCE_INVALID')
    h,w,_=source.shape
    if h!=w or not 256<=h<=2048:raise ValueError('SQUARE_SUPPORT_DIMENSIONS_INVALID')
    check_array(source,(h,w,3),'SOURCE');check_array(candidate,(h,w,3),'CANDIDATE')
    check_array(source_floor,(h,w),'SOURCE_FLOOR',binary=True)
    if len(person_masks)!=2:raise ValueError('EXACTLY_TWO_PERSON_MASKS_REQUIRED')
    for m in person_masks:check_array(m,(h,w),'PERSON_MASK',binary=True)
    if not -60<=pose.get('pitch',90)<=15 or not 30<=pose.get('hFov',0)<=120:raise ValueError('POSE_UNSUPPORTED')
    reasons=[]
    def hold(code):
        if code not in reasons:reasons.append(code)
    if assessment.get('schemaVersion')!='automatic-standing-pair-assessment/1' or assessment.get('status')!='ready_for_segmentation' or assessment.get('reviewReasons'):
        hold('TYPED_PEOPLE_ASSESSMENT_NOT_READY')
    p=assessment.get('proposal',{});people=p.get('people',[])
    if [x.get('id') for x in people]!=['adult_1','adult_2']:raise ValueError('PERSON_IDENTITIES_INVALID')
    required={'sourceRegion':'visible_level_floor_no_existing_people','group':'two_separate_standing_adults_no_props','foreground':'no_foreground_overlap',
        'protectedFeatures':'no_conflict_observed','optics':'no_relevant_dependency_observed','lighting':'diffuse_tiny_contact_only','contextCoverage':'sufficient_same_capture_views'}
    for key,status in required.items():
        if p.get(key,{}).get('status')!=status:hold(key.upper()+'_REQUIRES_REVIEW')
    if p.get('uncertainties'):hold('UNRESOLVED_UNCERTAINTY')
    scale=w/2048;radius=max(1,round(6*scale));kernel=cv2.getStructuringElement(cv2.MORPH_ELLIPSE,(radius*2+1,radius*2+1))
    safe=cv2.erode(source_floor,kernel);refined=[];details=[];contacts=[]
    if not np.any(safe):hold('ORIGINAL_FLOOR_SUPPORT_EMPTY')
    for i,initial in enumerate(person_masks):
        person=people[i];before=stats(initial)
        if person.get('status')!='complete_standing_adult' or person.get('anatomy')!='plausible_visible_limbs' or person.get('scale')!='plausible_source_relative' or person.get('topology')!='clear_visible_boundaries_and_holes':hold('PERSON_SEMANTICS_UNRESOLVED')
        if before['positivePixels']==0:raise ValueError('PERSON_MASK_EMPTY')
        if before['minimumMarginFraction']<.10:hold('PERSON_MASK_TOUCHES_GUARD')
        areas=before['componentAreas']
        if before['components']>8 or sum(areas[1:])>before['positivePixels']*.005:hold('PERSON_MASK_FRAGMENTED')
        if before['holes']>32:hold('PERSON_MASK_TOPOLOGY_COMPLEX')
        bx0,by0,bx1,by1=before['bounds'];bh=by1-by0;bw=bx1-bx0
        if not .20<=bh/h<=.8 or not .12<=bw/bh<=.75:hold('PERSON_MASK_PROPORTION_UNSUPPORTED')
        try:m,refinement=refine_contour(candidate,initial,scale/3)
        except ValueError as e:m=initial.copy();refinement={'error':str(e)};hold('PERSON_CONTOUR_REFINEMENT_HELD')
        after=stats(m)
        if before['components']!=after['components'] or before['holes']!=after['holes']:hold('PERSON_MASK_TOPOLOGY_CHANGED')
        if np.count_nonzero(m!=initial)>before['positivePixels']*.08:hold('PERSON_BOUNDARY_CHANGE_EXCESSIVE')
        if after['minimumMarginFraction']<.10:hold('REFINED_PERSON_TOUCHES_GUARD')
        shoes=person.get('shoes',[]);person_contacts=[]
        if [s.get('side') for s in shoes]!=['left','right']:hold('TWO_VISIBLE_SHOES_REQUIRED')
        else:
            for shoe in shoes:
                contact,reason=visible_shoe_contact(m,safe,shoe,pose,scale)
                if reason:hold(reason)
                else:person_contacts.append(contact);contacts.append(contact)
        if len(person_contacts)==2:
            a,b=[np.array(x['centrePixel']) for x in person_contacts]
            if np.linalg.norm(a-b)<max(4,12*scale):hold('SHOE_CONTACTS_NOT_DISTINCT')
        refined.append(m);details.append({'identity':person['id'],'initial':before,'refinement':refinement,'after':after,'contacts':person_contacts})
    if np.any((refined[0]>0)&(refined[1]>0)):hold('PERSON_MASKS_OVERLAP')
    union=np.maximum.reduce(refined)
    # Fractional boundary is inward only. No growth into unselected hair/background
    # corridors and no filling of arm/leg holes or disconnected appendages.
    distance=cv2.distanceTransform(np.uint8(union>0),cv2.DIST_L2,5)
    alpha=np.rint(np.clip((distance-.25)/max(.75,1.5*scale),0,1)*255).astype(np.uint8)
    alpha[union==0]=0
    shadows=np.zeros((h,w),np.float32)
    if not reasons and len(contacts)==4:
        yy,xx=np.mgrid[:h,:w]
        for contact in contacts:
            centre=np.array(contact['centrePixel']);down=np.array(contact['downVector']);down/=np.linalg.norm(down);across=np.array([down[1],-down[0]])
            centre=centre+down*max(1.5,3*scale);dx=xx-centre[0];dy=yy-centre[1]
            rx=max(2,min(45*scale,contact['soleLengthPixels']*.65));ry=max(1,4*scale)
            d=((dx*across[0]+dy*across[1])/rx)**2+((dx*down[0]+dy*down[1])/ry)**2
            local=.18*np.exp(-4*d);local[d>1]=0;local[union>0]=0
            if np.any((local>=1/255)&(safe!=255)):hold('SHOE_SHADOW_OUTSIDE_INWARD_SOURCE_FLOOR');break
            shadows=np.maximum(shadows,local)
    elif len(contacts)!=4:hold('FOUR_VISIBLE_SHOE_CONTACTS_REQUIRED')
    shadow8=np.rint(shadows*255).astype(np.uint8)
    if np.count_nonzero(shadow8)>h*w*.003:hold('SHOE_SHADOW_SUPPORT_EXCESSIVE')
    if np.any(shadow8) and stats(shadow8)['minimumMarginFraction']<.10:hold('SHOE_SHADOW_TOUCHES_GUARD')
    if reasons:shadow8[:]=0
    report={'schemaVersion':1,'recipe':VERSION,'status':'needs_review' if reasons else 'ready_for_unreviewed_composition',
        'automaticAcceptance':False,'machineAuthoredSupport':True,'humanReviewedSupport':False,'parameters':PARAMETERS,'runtime':runtime(),'pose':pose,
        'people':details,'objectAlphaStatistics':stats(alpha),'scalarShadowStatistics':stats(shadow8),
        'shadowOutsideSafeFloorPixels':int(np.count_nonzero((shadow8>0)&(safe!=255))),'alphaOutsidePersonMasksPixels':int(np.count_nonzero((alpha>0)&(union==0))),
        'reviewReasons':reasons,'providerCalls':0,'elapsedMs':round((time.monotonic()-start)*1000),
        'limitations':['Visible shoes and anatomy are machine visual observations, not calibrated measurements.',
            'Fine hair/finger alpha remains bounded image matting, subject to independent quality review.',
            'No person relocation, hidden limb synthesis, reflection synthesis or broad cast shadow is supported.',
            'Only tiny local contact attenuation is applied to original source floor; no regenerated floor is used.']}
    return {'objectAlpha':alpha,'scalarShadow':shadow8,'safeFloorAlpha':safe,'report':report}
