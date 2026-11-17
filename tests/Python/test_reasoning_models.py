"""The lab reasoning model is passed in by the server and checked against one shared allow-list. No provider dispatch."""
import copy
import hashlib
import inspect
import json
from pathlib import Path
import re
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'resources/iguide-lab'))
sys.path.insert(0,str(Path(__file__).resolve().parent))
import staging_assessment_protocol as shared
import staging_assessment_bridge as bridge
import grass_assessment_protocol as grass
import grass_source_admission_protocol as admission
import declutter_removal_protocol as declutter
import lifestyle_assessment_protocol as lifestyle
from test_staging_assessment_bridge import fixture as staging_fixture

TERRA='gpt-6.1-terra'
ECHO='gpt-6.1-terra-2026-11-01'


class AllowListTests(unittest.TestCase):
    def test_python_list_matches_the_php_config(self):
        config=(ROOT/'config/iguide-lab-models.php').read_text()
        listed=re.search(r"'allowed' => \[([^\]]*)\]",config).group(1)
        self.assertEqual(tuple(re.findall(r"'([^']+)'",listed)),shared.REASONING_MODELS)

    def test_every_protocol_takes_the_model_from_the_server_and_has_no_own_constant(self):
        for module in [shared,grass,admission,declutter,lifestyle]:
            self.assertFalse(hasattr(module,'MODEL'),module.__name__)
            self.assertEqual(list(inspect.signature(module.compile_request).parameters)[-1],'model',module.__name__)
            self.assertEqual(list(inspect.signature(module.parse_response).parameters)[-1],'model',module.__name__)

    def test_echo_is_the_requested_model_or_its_dated_snapshot(self):
        self.assertTrue(shared.model_echoes(TERRA,TERRA));self.assertTrue(shared.model_echoes(TERRA,ECHO))
        # ASCII digits only, as PHP's preg \d without /u: a Unicode-digit date must not parse here and then fail PHP checks.
        for echo in ['gpt-6-astra',TERRA+'-latest',TERRA+'-2026-11',ECHO+'x',ECHO+'\n',TERRA+'-٢٠٢٦-01-01',TERRA+'-２０２６-11-01',None,7]:self.assertFalse(shared.model_echoes(TERRA,echo),echo)
        self.assertFalse(shared.model_echoes('gpt-5','gpt-5'))
        self.assertEqual(shared.reasoning_model(TERRA),TERRA)
        for bad in ['gpt-5',ECHO,None,'']:
            with self.assertRaisesRegex(ValueError,'REASONING_MODEL_NOT_ALLOWED'):shared.reasoning_model(bad)


class StagingBridgeModelTests(unittest.TestCase):
    """Compile with the configured model, accept its dated echo; a stored astra record keeps validating."""
    @classmethod
    def setUpClass(cls):cls.context,cls.images,cls.proposal=staging_fixture()
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.root=Path(self.temp.name).resolve()
        self.runtime_patch=patch.object(bridge,'validate_runtime',return_value=None);self.runtime_patch.start()
        self.context_asset=self.write('context.json',self.context)
        self.image_assets=[{'id':view['id'],**self.write(view['id']+'.png',raw)} for view,raw in zip(self.context['evidence'],self.images)]
    def tearDown(self):self.runtime_patch.stop();self.temp.cleanup()
    def write(self,name,value):
        raw=value if isinstance(value,bytes) else bridge.canonical(value);path=self.root/name;path.write_bytes(raw)
        return {'path':str(path),'sha256':hashlib.sha256(raw).hexdigest()}
    def compile(self,model):
        req={'schemaVersion':1,'operation':'compile','outputDirectory':str(self.root/('compile-'+model)),'context':self.context_asset,'images':self.image_assets,'model':model}
        payload=bridge.run(req,self.root)['outputs']['payload'];return {k:payload[k] for k in ['path','sha256']}
    def parse(self,payload,echo,name):
        response=self.write(name+'-response.json',{'id':'resp_synthetic','model':echo,'status':'completed','output':[{'type':'message','role':'assistant','status':'completed',
            'content':[{'type':'output_text','text':json.dumps(self.proposal)}]}]})
        execution={'state':'completed','requestId':'07c734c6-d827-414d-92de-329d661eb656','providerResponseId':'resp_synthetic','model':echo,
            'contextSha256':shared.digest(self.context),'requestPayloadSha256':payload['sha256'],'responseSha256':response['sha256']}
        return bridge.run({'schemaVersion':1,'operation':'parse','outputDirectory':str(self.root/(name+'-parsed')),'context':self.context_asset,'response':response,
            'execution':self.write(name+'-execution.json',execution),'compiledPayload':payload},self.root)

    def test_configured_model_is_compiled_and_its_dated_echo_parses(self):
        payload=self.compile(TERRA);self.assertEqual(json.loads(Path(payload['path']).read_text())['model'],TERRA)
        self.assertEqual(self.parse(payload,ECHO,'terra')['resultStatus'],'ready_for_segmentation')

    def test_stored_astra_request_still_parses_and_bodies_differ_only_by_model(self):
        astra=self.compile('gpt-6-astra');terra=self.compile(TERRA)
        self.assertEqual(self.parse(astra,'gpt-6-astra','astra')['resultStatus'],'ready_for_segmentation')
        a=json.loads(Path(astra['path']).read_text());t=json.loads(Path(terra['path']).read_text());a['model']=TERRA;self.assertEqual(a,t)

    def test_echo_of_another_model_or_an_unlisted_model_is_rejected(self):
        payload=self.compile(TERRA)
        with self.assertRaisesRegex(ValueError,'EXECUTION_BINDING_INVALID'):self.parse(payload,'gpt-6-astra','other')
        with self.assertRaisesRegex(ValueError,'REASONING_MODEL_NOT_ALLOWED'):self.compile('gpt-5')
        with self.assertRaisesRegex(ValueError,'REQUEST_FIELDS_INVALID'):
            bridge.run({'schemaVersion':1,'operation':'compile','outputDirectory':str(self.root/'missing'),'context':self.context_asset,'images':self.image_assets},self.root)


class GrassParseModelTests(unittest.TestCase):
    def setUp(self):
        fixtures=ROOT/'tests/Fixtures/iguide-lab/grass-integration'
        self.context=json.loads((fixtures/'context.json').read_text());self.envelope=json.loads((fixtures/'response.SYNTHETIC.json').read_text())
        self.execution=json.loads((fixtures/'execution.SYNTHETIC.json').read_text())
    def parse(self,requested,echo,execution_model=None):
        envelope=copy.deepcopy(self.envelope);envelope['model']=echo;raw=json.dumps(envelope)
        execution=dict(self.execution,model=execution_model or echo,responseSha256=hashlib.sha256(raw.encode()).hexdigest())
        return grass.parse_response(raw,self.context,execution,requested)
    def test_dated_echo_of_the_requested_model_parses_and_mismatches_reject(self):
        self.assertEqual(self.parse(TERRA,ECHO)['execution']['model'],ECHO)
        with self.assertRaisesRegex(ValueError,'GRASS_EXECUTION_BINDING_INVALID'):self.parse(TERRA,'gpt-6-astra')
        with self.assertRaisesRegex(ValueError,'GRASS_EXECUTION_BINDING_INVALID'):self.parse('gpt-5','gpt-5')
        with self.assertRaisesRegex(ValueError,'GRASS_RESPONSE_ENVELOPE_INVALID'):self.parse(TERRA,ECHO,TERRA)


if __name__=='__main__':unittest.main()
