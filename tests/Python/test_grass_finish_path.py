"""Grass finish path: soft doubts are caveats, one text-only lawn mask, honest chroma clamp.

Synthetic fixtures plus the stored paid answer 063278ee (held in production with 7 soft doubts).
No provider dispatch.
"""
import copy
import hashlib
import json
from pathlib import Path
import sys
import unittest

import numpy as np

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'resources/iguide-lab'))
import grass_assessment_protocol as protocol
import grass_chroma as chroma

HELD=ROOT/'tests/Fixtures/iguide-lab/grass-held-063278ee'
SYNTHETIC=ROOT/'tests/Fixtures/iguide-lab/grass-integration'


def reservations(plans):
    return {plan['stepKey']:'00000000-0000-4000-8000-%012d'%(i+1) for i,plan in enumerate(plans)}


class StoredHeldAnswerTests(unittest.TestCase):
    def parse_held(self):
        context=json.loads((HELD/'context.json').read_text())
        execution=json.loads((HELD/'execution.json').read_text())
        return protocol.parse_response((HELD/'response-0.json').read_bytes().decode('utf-8'),context,execution)

    def test_stored_held_answer_now_reaches_segmentation_with_its_doubts_as_caveats(self):
        report=self.parse_held()
        self.assertEqual(report['status'],'ready_for_source_segmentation')
        self.assertEqual(report['reviewReasons'],[])
        self.assertEqual(report['caveats'],['TEXTURE_OR_MATERIAL_REQUIRES_REVIEW','EXTRA_SOURCE_CONTEXT_REQUIRED',
            'SOURCE_ASSESSMENT_UNCERTAINTY','EXCLUSION_UNRESOLVED:plants','EXCLUSION_UNRESOLVED:loose_debris',
            'EXCLUSION_UNRESOLVED:non_turf_ground','EXCLUSION_UNRESOLVED:capture_artifact'])
        plans=report['segmentationPlan']
        self.assertEqual([plan['maskRole'] for plan in plans],['lawn','paving'])
        lawn=plans[0]
        self.assertEqual(lawn['payload']['box_prompts'],[])
        self.assertEqual(lawn['payload']['max_masks'],1)
        self.assertEqual(lawn['payload']['prompt'],'grass lawn')
        self.assertEqual(lawn['expectedIdentities'],['lawn_1'])
        self.assertEqual(len(plans[1]['payload']['box_prompts']),6)
        bound=protocol.bind_reserved_plans(report,reservations(plans))
        self.assertEqual([x['reservationState'] for x in bound],['server_reserved']*2)


