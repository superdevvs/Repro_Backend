import copy,hashlib,json
from pathlib import Path
import sys,unittest
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'resources/iguide-lab'))
import grass_source_admission_protocol as p

def fixture():
    source=json.loads((ROOT/'tests/Fixtures/iguide-lab/grass-integration/context.json').read_text())
    binding={k:source['binding'][k] for k in ['workflowItemId','captureId','nativeSourceSha256','sourcePreparationSha256']}
    binding.update(sourceSha256=source['binding']['guidanceSourceSha256'],sourceAuthoritySha256='a'*64)
    context={'schemaVersion':p.VERSION,'binding':binding,'policy':p.POLICY,'round':0,'prior':None,
        'evidence':[x for x in source['evidence'] if x['id']!='candidate_panorama']}
    finding=lambda status:{'status':status,'evidenceIds':list(p.SOURCE_IDS),'notes':'Synthetic source evidence only.'}
    proposal={'schemaVersion':p.VERSION,'contextSha256':p.digest(context),'binding':binding,'summary':'Synthetic material screening.',
        'material':finding('established_turf'),'scope':finding('all_visible_established_turf'),'lawnBoundary':finding('complete_visible_boundary'),
        'exclusions':{role:finding('none_observed') for role in p.ROLES},'reflections':finding('no_visible_turf_reflection_observed'),
        'contextCoverage':{**finding('sufficient_same_capture_views'),'detailRequests':[]},'uncertainties':[]}
    return context,proposal

def parse(context,proposal):
    raw=json.dumps({'id':'resp-fixture','model':p.MODEL,'status':'completed','output':[{'type':'message','role':'assistant','status':'completed',
        'content':[{'type':'output_text','text':json.dumps(proposal)}]}]})
    execution={'state':'completed','requestId':context['binding']['workflowItemId'],'providerResponseId':'resp-fixture','model':p.MODEL,
        'contextSha256':p.digest(context),'requestPayloadSha256':'b'*64,'responseSha256':hashlib.sha256(raw.encode()).hexdigest()}
    return p.parse_response(raw,context,execution)

def request_detail(proposal):
    proposal['material']['status']='uncertain'
    proposal['contextCoverage']['status']='details_may_resolve'
    proposal['contextCoverage']['detailRequests']=[{'pose':{'yaw':20,'pitch':-40,'hFov':45},'purpose':'Resolve original turf boundary.','evidenceIds':['source_panorama']}]
    proposal['uncertainties']=['Original turf boundary is unclear.']

def next_context(context,proposal):
    value=copy.deepcopy(context);value['round']=1
    value['prior']={'assessmentId':context['binding']['workflowItemId'],'resultSha256':'f'*64,'proposal':copy.deepcopy(proposal)}
    for i,request in enumerate(proposal['contextCoverage']['detailRequests']):
        value['evidence'].append({**value['evidence'][1],'id':f'source_detail_{i+1}','pose':{**request['pose'],'width':1024,'height':1024}})
    return value

