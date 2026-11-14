"""Bounded native detail correctness; local tests patch pins only, not image or path checks."""
from pathlib import Path
import copy, importlib.util, json, sys, tempfile, unittest
from unittest.mock import patch
import numpy as np
from PIL import Image

MODULE=Path(__file__).resolve().parents[2]/'resources/iguide-lab'
sys.path.insert(0,str(MODULE))
import staging_layout_details as worker

class NativeDetails(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup);self.root=Path(self.tmp.name).resolve()
        y,x=np.mgrid[:1024,:2048];a=np.stack([x%256,y%256,(x+y)%256],axis=-1).astype(np.uint8)
        self.source=self.root/'source.png';Image.fromarray(a).save(self.source)
        self.request={'schemaVersion':1,'source':{'path':str(self.source),'sha256':worker.sha(self.source)},
            'requests':[{'id':'source_detail_1','pose':{'yaw':35,'pitch':-10,'hFov':65},'evidenceViewIds':['parent_right'],'reason':'Inspect original glazing and nearby source floor.'}],
            'outputDirectory':str(self.root/'details')}
        identity=worker.runtime();identity.update(python='3.12.3',numpy='2.5.4',pillow='13.0.0')
        self.runtime=patch.object(worker,'runtime',return_value=identity);self.runtime.start();self.addCleanup(self.runtime.stop)

    def test_exact_native_projection_replay_and_output_drift(self):
        report=worker.run(self.request,str(self.root));self.assertTrue(report['sameCaptureOnly']);self.assertFalse(report['editScopeExpanded'])
        self.assertFalse(report['independentCameraEvidence']);self.assertEqual(report['providerCalls'],0)
        path=self.root/'details/report.json';before=path.read_bytes();again=worker.run(self.request,str(self.root));self.assertEqual(report,again);self.assertEqual(before,path.read_bytes())
        image=Path(report['views'][0]['path']);Image.new('RGB',(1024,1024)).save(image)
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

if __name__=='__main__':unittest.main()
