import sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'resources' / 'iguide-lab'))
import unittest
import numpy as np
import bedroom_support as core


class BedroomSupportTests(unittest.TestCase):
    def fixture(self):
        n = 512
        source = np.full((n, n, 3), 150, np.uint8); candidate = source.copy()
        boxes = {'bed': (180, 210, 331, 391), 'nightstand_left': (90, 220, 161, 281), 'nightstand_right': (350, 220, 421, 281)}
        objects, contacts = {}, {}
        for role, (x0, y0, x1, y1) in boxes.items():
            mask = np.zeros((n, n), np.uint8); mask[y0:y1, x0:x1] = 255; objects[role] = mask
            candidate[mask > 0] = [80, 95, 110]
            contacts[role] = [[[x0 + 4, y1 - 1], [x1 - 5, y1 - 1]]]
        floor = np.zeros((n, n), np.uint8); floor[150:, :] = 255
        pose = {'yaw': 0., 'pitch': -20., 'hFov': 100.}
        context = {'profileId': 'empty-bedroom-platform-three-piece/1', 'sourceEmptyBedroom': True,
                   'placementStatus': 'ready_for_masks', 'foregroundAssessment': 'none_observed',
                   'opticalAssessment': 'clear_for_actual_placement', 'selectionOrigin': 'machine', 'sourceContextSha256': 'a' * 64}
        return source, candidate, objects, floor, [], pose, context, contacts

    def derive(self, args): return core.derive_support(*args, refine=False)

    def test_real_bed_profile_uses_visible_plinth_contacts_and_keeps_shadow_on_original_floor(self):
        args = self.fixture(); result = self.derive(args); report = result['report']
        self.assertEqual('ready_for_unreviewed_composition', report['status'], report['reviewReasons'])
        self.assertEqual(list(core.ROLES), [x['id'] for x in report['objects']])
        self.assertFalse(report['legacyContactHeuristicsUsed']); self.assertFalse(report['automaticAcceptance'])
        self.assertEqual(0, report['shadowOutsideSafeFloorPixels']); self.assertTrue(np.any(result['scalarShadow']))
        self.assertLessEqual(result['scalarShadow'].max(), 51)
        self.assertTrue(np.array_equal(args[0], np.full_like(args[0], 150)))

    def test_furniture_roles_cannot_be_renamed_into_a_bed(self):
        args = list(self.fixture()); args[2] = dict(zip(('sofa', 'chair', 'coffee_table'), args[2].values()))
        with self.assertRaisesRegex(ValueError, 'PROFILE_IDENTITIES'): self.derive(args)

    def test_unresolved_optics_foreground_or_machine_origin_hold_without_any_shadow(self):
        for key, bad in [('sourceEmptyBedroom', False), ('placementStatus', 'needs_review'), ('foregroundAssessment', 'unknown'), ('opticalAssessment', 'mirror_dependency'), ('selectionOrigin', 'human')]:
            args = list(self.fixture()); args[6][key] = bad; result = self.derive(args)
            self.assertEqual('needs_review', result['report']['status']); self.assertFalse(np.any(result['scalarShadow']))

    def test_interior_top_edge_and_wrong_floor_do_not_become_contact_evidence(self):
        args = list(self.fixture()); args[7]['bed'] = [[[185, 210], [325, 210]]]
        result = self.derive(args); self.assertEqual('needs_review', result['report']['status']); self.assertFalse(np.any(result['scalarShadow']))
        args = list(self.fixture()); args[3][390:, :] = 0
        self.assertEqual('needs_review', self.derive(args)['report']['status'])

    def test_missing_tiny_duplicate_and_unbounded_contacts_hold(self):
        for segments in ([], [[[180, 390], [181, 390]]], [[[185, 390], [325, 390]], [[185, 390], [325, 390]]], [[[0, 390], [511, 390]]]):
            args = list(self.fixture()); args[7]['bed'] = segments
            result = self.derive(args); self.assertEqual('needs_review', result['report']['status']); self.assertFalse(np.any(result['scalarShadow']))

    def test_actual_mask_overlap_protected_feature_and_section_guard_hold(self):
        args = list(self.fixture()); args[2]['nightstand_left'][230:250, 180:200] = 255
        self.assertIn('ACTUAL_INSTANCE_OVERLAP_UNRESOLVED', self.derive(args)['report']['reviewReasons'])
        args = list(self.fixture()); feature = np.zeros((512, 512), np.uint8); feature[250, 190] = 255; args[4] = [feature]
        self.assertIn('OBJECT_OVERLAPS_FIXED_SOURCE_FEATURE', self.derive(args)['report']['reviewReasons'])
        args = list(self.fixture()); args[2]['bed'][20:30, 200:220] = 255
        self.assertIn('OBJECT_TOUCHES_SECTION_GUARD', self.derive(args)['report']['reviewReasons'])

    def test_soft_masks_and_nonfinite_pose_are_rejected_without_thresholding(self):
        args = list(self.fixture()); args[2]['bed'][250, 200] = 127
        with self.assertRaises(ValueError): self.derive(args)
        args = list(self.fixture()); args[5]['pitch'] = float('nan')
        with self.assertRaisesRegex(ValueError, 'POSE'): self.derive(args)

    def test_floor_hidden_by_another_group_object_cannot_support_a_visible_plinth_claim(self):
        args = list(self.fixture())
        # No pixel overlap, but a foreground table starts directly below the claimed bed contact.
        mask = np.zeros((512, 512), np.uint8); mask[391:440, 180:331] = 255
        args[2]['nightstand_left'] = mask
        args[7]['nightstand_left'] = [[[185, 439], [325, 439]]]
        result = self.derive(args)
        self.assertIn('VISIBLE_BASE_LACKS_ORIGINAL_SAFE_FLOOR', result['report']['reviewReasons'])
        self.assertFalse(np.any(result['scalarShadow']))

    def test_deterministic_bytes_without_a_semantic_acceptance_claim(self):
        a = self.derive(self.fixture()); b = self.derive(self.fixture())
        for key in ('objectAlpha', 'scalarShadow', 'safeFloorAlpha'): self.assertTrue(np.array_equal(a[key], b[key]))
        self.assertEqual(0, a['report']['providerCalls']); self.assertFalse(a['report']['nativeCompositionPerformed'])


if __name__ == '__main__': unittest.main()
