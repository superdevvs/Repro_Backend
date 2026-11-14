from pathlib import Path
import json
import tempfile
import unittest
import numpy as np
from PIL import Image
import sys
ROOT = Path(__file__).resolve().parents[2] / 'resources' / 'iguide-lab'
sys.path.insert(0, str(ROOT))
import workflow_qa as w


class NumericQaTests(unittest.TestCase):
    def setUp(self):
        self.source = np.full((128, 256, 3), 90, np.uint8)
        self.alpha = np.zeros((128, 256), np.uint8)

    def test_protected_one_pixel_drift_is_blocking(self):
        candidate = self.source.copy(); candidate[40, 60, 1] += 1
        q = w.qa_arrays(self.source, candidate, self.alpha, 'declutter')
        self.assertEqual(q['sourcePreservation']['outsideAlphaChangedPixels'], 1)
        self.assertEqual(q['numericIntegrity'], 'fail')
        self.assertTrue(q['reviewReasons'][0]['blocking'])
        self.assertFalse(q['automaticAcceptance'])

    def test_new_wrap_failure_flagged_but_existing_seam_not_new_failure(self):
        candidate = self.source.copy(); candidate[:, 0] = 220
        alpha = self.alpha.copy(); alpha[:, 0] = 255
        q = w.qa_arrays(self.source, candidate, alpha, 'twilight')
        self.assertEqual(q['numericIntegrity'], 'pass')
        self.assertIn('WRAP_CHANGE_REVIEW', [r['code'] for r in q['reviewReasons']])
        old = w.qa_arrays(candidate, candidate, self.alpha, 'twilight')
        self.assertEqual(old['wrap']['sourceRelativeJumpMAE'], 0)
        self.assertNotIn('WRAP_CHANGE_REVIEW', [r['code'] for r in old['reviewReasons']])

    def test_interior_geometry_shift_has_diagnostic_not_semantic_pass(self):
        source = self.source.copy(); source[:, 50:52] = 250
        candidate = self.source.copy(); candidate[:, 75:77] = 250
        q = w.qa_arrays(source, candidate, np.full_like(self.alpha, 255), 'staging')
        self.assertGreater(q['edge']['unmatchedSourceEdgeFraction'], .8)
        self.assertEqual(q['reviewStatus'], 'unreviewed')

    def test_polar_changes_measured_and_rim_exposed(self):
        candidate = self.source.copy(); candidate[:12] = 180
        alpha = self.alpha.copy(); alpha[:12] = 255
        q = w.qa_arrays(self.source, candidate, alpha, 'golden-hour')
        self.assertGreater(q['poles']['zenith']['maeRGB'], 1)
        self.assertEqual(q['poles']['nadir']['maeRGB'], 0)
        self.assertGreater(q['rim']['p95ExtraGradientRGB'], 50)

    def test_tiny_support_cannot_disappear_from_diagnostics(self):
        alpha = np.zeros((1000, 2000), np.uint8); alpha[333, 777] = 1
        self.assertTrue(w.conservative_support(alpha, 32, 16).any())

    def test_entire_view_has_no_fake_protected_pixel_claim(self):
        q = w.qa_arrays(self.source, self.source+10, None, 'golden-hour')
        self.assertEqual(q['sourcePreservation']['applicability'], 'not_applicable')
        self.assertIsNone(q['sourcePreservation']['passesExactExterior'])
        self.assertEqual(q['editScope'], 'entire_view')
        self.assertFalse(q['automaticAcceptance'])


class CompositionTests(unittest.TestCase):
    def setUp(self):
        self.source = np.full((128, 256, 3), 100, np.uint8)
        self.cut = np.full((64, 64, 3), 100, np.uint8)
        self.view = {'yaw_degrees': 0, 'pitch_degrees': 0, 'hfov_degrees': 90, 'width': 64, 'height': 64}
        self.mask = np.zeros((64, 64), np.uint8); self.mask[20:44, 20:44] = 128

    def test_soft_alpha_applied_once_and_exterior_exact(self):
        result, alpha, obj, shadow, stats = w.recompose_arrays(self.source, self.cut+100, self.mask, self.view)
        self.assertTrue(np.array_equal(result[alpha == 0], self.source[alpha == 0]))
        expected = np.rint(w.g.from_linear(w.g.to_linear(np.array([100.]))*(1-128/255) + w.g.to_linear(np.array([200.]))*(128/255))).astype(np.uint8)[0]
        self.assertEqual(int(result[64, 128, 0]), int(expected))
        self.assertEqual(int(obj[64, 128]), 128)
        self.assertEqual(stats['shadowOnlyFormulaMismatchPixels'], 0)

    def test_residual_noop_is_exact_identity_despite_roundtrip_sampling(self):
        yy, xx = np.mgrid[:64, :64]
        cut = np.stack([(xx*3)%255, (yy*4)%255, (xx+yy)%255], axis=-1).astype(np.uint8)
        result, *_ = w.recompose_arrays(self.source, cut, self.mask, self.view, 'source-residual', cut)
        np.testing.assert_array_equal(result, self.source)

    def test_shadow_uses_original_native_values_and_has_no_object_color(self):
        shadow = np.zeros_like(self.mask); shadow[16:48, 16:48] = 64
        result, alpha, obj, applied, stats = w.recompose_arrays(self.source, self.cut+100, np.zeros_like(self.mask), self.view,
            scalar_shadow=shadow, floor_allowed=np.full_like(shadow, 255))
        self.assertGreater(stats['shadowOnlyPixels'], 0)
        self.assertEqual(stats['shadowOnlyFormulaMismatchPixels'], 0)
        expected = np.rint(w.g.from_linear(w.g.to_linear(self.source)*(1-applied[..., None]/255))).astype(np.uint8)
        np.testing.assert_array_equal(result, expected)

    def test_shadow_wall_spill_and_taper_violation_rejected(self):
        shadow = np.zeros_like(self.mask); shadow[20, 20] = 10
        with self.assertRaisesRegex(w.WorkerError, 'SHADOW_OUTSIDE_CERTAIN_FLOOR'):
            w.recompose_arrays(self.source, self.cut, self.mask, self.view, scalar_shadow=shadow, floor_allowed=np.zeros_like(shadow))
        mask = self.mask.copy(); mask[0, 20] = 1
        with self.assertRaisesRegex(w.WorkerError, 'SUPPORT_TOUCHES_SECTION_TAPER'):
            w.recompose_arrays(self.source, self.cut, mask, self.view)


