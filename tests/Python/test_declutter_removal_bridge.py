import hashlib,json,sys,tempfile,unittest,uuid
from pathlib import Path
from unittest.mock import patch
sys.path.insert(0,str(Path(__file__).resolve().parents[2]/'resources/iguide-lab'))
import declutter_removal_bridge as bridge
from test_declutter_removal_protocol import fixture


class BridgeTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup);self.root=Path(self.tmp.name).resolve()
        self.context,self.images,self.proposal=fixture();self.context_asset=self.write('context.json',self.context)
        self.image_assets=[dict(self.write(f'{i}.png',raw),id=view['id']) for i,(view,raw) in enumerate(zip(self.context['evidence'],self.images))]
        runtime=patch.object(bridge,'validate_runtime');runtime.start();self.addCleanup(runtime.stop)
    def write(self,name,value):
        raw=value if isinstance(value,bytes) else bridge.canonical(value);p=self.root/name;p.write_bytes(raw)
        return {'path':str(p),'sha256':hashlib.sha256(raw).hexdigest()}
    def request(self,operation,**args):
        return {'schemaVersion':1,'operation':operation,'outputDirectory':str(self.root/operation),**args}
    def compile(self):
        request=self.request('compile',context=self.context_asset,images=self.image_assets)
        return request,bridge.run(request,str(self.root))
    def parsed(self):
        _,compiled=self.compile();payload=compiled['outputs']['payload'];payload={k:payload[k] for k in ['path','sha256']}
        body={'id':'resp_fake','model':bridge.protocol.MODEL,'status':'completed','output':[{'type':'message','role':'assistant','status':'completed','content':[{'type':'output_text','text':json.dumps(self.proposal)}]}]}
        response=self.write('response.json',body)
        execution=self.write('execution.json',{'state':'completed','requestId':str(uuid.UUID(int=8)),'providerResponseId':'resp_fake','model':bridge.protocol.MODEL,
            'contextSha256':bridge.protocol.digest(self.context),'requestPayloadSha256':payload['sha256'],'responseSha256':response['sha256']})
        request=self.request('parse',context=self.context_asset,compiledPayload=payload,response=response,execution=execution)
        return request,bridge.run(request,str(self.root))
    def test_compile_parse_exact_replay(self):
        request,report=self.parsed();self.assertEqual(report['resultStatus'],'ready_for_segmentation');self.assertEqual(report,bridge.run(request,str(self.root)))
        self.assertEqual(report['providerCalls'],0);self.assertFalse(report['automaticAcceptance'])
    def test_reserved_ids_are_bound_only_after_parse(self):
        _,report=self.parsed();asset=report['outputs']['assessment'];asset={k:asset[k] for k in ['path','sha256']}
        request=self.request('bind-plans',assessment=asset,reservations=self.write('reservations.json',{'target_object':str(uuid.UUID(int=22))}))
        result=bridge.run(request,str(self.root));plans=json.loads(Path(result['outputs']['plans']['path']).read_text())
        self.assertEqual(plans[0]['requestId'],str(uuid.UUID(int=22)));self.assertEqual(plans[0]['reservationState'],'server_reserved')
    def test_source_bytes_drift_rejected_on_replay(self):
        request,_=self.compile();Path(self.image_assets[0]['path']).write_bytes(self.images[0]+b'drift')
        with self.assertRaisesRegex(ValueError,'INPUT_HASH'):bridge.run(request,str(self.root))
    def test_cached_payload_drift_rejected(self):
        request,report=self.compile();Path(report['outputs']['payload']['path']).write_text('{}')
        with self.assertRaisesRegex(ValueError,'CACHE_OUTPUT'):bridge.run(request,str(self.root))
    def test_extra_client_fields_and_scope_operations_reject(self):
        req=self.request('compile',context=self.context_asset,images=self.image_assets,humanReviewed=True)
        with self.assertRaisesRegex(ValueError,'REQUEST_FIELDS'):bridge.run(req,str(self.root))
        req=self.request('finalize-support',assessment=self.context_asset,plans=self.context_asset,receipts=[])
        with self.assertRaisesRegex(ValueError,'REQUEST_INVALID'):bridge.run(req,str(self.root))
    def test_wrong_evidence_order_rejected(self):
        request=self.request('compile',context=self.context_asset,images=list(reversed(self.image_assets)))
        with self.assertRaisesRegex(ValueError,'EVIDENCE_ORDER'):bridge.run(request,str(self.root))
    def test_output_cannot_contain_input(self):
        request=self.request('compile',context=self.context_asset,images=self.image_assets);request['outputDirectory']=str(self.root)
        with self.assertRaises(ValueError):bridge.run(request,str(self.root))


if __name__=='__main__':unittest.main()
