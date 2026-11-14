"""Source-bound local harmonization pre-step. No native decode, network or credentials."""
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import platform
import shutil
import tempfile

# Set before importing NumPy/SciPy: BLAS thread stacks also consume address space.
for _name in ['OPENBLAS_NUM_THREADS', 'OMP_NUM_THREADS', 'MKL_NUM_THREADS', 'NUMEXPR_NUM_THREADS']:
    os.environ[_name] = '1'

import numpy as np
import PIL
from PIL import Image

MODE = 'exact-domain-harmonic-v1'
VERSION = 'exact-domain-harmonic/1'
LIMITS = {'maxEditablePixels': 65536, 'maxClippedPixelFraction': 0.05, 'maxOvershoot': 64}
RUNTIME = {'pythonVersion': '3.12.3', 'numpyVersion': '2.5.4', 'pillowVersion': '13.0.0', 'scipyVersion': '1.18.1'}


def sha(path):
    h = hashlib.sha256()
    with Path(path).open('rb') as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b''): h.update(chunk)
    return h.hexdigest()


def checked(spec, root):
    if set(spec) != {'path', 'sha256'}: raise ValueError('Unexpected input field')
    path = Path(spec['path']).resolve(strict=True)
    if not path.is_file() or not path.is_relative_to(root) or path.stat().st_size > 30 * 1024 * 1024 or sha(path) != spec['sha256']:
        raise ValueError('Input path or fingerprint mismatch')
    return path


def pixels(path, mask=False):
    with Image.open(path) as im:
        if im.format != 'PNG' or im.size != (2048, 2048) or getattr(im, 'n_frames', 1) != 1 or im.info.get('icc_profile') or im.getexif().get(274, 1) != 1:
            raise ValueError('Exact plain 2048-square PNG required')
        if mask:
            if im.mode != 'L': raise ValueError('Normalized grayscale support required')
            a = np.asarray(im).copy()
            if any(np.any(edge) for edge in [a[:205], a[-205:], a[:, :205], a[:, -205:]]): raise ValueError('Mask touches projection guard')
        else:
            if im.mode not in ['RGB', 'RGBA'] or (im.mode == 'RGBA' and im.getchannel('A').getextrema() != (255, 255)):
                raise ValueError('Opaque RGB pixels required')
            a = np.asarray(im.convert('RGB')).copy()
        return a


def canonical(value): return json.dumps(value, sort_keys=True, separators=(',', ':'), allow_nan=False).encode()


