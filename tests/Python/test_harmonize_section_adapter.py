import copy
import importlib.util
import json
import os
from pathlib import Path
import platform
import sys
import tempfile
import unittest
from unittest import mock

import numpy as np
import PIL
from PIL import Image
import scipy

SCRIPT = Path(__file__).resolve().parents[2] / 'resources/iguide-lab/harmonize_section.py'
sys.path.insert(0, str(SCRIPT.parent))
spec = importlib.util.spec_from_file_location('harmonize_section', SCRIPT)
adapter = importlib.util.module_from_spec(spec); spec.loader.exec_module(adapter)
import section_harmonization as solver


class HarmonizeSectionAdapterTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.base = Path(self.temp.name).resolve()
        self.root = self.base / 'private-project'; self.root.mkdir()
        self.runtime = {'pythonVersion': platform.python_version(), 'numpyVersion': np.__version__,
                        'pillowVersion': PIL.__version__, 'scipyVersion': scipy.__version__}
        self.pin = mock.patch.object(adapter, 'RUNTIME', self.runtime); self.pin.start()
        self.runtime_path = self.base / 'trusted-runtime.json'
        self.runtime_path.write_text(json.dumps({'schemaVersion': 1, 'validationStatus': 'passed', **self.runtime}))
        self.source = np.full((2048, 2048, 3), 100, np.uint8)
        self.raw = self.source.copy(); self.raw[900:925, 900:930] = [130, 140, 150]
        self.mask = np.zeros((2048, 2048), np.uint8); self.mask[900:925, 900:930] = 64
        self.save_inputs()

    def tearDown(self):
        self.pin.stop(); self.temp.cleanup()

    def save_inputs(self):
        for name, data in [('source', self.source), ('candidate', self.raw), ('mask', self.mask)]:
            Image.fromarray(data).save(self.root / (name + '.png'))

    def descriptor(self, name):
        path = self.root / (name + '.png')
        return {'path': str(path), 'sha256': adapter.sha(path)}

    def request(self):
        return {'schemaVersion': 1, 'privateRoot': str(self.root),
                'source': self.descriptor('source'), 'candidate': self.descriptor('candidate'),
                'mask': self.descriptor('mask'), 'outputDirectory': str(self.root / 'harmonized'),
                'recipe': {'mode': adapter.MODE, 'recipeVersion': adapter.VERSION,
                           'solverSha256': adapter.sha(solver.__file__), 'adapterSha256': adapter.sha(SCRIPT),
                           'runtimeManifestSha256': adapter.sha(self.runtime_path),
                           'sourceCutSha256': adapter.sha(self.root / 'source.png'), 'limits': adapter.LIMITS.copy()}}

    def run_adapter(self, request):
        return adapter.run(request, self.runtime_path)

    def test_completed_replay_is_bound_and_gray_alpha_is_not_applied_twice(self):
        request = self.request(); original_mask = adapter.sha(self.root / 'mask.png')
        report = self.run_adapter(request)
        self.assertEqual(report['status'], 'completed')
        self.assertEqual(report['reviewStatus'], 'unreviewed')
        self.assertFalse(report['automaticAcceptance'])
        output = np.asarray(Image.open(report['candidate']['path']).convert('RGB'))
        self.assertTrue(np.array_equal(output[self.mask == 0], self.source[self.mask == 0]))
        self.assertTrue(np.all(output[self.mask > 0] == [130, 140, 150]))
        self.assertEqual(adapter.sha(self.root / 'mask.png'), original_mask)
        with mock.patch.object(solver, 'harmonize', side_effect=AssertionError('Replay must not solve')):
            self.assertEqual(report, self.run_adapter(request))
        if os.name == 'posix':
            directory = Path(request['outputDirectory'])
            self.assertEqual(directory.stat().st_mode & 0o7777, 0o2770)
            for path in directory.iterdir():
                self.assertEqual(path.stat().st_mode & 0o7777, 0o660)

    def test_trusted_runtime_manifest_bytes_and_validation_status_are_required(self):
        request = self.request()
        self.runtime_path.write_text(self.runtime_path.read_text() + '\n')
        with self.assertRaisesRegex(ValueError, 'runtime manifest changed'):
            self.run_adapter(request)
        request['recipe']['runtimeManifestSha256'] = adapter.sha(self.runtime_path)
        record = json.loads(self.runtime_path.read_text()); record['validationStatus'] = 'pending'
        self.runtime_path.write_text(json.dumps(record))
        request['recipe']['runtimeManifestSha256'] = adapter.sha(self.runtime_path)
        with self.assertRaisesRegex(ValueError, 'Runtime validation record changed'):
            self.run_adapter(request)
        self.assertFalse(Path(request['outputDirectory']).exists())

    def test_reserved_solver_adapter_and_source_cut_hashes_are_not_caller_overrides(self):
        for key in ['solverSha256', 'adapterSha256', 'sourceCutSha256']:
            request = self.request(); request['recipe'][key] = '0' * 64
            with self.assertRaisesRegex(ValueError, 'Reserved local implementation changed'):
                self.run_adapter(request)
        self.assertFalse((self.root / 'harmonized').exists())

    def test_input_fingerprint_escape_and_output_collision_fail_before_solver(self):
        outside = self.base / 'outside.png'; Image.fromarray(self.source).save(outside)
        requests = []
        r = self.request(); r['candidate']['sha256'] = '0' * 64; requests.append(r)
        r = self.request(); r['candidate'] = {'path': str(outside), 'sha256': adapter.sha(outside)}; requests.append(r)
        r = self.request(); r['outputDirectory'] = str(self.base / 'outside-output'); requests.append(r)
        r = self.request(); r['outputDirectory'] = str(self.root); requests.append(r)
        with mock.patch.object(solver, 'harmonize') as solve:
            for request in requests:
                with self.assertRaises(ValueError): self.run_adapter(request)
            solve.assert_not_called()
        self.assertFalse((self.root / 'harmonized').exists())

    def test_corrupted_terminal_cache_shapes_are_rejected_without_recomputation(self):
        request = self.request(); report = self.run_adapter(request)
        path = Path(request['outputDirectory']) / 'report.json'
        variants = []
        for key in ['candidate', 'clippingMask', 'diagnostics']:
            bad = copy.deepcopy(report); del bad[key]; variants.append(bad)
        for key, value in [('status', 'queued'), ('diagnostics', []), ('automaticAcceptance', True),
                           ('reviewStatus', 'accepted'), ('errorCode', 'unexpected')]:
            bad = copy.deepcopy(report); bad[key] = value; variants.append(bad)
        bad = copy.deepcopy(report); bad['status'] = 'needs_attention'; bad['errorCode'] = 'error'; variants.append(bad)
        with mock.patch.object(solver, 'harmonize') as solve:
            for bad in variants:
                path.write_text(json.dumps(bad))
                with self.assertRaises(ValueError): self.run_adapter(request)
            solve.assert_not_called()
        path.write_text(json.dumps(report))
        self.assertEqual(report, self.run_adapter(request))

    def test_cached_output_byte_tamper_is_rejected(self):
        request = self.request(); report = self.run_adapter(request)
        Path(report['candidate']['path']).write_bytes(b'corrupt')
        with self.assertRaisesRegex(ValueError, 'fingerprint mismatch'):
            self.run_adapter(request)

    def test_input_drift_during_solve_prevents_atomic_publish(self):
        request = self.request(); real_solver = solver.harmonize
        def mutate_after_read(*args):
            result = real_solver(*args)
            (self.root / 'candidate.png').write_bytes(b'changed during calculation')
            return result
        with mock.patch.object(solver, 'harmonize', side_effect=mutate_after_read):
            with self.assertRaisesRegex(ValueError, 'fingerprint mismatch'):
                self.run_adapter(request)
        self.assertFalse(Path(request['outputDirectory']).exists())
        self.assertEqual(list(self.root.glob('.harmonize-*')), [])

    def test_clipping_rejection_keeps_diagnostics_map_and_raw_inputs_private(self):
        self.mask[:] = 0; self.raw[:] = 36
        coordinates = [(700 + (i // 10) * 3, 700 + (i % 10) * 3) for i in range(100)]
        for y, x in coordinates:
            self.mask[y, x] = 255; self.raw[y, x] = 100
        for y, x in coordinates[:6]: self.raw[y, x, 0] = 255
        self.save_inputs(); request = self.request()
        before = [adapter.sha(self.root / (name + '.png')) for name in ['source', 'candidate', 'mask']]
        report = self.run_adapter(request)
        self.assertEqual(report['status'], 'needs_attention')
        self.assertEqual(report['errorCode'], 'clipping_fraction_limit')
        self.assertNotIn('candidate', report)
        self.assertFalse((Path(request['outputDirectory']) / 'candidate.png').exists())
        self.assertEqual(report['diagnostics']['clippedPixels'], 6)
        self.assertEqual(np.count_nonzero(np.asarray(Image.open(report['clippingMask']['path']))), 6)
        after = [adapter.sha(self.root / (name + '.png')) for name in ['source', 'candidate', 'mask']]
        self.assertEqual(before, after)
        self.assertEqual(report, self.run_adapter(request))
        if os.name == 'posix':
            self.assertEqual(Path(request['outputDirectory']).stat().st_mode & 0o7777, 0o2770)
            for path in Path(request['outputDirectory']).iterdir():
                self.assertEqual(path.stat().st_mode & 0o7777, 0o660)


if __name__ == '__main__': unittest.main()