class AdmissionTests(unittest.TestCase):
    def test_established_and_dormant_turf_are_screened_without_candidate_or_sam_authority(self):
        for status in ['established_turf','established_dormant_turf']:
            context,proposal=fixture();proposal['material']['status']=status
            report=parse(context,proposal)
            self.assertEqual(report['status'],'eligible_for_image')
            self.assertFalse(report['automaticAcceptance']);self.assertFalse(report['segmentationAuthority'])
            self.assertEqual(report['detailPlan'],[])
            self.assertNotIn('candidateSha256',report['binding'])

    def test_sparse_turf_and_bare_soil_hold_even_when_details_requested(self):
        for status in ['sparse_or_missing_turf','bare_soil']:
            context,proposal=fixture();request_detail(proposal);proposal['material']['status']=status
            report=parse(context,proposal);self.assertEqual(report['status'],'needs_review');self.assertEqual(report['detailPlan'],[])

    def test_dense_fine_debris_and_reflections_are_not_cleared_by_detail_request(self):
        for kind in ['debris','reflection','subset']:
            context,proposal=fixture();request_detail(proposal)
            if kind=='debris':proposal['exclusions']['loose_debris']['status']='too_fine_or_dense'
            elif kind=='reflection':proposal['reflections']['status']='visible_or_possible_turf_reflection'
            else:proposal['scope']['status']='parcel_subset_requested'
            report=parse(context,proposal);self.assertEqual(report['status'],'needs_review');self.assertEqual(report['detailPlan'],[])

    def test_uncertain_original_boundary_can_request_one_bounded_followup(self):
        context,proposal=fixture();request_detail(proposal);before=copy.deepcopy(proposal)
        report=parse(context,proposal);self.assertEqual(report['status'],'needs_detail');self.assertEqual(report['proposal'],before)
        self.assertEqual(report['detailPlan'],proposal['contextCoverage']['detailRequests'])
        follow=next_context(context,proposal);p.validate_context(follow)
        _,resolved=fixture();resolved['contextSha256']=p.digest(follow)
        resolved['material']['evidenceIds']+=['source_detail_1']
        self.assertEqual(parse(follow,resolved)['status'],'eligible_for_image')

    def test_unresolved_followup_holds_and_never_requests_a_third_analysis(self):
        context,proposal=fixture();request_detail(proposal);follow=next_context(context,proposal)
        proposal['contextSha256']=p.digest(follow)
        report=parse(follow,proposal)
        self.assertEqual(report['status'],'needs_review');self.assertEqual(report['detailPlan'],[])
        self.assertIn('SOURCE_DETAIL_BUDGET_EXHAUSTED',report['reviewReasons'])

    def test_context_rejects_candidate_extra_images_pose_drift_and_foreign_native(self):
        for change in ['candidate','pose','native','prior']:
            context,proposal=fixture();request_detail(proposal);follow=next_context(context,proposal)
            if change=='candidate':follow['evidence'].append({**follow['evidence'][0],'id':'candidate_panorama'})
            elif change=='pose':follow['evidence'][-1]['pose']['yaw']+=1
            elif change=='native':follow['evidence'][-1]['nativeSourceSha256']='e'*64
            else:follow['prior']['proposal']['binding']={**follow['binding'],'sourceSha256':'e'*64}
            with self.subTest(change=change),self.assertRaises(ValueError):p.validate_context(follow)

    def test_view_request_limits_are_explicit(self):
        for field,value in [('hFov',71),('hFov',29),('pitch',86),('yaw',361),('yaw',float('nan'))]:
            context,proposal=fixture();request_detail(proposal);proposal['contextCoverage']['detailRequests'][0]['pose'][field]=value
            with self.subTest(field=field,value=value),self.assertRaises(ValueError):parse(context,proposal)
        context,proposal=fixture();request_detail(proposal)
        proposal['contextCoverage']['detailRequests']*=2
        with self.assertRaisesRegex(ValueError,'DUPLICATE_DETAIL'):parse(context,proposal)

    def test_global_absence_and_reflection_require_all_original_views(self):
        context,proposal=fixture();proposal['exclusions']['plants']['evidenceIds']=['source_panorama']
        with self.assertRaisesRegex(ValueError,'EVIDENCE'):parse(context,proposal)
        context,proposal=fixture();proposal['material']['evidenceIds']=['candidate_panorama']
        with self.assertRaisesRegex(ValueError,'EVIDENCE'):parse(context,proposal)

    def test_compile_is_source_only_and_hash_bound(self):
        context,proposal=fixture();folder=ROOT/'tests/Fixtures/iguide-lab/grass-integration/images'
        images=[(folder/('source.png' if x['id']=='source_panorama' else x['id']+'.png')).read_bytes() for x in context['evidence']]
        payload=p.compile_request(context,images)
        self.assertEqual(payload,p.compile_request(context,images));self.assertEqual(len(payload['input'][0]['content']),15)
        self.assertNotIn('candidate_panorama',json.dumps(payload))
        images[0]+=b'changed'
        with self.assertRaisesRegex(ValueError,'HASH'):p.compile_request(context,images)

if __name__=='__main__':unittest.main()
