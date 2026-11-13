import unittest
import sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'resources' / 'iguide-lab'))
import numpy as np
from composite_grass import CompositeError,compose_pixels,feather_support

class GeometryAndPreservationTests(unittest.TestCase):
    def test_periodic_filter_is_roll_equivariant(self):
        m=np.zeros((64,128),dtype='uint8'); m[15:48,:12]=255; m[15:48,-14:]=255
        a=feather_support(m,2,2)
        b=np.roll(feather_support(np.roll(m,64,axis=1),2,2),-64,axis=1)
        np.testing.assert_array_equal(a,b)
        self.assertGreater(a[30,0],250)
        self.assertGreater(a[30,-1],250)

    def test_fully_edited_pole_does_not_gain_a_false_border(self):
        m=np.ones((64,128),dtype='uint8')*255
        np.testing.assert_array_equal(feather_support(m,2,2),m)

    def test_mask_never_leaks_to_protected_pixels(self):
        rng=np.random.default_rng(42)
        src=rng.integers(0,256,(64,128,3),dtype='uint8')
        dst=np.full_like(src,255)
        mask=np.zeros((64,128),dtype='uint8');mask[20:40,40:70]=255
        alpha=feather_support(mask,2,2)
        result=compose_pixels(src,dst,alpha)
        np.testing.assert_array_equal(result[mask==0],src[mask==0])
        self.assertTrue(np.any(result[alpha>0]!=src[alpha>0]))

    def test_noop_does_not_soften_original(self):
        src=np.random.default_rng(8).integers(0,256,(64,128,3),dtype='uint8')
        alpha=np.random.default_rng(7).integers(0,256,(64,128),dtype='uint8')
        np.testing.assert_array_equal(compose_pixels(src,src,alpha),src)

    def test_unsafe_filter_and_mismatched_shapes_rejected(self):
        with self.assertRaises(CompositeError): feather_support(np.zeros((64,128),dtype='uint8'),20,2)
        with self.assertRaises(CompositeError): compose_pixels(np.zeros((3,4,3)),np.zeros((4,3,3)),np.zeros((3,4)))

if __name__=='__main__':unittest.main()
