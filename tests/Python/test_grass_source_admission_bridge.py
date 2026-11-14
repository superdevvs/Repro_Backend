import hashlib,json,shutil,sys,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'resources/iguide-lab'))
import grass_source_admission_bridge as bridge
import grass_source_detail_views as details
from test_grass_source_admission_protocol import fixture,request_detail

class AdmissionBridgeTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.root=Path(self.tmp.name)
        self.guard=patch.object(bridge,'validate_runtime');self.guard.start()
    def tearDown(self):self.guard.stop();self.tmp.cleanup()
    def desc(self,path):return {'path':str(path),'sha256':hashlib.sha256(path.read_bytes()).hexdigest()}
    def save(self,name,value):
        path=self.root/name;path.write_text(json.dumps(value));return self.desc(path)
    def compiled(self):
        context,proposal=fixture();assets=[]
        folder=ROOT/'tests/Fixtures/iguide-lab/grass-integration/images'
        for view in context['evidence']:
            filename='source.png' if view['id']=='source_panorama' else view['id']+'.png'
            path=self.root/filename;shutil.copyfile(folder/filename,path);assets.append({'id':view['id'],**self.desc(path)})
        req={'schemaVersion':1,'operation':'compile','outputDirectory':str(self.root/'compiled'),'context':self.save('context.json',context),'images':assets}
        report=bridge.run(req,self.root);return context,proposal,req,report
    def parsed(self):
        context,proposal,compile_req,compiled=self.compiled();request_detail(proposal)
        raw={'id':'resp-source-fixture','model':'gpt-6-astra','status':'completed','output':[{'type':'message','role':'assistant','status':'completed','content':[{'type':'output_text','text':json.dumps(proposal)}]}]}
        response=self.save('response.json',raw)
        payload={k:compiled['outputs']['payload'][k] for k in ['path','sha256']}
        execution={'state':'completed','requestId':context['binding']['workflowItemId'],'providerResponseId':raw['id'],'model':raw['model'],
            'contextSha256':bridge.protocol.digest(context),'requestPayloadSha256':payload['sha256'],'responseSha256':response['sha256']}
        req={'schemaVersion':1,'operation':'parse','outputDirectory':str(self.root/'parsed'),'context':compile_req['context'],
            'response':response,'compiledPayload':payload,'execution':self.save('execution.json',execution)}
        return req,bridge.run(req,self.root)
    def test_actual_compile_parse_and_replay_preserve_source_only_request(self):
        req,report=self.parsed();self.assertEqual(report['resultStatus'],'needs_detail')
        self.assertEqual(bridge.run(req,self.root),report);self.assertEqual(report['providerCalls'],0)
        value=json.loads(Path(report['outputs']['assessment']['path']).read_bytes())
        self.assertFalse(value['segmentationAuthority']);self.assertEqual(len(value['detailPlan']),1)
    def test_changed_retained_response_and_compiled_source_reject(self):
        req,_=self.parsed();path=Path(req['response']['path']);path.write_bytes(path.read_bytes()+b' ')
        with self.assertRaisesRegex(ValueError,'INPUT_HASH'):bridge.run(req,self.root)
    def test_non_source_operations_and_output_authority_reject(self):
        req,report=self.parsed();req['operation']='bind-plans'
        with self.assertRaisesRegex(ValueError,'REQUEST_INVALID'):bridge.run(req,self.root)
        req['operation']='parse';path=Path(req['outputDirectory'])/'report.json';raw=json.loads(path.read_bytes());raw['automaticAcceptance']=True;path.write_text(json.dumps(raw))
        with self.assertRaisesRegex(ValueError,'CACHE_AUTHORITY'):bridge.run(req,self.root)

class NativeDetailTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.root=Path(self.tmp.name)
        self.source=self.root/'native.png';shutil.copyfile(ROOT/'tests/Fixtures/iguide-lab/grass-integration/images/source.png',self.source)
        self.sha=hashlib.sha256(self.source.read_bytes()).hexdigest()
        self.request={'schemaVersion':1,'source':{'path':str(self.source),'sha256':self.sha},'outputDirectory':str(self.root/'details'),
            'requests':[{'pose':{'yaw':0,'pitch':-30,'hFov':45},'purpose':'Synthetic native boundary diagnostic.','evidenceIds':['source_panorama']}]}
        self.guard=patch.object(details,'runtime',return_value={**details.runtime(),'python':'3.12.3','pillow':'13.0.0','numpy':'2.5.4'});self.guard.start()
    def tearDown(self):self.guard.stop();self.tmp.cleanup()
    def test_native_extraction_and_exact_replay_keep_pose_source_and_scope(self):
        report=details.run(self.request,self.root);self.assertEqual(report,details.run(self.request,self.root))
        self.assertEqual(report['source']['sha256'],self.sha);self.assertFalse(report['editScopeExpanded'])
        self.assertEqual(report['views'][0]['pose'],{'yaw':0,'pitch':-30,'hFov':45,'width':1024,'height':1024})
        self.assertEqual(hashlib.sha256(self.source.read_bytes()).hexdigest(),self.sha)
    def test_changed_detail_pose_source_or_output_rejects(self):
        report=details.run(self.request,self.root);self.request['requests'][0]['pose']['yaw']=1
        with self.assertRaisesRegex(ValueError,'REPLAY_BINDING'):details.run(self.request,self.root)
        self.request['requests'][0]['pose']['yaw']=0
        path=Path(report['views'][0]['path']);path.write_bytes(path.read_bytes()+b'changed')
        with self.assertRaisesRegex(ValueError,'IMAGE_OR_POSE'):details.run(self.request,self.root)
    def test_too_many_details_and_outside_root_reject(self):
        self.request['requests']*=3
        with self.assertRaisesRegex(ValueError,'COUNT'):details.run(self.request,self.root)
        self.request['requests']=self.request['requests'][:1];self.request['outputDirectory']=str(self.root.parent/'outside-detail')
        with self.assertRaisesRegex(ValueError,'OUTSIDE_TRUSTED'):details.run(self.request,self.root)

if __name__=='__main__':unittest.main()
