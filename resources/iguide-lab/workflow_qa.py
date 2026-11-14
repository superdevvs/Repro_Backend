"""Unattended, bounded geometry/QA primitives. No network or semantic acceptance.

Input images are immutable and hash-bound. A supplied mask is support, not proof
that a segmentation is correct. Generated RGB is unassociated with its alpha.
"""
from __future__ import annotations
import argparse
import hashlib
import json
import math
import os
from pathlib import Path
import platform
import shutil
import sys
import tempfile
import time

import numpy as np
from PIL import Image, ImageDraw, ImageFont, __version__ as PILLOW_VERSION

GEOMETRY_DIR = (Path(__file__).resolve().parent if (Path(__file__).resolve().parent/'pano_geometry.py').exists()
                else Path(__file__).resolve().parents[1] / 'geometry')
sys.path.insert(0, str(GEOMETRY_DIR))
import pano_geometry as g
import lighting_transfer as lighting
import global_lighting

VERSION = 'iguide-automation-geometry/1'
MAX_PIXELS = 64_000_000
MAX_BYTES = 256 * 1024 * 1024
PRESETS = {'grass', 'declutter', 'staging', 'twilight', 'golden-hour', 'lifestyle', 'interior-design'}
STRIP = 96


class WorkerError(ValueError):
    def __init__(self, code):
        super().__init__(code)
        self.code = code


def require(condition, code):
    if not condition:
        raise WorkerError(code)


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':'), allow_nan=False).encode()


def runtime():
    return {'python': platform.python_version(), 'numpy': np.__version__, 'pillow': PILLOW_VERSION,
            'platform': platform.system(), 'workerVersion': VERSION, 'workerSha256': g.sha256(__file__),
            'geometryVersion': g.PIPELINE_VERSION, 'geometrySha256': g.sha256(g.__file__),
            'lightingRecipe': lighting.RECIPE, 'lightingCodeSha256': g.sha256(lighting.__file__),
            'globalLightingCodeSha256': g.sha256(global_lighting.__file__)}


def validate_runtime():
    require(sys.version_info[:2] == (3,12) and np.__version__ == '2.5.4'
            and PILLOW_VERSION in {'12.3.0','13.0.0'}, 'UNVALIDATED_RUNTIME_VERSION')


def checked(spec, root, max_bytes=MAX_BYTES):
    require(set(spec) == {'path', 'sha256'}, 'INVALID_ASSET_DESCRIPTOR')
    path = Path(spec['path']).resolve(strict=True)
    require(path != root and path.is_relative_to(root) and path.is_file(), 'PRIVATE_PATH_INVALID')
    require(path.stat().st_size <= max_bytes, 'ASSET_TOO_LARGE')
    require(g.sha256(path) == spec['sha256'], 'INPUT_HASH_MISMATCH')
    return path


def read_image(path, grayscale=False, expected=None, panorama=False):
    with Image.open(path) as im:
        require(im.format in {'PNG', 'JPEG', 'WEBP'} and getattr(im, 'n_frames', 1) == 1, 'ENCODING_UNSUPPORTED')
        w, h = im.size
        require(64 <= w <= 16000 and 64 <= h <= 8000 and w * h <= MAX_PIXELS, 'IMAGE_DIMENSION_LIMIT')
        require(not expected or im.size == expected, 'DIMENSION_MISMATCH')
        require(not panorama or w == 2 * h, 'EXPECTED_EQUIRECTANGULAR_2_TO_1')
        require(im.getexif().get(274, 1) == 1 and not im.info.get('icc_profile'), 'NORMALIZATION_REQUIRED')
        require(im.mode in {'L', 'RGB', 'RGBA'}, 'COLOR_MODE_UNSUPPORTED')
        if im.mode == 'RGBA':
            require(im.getchannel('A').getextrema() == (255, 255), 'TRANSPARENT_IMAGE_UNSUPPORTED')
        if grayscale:
            require(im.format == 'PNG', 'MASK_MUST_BE_PNG')
            a = np.asarray(im.convert('RGB'))
            require(np.array_equal(a[:, :, 0], a[:, :, 1]) and np.array_equal(a[:, :, 0], a[:, :, 2]), 'MASK_MUST_BE_GRAYSCALE')
            return a[:, :, 0].copy()
        return np.asarray(im.convert('RGB')).copy()


def checked_geometry(path, root):
    raw = json.loads(path.read_text(encoding='utf-8'))
    require(isinstance(raw.get('views'), list) and len(raw['views']) == 1, 'EXPECTED_SINGLE_SECTION')
    checked({'path': raw['source']['path'], 'sha256': raw['source']['sha256']}, root)
    for v in raw['views']:
        checked({'path': v['path'], 'sha256': v['sha256']}, root)
    return g.read_manifest(path)


def margin_stats(mask):
    yy, xx = np.nonzero(mask)
    require(len(xx) > 0, 'EMPTY_SUPPORT')
    h, w = mask.shape
    margins = [int(xx.min()), int(yy.min()), int(w - 1 - xx.max()), int(h - 1 - yy.max())]
    return {'positivePixels': len(xx), 'boundsExclusive': [int(xx.min()), int(yy.min()), int(xx.max()+1), int(yy.max()+1)],
            'marginsPixelsLeftTopRightBottom': margins, 'minimumMarginPixels': min(margins),
            'minimumMarginFraction': min(margins[0]/w, margins[1]/h, margins[2]/w, margins[3]/h)}


