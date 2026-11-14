"""Private, source-bound single-section geometry and explicit-alpha recomposition. No network."""
from __future__ import annotations

import argparse
import hashlib
import json
import math
import os
from pathlib import Path
import shutil
import tempfile

import numpy as np
from PIL import Image
import pano_geometry as geometry

VERSION = 'iguide-section-adapter/1'
MAX_PIXELS = 64_000_000
EXPECTED_SIZE = (2048, 2048)


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':'), allow_nan=False).encode()


def inside(path, root, exists=True):
    path = Path(path).resolve(strict=exists)
    if path == root or not path.is_relative_to(root):
        raise ValueError('Private path escaped its project')
    return path


def checked(spec, root, max_bytes=30 * 1024 * 1024):
    path = inside(spec['path'], root)
    if not path.is_file() or path.stat().st_size > max_bytes or geometry.sha256(path) != spec['sha256']:
        raise ValueError('Input fingerprint mismatch')
    return path


def image_shape(path, required=None, panorama=False):
    with Image.open(path) as image:
        width, height = image.size
        if image.format not in ['PNG', 'JPEG', 'WEBP'] or getattr(image, 'n_frames', 1) != 1:
            raise ValueError('Unsupported image encoding')
        if not 64 <= width <= 16000 or not 64 <= height <= 8000 or width * height > MAX_PIXELS:
            raise ValueError('Image dimensions exceed the adapter limit')
        if required and image.size != required:
            raise ValueError('Exact image dimensions changed')
        if panorama and width != 2 * height:
            raise ValueError('Expected an exact spherical source')
        if image.getexif().get(274, 1) != 1 or image.info.get('icc_profile'):
            raise ValueError('Orientation or color profile requires an explicit normalized source')
        if image.mode not in ['RGB', 'L', 'RGBA']:
            raise ValueError('Unsupported color mode')
        if image.mode == 'RGBA' and image.getchannel('A').getextrema() != (255, 255):
            raise ValueError('Transparent source pixels are unsupported')
        return width, height


def mask_pixels(path, size, section):
    image_shape(path, required=size)
    with Image.open(path) as image:
        if image.format != 'PNG':
            raise ValueError('Support must be PNG')
        rgb = np.asarray(image.convert('RGB'))
        if not np.array_equal(rgb[:, :, 0], rgb[:, :, 1]) or not np.array_equal(rgb[:, :, 0], rgb[:, :, 2]):
            raise ValueError('Support must have equal grayscale channels')
        mask = rgb[:, :, 0].copy()
    if not np.any(mask):
        raise ValueError('Support is empty')
    if section:
        # Core projection tapers outer20% of each half-frame = outer10% of the full cut.
        border = math.ceil(size[0] / 10)
        if np.any(mask[:border]) or np.any(mask[-border:]) or np.any(mask[:, :border]) or np.any(mask[:, -border:]):
            raise ValueError('Support reaches the cut taper; widen or reposition the section')
    return mask


def view_spec(view):
    if set(view) != {'yaw', 'pitch', 'hFov'}:
        raise ValueError('Unknown section controls')
    for key, low, high in [('yaw', -180, 180), ('pitch', -90, 90), ('hFov', 30, 130)]:
        value = view[key]
        if isinstance(value, bool) or not isinstance(value, (int, float)) or not math.isfinite(value) or not low <= value <= high:
            raise ValueError('Section angle is out of range')
    return {'id': 'section', 'yaw_degrees': view['yaw'], 'pitch_degrees': view['pitch'],
            'hfov_degrees': view['hFov'], 'width': 2048, 'height': 2048}


def descriptor(path, destination, name):
    with Image.open(path) as image:
        size = image.size
        image.load()
    return {'path': str(destination / name), 'sha256': geometry.sha256(path), 'width': size[0], 'height': size[1]}


def verify_result(report, request_hash, out, root):
    if report.get('requestSha256') != request_hash or report.get('adapterSha256') != geometry.sha256(__file__) or report.get('geometryCodeSha256') != geometry.sha256(geometry.__file__):
        raise ValueError('Cached request or implementation changed')
    for artifact in report['artifacts'].values():
        path = checked(artifact, root, 256 * 1024 * 1024)
        if not path.is_relative_to(out):
            raise ValueError('Cached result path changed')
        if 'width' in artifact:
            with Image.open(path) as image:
                if image.format != 'PNG' or image.size != (artifact['width'], artifact['height']):
                    raise ValueError('Cached image dimensions changed')
                image.load()
    return report


