import copy,json,sys,tempfile,unittest,uuid
from pathlib import Path
from unittest.mock import patch
import numpy as np
from PIL import Image
sys.path.insert(0,str(Path(__file__).resolve().parents[2]/'resources/iguide-lab'))
import declutter_support as s
import declutter_removal_protocol as p
from test_declutter_removal_protocol import fixture,parsed

class SupportTests(unittest.TestCase):
    def setUp(self):
        self.mask=np.zeros((2048,2048),bool);self.mask[750:950,650:850]=True
        self.proposal={'protectedFeatures':[]}
    def test_target_fully_opaque_and_feather_only_inside_bounded_expansion(self):
        a,reasons,stats=s.derive({'target_object':self.mask},self.proposal)
        self.assertEqual(reasons,[]);self.assertTrue(np.all(a[self.mask]==255))
        self.assertEqual(a[740,650],0);self.assertTrue(np.any((a>0)&(a<255)))
        self.assertLess(stats['positiveSupportPixels'],131072)
    def test_empty_disconnected_and_hollow_masks_hold(self):
        empty=np.zeros_like(self.mask);split=self.mask.copy();split[1200:1210,1200:1210]=True
        hole=self.mask.copy();hole[800:810,700:710]=False
        for m,reason in [(empty,'OBJECT_MASK_EMPTY_OR_TOO_SMALL'),(split,'OBJECT_MASK_DISCONNECTED'),(hole,'OBJECT_MASK_HAS_UNRESOLVED_HOLES')]:
            self.assertIn(reason,s.derive({'target_object':m},self.proposal)[1])
    def test_large_guard_and_protected_feature_hold(self):
        large=self.mask.copy();large[600:1100,600:1100]=True
        guard=np.zeros_like(self.mask);guard[204:300,600:700]=True
        self.assertIn('EDIT_DOMAIN_TOO_LARGE',s.derive({'target_object':large},self.proposal)[1])
        self.assertIn('SUPPORT_TOUCHES_GUARD',s.derive({'target_object':guard},self.proposal)[1])
        self.proposal['protectedFeatures']=[{'box':{'xMin':.3,'xMax':.4,'yMin':.3,'yMax':.5}}]
        self.assertIn('PROTECTED_FEATURE_OVERLAP',s.derive({'target_object':self.mask},self.proposal)[1])
    def test_unlocated_protection_and_detached_shadow_hold(self):
        self.proposal['protectedFeatures']=[{'box':None}]
        self.assertIn('PROTECTED_FEATURE_UNLOCATED',s.derive({'target_object':self.mask},self.proposal)[1])
        shadow=np.zeros_like(self.mask);shadow[1300:1320,1200:1220]=True
        self.assertIn('SHADOW_NOT_LOCAL_TO_OBJECT',s.derive({'target_object':self.mask,'target_local_shadow':shadow},{'protectedFeatures':[]})[1])
    def test_local_shadow_unioned_without_changing_object_coverage(self):
        shadow=np.zeros_like(self.mask);shadow[920:970,820:900]=True
        a,reasons,stats=s.derive({'target_object':self.mask,'target_local_shadow':shadow},self.proposal)
        self.assertEqual(reasons,[]);self.assertTrue(np.all(a[self.mask|shadow]==255));self.assertGreater(stats['shadowPixels'],0)
    def test_exact_opaque_mask_formats_and_fractional_alpha_rejection(self):
        with tempfile.TemporaryDirectory() as d:
            path=Path(d)/'mask.png';base=Image.fromarray(self.mask.astype('uint8')*255)
            for mode in ['L','LA','RGB','RGBA']:
                base.convert(mode).save(path);self.assertTrue(np.array_equal(s.image(path,True),self.mask))
            a=np.asarray(base.convert('RGBA')).copy();a[0,0,3]=254;Image.fromarray(a).save(path)
            with self.assertRaisesRegex(ValueError,'TRANSPARENCY'):s.image(path,True)
            a=np.zeros((2048,2048),np.uint8);a[0,0]=128;Image.fromarray(a).save(path)
            with self.assertRaisesRegex(ValueError,'SOFT_PROVIDER'):s.image(path,True)

class AdapterTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.root=Path(self.temp.name)
        context,images,proposal=fixture();proposal['target']['box']={'xMin':.31,'yMin':.36,'xMax':.42,'yMax':.47};self.assessment=parsed(context,proposal)
        self.plan=p.bind_reserved_plans(self.assessment,{'target_object':str(uuid.UUID(int=20))})[0]
        source=self.root/'source.png';source.write_bytes(images[0])
        mask=np.zeros((2048,2048),np.uint8);mask[750:950,650:850]=255;Image.fromarray(mask).save(self.root/'mask.png')
        self.receipt={k:self.plan[k] for k in ['requestId','model','inputFingerprint','inputSha256']}
        self.receipt.update(providerRequestId='synthetic_sam',rawResponseSha256='c'*64,status='completed',masks=[{
            'sha256':s.sha(self.root/'mask.png'),'width':2048,'height':2048,'bounds':[650,750,850,950],'positivePixels':40000}])
        def put(name,value):
            path=self.root/name;path.write_bytes(s.canonical(value));return {'path':str(path),'sha256':s.sha(path)}
        self.request={'schemaVersion':1,'privateRoot':str(self.root),'source':{'path':str(source),'sha256':s.sha(source)},
            'assessment':put('assessment.json',self.assessment),'plans':put('plans.json',[self.plan]),'receipts':[put('receipt.json',self.receipt)],
            'masks':[{'role':'target_object','image':{'path':str(self.root/'mask.png'),'sha256':s.sha(self.root/'mask.png')}}],
            'outputDirectory':str(self.root/'output')}
        # Local tests exercise mechanics. The separate Linux CLI proof uses real pinned runtime identity.
        identity=s.runtime();identity.update(python='3.12.3',pillow='13.0.0')
        self.mock=patch.object(s,'runtime',return_value=identity);self.mock.start()
    def tearDown(self):self.mock.stop();self.temp.cleanup()
    def test_immutable_exact_replay_and_support_tamper(self):
        first=s.run(self.request);path=self.root/'output/report.json';raw=path.read_bytes()
        self.assertEqual(first['status'],'ready_for_generation');self.assertEqual(s.run(copy.deepcopy(self.request)),first);self.assertEqual(path.read_bytes(),raw)
        with (self.root/'output/support.png').open('ab') as f:f.write(b'tampered')
        with self.assertRaisesRegex(ValueError,'CACHED_SUPPORT'):s.run(self.request)
    def test_source_or_mask_drift_rejected_before_replay(self):
        s.run(self.request)
        with (self.root/'source.png').open('ab') as f:f.write(b'drift')
        with self.assertRaisesRegex(ValueError,'INPUT_CHANGED'):s.run(self.request)
    def test_decoded_descriptor_and_role_cannot_be_invented(self):
        self.receipt['masks'][0]['positivePixels']=1000
        path=self.root/'receipt.json';path.write_bytes(s.canonical(self.receipt));self.request['receipts'][0]['sha256']=s.sha(path)
        with self.assertRaisesRegex(ValueError,'DECODED_MASK_CHANGED'):s.run(self.request)
    def test_unknown_candidate_input_rejected(self):
        self.request['candidate']=self.request['source']
        with self.assertRaisesRegex(ValueError,'REQUEST_FIELDS'):s.run(self.request)

if __name__=='__main__':unittest.main()
