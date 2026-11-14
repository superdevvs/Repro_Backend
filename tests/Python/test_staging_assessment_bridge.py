import base64
import copy
import hashlib
import io
import json
import os
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch
from PIL import Image

RESOURCE=Path(__file__).resolve().parents[2]/'resources'/'iguide-lab'
sys.path.insert(0,str(RESOURCE))
import staging_assessment_bridge as b
p=b.protocol


def fixture():
    binding={k:hashlib.sha256(k.encode()).hexdigest() for k in p.BINDINGS}
    binding.update(workflowItemId='2209f4f0-0b7d-4e0a-85db-e14f09236655',captureId='8f585286-158e-4a8b-97ef-e77034a32fa2')
    pose={'yaw':0,'pitch':-25,'hFov':110,'width':2048,'height':2048};evidence=[];images=[]
    for i,name in enumerate(p.IDS):
        dims=(2048,2048) if i<2 else (2048,1024) if i==2 else (1024,1024)
        buffer=io.BytesIO();Image.new('RGB',dims,(70+i*10,100,130)).save(buffer,format='PNG');raw=buffer.getvalue();images.append(raw)
        evidence.append({'id':name,'sha256':hashlib.sha256(raw).hexdigest(),'captureId':binding['captureId'],
            'nativeSourceSha256':binding['nativeSourceSha256'],'derivationSha256':binding['sourceGeometrySha256'] if i==0 else hashlib.sha256(name.encode()).hexdigest(),
            'width':dims[0],'height':dims[1],'projection':'equirectangular' if i==2 else 'rectilinear',
            'pose':pose.copy() if i<2 else None if i==2 else {'yaw':[0,90,180,270][i-3],'pitch':0,'hFov':100,'width':1024,'height':1024}})
    for i,key in [(0,'sourceCutSha256'),(1,'candidateCutSha256'),(2,'parentContextSha256')]:binding[key]=evidence[i]['sha256']
    context={'schemaVersion':p.VERSION,'binding':binding,'pose':pose,'evidence':evidence}
    both=['source_cut','candidate_cut'];parents=p.IDS[2:];objects=[]
    for name,coords in zip(p.OBJECTS,[(.51,.38,.84,.68),(.15,.45,.35,.80),(.40,.62,.63,.85)]):
        objects.append({'id':name,'status':'visible_complete','box':dict(zip(['xMin','yMin','xMax','yMax'],coords)),
            'contactVisibility':'separated_lower_tips_visible','sourceFloorRelation':'visible_source_floor_at_exposed_contacts',
            'frameMargin':'clear_guard','evidenceIds':both,'notes':'Synthetic separated contact tips; no real image claim.'})
    proposal={'schemaVersion':p.VERSION,'contextSha256':p.digest(context),'binding':copy.deepcopy(binding),'summary':'Synthetic structured response.',
        'room':{'classification':'empty_living_supported','floor':'continuous_level_visible','evidenceIds':['source_cut','parent_overview'],'notes':'Synthetic floor.'},
        'objects':objects,'additionalObjects':{'status':'none_observed','evidenceIds':both,'notes':'Three items.'},
        'protectedFeatureConflict':{'status':'none_observed','evidenceIds':both,'notes':'No conflicts observed.'},
        'foreground':{'ordering':'no_foreground_occlusion','evidenceIds':both,'notes':'None observed.'},
        'optics':{'classification':'no_relevant_reflection_observed','evidenceIds':parents,'surfaces':[],'notes':'No physical certification.'},
        'contextCoverage':{'status':'sufficient_same_capture_views','requestedDetails':[],'evidenceIds':parents,'notes':'Required directions.'},
        'floorSelection':{'status':'visible_bare_floor','box':{'xMin':.05,'yMin':.35,'xMax':.95,'yMax':.95},'material':'wood_laminate','evidenceIds':['source_cut'],'notes':'Source floor.'},
        'uncertainties':[]}
    return context,images,proposal


class BridgeTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):cls.context,cls.images,cls.proposal=fixture()
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.root=Path(self.temp.name).resolve()
        self.runtime_patch=patch.object(b,'validate_runtime',return_value=None);self.runtime_patch.start()
        self.context_asset=self.write('context.json',self.context)
        self.image_assets=[]
        for view,raw in zip(self.context['evidence'],self.images):
            self.image_assets.append({'id':view['id'],**self.write(view['id']+'.png',raw)})
    def tearDown(self):self.runtime_patch.stop();self.temp.cleanup()
    def write(self,name,value):
        raw=value if isinstance(value,bytes) else b.canonical(value)
        path=self.root/name;path.write_bytes(raw)
        return {'path':str(path),'sha256':hashlib.sha256(raw).hexdigest()}
    def request(self,operation,**fields):
        return {'schemaVersion':1,'operation':operation,'outputDirectory':str(self.root/(operation+'-out')),**fields}
    def run_compile(self):
        req=self.request('compile',context=self.context_asset,images=self.image_assets)
        return req,b.run(req,self.root)
    def parse_inputs(self,proposal=None):
        _,compiled=self.run_compile();payload=compiled['outputs']['payload'];payload_asset={k:payload[k] for k in ['path','sha256']}
        response={'id':'resp_synthetic','model':p.MODEL,'status':'completed','output':[{'type':'message','role':'assistant','status':'completed',
            'content':[{'type':'output_text','text':json.dumps(proposal or self.proposal)}]}]}
        response_asset=self.write('response.json',response)
        execution={'state':'completed','requestId':'07c734c6-d827-414d-92de-329d661eb656','providerResponseId':'resp_synthetic','model':p.MODEL,
            'contextSha256':p.digest(self.context),'requestPayloadSha256':payload_asset['sha256'],'responseSha256':response_asset['sha256']}
        return self.request('parse',context=self.context_asset,response=response_asset,execution=self.write('execution.json',execution),compiledPayload=payload_asset)
    def ready_assessment(self):
        report=b.run(self.parse_inputs(),self.root);desc=report['outputs']['assessment']
        return {k:desc[k] for k in ['path','sha256']}
    def plans(self):
        assessment=self.ready_assessment();reservations={'source-floor':'783b490d-41f2-42a5-b001-f455b5575692','candidate-furniture':'ed06c2b5-2cba-4fd4-8835-6ad6fd6c15c1'}
        report=b.run(self.request('bind-plans',assessment=assessment,reservations=self.write('reservations.json',reservations)),self.root)
        desc=report['outputs']['plans'];asset={k:desc[k] for k in ['path','sha256']}
        return assessment,asset,json.loads(Path(asset['path']).read_text())
    def receipt(self,step,empty=False):
        masks=[]
        for i,box in enumerate(step['payload']['box_prompts']):
            bounds=[box['x_min']+3,box['y_min']+3,box['x_max']-3,box['y_max']-3]
            masks.append({'sha256':hashlib.sha256((step['stepKey']+str(i)).encode()).hexdigest(),'width':2048,'height':2048,
                'bounds':bounds,'positivePixels':int((bounds[2]-bounds[0])*(bounds[3]-bounds[1])*.6)})
        return {'requestId':step['requestId'],'providerRequestId':'synthetic_sam_'+step['stepKey'],'model':step['model'],
            'inputFingerprint':step['inputFingerprint'],'inputSha256':step['inputSha256'],'rawResponseSha256':'d'*64,'status':'completed','masks':[] if empty else masks}
    def test_compile_exact_replay_and_private_output(self):
        req,report=self.run_compile();self.assertFalse(report['automaticAcceptance']);self.assertEqual(report['providerCalls'],0)
        with patch.object(p,'compile_request',side_effect=AssertionError('must not recompile')):self.assertEqual(report,b.run(req,self.root))
        if os.name=='posix':
            self.assertEqual(Path(report['outputs']['payload']['path']).stat().st_mode&0o777,0o660)
            self.assertEqual(Path(req['outputDirectory']).stat().st_mode&0o7777,0o2770)
    def test_compile_wrong_order_capture_and_hash_rejected(self):
        images=copy.deepcopy(self.image_assets);images[0],images[1]=images[1],images[0]
        with self.assertRaisesRegex(ValueError,'EVIDENCE_ORDER'):b.run(self.request('compile',context=self.context_asset,images=images),self.root)
        context=copy.deepcopy(self.context);context['evidence'][3]['captureId']='1'*36
        with self.assertRaisesRegex(ValueError,'SAME_CAPTURE'):b.run(self.request('compile',context=self.write('bad-context.json',context),images=self.image_assets),self.root)
        images=copy.deepcopy(self.image_assets);Path(images[0]['path']).write_bytes(b'changed')
        with self.assertRaisesRegex(ValueError,'INPUT_HASH_MISMATCH'):b.run(self.request('compile',context=self.context_asset,images=images),self.root)
    def test_parse_binds_actual_saved_payload_and_response(self):
        req=self.parse_inputs();report=b.run(req,self.root)
        self.assertEqual(report['resultStatus'],'ready_for_segmentation')
        assessment=json.loads(Path(report['outputs']['assessment']['path']).read_text())
        self.assertFalse(assessment['physicalGeometryVerified']);self.assertEqual(len(assessment['segmentationPlan']),2)
        execution=json.loads(Path(req['execution']['path']).read_text());execution['requestPayloadSha256']='a'*64
        req['execution']=self.write('wrong-execution.json',execution);req['outputDirectory']=str(self.root/'wrong-parse')
        with self.assertRaisesRegex(ValueError,'EXECUTION_REQUEST_PAYLOAD_HASH'):b.run(req,self.root)
    def test_changed_payload_rejected_even_with_updated_receipt_hash(self):
        req=self.parse_inputs();payload=json.loads(Path(req['compiledPayload']['path']).read_text());payload['instructions']='Different policy'
        req['compiledPayload']=self.write('changed-payload.json',payload)
        execution=json.loads(Path(req['execution']['path']).read_text());execution['requestPayloadSha256']=req['compiledPayload']['sha256']
        req['execution']=self.write('changed-execution.json',execution)
        with self.assertRaisesRegex(ValueError,'COMPILED_PAYLOAD_CONTEXT_OR_POLICY_CHANGED'):b.run(req,self.root)
    def test_optical_hold_never_produces_segmentation_plans(self):
        proposal=copy.deepcopy(self.proposal);proposal['optics']['classification']='visible_or_possible_dependency'
        req=self.parse_inputs(proposal);report=b.run(req,self.root)
        self.assertEqual(report['resultStatus'],'needs_review')
        assessment=json.loads(Path(report['outputs']['assessment']['path']).read_text());self.assertEqual(assessment['segmentationPlan'],[])
    def test_single_source_floor_association_precedes_furniture(self):
        _,_,plans=self.plans();step=plans[0]
        report=b.run(self.request('associate',step=self.write('step.json',step),receipt=self.write('floor-receipt.json',self.receipt(step))),self.root)
        self.assertEqual(report['resultStatus'],'ready_for_support_validation')
        self.assertNotIn('support-assessment',report['outputs'])
    def test_empty_floor_holds_without_furniture_receipt(self):
        _,_,plans=self.plans();step=plans[0]
        report=b.run(self.request('associate',step=self.write('step.json',step),receipt=self.write('floor-empty.json',self.receipt(step,True))),self.root)
        self.assertEqual(report['resultStatus'],'needs_review');self.assertEqual(set(report['outputs']),{'association'})
    def test_finalize_requires_both_bound_receipts(self):
        assessment,plans_asset,plans=self.plans();receipts=[self.write('receipt-'+str(i)+'.json',self.receipt(step)) for i,step in enumerate(plans)]
        req=self.request('finalize-support',assessment=assessment,plans=plans_asset,receipts=receipts)
        report=b.run(req,self.root);self.assertEqual(report['resultStatus'],'ready_for_support_validation')
        record=json.loads(Path(report['outputs']['support-assessment']['path']).read_text())
        self.assertEqual(record['nativeSourceSha256'],self.context['binding']['nativeSourceSha256'])
        self.assertEqual(record['sourceGeometrySha256'],self.context['binding']['sourceGeometrySha256'])
        req['receipts']=list(reversed(receipts));req['outputDirectory']=str(self.root/'wrong-finalize')
        with self.assertRaisesRegex(ValueError,'SEGMENTATION_RECEIPT_BINDING'):b.run(req,self.root)
    def test_cache_drift_never_overwrites(self):
        req,report=self.run_compile();path=Path(report['outputs']['payload']['path']);path.write_bytes(path.read_bytes()+b' ')
        with self.assertRaisesRegex(ValueError,'CACHE_OUTPUT_CHANGED'):b.run(req,self.root)
        self.assertTrue(path.read_bytes().endswith(b' '))
    def test_private_path_and_strict_json(self):
        req=self.request('compile',context=self.context_asset,images=self.image_assets);req['outputDirectory']=str(self.root.parent/'escape')
        with self.assertRaisesRegex(ValueError,'PATH_OUTSIDE_TRUSTED_ROOT'):b.run(req,self.root)
        with self.assertRaisesRegex(ValueError,'DUPLICATE_JSON_KEY'):b.decode_json(b'{"a":1,"a":2}')
        with self.assertRaisesRegex(ValueError,'NONFINITE_JSON'):b.decode_json(b'{"a":NaN}')
    def test_input_drift_during_compile_is_not_published(self):
        req=self.request('compile',context=self.context_asset,images=self.image_assets);original=p.compile_request
        def change(context,images):
            value=original(context,images);Path(self.image_assets[0]['path']).write_bytes(b'changed');return value
        with patch.object(p,'compile_request',side_effect=change):
            with self.assertRaisesRegex(ValueError,'INPUT_CHANGED_DURING_PROCESSING'):b.run(req,self.root)
        self.assertFalse(Path(req['outputDirectory']).exists())
    def test_real_runtime_guard_is_exact(self):
        self.runtime_patch.stop()
        with patch.object(b.platform,'python_version',return_value='3.12.9'):
            with self.assertRaisesRegex(ValueError,'UNVALIDATED_ASSESSMENT'):b.validate_runtime()
        self.runtime_patch.start()
    def test_opaque_rgba_evidence_retains_bytes_but_transparency_rejects(self):
        image=Image.new('RGBA',(2048,2048),(20,30,40,255));buffer=io.BytesIO();image.save(buffer,format='PNG');raw=buffer.getvalue()
        b._validate_image(raw,self.context['evidence'][0])
        image.putpixel((100,100),(20,30,40,254));buffer=io.BytesIO();image.save(buffer,format='PNG')
        with self.assertRaisesRegex(ValueError,'TRANSPARENT_EVIDENCE_UNSUPPORTED'):b._validate_image(buffer.getvalue(),self.context['evidence'][0])


if __name__=='__main__':unittest.main()
