import copy
import hashlib
from pathlib import Path
import sys
import unittest

import numpy as np

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'resources' / 'iguide-lab'))
import object_layer_compositor as c
import pano_geometry as g
import workflow_qa as old


class ObjectLayerTests(unittest.TestCase):
    def fixture(self, near=True, yaw=0, pitch=0, width=2048):
        h = width//2
        source = np.full((h, width, 3), [60, 100, 140], np.uint8)
        candidate = np.full((256, 256, 3), [210, 80, 30], np.uint8)
        alpha = np.zeros((256, 256), np.uint8)
        alpha[90:140, 206:239] = 255 if near else 0
        if not near:
            alpha[85:145, 85:145] = 255
        view = {'width': 256, 'height': 256, 'hfov_degrees': 90.,
                'yaw_degrees': float(yaw), 'pitch_degrees': float(pitch)}
        return source, candidate, alpha, view

    def run_it(self, args, **kwargs):
        return c.recompose_objects(*args, enabled=True, **kwargs)

    def exterior(self, source, result, effective):
        np.testing.assert_array_equal(result[effective == 0], source[effective == 0])

    def test_default_disabled_and_frozen_production_gate_remains(self):
        args = self.fixture()
        with self.assertRaisesRegex(ValueError, 'DISABLED'):
            c.recompose_objects(*args)
        with self.assertRaisesRegex(old.WorkerError, 'TAPER'):
            old.recompose_arrays(*args)

    def test_opaque_near_edge_object_is_not_faded(self):
        args = self.fixture(); before = args[2].copy()
        result, effective, alpha, _, report = self.run_it(args)
        x, y, z = g.sphere_rays(2048, 1024, 0, 1024)
        u, v, taper = g.project_view(x, y, z, args[3])
        interior = (u > 232) & (u < 236) & (v > 95) & (v < 135)
        self.assertTrue(np.any(interior & (taper < .9)))
        self.assertTrue(np.all(alpha[interior] == 255))
        self.assertTrue(np.all(result[interior] == args[1][0, 0]))
        self.assertFalse(report['sectionTaperApplied']); self.assertEqual('unreviewed', report['status'])
        self.assertFalse(report['automaticAcceptance']); self.exterior(args[0], result, effective)
        np.testing.assert_array_equal(args[2], before)

    def test_current_interior_result_is_bit_exact_when_taper_was_already_one(self):
        args = self.fixture(near=False)
        a = self.run_it(args); b = old.recompose_arrays(*args)
        for expected, actual in zip(a[:4], b[:4]):
            np.testing.assert_array_equal(expected, actual)

    def test_donor_background_color_cannot_contaminate_object_edges(self):
        for width in [2048, 512]:
            with self.subTest(native_width=width):
                args = self.fixture(near=False, width=width)
                # Include partial coverage and a transparent interior hole.
                args[2][85, 85:145] = 96
                args[2][105:125, 105:125] = 0
                first = list(args); second = list(args)
                first[1] = args[1].copy(); second[1] = args[1].copy()
                first[1][args[2] == 0] = [255, 0, 255]
                second[1][args[2] == 0] = [0, 255, 0]
                a = self.run_it(first); b = self.run_it(second)
                self.assertTrue(np.any((a[2] > 0) & (a[2] < 255)))
                for expected, actual in zip(a[:4], b[:4]):
                    np.testing.assert_array_equal(expected, actual)
                self.exterior(args[0], a[0], a[1])
                self.assertEqual('premultiplied_linear_light_float_mips', a[4]['colorSampling'])
                self.assertFalse(a[4]['zeroAlphaDonorColorUsed'])

    def test_float_mips_preserve_constant_foreground_under_minification(self):
        args = self.fixture(near=False, width=512)
        result, effective, alpha, _, _ = self.run_it(args)
        a = alpha.astype(np.float32)/255
        expected = np.rint(g.from_linear(g.to_linear(args[0])*(1-a[..., None])
                                        + g.to_linear(args[1][0, 0])*a[..., None])).astype(np.uint8)
        np.testing.assert_array_equal(result, expected)
        self.exterior(args[0], result, effective)

    def test_actual_support_touching_cut_edge_is_rejected_without_clipping(self):
        args = self.fixture(); args[2][100:120, 250:] = 255; before = args[2].copy()
        with self.assertRaisesRegex(ValueError, 'SOURCE_BORDER'):
            self.run_it(args)
        np.testing.assert_array_equal(args[2], before)

    def test_nonzero_shadow_also_requires_complete_frame_support(self):
        args = self.fixture(); shadow = np.zeros_like(args[2]); shadow[150:170, -1] = 20
        with self.assertRaisesRegex(ValueError, 'SOURCE_BORDER'):
            self.run_it(args, scalar_shadow=shadow, floor_allowed=np.full_like(shadow, 255))

    def test_small_nominal_margin_does_not_bypass_minification_filter_guard(self):
        args = self.fixture(width=128)
        with self.assertRaisesRegex(ValueError, 'FILTER_FOOTPRINT_GUARD|TRUNCATED_MIP|CLAMPED_BILINEAR'):
            self.run_it(args)

    def test_tiny_coarsely_sampled_edge_support_cannot_evade_active_sample_guard(self):
        args = self.fixture(width=128); args[2][:] = 0; args[2][120, 252] = 255
        before = args[2].copy()
        with self.assertRaisesRegex(ValueError, 'FILTER_FOOTPRINT_GUARD'):
            self.run_it(args)
        np.testing.assert_array_equal(before, args[2])

    def test_source_mask_hole_and_input_topology_are_not_filled_or_cropped(self):
        args = self.fixture(); args[2][106:123, 216:230] = 0
        digest = hashlib.sha256(args[2].tobytes()).hexdigest()
        result, effective, alpha, _, report = self.run_it(args)
        x, y, z = g.sphere_rays(2048, 1024, 0, 1024); u, v, _ = g.project_view(x, y, z, args[3])
        hole = (u > 219) & (u < 226) & (v > 110) & (v < 119)
        self.assertTrue(np.any(hole)); self.assertTrue(np.all(alpha[hole] == 0))
        self.assertEqual(digest, hashlib.sha256(args[2].tobytes()).hexdigest())
        self.assertFalse(report['supportClippedOrFilled']); self.exterior(args[0], result, effective)

    def test_wrap_and_polar_projection_keep_complete_support_and_exact_exterior(self):
        for yaw, pitch in [(179, 0), (0, 90), (0, -90)]:
            with self.subTest(yaw=yaw, pitch=pitch):
                args = self.fixture(near=False, yaw=yaw, pitch=pitch)
                result, effective, alpha, _, _ = self.run_it(args)
                self.assertTrue(np.any(alpha == 255)); self.exterior(args[0], result, effective)
                if yaw == 179:
                    self.assertTrue(np.any(alpha[:, 0])); self.assertTrue(np.any(alpha[:, -1]))
                if pitch == 90:
                    self.assertTrue(np.all(alpha[0] == 255))
                if pitch == -90:
                    self.assertTrue(np.all(alpha[-1] == 255))

    def test_tiny_scalar_shadow_retains_strength_near_edge_without_taper(self):
        args = self.fixture(); shadow = np.zeros_like(args[2]); shadow[152:168, 210:238] = 51
        before = shadow.copy()
        result, effective, obj, applied, report = self.run_it(args, scalar_shadow=shadow, floor_allowed=np.full_like(shadow, 255))
        self.assertEqual(51, int(applied.max())); self.assertGreater(report['shadowOnlyPixels'], 0)
        only = (obj == 0) & (applied > 0)
        expected = np.rint(g.from_linear(g.to_linear(args[0])*(1-applied[..., None].astype(np.float32)/255))).astype(np.uint8)
        np.testing.assert_array_equal(expected[only], result[only]); np.testing.assert_array_equal(shadow, before)
        self.exterior(args[0], result, effective)

    def test_shadow_outside_source_floor_is_rejected_without_trimming(self):
        args = self.fixture(); shadow = np.zeros_like(args[2]); shadow[152:168, 210:238] = 51
        floor = np.full_like(shadow, 255); floor[160, 220] = 0
        with self.assertRaisesRegex(ValueError, 'OUTSIDE_CERTAIN_FLOOR'):
            self.run_it(args, scalar_shadow=shadow, floor_allowed=floor)

    def test_dependency_mask_rejects_recursive_lanczos_boundary_even_when_base_is_in_frame(self):
        flags = c._axis_complete([256, 128, 64])
        self.assertTrue(flags[0].all()); self.assertFalse(flags[1][0]); self.assertFalse(flags[2][1])
        self.assertFalse(flags[1][2])  # C trunc(-0.5)==0 must not hide an outside tap.
        self.assertTrue(flags[2][32])

    def test_negative_fractional_filter_endpoint_cannot_pass_as_zero_integer_bound(self):
        levels = [np.zeros((256, 256), np.uint8), np.zeros((128, 128), np.uint8)]
        u = np.array([[5, 7], [5, 7]], np.float32); v = np.array([[128, 128], [130, 130]], np.float32)
        active = np.array([[True, False], [False, False]])
        complete = c._axis_complete([256, 128])
        with self.assertRaisesRegex(ValueError, 'TRUNCATED_MIP'):
            c._guard_sample_footprints(levels, u, v, active, complete, complete)


if __name__ == '__main__':
    unittest.main()
