"""Linux adapter checks: source binding, bounded views, immutable replay and failure isolation."""
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from PIL import Image

SCRIPT = Path(os.environ.get('IGUIDE_ANALYSIS_VIEWS_SCRIPT', Path(__file__).resolve().parents[2] / 'resources/iguide-lab/analysis_views.py'))

def sha(path):
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()

class AnalysisViewTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.source = self.root / 'source.png'
        Image.new('RGB', (2048, 1024), (65, 120, 180)).save(self.source)
        self.original = sha(self.source)
        self.request = {'schemaVersion': 1, 'source': {'path': str(self.source), 'sha256': self.original}, 'outputDirectory': str(self.root / 'diagnostics')}

    def tearDown(self):
        self.temp.cleanup()

    def invoke(self, request=None):
        path = self.root / 'request.json'
        path.write_text(json.dumps(request or self.request), encoding='utf-8')
        env = {'PATH': '/usr/bin:/bin', 'LANG': 'C.UTF-8', 'PYTHONNOUSERSITE': '1', 'OPENBLAS_NUM_THREADS': '2', 'OMP_NUM_THREADS': '2'}
        result = subprocess.run([sys.executable, str(SCRIPT), '--request', str(path)], capture_output=True, text=True, timeout=90, env=env)
        return result, json.loads(result.stdout)

    def test_six_bounded_views_replay_exactly_and_preserve_source(self):
        process, first = self.invoke()
        self.assertEqual(process.returncode, 0, process.stderr)
        self.assertEqual(first['status'], 'completed')
        manifest = first['manifest']
        self.assertEqual([v['id'] for v in manifest['views']], ['front', 'right', 'back', 'left', 'zenith', 'nadir'])
        for view in manifest['views']:
            self.assertEqual([view['width'], view['height'], view['hfov_degrees']], [1024, 1024, 100.0])
            self.assertEqual(sha(view['path']), view['sha256'])
        replay, second = self.invoke()
        self.assertEqual(replay.returncode, 0)
        self.assertEqual(first, second)
        self.assertEqual(sha(self.source), self.original)

    def test_wrong_source_hash_or_small_panorama_fails_without_output(self):
        wrong = dict(self.request, source={'path': str(self.source), 'sha256': 'f' * 64})
        process, result = self.invoke(wrong)
        self.assertNotEqual(process.returncode, 0)
        self.assertEqual(result, {'status': 'failed', 'code': 'ValueError'})
        self.assertFalse((self.root / 'diagnostics').exists())
        Image.new('RGB', (512, 256)).save(self.source)
        small = dict(self.request, source={'path': str(self.source), 'sha256': sha(self.source)})
        process, result = self.invoke(small)
        self.assertNotEqual(process.returncode, 0)
        self.assertFalse((self.root / 'diagnostics').exists())

    def test_changed_cached_view_fails_without_overwriting_any_source(self):
        _, first = self.invoke()
        target = Path(first['manifest']['views'][0]['path'])
        target.write_bytes(b'changed cached bytes')
        process, result = self.invoke()
        self.assertNotEqual(process.returncode, 0)
        self.assertEqual(result['code'], 'ValueError')
        self.assertEqual(target.read_bytes(), b'changed cached bytes')
        self.assertEqual(sha(self.source), self.original)

    def test_changed_cache_angles_or_implementation_hashes_fail_closed(self):
        _, first = self.invoke()
        path = self.root / 'diagnostics/manifest.json'
        for field in ['yaw_degrees', 'pitch_degrees', 'adapterSha256', 'geometrySha256']:
            manifest = json.loads(json.dumps(first['manifest']))
            if field.endswith('Sha256'):
                manifest[field] = 'f' * 64
            else:
                manifest['views'][0][field] = 45
            path.write_text(json.dumps(manifest), encoding='utf-8')
            process, result = self.invoke()
            self.assertNotEqual(process.returncode, 0)
            self.assertEqual(result['code'], 'ValueError')
        self.assertEqual(sha(self.source), self.original)

if __name__ == '__main__':
    unittest.main()
