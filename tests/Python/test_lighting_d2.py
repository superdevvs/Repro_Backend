import sys,unittest
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[2]/'resources/iguide-lab'))
import numpy as np
import pano_geometry as g
import lighting_transfer_d2 as d
import lighting_transfer_d2 as c
class VariantD2Tests(unittest.TestCase):
 def test_monotone_all_channels(self):
  for k in [-2.4,-1.,-.001,0.,1.,2.4]:
   for confidence in [0.,.5,1.]:
    for channel in range(3):
     x=np.full((1,10001,3),.8,np.float32);x[0,:,channel]=np.linspace(0,1,10001,dtype=np.float32);y=d.guarded_curve(x,np.full_like(x,k),np.full_like(x,confidence))
     self.assertTrue(np.all(np.diff(y[0,:,channel])>=-1e-6));self.assertGreaterEqual(y.min(),-1e-6);self.assertLessEqual(y.max(),1+1e-6)
 def test_blue_only_donor_is_not_luminaire(self):
  donor=np.empty((64,128,3),np.uint8);donor[:]=[60,100,255];confirm=d.confirmation(donor,g.to_linear);self.assertEqual(float(confirm.max()),0.)
  x=np.ones((64,128,3),np.float32);k=np.full_like(x,-1.);np.testing.assert_array_equal(d.guarded_curve(x,k,confirm),c.curve(x,k))
 def test_white_core_protection(self):
  donor=np.full((64,128,3),255,np.uint8);confirm=d.confirmation(donor,g.to_linear);x=np.ones_like(confirm);np.testing.assert_array_equal(d.guarded_curve(x,-x,confirm),x)
 def test_constant_source_pole_invariant(self):
  source=np.full((128,256,3),240,np.uint8);donor=np.full_like(source,255);donor[:8,128:]=80;field=np.full((128,256,3),-1,np.float32)
  confirm=d.confirmation(donor,g.to_linear);out,_=d.apply_field(source,field,confirm,g);self.assertEqual(int(np.ptp(out[0].astype(np.int16))),0)
 def test_identity_replay_nadir(self):
  source=np.random.default_rng(0).integers(0,256,(128,256,3),dtype=np.uint8);zero=np.zeros((64,128,3),np.float32);confirm=np.ones_like(zero)
  out,_=d.apply_field(source,zero,confirm,g);np.testing.assert_array_equal(out,source)
  field=np.full_like(zero,-2.4);a,qa=d.apply_field(source,field,confirm,g);b,qb=d.apply_field(source,field,confirm,g);np.testing.assert_array_equal(a,b);self.assertEqual(qa,qb);np.testing.assert_array_equal(a[-7:],source[-7:])
 def test_periodic_confirmation(self):
  donor=np.random.default_rng(1).integers(0,256,(64,128,3),dtype=np.uint8);a=d.confirmation(donor,g.to_linear);b=d.confirmation(np.roll(donor,17,1),g.to_linear);np.testing.assert_allclose(b,np.roll(a,17,1),atol=1e-6)


class WorkerTests(unittest.TestCase):
 def test_complete_binding_replay_and_confirmation_drift(self):
  import tempfile,json
  from PIL import Image
  import lighting_d2_worker as w
  with tempfile.TemporaryDirectory() as td:
   root=Path(td)
   for name,shape,value in [('source',(128,256,3),240),('guidance',(1024,2048,3),240),('candidate',(1024,2048,3),130)]:
    Image.fromarray(np.full(shape,value,np.uint8)).save(root/f'{name}.png')
   asset=lambda n:{'path':str(root/f'{n}.png'),'sha256':g.sha256(root/f'{n}.png')}
   req={'schemaVersion':1,'operation':'transfer-lighting-d2','privateRoot':td,'source':asset('source'),
     'guidanceSource':asset('guidance'),'candidate':asset('candidate'),'preset':'twilight','editScope':'entire_view',
     'recipe':d.RECIPE,'outputDirectory':str(root/'out'),
     'sourcePreparation':{'nativeSourceSha256':asset('source')['sha256'],'guidanceSourceSha256':asset('guidance')['sha256'],
       'preparerSha256':'0'*64,'method':'LabImages::prepared/GD','verifiedByCaller':True}}
   result=w.run(req);self.assertEqual(result,w.run(req));self.assertFalse(result['automaticAcceptance'])
   self.assertEqual(set(result['artifacts']),{'native','preview','lighting-field','highlight-confirmation'})
   self.assertEqual(result['telemetry']['policyProtectedNadirChangedPixels'],0)
   self.assertEqual(result['recipe'],d.RECIPE);self.assertEqual(result['runtime']['lightingD2CodeSha256'],g.sha256(d.__file__))
   bad={**req,'recipe':'source-detail-log-odds-lighting/1'}
   with self.assertRaisesRegex(w.qa.WorkerError,'LIGHTING_RECIPE_UNSUPPORTED'):w.run(bad)
   bad={**req,'sourcePreparation':{**req['sourcePreparation'],'nativeSourceSha256':'1'*64}}
   with self.assertRaisesRegex(w.qa.WorkerError,'SOURCE_PREPARATION_BINDING_INVALID'):w.run(bad)
   arr=Path(result['artifacts']['highlight-confirmation']['path']);arr.write_bytes(arr.read_bytes()+b'changed')
   with self.assertRaisesRegex(w.qa.WorkerError,'CACHE_ASSET_MISMATCH'):w.run(req)
 def test_white_can_dim_without_mutual_bright_donor_and_v1_is_unchanged(self):
  import lighting_transfer as old
  source=np.full((128,256,3),255,np.uint8);field=np.full((64,128,3),-1,np.float32);confirm=np.zeros_like(field)
  result,_=d.apply_field(source,field,confirm,g);legacy,_=old.apply_field(source,field,g)
  self.assertLess(result[50].max(),200);self.assertEqual(legacy[50].min(),255)
  np.testing.assert_array_equal(result[-7:],source[-7:])
if __name__=='__main__':unittest.main()