def run(request):
    if request.get('schemaVersion') != 1 or request.get('operation') not in ['extract', 'compose-section', 'compose-grass']:
        raise ValueError('Unsupported geometry request')
    operation = request['operation']
    allowed = {'schemaVersion', 'operation', 'privateRoot', 'source', 'outputDirectory'}
    allowed |= {'view'} if operation == 'extract' else {'candidate', 'mask'}
    if operation == 'compose-section':
        allowed |= {'geometry'}
    if set(request) != allowed:
        raise ValueError('Unexpected geometry request fields')
    root = Path(request['privateRoot']).resolve(strict=True)
    source = checked(request['source'], root)
    dimensions = image_shape(source, panorama=True)
    out = inside(request['outputDirectory'], root, exists=False)
    if out == source or out in source.parents:
        raise ValueError('Output cannot contain its source')
    inputs = [source]
    if operation == 'extract':
        spec = view_spec(request['view'])
    else:
        candidate = checked(request['candidate'], root)
        mask = checked(request['mask'], root, 8 * 1024 * 1024)
        inputs += [candidate, mask]
        expected = EXPECTED_SIZE if operation == 'compose-section' else (2048, 1024)
        image_shape(candidate, required=expected)
        support = mask_pixels(mask, expected, operation == 'compose-section')
        if operation == 'compose-grass' and dimensions != expected:
            raise ValueError('Grass source must be the exact prepared2K PNG')
        if operation == 'compose-section':
            manifest_path = checked(request['geometry'], root, 65536)
            manifest = json.loads(manifest_path.read_text(encoding='utf-8'))
            if manifest.get('adapterSha256') != geometry.sha256(__file__) or manifest.get('geometrySha256') != geometry.sha256(geometry.__file__):
                raise ValueError('Section implementation fingerprint changed')
            if Path(manifest['source']['path']).resolve() != source or manifest['source']['sha256'] != request['source']['sha256']:
                raise ValueError('Generation source and composition base must be identical')
            if len(manifest['views']) != 1 or manifest['views'][0]['id'] != 'section':
                raise ValueError('Expected one section')
            cut = manifest['views'][0]
            checked(cut, root); image_shape(cut['path'], required=EXPECTED_SIZE)
            if [manifest['source']['width'], manifest['source']['height']] != list(dimensions):
                raise ValueError('Native source dimensions changed')
            spec = view_spec({'yaw': cut['yaw_degrees'], 'pitch': cut['pitch_degrees'], 'hFov': cut['hfov_degrees']})
            if any(cut[key] != value for key, value in spec.items()):
                raise ValueError('Section geometry mismatch')
            inputs += [manifest_path, Path(cut['path'])]
    if any(out == p or out in p.parents for p in inputs):
        raise ValueError('Output collides with an input')
    request_hash = hashlib.sha256(canonical(request)).hexdigest()
    if out.exists():
        return verify_result(json.loads((out / 'report.json').read_text()), request_hash, out, root)
    out.parent.mkdir(parents=True, exist_ok=True)
    temporary = Path(tempfile.mkdtemp(prefix='.section-', dir=out.parent))
    if os.name == 'posix':
        # The parent is the Studio setgid directory shared by worker and PHP-FPM.
        # mkdtemp otherwise leaves a0700 result that HTTP cannot inspect after rename.
        os.chmod(temporary, 0o2770)
    try:
        report = {'status': 'completed', 'version': VERSION, 'operation': operation, 'requestSha256': request_hash,
                  'adapterSha256': geometry.sha256(__file__), 'geometryCodeSha256': geometry.sha256(geometry.__file__),
                  'geometryVersion': geometry.PIPELINE_VERSION, 'coordinateConvention': geometry.CONVENTION,
                  'sourceSha256': request['source']['sha256'], 'sourceDimensions': list(dimensions),
                  'automaticAcceptance': False, 'reviewStatus': 'unreviewed', 'artifacts': {}}
        if operation == 'extract':
            manifest = geometry.extract(source, temporary / 'cut', view_specs=[spec])
            manifest['adapterSha256'] = report['adapterSha256']; manifest['geometrySha256'] = report['geometryCodeSha256']
            manifest['views'][0]['path'] = str(out / 'cut/views/section.png')
            manifest_path = temporary / 'cut/manifest.json'
            manifest_path.write_text(json.dumps(manifest, indent=2), encoding='utf-8')
            report['artifacts']['cut'] = descriptor(temporary / 'cut/views/section.png', out, 'cut/views/section.png')
            report['artifacts']['geometry'] = {'path': str(out / 'cut/manifest.json'), 'sha256': geometry.sha256(manifest_path)}
            report['view'] = request['view']
        else:
            native_path = temporary / 'native.png'; alpha_path = temporary / 'alpha.png'
            if operation == 'compose-section':
                result = geometry.reproject(manifest_path, native_path,
                    {'views': [{'view_id': 'section', 'image_path': str(candidate), 'mask_path': str(mask)}]}, dimensions[0], blend_mode='replacement')
                if result['changed_pixels_outside_coverage'] != 0:
                    raise ValueError('Protected native source pixels changed')
                Path(result['coverage_alpha_path']).rename(alpha_path)
                # The core's private report is retained as evidence; paths refer to the temporary execution stage.
                report['statistics'] = {'outsideAlphaChangedPixels': 0, 'outsideAlphaPixels': result['outside_coverage_pixels']}
            else:
                original = geometry.rgb(source); edited = geometry.rgb(candidate)
                a = support.astype(np.float32)[:, :, None] / 255
                pixels = np.rint(original.astype(np.float32) * (1 - a) + edited.astype(np.float32) * a).clip(0, 255).astype(np.uint8)
                pixels[support == 0] = original[support == 0]
                if np.any(pixels[support == 0] != original[support == 0]):
                    raise ValueError('Protected preview source pixels changed')
                geometry.save_new(Image.fromarray(pixels), native_path); geometry.save_new(Image.fromarray(support), alpha_path)
                report['statistics'] = {'outsideAlphaChangedPixels': 0, 'outsideAlphaPixels': int(np.count_nonzero(support == 0)),
                                        'suppliedAlphaPreservedExactly': True}
            with Image.open(native_path) as image:
                preview = image.resize((2048, 1024), Image.Resampling.LANCZOS) if image.size != (2048, 1024) else image.copy()
                geometry.save_new(preview, temporary / 'preview.png')
            report['artifacts']['native'] = descriptor(native_path, out, 'native.png')
            report['artifacts']['preview'] = descriptor(temporary / 'preview.png', out, 'preview.png')
            report['artifacts']['alpha'] = descriptor(alpha_path, out, 'alpha.png')
            report['candidateSha256'] = request['candidate']['sha256']; report['maskSha256'] = request['mask']['sha256']
            report['generatedDetailDimensions'] = list(expected)
            report['limitations'] = ['Native canvas size does not restore native generated detail.',
                                    'Source preservation does not prove semantic correctness, natural appearance or cross-view consistency.']
        if geometry.sha256(source) != request['source']['sha256']:
            raise ValueError('Source changed during processing')
        if operation != 'extract':
            if geometry.sha256(candidate) != request['candidate']['sha256'] or geometry.sha256(mask) != request['mask']['sha256']:
                raise ValueError('Input changed during processing')
        geometry.write_json(temporary / 'report.json', report)
        if os.name == 'posix':
            for path in temporary.rglob('*'):
                if path.is_symlink():
                    raise ValueError('Unexpected symlink in generated artifacts')
                os.chmod(path, 0o2770 if path.is_dir() else 0o660)
        os.rename(temporary, out)
        return verify_result(report, request_hash, out, root)
    finally:
        if temporary.is_dir() and temporary.parent == out.parent and temporary.name.startswith('.section-'):
            # Only this adapter-created scratch directory is cleaned; immutable inputs and final artifacts are untouched.
            shutil.rmtree(temporary)


def main():
    parser = argparse.ArgumentParser(); parser.add_argument('--request', required=True); args = parser.parse_args()
    try:
        import resource
        resource.setrlimit(resource.RLIMIT_AS, (3 * 1024 ** 3, 3 * 1024 ** 3))
        resource.setrlimit(resource.RLIMIT_CPU, (220, 220))
        path = Path(args.request)
        if path.stat().st_size > 65536:
            raise ValueError('Request too large')
        result = run(json.loads(path.read_text(encoding='utf-8-sig')))
        print(json.dumps(result, separators=(',', ':')))
        return 0
    except (OSError, ValueError, KeyError, TypeError, MemoryError):
        print(json.dumps({'status': 'failed', 'code': 'GEOMETRY_VALIDATION_FAILED'}))
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
