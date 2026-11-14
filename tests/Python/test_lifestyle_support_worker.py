import copy
import hashlib
import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch
import numpy as np
from PIL import Image

sys.path.insert(0,str(Path(__file__).resolve().parents[2]/'resources'/'iguide-lab'))
import lifestyle_support_worker as w
import lifestyle_assessment_protocol as protocol
from test_lifestyle_support import fixture as core_fixture


class AdapterTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.root=Path(self.temp.name).resolve()
        source,candidate,masks,floor,pose,typed=core_fixture()
        def upscale(a):return np.repeat(np.repeat(a,4,axis=0),4,axis=1)
        self.source=self.save_image('source.png',upscale(source))
        self.candidate=self.save_image('candidate.png',upscale(candidate))
        self.context=self.save_image('context.png',np.full((1024,2048,3),100,np.uint8))
        geometry=self.save_json('geometry.json',{'sourceCutSha256':self.source['sha256'],'nativeSourceSha256':'a'*64,
            'projection':'rectilinear','width':2048,'height':2048,'pose':pose})
        # Nearest-neighbour scaling retains exact synthetic lower pixels. Locate
        # the new bottom of the visible sole, not an inferred body extremum.
        for person in typed['proposal']['people']:
            for shoe in person['shoes']:
                for point in shoe['soleSegment']:point['y']+=3/2048
        typed.update(proposalSha256=protocol.digest(typed['proposal']),contextSha256='c'*64,
            binding={'nativeSourceSha256':'a'*64,'sourceCutSha256':self.source['sha256'],
                'candidateCutSha256':self.candidate['sha256'],'sourceGeometrySha256':geometry['sha256']})
        self.assessment={'sourceCutSha256':self.source['sha256'],'candidateCutSha256':self.candidate['sha256'],
            'sourceContextSha256':self.context['sha256'],'nativeSourceSha256':'a'*64,'sourceGeometrySha256':geometry['sha256'],
            'authorOrigin':'machine','typedAssessment':typed,'assessmentProposalSha256':typed['proposalSha256'],'contextSha256':'c'*64}
        self.request={'schemaVersion':1,'operation':'derive-lifestyle-support','sourceCut':self.source,'candidateCut':self.candidate,
            'sourceContext':self.context,'sourceGeometry':geometry,'assessment':self.save_json('assessment.json',self.assessment),
            'sourceFloor':self.record('floor','source_floor',self.source['sha256'],upscale(floor)),
            'candidatePeople':[self.record('adult_'+str(i+1),'candidate_person',self.candidate['sha256'],upscale(m)) for i,m in enumerate(masks)],
            'pose':pose,'outputDirectory':str(self.root/'output')}
    def tearDown(self):self.temp.cleanup()
    def desc(self,p):return {'path':str(p),'sha256':w.sha(p)}
    def save_image(self,name,pixels):
        p=self.root/name;Image.fromarray(pixels).save(p);return self.desc(p)
    def save_json(self,name,value):
        p=self.root/name;p.write_text(json.dumps(value),encoding='utf-8');return self.desc(p)
    def record(self,name,role,input_sha,pixels):
        image=self.save_image(name+'.png',pixels)
        evidence=self.save_json(name+'.json',{'inputSha256':input_sha,'maskSha256':image['sha256'],
            'generationOrigin':'model_segmentation','selectionOrigin':'machine'})
        return {'id':name,'role':role,'image':image,'evidence':evidence}
    def test_real_2048_support_and_exact_replay_bind_all_code_and_assets(self):
        result=w.execute(self.request,self.root)
        self.assertEqual(result['status'],'ready_for_unreviewed_composition',result['reviewReasons'])
        self.assertEqual(result['alphaOutsidePersonMasksPixels'],0);self.assertEqual(result['shadowOutsideSafeFloorPixels'],0)
        self.assertFalse(result['automaticAcceptance']);self.assertFalse(result['nativeCompositionPerformed'])
        self.assertEqual(result['protocolSha256'],w.sha(protocol.__file__))
        with patch.object(w.core,'derive_support',side_effect=AssertionError('replay must not recompute')):
            self.assertEqual(result,w.execute(self.request,self.root))
        Path(self.request['outputDirectory'],'scalar-shadow.png').write_bytes(b'changed')
        with self.assertRaisesRegex(ValueError,'CACHE_OUTPUT_HASH_CHANGED'):w.execute(self.request,self.root)
    def test_source_and_native_pose_drift_prevent_processing(self):
        self.request['pose']=dict(self.request['pose'],pitch=-20)
        with self.assertRaisesRegex(ValueError,'SOURCE_GEOMETRY_BINDING'):w.execute(self.request,self.root)
        self.request['pose']['pitch']=0;self.assessment['nativeSourceSha256']='b'*64
        self.request['assessment']=self.save_json('assessment.json',self.assessment)
        with self.assertRaisesRegex(ValueError,'ASSESSMENT_NATIVE_SOURCE'):w.execute(self.request,self.root)
        Path(self.source['path']).write_bytes(b'changed')
        with self.assertRaisesRegex(ValueError,'INPUT_HASH'):w.execute(self.request,self.root)
    def test_generated_floor_is_never_source_floor_authority(self):
        record=self.request['sourceFloor'];data=json.loads(Path(record['evidence']['path']).read_text());data['inputSha256']=self.candidate['sha256']
        record['evidence']=self.save_json('floor.json',data)
        with self.assertRaisesRegex(ValueError,'MASK_SOURCE_ROLE_MISMATCH'):w.execute(self.request,self.root)
    def test_human_selection_and_changed_typed_proposal_are_rejected(self):
        record=self.request['candidatePeople'][0];data=json.loads(Path(record['evidence']['path']).read_text());data['selectionOrigin']='human'
        record['evidence']=self.save_json('adult_1.json',data)
        with self.assertRaisesRegex(ValueError,'MACHINE_ASSESSMENT_REQUIRED'):w.execute(self.request,self.root)
        data['selectionOrigin']='machine';record['evidence']=self.save_json('adult_1.json',data)
        self.assessment['typedAssessment']['proposal']['people'][0]['shoes'][0]['status']='occluded'
        self.request['assessment']=self.save_json('assessment.json',self.assessment)
        with self.assertRaisesRegex(ValueError,'PEOPLE_PROPOSAL_CHANGED'):w.execute(self.request,self.root)
    def test_no_hidden_shoe_shadow_is_created(self):
        typed=self.assessment['typedAssessment'];typed['proposal']['people'][0]['shoes'][0]['status']='occluded'
        typed['proposalSha256']=protocol.digest(typed['proposal']);self.assessment['assessmentProposalSha256']=typed['proposalSha256']
        self.request['assessment']=self.save_json('assessment.json',self.assessment)
        result=w.execute(self.request,self.root);self.assertEqual(result['status'],'needs_review')
        mask=np.asarray(Image.open(Path(self.request['outputDirectory'])/'scalar-shadow.png'));self.assertFalse(np.any(mask))
    def test_furniture_or_reordered_identity_is_not_people_authority(self):
        self.request['candidatePeople']=list(reversed(self.request['candidatePeople']))
        with self.assertRaisesRegex(ValueError,'EXPECTED_TWO_PERSON'):w.execute(self.request,self.root)
        self.request['candidatePeople'][0]['id']='sofa'
        with self.assertRaisesRegex(ValueError,'EXPECTED_TWO_PERSON'):w.execute(self.request,self.root)
    def test_fractional_alpha_or_colored_mask_cannot_be_silently_normalized(self):
        for mode,color,code in [('RGBA',(100,100,100,254),'TRANSPARENT_MASK'),('RGB',(100,101,100),'COLORED_MASK')]:
            p=self.root/(mode+'.png');Image.new(mode,(2048,2048),color).save(p)
            with self.assertRaisesRegex(ValueError,code):w.read_image(p,'L')
        p=self.root/'opaque.png';Image.new('RGBA',(2048,2048),(100,100,100,255)).save(p);before=w.sha(p)
        self.assertTrue(np.all(w.read_image(p,'L')==100));self.assertEqual(before,w.sha(p))
    def test_foreign_root_incomplete_cache_and_protocol_drift_fail_closed(self):
        self.request['outputDirectory']=str(self.root.parent/'escape')
        with self.assertRaisesRegex(ValueError,'PATH_OUTSIDE'):w.execute(self.request,self.root)
        self.request['outputDirectory']=str(self.root/'output');Path(self.request['outputDirectory']).mkdir()
        with self.assertRaisesRegex(ValueError,'INPUT_FILE_MISSING'):w.execute(self.request,self.root)
    def test_changed_input_during_processing_is_never_published(self):
        original=w.core.derive_support
        def mutate(*args):
            result=original(*args);Path(self.candidate['path']).write_bytes(b'changed');return result
        with patch.object(w.core,'derive_support',side_effect=mutate):
            with self.assertRaisesRegex(ValueError,'INPUT_CHANGED_DURING_PROCESSING'):w.execute(self.request,self.root)
        self.assertFalse(Path(self.request['outputDirectory']).exists())

if __name__=='__main__':unittest.main()
