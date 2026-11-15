import copy
import hashlib
import sys
import unittest
from pathlib import Path
import cv2
import numpy as np
sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'resources/iguide-lab'))
import room_floor_union as u
import room_floor_witness as old

def selection():
    return {'status': 'opaque_matte', 'prompt': u.LABEL, 'regions': [[200, 1200, 1200, 1800], [1100, 1100, 1800, 1800]],
            'planeEvidence': {'status': 'same_visible_target_floor', 'evidenceViewIds': sorted(u.IDS), 'reason': 'Synthetic original plane only.'},
            'contactEnvelopes': [{'objectId': r, 'box': b} for r, b in zip(u.ROLES[0], [[330, 1500, 570, 1580], [1420, 1500, 1680, 1580], [875, 1500, 1120, 1580]])]}

class FloorUnionTest(unittest.TestCase):
    def witness(self):
        s=selection(); a,b=u.domain(s)
        return {'version':u.VERSION,'selection':s,'sourceSha256':'a'*64,'nativeSourceSha256':'b'*64,'rawMaskSha256':'c'*64,
                'planSha256':'d'*64,'assessmentSha256':'e'*64,'contextSha256':'f'*64,
                'usablePixelsSha256':hashlib.sha256(a.tobytes()).hexdigest(),'safePixelsSha256':hashlib.sha256(b.tobytes()).hexdigest()}
    def test_union_and_erosion_no_bridge_or_internal_seam(self):
        a,b=u.domain(selection()); self.assertEqual(a[1150,500],0);self.assertEqual(b[1300,1100],255);self.assertEqual(b[1200,500],0);self.assertEqual(b[1206,500],255)
        self.assertEqual(np.count_nonzero(a),1001*601+701*701-101*601)
        kernel=cv2.getStructuringElement(cv2.MORPH_ELLIPSE,(13,13));self.assertEqual([(np.count_nonzero(row)-1)//2 for row in kernel],[0,3,4,5,6,6,6,6,6,5,4,3,0])
    def test_mask_hole_gray_source_and_digest_drift_fail(self):
        w=self.witness();m=np.full((2048,2048),255,np.uint8);usable,report=u.restrict_floor(m,w,'a'*64,'b'*64,'c'*64)
        self.assertFalse(report['objectAlphaRestrictedByWitness']);self.assertEqual(usable[0,0],0)
        for value in [0,128]:
            bad=m.copy();bad[1400,500]=value
            with self.assertRaises(ValueError):u.restrict_floor(bad,w,'a'*64,'b'*64,'c'*64)
        for key in ['usablePixelsSha256','safePixelsSha256','sourceSha256','nativeSourceSha256','rawMaskSha256']:
            bad=copy.deepcopy(w);bad[key]='0'*64
            with self.assertRaises(ValueError):u.restrict_floor(m,bad,'a'*64,'b'*64,'c'*64)
    def test_gap_and_edge_contact_envelopes_are_held(self):
        for box in [[330,1200,570,1280],[330,1110,570,1180]]:
            s=selection();s['contactEnvelopes'][0]['box']=box
            with self.assertRaises(ValueError):u.domain(s)
        s=selection();s['regions']=[[200,1200,900,1800],[1000,1200,1800,1800]]
        with self.assertRaises(ValueError):u.domain(s)
    def test_v2_and_v3_are_closed_and_no_wrong_plane_or_duplicate(self):
        for change in ['version','duplicate','plane','role','missing_view']:
            w=self.witness()
            if change=='version':w['version']=old.VERSION
            elif change=='duplicate':w['selection']['regions'].append(w['selection']['regions'][0])
            elif change=='plane':w['selection']['planeEvidence']['status']='different_level'
            elif change=='role':w['selection']['contactEnvelopes'][0]['objectId']='bed'
            else:w['selection']['planeEvidence']['evidenceViewIds'].pop()
            with self.assertRaises(ValueError):u.restrict_floor(np.full((2048,2048),255,np.uint8),w,'a'*64,'b'*64,'c'*64)

if __name__=='__main__':unittest.main()
