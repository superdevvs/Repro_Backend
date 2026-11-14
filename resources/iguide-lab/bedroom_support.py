"""Bounded support for actual platform-bed / two-plinth-table candidates.

This does not reuse furniture lower-contour extrema as semantic feet. Explicit
visible base segments come from the independently retained actual-placement
assessment and must agree with mask boundaries and original floor. It never
proves hidden footprint clearance, calibrated dimensions or visual acceptance.
"""
from __future__ import annotations
import os
for _key in ('OMP_NUM_THREADS', 'OPENBLAS_NUM_THREADS', 'MKL_NUM_THREADS', 'NUMEXPR_NUM_THREADS'):
    os.environ[_key] = '1'
import math
import time
import cv2
import numpy as np
import staging_support as primitives

VERSION = 'bedroom-visible-plinth-support/1'
ROLES = ('bed', 'nightstand_left', 'nightstand_right')
PARAMETERS = {
    'sectionGuardFraction': .10, 'floorInsetPixelsAt2048': 6,
    'contactBoundaryTolerancePixelsAt2048': 2,
    'contactFloorProbePixelsAt2048': [4, 8, 12],
    'contactSegmentLengthPixelsAt2048': [8, 600],
    'minimumVisibleBaseFraction': .08, 'maximumContactSegmentsPerObject': 3,
    'maximumSegmentSamples': 129, 'contactShadowStrength': .20,
    'contactShadowDepthPixelsAt2048': 4,
    'maximumShadowAreaFraction': .02,
    'contourRefinement': 'unchanged-staging-bounded-grabcut/1',
}
cv2.setNumThreads(1)
cv2.setRNGSeed(0)


def runtime():
    return {**primitives.runtime(), 'recipe': VERSION, 'parameters': PARAMETERS,
            'legacyContactHeuristicsUsed': False}


def validate_contacts(mask, safe_floor, segments, pose, scale):
    """Verify supplied *visible* base contacts; do not invent missing contacts."""
    reasons, accepted = [], []
    if not isinstance(segments, list) or not 1 <= len(segments) <= 3:
        return {'reason': 'VISIBLE_BASE_CONTACTS_MISSING', 'segments': []}
    h, w = mask.shape
    binary = np.uint8(mask > 0)
    # Distance to the actual decoded boundary, both inside and outside.
    eroded = cv2.erode(binary, np.ones((3, 3), np.uint8))
    boundary = binary - eroded
    distance = cv2.distanceTransform(1 - boundary, cv2.DIST_L2, cv2.DIST_MASK_PRECISE)
    coverage = np.zeros_like(mask)
    for segment in segments:
        if not isinstance(segment, list) or len(segment) != 2 or any(
            not isinstance(p, list) or len(p) != 2 or any(type(n) is not int or not 0 <= n < w for n in p)
            for p in segment
        ):
            return {'reason': 'VISIBLE_BASE_SEGMENT_INVALID', 'segments': []}
        a, b = np.asarray(segment, np.float64)
        length = float(np.linalg.norm(b - a))
        if not max(2., 8 * scale) <= length <= 600 * scale:
            return {'reason': 'VISIBLE_BASE_SEGMENT_EXTENT_UNSUPPORTED', 'segments': []}
        count = min(129, max(3, math.ceil(length / max(1., 4 * scale)) + 1))
        points = np.linspace(a, b, count)
        down, magnitude = primitives.gravity_vectors(points, mask.shape, pose['pitch'], pose['hFov'])
        if not np.all(np.isfinite(down)) or np.any(magnitude < 1e-6):
            return {'reason': 'PROJECTED_GRAVITY_UNRESOLVED', 'segments': []}
        xy = np.rint(points).astype(int)
        if np.any(distance[xy[:, 1], xy[:, 0]] > max(1., 2 * scale)):
            reasons.append('VISIBLE_BASE_DISAGREES_WITH_MASK_BOUNDARY')
        for offset in (4, 8, 12):
            probes = points + down * max(1., offset * scale)
            if np.any(primitives._sample(binary, probes) != 0):
                reasons.append('VISIBLE_BASE_IS_NOT_LOWER_OBJECT_BOUNDARY')
            if not np.all(primitives._sample(safe_floor, probes)):
                reasons.append('VISIBLE_BASE_LACKS_ORIGINAL_SAFE_FLOOR')
        line_mask = np.zeros_like(mask)
        cv2.line(line_mask, tuple(segment[0]), tuple(segment[1]), 255, 1, cv2.LINE_8)
        if np.count_nonzero((coverage > 0) & (line_mask > 0)) > max(2, round(2 * scale)):
            reasons.append('VISIBLE_BASE_SEGMENTS_OVERLAP')
        coverage = np.maximum(coverage, line_mask)
        accepted.append({'endpoints': segment, 'lengthPixels': length, 'samples': count,
                         'floorProbeOffsetsPixels': [max(1., n * scale) for n in (4, 8, 12)]})
    bounds = primitives.stats(mask)['bounds']
    # A repeated tiny contact cannot support a wide plinth by assertion.
    if np.count_nonzero(coverage) < max(3., .08 * (bounds[2] - bounds[0])):
        reasons.append('VISIBLE_BASE_COVERAGE_TOO_SMALL')
    for i, left in enumerate(segments):
        for right in segments[i + 1:]:
            if left == right or left == list(reversed(right)):
                reasons.append('VISIBLE_BASE_SEGMENT_DUPLICATED')
    return {'reason': sorted(set(reasons))[0] if reasons else 'VISIBLE_BASE_SUPPORTED',
            'reasons': sorted(set(reasons)), 'segments': accepted,
            'semanticLimitation': 'Observed visible plinth only; no hidden-footprint or calibrated physical claim.'}


