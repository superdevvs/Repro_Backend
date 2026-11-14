"""Fixed global color/brightness control. No masks, scene coordinates or network.

This is an artistic transform, not recovered physical lighting. The estimate
is one three-vector; every equal source color receives the same mapping except
inside the existing fixed nadir transition. Spatial detail is source-only.
"""
import hashlib
import math
from pathlib import Path
import numpy as np

VERSION = 'source-detail-global-log-odds-lighting/1'
PARAMETERS = {
    'estimator': 'per-channel weighted median of linear-RGB log-odds differences',
    'logitEpsilon': .002,
    'maximumAbsoluteLogOddsDelta': 2.4,
    'darkReliabilityLevel': .025,
    'highlightReliabilityLevel': .08,
    'minimumReliabilityWeight': .03,
    'sphericalAreaWeighting': 'cosine of pixel-centre elevation',
    'estimationNadirPolicy': 'existing fixed source cap/fade weight: zero below -80, full above -70 degrees',
    'minimumReliableWeightedFraction': .01,
    'minimumReliablePixelWeight': .25,
    'nativeRowBlockHeight': 96,
    'fieldDimensions': [128, 64],
    'fieldMeaning': 'constant three-vector, not a spatially varying field',
}


def weighted_quantile(values, weights, probability):
    order = np.argsort(values, kind='stable')
    cumulative = np.cumsum(weights[order], dtype=np.float64)
    index = np.searchsorted(cumulative, probability * cumulative[-1], side='left')
    return float(values[order[min(index, len(order) - 1)]])


def derive_offset(source_guidance, candidate, to_linear):
    if (source_guidance.dtype != np.uint8 or candidate.dtype != np.uint8
            or source_guidance.shape != candidate.shape or source_guidance.ndim != 3
            or source_guidance.shape[2] != 3):
        raise ValueError('GUIDANCE_SHAPE_OR_TYPE_INVALID')
    h, w = source_guidance.shape[:2]
    if w != 2*h or not 128 <= w <= 2048:
        raise ValueError('GUIDANCE_DIMENSION_LIMIT')
    source = to_linear(source_guidance).astype(np.float32)
    result = to_linear(candidate).astype(np.float32)
    eps = PARAMETERS['logitEpsilon']
    def logit(a):
        clipped = np.clip(a, eps, 1-eps)
        return np.log(clipped/(1-clipped))
    delta = np.clip(logit(result)-logit(source), -2.4, 2.4)
    reliability = np.clip(np.minimum(source.min(axis=2)/.025, 1)
                          * np.minimum((1-source.max(axis=2))/.08, 1), .03, 1)
    elevation = 90-(np.arange(h, dtype=np.float64)+.5)*180/h
    strength = np.clip((elevation+80)/10, 0, 1)
    strength = strength*strength*(3-2*strength)
    area = np.cos(np.radians(elevation))*strength
    reliable_fraction = float(np.sum((reliability >= .25)*area[:, None])/(w*area.sum()))
    if reliable_fraction < PARAMETERS['minimumReliableWeightedFraction']:
        raise ValueError('INSUFFICIENT_RELIABLE_SOURCE_TONAL_COVERAGE')
    weights = (reliability*area[:, None]).ravel()
    keep = weights > 0
    weights = weights[keep]
    offset = []; spread = []
    for channel in range(3):
        values = delta[:, :, channel].ravel()[keep]
        quantiles = [weighted_quantile(values, weights, q) for q in [.1, .25, .5, .75, .9]]
        offset.append(quantiles[2]); spread.append(quantiles)
    offset = np.asarray(offset, np.float32)
    if not np.isfinite(offset).all() or np.any(np.abs(offset) > 2.4):
        raise ValueError('GLOBAL_OFFSET_INVALID')
    return offset, {
        'offsetLinearRgbLogOdds': offset.tolist(),
        'weightedDeltaQuantiles': {'probabilities': [.1, .25, .5, .75, .9], 'RGB': spread},
        'reliableSphericalWeightedFraction': reliable_fraction,
        'nonzeroEstimationWeightPixels': int(keep.sum()),
        'guidanceDimensions': [w, h],
        'fitQualityClaim': 'none: broad quantile spread can indicate incompatible local guidance',
    }


def apply_offset(source, offset, geometry, existing_lighting):
    offset = np.asarray(offset)
    if offset.dtype != np.float32 or offset.shape != (3,) or not np.isfinite(offset).all() or np.any(np.abs(offset) > 2.4):
        raise ValueError('GLOBAL_OFFSET_INVALID')
    # Reuse the reviewed bounded logistic transform and fixed nadir policy.
    field = np.broadcast_to(offset, (64, 128, 3)).copy()
    output, telemetry = existing_lighting.apply_field(source, field, geometry, strip=96)
    telemetry['globalMappingEqualColors'] = 'equal RGB maps equally outside the fixed nadir fade; no physical optical proof'
    return output, telemetry