def exact_preservation(source, candidate, alpha):
    require(source.shape == candidate.shape and source.shape[:2] == alpha.shape, 'ARRAY_SHAPE_MISMATCH')
    protected = changed = maximum = changed_total = 0
    for y in range(0, source.shape[0], STRIP):
        a, b, mask = source[y:y+STRIP], candidate[y:y+STRIP], alpha[y:y+STRIP] == 0
        diff = np.abs(a.astype(np.int16) - b.astype(np.int16))
        protected += int(mask.sum())
        changed += int(np.any(diff[mask] > 0, axis=-1).sum())
        maximum = max(maximum, int(diff[mask].max(initial=0)))
        changed_total += int(np.any(diff > 0, axis=-1).sum())
    return {'protectedPixels': protected, 'outsideAlphaChangedPixels': changed,
            'outsideAlphaMaxChannelDifference': maximum, 'changedPixels': changed_total,
            'positiveAlphaPixels': int(np.count_nonzero(alpha)), 'passesExactExterior': changed == 0}


def _metric(a, b):
    d = np.abs(a.astype(np.float32) - b.astype(np.float32))
    return {'maeRGB': float(d.mean()), 'p95ChannelDifference': float(np.percentile(d, 95)), 'maxChannelDifference': float(d.max(initial=0))}


def _rim(a, b, support):
    # Mask transitions in both axes. Longitude wraps; latitude does not.
    values = []
    for axis in [0, 1]:
        nxt_a, nxt_b = np.roll(a, -1, axis), np.roll(b, -1, axis)
        crossing = support != np.roll(support, -1, axis)
        if axis == 0:
            crossing[-1] = False
        source_grad = nxt_a.astype(np.float32) - a.astype(np.float32)
        candidate_grad = nxt_b.astype(np.float32) - b.astype(np.float32)
        values.append(np.abs(candidate_grad - source_grad)[crossing].mean(axis=-1))
    values = np.concatenate(values)
    return {'crossingPairs': int(values.size), 'meanExtraGradientRGB': float(values.mean()) if values.size else 0.,
            'p95ExtraGradientRGB': float(np.percentile(values, 95)) if values.size else 0.,
            'maxExtraGradientRGB': float(values.max(initial=0))}


def conservative_support(alpha, width, height):
    """Any-positive footprint pooling; a one-pixel edit cannot disappear."""
    sh, sw = alpha.shape
    left = np.floor(np.arange(width)*sw/width).astype(int)
    right = np.ceil(np.arange(1, width+1)*sw/width).astype(int)
    result = np.zeros((height, width), bool)
    for y in range(height):
        top, bottom = math.floor(y*sh/height), math.ceil((y+1)*sh/height)
        row = np.any(alpha[top:bottom] > 0, axis=0)
        counts = np.concatenate([[0], np.cumsum(row, dtype=np.int32)])
        result[y] = counts[right] > counts[left]
    return result


def qa_arrays(source, candidate, alpha, preset, diagnostic_width=2048):
    require(preset in PRESETS, 'UNKNOWN_PRESET')
    require(source.dtype == candidate.dtype == np.uint8 and (alpha is None or alpha.dtype == np.uint8), 'EXPECTED_UINT8')
    require(alpha is None or source.shape == candidate.shape, 'ARRAY_SHAPE_MISMATCH')
    if alpha is None:
        changed = (sum(int(np.any(source[y:y+STRIP] != candidate[y:y+STRIP], axis=-1).sum()) for y in range(0, len(source), STRIP))
                   if source.shape == candidate.shape else None)
        proof = {'applicability': 'not_applicable', 'reason': 'Explicit entire_view edit scope; no protected-pixel support or pixel-lock claim.',
            'protectedPixels': None, 'outsideAlphaChangedPixels': None, 'outsideAlphaMaxChannelDifference': None,
            'passesExactExterior': None, 'changedPixels': changed, 'positiveAlphaPixels': None}
    else:
        proof = exact_preservation(source, candidate, alpha)
        proof['applicability'] = 'applicable'
    width = min(diagnostic_width, source.shape[1], candidate.shape[1])
    a = g.resize_rgb(source, width) if width != source.shape[1] else source
    b = g.resize_rgb(candidate, width) if width != candidate.shape[1] else candidate
    support = conservative_support(alpha, a.shape[1], a.shape[0]) if alpha is not None else None
    source_seam, candidate_seam = g.seam_metric(a), g.seam_metric(b)
    seam_delta = (b[:, 0].astype(np.float32)-b[:, -1].astype(np.float32)) - (a[:, 0].astype(np.float32)-a[:, -1].astype(np.float32))
    wrap = {'source': source_seam, 'candidate': candidate_seam,
            'sourceRelativeJumpMAE': float(np.abs(seam_delta).mean()),
            'sourceRelativeJumpP95': float(np.percentile(np.abs(seam_delta), 95))}
    ea, eb = g.edge_map(a), g.edge_map(b)
    edge_threshold = 24
    source_edges, candidate_edges = ea > edge_threshold, eb > edge_threshold
    nearest = g.dilate(candidate_edges, 2)
    missing = source_edges & ~nearest
    edge = {'diagnosticWidth': width, 'threshold': edge_threshold, 'searchRadiusPixels': 2,
            'sourceEdgePixels': int(source_edges.sum()), 'unmatchedSourceEdges': int(missing.sum()),
            'unmatchedSourceEdgeFraction': float(missing.sum()/max(1, source_edges.sum())),
            'interpretation': 'Edit-dependent diagnostic only; new objects, material changes and lighting can change edges.'}
    poles = {}
    for name, pitch in [('zenith', 90), ('nadir', -90)]:
        sa = g.perspective(a, 0, pitch, 100, (256, 256))
        cb = g.perspective(b, 0, pitch, 100, (256, 256))
        poles[name] = _metric(sa, cb)
        poles[name]['interpretation'] = 'Source-relative difference, not a polar-artifact detector or defect verdict.'
    reasons = []
    if proof['passesExactExterior'] is False:
        reasons.append({'code': 'PROTECTED_SOURCE_CHANGED', 'kind': 'integrity_failure', 'blocking': True})
    if wrap['sourceRelativeJumpMAE'] > 12:
        reasons.append({'code': 'WRAP_CHANGE_REVIEW', 'kind': 'visual_flag', 'blocking': False})
    rim = _rim(a, b, support) if support is not None else {'applicability': 'not_applicable', 'reason': 'No bounded edit rim in entire_view scope.'}
    if rim.get('p95ExtraGradientRGB', 0) > 24:
        reasons.append({'code': 'SUPPORT_RIM_REVIEW', 'kind': 'visual_flag', 'blocking': False})
    if edge['unmatchedSourceEdgeFraction'] > .25:
        reasons.append({'code': 'EDGE_CHANGE_REVIEW', 'kind': 'visual_flag', 'blocking': False})
    interpretation = {
        'staging': 'New object edges are expected. Source-floor contact, scale, circulation, reflections and masks need semantic review.',
        'lifestyle': 'New anatomy edges are expected. Check actual feet, foreground occlusion, body identity and reflections from actual placement.',
        'declutter': 'Target edges should disappear. Check hidden-surface reconstruction, target-attributable reflections and support rims.',
        'interior-design': 'Material-only edits should preserve folds and object silhouettes. Furniture replacement needs separate identity/placement checks.',
        'grass': 'Check soil versus turf, property extent, leaves/stems and wrap coverage. Protected pixels alone do not prove correct lawn support.',
        'twilight': 'Broad photometric differences are expected. Check shadow attenuation, glazing, lamp identity and pole-safe illumination.',
        'golden-hour': 'Broad photometric differences are expected. Check source obstructions, plausible light direction, glass and retained texture.'}[preset]
    return {'reviewStatus': 'unreviewed', 'automaticAcceptance': False,
            'numericIntegrity': 'fail' if proof['passesExactExterior'] is False else 'pass',
            'editScope': 'entire_view' if alpha is None else 'masked',
            'sourcePreservation': proof, 'edge': edge, 'rim': rim, 'wrap': wrap, 'poles': poles,
            'presetInterpretation': interpretation, 'reviewReasons': reasons,
            'thresholdMeaning': 'Experimental triage flags only, never calibrated image-quality or semantic acceptance.',
            'limitations': ['A zero exterior difference does not detect missing reflections or wrong occlusion inside the support.',
                'No metric proves multi-camera consistency, complete target coverage, plausible anatomy or physically correct lighting.']}


