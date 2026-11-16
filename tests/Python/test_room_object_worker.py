"""Room object worker: draft furniture into the native panorama with the opaque object compositor."""
import importlib.util
from pathlib import Path
import sys
import tempfile
import unittest

import numpy as np
from PIL import Image

ROOT = Path(__file__).resolve().parents[2] / 'resources' / 'iguide-lab'
sys.path.insert(0, str(ROOT))
import room_object_worker as worker  # noqa: E402
import workflow_qa as q  # noqa: E402

spec = importlib.util.spec_from_file_location('section_geometry', ROOT / 'section_geometry.py')
sections = importlib.util.module_from_spec(spec); spec.loader.exec_module(sections)


class RoomObjectWorkerTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(); self.root = Path(self.temp.name).resolve()
        # 4096 wide keeps the draft close to native scale, as on a real 11008 wide capture.
        x = np.linspace(20, 220, 4096, dtype=np.uint8)
        self.source = np.stack([np.broadcast_to(x, (2048, 4096)), np.full((2048, 4096), 100, np.uint8),
                                np.full((2048, 4096), 130, np.uint8)], axis=-1)
        Image.fromarray(self.source).save(self.root / 'source.png')
        cut = sections.run({'schemaVersion': 1, 'operation': 'extract', 'privateRoot': str(self.root), 'source': self.descriptor('source.png'),
                            'view': {'yaw': 0, 'pitch': -10, 'hFov': 90}, 'outputDirectory': str(self.root / 'extract')})
        self.geometry = cut['artifacts']['geometry']
        draft = np.asarray(Image.open(cut['artifacts']['cut']['path']).convert('RGB')).copy()
        draft[900:1300, 800:1200] = [200, 40, 30]; draft[1400:1700, 1500:1800] = [30, 160, 60]; draft[1000:1200, 3:300] = [20, 20, 200]
        Image.fromarray(draft).save(self.root / 'draft.png')

    def tearDown(self):
        self.temp.cleanup()

    def descriptor(self, name):
        path = self.root / name
        return {'path': str(path), 'sha256': q.g.sha256(path)}

    def mask(self, name, *boxes):
        m = np.zeros((2048, 2048), np.uint8)
        for y0, y1, x0, x1 in boxes:
            m[y0:y1, x0:x1] = 255
        Image.fromarray(m).save(self.root / name)
        return self.descriptor(name)

    def request(self, masks, out='out', region=None):
        request = {'schemaVersion': 1, 'operation': 'compose-room-objects', 'privateRoot': str(self.root), 'source': self.descriptor('source.png'),
                   'candidate': self.descriptor('draft.png'), 'preset': 'interior-design', 'outputDirectory': str(self.root / out),
                   'geometry': self.geometry, 'objectMasks': masks}
        if region is not None:
            request['draftRegion'] = region
        return request

    def native(self, report, name):
        return np.asarray(Image.open(report['artifacts'][name]['path']))

    def test_furniture_masks_are_composited_and_everything_else_stays_byte_identical(self):
        request = self.request([self.mask('sofa.png', (900, 1300, 800, 1200)), self.mask('chair.png', (1400, 1700, 1500, 1800))])
        report = worker.run(request)
        self.assertEqual('completed', report['status']); self.assertEqual('compose-room-objects', report['operation'])
        self.assertFalse(report['automaticAcceptance']); self.assertEqual(0, report['providerCalls']); self.assertEqual('unreviewed', report['reviewStatus'])
        self.assertEqual('objects', report['objects']['fallback']); self.assertEqual(2, report['objects']['maskCount'])
        self.assertEqual({'native', 'effective-alpha', 'object-alpha', 'shadow-strength', 'preview', 'qa-wrap'} | {f'qa-{n}' for n, _, _ in q.g.FACES},
                         set(report['artifacts']))
        result, alpha = self.native(report, 'native'), self.native(report, 'effective-alpha')
        np.testing.assert_array_equal(result[alpha == 0], self.source[alpha == 0])
        self.assertGreater(int(np.count_nonzero(alpha == 255)), 1000)
        self.assertEqual(q.runtime()['workerSha256'], report['runtime']['workerSha256'])
        self.assertEqual(q.g.sha256(ROOT / 'room_object_worker.py'), report['objectRuntime']['objectWorkerSha256'])
        self.assertEqual({'cropMargin', 'clippedNativePixels', 'shadowOnlyPixels', 'shadowOnlyFormulaMismatchPixels', 'mode', 'alphaAppliedOnce',
                          'nativeFloorResampled', 'generatedDetailDimensions', 'nativeCanvasDimensions'}, set(report['composition']))
        self.assertEqual(report, worker.run(request))

    def test_furniture_near_the_draft_edge_is_kept_opaque_with_the_small_clearance(self):
        report = worker.run(self.request([self.mask('sofa.png', (900, 1300, 800, 1200), (1000, 1200, 32, 300))]))
        self.assertEqual('objects', report['objects']['fallback'])
        self.assertLessEqual(max(report['objects']['compositor']['requiredSourceClearancePixels']), 32)

    def test_furniture_touching_the_draft_edge_falls_back_to_an_edge_fade_instead_of_failing(self):
        report = worker.run(self.request([self.mask('sofa.png', (1000, 1200, 0, 300))]))
        self.assertEqual('completed', report['status']); self.assertEqual('edge_faded', report['objects']['fallback'])
        result, alpha = self.native(report, 'native'), self.native(report, 'effective-alpha')
        np.testing.assert_array_equal(result[alpha == 0], self.source[alpha == 0]); self.assertGreater(int(np.count_nonzero(alpha)), 0)

    def test_only_an_empty_or_unusable_mask_set_fails(self):
        with self.assertRaisesRegex(ValueError, 'EMPTY_OBJECT_MASK'):
            worker.run(self.request([self.mask('empty.png')]))
        with self.assertRaisesRegex(ValueError, 'OBJECT_MASK_COUNT'):
            worker.run(self.request([]))
        four = [self.mask(f'm{i}.png', (900, 1000, 800 + 50 * i, 840 + 50 * i)) for i in range(4)]
        with self.assertRaisesRegex(ValueError, 'OBJECT_MASK_COUNT'):
            worker.run(self.request(four, 'four'))
        self.assertFalse((self.root / 'four').exists())

    # Planned furniture and floor boxes of the layout, [x0, y0, x1, y1] on the 2048 draft.
    REGION = [[700, 850, 1300, 1350], [400, 1250, 1900, 1900]]

    def test_no_usable_furniture_mask_still_composites_the_draft_inside_the_planned_floor_region(self):
        report = worker.run(self.request([], 'region', self.REGION))
        self.assertEqual('completed', report['status']); self.assertEqual('draft_region', report['objects']['fallback'])
        self.assertEqual(0, report['objects']['maskCount']); self.assertEqual([], report['maskHashes'])
        result, alpha = self.native(report, 'native'), self.native(report, 'effective-alpha')
        np.testing.assert_array_equal(result[alpha == 0], self.source[alpha == 0]); self.assertGreater(int(np.count_nonzero(alpha)), 10000)
        self.assertEqual(report, worker.run(self.request([], 'region', self.REGION)))

    def test_furniture_entirely_inside_the_border_band_falls_back_to_the_draft_region(self):
        report = worker.run(self.request([self.mask('rim.png', (1000, 1200, 0, 30))], 'rim', self.REGION))
        self.assertEqual('completed', report['status']); self.assertEqual('draft_region', report['objects']['fallback'])
        self.assertGreater(int(np.count_nonzero(self.native(report, 'effective-alpha'))), 0)

    def test_an_empty_mask_with_a_region_falls_back_and_an_invalid_region_is_refused(self):
        report = worker.run(self.request([self.mask('empty.png')], 'empty-region', self.REGION))
        self.assertEqual('draft_region', report['objects']['fallback'])
        for region in ([], [[0, 0, 10]], [[100, 100, 50, 200]], [[0, 0, 2049, 10]], [[0.5, 0, 10, 10]]):
            with self.assertRaisesRegex(ValueError, 'DRAFT_REGION_INVALID'):
                worker.run(self.request([], 'bad', region))
        self.assertFalse((self.root / 'bad').exists())


if __name__ == '__main__':
    unittest.main()
