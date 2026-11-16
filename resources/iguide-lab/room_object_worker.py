"""Draft furniture into the native panorama with the opaque object compositor.

Inputs are hash-bound: the native source, one 2048 square furniture draft of a single extracted
section, and 1-3 machine "furniture" masks of that draft. Nothing here judges the furniture; the
result is unreviewed and is only ever shown with its caveats until the independent final check.

Fallback ladder: opaque objects with the compositor's own small filter clearance, then the same
objects edge-faded just inside the draft border, then (when the request carries the layout's planned
furniture and floor boxes) the whole draft inside that region, edge-faded at the old 10% margin.
The last step needs no furniture mask, so a paid draft with no usable mask still gets a composite.
Without a region, only an empty mask set fails.
"""
from __future__ import annotations
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import sys
import tempfile
import time

import numpy as np
from PIL import Image, ImageFilter

sys.path.insert(0, str(Path(__file__).resolve().parent))
import workflow_qa as q  # noqa: E402
import object_layer_compositor as c  # noqa: E402

g = q.g
VERSION = 'room-object-worker/1'
OPERATION = 'compose-room-objects'
MAX_MASKS = 3
FADE_PIXELS = 48
SECTION = (2048, 2048)
GUARD_FAILURES = ('SOURCE_SUPPORT_INSIDE_FILTER_FOOTPRINT_GUARD_REQUIRED', 'SUPPORT_TOUCHES_SOURCE_BORDER',
                  'ACTIVE_SUPPORT_REQUIRES_CLAMPED_BILINEAR_SAMPLE', 'ACTIVE_SUPPORT_REQUIRES_TRUNCATED_MIP_FILTER')
FIELDS = {'schemaVersion', 'operation', 'privateRoot', 'source', 'candidate', 'preset', 'outputDirectory', 'geometry', 'objectMasks'}
REGION_FIELD = 'draftRegion'
MAX_REGION_BOXES = 8
REGION_CLEARANCE = 206
REGION_FEATHER = 24


def object_runtime():
    here = Path(__file__).resolve()
    return {'objectWorkerVersion': VERSION, 'objectWorkerSha256': g.sha256(here),
            'compositorVersion': c.VERSION, 'compositorSha256': g.sha256(here.parent / 'object_layer_compositor.py')}


def union_alpha(masks):
    """Union of the machine masks with smoothed contours (no stair steps) and a soft anti-aliased rim."""
    union = np.zeros(masks[0].shape, np.uint8)
    for mask in masks:
        union = np.maximum(union, np.where(mask > 127, 255, 0).astype(np.uint8))
    q.require(np.any(union), 'EMPTY_OBJECT_MASK')
    smooth = np.asarray(Image.fromarray(union).filter(ImageFilter.GaussianBlur(1.5)))
    smooth = np.where(smooth >= 128, 255, 0).astype(np.uint8)
    q.require(np.any(smooth), 'EMPTY_OBJECT_MASK')
    return np.asarray(Image.fromarray(smooth).filter(ImageFilter.GaussianBlur(.7))).copy()


def grade(candidate, source_cut, alpha):
    """The draft re-renders the whole room brighter/warmer; remove that measured shift from the furniture."""
    outside = np.asarray(Image.fromarray(alpha).filter(ImageFilter.MaxFilter(31))) == 0
    if np.count_nonzero(outside) < 4096:
        return candidate, [1., 1., 1.]
    draft, original = g.to_linear(candidate)[outside], g.to_linear(source_cut)[outside]
    gain = np.clip(original.mean(axis=0) / np.maximum(draft.mean(axis=0), 1e-4), .7, 1.3)
    graded = np.rint(g.from_linear(np.clip(g.to_linear(candidate) * gain, 0, 1))).clip(0, 255).astype(np.uint8)
    return graded, [round(float(x), 4) for x in gain]


def edge_fade(alpha, clearance):
    h, w = alpha.shape
    yy, xx = np.mgrid[0:h, 0:w]
    distance = np.minimum(np.minimum(xx, w - 1 - xx), np.minimum(yy, h - 1 - yy)).astype(np.float32)
    ramp = np.clip((distance - clearance) / FADE_PIXELS, 0, 1)
    return np.rint(alpha.astype(np.float32) * ramp).astype(np.uint8)


def checked_region(region):
    """Planned furniture and floor boxes [x0, y0, x1, y1] on the 2048 draft; integers only."""
    q.require(isinstance(region, list) and 1 <= len(region) <= MAX_REGION_BOXES and all(
        isinstance(b, list) and len(b) == 4 and all(type(n) is int for n in b)
        and 0 <= b[0] < b[2] <= SECTION[0] and 0 <= b[1] < b[3] <= SECTION[1] for b in region), 'DRAFT_REGION_INVALID')
    return region