def recompose_arrays(source, candidate, object_alpha, view, mode='replacement', source_cut=None,
                     scalar_shadow=None, floor_allowed=None):
    require(mode in {'replacement', 'source-residual'}, 'INVALID_COMPOSITION_MODE')
    require(candidate.dtype == object_alpha.dtype == source.dtype == np.uint8, 'EXPECTED_UINT8')
    require(candidate.shape[:2] == object_alpha.shape == (view['height'], view['width']), 'SECTION_DIMENSION_MISMATCH')
    shadow = np.zeros_like(object_alpha) if scalar_shadow is None else scalar_shadow
    require(shadow.shape == object_alpha.shape and shadow.dtype == np.uint8, 'SHADOW_SHAPE_MISMATCH')
    if np.any(shadow):
        require(floor_allowed is not None and floor_allowed.shape == shadow.shape, 'SOURCE_FLOOR_SUPPORT_REQUIRED')
        require(not np.any((shadow > 0) & (floor_allowed != 255)), 'SHADOW_OUTSIDE_CERTAIN_FLOOR')
    support = np.maximum(object_alpha, shadow)
    margin = margin_stats(support)
    require(margin['minimumMarginFraction'] >= .10, 'SUPPORT_TOUCHES_SECTION_TAPER')
    if mode == 'source-residual':
        require(source_cut is not None and source_cut.shape == candidate.shape, 'EXACT_SOURCE_CUT_REQUIRED')
    h, w = source.shape[:2]
    require(w == 2*h and w*h <= MAX_PIXELS, 'NATIVE_DIMENSION_LIMIT')
    colors, alphas, shadows = g.mipmaps(candidate), g.mipmaps(object_alpha), g.mipmaps(shadow)
    cut_levels = g.mipmaps(source_cut) if mode == 'source-residual' else None
    result = np.empty_like(source)
    effective = np.zeros((h, w), np.uint8)
    applied_object, applied_shadow = np.zeros_like(effective), np.zeros_like(effective)
    clipped = shadow_only = shadow_mismatch = 0
    for y0 in range(0, h, STRIP):
        y1 = min(y0+STRIP, h)
        x, y, z = g.sphere_rays(w, h, y0, y1)
        u, v, weight = g.project_view(x, y, z, view)
        valid = weight > 0
        aa = np.rint(np.clip(g.sample_antialiased(alphas, u, v, valid=valid)*weight, 0, 255)).astype(np.uint8)
        ss = np.rint(np.clip(g.sample_antialiased(shadows, u, v, valid=valid)*weight, 0, 255)).astype(np.uint8)
        a, s = aa.astype(np.float32)/255, ss.astype(np.float32)/255
        rgb = g.sample_antialiased(colors, u, v, valid=valid)
        original = source[y0:y1]
        lin = g.to_linear(original)
        if mode == 'replacement':
            mixed = lin*(1-s[..., None])*(1-a[..., None]) + g.to_linear(rgb)*a[..., None]
        else:
            cut = g.sample_antialiased(cut_levels, u, v, valid=valid)
            mixed = lin*(1-s[..., None]) + (g.to_linear(rgb)-g.to_linear(cut))*a[..., None]
        clipped += int(np.any((mixed < 0) | (mixed > 1), axis=-1).sum())
        part = np.rint(g.from_linear(mixed)).clip(0, 255).astype(np.uint8)
        coverage = np.rint((1-(1-a)*(1-s))*255).astype(np.uint8)
        part[coverage == 0] = original[coverage == 0]
        only_shadow = (aa == 0) & (ss > 0)
        expected = np.rint(g.from_linear(lin*(1-s[..., None]))).clip(0, 255).astype(np.uint8)
        shadow_only += int(only_shadow.sum())
        shadow_mismatch += int(np.any(part[only_shadow] != expected[only_shadow], axis=-1).sum())
        result[y0:y1], effective[y0:y1] = part, coverage
        applied_object[y0:y1], applied_shadow[y0:y1] = aa, ss
    return result, effective, applied_object, applied_shadow, {
        'cropMargin': margin, 'clippedNativePixels': clipped, 'shadowOnlyPixels': shadow_only,
        'shadowOnlyFormulaMismatchPixels': shadow_mismatch, 'mode': mode, 'alphaAppliedOnce': True,
        'nativeFloorResampled': False, 'generatedDetailDimensions': [candidate.shape[1], candidate.shape[0]],
        'nativeCanvasDimensions': [w, h]}