def run(request, runtime_manifest):
    import scipy
    import section_harmonization as solver
    if set(request) != {'schemaVersion', 'privateRoot', 'source', 'candidate', 'mask', 'recipe', 'outputDirectory'} or request['schemaVersion'] != 1:
        raise ValueError('Unsupported local harmonization request')
    versions = {'pythonVersion': platform.python_version(), 'numpyVersion': np.__version__, 'pillowVersion': PIL.__version__, 'scipyVersion': scipy.__version__}
    if versions != RUNTIME: raise ValueError('Pinned local runtime changed')
    recipe = request['recipe']
    if set(recipe) != {'mode', 'recipeVersion', 'solverSha256', 'adapterSha256', 'runtimeManifestSha256', 'sourceCutSha256', 'limits'}:
        raise ValueError('Unsupported harmonization recipe')
    if (recipe['mode'] != MODE or recipe['recipeVersion'] != VERSION or solver.ALGORITHM_VERSION != VERSION or recipe['limits'] != LIMITS
            or recipe['solverSha256'] != sha(solver.__file__) or recipe['adapterSha256'] != sha(__file__)
            or recipe['sourceCutSha256'] != request['source']['sha256']):
        raise ValueError('Reserved local implementation changed')
    runtime_path = Path(runtime_manifest).resolve(strict=True)
    if not runtime_path.is_file() or runtime_path.stat().st_size > 65536 or sha(runtime_path) != recipe['runtimeManifestSha256']:
        raise ValueError('Reserved runtime manifest changed')
    runtime_record = json.loads(runtime_path.read_text(encoding='utf-8'))
    if (runtime_record.get('schemaVersion') != 1 or runtime_record.get('validationStatus') != 'passed'
            or any(runtime_record.get(key) != value for key, value in versions.items())):
        raise ValueError('Runtime validation record changed')
    root = Path(request['privateRoot']).resolve(strict=True)
    source = checked(request['source'], root); candidate = checked(request['candidate'], root); mask = checked(request['mask'], root)
    out = Path(request['outputDirectory']).resolve()
    if out == root or not out.is_relative_to(root) or any(out == p or out in p.parents for p in [source, candidate, mask]):
        raise ValueError('Output path collides with immutable input')
    identity = hashlib.sha256(canonical(request)).hexdigest()
    def verify(report):
        if report.get('requestSha256') != identity or report.get('recipe') != recipe or report.get('runtime') != versions:
            raise ValueError('Cached harmonization identity changed')
        if (report.get('automaticAcceptance') is not False or report.get('reviewStatus') != 'unreviewed'
                or not isinstance(report.get('diagnostics'), dict)):
            raise ValueError('Cached harmonization evidence incomplete')
        if report.get('status') == 'completed':
            if not isinstance(report.get('candidate'), dict) or not isinstance(report.get('clippingMask'), dict) or 'errorCode' in report:
                raise ValueError('Completed harmonization assets incomplete')
        elif report.get('status') == 'needs_attention':
            if not isinstance(report.get('errorCode'), str) or not report['errorCode'] or 'candidate' in report:
                raise ValueError('Rejected harmonization evidence incomplete')
        else:
            raise ValueError('Unexpected cached harmonization status')
        for name, filename in [('candidate', 'candidate.png'), ('clippingMask', 'clipping.png')]:
            if name in report:
                asset = report[name]; path = checked(asset, root)
                if path != out / filename: raise ValueError('Cached asset path changed')
                pixels(path, mask=name == 'clippingMask')
        return report
    if out.exists(): return verify(json.loads((out / 'report.json').read_text()))
    out.parent.mkdir(parents=True, exist_ok=True)
    temp = Path(tempfile.mkdtemp(prefix='.harmonize-', dir=out.parent))
    try:
        original = pixels(source); raw = pixels(candidate); support = pixels(mask, True)
        report = {'status': 'completed', 'recipe': recipe, 'runtime': versions, 'requestSha256': identity,
                  'sourceSha256': request['source']['sha256'], 'rawCandidateSha256': request['candidate']['sha256'],
                  'supportSha256': request['mask']['sha256'], 'automaticAcceptance': False, 'reviewStatus': 'unreviewed'}
        clipping = None
        try:
            corrected, diagnostics, clipping = solver.harmonize(original, raw, support)
            if np.any(corrected[support == 0] != original[support == 0]): raise ValueError('Protected source pixels changed')
            Image.fromarray(corrected).save(temp / 'candidate.png')
            report['candidate'] = {'path': str(out / 'candidate.png'), 'sha256': sha(temp / 'candidate.png')}
        except solver.HarmonizationError as error:
            report['status'] = 'needs_attention'; report['errorCode'] = error.code
            diagnostics = error.telemetry; clipping = getattr(error, 'clipping_mask', None)
        report['diagnostics'] = diagnostics
        if clipping is not None:
            if clipping.dtype != np.uint8 or clipping.shape != (2048, 2048) or np.any(clipping[support == 0]): raise ValueError('Invalid clipping diagnostic')
            Image.fromarray(clipping).save(temp / 'clipping.png')
            report['clippingMask'] = {'path': str(out / 'clipping.png'), 'sha256': sha(temp / 'clipping.png')}
        for spec in ['source', 'candidate', 'mask']: checked(request[spec], root)
        (temp / 'report.json').write_bytes(json.dumps(report, separators=(',', ':'), allow_nan=False).encode())
        if os.name == 'posix':
            os.chmod(temp, 0o2770)
            for path in temp.iterdir():
                if path.is_symlink() or not path.is_file(): raise ValueError('Unexpected harmonization artifact')
                os.chmod(path, 0o660)
        os.rename(temp, out)
        return verify(report)
    finally:
        if temp.is_dir() and temp.parent == out.parent and temp.name.startswith('.harmonize-'): shutil.rmtree(temp)


def main():
    parser = argparse.ArgumentParser(); parser.add_argument('--request', required=True); parser.add_argument('--runtime-manifest', required=True); args = parser.parse_args()
    try:
        import resource
        resource.setrlimit(resource.RLIMIT_AS, (1536 * 1024 ** 2, 1536 * 1024 ** 2))
        resource.setrlimit(resource.RLIMIT_CPU, (55, 55))
        path = Path(args.request)
        if path.stat().st_size > 65536: raise ValueError('Request too large')
        report = run(json.loads(path.read_text(encoding='utf-8-sig')), args.runtime_manifest)
        print(json.dumps(report, separators=(',', ':'), allow_nan=False)); return 0
    except (OSError, ValueError, KeyError, TypeError, MemoryError):
        print(json.dumps({'status': 'failed', 'errorCode': 'HARMONIZATION_VALIDATION_FAILED'})); return 1


if __name__ == '__main__': raise SystemExit(main())