def region_alpha(region):
    """The whole draft inside the planned region, feathered, and faded at the old 10% margin (always fits)."""
    m = np.zeros((SECTION[1], SECTION[0]), np.uint8)
    for x0, y0, x1, y1 in region:
        m[y0:y1, x0:x1] = 255
    soft = np.asarray(Image.fromarray(m).filter(ImageFilter.GaussianBlur(REGION_FEATHER)))
    faded = edge_fade(soft, REGION_CLEARANCE)
    q.require(np.any(faded), 'EMPTY_OBJECT_MASK')
    return faded


def compose(source, candidate, alpha, view, region=None):
    """Objects first; on a clearance failure, fade the objects just inside the draft border and retry;
    if that still cannot place them (or there is no usable mask), use the draft region when one was given."""
    last = None
    if alpha is not None:
        try:
            return c.recompose_objects(source, candidate, alpha, view, enabled=True), 'objects', 0
        except ValueError as error:
            if str(error) not in GUARD_FAILURES:
                raise
        # 32 px covers the filter guard up to mip level 2; 206 px is the old 10% section margin, which always fits.
        for clearance in (32, 206):
            faded = edge_fade(alpha, clearance)
            if region is not None and not np.any(faded):
                break
            q.require(np.any(faded), 'EMPTY_OBJECT_MASK')
            try:
                return c.recompose_objects(source, candidate, faded, view, enabled=True), 'edge_faded', clearance
            except ValueError as error:
                if str(error) not in GUARD_FAILURES:
                    raise
                last = error
        if region is None:
            raise last
    return c.recompose_objects(source, candidate, region_alpha(region), view, enabled=True), 'draft_region', REGION_CLEARANCE