def _save(path, array):
    Image.fromarray(array).save(path, format='PNG', compress_level=3)


def _descriptor(path, final):
    with Image.open(path) as im:
        return {'path': str(final), 'sha256': g.sha256(path), 'dimensions': list(im.size)}


def build_evidence(request):
    """Ordered, paired source/candidate evidence. Native inputs feed all view cuts."""
    allowed = {'schemaVersion', 'operation', 'privateRoot', 'source', 'candidate', 'preset', 'outputDirectory', 'editScope'}
    require(request.get('editScope') in {'masked', 'entire_view'}, 'EDIT_SCOPE_REQUIRED')
    if request['editScope'] == 'masked':
        allowed |= {'effectiveAlpha'}
    require(allowed.issubset(request) and set(request) <= allowed | {'details'}, 'REQUEST_FIELDS_INVALID')
    require(request['preset'] in PRESETS, 'UNKNOWN_PRESET')
    root = Path(request['privateRoot']).resolve(strict=True)
    out = Path(request['outputDirectory']).resolve()
    require(out != root and out.is_relative_to(root), 'OUTPUT_PATH_INVALID')
    sources = {key: checked(request[key], root) for key in ['source', 'candidate']}
    if 'effectiveAlpha' in request:
        sources['effectiveAlpha'] = checked(request['effectiveAlpha'], root)
    details = request.get('details', [])
    require(isinstance(details, list) and len(details) <= 2, 'MAX_TWO_DETAIL_SECTIONS')
    specs = []
    for index, detail in enumerate(details):
        require(set(detail) == {'geometry'}, 'DETAIL_FIELDS_INVALID')
        path = checked(detail['geometry'], root, 65536)
        m = checked_geometry(path, root)
        require(Path(m['source']['path']).resolve() == sources['source'] and m['source']['sha256'] == request['source']['sha256'], 'DETAIL_NOT_BOUND_TO_SOURCE')
        require(len(m['views']) == 1, 'DETAIL_REQUIRES_ONE_VIEW')
        v = m['views'][0]
        sources[f'detail_{index+1}-geometry'] = path
        sources[f'detail_{index+1}-cut'] = checked({'path': v['path'], 'sha256': v['sha256']}, root)
        specs.append(v)
    require(not any(out == p or out in p.parents for p in sources.values()), 'OUTPUT_COLLIDES_WITH_INPUT')
    identity = runtime()
    fingerprint = hashlib.sha256(canonical({'request': request, 'runtime': identity})).hexdigest()
    ids = ['overview', 'front', 'right', 'back', 'left', 'poles', 'wrap'] + [f'detail_{i+1}' for i in range(len(details))]
    if out.exists():
        report = json.loads((out/'report.json').read_text())
        require(report.get('requestFingerprint') == fingerprint and report.get('status') == 'completed'
                and report.get('reviewStatus') == 'unreviewed' and report.get('automaticAcceptance') is False,
                'CACHE_IDENTITY_MISMATCH')
        require(isinstance(report.get('qa'), dict) and report.get('editScope') == request['editScope']
                and report.get('sourceSha256') == request['source']['sha256'] and report.get('candidateSha256') == request['candidate']['sha256'], 'CACHE_REPORT_INVALID')
        require([b.get('id') for b in report.get('evidence', [])] == ids, 'CACHE_EVIDENCE_ORDER_INVALID')
        for board in report['evidence']:
            for artifact in [board, *board['halves']]:
                p = Path(artifact['path']).resolve(strict=True)
                require(p.is_relative_to(out) and g.sha256(p) == artifact['sha256'], 'CACHE_ASSET_MISMATCH')
                with Image.open(p) as im:
                    require(list(im.size) == artifact['dimensions'], 'CACHE_DIMENSION_MISMATCH')
        return report
    start = time.monotonic()
    before = {key: g.sha256(p) for key, p in sources.items()}
    original = read_image(sources['source'], panorama=True)
    candidate = read_image(sources['candidate'], expected=(original.shape[1], original.shape[0]) if request['editScope'] == 'masked' else None, panorama=True)
    alpha = read_image(sources['effectiveAlpha'], grayscale=True, expected=(original.shape[1], original.shape[0])) if 'effectiveAlpha' in sources else None
    qa = qa_arrays(original, candidate, alpha, request['preset'])
    inputs = [('source', original), ('candidate', candidate)]
    levels = {role: g.mipmaps(array) for role, array in inputs}
    out.parent.mkdir(parents=True, exist_ok=True)
    temp = Path(tempfile.mkdtemp(prefix='.automation-evidence-', dir=out.parent))
    font = ImageFont.load_default(size=20)
    small = ImageFont.load_default(size=16)
    boards = []
    try:
        for board_id in ids:
            halves = []
            if board_id == 'overview':
                shape, board_height = (1020, 984), 1024
                pose = {'projection': 'equirectangular', 'yaw': None, 'pitch': None, 'hFov': 360, 'vFov': 180}
                pose['imageContentRectangleWithinHalf'] = [0, 237, 1020, 747]
                pose['paddingWithinHalf'] = {'top': 237, 'bottom': 237, 'left': 0, 'right': 0}
                rendered = []
                for _, a in inputs:
                    panel = Image.new('RGB', shape, '#171b20')
                    panel.paste(Image.fromarray(g.resize_rgb(a, 1020)), (0, 237))
                    rendered.append(np.asarray(panel))
            elif board_id == 'wrap':
                shape, board_height = (1020, 984), 1024
                pose = {'projection': 'rectilinear', 'yaw': 180, 'pitch': 0, 'hFov': 100,
                        'vFov': math.degrees(2*math.atan(math.tan(math.radians(50))*984/1020)),
                        'longitudeBoundaryCentered': True, 'convention': g.CONVENTION}
                rendered = [g.perspective(a, 180, 0, 100, shape, levels=levels[role]) for role, a in inputs]
            elif board_id == 'poles':
                shape, board_height = (1020, 984), 1024
                pose = {'projection': 'paired_rectilinear_poles', 'yaw': 0, 'pitch': [90, -90], 'hFov': 100, 'vFov': 100,
                        'tileDimensions': [470, 470], 'tileRectanglesWithinHalf': [[275,22,745,492],[275,514,745,984]],
                        'paddingHorizontalWithinHalf': [275,275], 'convention': g.CONVENTION}
                rendered = []
                for role, a in inputs:
                    panel = Image.new('RGB', shape, '#171b20'); d = ImageDraw.Draw(panel)
                    for row, (label, pitch) in enumerate([('ZENITH / UP', 90), ('NADIR / DOWN', -90)]):
                        yy = row*492
                        d.text((16, yy+1), label, font=small, fill='white')
                        tile = g.perspective(a, 0, pitch, 100, (470, 470), levels=levels[role])
                        panel.paste(Image.fromarray(tile), (275, yy+22))
                    rendered.append(np.asarray(panel))
            else:
                shape, board_height = (1020, 984), 1024
                if board_id.startswith('detail_'):
                    v = specs[int(board_id.split('_')[1])-1]
                    yaw, pitch, hfov = v['yaw_degrees'], v['pitch_degrees'], v['hfov_degrees']
                else:
                    yaw, pitch, hfov = {'front': 0, 'right': 90, 'back': 180, 'left': 270}[board_id], 0, 100
                pose = {'projection': 'rectilinear', 'yaw': yaw, 'pitch': pitch, 'hFov': hfov,
                        'vFov': math.degrees(2*math.atan(math.tan(math.radians(hfov/2))*984/1020)), 'convention': g.CONVENTION}
                if board_id.startswith('detail_'):
                    pose['sourceGeometrySha256'] = g.sha256(sources[board_id+'-geometry'])
                    pose['nativePixelsDirectlySampled'] = True
                rendered = [g.perspective(a, yaw, pitch, hfov, shape, levels=levels[role]) for role, a in inputs]
            board_width = shape[0]*2+8
            canvas = Image.new('RGB', (board_width, board_height), '#171b20'); draw = ImageDraw.Draw(canvas)
            for index, ((role, a), pixels) in enumerate(zip(inputs, rendered)):
                label = 'SOURCE LEFT' if role == 'source' else 'CANDIDATE RIGHT'
                xpos = index*(shape[0]+8)
                draw.text((xpos+12, 9), label+' / '+board_id.upper(), font=font, fill='white')
                canvas.paste(Image.fromarray(pixels), (xpos, 40))
                name = f'{board_id}-{role}-half.png'
                _save(temp/name, pixels)
                half = _descriptor(temp/name, out/name)
                half.update({'role': role, 'originalAssetSha256': request[role]['sha256'],
                    'originalAssetDimensions': [a.shape[1], a.shape[0]], 'pose': pose,
                    'contentRectangleInBoard': [xpos, 40, xpos+shape[0], 40+shape[1]],
                    'generatedFromSamePanoramaPosition': True})
                halves.append(half)
            name = f'{board_id}.png'; canvas.save(temp/name, compress_level=3)
            require((temp/name).stat().st_size <= 24*1024*1024, 'EVIDENCE_BOARD_TOO_LARGE')
            entry = _descriptor(temp/name, out/name)
            entry.update({'id': board_id, 'order': len(boards), 'mime': 'image/png', 'bytes': (temp/name).stat().st_size,
                          'gutterPixels': 8, 'sourceLeftCandidateRight': True, 'halves': halves})
            boards.append(entry)
        require(all(g.sha256(p) == before[k] for k, p in sources.items()), 'INPUT_CHANGED_DURING_WORK')
        report = {'schemaVersion': 1, 'status': 'completed', 'operation': 'build-evidence',
            'requestFingerprint': fingerprint, 'runtime': identity, 'reviewStatus': 'unreviewed',
            'automaticAcceptance': False, 'providerCalls': 0, 'preset': request['preset'],
            'sourceSha256': request['source']['sha256'], 'candidateSha256': request['candidate']['sha256'],
            'evidence': boards, 'qa': qa, 'editScope': request['editScope'], 'inputHashes': before, 'elapsedMs': round((time.monotonic()-start)*1000),
            'limitations': ['Same panorama camera position only; no independent-camera or cross-view claim.',
                'Evidence images are views for semantic evaluation, not automatic acceptance.',
                'All rectilinear boards sample supplied native inputs directly; overview is intentionally downsampled.']}
        (temp/'report.json').write_text(json.dumps(report, indent=2, allow_nan=False))
        if os.name == 'posix':
            os.chmod(temp, 0o2770)
            for p in temp.rglob('*'):
                os.chmod(p, 0o2770 if p.is_dir() else 0o660)
        os.rename(temp, out)
        return report
    finally:
        if temp.exists() and temp.parent == out.parent and temp.name.startswith('.automation-evidence-'):
            shutil.rmtree(temp)


