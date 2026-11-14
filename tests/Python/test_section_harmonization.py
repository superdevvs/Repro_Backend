import importlib.util
import json
from pathlib import Path
import sys
import unittest
from unittest import mock

import numpy as np

SCRIPT = Path(__file__).resolve().parents[2] / 'resources/iguide-lab/section_harmonization.py'
spec = importlib.util.spec_from_file_location('section_harmonization', SCRIPT)
if spec.name in sys.modules:
    h = sys.modules[spec.name]
    assert Path(h.__file__).resolve() == SCRIPT.resolve()
else:
    h = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = h
    spec.loader.exec_module(h)


class SectionHarmonizationTest(unittest.TestCase):
    def setUp(self):
        self.source = np.full((2048, 2048, 3), 100, np.uint8)
        self.candidate = self.source.copy()
        self.mask = np.zeros((2048, 2048), np.uint8)
        self.mask[900:925, 900:930] = 255

    def run_solver(self):
        return h.harmonize(self.source, self.candidate, self.mask)

    def assert_rejected(self, code):
        with self.assertRaises(h.HarmonizationError) as caught:
            self.run_solver()
        self.assertEqual(caught.exception.code, code)
        self.assertEqual(caught.exception.telemetry['status'], 'rejected')
        self.assertFalse(caught.exception.telemetry['automaticAcceptance'])
        json.dumps(caught.exception.telemetry, allow_nan=False)
        if code.startswith('clipping_'):
            self.assertEqual(caught.exception.clipping_mask.dtype, np.uint8)
            self.assertEqual(np.count_nonzero(caught.exception.clipping_mask),
                             caught.exception.telemetry['clippedPixels'])
        return caught.exception.telemetry

    def test_noop_irregular_domain_is_identity_and_does_not_mutate_inputs(self):
        rng = np.random.default_rng(23)
        self.source[870:960, 870:960] = rng.integers(0, 256, (90, 90, 3), dtype=np.uint8)
        self.candidate = self.source.copy()
        self.mask[900:908, 900:910] = 0
        self.mask[910:920, 910:920] = 79
        originals = [v.copy() for v in [self.source, self.candidate, self.mask]]
        result, telemetry, clip = self.run_solver()
        self.assertTrue(np.array_equal(result, self.source))
        for actual, original in zip([self.source, self.candidate, self.mask], originals):
            self.assertTrue(np.array_equal(actual, original))
        self.assertEqual(telemetry['maxResidual'], 0)
        self.assertEqual(telemetry['clippedPixels'], 0)
        self.assertFalse(np.any(clip))
        self.assertEqual(telemetry['status'], 'unreviewed')
        json.dumps(telemetry, allow_nan=False)

    def test_constant_color_offset_is_removed_using_source_exterior(self):
        x = np.arange(2048, dtype=np.uint16) % 80
        self.source[:, :, :] = x[np.newaxis, :, np.newaxis] + 80
        self.candidate = (self.source.astype(np.int16) + [20, -15, 35]).astype(np.uint8)
        result, telemetry, _ = self.run_solver()
        self.assertTrue(np.array_equal(result, self.source))
        self.assertLess(telemetry['maxResidual'], 1e-7)

    def test_source_object_interior_is_not_resurrected_and_holes_are_source_boundaries(self):
        self.mask[909:914, 909:914] = 0
        self.source[self.mask > 0] = [220, 25, 30]
        self.candidate[self.mask > 0] = [120, 130, 140]
        # Candidate is shifted everywhere; correction must come only from exterior.
        self.candidate += np.uint8(10)
        result, telemetry, _ = self.run_solver()
        self.assertTrue(np.all(result[self.mask > 0] == [120, 130, 140]))
        self.assertTrue(np.array_equal(result[self.mask == 0], self.source[self.mask == 0]))
        self.assertFalse(telemetry['sourceInteriorUsed'])

    def test_soft_support_is_unassociated_and_kept_byte_exact(self):
        self.candidate[self.mask > 0] = [130, 140, 150]
        hard, _, _ = self.run_solver()
        self.mask[self.mask > 0] = 64
        saved = self.mask.copy()
        soft, _, _ = self.run_solver()
        self.assertTrue(np.array_equal(soft, hard))
        self.assertTrue(np.array_equal(saved, self.mask))
        # It must not return an already-alpha-blended candidate.
        self.assertTrue(np.all(soft[self.mask > 0] == [130, 140, 150]))
        once = np.rint(100 + (soft[self.mask > 0].astype(float) - 100) * 64 / 255)
        self.assertTrue(np.all(once == [108, 110, 113]))

    def test_validation_guards_precede_factorization(self):
        with mock.patch.object(h, 'splu') as solver:
            self.mask[:] = 0
            self.assert_rejected('empty_domain')
            self.mask[0, 2] = 1
            self.assert_rejected('edge_domain')
            self.mask[:] = 0
            self.mask[500:1012, 500:756] = 1
            self.mask[1012, 500] = 1
            self.assertEqual(np.count_nonzero(self.mask), 131_073)
            self.assert_rejected('domain_limit')
            self.mask = np.zeros((1024, 1024), np.uint8)
            self.assert_rejected('invalid_input')
            self.mask = np.zeros((2048, 2048), np.float32)
            self.assert_rejected('invalid_input')
            solver.assert_not_called()

    def test_nonfinite_and_bad_residual_solutions_fail_closed(self):
        real_splu = h.splu
        for value, code in [(float('nan'), 'nonfinite_solution'), (float('inf'), 'nonfinite_solution'),
                            (1.0, 'residual_limit')]:
            def fake(*args, **kwargs):
                factor = real_splu(*args, **kwargs)
                return mock.Mock(L=factor.L, U=factor.U,
                                 solve=lambda rhs: np.full_like(rhs, value))
            with mock.patch.object(h, 'splu', side_effect=fake):
                self.assert_rejected(code)

    def test_factor_storage_and_memory_errors_fail_without_fallback(self):
        with mock.patch.object(h, 'MAX_FACTOR_STORAGE_BYTES', 1):
            self.assert_rejected('factor_memory_limit')
        with mock.patch.object(h, 'splu', side_effect=MemoryError):
            self.assert_rejected('memory_failure')
        with mock.patch.object(h, '_pixel_hash', side_effect=MemoryError):
            self.assert_rejected('memory_failure')

    def isolated_clip_fixture(self, pixels=100, clipped=5, overshoot=64, below=False):
        self.mask[:] = 0
        coords = [(700 + (i // 10) * 3, 700 + (i % 10) * 3) for i in range(pixels)]
        for y, x in coords:
            self.mask[y, x] = 255
        # Source exterior fixes a constant +/-64 correction. Isolated domain
        # pixels make the exact boundary thresholds independent of conditioning.
        self.source[:] = 100
        self.candidate[:] = 100 + overshoot if below else 100 - overshoot
        for y, x in coords:
            self.candidate[y, x] = 100
        for y, x in coords[:clipped]:
            self.candidate[y, x, 0] = 0 if below else 255

    def test_clipping_exact_limits_pass_but_remain_unreviewed_both_directions(self):
        for below in [False, True]:
            self.isolated_clip_fixture(below=below)
            result, stats, clip = self.run_solver()
            self.assertEqual(stats['clippedPixels'], 5)
            self.assertEqual(stats['clippedChannels'], 5)
            self.assertEqual(stats['clippedPixelFraction'], 0.05)
            self.assertEqual(stats['maxOvershoot'], 64)
            self.assertEqual(np.count_nonzero(clip), 5)
            self.assertEqual(stats['status'], 'unreviewed')
            self.assertTrue(stats['requiresVisualReview'])
            self.assertTrue(np.all(result[clip > 0, 0] == (0 if below else 255)))

    def test_clipping_fraction_and_overshoot_above_limits_reject_both_directions(self):
        for below in [False, True]:
            self.isolated_clip_fixture(clipped=6, below=below)
            stats = self.assert_rejected('clipping_fraction_limit')
            self.assertEqual(stats['clippedPixels'], 6)
            self.isolated_clip_fixture(clipped=1, overshoot=65, below=below)
            stats = self.assert_rejected('clipping_overshoot_limit')
            self.assertEqual(stats['maxOvershoot'], 65)

    def test_compact_512_by_256_limit_has_bounded_factor_and_exact_offset_result(self):
        self.mask[:] = 0
        self.mask[700:1212, 700:956] = 255
        self.candidate[:] = [120, 85, 135]
        result, stats, _ = self.run_solver()
        self.assertEqual(stats['editablePixels'], 131_072)
        self.assertEqual(stats['components4Connected'], 1)
        self.assertLess(stats['factorBytes'], h.MAX_FACTOR_STORAGE_BYTES)
        self.assertTrue(np.array_equal(result, self.source))

    def test_near_square_exact_limit_has_bounded_factor_and_exact_offset_result(self):
        self.mask[:] = 0
        self.mask[700:1062, 700:1062] = 255
        self.mask[1062, 700:728] = 255
        self.candidate[:] = [120, 85, 135]
        result, stats, _ = self.run_solver()
        self.assertEqual(stats['editablePixels'], 131_072)
        self.assertEqual(stats['components4Connected'], 1)
        self.assertLess(stats['factorBytes'], h.MAX_FACTOR_STORAGE_BYTES)
        self.assertTrue(np.array_equal(result, self.source))

    def test_131072_disconnected_pixels_do_not_allocate_dense_operator(self):
        self.mask[:] = 0
        self.mask[500:1524:2, 600:1112:2] = 127
        self.candidate[:] = [120, 85, 135]
        result, stats, _ = self.run_solver()
        self.assertEqual(stats['editablePixels'], 131_072)
        self.assertEqual(stats['components4Connected'], 131_072)
        self.assertEqual(stats['matrixNnz'], 131_072)
        self.assertLess(stats['factorBytes'], 5_000_000)
        self.assertTrue(np.array_equal(result, self.source))


if __name__ == '__main__':
    unittest.main()
