"""Producer/consumer regressions; synthetic fixtures, no provider dispatch."""
import copy
import hashlib
import json
from pathlib import Path
import sys
import unittest

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'resources/iguide-lab'))
import grass_assessment_protocol as protocol


class GrassAssessmentContractTests(unittest.TestCase):
    def setUp(self):
        self.fixtures=ROOT/'tests/Fixtures/iguide-lab/grass-integration'
        self.context=json.loads((self.fixtures/'context.json').read_text())
        self.envelope=json.loads((self.fixtures/'response.SYNTHETIC.json').read_text())
        self.message=next(v for v in self.envelope['output'] if v['type']=='message')
        self.proposal=json.loads(self.message['content'][0]['text'])
        self.execution=json.loads((self.fixtures/'execution.SYNTHETIC.json').read_text())

    def parse(self):
        self.message['content'][0]['text']=json.dumps(self.proposal)
        raw=json.dumps(self.envelope)
        self.execution['responseSha256']=hashlib.sha256(raw.encode()).hexdigest()
        return protocol.parse_response(raw,self.context,self.execution)

    def descriptive_labels(self):
        for role in ['lawn',*protocol.CLASSES]:
            finding=self.proposal['lawn'] if role=='lawn' else self.proposal['exclusions'][role]
            for i,selection in enumerate(finding['selections']):selection['id']=f'visible {role} region {i+1}'

    def test_descriptive_ids_stay_in_proposal_but_machine_ids_are_server_derived(self):
        self.descriptive_labels(); original=copy.deepcopy(self.proposal)
        report=self.parse()
        self.assertEqual(report['status'],'ready_for_source_segmentation')
        self.assertEqual(report['proposal'],original)
        self.assertEqual(report['proposalSha256'],protocol.digest(original))
        reservations=json.loads((self.fixtures/'reservations.mock-database.json').read_text())
        plans=protocol.bind_reserved_plans(report,reservations)
        for plan in plans:
            role=plan['maskRole']
            self.assertEqual(plan['expectedIdentities'],[f'{role}_{i+1}' for i in range(plan['payload']['max_masks'])])
            receipt=json.loads((self.fixtures/'receipts'/f'{role}.json').read_text())
            receipt['inputFingerprint']=plan['inputFingerprint']
            associated=protocol.bind_segmentation_receipt(plan,receipt)
            self.assertEqual(associated['status'],'ready_for_source_support')
            self.assertEqual([x['identity'] for x in associated['associations']],plan['expectedIdentities'])

    def test_retained_response_shape_keeps_seven_semantic_holds_and_no_sam(self):
        self.descriptive_labels()
        self.proposal['material']['status']='sparse_or_missing_turf'
        self.proposal['contextCoverage']['status']='extra_context_required'
        self.proposal['contextCoverage']['requestedDetails']=['Inspect source mixed turf boundary.']
        for role,status in [('plants','uncertain'),('loose_debris','too_fine_or_dense'),('non_turf_ground','uncertain'),('capture_artifact','uncertain')]:
            self.proposal['exclusions'][role]['status']=status
        self.proposal['uncertainties']=['Sparse turf and overlapping materials cannot be separated.']
        original=copy.deepcopy(self.proposal); report=self.parse()
        self.assertEqual(report['status'],'needs_review')
        self.assertEqual(report['reviewReasons'],['TEXTURE_OR_MATERIAL_REQUIRES_REVIEW','EXTRA_SOURCE_CONTEXT_REQUIRED',
            'SOURCE_ASSESSMENT_UNCERTAINTY','EXCLUSION_UNRESOLVED:plants','EXCLUSION_UNRESOLVED:loose_debris',
            'EXCLUSION_UNRESOLVED:non_turf_ground','EXCLUSION_UNRESOLVED:capture_artifact'])
        self.assertEqual(report['proposal'],original)
        self.assertEqual(report['segmentationPlan'],[])
        with self.assertRaisesRegex(ValueError,'NOT_ELIGIBLE'):protocol.segmentation_plan(report)
        with self.assertRaisesRegex(ValueError,'NOT_ELIGIBLE'):protocol.bind_reserved_plans(report,{})

    def test_each_uncertain_role_with_valid_partial_boxes_holds_independently(self):
        for role in ['lawn',*protocol.CLASSES]:
            for state in (['uncertain','too_fragmented'] if role=='lawn' else ['uncertain','too_fine_or_dense']):
                with self.subTest(role=role,status=state):
                    self.setUp()
                    finding=self.proposal['lawn'] if role=='lawn' else self.proposal['exclusions'][role]
                    finding['status']=state
                    self.assertTrue(finding['selections'])
                    report=self.parse()
                    self.assertEqual(report['status'],'needs_review');self.assertEqual(report['segmentationPlan'],[])
                    finding['selections']=[]
                    self.assertEqual(self.parse()['status'],'needs_review')

    def test_selectable_empty_and_absent_nonempty_findings_remain_invalid(self):
        self.proposal['lawn']['selections']=[]
        with self.assertRaisesRegex(ValueError,'STATUS_MISMATCH'):self.parse()
        self.setUp();self.proposal['exclusions']['plants']['status']='none_observed'
        self.proposal['exclusions']['plants']['evidenceIds']=protocol.SOURCE_IDS
        with self.assertRaisesRegex(ValueError,'STATUS_MISMATCH'):self.parse()

    def test_duplicate_blank_and_overlong_labels_remain_invalid(self):
        for label in [' ', 'x'*61, self.proposal['lawn']['selections'][0]['id']]:
            with self.subTest(label=label):
                self.proposal['lawn']['selections'][1]['id']=label
                with self.assertRaises(ValueError):self.parse()

    def test_uncertain_selection_still_requires_valid_box_and_source_evidence(self):
        self.proposal['exclusions']['plants']['status']='uncertain'
        self.proposal['exclusions']['plants']['selections'][0]['box']['xMax']=0
        with self.assertRaisesRegex(ValueError,'BOX_DEGENERATE'):self.parse()
        self.setUp();self.proposal['exclusions']['plants']['status']='uncertain'
        self.proposal['exclusions']['plants']['evidenceIds']=['source_panorama','candidate_panorama']
        with self.assertRaisesRegex(ValueError,'USES_CANDIDATE'):self.parse()

    def test_wire_schema_and_instructions_explain_label_and_hold_semantics(self):
        schema=protocol.response_schema(True)
        for finding in [schema['properties']['lawn'],*schema['properties']['exclusions']['properties'].values()]:
            selections=finding['properties']['selections']
            self.assertIn('diagnostic boxes',selections['description'])
            self.assertIn('server derives',selections['items']['properties']['id']['description'])
        self.assertIn('all such findings stop segmentation',protocol.INSTRUCTIONS)
        self.descriptive_labels()
        protocol.shared._validate(self.proposal,schema)


if __name__=='__main__':unittest.main()