def transfer_lighting(request):
    allowed = {'schemaVersion','operation','privateRoot','source','guidanceSource','candidate','preset','outputDirectory',
               'editScope','recipe','sourcePreparation'}
    require(set(request) == allowed, 'REQUEST_FIELDS_INVALID')
    require(request['preset'] in {'golden-hour','twilight'} and request['editScope'] == 'entire_view', 'LIGHTING_SCOPE_INVALID')
    is_global = request['operation'] == 'transfer-lighting-global'
    selected_recipe = global_lighting.VERSION if is_global else lighting.RECIPE
    require(request['recipe'] == selected_recipe, 'LIGHTING_RECIPE_UNSUPPORTED')
    prep = request['sourcePreparation']
    require(set(prep) == {'nativeSourceSha256','guidanceSourceSha256','preparerSha256','method','verifiedByCaller'}, 'SOURCE_PREPARATION_DESCRIPTOR_INVALID')
    require(prep['nativeSourceSha256'] == request['source']['sha256'] and prep['guidanceSourceSha256'] == request['guidanceSource']['sha256']
            and prep['method'] == 'LabImages::prepared/GD' and prep['verifiedByCaller'] is True
            and isinstance(prep['preparerSha256'],str) and len(prep['preparerSha256']) == 64
            and all(c in '0123456789abcdef' for c in prep['preparerSha256']), 'SOURCE_PREPARATION_BINDING_INVALID')
    root = Path(request['privateRoot']).resolve(strict=True)
    assets = {key:checked(request[key],root) for key in ['source','guidanceSource','candidate']}
    out = Path(request['outputDirectory']).resolve()
    require(out != root and out.is_relative_to(root) and not any(out == p or out in p.parents for p in assets.values()), 'OUTPUT_PATH_INVALID')
    identity = runtime()
    fingerprint = hashlib.sha256(canonical({'request':request,'runtime':identity})).hexdigest()
    if out.exists():
        report = json.loads((out/'report.json').read_text())
        require(report.get('requestFingerprint') == fingerprint and report.get('status') == 'completed'
                and report.get('reviewStatus') == 'unreviewed' and report.get('automaticAcceptance') is False, 'CACHE_IDENTITY_MISMATCH')
        require(report.get('operation') == request['operation'] and report.get('recipe') == selected_recipe
                and report.get('runtime') == identity and report.get('sourceSha256') == request['source']['sha256']
                and report.get('candidateSha256') == request['candidate']['sha256']
                and report.get('guidanceSourceSha256') == request['guidanceSource']['sha256']
                and report.get('sourcePreparation') == prep and report.get('editScope') == 'entire_view'
                and report.get('providerCalls') == 0, 'CACHE_EXECUTION_BINDING_INVALID')
        require(set(report.get('artifacts',{})) == {'native','preview','lighting-field'} and isinstance(report.get('qa'),dict), 'CACHE_ARTIFACT_SET_INVALID')
        for item in report['artifacts'].values():
            p=Path(item['path']).resolve(strict=True)
            require(p.is_relative_to(out) and g.sha256(p)==item['sha256'], 'CACHE_ASSET_MISMATCH')
        return report
    start=time.monotonic()
    source=read_image(assets['source'],panorama=True)
    guide=read_image(assets['guidanceSource'],expected=(2048,1024),panorama=True)
    donor=read_image(assets['candidate'],expected=(2048,1024),panorama=True)
    estimation = None
    if is_global:
        offset, estimation = global_lighting.derive_offset(guide, donor, g.to_linear)
        field = np.broadcast_to(offset, (64, 128, 3)).copy()
    else:
        field=lighting.derive_field(guide,donor,g.to_linear)
    result,telemetry=lighting.apply_field(source,field,g)
    require(telemetry['nonfiniteChannels'] == 0 and telemetry['policyProtectedNadirChangedPixels'] == 0
            and telemetry['preclipLinearMinMax'][0] >= -1e-6 and telemetry['preclipLinearMinMax'][1] <= 1+1e-6,
            'LIGHTING_NUMERICAL_INVARIANT_FAILED')
    qa=qa_arrays(source,result,None,request['preset'])
    require(all(g.sha256(p)==request[key]['sha256'] for key,p in assets.items()), 'INPUT_CHANGED_DURING_WORK')
    out.parent.mkdir(parents=True,exist_ok=True)
    temp=Path(tempfile.mkdtemp(prefix='.automation-lighting-',dir=out.parent))
    try:
        _save(temp/'native.png',result)
        _save(temp/'preview.png',g.resize_rgb(result,2048))
        np.save(temp/'lighting-field.npy',field,allow_pickle=False)
        artifacts={name:_descriptor(temp/f'{name}.png',out/f'{name}.png') for name in ['native','preview']}
        artifacts['lighting-field']={'path':str(out/'lighting-field.npy'),'sha256':g.sha256(temp/'lighting-field.npy'),
            'dimensions':[field.shape[1],field.shape[0],3],'dtype':'float32','role':'Global constant color offset; no generated pixels copied.' if is_global else 'Low-frequency guidance only; no generated RGB pixels copied.'}
        report={'schemaVersion':1,'status':'completed','operation':request['operation'],'recipe':selected_recipe,
            'requestFingerprint':fingerprint,'runtime':identity,'reviewStatus':'unreviewed','automaticAcceptance':False,
            'providerCalls':0,'editScope':'entire_view','preset':request['preset'],'sourceSha256':request['source']['sha256'],
            'candidateSha256':request['candidate']['sha256'],'guidanceSourceSha256':request['guidanceSource']['sha256'],
            'sourcePreparation':prep,'sourcePreparationEvidence':'Exact preparation relation asserted and checked by trusted PHP caller; this worker checks asset hashes, not a different resampler.',
            'parameters':({**global_lighting.PARAMETERS, 'nadirPreserveBelowElevationDegrees': -80.0, 'nadirFadeBeginsElevationDegrees': -70.0} if is_global else lighting.PARAMETERS),
            'estimation':estimation,'telemetry':telemetry,'qa':qa,'artifacts':artifacts,
            'fieldMinMax':[float(field.min()),float(field.max())], 'elapsedMs':round((time.monotonic()-start)*1000),
            'kind':'derived_source_detail_lighting_composite',
            'limitations':['Lighting is an artistic inference, not calibrated radiance or recovered material properties.',
                ('A single global color offset cannot reproduce directional lighting or guarantee visible preset intent; visual optics and appearance checks remain mandatory.' if is_global else 'Smooth lighting can retain unsupported broad shadow/light patterns from the donor; visual optics/obstruction checks remain mandatory.'),
                'Only source spatial detail is used, but RGB and local contrast change. No pixel-identical texture or whole-image pixel lock claim.',
                'Fixed nadir preservation can leave source-colored capture surroundings; it does not identify every scanner artifact.',
                'No lamp-emission synthesis, new sky structure, body reflections or cross-camera consistency is supplied by this operation.']}
        (temp/'report.json').write_text(json.dumps(report,indent=2,allow_nan=False))
        if os.name=='posix':
            os.chmod(temp,0o2770)
            for p in temp.rglob('*'):
                os.chmod(p,0o2770 if p.is_dir() else 0o660)
        os.rename(temp,out)
        return report
    finally:
        if temp.exists() and temp.parent==out.parent and temp.name.startswith('.automation-lighting-'):
            shutil.rmtree(temp)


