import importlib.util
import json
import os
from pathlib import Path
import sys
import tempfile
import unittest

import numpy as np
from PIL import Image

SCRIPT = Path(os.environ.get('IGUIDE_SECTION_GEOMETRY_SCRIPT', Path(__file__).resolve().parents[2] / 'resources/iguide-lab/section_geometry.py'))
sys.path.insert(0, str(SCRIPT.parent))
spec = importlib.util.spec_from_file_location('section_geometry', SCRIPT)
adapter = importlib.util.module_from_spec(spec); spec.loader.exec_module(adapter)


class SectionGeometryTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(); self.root = Path(self.temp.name).resolve()
        x = np.linspace(20, 220, 2048, dtype=np.uint8)
        self.source = np.stack([np.broadcast_to(x, (1024, 2048)), np.full((1024, 2048), 100, np.uint8), np.full((1024, 2048), 130, np.uint8)], axis=-1)
        Image.fromarray(self.source).save(self.root / 'source.png')

    def tearDown(self):
        self.temp.cleanup()

    def descriptor(self, name):
        path = self.root / name
        return {'path': str(path), 'sha256': adapter.geometry.sha256(path)}

    def extract_request(self):
        return {'schemaVersion': 1, 'operation': 'extract', 'privateRoot': str(self.root), 'source': self.descriptor('source.png'),
                'view': {'yaw': 179, 'pitch': -12, 'hFov': 100}, 'outputDirectory': str(self.root / 'extract')}

    def test_extract_replay_is_identical_and_cached_cut_drift_is_rejected(self):
        request = self.extract_request(); report = adapter.run(request)
        self.assertEqual(report, adapter.run(request))
        manifest = json.loads(Path(report['artifacts']['geometry']['path']).read_text())
        view = manifest['views'][0]
        self.assertEqual((view['yaw_degrees'], view['pitch_degrees'], view['hfov_degrees']), (179, -12, 100))
        Path(report['artifacts']['cut']['path']).write_bytes(b'corrupt')
        with self.assertRaises(ValueError): adapter.run(request)

    def test_native_composition_preserves_source_outside_actual_alpha(self):
        request = self.extract_request(); cut = adapter.run(request)
        pixels = np.asarray(Image.open(cut['artifacts']['cut']['path']).convert('RGB')).copy()
        pixels[700:1300, 700:1300] = [20, 200, 60]; Image.fromarray(pixels).save(self.root / 'edited.png')
        mask = np.zeros((2048, 2048), np.uint8); mask[750:1250, 750:1250] = 255; Image.fromarray(mask).save(self.root / 'mask.png')
        compose = {'schemaVersion': 1, 'operation': 'compose-section', 'privateRoot': str(self.root), 'source': request['source'],
                   'geometry': cut['artifacts']['geometry'], 'candidate': self.descriptor('edited.png'), 'mask': self.descriptor('mask.png'), 'outputDirectory': str(self.root / 'composed')}
        report = adapter.run(compose)
        output = np.asarray(Image.open(report['artifacts']['native']['path']))
        alpha = np.asarray(Image.open(report['artifacts']['alpha']['path']))
        self.assertTrue(np.array_equal(output[alpha == 0], self.source[alpha == 0]))
        self.assertGreater(np.count_nonzero(output != self.source), 0)
        self.assertEqual(report['statistics']['outsideAlphaChangedPixels'], 0)
        self.assertFalse(report['automaticAcceptance'])
        self.assertEqual(report, adapter.run(compose))

    def test_whole_panorama_preserves_each_supplied_feather_value_without_new_mask_inference(self):
        Image.fromarray(np.full_like(self.source, 220)).save(self.root / 'candidate.png')
        mask = np.zeros((1024, 2048), np.uint8); mask[400:500, :1024] = 17; mask[400:500, 1024:] = 193
        Image.fromarray(mask).save(self.root / 'mask.png')
        request = {'schemaVersion': 1, 'operation': 'compose-grass', 'privateRoot': str(self.root), 'source': self.descriptor('source.png'),
                   'candidate': self.descriptor('candidate.png'), 'mask': self.descriptor('mask.png'), 'outputDirectory': str(self.root / 'grass')}
        result = adapter.run(request)
        actual = np.asarray(Image.open(result['artifacts']['alpha']['path']))
        self.assertTrue(np.array_equal(actual, mask))
        output = np.asarray(Image.open(result['artifacts']['preview']['path']))
        expected = np.rint(self.source.astype(np.float32) * (1 - mask[:, :, None] / np.float32(255)) + 220 * (mask[:, :, None] / np.float32(255))).astype(np.uint8)
        self.assertTrue(np.array_equal(output, expected))

    def test_invalid_geometry_source_hash_and_path_escape_fail_before_creating_output(self):
        for field, value in [('yaw', float('nan')), ('pitch', 91), ('hFov', 150)]:
            request = self.extract_request(); request['view'][field] = value
            with self.assertRaises(ValueError): adapter.run(request)
        request = self.extract_request(); request['source']['sha256'] = '0' * 64
        with self.assertRaises(ValueError): adapter.run(request)
        request = self.extract_request(); request['outputDirectory'] = str(self.root.parent / 'outside-project')
        with self.assertRaises(ValueError): adapter.run(request)
        self.assertFalse((self.root / 'extract').exists())

    def test_cut_taper_colored_transparent_and_empty_masks_are_rejected(self):
        for mode in ['edge', 'colored', 'transparent', 'empty']:
            pixels = np.zeros((2048, 2048, 4), np.uint8); pixels[:, :, 3] = 255
            if mode == 'edge': pixels[5:8, 800:900, :3] = 255
            if mode == 'colored': pixels[900:1000, 900:1000, 0] = 255
            if mode == 'transparent': pixels[900:1000, 900:1000, 3] = 0
            path = self.root / (mode + '.png'); Image.fromarray(pixels).save(path)
            with self.assertRaises(ValueError): adapter.mask_pixels(path, (2048, 2048), True)


if __name__ == '__main__': unittest.main()
