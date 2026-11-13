"""Numerical tests for geometry and immutability, not provider image quality."""
import json
import tempfile
import unittest
from pathlib import Path
import sys
sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'resources' / 'iguide-lab'))

import numpy as np
from PIL import Image

import pano_geometry as g


class GeometryTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        w, h = 512, 256
        lon = ((np.arange(w)+.5)/w*2-1)*np.pi
        lat = (.5-(np.arange(h)+.5)/h)*np.pi
        self.array = np.stack([(np.cos(lat)[:, None]*np.sin(lon)[None, :]+1)*127.5,
                               np.broadcast_to((np.sin(lat)[:, None]+1)*127.5, (h, w)),
                               (np.cos(lat)[:, None]*np.cos(lon)[None, :]+1)*127.5], axis=-1).round().astype(np.uint8)
        self.source = self.root / 'source.png'
        Image.fromarray(self.array).save(self.source)

    def tearDown(self):
        self.temp.cleanup()

    def extract(self, array=None):
        if array is not None:
            Image.fromarray(array).save(self.source)
        g.extract(self.source, self.root/'cuts', 256)
        return self.root/'cuts'/'manifest.json'

    def test_smooth_roundtrip_covers_poles_and_wrap(self):
        m = self.extract()
        r = g.reproject(m, self.root/'roundtrip.png', roundtrip=True)
        self.assertEqual(r['uncovered_pixels'], 0)
        self.assertLess(r['resampling_error']['mae_0_255'], .3)
        self.assertLessEqual(r['resampling_error']['p95_absolute_channel_error'], 1)
        self.assertTrue(r['source_unchanged'])
        output = g.rgb(self.root/'roundtrip.png')
        self.assertLess(np.abs(output[:, [0, -1]].astype(float)-self.array[:, [0, -1]]).mean(), .3)
        # Conservative isotropic antialiasing softens the extreme pole, where longitude
        # sampling becomes singular. Keep an explicit error bound, not an exactness claim.
        self.assertLess(np.abs(output[[0, -1]].astype(float)-self.array[[0, -1]]).mean(), 3)

    def test_constant_roundtrip_is_pixel_exact(self):
        m = self.extract(np.full_like(self.array, [25, 127, 220]))
        r = g.reproject(m, self.root/'constant.png', roundtrip=True)
        self.assertTrue(r['resampling_error']['pixel_identical'])

    def test_empty_apply_preserves_every_source_pixel(self):
        m = self.extract()
        r = g.reproject(m, self.root/'unchanged.png', {'views': []})
        self.assertTrue(np.array_equal(g.rgb(self.root/'unchanged.png'), self.array))
        self.assertEqual(r['changed_pixels_outside_coverage'], 0)

    def test_residual_noop_is_exact_identity_despite_resampling(self):
        m = self.extract()
        manifest = json.loads(m.read_text())
        views = []
        for v in manifest['views']:
            copied = self.root/f"unchanged-{v['id']}.png"
            copied.write_bytes(Path(v['path']).read_bytes())
            views.append({'view_id':v['id'], 'image_path':str(copied)})
        r = g.reproject(m, self.root/'residual-noop.png', {'views':views}, blend_mode='residual')
        self.assertTrue(np.array_equal(g.rgb(self.root/'residual-noop.png'), self.array))
        self.assertTrue(r['residual']['source_pixel_difference']['pixel_identical'])
        self.assertEqual(r['residual']['clipped_pixel_count'], 0)
        self.assertNotIn('resampling_error', r)

    def test_residual_controlled_patch_and_outside_identity(self):
        m = self.extract(np.full_like(self.array, [50, 80, 110]))
        Image.new('RGB', (256, 256), (70, 80, 110)).save(self.root/'edit.png')
        mask = np.zeros((256, 256), np.uint8)
        mask[80:176, 80:176] = 255
        Image.fromarray(mask).save(self.root/'mask.png')
        r = g.reproject(m, self.root/'residual-patch.png', {'views': [{'view_id':'front', 'image_path':str(self.root/'edit.png'), 'mask_path':str(self.root/'mask.png')}]}, blend_mode='residual')
        b = g.rgb(self.root/'residual-patch.png')
        self.assertTrue(np.array_equal(b[128, 256], [70, 80, 110]))
        self.assertTrue(np.array_equal(b[0], np.full_like(b[0], [50, 80, 110])))
        self.assertEqual(r['changed_pixels_outside_coverage'], 0)
        self.assertGreater(r['outside_coverage_pixels'], 512*256*.8)
        self.assertEqual(r['residual']['clipped_pixel_count'], 0)
        alpha = np.asarray(Image.open(r['coverage_alpha_path']))
        self.assertTrue(np.array_equal(b[alpha==0], g.rgb(self.source)[alpha==0]))

    def test_masked_apply_leaves_outside_coverage_exact(self):
        m = self.extract()
        Image.new('RGB', (256, 256), (255, 0, 0)).save(self.root/'edit.png')
        mask = np.zeros((256, 256), np.uint8)
        mask[80:176, 80:176] = 255
        Image.fromarray(mask).save(self.root/'mask.png')
        r = g.reproject(m, self.root/'masked.png', {'views': [{'view_id': 'front', 'image_path': str(self.root/'edit.png'), 'mask_path': str(self.root/'mask.png')}]})
        b = g.rgb(self.root/'masked.png')
        self.assertGreater(r['outside_coverage_pixels'], self.array.shape[0]*self.array.shape[1]*.8)
        self.assertEqual(r['changed_pixels_outside_coverage'], 0)
        self.assertTrue(np.array_equal(b[128, 256], [255, 0, 0]))
        self.assertTrue(np.array_equal(b[0], self.array[0]))
        alpha = np.asarray(Image.open(r['coverage_alpha_path']))
        self.assertTrue(np.array_equal(b[alpha==0], self.array[alpha==0]))

    def test_changed_output_dimensions_are_rejected(self):
        m = self.extract()
        Image.new('RGB', (256, 128)).save(self.root/'wrong.png')
        with self.assertRaisesRegex(ValueError, 'dimensions changed'):
            g.reproject(m, self.root/'out.png', {'views': [{'view_id':'front', 'image_path':str(self.root/'wrong.png')}]})

    def test_mutated_source_is_rejected(self):
        m = self.extract()
        Image.new('RGB', (512, 256)).save(self.source)
        with self.assertRaisesRegex(ValueError, 'Original checksum changed'):
            g.reproject(m, self.root/'out.png', roundtrip=True)

    def test_mutated_cut_is_rejected(self):
        m = self.extract()
        Image.new('RGB', (256, 256)).save(self.root/'cuts'/'views'/'front.png')
        with self.assertRaisesRegex(ValueError, 'view checksum changed'):
            g.reproject(m, self.root/'out.png', roundtrip=True)

    def test_original_cannot_be_overwritten(self):
        m = self.extract()
        with self.assertRaisesRegex(ValueError, 'overwrite the original'):
            g.reproject(m, self.source, roundtrip=True)

    def test_cardinal_view_directions(self):
        # The synthetic RGB source encodes ray direction. Center samples should follow yaw/pitch.
        front = g.perspective(self.array, 0, 0, 100, (256, 256))[128, 128]
        right = g.perspective(self.array, 90, 0, 100, (256, 256))[128, 128]
        up = g.perspective(self.array, 0, 90, 100, (256, 256))[128, 128]
        self.assertGreater(front[2], 250)
        self.assertGreater(right[0], 250)
        self.assertGreater(up[1], 250)

    def test_insufficient_fov_is_rejected(self):
        with self.assertRaisesRegex(ValueError, 'HFOV'):
            g.extract(self.source, self.root/'badcuts', 256, 90)

    def test_minified_stripes_are_filtered_before_sampling(self):
        stripes = np.broadcast_to(((np.arange(2048)%2)*255)[None, :, None], (1024, 2048, 3)).astype(np.uint8)
        cut = g.perspective(stripes, 0, 0, 100, (64, 64))
        self.assertLess(float(cut[8:-8, 8:-8].std()), 2)
        self.assertAlmostEqual(float(cut[8:-8, 8:-8].mean()), 127.5, delta=1)

    def test_float32_wrap_rounding_cannot_index_past_image(self):
        a = np.full((32, 5504, 3), 90, np.uint8)
        color = g.bilinear(a, np.array([[-.000001]], np.float32), np.array([[15]], np.float32), True)
        self.assertTrue(np.array_equal(color[0, 0], [90, 90, 90]))

    def test_custom_rectilinear_view_preserves_projection_metadata(self):
        m = g.extract(self.source, self.root/'custom', view_specs=[{'id':'island', 'yaw_degrees':0, 'pitch_degrees':-10, 'hfov_degrees':90, 'width':256, 'height':128}])
        self.assertFalse(m['layout']['full_sphere_coverage_expected'])
        self.assertEqual(m['views'][0]['pitch_degrees'], -10)
        self.assertAlmostEqual(m['views'][0]['vfov_degrees'], 53.130102354)
        with self.assertRaisesRegex(ValueError, 'Coverage failed'):
            g.reproject(self.root/'custom'/'manifest.json', self.root/'partial-roundtrip.png', roundtrip=True)

    def test_unsafe_view_filename_is_rejected(self):
        with self.assertRaisesRegex(ValueError, 'safe lowercase'):
            g.extract(self.source, self.root/'unsafe', view_specs=[{'id':'../original', 'yaw_degrees':0, 'pitch_degrees':0, 'hfov_degrees':90, 'width':256, 'height':256}])

    def test_landmarks_wrap_horizontally(self):
        landmarks = self.root/'landmarks.json'
        landmarks.write_text(json.dumps({'coordinate_space':'normalized_equirectangular','landmarks':[{'name':'seam landmark','source_uv':[.99,.5],'candidate_uv':[.01,.5],'tolerance_preview_pixels':12}]}))
        r = g.qa(self.source, self.source, self.root/'qa', 512, landmarks=landmarks)
        self.assertAlmostEqual(r['manual_landmarks'][0]['displacement_preview_pixels'], 10.24)
        self.assertFalse(r['manual_landmarks'][0]['flag'])
        self.assertEqual(r['edge_alignment']['fraction_source_edges_unmatched'], None) # Smooth direction field has no strong edges.


if __name__ == '__main__':
    unittest.main(verbosity=2)
