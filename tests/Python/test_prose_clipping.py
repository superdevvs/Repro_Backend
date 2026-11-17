"""Over-long model prose is clipped to its bound instead of discarding a (possibly already paid) answer.

Synthetic answers only, shaped after the production failures of 17 Nov (declutter $.target.description 131/162 > 120,
lifestyle $.optics.notes 507..784 > 500). Every non-prose bound stays strict. No provider dispatch.
"""
import copy
import hashlib
import json
from pathlib import Path
import sys
import unittest

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'resources/iguide-lab'))
sys.path.insert(0,str(Path(__file__).resolve().parent))
import staging_assessment_protocol as shared
import staging_assessment_protocol as staging
import grass_assessment_protocol as grass
import grass_source_admission_protocol as admission
import declutter_removal_protocol as declutter
import lifestyle_assessment_protocol as lifestyle
from test_staging_assessment_bridge import fixture as staging_fixture
from test_declutter_removal_protocol import fixture as declutter_fixture
from test_lifestyle_assessment_protocol import fixture as lifestyle_fixture
from test_grass_source_admission_protocol import fixture as admission_fixture, request_detail

ELLIPSIS='…'
SENTENCE='The tall mirror beside the window may reflect the adults, so the reflection must be checked closely. '


def prose(length):
    text=(SENTENCE*(length//len(SENTENCE)+2))[:length]
    return text[:-1]+'x' if text.endswith(' ') else text


def run(module,context,proposal):
    raw=json.dumps({'id':'resp_synthetic','model':'gpt-6-astra','status':'completed','output':[{'type':'message','role':'assistant',
        'status':'completed','content':[{'type':'output_text','text':json.dumps(proposal)}]}]})
    execution={'state':'completed','requestId':'07c734c6-d827-414d-92de-329d661eb656','providerResponseId':'resp_synthetic','model':'gpt-6-astra',
        'contextSha256':module.validate_context(copy.deepcopy(context)),'requestPayloadSha256':'b'*64,'responseSha256':hashlib.sha256(raw.encode()).hexdigest()}
    return module.parse_response(raw,context,execution,'gpt-6-astra')


def at(value,path):
    for part in path[2:].replace('[','.[').split('.'):
        value=value[int(part[1:-1])] if part.startswith('[') else value[part]
    return value


def put(value,path,new):
    parts=path[2:].replace('[','.[').split('.');*head,last=parts
    for part in head:value=value[int(part[1:-1])] if part.startswith('[') else value[part]
    if last.startswith('['):value[int(last[1:-1])]=new
    else:value[last]=new


class ClipTextTests(unittest.TestCase):
    def test_short_text_is_unchanged(self):
        for text in ['a','Within bound.','x'*120]:self.assertEqual(shared.clip_prose(text,120),text)

    def test_cut_at_a_word_boundary_with_ellipsis_within_the_limit(self):
        text='Small decorative ceramic vase with dried stems on the kitchen island, beside the fruit bowl and the cutting board near the sink'
        clipped=shared.clip_prose(text,120)
        self.assertLessEqual(len(clipped),120);self.assertTrue(clipped.endswith(ELLIPSIS))
        self.assertTrue(text.startswith(clipped[:-1]));self.assertTrue(text[len(clipped)-1] in ' ,')
        self.assertEqual(clipped,'Small decorative ceramic vase with dried stems on the kitchen island, beside the fruit bowl and the cutting board near…')

    def test_trailing_separators_are_dropped_and_unbroken_text_is_hard_cut(self):
        self.assertEqual(shared.clip_prose('alpha beta, gamma delta',13),'alpha beta…')
        self.assertEqual(shared.clip_prose('n'*900,700),'n'*699+ELLIPSIS)
        self.assertEqual(shared.clip_prose('paving '+'x'*80,60),'paving '+'x'*52+ELLIPSIS)

    def test_length_is_counted_in_code_points_like_php_mb_strlen(self):
        clipped=shared.clip_prose('é'*30+' '+'ü'*30,40)
        self.assertEqual(len(clipped),31);self.assertEqual(clipped,'é'*30+ELLIPSIS)


class ProtocolClipMixin:
    module=None
    prose_paths=()      # (path, maxLength) of free text that must be clipped
    strict_cases=()     # (path, over-long value, expected error) that must still fail

    def setUp(self):
        # Fixtures share module lists (e.g. evidence ID lists); never mutate those in place.
        context,proposal=self.build();self.context=copy.deepcopy(context);self.proposal=copy.deepcopy(proposal)

    def parse(self,proposal=None):
        return run(self.module,self.context,self.proposal if proposal is None else proposal)

    def test_answer_within_bounds_is_unchanged_and_records_no_clip(self):
        original=copy.deepcopy(self.proposal);report=self.parse()
        self.assertEqual(report['proposal'],original);self.assertNotIn('proseClipped',report)

    def test_overlong_prose_by_a_little_and_a_lot_is_clipped_and_recorded(self):
        baseline=self.parse()
        for path,maximum in self.prose_paths:
            for extra in [3,maximum+217]:
                with self.subTest(path=path,length=maximum+extra):
                    self.setUp();text=prose(maximum+extra);put(self.proposal,path,text)
                    report=self.parse()
                    self.assertEqual(report['status'],baseline['status'])
                    self.assertEqual(report['reviewReasons'],baseline['reviewReasons'])
                    value=at(report['proposal'],path)
                    self.assertLessEqual(len(value),maximum);self.assertTrue(value.endswith(ELLIPSIS))
                    self.assertTrue(text.startswith(value[:-1]))
                    self.assertEqual(report['proseClipped'],[{'path':path,'length':maximum+extra,'maxLength':maximum}])
                    self.assertEqual(report['proposalSha256'],shared.digest(report['proposal']))

    def test_every_other_bound_stays_strict(self):
        for path,value,error in self.strict_cases:
            with self.subTest(path=path):
                self.setUp();put(self.proposal,path,value)
                with self.assertRaisesRegex(ValueError,error):self.parse()

    def test_empty_prose_and_unknown_keys_still_fail(self):
        path=self.prose_paths[0][0];put(self.proposal,path,'')
        with self.assertRaisesRegex(ValueError,'STRING_BOUND'):self.parse()
        self.setUp();self.proposal['humanReviewed']=prose(900)
        with self.assertRaisesRegex(ValueError,'FIELDS_INVALID'):self.parse()


class DeclutterClipTests(ProtocolClipMixin,unittest.TestCase):
    module=declutter
    prose_paths=(('$.target.description',120),('$.optics.notes',600),('$.receiver.notes',600),('$.uncertainties[0]',600))
    strict_cases=(('$.protectedFeatures[0].id','f'*81,'STRING_BOUND'),('$.generationPrompt','Remove only the object. '*300,'STRING_BOUND'),
        ('$.target.purpose','decorative_only_mostly','ENUM_INVALID'),('$.contextSha256','a'*65,'STRING_BOUND'),
        ('$.binding.captureId','0'*37,'STRING_BOUND'),('$.target.evidenceIds[0]','s'*41,'STRING_BOUND'),
        ('$.uncertainties',['Unresolved.']*13,'ARRAY_BOUND'),('$.target.box.xMax',1.2,'NUMBER_BOUND'))
    def build(self):
        context,_,proposal=declutter_fixture()
        proposal['protectedFeatures']=[{'id':'window_frame','box':{'xMin':.7,'yMin':.1,'xMax':.9,'yMax':.3},'evidenceIds':['source_cut'],'notes':'Kept.'}]
        proposal['uncertainties']=['Receiver texture under the object is unobserved.']
        return context,proposal

    def test_real_failure_shapes_now_parse(self):
        for length in [131,162]:
            with self.subTest(length=length):
                self.setUp();self.proposal['target']['description']=prose(length)
                report=self.parse();self.assertIn(report['status'],['ready_for_segmentation','needs_review'])
                self.assertLessEqual(len(report['proposal']['target']['description']),120)


class LifestyleClipTests(ProtocolClipMixin,unittest.TestCase):
    module=lifestyle
    prose_paths=(('$.optics.notes',500),('$.summary',700),('$.people[1].shoes[0].notes',400),('$.floorSelection.notes',400),('$.uncertainties[0]',300))
    strict_cases=(('$.binding.captureId','c'*81,'STRING_BOUND'),('$.people[0].id','adult_1_tall','ENUM_INVALID'),
        ('$.optics.evidenceIds[0]','source_cut_extra','ENUM_INVALID'),('$.uncertainties',['u']*9,'ARRAY_BOUND'),
        ('$.people[0].box.yMax',1.5,'NUMBER_BOUND'))
    def build(self):
        context,_,proposal=lifestyle_fixture();proposal['uncertainties']=['A glossy floor may show faint reflections.']
        return context,proposal

    def test_real_failure_shapes_now_parse(self):
        for length in [507,612,784]:
            with self.subTest(length=length):
                self.setUp();self.proposal['optics']['notes']=prose(length)
                report=self.parse();self.assertIn(report['status'],['ready_for_segmentation','needs_review'])
                self.assertLessEqual(len(report['proposal']['optics']['notes']),500)


class StagingClipTests(ProtocolClipMixin,unittest.TestCase):
    module=staging
    prose_paths=(('$.room.notes',400),('$.objects[2].notes',400),('$.optics.surfaces[0].notes',400),('$.summary',700),
        ('$.contextCoverage.requestedDetails[0]',200),('$.uncertainties[0]',300))
    strict_cases=(('$.optics.surfaces[0].id','m'*41,'STRING_BOUND'),('$.objects[0].evidenceIds[0]','e'*41,'STRING_BOUND'),
        ('$.binding.captureId','c'*81,'STRING_BOUND'),('$.room.classification','empty','ENUM_INVALID'),
        ('$.uncertainties',['u']*9,'ARRAY_BOUND'))
    def build(self):
        context,_,proposal=staging_fixture()
        proposal['optics']['surfaces']=[{'id':'hall_mirror','kind':'mirror','dependency':'possible','evidenceIds':['parent_overview'],'notes':'Possible.'}]
        proposal['contextCoverage']['requestedDetails']=['Closer view of the hall mirror.'];proposal['uncertainties']=['Mirror content unclear.']
        return context,proposal


class GrassClipTests(ProtocolClipMixin,unittest.TestCase):
    module=grass
    prose_paths=(('$.material.notes',700),('$.summary',700),('$.exclusions.plants.notes',700),('$.uncertainties[0]',500),
        ('$.contextCoverage.requestedDetails[0]',300))
    strict_cases=(('$.contextSha256',None,'TYPE_INVALID'),('$.binding.captureId','c'*37,'STRING_BOUND'),
        ('$.lawn.evidenceIds[0]','s'*41,'STRING_BOUND'),('$.material.status','lush','ENUM_INVALID'))
    def build(self):
        fixtures=ROOT/'tests/Fixtures/iguide-lab/grass-integration'
        context=json.loads((fixtures/'context.json').read_text())
        envelope=json.loads((fixtures/'response.SYNTHETIC.json').read_text())
        proposal=json.loads(next(v for v in envelope['output'] if v['type']=='message')['content'][0]['text'])
        proposal['uncertainties']=['Mixed turf at the fence.'];proposal['contextCoverage']['requestedDetails']=['Fence line detail.']
        return context,proposal

    def test_overlong_context_hash_is_no_longer_truncated_into_a_match(self):
        self.proposal['contextSha256']=self.proposal['contextSha256']+'0'
        with self.assertRaisesRegex(ValueError,'STRING_BOUND'):self.parse()

    def test_descriptive_selection_labels_remain_clipped_as_before(self):
        self.proposal['exclusions']['paving']['selections'][0]['id']='paving '+'x'*80
        report=self.parse();label=report['proposal']['exclusions']['paving']['selections'][0]['id']
        self.assertEqual(len(label),60);self.assertTrue(label.endswith(ELLIPSIS))
        self.assertEqual(report['proseClipped'],[{'path':'$.exclusions.paving.selections[0].id','length':87,'maxLength':60}])


class AdmissionClipTests(ProtocolClipMixin,unittest.TestCase):
    module=admission
    prose_paths=(('$.summary',700),('$.material.notes',700),('$.contextCoverage.detailRequests[0].purpose',400),('$.uncertainties[0]',500))
    strict_cases=(('$.binding.captureId','c'*37,'STRING_BOUND'),('$.contextCoverage.detailRequests[0].evidenceIds[0]','s'*41,'STRING_BOUND'),
        ('$.contextCoverage.detailRequests[0].pose.hFov',90,'NUMBER_BOUND'),('$.uncertainties',['u']*9,'ARRAY_BOUND'))
    def build(self):
        context,proposal=admission_fixture();request_detail(proposal);return context,proposal

    def test_clipped_round_zero_answer_is_a_valid_prior_for_round_one(self):
        from test_grass_source_admission_protocol import next_context
        self.proposal['contextCoverage']['detailRequests'][0]['purpose']=prose(530)
        report=self.parse();self.assertEqual(len(report['proseClipped']),1)
        follow=next_context(self.context,report['proposal'])
        admission.validate_context(follow)


class WireSchemaTests(unittest.TestCase):
    def test_request_schemas_are_unchanged_by_the_parser_side_clipping(self):
        for schema in [staging.response_schema(True),grass.response_schema(True),admission.response_schema(True),
                declutter.response_schema(True),lifestyle.response_schema(True)]:
            self.assertNotIn('maxLength',json.dumps(schema))


if __name__=='__main__':
    unittest.main()
