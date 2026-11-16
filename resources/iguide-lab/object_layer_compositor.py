"""Unregistered, disabled-by-default object layer experiment.

No provider, workflow, filesystem, semantic, support-generation or Ready authority.
Existing workflow_qa/pano_geometry and their production 10% checks stay unchanged.
"""
import hashlib
import math
import sys

import numpy as np
from PIL import Image, __version__ as PILLOW_VERSION

import pano_geometry as g
import workflow_qa as q

VERSION = 'experimental-object-layer-compositor/2'
GEOMETRY_SHA256 = '3415cd69d72e60d2fd3fa37e738d4af1fe31a85299153756ee18b79d5aabb98f'


def _require(value, reason):
    if not value:
        raise ValueError(reason)


def _premultiplied_mipmaps(candidate, object_alpha):
    """Filter linear color and coverage together; zero-alpha RGB has no effect.

    Float channels retain signed Lanczos intermediates without per-level byte
    rounding or clipping. Clamp coverage and unassociated color only after the
    final spatial/mip sample. This prevents new donor-background filter bleed;
    it cannot remove background color already baked into a nonzero-alpha pixel.
    """
    alpha = object_alpha.astype(np.float32)/255
    colors = [g.to_linear(candidate).astype(np.float32)*alpha[..., None]]
    alphas = [alpha]
    while min(alphas[-1].shape) > 32:
        previous = alphas[-1]
        size = (max(1, previous.shape[1]//2), max(1, previous.shape[0]//2))
        alphas.append(np.asarray(Image.fromarray(previous).resize(size, Image.Resampling.LANCZOS)))
        colors.append(np.stack([
            np.asarray(Image.fromarray(colors[-1][..., channel]).resize(size, Image.Resampling.LANCZOS))
            for channel in range(3)], axis=-1))
    return colors, alphas


def _axis_complete(sizes):
    """Conservative untruncated dependency for each Pillow13 Lanczos mip texel.

    Pillow13 Resample.c: support=3*max(inSize/outSize,1), centre=(x+.5)*scale,
    integer bounds are trunc(centre +/- support + .5), then clamped. Requiring
    the unclamped interval AND every parent texel complete is conservative,
    including zero-coefficient endpoint taps. It does not change the filter.
    """
    flags = [np.ones(sizes[0], dtype=bool)]
    for previous, size in zip(sizes, sizes[1:]):
        scale = previous / size
        radius = 3 * max(scale, 1)
        complete = np.zeros(size, dtype=bool)
        for i in range(size):
            centre = (i + .5) * scale
            lower, upper = centre-radius+.5, centre+radius+.5
            lo, hi = math.trunc(lower), math.trunc(upper)
            # C truncation turns -0.5 into 0 before Pillow's clamp. Test the
            # floating support endpoints too; that missing outside tap is not
            # safe merely because the resulting integer bound equals zero.
            complete[i] = lower >= 0 and upper <= previous and hi > lo and flags[-1][lo:hi].all()
        flags.append(complete)
    return flags


def _lod(levels, u, v):
    # Same footprint/LOD calculation as the frozen g.sample_antialiased.
    footprint = np.maximum(np.hypot(g.coordinate_derivative(u, 1), g.coordinate_derivative(v, 1)),
                           np.hypot(g.coordinate_derivative(u, 0), g.coordinate_derivative(v, 0)))
    return np.clip(np.log2(np.maximum(footprint, 1)), 0, len(levels)-1)


def _source_clearance(sizes, last_level):
    # Radius in original pixels: each recursive Lanczos stage adds its scaled
    # support plus one conservative rounding pixel. Bilinear adds one texel at
    # the coarsest selected level, then two original border pixels. This also
    # protects input support that a coarse output grid could otherwise skip.
    radius, scale = 0., 1.
    for previous, size in zip(sizes[:last_level], sizes[1:last_level+1]):
        ratio = previous/size
        radius += (3*max(ratio, 1)+1)*scale
        scale *= ratio
    return math.ceil(radius+scale+2)


def _guard_sample_footprints(levels, u, v, active, complete_x, complete_y):
    lod = _lod(levels, u, v)
    high_used = np.ceil(lod).astype(np.int16)
    # Completeness is monotonically stricter at coarser levels, but explicitly
    # test both actual levels rather than depending on that fact.
    low = np.floor(lod).astype(np.int16)
    base_h, base_w = levels[0].shape[:2]
    maximum = 0
    for indices in [low, high_used]:
        for index in np.unique(indices[active]):
            use = active & (indices == index)
            hh, ww = levels[int(index)].shape[:2]
            uu, vv = (u[use]+.5)*ww/base_w-.5, (v[use]+.5)*hh/base_h-.5
            x0, y0 = np.floor(uu).astype(np.int32), np.floor(vv).astype(np.int32)
            _require(np.all((x0 >= 0) & (y0 >= 0) & (x0+1 < ww) & (y0+1 < hh)),
                     'ACTIVE_SUPPORT_REQUIRES_CLAMPED_BILINEAR_SAMPLE')
            _require(np.all(complete_x[index][x0] & complete_x[index][x0+1]
                            & complete_y[index][y0] & complete_y[index][y0+1]),
                     'ACTIVE_SUPPORT_REQUIRES_TRUNCATED_MIP_FILTER')
            maximum = max(maximum, int(index))
    return maximum


def recompose_objects(source, candidate, object_alpha, view, *, scalar_shadow=None,
                      floor_allowed=None, enabled=False):
    """Return unreviewed native object replacement without a section-wide taper.

    Caller must separately establish complete objects, contacts, floor support,
    physical plausibility, optics and all normal acceptance gates. Nothing here
    supplies those assertions or promotes a held production record.
    """
    _require(enabled is True, 'EXPERIMENT_DISABLED')
    _require(sys.version_info[:2] == (3, 12) and np.__version__ == '2.5.4'
             and PILLOW_VERSION == '13.0.0', 'UNVALIDATED_FILTER_RUNTIME')
    _require(g.sha256(g.__file__) == GEOMETRY_SHA256, 'FROZEN_GEOMETRY_CHANGED')
    _require(source.dtype == candidate.dtype == object_alpha.dtype == np.uint8, 'EXPECTED_UINT8')
    _require(source.ndim == candidate.ndim == 3 and source.shape[2] == candidate.shape[2] == 3,
             'RGB_REQUIRED')
    size = (view['height'], view['width'])
    _require(candidate.shape[:2] == object_alpha.shape == size, 'SECTION_DIMENSION_MISMATCH')
    _require(all(math.isfinite(view[k]) for k in ['yaw_degrees', 'pitch_degrees', 'hfov_degrees'])
             and -90 <= view['pitch_degrees'] <= 90 and 30 <= view['hfov_degrees'] <= 130,
             'UNSUPPORTED_PROJECTION')
    h, w = source.shape[:2]
    _require(w == 2*h and w*h <= q.MAX_PIXELS, 'NATIVE_DIMENSION_LIMIT')
    shadow = np.zeros_like(object_alpha) if scalar_shadow is None else scalar_shadow
    _require(shadow.shape == size and shadow.dtype == np.uint8, 'SHADOW_SHAPE_MISMATCH')
    has_shadow = bool(np.any(shadow))
    if has_shadow:
        _require(floor_allowed is not None and floor_allowed.shape == size
                 and floor_allowed.dtype == np.uint8, 'SOURCE_FLOOR_REQUIRED')
        _require(not np.any((shadow > 0) & (floor_allowed != 255)), 'SHADOW_OUTSIDE_CERTAIN_FLOOR')
    support = np.maximum(object_alpha, shadow)
    margin = q.margin_stats(support)
    _require(margin['minimumMarginPixels'] >= 2, 'SUPPORT_TOUCHES_SOURCE_BORDER')
    colors, alphas = _premultiplied_mipmaps(candidate, object_alpha)
    shadows = g.mipmaps(shadow)
    floors = g.mipmaps(floor_allowed) if has_shadow else None
    complete_x = _axis_complete([a.shape[1] for a in alphas])
    complete_y = _axis_complete([a.shape[0] for a in alphas])
    maximum_visible_mip = 0
    for y0 in range(0, h, q.STRIP):
        x, y, z = g.sphere_rays(w, h, y0, min(h, y0+q.STRIP))
        u, v, weight = g.project_view(x, y, z, view)
        valid = weight > 0
        if np.any(valid):
            maximum_visible_mip = max(maximum_visible_mip, int(np.ceil(_lod(alphas, u, v)[valid].max())))
    guard_x = _source_clearance([a.shape[1] for a in alphas], maximum_visible_mip)
    guard_y = _source_clearance([a.shape[0] for a in alphas], maximum_visible_mip)
    left, top, right, bottom = margin['marginsPixelsLeftTopRightBottom']
    _require(min(left, right) >= guard_x and min(top, bottom) >= guard_y,
             'SOURCE_SUPPORT_INSIDE_FILTER_FOOTPRINT_GUARD_REQUIRED')
    result = np.empty_like(source)
    effective = np.zeros((h, w), np.uint8)
    objects, attenuation = np.zeros_like(effective), np.zeros_like(effective)
    max_mip = shadow_pixels = 0
    object_digest = hashlib.sha256(object_alpha.tobytes()).hexdigest()
    shadow_digest = hashlib.sha256(shadow.tobytes()).hexdigest()
    for y0 in range(0, h, q.STRIP):
        y1 = min(h, y0+q.STRIP)
        x, y, z = g.sphere_rays(w, h, y0, y1)
        u, v, section_weight = g.project_view(x, y, z, view)
        valid = section_weight > 0  # exact existing frustum; never opacity
        sampled_alpha = g.sample_antialiased(alphas, u, v, valid=valid)
        aa = np.rint(np.clip(sampled_alpha, 0, 1)*255).astype(np.uint8)
        ss = np.rint(np.clip(g.sample_antialiased(shadows, u, v, valid=valid), 0, 255)).astype(np.uint8)
        active = (aa > 0) | (ss > 0)
        max_mip = max(max_mip, _guard_sample_footprints(alphas, u, v, active, complete_x, complete_y))
        if has_shadow and np.any(ss):
            sampled_floor = g.sample_antialiased(floors, u, v, valid=valid)
            _require(np.all(sampled_floor[ss > 0] >= 255-1e-4), 'RESAMPLED_SHADOW_OUTSIDE_CERTAIN_FLOOR')
        a, s = aa.astype(np.float32)/255, ss.astype(np.float32)/255
        premultiplied = g.sample_antialiased(colors, u, v, valid=valid)
        foreground = np.zeros_like(premultiplied)
        np.divide(premultiplied, sampled_alpha[..., None], out=foreground,
                  where=sampled_alpha[..., None] > 0)
        foreground = np.clip(foreground, 0, 1)
        original = source[y0:y1]
        # Use the reported byte coverage for both terms, so quantization cannot
        # leave hidden changes outside effective support or alter object hue.
        mixed = g.to_linear(original)*(1-s[..., None])*(1-a[..., None]) + foreground*a[..., None]
        part = np.rint(g.from_linear(mixed)).clip(0, 255).astype(np.uint8)
        coverage = np.rint((1-(1-a)*(1-s))*255).astype(np.uint8)
        part[coverage == 0] = original[coverage == 0]
        shadow_pixels += int(np.count_nonzero((aa == 0) & (ss > 0)))
        result[y0:y1], effective[y0:y1] = part, coverage
        objects[y0:y1], attenuation[y0:y1] = aa, ss
    _require(object_digest == hashlib.sha256(object_alpha.tobytes()).hexdigest()
             and shadow_digest == hashlib.sha256(shadow.tobytes()).hexdigest(), 'INPUT_ALPHA_CHANGED')
    return result, effective, objects, attenuation, {
        'version': VERSION, 'status': 'unreviewed', 'automaticAcceptance': False,
        'sectionTaperApplied': False, 'nativeFloorResampled': False,
        'colorSampling': 'premultiplied_linear_light_float_mips',
        'zeroAlphaDonorColorUsed': False,
        'inputMasksChanged': False, 'supportClippedOrFilled': False,
        'sourceSupportMargin': margin, 'maximumActiveMipLevel': max_mip,
        'maximumVisibleMipLevel': maximum_visible_mip,
        'requiredSourceClearancePixels': [guard_x, guard_y],
        'samplingGuard': 'untruncated_recursive_lanczos3_and_unclamped_bilinear',
        'inputObjectAlphaPixelsSha256': object_digest, 'inputShadowPixelsSha256': shadow_digest,
        'shadowOnlyPixels': shadow_pixels,
        'limitation': 'Projection/sampling integrity only; no semantic, metric fit, contact or optical acceptance.'}