class SyntheticAssessmentTests(unittest.TestCase):
    def setUp(self):
        self.context=json.loads((SYNTHETIC/'context.json').read_text())
        self.envelope=json.loads((SYNTHETIC/'response.SYNTHETIC.json').read_text())
        self.message=next(v for v in self.envelope['output'] if v['type']=='message')
        self.proposal=json.loads(self.message['content'][0]['text'])
        self.execution=json.loads((SYNTHETIC/'execution.SYNTHETIC.json').read_text())

    def parse(self):
        self.message['content'][0]['text']=json.dumps(self.proposal)
        raw=json.dumps(self.envelope)
        self.execution['responseSha256']=hashlib.sha256(raw.encode()).hexdigest()
        return protocol.parse_response(raw,self.context,self.execution)

    def test_no_lawn_observed_is_still_a_hard_stop(self):
        self.proposal['lawn']['status']='none_observed';self.proposal['lawn']['selections']=[]
        report=self.parse()
        self.assertEqual(report['status'],'needs_review')
        self.assertEqual(report['reviewReasons'],['NO_SOURCE_LAWN_OBSERVED'])
        self.assertEqual(report['segmentationPlan'],[])

    def test_bare_soil_material_and_parcel_subset_are_still_hard_stops(self):
        self.proposal['material']['status']='bare_soil'
        self.assertEqual(self.parse()['reviewReasons'],['NO_SOURCE_TURF_TO_RECOLOR'])
        self.setUp();self.proposal['scope']['status']='parcel_subset_requested'
        self.assertEqual(self.parse()['reviewReasons'],['PARCEL_OR_EDIT_SCOPE_UNRESOLVED'])

    def test_dormant_and_patchy_turf_are_accepted_materials_with_a_caveat(self):
        for material in ['established_dormant_turf','patchy_turf']:
            with self.subTest(material=material):
                self.setUp();self.proposal['material']['status']=material
                report=self.parse()
                self.assertEqual(report['status'],'ready_for_source_segmentation')
                self.assertIn('TEXTURE_OR_MATERIAL_REQUIRES_REVIEW',report['caveats'])
        enum=protocol.response_schema(True)['properties']['material']['properties']['status']['enum']
        self.assertIn('established_dormant_turf',enum);self.assertIn('patchy_turf',enum)

    def test_uncertain_lawn_still_gets_one_text_only_mask(self):
        for state in ['uncertain','too_fragmented']:
            with self.subTest(state=state):
                self.setUp();self.proposal['lawn']['status']=state
                report=self.parse()
                self.assertEqual(report['status'],'ready_for_source_segmentation')
                self.assertIn('SOURCE_LAWN_SELECTION_UNRESOLVED',report['caveats'])
                self.assertEqual(report['segmentationPlan'][0]['expectedIdentities'],['lawn_1'])

    def test_doubtful_exclusions_are_skipped_as_caveats_not_holds(self):
        self.proposal['exclusions']['plants']['status']='uncertain'
        report=self.parse()
        self.assertEqual(report['status'],'ready_for_source_segmentation')
        self.assertIn('EXCLUSION_UNRESOLVED:plants',report['caveats'])
        self.assertNotIn('plants',[plan['maskRole'] for plan in report['segmentationPlan']])

    def test_overlong_text_is_truncated_not_fatal(self):
        self.proposal['material']['notes']='n'*900
        self.proposal['summary']='s'*800
        self.proposal['uncertainties']=['u'*650]*10
        self.proposal['contextCoverage']['requestedDetails']=['d'*400]*4
        self.proposal['exclusions']['paving']['selections'][0]['id']='paving '+'x'*80
        report=self.parse()
        self.assertEqual(report['status'],'ready_for_source_segmentation')
        proposal=report['proposal']
        self.assertEqual(len(proposal['material']['notes']),700)
        self.assertEqual(len(proposal['summary']),700)
        self.assertEqual(len(proposal['uncertainties']),8)
        self.assertTrue(all(len(x)==500 for x in proposal['uncertainties']))
        self.assertEqual(len(proposal['contextCoverage']['requestedDetails']),3)
        self.assertEqual(len(proposal['exclusions']['paving']['selections'][0]['id']),60)
        self.assertEqual(report['proposalSha256'],protocol.digest(proposal))

    def test_blank_and_duplicate_labels_remain_invalid(self):
        for label in [' ',self.proposal['exclusions']['paving']['selections'][0]['id']]:
            with self.subTest(label=label):
                self.setUp()
                self.proposal['exclusions']['paving']['selections'].append(copy.deepcopy(self.proposal['exclusions']['paving']['selections'][0]))
                self.proposal['exclusions']['paving']['selections'][1]['id']=label
                with self.assertRaises(ValueError):self.parse()

    def test_request_uses_medium_effort_and_a_larger_output_cap(self):
        names={'source_panorama':'source.png','candidate_panorama':'candidate.png'}
        images=[(SYNTHETIC/'images'/names.get(view['id'],view['id']+'.png')).read_bytes() for view in self.context['evidence']]
        request=protocol.compile_request(self.context,images)
        self.assertEqual(request['reasoning'],{'effort':'medium'})
        self.assertEqual(request['max_output_tokens'],24000)

    def lawn_plan(self):
        report=self.parse();plans=protocol.bind_reserved_plans(report,reservations(report['segmentationPlan']))
        return report,plans

    def receipt(self,plan,masks):
        return {'requestId':plan['requestId'],'providerRequestId':'SYNTHETIC_sam_text','model':protocol.SAM_MODEL,
            'inputFingerprint':plan['inputFingerprint'],'inputSha256':plan['inputSha256'],'rawResponseSha256':'a'*64,
            'status':'completed','masks':masks}

    def mask(self,seed='1',bounds=(2,565,2045,900),pixels=400000):
        return {'sha256':hashlib.sha256(seed.encode()).hexdigest(),'width':2048,'height':1024,'bounds':list(bounds),'positivePixels':pixels}

    def test_text_only_lawn_receipt_associates_its_single_mask(self):
        _,plans=self.lawn_plan();lawn=plans[0]
        result=protocol.bind_segmentation_receipt(lawn,self.receipt(lawn,[self.mask()]))
        self.assertEqual(result['status'],'ready_for_source_support')
        self.assertEqual([x['identity'] for x in result['associations']],['lawn_1'])
        self.assertEqual(result['associations'][0]['maskIndex'],0)
        for masks in [[],[self.mask('1'),self.mask('2')]]:
            self.assertEqual(protocol.bind_segmentation_receipt(lawn,self.receipt(lawn,masks))['status'],'needs_review')

    def test_ambiguous_exclusion_masks_protect_in_order_instead_of_holding(self):
        self.proposal['exclusions']['paving']['selections']=[
            {'id':'paving a','box':{'xMin':.4,'yMin':.5,'xMax':.6,'yMax':.9}},
            {'id':'paving b','box':{'xMin':.4,'yMin':.5,'xMax':.6,'yMax':.9}}]
        _,plans=self.lawn_plan();paving=next(p for p in plans if p['maskRole']=='paving')
        bounds=(820,512,1228,921)
        result=protocol.bind_segmentation_receipt(paving,self.receipt(paving,[self.mask('a',bounds,1000),self.mask('b',bounds,2000)]))
        self.assertEqual(result['status'],'ready_for_source_support')
        self.assertEqual([x['maskIndex'] for x in result['associations']],[0,1])
        self.assertEqual(result['associationCaveats'],['ROLE_MASK_ASSOCIATION_AMBIGUOUS_PROTECTED_IN_ORDER'])
        report,plans=self.lawn_plan();bindings=[]
        for plan in plans:
            masks=[self.mask('lawn')] if plan['maskRole']=='lawn' else [self.mask(plan['maskRole']+str(i),bounds if plan['maskRole']=='paving' else
                (b['x_min'],b['y_min'],b['x_max'],b['y_max']),10) for i,b in enumerate(plan['payload']['box_prompts'])]
            bindings.append(protocol.bind_segmentation_receipt(plan,self.receipt(plan,masks)))
        caveats=protocol.composition_assessment_record(report,plans,bindings)['caveats']
        self.assertEqual(caveats.count('ROLE_MASK_ASSOCIATION_AMBIGUOUS_PROTECTED_IN_ORDER:paving'),1)

    def test_composition_record_carries_material_unsegmented_exclusions_and_caveats(self):
        self.proposal['material']['status']='patchy_turf'
        self.proposal['exclusions']['plants']['status']='uncertain'
        report,plans=self.lawn_plan()
        bindings=[]
        for plan in plans:
            masks=[self.mask(plan['maskRole'])] if plan['maskRole']=='lawn' else [
                self.mask(plan['maskRole']+str(i),(b['x_min'],b['y_min'],b['x_max'],b['y_max']),10) for i,b in enumerate(plan['payload']['box_prompts'])]
            bindings.append(protocol.bind_segmentation_receipt(plan,self.receipt(plan,masks)))
        self.assertEqual({x['status'] for x in bindings},{'ready_for_source_support'})
        record=protocol.composition_assessment_record(report,plans,bindings)
        self.assertEqual(record['lawnMaterial'],'patchy_turf')
        self.assertEqual(record['exclusions']['plants'],'unsegmented')
        self.assertEqual(record['exclusions']['paving'],'supplied')
        self.assertEqual(record['caveats'],report['caveats'])
        self.assertEqual(record['uncertainties'],[])


    def test_a_failed_exclusion_mask_becomes_an_unsegmented_caveat_but_the_lawn_is_required(self):
        report,plans=self.lawn_plan()
        def ready(plan):
            masks=[self.mask(plan['maskRole'])] if plan['maskRole']=='lawn' else [
                self.mask(plan['maskRole']+str(i),(b['x_min'],b['y_min'],b['x_max'],b['y_max']),10) for i,b in enumerate(plan['payload']['box_prompts'])]
            return protocol.bind_segmentation_receipt(plan,self.receipt(plan,masks))
        skipped=['source-grass-paving']
        bindings=[ready(plan) for plan in plans if plan['stepKey'] not in skipped]
        record=protocol.composition_assessment_record(report,plans,bindings,skipped)
        self.assertEqual(record['exclusions']['paving'],'unsegmented')
        self.assertIn('EXCLUSION_MASK_UNAVAILABLE:paving',record['caveats'])
        with self.assertRaisesRegex(ValueError,'LAWN_MASK_REQUIRED'):
            protocol.composition_assessment_record(report,plans,[ready(plan) for plan in plans[1:]],['source-grass-lawn'])
        with self.assertRaisesRegex(ValueError,'PREREQUISITES_NOT_BOUND'):
            protocol.composition_assessment_record(report,plans,bindings,[])


