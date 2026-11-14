import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import shutil
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'resources/iguide-lab'))
import grass_assessment_bridge as bridge
import grass_assessment_protocol as protocol


class GrassBridgeTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.root=Path(self.tmp.name)
        shutil.copytree(ROOT/'tests/Fixtures/iguide-lab/grass-integration',self.root/'inputs')
        self.inputs=self.root/'inputs'
        self.guard=patch.object(bridge,'validate_runtime');self.guard.start()
    def tearDown(self):self.guard.stop();self.tmp.cleanup()
    def desc(self,path):return {'path':str(path),'sha256':hashlib.sha256(path.read_bytes()).hexdigest()}
    def save(self,name,value):
        path=self.root/name;path.write_text(json.dumps(value),encoding='utf-8');return self.desc(path)
    def request(self,op,**assets):return {'schemaVersion':1,'operation':op,'outputDirectory':str(self.root/('out-'+op)),**assets}
    def compile(self):
        context=json.loads((self.inputs/'context.json').read_text());images=[]
        for e in context['evidence']:
            name={'source_panorama':'source','candidate_panorama':'candidate'}.get(e['id'],e['id'])
            images.append({'id':e['id'],**self.desc(self.inputs/'images'/f'{name}.png')})
        req=self.request('compile',context=self.desc(self.inputs/'context.json'),images=images)
        return req,bridge.run(req,self.root)
    def parsed(self):
        _,compiled=self.compile();payload=compiled['outputs']['payload']
        execution=json.loads((self.inputs/'execution.SYNTHETIC.json').read_text());execution['requestPayloadSha256']=payload['sha256']
        req=self.request('parse',context=self.desc(self.inputs/'context.json'),response=self.desc(self.inputs/'response.SYNTHETIC.json'),
            execution=self.save('execution.json',execution),compiledPayload={k:payload[k] for k in ['path','sha256']})
        return req,bridge.run(req,self.root)
    def test_actual_compile_parse_replay_no_provider(self):
        req,report=self.parsed();self.assertEqual(report['resultStatus'],'ready_for_source_segmentation')
        before=(Path(report['outputs']['assessment']['path'])).read_bytes()
        self.assertEqual(bridge.run(req,self.root),report)
        self.assertEqual(Path(report['outputs']['assessment']['path']).read_bytes(),before)
        self.assertEqual(report['providerCalls'],0);self.assertFalse(report['automaticAcceptance'])
    def test_six_roles_associate_and_finalize_exact_fixture(self):
        _,parsed=self.parsed();assessment={k:parsed['outputs']['assessment'][k] for k in ['path','sha256']}
        bound=bridge.run(self.request('bind-plans',assessment=assessment,reservations=self.desc(self.inputs/'reservations.mock-database.json')),self.root)
        plans={k:bound['outputs']['plans'][k] for k in ['path','sha256']}
        values=json.loads(Path(plans['path']).read_text());receipts=[]
        for i,step in enumerate(values):
            receipt=self.desc(self.inputs/'receipts'/f"{step['maskRole']}.json");receipts.append(receipt)
            req=self.request('associate',step=self.save('step-'+str(i)+'.json',step),receipt=receipt)
            req['outputDirectory']=str(self.root/('association-'+str(i)))
            self.assertEqual(bridge.run(req,self.root)['resultStatus'],'ready_for_source_support')
        req=self.request('finalize-support',assessment=assessment,plans=plans,receipts=receipts)
        result=bridge.run(req,self.root)
        actual=json.loads(Path(result['outputs']['support-assessment']['path']).read_text())
        self.assertEqual(actual,json.loads((self.inputs/'composition-assessment.json').read_text()))
        self.assertEqual(bridge.run(req,self.root),result)
    def test_receipt_count_does_not_silently_zip(self):
        req=self.request('finalize-support',assessment=self.desc(self.inputs/'assessment.json'),plans=self.desc(self.inputs/'plans.json'),
            receipts=[self.desc(self.inputs/'receipts/lawn.json')])
        with self.assertRaisesRegex(ValueError,'RECEIPT_COUNT'):bridge.run(req,self.root)
    def test_missing_mask_yields_review_and_no_support_record(self):
        receipts=json.loads((self.inputs/'receipts.json').read_text());receipts[0]['masks']=[]
        req=self.request('finalize-support',assessment=self.desc(self.inputs/'assessment.json'),plans=self.desc(self.inputs/'plans.json'),
            receipts=[self.save('receipt-'+str(i)+'.json',r) for i,r in enumerate(receipts)])
        result=bridge.run(req,self.root);self.assertEqual(result['resultStatus'],'needs_review')
        self.assertEqual(set(result['outputs']),{'associations'})
    def test_changed_response_payload_native_and_mask_bindings_reject(self):
        req,_=self.parsed();path=Path(req['response']['path']);path.write_bytes(path.read_bytes()+b' ')
        with self.assertRaisesRegex(ValueError,'INPUT_HASH'):bridge.run(req,self.root)
        plan=json.loads((self.inputs/'plans.json').read_text())[0]
        receipt=json.loads((self.inputs/'receipts/lawn.json').read_text());receipt['inputSha256']='f'*64
        with self.assertRaisesRegex(ValueError,'RECEIPT_BINDING'):
            bridge.run(self.request('associate',step=self.save('step.json',plan),receipt=self.save('wrong.json',receipt)),self.root)
    def test_output_drift_and_shared_module_drift_reject(self):
        req,report=self.parsed();path=Path(report['outputs']['assessment']['path']);path.write_bytes(b'{}')
        with self.assertRaisesRegex(ValueError,'CACHE_OUTPUT'):bridge.run(req,self.root)
    def test_wrong_runtime_and_private_path_reject(self):
        self.guard.stop()
        with patch.object(bridge.platform,'python_version',return_value='0.0.0'):
            with self.assertRaisesRegex(ValueError,'RUNTIME'):bridge.validate_runtime()
        self.guard.start()
        req,_=self.compile();req['outputDirectory']=str(self.root.parent/'outside-grass')
        with self.assertRaisesRegex(ValueError,'OUTSIDE_TRUSTED'):bridge.run(req,self.root)
    def test_completed_cache_cannot_claim_acceptance(self):
        req,_=self.parsed();path=Path(req['outputDirectory'])/'report.json';report=json.loads(path.read_text())
        report['automaticAcceptance']=True;path.write_text(json.dumps(report))
        with self.assertRaisesRegex(ValueError,'CACHE_AUTHORITY'):bridge.run(req,self.root)

if __name__=='__main__':unittest.main()