def run(request):
    start = time.monotonic()
    q.validate_runtime()
    q.require(isinstance(request, dict) and set(request) in (FIELDS, FIELDS | {REGION_FIELD}) and request['schemaVersion'] == 1
              and request['operation'] == OPERATION, 'REQUEST_FIELDS_INVALID')
    q.require(request['preset'] in q.PRESETS, 'UNKNOWN_PRESET')
    region = checked_region(request[REGION_FIELD]) if REGION_FIELD in request else None
    masks = request['objectMasks']
    q.require(isinstance(masks, list) and (0 if region else 1) <= len(masks) <= MAX_MASKS, 'OBJECT_MASK_COUNT')
    root = Path(request['privateRoot']).resolve(strict=True)
    out = Path(request['outputDirectory']).resolve()
    q.require(out != root and out.is_relative_to(root), 'OUTPUT_PATH_INVALID')
    assets = {key: q.checked(request[key], root, 65536 if key == 'geometry' else q.MAX_BYTES) for key in ['source', 'candidate', 'geometry']}
    mask_paths = [q.checked(mask, root) for mask in masks]
    manifest = q.checked_geometry(assets['geometry'], root)
    q.require(Path(manifest['source']['path']).resolve() == assets['source'] and manifest['source']['sha256'] == request['source']['sha256'],
              'GEOMETRY_SOURCE_MISMATCH')
    view = manifest['views'][0]
    cut_path = q.checked({'path': view['path'], 'sha256': view['sha256']}, root)
    q.require(not any(out == p or out in p.parents for p in [*assets.values(), *mask_paths, cut_path]), 'OUTPUT_COLLIDES_WITH_INPUT')
    identity, objects_identity = q.runtime(), object_runtime()
    fingerprint = hashlib.sha256(q.canonical({'request': request, 'runtime': identity, 'objectRuntime': objects_identity})).hexdigest()
    expected = {'native', 'effective-alpha', 'object-alpha', 'shadow-strength', 'preview', 'qa-wrap'} | {f'qa-{n}' for n, _, _ in g.FACES}
    if out.exists():
        report = json.loads((out / 'report.json').read_text())
        q.require(report.get('requestFingerprint') == fingerprint and report.get('status') == 'completed'
                  and report.get('automaticAcceptance') is False and set(report.get('artifacts', {})) == expected, 'CACHE_IDENTITY_MISMATCH')
        for item in report['artifacts'].values():
            p = Path(item['path']).resolve(strict=True)
            q.require(p.is_relative_to(out) and g.sha256(p) == item['sha256'], 'CACHE_ASSET_MISMATCH')
        return report
    source = q.read_image(assets['source'], panorama=True)
    q.require((view['width'], view['height']) == SECTION, 'EXPECTED_2048_SQUARE_SECTION')
    candidate = q.read_image(assets['candidate'], expected=SECTION)
    alpha = None
    if mask_paths:
        try:
            alpha = union_alpha([q.read_image(p, grayscale=True, expected=SECTION) for p in mask_paths])
        except q.WorkerError as error:
            if region is None or str(error) != 'EMPTY_OBJECT_MASK':
                raise
    candidate, gain = grade(candidate, q.read_image(cut_path, expected=SECTION), alpha if alpha is not None else region_alpha(region))
    (result, effective, object_native, shadow_native, compositor), fallback, clearance = compose(source, candidate, alpha, view, region)
    qa = q.qa_arrays(source, result, effective, request['preset'])
    for key, p in assets.items():
        q.require(g.sha256(p) == request[key]['sha256'], 'INPUT_CHANGED_DURING_WORK')
    for p, mask in zip(mask_paths, masks):
        q.require(g.sha256(p) == mask['sha256'], 'INPUT_CHANGED_DURING_WORK')
    # Same composition fields as the existing recompose step, so stored room composites keep one shape.
    composition = {'cropMargin': compositor['sourceSupportMargin'], 'clippedNativePixels': 0, 'shadowOnlyPixels': compositor['shadowOnlyPixels'],
                   'shadowOnlyFormulaMismatchPixels': 0, 'mode': 'replacement', 'alphaAppliedOnce': True, 'nativeFloorResampled': False,
                   'generatedDetailDimensions': list(SECTION), 'nativeCanvasDimensions': [source.shape[1], source.shape[0]]}
    objects = {'fallback': fallback, 'edgeFadeClearancePixels': clearance, 'edgeFadePixels': FADE_PIXELS if clearance else 0,
               'maskCount': len(masks), 'draftRegion': region, 'contactShadow': False, 'colorGain': gain, 'compositor': compositor}
    out.parent.mkdir(parents=True, exist_ok=True)
    temp = Path(tempfile.mkdtemp(prefix='.automation-', dir=out.parent))
    try:
        artifacts = {}
        for name, array in [('native', result), ('effective-alpha', effective), ('object-alpha', object_native), ('shadow-strength', shadow_native)]:
            p = temp / f'{name}.png'; q._save(p, array); artifacts[name] = q._descriptor(p, out / p.name)
        width = min(2048, result.shape[1])
        preview = g.resize_rgb(result, width); q._save(temp / 'preview.png', preview)
        artifacts['preview'] = q._descriptor(temp / 'preview.png', out / 'preview.png')
        base = g.resize_rgb(source, width)
        for name, yaw, pitch in g.FACES:
            pair = np.concatenate([g.perspective(base, yaw, pitch, 100, (512, 512)), g.perspective(preview, yaw, pitch, 100, (512, 512))], axis=1)
            p = temp / f'qa-{name}.png'; q._save(p, pair); artifacts[f'qa-{name}'] = q._descriptor(p, out / p.name)
        seam = np.concatenate([np.concatenate([base[:, -128:], base[:, :128]], axis=1),
                               np.concatenate([preview[:, -128:], preview[:, :128]], axis=1)], axis=1)
        q._save(temp / 'qa-wrap.png', seam); artifacts['qa-wrap'] = q._descriptor(temp / 'qa-wrap.png', out / 'qa-wrap.png')
        report = {'schemaVersion': 1, 'status': 'completed', 'operation': OPERATION, 'requestFingerprint': fingerprint,
                  'reviewStatus': 'unreviewed', 'automaticAcceptance': False, 'runtime': identity, 'objectRuntime': objects_identity,
                  'sourceSha256': request['source']['sha256'], 'candidateSha256': request['candidate']['sha256'],
                  'inputHashes': {key: g.sha256(p) for key, p in assets.items()}, 'maskHashes': [m['sha256'] for m in masks],
                  'preset': request['preset'], 'qa': qa, 'composition': composition, 'objects': objects, 'artifacts': artifacts,
                  'elapsedMs': round((time.monotonic() - start) * 1000), 'providerCalls': 0,
                  'qualityDecision': 'No semantic pass inferred. Furniture is an unreviewed draft until the final check.'}
        (temp / 'report.json').write_text(json.dumps(report, indent=2, allow_nan=False))
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
            resource.setrlimit(resource.RLIMIT_AS, (4 * 1024**3, 4 * 1024**3))
            resource.setrlimit(resource.RLIMIT_CPU, (540, 540))
        p = Path(args.request)
        q.require(p.stat().st_size <= 65536, 'REQUEST_TOO_LARGE')
        print(json.dumps(run(json.loads(p.read_text(encoding='utf-8-sig'))), separators=(',', ':')))
        return 0
    except (q.WorkerError, OSError, ValueError, KeyError, TypeError, MemoryError) as exc:
        print(json.dumps({'status': 'failed', 'code': exc.code if isinstance(exc, q.WorkerError) else type(exc).__name__,
                          'automaticAcceptance': False, 'providerCalls': 0}))
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
