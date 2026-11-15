"""Versioned source witness restriction; never changes object alpha or certifies a plane."""
import hashlib
import numpy as np

VERSION = 'interior-source-floor-witness/2'
KEYS = {'version', 'semantics', 'box', 'sourceSha256', 'nativeSourceSha256', 'rawMaskSha256',
        'planSha256', 'assessmentSha256', 'contextSha256', 'usablePixelsSha256'}


def restrict_floor(mask, witness, source_sha256, native_sha256, raw_mask_sha256):
    if not isinstance(witness, dict) or set(witness) != KEYS or witness['version'] != VERSION or witness['semantics'] != 'interior_support_witness':
        raise ValueError('FLOOR_WITNESS_CONTRACT_INVALID')
    for key in KEYS - {'version', 'semantics', 'box'}:
        value = witness[key]
        if not isinstance(value, str) or len(value) != 64 or any(c not in '0123456789abcdef' for c in value):
            raise ValueError('FLOOR_WITNESS_HASH_INVALID')
    if (witness['sourceSha256'], witness['nativeSourceSha256'], witness['rawMaskSha256']) != (source_sha256, native_sha256, raw_mask_sha256):
        raise ValueError('FLOOR_WITNESS_SOURCE_CHANGED')
    if not isinstance(mask, np.ndarray) or mask.ndim != 2 or mask.dtype != np.uint8 or not np.all((mask == 0) | (mask == 255)):
        raise ValueError('FLOOR_WITNESS_BINARY_MASK_REQUIRED')
    box = witness['box']; h, w = mask.shape
    if not isinstance(box, list) or len(box) != 4 or any(type(x) is not int for x in box):
        raise ValueError('FLOOR_WITNESS_BOX_INVALID')
    x0, y0, x1, y1 = box
    if not (0 <= x0 < x1 < w and 0 <= y0 < y1 < h and x1 - x0 + 1 >= 16 and y1 - y0 + 1 >= 16):
        raise ValueError('FLOOR_WITNESS_BOX_INVALID')
    if not np.all(mask[y0:y1+1, x0:x1+1] == 255):
        raise ValueError('FLOOR_WITNESS_PIXELS_UNCONFIRMED')
    usable = np.zeros_like(mask)
    usable[y0:y1+1, x0:x1+1] = mask[y0:y1+1, x0:x1+1]
    digest = hashlib.sha256(usable.tobytes()).hexdigest()
    if witness['usablePixelsSha256'] != digest:
        raise ValueError('FLOOR_WITNESS_USABLE_PIXELS_CHANGED')
    return usable, {'witness': witness, 'rawMaskSha256': raw_mask_sha256,
                    'rawPositivePixels': int(np.count_nonzero(mask)), 'usablePositivePixels': int(np.count_nonzero(usable)),
                    'usablePixelsSha256': digest, 'semanticClassVerified': False,
                    'restrictionUsage': 'support_eligibility_only', 'objectAlphaRestrictedByWitness': False}
