from pathlib import Path
import copy
import json
import os
import platform
import sys
import tempfile
import unittest
from unittest import mock
import uuid
import cv2
import numpy as np
from PIL import Image

ROOT=Path(__file__).resolve().parents[2]/'resources'/'iguide-lab'
sys.path.insert(0,str(ROOT));sys.path.insert(0,str(Path(__file__).parent))
import interior_material_worker as worker
from test_interior_material_transfer import fixture


class WorkerTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        s,c,t,p=fixture()
        cls.source=cv2.resize(s,(2048,2048));cls.candidate=cv2.resize(c,(2048,2048))
        cls.targets=[{'id':v['id'],'registrationSurface':v['registrationSurface'],
            'sourceMask':cv2.resize(v['sourceMask'],(2048,2048),interpolation=cv2.INTER_NEAREST),
            'candidateMask':cv2.resize(v['candidateMask'],(2048,2048),interpolation=cv2.INTER_NEAREST)} for v in t]
        cls.protected=np.zeros((2048,2048),np.uint8)
        cls.native=np.full((256,512,3),[125,120,115],np.uint8)
        cls.native[...,1]+=np.tile(np.arange(512,dtype=np.uint16)%11,(256,1)).astype(np.uint8)

    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.root=Path(self.temp.name)
        self.pins=mock.patch.object(worker,'PINS',{'python':platform.python_version(),'numpy':np.__version__,'pillow':worker.PILLOW_VERSION,'opencv':cv2.__version__});self.pins.start()
        self.request=self.prepare()

    def tearDown(self):self.pins.stop();self.temp.cleanup()

    def save(self,name,value):
        path=self.root/name
        if isinstance(value,np.ndarray):Image.fromarray(value).save(path)
        else:path.write_bytes(worker.canonical(value))
        return {'path':str(path),'sha256':worker.sha(path)}

    def prepare(self):
        source=self.save('source.png',self.source);candidate=self.save('candidate.png',self.candidate);native=self.save('native.png',self.native)
        cut_path=self.root/'source-cut.png';Image.fromarray(self.source).save(cut_path,compress_level=1)
        cut={'path':str(cut_path),'sha256':worker.sha(cut_path)}
        self.assertNotEqual(cut['sha256'],source['sha256'])
        geometry=self.save('geometry.json',{'version':'interior-source-geometry/1','nativeSourceSha256':native['sha256'],'sourceCutSha256':cut['sha256'],'preparedSourceSha256':source['sha256'],
            'projection':'rectilinear','width':2048,'height':2048,'view':{'yaw':170,'pitch':-15,'hFov':100},'convention':worker.CONVENTION})
        protected=self.save('protected.png',self.protected)
        target_assets=[(t,self.save(t['id']+'-source.png',t['sourceMask']),self.save(t['id']+'-candidate.png',t['candidateMask'])) for t in self.targets]
        assessment=self.save('assessment.json',{'version':'interior-material-assessment/1','status':'eligible_for_transfer','authorOrigin':'machine',
            'nativeSourceSha256':native['sha256'],'sourceCutSha256':cut['sha256'],'sourceSha256':source['sha256'],'candidateSha256':candidate['sha256'],'sourceGeometrySha256':geometry['sha256'],
            'protectedForegroundSha256':protected['sha256'],'foregroundFindings':{'source':'none_observed','candidate':'none_observed'},
            'checks':dict.fromkeys(worker.core.CHECKS,'supported'),
            'targets':[{'id':t['id'],'sourceMaskSha256':s['sha256'],'candidateMaskSha256':c['sha256'],'registrationSurface':t['registrationSurface']} for t,s,c in target_assets]})
        def evidence(mid,role,image,input_sha,origin):
            ev={'version':'interior-machine-mask-association/1','targetId':mid,'role':role,'inputSha256':input_sha,'maskSha256':image['sha256'],
                'generationOrigin':origin,'selectionOrigin':'machine','assessmentSha256':assessment['sha256']}
            if origin=='model_segmentation':ev['providerReceiptSha256']='9'*64
            return self.save(mid+'-'+role+'-evidence.json',ev)
        records=[]
        for t,s,c in target_assets:
            records.append({'id':t['id'],'sourceMask':{'id':t['id'],'role':'source-material','image':s,'evidence':evidence(t['id'],'source-material',s,source['sha256'],'model_segmentation')},
                'candidateMask':{'id':t['id'],'role':'candidate-material','image':c,'evidence':evidence(t['id'],'candidate-material',c,candidate['sha256'],'model_segmentation')}})
        runtime=self.save('runtime.json',{'version':worker.RUNTIME_VERSION,'validationStatus':'passed',**worker.PINS,'files':worker.module_hashes()})
        self.runtime=Path(runtime['path'])
        return {'version':worker.VERSION,'requestId':str(uuid.uuid4()),'outputDirectory':str(self.root/'output'),'runtimeManifestSha256':runtime['sha256'],
            'sourceCut':cut,'source':source,'candidate':candidate,'native':native,'geometry':geometry,'assessment':assessment,'targets':records,
            'protectedForeground':{'id':'protected_foreground','role':'source-protected-foreground','image':protected,
                'evidence':evidence('protected_foreground','source-protected-foreground',protected,source['sha256'],'machine_assessed_absence')}}

    def run_worker(self):return worker.run(self.request,self.root,self.runtime)

    def source_only(self):
        self.request.pop('candidate');self.request['operation']='source-support'
        self.request['targets']=[{k:v for k,v in r.items() if k!='candidateMask'} for r in self.request['targets']]
        def change(a):
            a.pop('candidateSha256');a['version']='interior-material-source-assessment/1';a['status']='eligible_for_source_support'
            a['foregroundFindings']={'source':'none_observed'}
            for t in a['targets']:t.pop('candidateMaskSha256')
        self.update_json(self.request['assessment'],change)
        for record in [self.request['protectedForeground']]+[t['sourceMask'] for t in self.request['targets']]:
            self.update_json(record['evidence'],lambda e:e.update(assessmentSha256=self.request['assessment']['sha256']))

    def test_source_only_gate_exact_replay_and_no_fabricated_donor(self):
        self.source_only()
        with mock.patch.object(worker.core,'derive_transfer',side_effect=AssertionError('No donor operation')):
            report=self.run_worker();self.assertEqual(report['status'],'eligible_for_donor_request',report)
            self.assertEqual(report['outputs'],{});self.assertFalse(report['imageGenerationAuthorized']);self.assertFalse(report['materialTransferAuthorized'])
            self.assertEqual(self.run_worker(),report)
        self.request['candidate']=self.request['source']
        with self.assertRaisesRegex(ValueError,'SOURCE_REQUEST_SCHEMA'):self.run_worker()

    def test_source_only_uncertain_optics_holds_without_algorithm(self):
        self.source_only();self.update_json(self.request['assessment'],lambda a:a['checks'].update(offCutOptics='uncertain'))
        for r in [self.request['protectedForeground']]+[t['sourceMask'] for t in self.request['targets']]:
            self.update_json(r['evidence'],lambda e:e.update(assessmentSha256=self.request['assessment']['sha256']))
        with mock.patch.object(worker.core,'inspect_source_support',side_effect=AssertionError('Must hold')):report=self.run_worker()
        self.assertEqual(report['status'],'needs_review');self.assertEqual(report['outputs'],{})

    def test_source_only_extracted_prepared_pixels_must_match(self):
        self.source_only();changed=self.source.copy();changed[0,0,0]^=1
        self.request['sourceCut']=self.save('source-cut.png',changed)
        self.update_json(self.request['geometry'],lambda g:g.update(sourceCutSha256=self.request['sourceCut']['sha256']))
        self.update_json(self.request['assessment'],lambda a:a.update(sourceCutSha256=self.request['sourceCut']['sha256'],sourceGeometrySha256=self.request['geometry']['sha256']))
        for r in [self.request['protectedForeground']]+[t['sourceMask'] for t in self.request['targets']]:
            self.update_json(r['evidence'],lambda e:e.update(assessmentSha256=self.request['assessment']['sha256']))
        with self.assertRaisesRegex(ValueError,'PREPARED_CUT_PIXEL_DRIFT'):self.run_worker()

    def update_json(self,desc,fn):
        value=json.loads(Path(desc['path']).read_text());fn(value);Path(desc['path']).write_bytes(worker.canonical(value));desc['sha256']=worker.sha(desc['path'])

    def test_actual_2048_transfer_native_replay_and_output_drift(self):
        report=self.run_worker();self.assertEqual(report['status'],'unreviewed',report)
        path=self.root/'output'/'report.json';before=path.read_bytes()
        self.assertEqual(self.run_worker(),report);self.assertEqual(path.read_bytes(),before)
        out=np.array(Image.open(self.root/'output'/'native.png'));alpha=np.array(Image.open(self.root/'output'/'effective-alpha.png'))
        self.assertTrue(np.array_equal(out[alpha==0],self.native[alpha==0]));self.assertGreater(int(alpha.sum()),0)
        self.assertFalse(report['manualAcceptance']);self.assertEqual(report['generatedDetail'],[2048,2048])
        (self.root/'output'/'native.png').write_bytes(b'changed')
        with self.assertRaisesRegex(ValueError,'CACHED_ASSET_DRIFT'):self.run_worker()

    def test_runtime_and_validated_code_drift(self):
        with self.subTest('manifest'):
            self.request['runtimeManifestSha256']='0'*64
            with self.assertRaisesRegex(ValueError,'RUNTIME_MANIFEST_HASH'):self.run_worker()
        self.request['runtimeManifestSha256']=worker.sha(self.runtime)
        value=worker.read_json(self.runtime);value['files']['interior_material_transfer.py']='0'*64;self.runtime.write_bytes(worker.canonical(value));self.request['runtimeManifestSha256']=worker.sha(self.runtime)
        with self.assertRaisesRegex(ValueError,'VALIDATED_CODE_HASH'):self.run_worker()

    def test_raw_input_native_and_geometry_binding(self):
        Path(self.request['source']['path']).write_bytes(b'wrong')
        with self.assertRaisesRegex(ValueError,'INPUT_HASH_MISMATCH'):self.run_worker()
        self.request['source']=self.save('source.png',self.source)
        self.update_json(self.request['geometry'],lambda v:v.update(nativeSourceSha256='0'*64))
        with self.assertRaisesRegex(ValueError,'SOURCE_GEOMETRY_BINDING'):self.run_worker()

    def test_assessment_geometry_and_candidate_drift(self):
        for key in ('sourceGeometrySha256','candidateSha256'):
            with self.subTest(key=key):
                desc=self.request['assessment'];raw=Path(desc['path']).read_bytes();old=desc['sha256']
                self.update_json(desc,lambda v:v.update({key:'0'*64}))
                with self.assertRaisesRegex(ValueError,'ASSESSMENT_BINDING_MISMATCH'):self.run_worker()
                Path(desc['path']).write_bytes(raw);desc['sha256']=old

    def test_extracted_and_prepared_source_hashes_are_distinct_and_bound(self):
        self.assertNotEqual(self.request['sourceCut']['sha256'],self.request['source']['sha256'])
        for key in ('sourceCutSha256','preparedSourceSha256'):
            desc=self.request['geometry'];raw=Path(desc['path']).read_bytes();old=desc['sha256']
            self.update_json(desc,lambda v:v.update({key:'0'*64}))
            with self.assertRaisesRegex(ValueError,'SOURCE_GEOMETRY_BINDING'):self.run_worker()
            Path(desc['path']).write_bytes(raw);desc['sha256']=old
        Path(self.request['sourceCut']['path']).write_bytes(b'drift')
        with self.assertRaisesRegex(ValueError,'INPUT_HASH_MISMATCH'):self.run_worker()

    def test_role_source_and_manual_mask_authority_rejected(self):
        record=self.request['targets'][0]['candidateMask'];record['role']='source-material'
        with self.assertRaisesRegex(ValueError,'MASK_ID_ROLE_MISMATCH'):self.run_worker()
        record['role']='candidate-material'
        self.update_json(record['evidence'],lambda v:v.update(selectionOrigin='human'))
        with self.assertRaisesRegex(ValueError,'MASK_ASSOCIATION_MISMATCH'):self.run_worker()

    def test_foreground_absence_never_fabricates_provider_receipt(self):
        record=self.request['protectedForeground'];self.update_json(record['evidence'],lambda v:v.update(providerReceiptSha256='0'*64))
        with self.assertRaisesRegex(ValueError,'FOREGROUND_ABSENCE_SCHEMA'):self.run_worker()

    def test_missing_or_nonzero_absence_mask_rejected(self):
        record=self.request['protectedForeground'];p=self.protected.copy();p[1000,1000]=255
        record['image']=self.save('protected.png',p)
        self.update_json(record['evidence'],lambda v:v.update(maskSha256=record['image']['sha256']))
        self.update_json(self.request['assessment'],lambda v:v.update(protectedForegroundSha256=record['image']['sha256']))
        self.update_json(record['evidence'],lambda v:v.update(assessmentSha256=self.request['assessment']['sha256']))
        with self.assertRaisesRegex(ValueError,'FOREGROUND_ABSENCE_CONTRADICTION'):self.run_worker()

    def test_path_escape_and_existing_output_not_overwritten(self):
        self.request['outputDirectory']=str(self.root.parent/'outside')
        with self.assertRaisesRegex(ValueError,'PATH_OUTSIDE_TRUSTED_ROOT'):self.run_worker()
        self.request['outputDirectory']=str(self.root/'output');(self.root/'output').mkdir();(self.root/'output'/'keep.txt').write_text('keep')
        with self.assertRaisesRegex(ValueError,'OUTPUT_EXISTS_INCOMPLETE'):self.run_worker()
        self.assertEqual((self.root/'output'/'keep.txt').read_text(),'keep')

    def test_opaque_mask_formats_and_transparency(self):
        m=np.zeros((2048,2048),np.uint8);m[300:800,300:800]=255
        for mode in ('L','LA','RGB','RGBA'):
            path=self.root/(mode+'.png');Image.fromarray(m).convert(mode).save(path)
            self.assertTrue(np.array_equal(worker.image(path,'mask'),m))
        rgba=np.zeros((2048,2048,4),np.uint8);rgba[...,3]=255;rgba[500,500,3]=254
        path=self.root/'transparent.png';Image.fromarray(rgba).save(path)
        with self.assertRaisesRegex(ValueError,'TRANSPARENT_IMAGE_REJECTED'):worker.image(path,'mask')
        rgba[...,3]=255;rgba[500,500,:3]=[20,30,20];Image.fromarray(rgba).save(path)
        with self.assertRaisesRegex(ValueError,'COLORED_MASK_REJECTED'):worker.image(path,'mask')

    def test_input_drift_during_solve_does_not_publish(self):
        original=worker.core.derive_transfer
        def drift(*args):
            result=original(*args);Path(self.request['source']['path']).write_bytes(b'drift');return result
        with mock.patch.object(worker.core,'derive_transfer',side_effect=drift):
            with self.assertRaisesRegex(ValueError,'INPUT_DRIFT_DURING_WORK'):self.run_worker()
        self.assertFalse((self.root/'output').exists())

    def test_hold_cached_shape_and_no_candidate(self):
        with mock.patch.object(worker.core,'derive_transfer',return_value={'status':'needs_review','reasons':['REGISTRATION_TEST_HOLD'],'diagnostics':{}}):report=self.run_worker()
        self.assertEqual(report['outputs'],{});self.assertEqual(self.run_worker(),report)
        path=self.root/'output'/'report.json';value=json.loads(path.read_text());value['status']='unreviewed';path.write_bytes(worker.canonical(value))
        commit=self.root/'output'/'commit.json';cv=json.loads(commit.read_text());cv['reportSha256']=worker.sha(path);commit.write_bytes(worker.canonical(cv))
        with self.assertRaisesRegex(ValueError,'CACHED_OUTPUT_SHAPE'):self.run_worker()

    def test_typed_optical_hold_skips_algorithm(self):
        self.update_json(self.request['assessment'],lambda a:a['checks'].update(offCutOptics='uncertain'))
        records=[self.request['protectedForeground']]+[r[k] for r in self.request['targets'] for k in ('sourceMask','candidateMask')]
        for record in records:self.update_json(record['evidence'],lambda e:e.update(assessmentSha256=self.request['assessment']['sha256']))
        with mock.patch.object(worker.core,'derive_transfer',side_effect=AssertionError('must not run')):report=self.run_worker()
        self.assertEqual(report['status'],'needs_review');self.assertEqual(report['outputs'],{})

    def test_observed_foreground_requires_exact_machine_union(self):
        p=self.protected.copy();p[790:810,590:610]=255
        record=self.request['protectedForeground'];record['image']=self.save('protected.png',p)
        self.update_json(self.request['assessment'],lambda a:a.update(protectedForegroundSha256=record['image']['sha256'],foregroundFindings={'source':'observed','candidate':'observed'}))
        member={'id':'occluder_1','role':'source-foreground','image':self.save('occluder.png',p)}
        ev={'version':'interior-machine-mask-association/1','targetId':'occluder_1','role':'source-foreground','inputSha256':self.request['source']['sha256'],
            'maskSha256':member['image']['sha256'],'generationOrigin':'model_segmentation','selectionOrigin':'machine','assessmentSha256':self.request['assessment']['sha256'],'providerReceiptSha256':'8'*64}
        member['evidence']=self.save('member-evidence.json',ev)
        self.update_json(record['evidence'],lambda e:e.update(maskSha256=record['image']['sha256'],assessmentSha256=self.request['assessment']['sha256'],generationOrigin='machine_union',members=[member]))
        for r in self.request['targets']:
            for k in ('sourceMask','candidateMask'):self.update_json(r[k]['evidence'],lambda e:e.update(assessmentSha256=self.request['assessment']['sha256']))
        inputs={};a=worker.read_json(Path(self.request['assessment']['path']))
        actual=worker.foreground(record,self.request['source']['sha256'],self.request['assessment']['sha256'],a,self.root,inputs)
        self.assertTrue(np.array_equal(actual,p))
        # A claimed union cannot omit a real member pixel or add an unassociated one.
        altered=p.copy();altered[790,590]=0;record['image']=self.save('protected.png',altered)
        self.update_json(record['evidence'],lambda e:e.update(maskSha256=record['image']['sha256']))
        with self.assertRaisesRegex(ValueError,'FOREGROUND_UNION_MISMATCH'):worker.foreground(record,self.request['source']['sha256'],self.request['assessment']['sha256'],a,self.root,{})


if __name__=='__main__':unittest.main()