def run(request):
    start = time.monotonic()
    validate_runtime()
    require(request.get('schemaVersion') == 1, 'SCHEMA_VERSION_UNSUPPORTED')
    op = request.get('operation')
    if op == 'build-evidence':
        return build_evidence(request)
    if op in {'transfer-lighting', 'transfer-lighting-global'}:
        return transfer_lighting(request)
    require(op in {'qa-panorama', 'recompose-section'}, 'OPERATION_UNSUPPORTED')
    allowed = {'schemaVersion', 'operation', 'privateRoot', 'source', 'candidate', 'preset', 'outputDirectory'}
    if op == 'qa-panorama':
        require(request.get('editScope') in {'masked', 'entire_view'}, 'EDIT_SCOPE_REQUIRED')
        allowed |= {'editScope'}
        if request['editScope'] == 'masked':
            allowed |= {'effectiveAlpha'}
    else:
        allowed |= {'geometry', 'objectAlpha', 'mode'}
    optional = set() if op == 'qa-panorama' else {'scalarShadow', 'floorAllowedAlpha'}
    require(allowed.issubset(request) and set(request) <= allowed | optional, 'REQUEST_FIELDS_INVALID')
    require(request['preset'] in PRESETS, 'UNKNOWN_PRESET')
    root = Path(request['privateRoot']).resolve(strict=True)
    out = Path(request['outputDirectory']).resolve()
    require(out != root and out.is_relative_to(root), 'OUTPUT_PATH_INVALID')
    assets = {key: checked(request[key], root, 65536 if key == 'geometry' else MAX_BYTES)
              for key in ['source', 'candidate', 'effectiveAlpha', 'geometry', 'objectAlpha', 'scalarShadow', 'floorAllowedAlpha'] if key in request}
    if op == 'recompose-section':
        m = checked_geometry(assets['geometry'], root)
        require(Path(m['source']['path']).resolve() == assets['source'] and m['source']['sha256'] == request['source']['sha256'], 'GEOMETRY_SOURCE_MISMATCH')
        require(len(m['views']) == 1, 'EXPECTED_SINGLE_SECTION')
        view = m['views'][0]
        assets['sourceCut'] = checked({'path': view['path'], 'sha256': view['sha256']}, root)
    require(not any(out == path or out in path.parents for path in assets.values()), 'OUTPUT_COLLIDES_WITH_INPUT')
    identity = runtime()
    fingerprint = hashlib.sha256(canonical({'request': request, 'runtime': identity})).hexdigest()
    if out.exists():
        report = json.loads((out/'report.json').read_text())
        require(report.get('requestFingerprint') == fingerprint and report.get('reviewStatus') == 'unreviewed'
                and report.get('automaticAcceptance') is False, 'CACHE_IDENTITY_MISMATCH')
        require(report.get('status') == 'completed' and isinstance(report.get('qa'), dict), 'CACHE_REPORT_INVALID')
        expected_keys = {'preview', 'qa-wrap'} | {f'qa-{name}' for name, _, _ in g.FACES}
        if op == 'recompose-section':
            expected_keys |= {'native', 'effective-alpha', 'object-alpha', 'shadow-strength'}
        require(set(report.get('artifacts', {})) == expected_keys, 'CACHE_ARTIFACT_SET_INVALID')
        for item in report['artifacts'].values():
            p = Path(item['path']).resolve(strict=True)
            require(p.is_relative_to(out) and g.sha256(p) == item['sha256'], 'CACHE_ASSET_MISMATCH')
        return report
    source = read_image(assets['source'], panorama=True)
    candidate = read_image(assets['candidate'], expected=(source.shape[1], source.shape[0]) if op == 'qa-panorama' and request['editScope'] == 'masked' else None,
                           panorama=op == 'qa-panorama')
    composition = None
    if op == 'qa-panorama':
        alpha = read_image(assets['effectiveAlpha'], grayscale=True, expected=(source.shape[1], source.shape[0])) if 'effectiveAlpha' in assets else None
        result = candidate
    else:
        cut_path = assets['sourceCut']
        size = (view['width'], view['height'])
        require(candidate.shape[:2] == (size[1], size[0]) and size == (2048, 2048), 'EXPECTED_2048_SQUARE_SECTION')
        if request['mode'] == 'source-residual':
            require(request['preset'] in {'interior-design', 'grass'}, 'RESIDUAL_NOT_ALLOWED_FOR_OBJECT_REMOVAL_OR_INSERTION')
        obj = read_image(assets['objectAlpha'], grayscale=True, expected=size)
        shadow = read_image(assets['scalarShadow'], grayscale=True, expected=size) if 'scalarShadow' in assets else None
        floor = read_image(assets['floorAllowedAlpha'], grayscale=True, expected=size) if 'floorAllowedAlpha' in assets else None
        cut = read_image(cut_path, expected=size)
        result, alpha, object_native, shadow_native, composition = recompose_arrays(source, candidate, obj, view,
            request['mode'], cut, shadow, floor)
    qa = qa_arrays(source, result, alpha, request['preset'])
    for key, p in assets.items():
        if key != 'sourceCut':
            require(g.sha256(p) == request[key]['sha256'], 'INPUT_CHANGED_DURING_WORK')
    if op == 'recompose-section':
        require(g.sha256(assets['sourceCut']) == view['sha256'], 'INPUT_CHANGED_DURING_WORK')
    out.parent.mkdir(parents=True, exist_ok=True)
    temp = Path(tempfile.mkdtemp(prefix='.automation-', dir=out.parent))
    try:
        artifacts = {}
        if op == 'recompose-section':
            for name, array in [('native', result), ('effective-alpha', alpha), ('object-alpha', object_native), ('shadow-strength', shadow_native)]:
                p = temp/f'{name}.png'; _save(p, array); artifacts[name] = _descriptor(p, out/p.name)
        diagnostic_width = min(2048, result.shape[1], source.shape[1])
        preview = g.resize_rgb(result, diagnostic_width)
        _save(temp/'preview.png', preview)
        artifacts['preview'] = _descriptor(temp/'preview.png', out/'preview.png')
        # Matched views expose semantic failures without pretending to classify them.
        base = g.resize_rgb(source, diagnostic_width)
        for name, yaw, pitch in g.FACES:
            pair = np.concatenate([g.perspective(base, yaw, pitch, 100, (512, 512)),
                                   g.perspective(preview, yaw, pitch, 100, (512, 512))], axis=1)
            p = temp/f'qa-{name}.png'; _save(p, pair); artifacts[f'qa-{name}'] = _descriptor(p, out/p.name)
        seam = np.concatenate([np.concatenate([base[:, -128:], base[:, :128]], axis=1),
                               np.concatenate([preview[:, -128:], preview[:, :128]], axis=1)], axis=1)
        _save(temp/'qa-wrap.png', seam); artifacts['qa-wrap'] = _descriptor(temp/'qa-wrap.png', out/'qa-wrap.png')
        report = {'schemaVersion': 1, 'status': 'completed', 'operation': op, 'requestFingerprint': fingerprint,
            'reviewStatus': 'unreviewed', 'automaticAcceptance': False, 'runtime': identity,
            'sourceSha256': request['source']['sha256'], 'candidateSha256': request['candidate']['sha256'],
            'inputHashes': {key: g.sha256(p) for key, p in assets.items()}, 'preset': request['preset'],
            'qa': qa, 'composition': composition, 'artifacts': artifacts,
            'elapsedMs': round((time.monotonic()-start)*1000), 'providerCalls': 0,
            'qualityDecision': 'No semantic pass inferred. Integrity failures and visual flags are separate.'}
        (temp/'report.json').write_text(json.dumps(report, indent=2, allow_nan=False))
        if os.name == 'posix':
            os.chmod(temp, 0o2770)
            for p in temp.rglob('*'):
                os.chmod(p, 0o2770 if p.is_dir() else 0o660)
        os.rename(temp, out)
        return report
    finally:
        if temp.exists() and temp.parent == out.parent and temp.name.startswith('.automation-'):
            shutil.rmtree(temp)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--request', required=True)
    args = parser.parse_args()
    try:
        if os.name == 'posix':
            import resource
            resource.setrlimit(resource.RLIMIT_AS, (3*1024**3, 3*1024**3))
            resource.setrlimit(resource.RLIMIT_CPU, (220, 220))
        p = Path(args.request)
        require(p.stat().st_size <= 65536, 'REQUEST_TOO_LARGE')
        report = run(json.loads(p.read_text(encoding='utf-8-sig')))
        print(json.dumps(report, separators=(',', ':')))
        return 0
    except (WorkerError, OSError, ValueError, KeyError, TypeError, MemoryError) as exc:
        print(json.dumps({'status': 'failed', 'code': exc.code if isinstance(exc, WorkerError) else type(exc).__name__,
                          'automaticAcceptance': False, 'providerCalls': 0}))
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