class ChromaFinishTests(unittest.TestCase):
    def scene(self,candidate_shift):
        h,w=64,128
        source=np.zeros((h,w,3),np.uint8);source[...]=(120,110,70)
        candidate=source.copy();candidate[...]=np.clip(np.array((120,110,70))+np.array(candidate_shift),0,255)
        lawn=np.zeros((h,w),np.uint8);lawn[40:60,10:118]=255
        return source,source.copy(),candidate,[lawn]

    def assessment(self,**changes):
        value={'authorOrigin':'machine','maskSelectionOrigin':'machine','lawnMaterial':'established_turf',
            'requestedChange':'recolor_existing_turf','scope':'all_visible_established_turf',
            'exclusions':{name:'none_observed' for name in chroma.CLASSES},'uncertainties':[],'caveats':[]}
        value.update(changes);return value

    def empty(self):return {name:[] for name in chroma.CLASSES}

    def test_large_chroma_guidance_is_clamped_not_stopped(self):
        source,guide,candidate,lawn=self.scene((-120,140,-60))
        result=chroma.compose(source,guide,candidate,lawn,self.empty(),self.assessment())
        report=result['report']
        self.assertEqual(report['status'],'completed_unreviewed')
        self.assertIn('CHROMA_GUIDANCE_CLAMPED',report['reviewFlags'])
        self.assertAlmostEqual(float(np.linalg.norm(report['chromaGuidance']['appliedDeltaAB'])),40.,places=3)
        self.assertGreater(np.linalg.norm(report['chromaGuidance']['deltaAB']),40)

    def test_too_few_lawn_samples_remains_a_real_stop(self):
        source,guide,candidate,lawn=self.scene((-20,30,-10))
        lawn[0][:]=0;lawn[0][50:52,60:62]=255
        report=chroma.compose(source,guide,candidate,lawn,self.empty(),self.assessment())['report']
        self.assertEqual(report['status'],'needs_review')
        self.assertIn('INSUFFICIENT_SAFE_CHROMA_SAMPLES',report['semanticBlockers'])

    def test_unsegmented_exclusions_and_turf_variants_compose_but_bare_soil_does_not(self):
        source,guide,candidate,lawn=self.scene((-20,30,-10))
        exclusions={name:'none_observed' for name in chroma.CLASSES};exclusions['plants']='unsegmented'
        for material in ['established_dormant_turf','patchy_turf','sparse_or_missing_turf','uncertain']:
            with self.subTest(material=material):
                report=chroma.compose(source,guide,candidate,lawn,self.empty(),self.assessment(lawnMaterial=material,exclusions=exclusions))['report']
                self.assertEqual(report['status'],'completed_unreviewed')
        report=chroma.compose(source,guide,candidate,lawn,self.empty(),self.assessment(lawnMaterial='bare_soil'))['report']
        self.assertIn('MATERIAL_OR_TEXTURE_REPLACEMENT_REQUIRES_REVIEW',report['semanticBlockers'])
        exclusions['plants']='unsegmented'
        report=chroma.compose(source,guide,candidate,lawn,{**self.empty(),'plants':[lawn[0]]},self.assessment(exclusions=exclusions))['report']
        self.assertIn('EXCLUSION_EVIDENCE_UNRESOLVED:plants',report['semanticBlockers'])


if __name__=='__main__':unittest.main()
