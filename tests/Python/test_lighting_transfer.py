import unittest
from pathlib import Path
import tempfile
from unittest.mock import patch
import numpy as np
from PIL import Image
import sys
ROOT = Path(__file__).resolve().parents[2] / 'resources' / 'iguide-lab'
sys.path.insert(0, str(ROOT))
import workflow_qa as w
import lighting_transfer as l


class LightingTests(unittest.TestCase):
    def test_file_contract_preparation_binding_cache_and_runtime(self):
        with tempfile.TemporaryDirectory() as td:
            root=Path(td)
            for name,shape,value in [('source',(128,256,3),100),('guidance',(1024,2048,3),100),('candidate',(1024,2048,3),125)]:
                Image.fromarray(np.full(shape,value,np.uint8)).save(root/f'{name}.png')
            asset=lambda name:{'path':str(root/f'{name}.png'),'sha256':w.g.sha256(root/f'{name}.png')}
            req={'schemaVersion':1,'operation':'transfer-lighting','privateRoot':td,'source':asset('source'),
                'guidanceSource':asset('guidance'),'candidate':asset('candidate'),'preset':'twilight','editScope':'entire_view',
                'recipe':l.RECIPE,'outputDirectory':str(root/'out'),
                'sourcePreparation':{'nativeSourceSha256':asset('source')['sha256'],'guidanceSourceSha256':asset('guidance')['sha256'],
                    'preparerSha256':'0'*64,'method':'LabImages::prepared/GD','verifiedByCaller':True}}
            # This is a test-only trusted-caller assertion, not evidence of a real GD preparation.
            report=w.run(req)
            self.assertEqual(report,w.run(req));self.assertEqual(report['qa']['editScope'],'entire_view')
            self.assertFalse(report['automaticAcceptance'])
            req['sourcePreparation']['nativeSourceSha256']='1'*64
            with self.assertRaisesRegex(w.WorkerError,'SOURCE_PREPARATION_BINDING_INVALID'):w.run(req)
            with patch.object(w,'PILLOW_VERSION','99.0.0'):
                with self.assertRaisesRegex(w.WorkerError,'UNVALIDATED_RUNTIME_VERSION'):w.run(req)

    def test_spherical_filter_constant_and_longitude_equivariance(self):
        a=np.full((64,128),.7,np.float32)
        np.testing.assert_allclose(l.sphere_blur(a,2),a,atol=1e-6)
        a[:]=0;a[:,0]=1
        b=l.sphere_blur(a,2)
        self.assertGreater(float(b[:, -1].mean()),.05)
        np.testing.assert_allclose(np.roll(b,17,axis=1),l.sphere_blur(np.roll(a,17,axis=1),2),atol=1e-6)

    def test_identity_field_preserves_native_byte_exact(self):
        rng=np.random.default_rng(1)
        source=rng.integers(0,256,(128,256,3),dtype=np.uint8)
        guide=source[::2,::2]
        field=l.derive_field(guide,guide,w.g.to_linear)
        self.assertEqual(float(np.abs(field).max()),0)
        result,stats=l.apply_field(source,field,w.g)
        np.testing.assert_array_equal(result,source)
        self.assertEqual(stats['nonfiniteChannels'],0)

    def test_source_nadir_policy_and_monotone_endpoints(self):
        source=np.full((128,256,3),100,np.uint8)
        source[30,40]=0;source[30,41]=255
        field=np.full((64,128,3),1,np.float32)
        result,stats=l.apply_field(source,field,w.g)
        elevation=90-(np.arange(128)+.5)*180/128
        np.testing.assert_array_equal(result[elevation<=-80],source[elevation<=-80])
        np.testing.assert_array_equal(result[30,40],source[30,40])
        np.testing.assert_array_equal(result[30,41],source[30,41])
        self.assertGreater(int(result[30,50,0]),100)
        self.assertEqual(stats['policyProtectedNadirChangedPixels'],0)

    def test_generated_high_frequency_texture_is_not_transplanted(self):
        guide=np.full((64,128,3),100,np.uint8)
        yy,xx=np.mgrid[:64,:128]
        donor=np.repeat(np.where((xx+yy)%2,180,70)[...,None],3,axis=2).astype(np.uint8)
        field=l.derive_field(guide,donor,w.g.to_linear)
        interior=field[10:-10]
        self.assertLess(float(np.std(interior)),.025)
        native=np.full((128,256,3),100,np.uint8)
        native[40:70,80:100]=80
        result,_=l.apply_field(native,field,w.g)
        # Source rectangle remains darker; no checkerboard copied into the original.
        self.assertLess(float(result[45:65,85:95].mean()),float(result[45:65,105:115].mean()))

    def test_poles_collapsed_and_nonfinite_field_rejected(self):
        rng=np.random.default_rng(3)
        guide=np.full((64,128,3),100,np.uint8)
        donor=rng.integers(40,220,guide.shape,dtype=np.uint8)
        field=l.derive_field(guide,donor,w.g.to_linear)
        self.assertLess(float(np.ptp(field[0],axis=0).max()),1e-6)
        self.assertLess(float(np.ptp(field[-1],axis=0).max()),1e-6)
        field[0,0,0]=np.nan
        with self.assertRaisesRegex(ValueError,'LIGHTING_FIELD_INVALID'):
            l.apply_field(guide,field,w.g)


if __name__=='__main__':unittest.main()