def derive_support(source, candidate, objects, floor, protected_features, pose, context, contacts, refine=True):
    started = time.monotonic()
    cv2.setNumThreads(1); cv2.setRNGSeed(0)
    if cv2.__version__ != '5.0.0' or np.__version__ != '2.5.4':
        raise ValueError('UNVALIDATED_BEDROOM_RUNTIME')
    h, w = source.shape[:2]
    if w != h or not 128 <= w <= 2048:
        raise ValueError('BEDROOM_SECTION_DIMENSIONS')
    primitives.check_array(source, (h, w, 3), 'SOURCE')
    primitives.check_array(candidate, source.shape, 'CANDIDATE')
    if not isinstance(objects, dict) or tuple(objects) != ROLES or not isinstance(contacts, dict) or set(contacts) != set(ROLES):
        raise ValueError('BEDROOM_PROFILE_IDENTITIES_REQUIRED')
    if not isinstance(pose, dict) or set(pose) != {'yaw', 'pitch', 'hFov'} or any(
        type(n) not in (int, float) or not math.isfinite(n) for n in pose.values()
    ) or not -180 <= pose['yaw'] <= 180 or not -55 <= pose['pitch'] <= 5 or not 45 <= pose['hFov'] <= 120:
        raise ValueError('BEDROOM_POSE_UNSUPPORTED')
    scale = w / 2048
    reasons = []
    def reason(value):
        if value not in reasons: reasons.append(value)
    for key, expected, code in [
        ('profileId', 'empty-bedroom-platform-three-piece/1', 'BEDROOM_PROFILE_UNCONFIRMED'),
        ('sourceEmptyBedroom', True, 'SOURCE_EMPTY_BEDROOM_UNCONFIRMED'),
        ('placementStatus', 'ready_for_masks', 'ACTUAL_PLACEMENT_UNCONFIRMED'),
        ('foregroundAssessment', 'none_observed', 'BEDROOM_FOREGROUND_ORDER_UNSUPPORTED'),
        ('opticalAssessment', 'clear_for_actual_placement', 'ACTUAL_OPTICAL_DEPENDENCY_UNRESOLVED'),
        ('selectionOrigin', 'machine', 'MACHINE_SELECTION_REQUIRED'),
    ]:
        if (context.get(key) is not True) if expected is True else (context.get(key) != expected): reason(code)
    if not context.get('sourceContextSha256'): reason('ORIGINAL_CONTEXT_MISSING')
    if floor is None:
        safe_floor = np.zeros((h, w), np.uint8); reason('SOURCE_FLOOR_MISSING')
    else:
        primitives.check_array(floor, (h, w), 'FLOOR', binary=True)
        radius = max(1, round(6 * scale))
        safe_floor = cv2.erode(floor, cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (2 * radius + 1, 2 * radius + 1)))
        if not np.any(safe_floor): reason('SOURCE_FLOOR_EMPTY')
    fixed = np.zeros((h, w), np.uint8)
    for feature in protected_features:
        primitives.check_array(feature, (h, w), 'PROTECTED_FEATURE')
        fixed = np.maximum(fixed, feature)
    refined, details = {}, []
    for role in ROLES:
        mask = objects[role]
        primitives.check_array(mask, (h, w), role.upper(), binary=True)
        before = primitives.stats(mask)
        if not before['positivePixels']:
            reason('OBJECT_MASK_EMPTY'); refined[role] = mask.copy(); continue
        if before['minimumMarginFraction'] < .10: reason('OBJECT_TOUCHES_SECTION_GUARD')
        try:
            result, diagnostic = primitives.refine_contour(candidate, mask, scale) if refine else (mask.copy(), {'syntheticRefinementDisabled': True})
        except (ValueError, cv2.error):
            result, diagnostic = mask.copy(), {'refinementFailed': True}; reason('OBJECT_BOUNDARY_REFINEMENT_UNRESOLVED')
        after = primitives.stats(result)
        if not after['positivePixels']:
            reason('REFINED_OBJECT_MASK_EMPTY'); refined[role] = result; continue
        if after['components'] != before['components'] or after['holes'] != before['holes']:
            reason('OBJECT_TOPOLOGY_CHANGED')
        if after['components'] != 1: reason('BEDROOM_OBJECT_FRAGMENTED')
        if np.any((result > 0) & (fixed > 0)): reason('OBJECT_OVERLAPS_FIXED_SOURCE_FEATURE')
        refined[role] = result
        details.append({'id': role, 'initial': before, 'after': after, 'refinement': diagnostic})
    for i, role in enumerate(ROLES):
        for other in ROLES[i + 1:]:
            if np.any((refined[role] > 0) & (refined[other] > 0)): reason('ACTUAL_INSTANCE_OVERLAP_UNRESOLVED')
    union = np.maximum.reduce(list(refined.values()))
    # A floor region behind another added object is not visible contact evidence.
    # Evaluate only after all three final contours exist, not against one isolated mask.
    visible_floor = safe_floor.copy()
    visible_floor[union > 0] = 0
    for detail in details:
        role = detail['id']
        contact = validate_contacts(refined[role], visible_floor, contacts[role], pose, scale)
        detail['visibleBase'] = contact
        if contact['reason'] != 'VISIBLE_BASE_SUPPORTED': reason(contact['reason'])
    soft = cv2.GaussianBlur(union, (0, 0), max(.125, .5 * scale))
    if np.any((soft > 0) & (fixed > 0)): reason('SOFT_BOUNDARY_OVERLAPS_FIXED_SOURCE_FEATURE')
    if primitives.stats(soft)['minimumMarginFraction'] < .10: reason('SOFT_SUPPORT_TOUCHES_SECTION_GUARD')
    shadow = np.zeros((h, w), np.float32)
    if not reasons:
        # Tiny source-floor contact attenuation only; no broad cast-shadow synthesis.
        # Bounding each local ROI avoids a full-canvas grid for every contact line.
        depth = max(1., 4 * scale)
        for role in ROLES:
            for segment in contacts[role]:
                a, b = np.asarray(segment, np.float64)
                pad = math.ceil(depth) + 2
                lo = np.floor(np.minimum(a, b)).astype(int) - pad
                hi = np.ceil(np.maximum(a, b)).astype(int) + pad + 1
                lo = np.maximum(lo, 0); hi = np.minimum(hi, w)
                yy, xx = np.mgrid[lo[1]:hi[1], lo[0]:hi[0]]
                points = np.column_stack((xx.ravel(), yy.ravel()))
                vector = b - a; t = np.clip((points - a) @ vector / (vector @ vector), 0., 1.)
                near = a + t[:, None] * vector
                down, _ = primitives.gravity_vectors(near, (h, w), pose['pitch'], pose['hFov'])
                offset = points - near
                normal = np.sum(offset * down, axis=1)
                lateral = np.abs(offset[:, 0] * down[:, 1] - offset[:, 1] * down[:, 0])
                active = (normal > 0) & (normal <= depth) & (lateral <= max(1., scale))
                local = np.where(active, .20 * np.exp(-3 * normal / depth), 0.).reshape(xx.shape)
                region = np.s_[lo[1]:hi[1], lo[0]:hi[0]]
                local[union[region] > 0] = 0
                positive = local >= 1 / 255
                if np.any(positive & ((safe_floor[region] != 255) | (fixed[region] > 0))):
                    reason('CONTACT_SHADOW_LEAVES_ORIGINAL_SAFE_FLOOR'); break
                shadow[region] = np.maximum(shadow[region], local)
            if reasons: break
    shadow8 = np.rint(shadow * 255).astype(np.uint8)
    if np.count_nonzero(shadow8) > h * w * .02: reason('BEDROOM_CONTACT_SHADOW_EXTENT_UNSUPPORTED')
    if reasons: shadow8[:] = 0
    report = {'schemaVersion': 1, 'recipe': VERSION, 'profileId': context.get('profileId'),
              'status': 'needs_review' if reasons else 'ready_for_unreviewed_composition',
              'reviewReasons': reasons, 'parameters': PARAMETERS, 'runtime': runtime(), 'pose': pose,
              'objects': details, 'objectAlphaStatistics': primitives.stats(soft), 'scalarShadowStatistics': primitives.stats(shadow8),
              'shadowOutsideSafeFloorPixels': int(np.count_nonzero((shadow8 > 0) & (safe_floor != 255))),
              'automaticAcceptance': False, 'humanReviewedSupport': False, 'legacyContactHeuristicsUsed': False,
              'providerCalls': 0, 'nativeCompositionPerformed': False, 'elapsedMs': round((time.monotonic() - started) * 1000),
              'limitations': ['Only actual visible platform/plinth contacts are checked; hidden support or world-space clearance is not certified.',
                             'Source floor and optical assessments are machine claims requiring independent native quality review.',
                             'No object repositioning, form repair, broad cast-shadow synthesis or reflection generation is performed.']}
    return {'objectAlpha': soft, 'scalarShadow': shadow8, 'safeFloorAlpha': safe_floor, 'report': report}
