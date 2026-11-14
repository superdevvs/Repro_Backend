import copy,hashlib,io,json,sys,unittest,uuid
from pathlib import Path
from PIL import Image
sys.path.insert(0,str(Path(__file__).resolve().parents[2]/'resources/iguide-lab'))
import declutter_removal_protocol as p


def fixture(shadow=False):
    sha='a'*64;capture=str(uuid.UUID(int=1))
    b={key:sha for key in p.BINDINGS};b.update(workflowItemId=str(uuid.UUID(int=2)),captureId=capture)
    pose={'yaw':0,'pitch':-30,'hFov':65,'width':2048,'height':2048};views=[];images=[]
    for i,name in enumerate(p.IDS):
        size=(2048,2048) if i==0 else (2048,1024) if i==1 else (1024,1024)
        buf=io.BytesIO();Image.new('RGB',size,(100+i,120,130)).save(buf,'PNG');raw=buf.getvalue();images.append(raw)
        views.append({'id':name,'sha256':hashlib.sha256(raw).hexdigest(),'captureId':capture,'nativeSourceSha256':sha,'derivationSha256':sha,
            'width':size[0],'height':size[1],'projection':'equirectangular' if i==1 else 'rectilinear',
            'pose':pose if i==0 else None if i==1 else {'yaw':(i-2)*90,'pitch':0,'hFov':100,'width':1024,'height':1024}})
    b.update(sourceCutSha256=views[0]['sha256'],parentContextSha256=views[1]['sha256'])
    context={'schemaVersion':p.VERSION,'binding':b,'pose':pose,'evidence':views,
        'sourcePlan':{'summary':'Synthetic narrow one-object removal route.','targets':[],'protectedFeatures':[],'sourceArtifacts':[],'uncertainties':[]}}
    def finding(value,parents=False):return {**value,'evidenceIds':p.IDS[1:] if parents else ['source_cut'],'notes':'Synthetic mechanics only; no real scene claim.'}
    box={'xMin':.3,'yMin':.3,'xMax':.45,'yMax':.55}
    proposal={'contextSha256':p.digest(context),'binding':b,
        'target':finding({'id':'target_object','description':'solid decorative stone','count':'exactly_one','purpose':'decorative_only','opacity':'opaque','silhouette':'complete_compact_solid','box':box}),
        'receiver':finding({'material':'matte_simple_continuous','continuity':'visible_surrounding_support','hiddenSurface':'unobserved_generated_estimate'}),
        'shadow':finding({'status':'single_local_attributable' if shadow else 'none_observed','box':{'xMin':.4,'yMin':.5,'xMax':.6,'yMax':.6} if shadow else None}),
        'optics':finding({'status':'no_relevant_dependency_observed'},True),'foreground':finding({'status':'no_overlap_observed'}),
        'contextCoverage':finding({'status':'sufficient_same_capture_views'},True),'protectedFeatures':[],
        'generationPrompt':'Remove only the compact decorative stone and its attributable local shadow. Continue the matte tabletop naturally, preserving all other objects, original lighting, geometry, perspective and image boundaries.','uncertainties':[]}
    return context,images,proposal


def parsed(context,proposal):
    body={'id':'resp_synthetic','model':p.MODEL,'status':'completed','output':[{'type':'message','role':'assistant','status':'completed','content':[{'type':'output_text','text':json.dumps(proposal)}]}]}
    raw=json.dumps(body)
    execution={'state':'completed','requestId':str(uuid.UUID(int=3)),'providerResponseId':body['id'],'model':p.MODEL,
        'contextSha256':p.digest(context),'requestPayloadSha256':'b'*64,'responseSha256':hashlib.sha256(raw.encode()).hexdigest()}
    return p.parse_response(raw,context,execution)


class ProtocolTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):cls.base=fixture()
    def setUp(self):self.context,self.images,self.proposal=copy.deepcopy(self.base)
    def test_compile_exact_source_only_inputs(self):
        body=p.compile_request(self.context,self.images)
        self.assertEqual(body['model'],'gpt-6-astra');self.assertFalse(body['store'])
        self.assertEqual(len(body['input'][0]['content']),13);self.assertNotIn('candidate_cut',json.dumps(self.context))
    def test_source_hash_drift_rejects(self):
        self.images[0]+=b'changed'
        with self.assertRaisesRegex(ValueError,'IMAGE_HASH'):p.compile_request(self.context,self.images)
    def test_native_and_pose_bindings_reject_drift(self):
        for field,value in [('nativeSourceSha256','b'*64),('derivationSha256','b'*64)]:
            c=copy.deepcopy(self.context);c['evidence'][0][field]=value
            with self.assertRaises(ValueError):p.validate_context(c)
        self.context['evidence'][0]['pose']=dict(self.context['pose'],yaw=1)
        with self.assertRaises(ValueError):p.validate_context(self.context)
    def test_one_source_only_role_without_shadow(self):
        a=parsed(self.context,self.proposal)
        self.assertEqual(a['status'],'ready_for_segmentation');self.assertEqual([x['stepKey'] for x in a['segmentationPlan']],['target_object'])
        self.assertFalse(a['automaticAcceptance']);self.assertIsNone(a['segmentationPlan'][0]['requestId'])
    def test_shadow_declares_second_source_only_role(self):
        c,_,v=fixture(True);a=parsed(c,v)
        self.assertEqual([x['stepKey'] for x in a['segmentationPlan']],p.ROLES)
        self.assertEqual({x['inputSha256'] for x in a['segmentationPlan']},{c['binding']['sourceCutSha256']})
    def test_closed_scope_holds(self):
        cases=[('target','count','multiple_or_uncertain'),('target','purpose','functional_or_uncertain'),
            ('target','opacity','transparent_or_uncertain'),('target','silhouette','clipped_thin_holes_or_uncertain'),
            ('receiver','material','reflective_patterned_or_uncertain'),('receiver','continuity','unresolved'),
            ('optics','status','possible_dependency_or_insufficient_context'),('foreground','status','overlap_or_uncertain'),
            ('contextCoverage','status','needs_more_context'),('shadow','status','needs_review')]
        for group,key,value in cases:
            with self.subTest(group=group,key=key):
                proposal=copy.deepcopy(self.proposal);proposal[group][key]=value;a=parsed(self.context,proposal)
                self.assertEqual(a['status'],'needs_review');self.assertEqual(a['segmentationPlan'],[])
    def test_margin_and_uncertainty_hold(self):
        self.proposal['target']['box']['xMin']=.09
        self.assertIn('SUPPORT_SELECTION_TOUCHES_GUARD',parsed(self.context,self.proposal)['reviewReasons'])
        self.proposal=copy.deepcopy(self.base[2]);self.proposal['uncertainties']=['Cannot distinguish adjacent edge.']
        self.assertEqual(parsed(self.context,self.proposal)['status'],'needs_review')
    def test_parent_uncertainty_cannot_be_silently_discarded(self):
        self.context['sourcePlan']['uncertainties']=['Possible off-cut reflection.']
        self.proposal['contextSha256']=p.digest(self.context)
        a=parsed(self.context,self.proposal)
        self.assertIn('SOURCE_PLAN_UNRESOLVED_UNCERTAINTY',a['reviewReasons'])
        self.assertEqual(a['segmentationPlan'],[])
    def test_no_shadow_requires_consistent_source_evidence(self):
        self.proposal['shadow']['box']=self.proposal['target']['box']
        self.assertIn('SHADOW_SELECTION_CONTRADICTS_FINDING',parsed(self.context,self.proposal)['reviewReasons'])
        self.proposal['shadow']['evidenceIds']=['parent_front']
        with self.assertRaisesRegex(ValueError,'FINDING_EVIDENCE'):parsed(self.context,self.proposal)
    def test_protected_overlap_holds(self):
        self.proposal['protectedFeatures']=[{'id':'nearby object','box':self.proposal['target']['box'],'evidenceIds':['source_cut'],'notes':'Keep exact.'}]
        self.assertIn('PROTECTED_FEATURE_SELECTION_OVERLAP',parsed(self.context,self.proposal)['reviewReasons'])
    def test_parent_evidence_cannot_be_skipped(self):
        self.proposal['optics']['evidenceIds']=['parent_overview']
        with self.assertRaisesRegex(ValueError,'FINDING_EVIDENCE'):parsed(self.context,self.proposal)
    def test_no_extra_client_or_candidate_fields(self):
        self.proposal['humanReviewed']=True
        with self.assertRaises(ValueError):parsed(self.context,self.proposal)
        self.context['candidateSha256']='b'*64
        with self.assertRaises(ValueError):p.validate_context(self.context)
    def test_reservation_ids_and_key_order_are_stable(self):
        c,_,v=fixture(True);a=parsed(c,v);ids={key:str(uuid.UUID(int=i+10)) for i,key in enumerate(p.ROLES)}
        plans=p.bind_reserved_plans(a,ids)
        self.assertEqual(plans,p.bind_reserved_plans(json.loads(json.dumps(a,sort_keys=True)),dict(reversed(list(ids.items())))))
        with self.assertRaises(ValueError):p.bind_reserved_plans(a,{key:ids[p.ROLES[0]] for key in p.ROLES})
    def test_empty_mask_is_hold_not_shadow_absence(self):
        a=parsed(self.context,self.proposal);plan=p.bind_reserved_plans(a,{'target_object':str(uuid.UUID(int=10))})[0]
        receipt={key:plan[key] for key in ['requestId','model','inputFingerprint','inputSha256']}
        receipt.update(providerRequestId='sam_synthetic',rawResponseSha256='c'*64,status='completed',masks=[])
        self.assertEqual(p.bind_segmentation_receipt(plan,receipt)['status'],'needs_review')
    def test_exact_source_receipt_binding(self):
        a=parsed(self.context,self.proposal);plan=p.bind_reserved_plans(a,{'target_object':str(uuid.UUID(int=10))})[0]
        box=plan['payload']['box_prompts'][0]
        receipt={key:plan[key] for key in ['requestId','model','inputFingerprint','inputSha256']}
        receipt.update(providerRequestId='sam_synthetic',rawResponseSha256='c'*64,status='completed',masks=[{'sha256':'d'*64,'width':2048,'height':2048,'bounds':[box[k] for k in ['x_min','y_min','x_max','y_max']],'positivePixels':10000}])
        self.assertEqual(p.bind_segmentation_receipt(plan,receipt)['status'],'ready_for_support_validation')
        receipt['inputSha256']='e'*64
        with self.assertRaises(ValueError):p.bind_segmentation_receipt(plan,receipt)


if __name__=='__main__':unittest.main()
