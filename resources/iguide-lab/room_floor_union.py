"""Exact source-only union support domain. No object alpha edits or calibration claim."""
import hashlib
import cv2
import numpy as np

VERSION = 'interior-source-floor-witness/3'
LABEL = 'opaque matte target-room floor union interior witnesses'
IDS = {'source', 'parent_source', 'parent_front', 'parent_right', 'parent_back', 'parent_left'}
ROLES = [('sofa', 'coffee_table', 'chair'), ('bed', 'nightstand_left', 'nightstand_right')]
KEYS = {'version', 'selection', 'nativeSourceSha256', 'usablePixelsSha256', 'safePixelsSha256',
        'sourceSha256', 'rawMaskSha256', 'planSha256', 'assessmentSha256', 'contextSha256'}


def _box(box, minimum=16, guard=False):
    if not isinstance(box, list) or len(box) != 4 or any(type(n) is not int for n in box):
        raise ValueError('FLOOR_UNION_BOX_INVALID')
    low, high = (205, 1842) if guard else (0, 2047)
    x0, y0, x1, y1 = box
    if not (low <= x0 < x1 <= high and low <= y0 < y1 <= high and x1-x0+1 >= minimum and y1-y0+1 >= minimum):
        raise ValueError('FLOOR_UNION_BOX_INVALID')
    return x0, y0, x1, y1


def domain(selection):
    if not isinstance(selection, dict) or set(selection) != {'status', 'prompt', 'regions', 'planeEvidence', 'contactEnvelopes'} or selection['status'] != 'opaque_matte' or selection['prompt'] != LABEL:
        raise ValueError('FLOOR_UNION_SELECTION_INVALID')
    regions = selection['regions']
    if not isinstance(regions, list) or not 1 <= len(regions) <= 4:
        raise ValueError('FLOOR_UNION_REGIONS_INVALID')
    usable = np.zeros((2048, 2048), np.uint8); seen = set()
    for box in regions:
        x0, y0, x1, y1 = _box(box)
        if tuple(box) in seen: raise ValueError('FLOOR_UNION_DUPLICATE_REGION')
        seen.add(tuple(box)); usable[y0:y1+1, x0:x1+1] = 255
    evidence = selection['planeEvidence']
    if not isinstance(evidence, dict) or set(evidence) != {'status', 'evidenceViewIds', 'reason'} or evidence['status'] != 'same_visible_target_floor' or not isinstance(evidence['reason'], str) or not evidence['reason'].strip() or len(evidence['reason']) > 550:
        raise ValueError('FLOOR_UNION_SOURCE_PLANE_REQUIRED')
    ids = evidence['evidenceViewIds']
    if not isinstance(ids, list) or len(ids) != 6 or set(ids) != IDS:
        raise ValueError('FLOOR_UNION_SOURCE_VIEWS_REQUIRED')
    safe = cv2.erode(usable, cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (13, 13)))
    envelopes = selection['contactEnvelopes']
    if not isinstance(envelopes, list) or len(envelopes) != 3 or not all(isinstance(e, dict) and set(e) == {'objectId', 'box'} for e in envelopes) or tuple(e['objectId'] for e in envelopes) not in ROLES:
        raise ValueError('FLOOR_UNION_ROLE_ENVELOPES_INVALID')
    for envelope in envelopes:
        x0, y0, x1, y1 = _box(envelope['box'], minimum=2, guard=True)
        if not np.all(safe[y0:y1+1, x0:x1+1] == 255):
            raise ValueError('FLOOR_UNION_CONTACT_ENVELOPE_OUTSIDE_SAFE_DOMAIN')
    return usable, safe


def restrict_floor(mask, witness, source_sha256, native_sha256, raw_mask_sha256):
    if not isinstance(witness, dict) or set(witness) != KEYS or witness['version'] != VERSION:
        raise ValueError('FLOOR_UNION_CONTRACT_INVALID')
    for key in KEYS - {'version', 'selection'}:
        value = witness[key]
        if not isinstance(value, str) or len(value) != 64 or any(c not in '0123456789abcdef' for c in value):
            raise ValueError('FLOOR_UNION_HASH_INVALID')
    if (witness['sourceSha256'], witness['nativeSourceSha256'], witness['rawMaskSha256']) != (source_sha256, native_sha256, raw_mask_sha256):
        raise ValueError('FLOOR_UNION_SOURCE_CHANGED')
    if not isinstance(mask, np.ndarray) or mask.shape != (2048, 2048) or mask.dtype != np.uint8 or not np.all((mask == 0) | (mask == 255)):
        raise ValueError('FLOOR_UNION_BINARY_MASK_REQUIRED')
    usable, safe = domain(witness['selection'])
    if np.any((usable > 0) & (mask != 255)): raise ValueError('FLOOR_UNION_PIXELS_UNCONFIRMED')
    for key, image in [('usablePixelsSha256', usable), ('safePixelsSha256', safe)]:
        if witness[key] != hashlib.sha256(image.tobytes()).hexdigest(): raise ValueError('FLOOR_UNION_DOMAIN_CHANGED')
    return usable, {'witness': witness, 'rawMaskSha256': raw_mask_sha256,
        'rawPositivePixels': int(np.count_nonzero(mask)), 'usablePositivePixels': int(np.count_nonzero(usable)),
        'usablePixelsSha256': witness['usablePixelsSha256'], 'safePixelsSha256': witness['safePixelsSha256'],
        'semanticClassVerified': False, 'restrictionUsage': 'support_eligibility_only', 'objectAlphaRestrictedByWitness': False}
