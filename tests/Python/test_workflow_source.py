import hashlib,json,os,subprocess,sys,tempfile,unittest
from pathlib import Path
from PIL import Image
SCRIPT=Path(__file__).resolve().parents[2]/'resources'/'iguide-lab'/'workflow_source.py'

class SourcePreflightTests(unittest.TestCase):
    def run_source(self,profile=False,wrong_hash=False):
        with tempfile.TemporaryDirectory() as temp:
            root=Path(temp); path=root/'source.png'
            Image.new('RGB',(2048,1024),(125,130,140)).save(path,**({'icc_profile':b'fixture-embedded-profile'} if profile else {}))
            request={'source':{'path':str(path),'sha256':hashlib.sha256(path.read_bytes()).hexdigest()},'privateRoot':str(root)}
            if wrong_hash:request['source']['sha256']='0'*64
            (root/'request.json').write_text(json.dumps(request))
            env=dict(os.environ,OPENBLAS_NUM_THREADS='1',OMP_NUM_THREADS='1')
            result=subprocess.run([sys.executable,str(SCRIPT),str(root/'request.json')],capture_output=True,text=True,env=env,timeout=40)
            return result.returncode,json.loads(result.stdout)
    def test_valid_rgb_is_preflighted_without_provider(self):
        code,report=self.run_source();self.assertEqual(code,0);self.assertEqual(report['dimensions'],[2048,1024]);self.assertEqual(report['providerCalls'],0)
    def test_embedded_profile_requires_explicit_normalization_before_payment(self):
        code,report=self.run_source(profile=True);self.assertEqual(code,1);self.assertEqual(report['code'],'NORMALIZATION_REQUIRED')
    def test_wrong_source_hash_is_rejected(self):
        code,report=self.run_source(wrong_hash=True);self.assertEqual(code,1);self.assertEqual(report['code'],'INPUT_HASH_MISMATCH')
