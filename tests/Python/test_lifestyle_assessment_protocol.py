import copy
import hashlib
import io
import json
from pathlib import Path
import sys
import unittest
from PIL import Image
sys.path.insert(0,str(Path(__file__).resolve().parents[2]/'resources'/'iguide-lab'))
import lifestyle_assessment_protocol as p

def fixture():
    binding={k:hashlib.sha256(k.encode()).hexdigest() for k in p.BINDINGS}
    binding.update(workflowItemId='2209f4f0-0b7d-4e0a-85db-e14f09236655',captureId='8f585286-158e-4a8b-97ef-e77034a32fa2')
    pose={'yaw':0,'pitch':-15,'hFov':90,'width':2048,'height':2048};views=[];images=[]
    for i,name in enumerate(p.IDS):
        size=(2048,2048) if i<2 else (2048,1024) if i==2 else (1024,1024)
        buffer=io.BytesIO();Image.new('RGB',size,(80+i*10,100,130)).save(buffer,format='PNG');raw=buffer.getvalue();images.append(raw)
        views.append({'id':name,'sha256':hashlib.sha256(raw).hexdigest(),'captureId':binding['captureId'],'nativeSourceSha256':binding['nativeSourceSha256'],
            'derivationSha256':binding['sourceGeometrySha256'] if i==0 else hashlib.sha256(name.encode()).hexdigest(),'width':size[0],'height':size[1],
            'projection':'equirectangular' if i==2 else 'rectilinear','pose':pose.copy() if i<2 else None if i==2 else {'yaw':[0,90,180,270][i-3],'pitch':0,'hFov':100,'width':1024,'height':1024}})
    for i,k in [(0,'sourceCutSha256'),(1,'candidateCutSha256'),(2,'parentContextSha256')]:binding[k]=views[i]['sha256']
    context={'schemaVersion':p.VERSION,'binding':binding,'pose':pose,'evidence':views}
    both=p.IDS[:2];parents=p.IDS[2:]
    finding=lambda value,ids:{'status':value,'evidenceIds':ids,'notes':'Synthetic diagnostic; no real semantic claim.'}
    people=[]
    for name,x in zip(p.PEOPLE,[.2,.58]):
        shoes=[]
        for side,offset in [('left',.025),('right',.11)]:
            shoes.append({'side':side,'status':'visible_contact','soleSegment':[{'x':x+offset,'y':.8},{'x':x+offset+.045,'y':.8}],
                'sourceFloorRelation':'visible_level_floor','evidenceIds':both,'notes':'Explicit synthetic shoe segment.'})
        people.append({'id':name,'box':{'xMin':x,'yMin':.2,'xMax':x+.2,'yMax':.82},'status':'complete_standing_adult','anatomy':'plausible_visible_limbs',
            'scale':'plausible_source_relative','topology':'clear_visible_boundaries_and_holes','shoes':shoes,'evidenceIds':both,'notes':'Synthetic person.'})
    proposal={'schemaVersion':p.VERSION,'contextSha256':p.digest(context),'binding':copy.deepcopy(binding),'summary':'Synthetic pair only.','people':people,
        'sourceRegion':finding('visible_level_floor_no_existing_people',['source_cut','parent_overview']),
        'group':finding('two_separate_standing_adults_no_props',both),'foreground':finding('no_foreground_overlap',both),
        'protectedFeatures':finding('no_conflict_observed',both),'optics':finding('no_relevant_dependency_observed',p.IDS),
        'lighting':finding('diffuse_tiny_contact_only',p.IDS),'contextCoverage':finding('sufficient_same_capture_views',parents),
        'floorSelection':{'status':'visible_level_floor','box':{'xMin':.1,'yMin':.6,'xMax':.9,'yMax':.95},'material':'wood_laminate','evidenceIds':['source_cut'],'notes':'Source floor.'},'uncertainties':[]}
    return context,images,proposal

class ProtocolTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):cls.context,cls.images,cls.proposal=fixture()
    def parse(self,proposal=None,mutate_execution=None):
        raw=json.dumps({'id':'resp_people','model':'gpt-6-astra','status':'completed','output':[{'type':'message','role':'assistant','status':'completed','content':[{'type':'output_text','text':json.dumps(proposal or self.proposal)}]}]})
        execution={'state':'completed','requestId':'07c734c6-d827-414d-92de-329d661eb656','providerResponseId':'resp_people','model':'gpt-6-astra',
            'contextSha256':p.digest(self.context),'requestPayloadSha256':'f'*64,'responseSha256':hashlib.sha256(raw.encode()).hexdigest()}
        if mutate_execution:mutate_execution(execution)
        return p.parse_response(raw,self.context,execution,'gpt-6-astra')
    def test_ready_has_two_explicit_people_plans_no_furniture(self):
        result=self.parse();self.assertEqual(result['status'],'ready_for_segmentation');plans=result['segmentationPlan']
        self.assertEqual([x['stepKey'] for x in plans],['source-floor','candidate-people'])
        self.assertEqual(plans[1]['expectedIdentities'],p.PEOPLE);self.assertEqual(plans[1]['payload']['max_masks'],2)
        self.assertEqual(plans[1]['payload']['prompt'],'person');self.assertFalse(result['automaticAcceptance'])
    def test_compile_exact_bound_images(self):
        payload=p.compile_request(self.context,self.images,'gpt-6-astra');self.assertEqual(payload['text']['format']['name'],'iguide_lifestyle_assessment')
        self.assertIn('BOTH shoes',payload['instructions']);self.assertEqual(payload['model'],'gpt-6-astra')
    def test_compile_uses_medium_effort_with_room_for_an_answer(self):
        payload=p.compile_request(self.context,self.images,'gpt-6-astra')
        self.assertEqual(payload['reasoning'],{'effort':'medium'});self.assertGreaterEqual(payload['max_output_tokens'],16000)
        self.assertNotIn('Uncertainty always stops before SAM',payload['instructions']);self.assertIn('caveat',payload['instructions'])
    def test_changed_actual_image_rejected(self):
        bad=self.images.copy();bad[1]=bad[0]
        with self.assertRaisesRegex(ValueError,'IMAGE_HASH'):p.compile_request(self.context,bad,'gpt-6-astra')
    def test_wrong_native_parent_rejected(self):
        c=copy.deepcopy(self.context);c['evidence'][3]['nativeSourceSha256']='0'*64
        with self.assertRaisesRegex(ValueError,'SAME_CAPTURE'):p.validate_context(c)
    def test_geometry_pose_drift_rejected(self):
        c=copy.deepcopy(self.context);c['evidence'][1]['pose']['pitch']=-14
        with self.assertRaisesRegex(ValueError,'EVIDENCE_POSE'):p.validate_context(c)
    def test_receipt_hash_and_execution_identity_rejected(self):
        for key,value in [('responseSha256','0'*64),('providerResponseId','resp_other'),('contextSha256','0'*64),('model','wrong')]:
            with self.subTest(key=key),self.assertRaises(ValueError):self.parse(mutate_execution=lambda x:x.update({key:value}))
    def assertCaveat(self,result,code):
        # A paid image is always segmented and composited; a doubt is a recorded caveat, never a stop.
        self.assertEqual(result['status'],'ready_for_segmentation');self.assertEqual(result['reviewReasons'],[])
        self.assertIn(code,result['caveats']);self.assertEqual(len(result['segmentationPlan']),2)
    def test_ready_result_records_no_caveats(self):
        self.assertEqual(self.parse()['caveats'],[])
    def test_semantic_doubts_become_caveats_and_still_emit_sam(self):
        values={'sourceRegion':'occupied_or_complex','group':'count_overlap_or_props','foreground':'overlap','protectedFeatures':'conflict','optics':'visible_or_possible_dependency','lighting':'broad_or_directional_shadow_required','contextCoverage':'insufficient_context'}
        for key,status in values.items():
            q=copy.deepcopy(self.proposal);q[key]['status']=status
            with self.subTest(key=key):self.assertCaveat(self.parse(q),key.upper()+'_REQUIRES_REVIEW')
    def test_seated_anatomy_scale_and_unclear_hair_are_caveats(self):
        for key,status,code in [('status','seated','PERSON_INCOMPLETE_OR_NOT_STANDING'),('anatomy','malformed','ANATOMY_OR_SCALE_UNRESOLVED'),
                ('scale','uncertain','ANATOMY_OR_SCALE_UNRESOLVED'),('topology','ambiguous_hair_hands_or_holes','PERSON_TOPOLOGY_UNRESOLVED')]:
            q=copy.deepcopy(self.proposal);q['people'][0][key]=status
            with self.subTest(key=key):self.assertCaveat(self.parse(q),code)
    def test_hidden_or_outside_shoe_are_caveats(self):
        q=copy.deepcopy(self.proposal);q['people'][0]['shoes'][0]['status']='occluded';q['people'][0]['shoes'][0]['soleSegment']=None
        self.assertCaveat(self.parse(q),'SHOE_CONTACT_UNRESOLVED')
        q=copy.deepcopy(self.proposal);q['people'][0]['shoes'][0]['soleSegment'][0]['x']=.9
        self.assertCaveat(self.parse(q),'SHOE_OUTSIDE_PERSON_SELECTION')
        q=copy.deepcopy(self.proposal);q['uncertainties']=['A faint reflection may be expected in the patio door.']
        self.assertCaveat(self.parse(q),'ASSESSMENT_HAS_UNRESOLVED_UNCERTAINTY')
    def test_missing_person_or_floor_box_is_a_hard_stop_before_sam(self):
        for change,code in [(lambda q:q['people'][1].update(box=None),'PERSON_BOX_MISSING'),(lambda q:q['people'][0].update(status='missing'),'PERSON_BOX_MISSING'),
                (lambda q:q['floorSelection'].update(box=None),'FLOOR_BOX_MISSING')]:
            q=copy.deepcopy(self.proposal);change(q)
            with self.subTest(code=code):
                result=self.parse(q);self.assertEqual(result['status'],'needs_review');self.assertIn(code,result['reviewReasons']);self.assertEqual(result['segmentationPlan'],[])
    def test_missing_parent_optics_evidence_rejected(self):
        q=copy.deepcopy(self.proposal);q['optics']['evidenceIds']=['parent_front']
        with self.assertRaisesRegex(ValueError,'FINDING_EVIDENCE'):self.parse(q)
    def test_actual_pose_must_be_cited_in_optics_and_lighting(self):
        for key in ['optics','lighting']:
            q=copy.deepcopy(self.proposal);q[key]['evidenceIds'].remove('candidate_cut')
            with self.subTest(key=key),self.assertRaisesRegex(ValueError,'FINDING_EVIDENCE'):self.parse(q)
    def test_source_only_floor_cannot_use_candidate(self):
        q=copy.deepcopy(self.proposal);q['floorSelection']['evidenceIds'].append('candidate_cut')
        with self.assertRaisesRegex(ValueError,'FINDING_EVIDENCE'):self.parse(q)
    def test_guard_and_person_overlap_are_caveats(self):
        q=copy.deepcopy(self.proposal);q['people'][0]['box']['xMin']=.09
        self.assertCaveat(self.parse(q),'PERSON_TOUCHES_FRAME_GUARD')
        q=copy.deepcopy(self.proposal);q['people'][0]['box']['xMax']=.7
        self.assertCaveat(self.parse(q),'PERSON_SELECTION_OVERLAP')
    def test_uncertain_floor_with_a_box_is_a_caveat(self):
        q=copy.deepcopy(self.proposal);q['floorSelection']['status']='uncertain'
        result=self.parse(q);self.assertCaveat(result,'FLOOR_SELECTION_UNRESOLVED');self.assertIsNotNone(result['proposal']['floorSelection']['box'])
    def test_furniture_identity_not_accepted(self):
        q=copy.deepcopy(self.proposal);q['people'][0]['id']='sofa'
        with self.assertRaisesRegex(ValueError,'ENUM_INVALID'):self.parse(q)
    def test_reordered_masks_associated_geometrically(self):
        result=self.parse();plans=p.bind_reserved_plans(result,{'source-floor':'84fe129a-6cff-40da-bb09-c850359f40d8','candidate-people':'a0175343-3846-4e1e-af89-4350d321a68c'});plan=plans[1]
        masks=[]
        for i,b in enumerate(plan['payload']['box_prompts']):
            masks.append({'sha256':str(i+1)*64,'width':2048,'height':2048,'bounds':[b['x_min'],b['y_min'],b['x_max'],b['y_max']],'positivePixels':10000})
        receipt={k:plan[k] for k in ['requestId','model','inputFingerprint','inputSha256']}
        receipt.update(providerRequestId='sam_people',rawResponseSha256='e'*64,status='completed',masks=list(reversed(masks)))
        bound=p.bind_segmentation_receipt(plan,receipt);self.assertEqual(bound['status'],'ready_for_support_validation')
        self.assertEqual([x['maskIndex'] for x in bound['associations']],[1,0])
        receipt['masks']=[];self.assertEqual(p.bind_segmentation_receipt(plan,receipt)['status'],'needs_review')
    def test_no_caller_generated_secondary_uuid(self):
        result=self.parse();self.assertTrue(all(x['requestId'] is None for x in result['segmentationPlan']))
        with self.assertRaisesRegex(ValueError,'DUPLICATE_RESERVATION'):p.bind_reserved_plans(result,dict.fromkeys(['source-floor','candidate-people'],'84fe129a-6cff-40da-bb09-c850359f40d8'))

if __name__=='__main__':unittest.main()