class FileContractTests(unittest.TestCase):
    def test_native_detail_uses_original_pixels_and_rejects_wrong_source(self):
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            yy, xx = np.mgrid[:128, :256]
            a = np.stack([(xx*13)%255, (yy*17)%255, (xx*yy)%255], axis=-1).astype(np.uint8)
            Image.fromarray(a).save(root/'source.png')
            Image.fromarray(a[::2, ::2]).save(root/'candidate.png')
            m = w.g.extract(root/'source.png', root/'detail', view_specs=[{'id':'target', 'yaw_degrees':36, 'pitch_degrees':-20, 'hfov_degrees':70, 'width':64, 'height':64}])
            asset = lambda p: {'path':str(p), 'sha256':w.g.sha256(p)}
            req = {'schemaVersion':1, 'operation':'build-evidence', 'privateRoot':td, 'preset':'golden-hour', 'editScope':'entire_view',
                'source':asset(root/'source.png'), 'candidate':asset(root/'candidate.png'), 'details':[{'geometry':asset(root/'detail/manifest.json')}], 'outputDirectory':str(root/'evidence')}
            report = w.run(req)
            detail = report['evidence'][-1]
            self.assertEqual(detail['id'], 'detail_1'); self.assertEqual(detail['dimensions'], [2048,1024])
            actual = np.asarray(Image.open(detail['halves'][0]['path']))
            np.testing.assert_array_equal(actual, w.g.perspective(a,36,-20,70,(1020,984)))
            self.assertIsNone(report['qa']['sourcePreservation']['changedPixels'])
            req['source'] = req['candidate']; req['outputDirectory'] = str(root/'invalid')
            with self.assertRaisesRegex(w.WorkerError, 'DETAIL_NOT_BOUND_TO_SOURCE'):
                w.run(req)

    def test_evidence_order_hashes_gutter_and_native_pose(self):
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            a = np.full((64, 128, 3), [90, 60, 40], np.uint8)
            b = np.full_like(a, [10, 80, 120])
            Image.fromarray(a).save(root/'source.png'); Image.fromarray(b).save(root/'candidate.png')
            asset = lambda name: {'path': str(root/f'{name}.png'), 'sha256': w.g.sha256(root/f'{name}.png')}
            req = {'schemaVersion': 1, 'operation': 'build-evidence', 'privateRoot': td,
                'source': asset('source'), 'candidate': asset('candidate'), 'preset': 'staging', 'editScope': 'entire_view', 'outputDirectory': str(root/'evidence')}
            report = w.run(req)
            self.assertEqual([e['id'] for e in report['evidence']], ['overview','front','right','back','left','poles','wrap'])
            self.assertEqual(report, w.run(req))
            for e in report['evidence']:
                self.assertLessEqual(e['dimensions'][0], 2048); self.assertLessEqual(e['dimensions'][1], 1024)
                self.assertEqual(e['halves'][0]['originalAssetSha256'], req['source']['sha256'])
                self.assertEqual(e['halves'][1]['originalAssetSha256'], req['candidate']['sha256'])
            wrap = report['evidence'][-1]
            self.assertEqual(wrap['halves'][0]['pose']['yaw'], 180)
            self.assertTrue(wrap['halves'][0]['pose']['longitudeBoundaryCentered'])

    def test_cache_hash_binding_and_readonly_inputs(self):
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            src = np.full((64, 128, 3), 90, np.uint8)
            for name, a in [('source', src), ('candidate', src), ('alpha', np.zeros((64, 128), np.uint8))]:
                Image.fromarray(a).save(root/f'{name}.png')
            asset = lambda name: {'path': str(root/f'{name}.png'), 'sha256': w.g.sha256(root/f'{name}.png')}
            req = {'schemaVersion': 1, 'operation': 'qa-panorama', 'privateRoot': td, 'source': asset('source'),
                'candidate': asset('candidate'), 'effectiveAlpha': asset('alpha'), 'editScope': 'masked', 'preset': 'declutter', 'outputDirectory': str(root/'out')}
            report = w.run(req)
            self.assertEqual(report, w.run(req))
            self.assertEqual(req['source']['sha256'], w.g.sha256(root/'source.png'))
            path = root/'out/report.json'
            data = json.loads(path.read_text()); data['artifacts'] = {}; path.write_text(json.dumps(data))
            with self.assertRaisesRegex(w.WorkerError, 'CACHE_ARTIFACT_SET_INVALID'):
                w.run(req)
            Image.fromarray(src+1).save(root/'candidate.png')
            with self.assertRaisesRegex(w.WorkerError, 'INPUT_HASH_MISMATCH'):
                w.run(req)


if __name__ == '__main__':
    unittest.main()
