"""Synthetic transport/mechanics evidence, never a room or quality acceptance."""
import copy
import hashlib
import json
from pathlib import Path
import sys
import tempfile
import unittest
sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'resources' / 'iguide-lab'))
import numpy as np
from PIL import Image
import room_furnishing_support_worker as worker


class RoomWorkerTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(); self.root = Path(self.temp.name).resolve()
        self.request = self.fixture(self.root)

    def tearDown(self): self.temp.cleanup()

    @staticmethod
    def write(root, name, value):
        path = root / name
        if isinstance(value, np.ndarray): Image.fromarray(value).save(path)
        else: path.write_text(json.dumps(value, sort_keys=True), encoding='utf8')
        return {'path': str(path), 'sha256': hashlib.sha256(path.read_bytes()).hexdigest()}

    @classmethod
    def fixture(cls, root):
        n=2048; source=np.full((n,n,3),150,np.uint8); candidate=source.copy()
        boxes={'bed':(720,840,1324,1564),'nightstand_left':(360,880,644,1124),'nightstand_right':(1400,880,1684,1124)}
        masks={}; contacts={}
        for role,(x0,y0,x1,y1) in boxes.items():
            m=np.zeros((n,n),np.uint8);m[y0:y1,x0:x1]=255;masks[role]=m;candidate[m>0]=[80,95,110]
            contacts[role]=[[[x0+16,y1-1],[min(x1-17,x0+560),y1-1]]]
        source_d=cls.write(root,'source.png',source); candidate_d=cls.write(root,'candidate.png',candidate)
        context=cls.write(root,'context.png',np.full((1024,2048,3),150,np.uint8)); pose={'yaw':0.,'pitch':-20.,'hFov':100.}
        geometry=cls.write(root,'geometry.json',{'version':'interior-furnishing-source-geometry/1','nativeSourceSha256':'a'*64,
            'sourceCutSha256':source_d['sha256'],'preparedSourceSha256':source_d['sha256'],'width':2048,'height':2048,
            'projection':'rectilinear','view':pose,'convention':'raw-yaw-zero-panorama-centre-positive-right-pitch-up'})
        authority={'version':'interior-room-support-authority/1','profileId':'empty-bedroom-platform-three-piece/1',
            'nativeSourceSha256':'a'*64,'sourceCutSha256':source_d['sha256'],'preparedSourceSha256':source_d['sha256'],
            'candidateSha256':candidate_d['sha256'],'sourceContextSha256':context['sha256'],'geometrySha256':geometry['sha256'],
            'routeSha256':'b'*64,'layoutResultSha256':'c'*64,'candidateResultSha256':'d'*64,'sourceEmptyRoom':True,
            'foregroundStatus':'none_observed','opticalStatus':'no_required_changes','contactShadowStatus':'tiny_local_only',
            'selectionOrigin':'machine','contacts':contacts}
        def record(role,kind,m,input_sha):
            image=cls.write(root,role+'.png',m)
            evidence=cls.write(root,role+'-evidence.json',{'generationOrigin':'model_segmentation','selectionOrigin':'machine',
                'inputSha256':input_sha,'maskSha256':image['sha256'],'requestId':'synthetic-no-provider',
                'providerRequestId':'synthetic-no-provider','rawResponseSha256':'e'*64})
            return {'id':role,'role':kind,'image':image,'evidence':evidence}
        floor=np.zeros((n,n),np.uint8);floor[600:]=255
        return {'schemaVersion':1,'operation':'derive-room-furnishing-support','profileId':authority['profileId'],'sourceCut':source_d,
            'candidateCut':candidate_d,'sourceContext':context,'sourceGeometry':geometry,'assessment':cls.write(root,'assessment.json',authority),
            'sourceFloor':record('floor','source_floor',floor,source_d['sha256']),
            'candidateObjects':[record(role,'candidate_object',m,candidate_d['sha256']) for role,m in masks.items()],
            'pose':pose,'outputDirectory':str(root/'result')}

    def run_worker(self): return worker.execute(self.request,self.root)
    def change_authority(self,key,value):
        path=Path(self.request['assessment']['path']);a=json.loads(path.read_text());a[key]=value
        self.request['assessment']=self.write(self.root,path.name,a)

    def test_actual_2048_refinement_and_hash_bound_replay_keep_source_unchanged(self):
        original=Path(self.request['sourceCut']['path']).read_bytes();report=self.run_worker()
        self.assertEqual('ready_for_unreviewed_composition',report['status'],report['reviewReasons'])
        self.assertFalse(report['automaticAcceptance']);self.assertFalse(report['nativeCompositionPerformed'])
        self.assertEqual(report,self.run_worker());self.assertEqual(original,Path(self.request['sourceCut']['path']).read_bytes())
        self.assertEqual(0,report['shadowOutsideSafeFloorPixels']);self.assertEqual(0,report['providerCalls'])

    def test_changed_pose_or_hash_reject_before_support(self):
        self.request['pose']['pitch']=-25
        with self.assertRaisesRegex(ValueError,'POSE_CHANGED'):self.run_worker()
        self.request['pose']['pitch']=-20;Path(self.request['candidateCut']['path']).write_bytes(b'changed')
        with self.assertRaisesRegex(ValueError,'HASH_MISMATCH'):self.run_worker()

    def test_actual_optical_uncertainty_preserves_hold_and_zero_shadow(self):
        self.change_authority('opticalStatus','unresolved');report=self.run_worker()
        self.assertEqual('needs_review',report['status']);shadow=np.asarray(Image.open(self.root/'result/scalar-shadow.png'))
        self.assertFalse(np.any(shadow));self.assertEqual(report,self.run_worker())

    def test_boolean_authority_role_switch_and_wrong_floor_input_are_rejected(self):
        self.change_authority('sourceEmptyRoom',1)
        with self.assertRaisesRegex(ValueError,'SCOPE_INVALID'):self.run_worker()
        self.change_authority('sourceEmptyRoom',True);self.request['candidateObjects'][0]['id']='sofa'
        with self.assertRaisesRegex(ValueError,'IDENTITIES_CHANGED'):self.run_worker()
        self.request['candidateObjects'][0]['id']='bed';e=self.request['sourceFloor']['evidence'];a=json.loads(Path(e['path']).read_text());a['inputSha256']=self.request['candidateCut']['sha256']
        self.request['sourceFloor']['evidence']=self.write(self.root,Path(e['path']).name,a)
        with self.assertRaisesRegex(ValueError,'SOURCE_ROLE_MISMATCH'):self.run_worker()

    def test_cached_asset_tamper_cannot_replay(self):
        self.run_worker();path=self.root/'result/object-alpha.png';path.write_bytes(path.read_bytes()+b'changed')
        with self.assertRaisesRegex(ValueError,'HASH_MISMATCH'):self.run_worker()


if __name__=='__main__':unittest.main()
