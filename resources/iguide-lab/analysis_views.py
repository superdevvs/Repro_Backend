"""Bounded diagnostic views of the same 2K source. No provider calls or image editing."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import resource
import shutil
import tempfile

resource.setrlimit(resource.RLIMIT_AS, (1536 * 1024 * 1024, 1536 * 1024 * 1024))
from PIL import Image
from pano_geometry import extract, sha256, PIPELINE_VERSION

IDS = ['front', 'right', 'back', 'left', 'zenith', 'nadir']
ANGLES = {'front': (0, 0), 'right': (90, 0), 'back': (180, 0), 'left': (270, 0), 'zenith': (0, 90), 'nadir': (0, -90)}

def verify(manifest, source, expected, out):
    if manifest['source']['sha256'] != expected or manifest['pipeline_version'] != PIPELINE_VERSION or Path(manifest['source']['path']).resolve() != source:
        raise ValueError('Cached source or geometry version changed')
    if manifest.get('adapterSha256') != sha256(__file__) or manifest.get('geometrySha256') != sha256(Path(__file__).with_name('pano_geometry.py')):
        raise ValueError('Cached implementation fingerprint changed')
    if [v['id'] for v in manifest['views']] != IDS:
        raise ValueError('Unexpected diagnostic view set')
    for view in manifest['views']:
        path = Path(view['path']).resolve()
        if path != out / 'views' / (view['id'] + '.png') or view['width'] != 1024 or view['height'] != 1024 or view['hfov_degrees'] != 100:
            raise ValueError('Unexpected diagnostic view geometry')
        if (view['yaw_degrees'], view['pitch_degrees']) != ANGLES[view['id']]:
            raise ValueError('Diagnostic view orientation changed')
        if sha256(path) != view['sha256']:
            raise ValueError('Diagnostic view fingerprint changed')
        with Image.open(path) as image:
            if image.format != 'PNG' or image.size != (1024, 1024) or getattr(image, 'n_frames', 1) != 1:
                raise ValueError('Diagnostic image dimensions changed')
            image.load()
    if sha256(source) != expected:
        raise ValueError('Source fingerprint changed')
    return manifest

def run(request):
    if request.get('schemaVersion') != 1:
        raise ValueError('Unsupported request')
    source = Path(request['source']['path']).resolve()
    expected = request['source']['sha256']
    if not source.is_file() or source.stat().st_size > 24 * 1024 * 1024 or sha256(source) != expected:
        raise ValueError('Invalid prepared source')
    with Image.open(source) as image:
        if image.format != 'PNG' or image.size != (2048, 1024) or getattr(image, 'n_frames', 1) != 1:
            raise ValueError('Expected one exact 2K panorama PNG')
    out = Path(request['outputDirectory']).resolve()
    if out == source or out in source.parents:
        raise ValueError('Output collides with source')
    if out.exists():
        return verify(json.loads((out / 'manifest.json').read_text(encoding='utf-8')), source, expected, out)
    out.parent.mkdir(parents=True, exist_ok=True)
    temporary = Path(tempfile.mkdtemp(prefix='.analysis-views-', dir=out.parent)).resolve()
    try:
        manifest = extract(source, temporary, size=1024, hfov=100.0)
        for view in manifest['views']:
            view['path'] = str(out / 'views' / (view['id'] + '.png'))
        manifest['diagnosticOnly'] = True
        manifest['adapterSha256'] = sha256(__file__)
        manifest['geometrySha256'] = sha256(Path(__file__).with_name('pano_geometry.py'))
        (temporary / 'manifest.json').write_text(json.dumps(manifest, indent=2), encoding='utf-8')
        os.rename(temporary, out)
        return verify(manifest, source, expected, out)
    finally:
        if temporary.is_dir() and temporary.parent == out.parent and temporary.name.startswith('.analysis-views-'):
            shutil.rmtree(temporary)

def main():
    parser = argparse.ArgumentParser(); parser.add_argument('--request', required=True); args = parser.parse_args()
    try:
        path = Path(args.request)
        if path.stat().st_size > 65536:
            raise ValueError('Request too large')
        result = run(json.loads(path.read_text(encoding='utf-8-sig')))
        print(json.dumps({'status': 'completed', 'manifest': result}, separators=(',', ':')))
        return 0
    except (OSError, ValueError, KeyError, TypeError, MemoryError) as error:
        print(json.dumps({'status': 'failed', 'code': type(error).__name__}))
        return 1

if __name__ == '__main__':
    raise SystemExit(main())
