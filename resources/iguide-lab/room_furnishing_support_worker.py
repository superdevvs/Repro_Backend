"""Private profile support adapter. No network, composition or acceptance."""
from __future__ import annotations
import argparse
import hashlib
import json
import os
from pathlib import Path
import platform
import tempfile
for _key in ('OMP_NUM_THREADS', 'OPENBLAS_NUM_THREADS', 'MKL_NUM_THREADS', 'NUMEXPR_NUM_THREADS'):
    os.environ[_key] = '1'
import numpy as np
from PIL import Image, __version__ as PILLOW_VERSION
import bedroom_support
import staging_support
import staging_support_worker as files

VERSION = 'interior-profile-support-file-adapter/1'
PROFILES = {'empty-bedroom-platform-three-piece/1': ('bed', 'nightstand_left', 'nightstand_right'),
            'empty-living-three-piece/1': ('sofa', 'coffee_table', 'chair')}


def execute(request, allowed_root):
    root = Path(allowed_root).resolve()
    if not root.is_dir(): raise ValueError('TRUSTED_ROOT_MISSING')
    required = {'schemaVersion', 'operation', 'profileId', 'sourceCut', 'candidateCut', 'sourceContext',
                'sourceGeometry', 'assessment', 'sourceFloor', 'candidateObjects', 'pose', 'outputDirectory'}
    if not isinstance(request, dict) or set(request) != required or request['schemaVersion'] != 1 or request['operation'] != 'derive-room-furnishing-support':
        raise ValueError('REQUEST_CONTRACT_INVALID')
    profile = request['profileId']
    if profile not in PROFILES: raise ValueError('ROOM_PROFILE_UNSUPPORTED')
    if platform.python_version_tuple()[:2] != ('3', '12') or PILLOW_VERSION not in {'12.3.0', '13.0.0'}:
        raise ValueError('SUPPORT_RUNTIME_UNVALIDATED')
    out = files.safe_path(request['outputDirectory'], root, exists=False)
    inputs, origins = {}, []
    source = files.read_image(files.asset(request['sourceCut'], root, inputs), 'RGB')
    candidate = files.read_image(files.asset(request['candidateCut'], root, inputs), 'RGB')
    context_path = files.asset(request['sourceContext'], root, inputs)
    with Image.open(context_path) as image:
        if image.format != 'PNG' or image.size != (2048, 1024): raise ValueError('ORIGINAL_CONTEXT_REQUIRED')
        image.verify()
    geometry = files.json_asset(request['sourceGeometry'], root, inputs)
    if geometry.get('version') != 'interior-furnishing-source-geometry/1' or geometry.get('projection') != 'rectilinear' or (geometry.get('width'), geometry.get('height')) != (2048, 2048):
        raise ValueError('SOURCE_GEOMETRY_INVALID')
    if geometry.get('sourceCutSha256') != request['sourceCut']['sha256'] or geometry.get('view') != request['pose']:
        raise ValueError('ORIGINAL_CUT_POSE_CHANGED')
    assessment = files.json_asset(request['assessment'], root, inputs)
    expected = {'version', 'profileId', 'nativeSourceSha256', 'sourceCutSha256', 'preparedSourceSha256', 'candidateSha256', 'sourceContextSha256',
                'geometrySha256', 'routeSha256', 'layoutResultSha256', 'candidateResultSha256', 'sourceEmptyRoom', 'foregroundStatus',
                'opticalStatus', 'contactShadowStatus', 'selectionOrigin', 'contacts'}
    if not isinstance(assessment, dict) or set(assessment) != expected or assessment['version'] != 'interior-room-support-authority/1' or assessment['profileId'] != profile:
        raise ValueError('ASSESSMENT_AUTHORITY_INVALID')
    if type(assessment['sourceEmptyRoom']) is not bool or assessment['foregroundStatus'] not in {'none_observed', 'uncertain', 'required_occlusion'} or assessment['selectionOrigin'] not in {'machine', 'human', 'unknown'}:
        raise ValueError('ASSESSMENT_SCOPE_INVALID')
    for key in ('nativeSourceSha256', 'preparedSourceSha256', 'routeSha256', 'layoutResultSha256', 'candidateResultSha256'):
        value = assessment[key]
        if not isinstance(value, str) or len(value) != 64 or any(c not in '0123456789abcdef' for c in value): raise ValueError('ASSESSMENT_HASH_INVALID')
    for key, descriptor in [('sourceCutSha256', 'sourceCut'), ('candidateSha256', 'candidateCut'), ('sourceContextSha256', 'sourceContext'), ('geometrySha256', 'sourceGeometry')]:
        if assessment[key] != request[descriptor]['sha256']: raise ValueError('ACTUAL_ASSESSMENT_INPUT_CHANGED')
    if assessment['nativeSourceSha256'] != geometry.get('nativeSourceSha256') or assessment['preparedSourceSha256'] != geometry.get('preparedSourceSha256'):
        raise ValueError('ACTUAL_ASSESSMENT_NATIVE_PREPARATION_CHANGED')
    items = request['candidateObjects']
    if not isinstance(items, list) or not all(isinstance(x, dict) for x in items) or tuple(x.get('id') for x in items) != PROFILES[profile]: raise ValueError('ACTUAL_PROFILE_IDENTITIES_CHANGED')
    objects = {x['id']: files.mask_record(x, 'candidate_object', request['candidateCut']['sha256'], root, inputs, origins) for x in items}
    if not isinstance(request['sourceFloor'], dict) or request['sourceFloor'].get('id') != 'floor': raise ValueError('ORIGINAL_FLOOR_ID_INVALID')
    floor = files.mask_record(request['sourceFloor'], 'source_floor', request['sourceCut']['sha256'], root, inputs, origins)
    machine = assessment['selectionOrigin'] == 'machine' and all(x['selectionOrigin'] == 'machine' and x['generationOrigin'] == 'model_segmentation' for x in origins)
    context = {'profileId': profile, 'sourceContextSha256': assessment['sourceContextSha256'], 'placementStatus': 'ready_for_masks',
               'foregroundAssessment': assessment['foregroundStatus'], 'selectionOrigin': 'machine' if machine else 'unknown',
               'opticalAssessment': 'clear_for_actual_placement' if assessment['opticalStatus'] == 'no_required_changes' and assessment['contactShadowStatus'] == 'tiny_local_only' else 'unknown'}
    identity = {'adapterSha256': files.sha(__file__), 'bedroomCoreSha256': files.sha(bedroom_support.__file__),
                'stagingCoreSha256': files.sha(staging_support.__file__), 'fileDecoderSha256': files.sha(files.__file__),
                'runtime': bedroom_support.runtime(), 'python': platform.python_version(), 'pillow': PILLOW_VERSION}
    fingerprint = hashlib.sha256(files.canonical({'request': request, 'identity': identity})).hexdigest()
    if out.exists():
        report_path = files.safe_path(str(out / 'report.json'), root)
        if report_path.stat().st_size > 1024 * 1024: raise ValueError('REPLAY_REPORT_LIMIT')
        report = json.loads(report_path.read_text('utf-8'))
        if report.get('adapter') != VERSION or report.get('requestFingerprint') != fingerprint or report.get('identity') != identity or report.get('inputAssets') != inputs or report.get('maskProvenance') != origins:
            raise ValueError('REPLAY_AUTHORITY_CHANGED')
        if report.get('status') not in {'needs_review', 'ready_for_unreviewed_composition'} or report.get('automaticAcceptance') is not False or report.get('nativeCompositionPerformed') is not False or report.get('providerCalls') != 0:
            raise ValueError('REPLAY_STATUS_INVALID')
        if (report['status'] == 'needs_review') != bool(report.get('reviewReasons')): raise ValueError('REPLAY_HOLD_CHANGED')
        if set(report.get('assets', {})) != {'objectAlpha', 'scalarShadow', 'safeFloorAlpha'}: raise ValueError('REPLAY_ASSET_SET_CHANGED')
        for key, name in [('objectAlpha', 'object-alpha.png'), ('scalarShadow', 'scalar-shadow.png'), ('safeFloorAlpha', 'safe-floor-alpha.png')]:
            a = report['assets'][key]
            if set(a) != {'file', 'sha256', 'width', 'height', 'mode'} or (a['file'], a['width'], a['height'], a['mode']) != (name, 2048, 2048, 'L'):
                raise ValueError('REPLAY_ASSET_DESCRIPTOR_CHANGED')
            image = files.read_image(files.asset({'path': str(out / name), 'sha256': a['sha256']}, root, {}), 'L')
            if key == 'scalarShadow' and report['status'] == 'needs_review' and np.any(image): raise ValueError('HELD_SHADOW_PRESENT')
        if any(files.sha(p) != digest for p, digest in inputs.items()): raise ValueError('INPUT_CHANGED_DURING_REPLAY')
        return report
    if profile == 'empty-bedroom-platform-three-piece/1':
        context['sourceEmptyBedroom'] = assessment['sourceEmptyRoom']
        result = bedroom_support.derive_support(source, candidate, objects, floor, [], request['pose'], context, assessment['contacts'])
    else:
        if assessment['contacts'] != []: raise ValueError('LIVING_PROFILE_CANNOT_BORROW_BED_CONTACTS')
        context['sourceEmptyLivingRoom'] = assessment['sourceEmptyRoom']
        result = staging_support.derive_support(source, candidate, list(objects.values()), floor, [], [], request['pose'], context)
    report = result['report']
    report.update({'adapter': VERSION, 'profileId': profile, 'identity': identity, 'requestFingerprint': fingerprint,
                   'inputAssets': inputs, 'maskProvenance': origins, 'nativeSourceSha256': assessment['nativeSourceSha256'],
                   'sourceCutSha256': assessment['sourceCutSha256'], 'candidateSha256': assessment['candidateSha256'],
                   'assessmentSha256': request['assessment']['sha256'], 'sourceGeometrySha256': request['sourceGeometry']['sha256'],
                   'routeSha256': assessment['routeSha256'], 'nativeCompositionPerformed': False, 'freshAutonomousSuccess': False})
    if any(files.sha(p) != digest for p, digest in inputs.items()): raise ValueError('INPUT_CHANGED_DURING_SUPPORT')
    out.parent.mkdir(parents=True, exist_ok=True)
    temporary = Path(tempfile.mkdtemp(prefix='.room-support-', dir=out.parent)); os.chmod(temporary, 0o2770)
    assets = {}
    for key, name in [('objectAlpha', 'object-alpha.png'), ('scalarShadow', 'scalar-shadow.png'), ('safeFloorAlpha', 'safe-floor-alpha.png')]:
        path = temporary / name; Image.fromarray(result[key]).save(path); os.chmod(path, 0o660)
        assets[key] = {'file': name, 'sha256': files.sha(path), 'width': 2048, 'height': 2048, 'mode': 'L'}
    report['assets'] = assets
    report = files._jsonable(report)
    path = temporary / 'report.json'; path.write_text(json.dumps(report, indent=2, allow_nan=False), encoding='utf-8'); os.chmod(path, 0o660)
    temporary.rename(out)
    return report


def main():
    parser = argparse.ArgumentParser(); parser.add_argument('request'); parser.add_argument('--allowed-root', required=True); args = parser.parse_args()
    if os.name == 'posix':
        import resource
        resource.setrlimit(resource.RLIMIT_AS, (2 * 1024**3, 2 * 1024**3)); resource.setrlimit(resource.RLIMIT_CPU, (90, 90)); os.umask(0o007)
    path = Path(args.request)
    if path.stat().st_size > 512 * 1024: raise ValueError('REQUEST_LIMIT')
    report = execute(json.loads(path.read_text('utf-8-sig')), args.allowed_root)
    print(json.dumps({'status': report['status'], 'reviewReasons': report['reviewReasons'], 'providerCalls': 0}))


if __name__ == '__main__': main()
