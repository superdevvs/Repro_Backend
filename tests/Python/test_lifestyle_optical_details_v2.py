"""Bounded native detail correctness; local tests patch pins only, not image or path checks."""
from pathlib import Path
import copy, importlib.util, json, sys, tempfile, unittest
from unittest.mock import patch
import numpy as np
from PIL import Image

MODULE=Path(__file__).resolve().parents[2]/'resources/iguide-lab'
sys.path.insert(0,str(MODULE))
import lifestyle_optical_details_v2 as worker

class NativeDetailsV2(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup);self.root=Path(self.tmp.name).resolve()
        y,x=np.mgrid[:2048,:4096];a=np.stack([x%256,y%256,(x+y)%256],axis=-1).astype(np.uint8)
        self.source=self.root/'source.png';Image.fromarray(a).save(self.source)
        self.request={'schemaVersion':1,'source':{'path':str(self.source),'sha256':worker.sha(self.source),'width':4096,'height':2048},
            'authority':{'parentFramingId':'11111111-1111-4111-8111-111111111111','requestId':'22222222-2222-4222-8222-222222222222',
                'resultSha256':'a'*64,'responseSha256':'b'*64,'policySha256':'c'*64},
            'requests':[{'id':'source_detail_1','pose':{'yaw':35,'pitch':-10,'hFov':65},'evidenceViewIds':['right'],'reason':'Inspect original glazing and nearby source floor.'}],
            'outputDirectory':str(self.root/'details')}
        identity=worker.runtime();identity.update(python='3.12.3',numpy='2.5.4',pillow='13.0.0')
        self.runtime=patch.object(worker,'runtime',return_value=identity);self.runtime.start();self.addCleanup(self.runtime.stop)

    def test_sampling_reports_interpolation_not_optical_resolution_and_wrap_is_invariant(self):
        a=worker.sampling_metadata(11008,5504,{'yaw':0,'pitch':0,'hFov':30})
        b=worker.sampling_metadata(11008,5504,{'yaw':180,'pitch':0,'hFov':30})
        self.assertEqual(a,b);self.assertFalse(a['independentOpticalResolutionKnown']);self.assertEqual(1,a['upsampledGridFraction'])
        self.assertLess(a['maxAxisFootprint']['max'],1)
        pole=worker.sampling_metadata(11008,5504,{'yaw':180,'pitch':85,'hFov':70})
        self.assertTrue(np.isfinite(pole['maxAxisFootprint']['max']))

    def test_sampling_tamper_is_not_replay_even_with_rehashed_manifest(self):
        report=worker.run(self.request,str(self.root));path=self.root/'details/report.json'
        report['views'][0]['sourceSampling']['independentOpticalResolutionKnown']=True
        path.write_bytes(worker.canonical(report))
        with self.assertRaisesRegex(ValueError,'SAMPLING_CHANGED'):worker.run(self.request,str(self.root))

    def test_exact_native_projection_replay_and_output_drift(self):
        report=worker.run(self.request,str(self.root));self.assertTrue(report['sameCaptureOnly']);self.assertFalse(report['editScopeExpanded'])
        self.assertFalse(report['independentCameraEvidence']);self.assertEqual(report['providerCalls'],0)
        path=self.root/'details/report.json';before=path.read_bytes();again=worker.run(self.request,str(self.root));self.assertEqual(report,again);self.assertEqual(before,path.read_bytes())
        image=Path(report['views'][0]['path']);Image.new('RGB',(2048,2048)).save(image)
        with self.assertRaisesRegex(ValueError,'IMAGE_OR_POSE_CHANGED'):worker.run(self.request,str(self.root))

    def test_source_drift_and_path_escape_rejected_before_output(self):
        wrong=copy.deepcopy(self.request);wrong['source']['sha256']='0'*64
        with self.assertRaisesRegex(ValueError,'SOURCE_BYTES'):worker.run(wrong,str(self.root))
        escaped=copy.deepcopy(self.request);escaped['outputDirectory']=str(self.root.parent/'escaped-details')
        with self.assertRaises((ValueError,OSError)):worker.run(escaped,str(self.root))
        self.assertFalse((self.root/'details').exists())

    def test_detail_role_budget_pose_and_order_are_explicit(self):
        cases=[]
        bad=copy.deepcopy(self.request);bad['requests'][0]['evidenceViewIds']=['candidate'];cases.append(bad)
        bad=copy.deepcopy(self.request);bad['requests'][0]['pose']['hFov']=121;cases.append(bad)
        bad=copy.deepcopy(self.request);bad['requests'][0]['id']='source_detail_2';cases.append(bad)
        bad=copy.deepcopy(self.request);bad['requests']*=3;cases.append(bad)
        for request in cases:
            with self.assertRaises(ValueError):worker.run(request,str(self.root))
        self.assertFalse((self.root/'details').exists())

    def test_runtime_drift_rejects_cache_without_overwrite(self):
        worker.run(self.request,str(self.root));path=self.root/'details/report.json';before=path.read_bytes()
        identity=worker.runtime();identity['workerSha256']='0'*64
        with patch.object(worker,'runtime',return_value=identity),self.assertRaisesRegex(ValueError,'REPLAY_BINDING_CHANGED'):worker.run(self.request,str(self.root))
        self.assertEqual(before,path.read_bytes())

    def test_strict_json_rejects_duplicate_keys_and_nonfinite_values(self):
        for raw in [b'{"source":1,"source":2}',b'{"value":NaN}',b'{"value":Infinity}',b'\xff']:
            with self.assertRaises(ValueError):worker.decode_json(raw)
        self.assertNotIn('pathHelperSha256',worker.runtime())

    def test_prepared_source_and_wrong_native_dimensions_are_rejected(self):
        small=self.root/'prepared.png';Image.open(self.source).resize((2048,1024)).save(small)
        r=copy.deepcopy(self.request);r['source']={'path':str(small),'sha256':worker.sha(small),'width':2048,'height':1024}
        with self.assertRaisesRegex(ValueError,'SOURCE_FORMAT'):worker.run(r,str(self.root))
        r=copy.deepcopy(self.request);r['source']['width']=2048
        with self.assertRaisesRegex(ValueError,'DIMENSIONS_CHANGED'):worker.run(r,str(self.root))

    def test_parent_receipt_policy_and_request_binding_reject_replay(self):
        worker.run(self.request,str(self.root));before=(self.root/'details/report.json').read_bytes()
        for key in ['resultSha256','responseSha256','policySha256','parentFramingId','requestId']:
            r=copy.deepcopy(self.request)
            r['authority'][key]='d'*64 if key.endswith('Sha256') else '33333333-3333-4333-8333-333333333333'
            with self.assertRaisesRegex(ValueError,'REPLAY_BINDING'):worker.run(r,str(self.root))
        self.assertEqual(before,(self.root/'details/report.json').read_bytes())

    def test_actual_native_detail_has_finer_source_information_than_prepared_projection(self):
        report=worker.run(self.request,str(self.root));native=np.asarray(Image.open(report['views'][0]['path']))
        self.assertEqual(native.shape,(2048,2048,3))
        # A high-frequency native fixture must not be equivalent to recutting the 2K preparation.
        prepared=self.root/'prepared.png';Image.open(self.source).resize((2048,1024),Image.Resampling.LANCZOS).save(prepared)
        spec=[{'id':'control','yaw_degrees':35,'pitch_degrees':-10,'hfov_degrees':65,'width':2048,'height':2048}]
        result=worker.geometry.extract(prepared,self.root/'control',view_specs=spec)
        low=np.asarray(Image.open(result['views'][0]['path']))
        self.assertGreater(np.count_nonzero(native!=low),100000)

    def test_changed_report_authority_flags_are_rejected(self):
        worker.run(self.request,str(self.root));path=self.root/'details/report.json';before=path.read_bytes()
        for key,value in [('status','accepted'),('independentCameraEvidence',True),('schemaVersion',2)]:
            changed=json.loads(before);changed[key]=value;path.write_text(json.dumps(changed))
            with self.assertRaises(ValueError):worker.run(self.request,str(self.root))
            path.write_bytes(before)
        self.assertEqual(worker.run(self.request,str(self.root))['status'],'completed')

if __name__=='__main__':unittest.main()
