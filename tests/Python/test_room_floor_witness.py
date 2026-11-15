import copy
import hashlib
import json
from pathlib import Path
import sys
import tempfile
import unittest
sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'resources' / 'iguide-lab'))
import numpy as np
import room_floor_witness as w


class FloorWitnessTests(unittest.TestCase):
    def fixture(self):
        mask = np.zeros((128,128),np.uint8); mask[20:120,10:120] = 255
        box=[40,40,71,71]; usable=np.zeros_like(mask);usable[40:72,40:72]=255
        d={'version':w.VERSION,'semantics':'interior_support_witness','box':box,'sourceSha256':'a'*64,'nativeSourceSha256':'b'*64,
           'rawMaskSha256':'c'*64,'planSha256':'d'*64,'assessmentSha256':'e'*64,'contextSha256':'f'*64,
           'usablePixelsSha256':hashlib.sha256(usable.tobytes()).hexdigest()}
        return mask,d,usable
    def run_it(self,mask,d):return w.restrict_floor(mask,d,'a'*64,'b'*64,'c'*64)
    def test_large_floor_is_restricted_without_changing_raw_mask(self):
        mask,d,expected=self.fixture();before=mask.copy();usable,t=self.run_it(mask,d)
        np.testing.assert_array_equal(usable,expected);np.testing.assert_array_equal(before,mask)
        self.assertFalse(t['semanticClassVerified']);self.assertFalse(t['objectAlphaRestrictedByWitness'])
        self.assertGreater(t['rawPositivePixels'],t['usablePositivePixels'])
    def test_full_frame_mask_is_not_semantically_certified_and_cannot_authorize_outside_witness(self):
        mask,d,expected=self.fixture();mask[:]=255;usable,t=self.run_it(mask,d)
        np.testing.assert_array_equal(usable,expected);self.assertFalse(t['semanticClassVerified'])
    def test_sparse_empty_partial_wrong_plane_and_gray_hold(self):
        for kind in ['sparse','empty','partial','wrong_plane','gray']:
            mask,d,_=self.fixture()
            if kind=='sparse':mask[:]=0;mask[50,50]=255
            if kind=='empty':mask[:]=0
            if kind=='partial':mask[50,50]=0
            if kind=='wrong_plane':mask[:]=0;mask[:20,:]=255
            if kind=='gray':mask[50,50]=254
            with self.subTest(kind=kind),self.assertRaises(ValueError):self.run_it(mask,d)
    def test_identity_version_and_usable_digest_drift_reject(self):
        for key in ['sourceSha256','nativeSourceSha256','rawMaskSha256','usablePixelsSha256','version','semantics']:
            mask,d,_=self.fixture();d[key]='0'*64
            with self.subTest(key=key),self.assertRaises(ValueError):self.run_it(mask,d)
        mask,d,_=self.fixture();d['extra']=True
        with self.assertRaises(ValueError):self.run_it(mask,d)
    def test_box_shape_bounds_and_minimum_are_explicit(self):
        for box in [[40,40,54,71],[-1,40,71,71],[40,40,128,128],[40.,40,71,71],[40,40,71]]:
            mask,d,_=self.fixture();d['box']=box
            with self.subTest(box=box),self.assertRaises(ValueError):self.run_it(mask,d)


class FloorWorkerTests(unittest.TestCase):
    def setUp(self):
        from test_room_furnishing_support_worker import RoomWorkerTests
        self.fixture=RoomWorkerTests;self.temp=tempfile.TemporaryDirectory();self.root=Path(self.temp.name)
        self.request=self.fixture.fixture(self.root)
    def tearDown(self):self.temp.cleanup()
    def restrict(self,box):
        a=json.loads(Path(self.request['assessment']['path']).read_text());a['version']='interior-room-support-authority/2'
        pixels=np.zeros((2048,2048),np.uint8);pixels[box[1]:box[3]+1,box[0]:box[2]+1]=255
        a['sourceFloorWitness']={'version':w.VERSION,'semantics':'interior_support_witness','box':box,
            'sourceSha256':self.request['sourceCut']['sha256'],'nativeSourceSha256':a['nativeSourceSha256'],
            'rawMaskSha256':self.request['sourceFloor']['image']['sha256'],'planSha256':'f'*64,'assessmentSha256':a['layoutResultSha256'],
            'contextSha256':'1'*64,'usablePixelsSha256':hashlib.sha256(pixels.tobytes()).hexdigest()}
        self.request['assessment']=self.fixture.write(self.root,'assessment.json',a)
    def test_real_worker_domain_does_not_clip_object_alpha_and_replay_binds_raw_and_usable_assets(self):
        import room_furnishing_support_worker as worker
        old=worker.execute(self.request,self.root);oldalpha=Path(self.request['outputDirectory'],'object-alpha.png').read_bytes()
        self.restrict([300,1100,1750,1650]);self.request['outputDirectory']=str(self.root/'v2')
        report=worker.execute(self.request,self.root)
        self.assertEqual('ready_for_unreviewed_composition',report['status'],report['reviewReasons'])
        self.assertEqual(oldalpha,(self.root/'v2/object-alpha.png').read_bytes())
        self.assertEqual(0,report['shadowOutsideSafeFloorPixels']);self.assertEqual(report,worker.execute(self.request,self.root))
        self.assertEqual(self.request['sourceFloor']['image']['sha256'],report['floorDomain']['rawMaskSha256'])
        p=self.root/'v2/usable-floor.png';self.assertEqual(hashlib.sha256(p.read_bytes()).hexdigest(),report['assets']['usableFloor']['sha256'])
        p.write_bytes(p.read_bytes()+b'drift')
        with self.assertRaises(ValueError):worker.execute(self.request,self.root)
    def test_actual_contact_outside_witness_holds_with_zero_shadows_without_clipping_objects(self):
        import room_furnishing_support_worker as worker
        self.restrict([300,1200,1750,1650]);report=worker.execute(self.request,self.root)
        self.assertEqual('needs_review',report['status']);self.assertTrue(report['reviewReasons'])
        from PIL import Image
        self.assertFalse(np.any(np.asarray(Image.open(self.root/'result/scalar-shadow.png'))))
        alpha=np.asarray(Image.open(self.root/'result/object-alpha.png'))
        self.assertTrue(np.any(alpha[:1200]));self.assertFalse(report['floorDomain']['objectAlphaRestrictedByWitness'])
    def test_worker_rejects_wrong_layout_and_native_binding_before_support(self):
        import room_furnishing_support_worker as worker
        self.restrict([300,1100,1750,1650]);a=json.loads(Path(self.request['assessment']['path']).read_text());a['sourceFloorWitness']['assessmentSha256']='0'*64
        self.request['assessment']=self.fixture.write(self.root,'assessment.json',a)
        with self.assertRaisesRegex(ValueError,'LAYOUT_CHANGED'):worker.execute(self.request,self.root)

if __name__=='__main__':unittest.main()
