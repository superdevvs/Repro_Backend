from pathlib import Path
import copy
import json
import sys
import tempfile
import unittest
import numpy as np
from PIL import Image
sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'resources' / 'iguide-lab'))
import global_lighting as gl
import workflow_qa as w


class GlobalLightingTests(unittest.TestCase):
    def test_equal_colors_keep_equal_optical_relation_despite_spatial_donor_mismatch(self):
        source = np.full((64, 128, 3), [80, 100, 120], np.uint8)
        donor = source.copy(); donor[:, :96] = [130, 110, 90]
        offset, stats = gl.derive_offset(source, donor, w.g.to_linear)
        native = np.tile(source, (2, 2, 1))
        native[30:45, 20:40] = [40, 60, 70]
        result, telemetry = gl.apply_offset(native, offset, w.g, w.lighting)
        np.testing.assert_array_equal(result[30, 90], result[30, 190])
        self.assertLess(int(result[35, 25, 0]), int(result[35, 90, 0]))
        self.assertEqual(telemetry['policyProtectedNadirChangedPixels'], 0)
        self.assertEqual(stats['fitQualityClaim'].split(':')[0], 'none')

    def test_noop_is_byte_exact_and_nadir_is_protected(self):
        rng = np.random.default_rng(5)
        a = rng.integers(10, 240, (64, 128, 3), dtype=np.uint8)
        offset, _ = gl.derive_offset(a, a, w.g.to_linear)
        result, _ = gl.apply_offset(a, offset, w.g, w.lighting)
        np.testing.assert_array_equal(result, a)
        result, stats = gl.apply_offset(a, np.array([1., -.5, .4], np.float32), w.g, w.lighting)
        elevation = 90-(np.arange(64)+.5)*180/64
        np.testing.assert_array_equal(result[elevation <= -80], a[elevation <= -80])
        self.assertEqual(stats['nonfiniteChannels'], 0)

    def test_longitude_equivariance_and_no_donor_checkerboard(self):
        source = np.full((64, 128, 3), 100, np.uint8)
        donor = source.copy(); donor[:, ::2] = 160
        offset, _ = gl.derive_offset(source, donor, w.g.to_linear)
        shifted, _ = gl.derive_offset(np.roll(source, 17, axis=1), np.roll(donor, 17, axis=1), w.g.to_linear)
        np.testing.assert_array_equal(offset, shifted)
        result, _ = gl.apply_offset(source, offset, w.g, w.lighting)
        self.assertEqual(int(np.ptp(result[20, :, 0])), 0)

    def test_invalid_or_uninformative_input_fails_closed(self):
        a = np.zeros((64, 128, 3), np.uint8)
        with self.assertRaisesRegex(ValueError, 'INSUFFICIENT_RELIABLE'): gl.derive_offset(a, a, w.g.to_linear)
        with self.assertRaisesRegex(ValueError, 'GUIDANCE_SHAPE'): gl.derive_offset(a.astype(float), a, w.g.to_linear)
        with self.assertRaisesRegex(ValueError, 'GLOBAL_OFFSET_INVALID'): gl.apply_offset(a, np.array([np.nan, 0, 0], np.float32), w.g, w.lighting)

    def test_file_contract_binds_retained_guidance_recipe_outputs_and_exact_replay(self):
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            for name, shape, value in [('source', (128, 256, 3), 90), ('guide', (1024, 2048, 3), 90), ('donor', (1024, 2048, 3), 125)]:
                Image.fromarray(np.full(shape, value, np.uint8)).save(root/f'{name}.png')
            asset = lambda n: {'path': str(root/f'{n}.png'), 'sha256': w.g.sha256(root/f'{n}.png')}
            request = {'schemaVersion': 1, 'operation': 'transfer-lighting-global', 'privateRoot': td,
                'source': asset('source'), 'guidanceSource': asset('guide'), 'candidate': asset('donor'),
                'preset': 'twilight', 'editScope': 'entire_view', 'recipe': gl.VERSION, 'outputDirectory': str(root/'out'),
                'sourcePreparation': {'nativeSourceSha256': asset('source')['sha256'], 'guidanceSourceSha256': asset('guide')['sha256'],
                    'preparerSha256': '0'*64, 'method': 'LabImages::prepared/GD', 'verifiedByCaller': True}}
            report = w.run(request); original_report = (root/'out/report.json').read_bytes()
            self.assertEqual(report, w.run(request)); self.assertEqual(original_report, (root/'out/report.json').read_bytes())
            self.assertEqual(report['recipe'], gl.VERSION); self.assertEqual(report['providerCalls'], 0)
            self.assertEqual(report['artifacts']['lighting-field']['dimensions'], [128, 64, 3])
            self.assertFalse(report['automaticAcceptance']); self.assertEqual(report['reviewStatus'], 'unreviewed')
            self.assertEqual(report['qa']['sourcePreservation']['applicability'], 'not_applicable')
            changed = copy.deepcopy(request); changed['recipe'] = w.lighting.RECIPE
            with self.assertRaisesRegex(w.WorkerError, 'LIGHTING_RECIPE_UNSUPPORTED'): w.run(changed)
            Image.fromarray(np.full((1024, 2048, 3), 126, np.uint8)).save(root/'donor.png')
            with self.assertRaisesRegex(w.WorkerError, 'INPUT_HASH_MISMATCH'): w.run(request)


if __name__ == '__main__': unittest.main()
