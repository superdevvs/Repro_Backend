"""Deploy 2.1 grass: no-lawn photos stop before the image; a paid image always ends viewable.

Synthetic fixtures plus the stored real source-admission answer for item 8a63ae (held before any image).
No provider dispatch.
"""
import json
from pathlib import Path
import sys
import unittest

import numpy as np

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'resources/iguide-lab'))
sys.path.insert(0,str(ROOT/'tests/Python'))
import grass_source_admission_protocol as admission
import grass_chroma as chroma
import grass_assessment_protocol as protocol
from test_grass_source_admission_protocol import fixture,parse,request_detail
import test_grass_finish_path as finish

HELD_ADMISSION=ROOT/'tests/Fixtures/iguide-lab/grass-held-8a63ae/admission-proposal.json'


class AdmissionStopsOnlyHardBlockersTests(unittest.TestCase):
    def test_no_visible_lawn_bare_soil_and_parcel_subset_stop_before_the_image(self):
        for change in ['no_visible_lawn','bare_soil','parcel_subset_requested']:
            context,proposal=fixture()
            if change=='parcel_subset_requested':proposal['scope']['status']=change
            else:proposal['material']['status']=change
            with self.subTest(change=change):
                report=parse(context,proposal)
                self.assertEqual(report['status'],'needs_review')
                self.assertTrue(report['reviewReasons'])
                self.assertEqual(report['detailPlan'],[])

    def test_soft_doubts_are_caveats_and_admit_the_image_without_a_detail_round(self):
        context,proposal=fixture();request_detail(proposal)
        proposal['material']['status']='sparse_or_missing_turf'
        proposal['exclusions']['plants']['status']='too_fine_or_dense'
        proposal['reflections']['status']='visible_or_possible_turf_reflection'
        report=parse(context,proposal)
        self.assertEqual(report['status'],'eligible_for_image')
        self.assertEqual(report['reviewReasons'],[])
        self.assertEqual(report['detailPlan'],[])
        self.assertEqual(report['caveats'],['SOURCE_TURF_MATERIAL_UNRESOLVED','SOURCE_EXCLUSION_UNRESOLVED:plants',
            'SOURCE_REFLECTION_UNRESOLVED','SOURCE_CONTEXT_UNRESOLVED','SOURCE_ADMISSION_UNCERTAINTY'])
        self.assertFalse(report['automaticAcceptance']);self.assertFalse(report['segmentationAuthority'])

    def test_clean_source_has_no_caveats(self):
        context,proposal=fixture()
        report=parse(context,proposal)
        self.assertEqual((report['status'],report['caveats']),('eligible_for_image',[]))

    def test_instructions_name_only_the_hard_blockers(self):
        self.assertIn('no_visible_lawn',json.dumps(admission.response_schema(True)))
        self.assertIn('recorded as caveats',admission.INSTRUCTIONS)
        self.assertNotIn('must hold',admission.INSTRUCTIONS)

    def test_stored_held_item_8a63ae_is_now_admitted_with_its_doubts_as_caveats(self):
        proposal=json.loads(HELD_ADMISSION.read_text())
        status,reasons,caveats=admission.screen(proposal,0)
        self.assertEqual(status,'eligible_for_image')
        self.assertEqual(reasons,[])
        self.assertEqual(caveats,['SOURCE_TURF_MATERIAL_UNRESOLVED','SOURCE_LAWN_BOUNDARY_UNRESOLVED','SOURCE_EXCLUSION_UNRESOLVED:plants',
            'SOURCE_EXCLUSION_UNRESOLVED:loose_debris','SOURCE_EXCLUSION_UNRESOLVED:non_turf_ground','SOURCE_ADMISSION_UNCERTAINTY'])


class ChromaAlwaysCompositesTests(unittest.TestCase):
    """After the paid image: a weak, empty or misplaced lawn mask still composites, flagged for review."""
    def scene(self,shift=(-20,30,-10)):
        h,w=64,128
        source=np.zeros((h,w,3),np.uint8);source[...]=(120,110,70)
        candidate=source.copy()
        # The generator greened only the lower ground band (rows 40-60); sky and walls are unchanged.
        candidate[40:60]=np.clip(np.array((120,110,70))+np.array(shift),0,255)
        lawn=np.zeros((h,w),np.uint8);lawn[40:60,10:118]=255
        return source,source.copy(),candidate,lawn

    def assessment(self):
        return {'authorOrigin':'machine','maskSelectionOrigin':'machine','lawnMaterial':'established_turf',
            'requestedChange':'recolor_existing_turf','scope':'all_visible_established_turf',
            'exclusions':{name:'none_observed' for name in chroma.CLASSES},'uncertainties':[],'caveats':[]}

    def empty(self):return {name:[] for name in chroma.CLASSES}

    def assert_viewable(self,result,flag):
        report=result['report']
        self.assertEqual(report['status'],'completed_unreviewed')
        self.assertEqual(report['semanticBlockers'],[])
        self.assertIn(flag,report['finishReview'])
        self.assertIn('native',result);self.assertIn('effectiveAlpha',result)
        self.assertEqual(report['statistics']['outsideAlphaChangedPixels'],0)
        self.assertEqual(report['statistics']['spatialDonorPixelsCopied'],0)
        return report

    def test_clean_lawn_has_no_finish_review(self):
        source,guide,candidate,lawn=self.scene()
        report=chroma.compose(source,guide,candidate,[lawn],self.empty(),self.assessment())['report']
        self.assertEqual(report['status'],'completed_unreviewed');self.assertEqual(report['finishReview'],[])

    def test_too_few_safe_samples_uses_the_candidate_greened_ground_region(self):
        source,guide,candidate,lawn=self.scene()
        lawn[:]=0;lawn[50:52,60:62]=255
        result=chroma.compose(source,guide,candidate,[lawn],self.empty(),self.assessment())
        report=self.assert_viewable(result,'LAWN_REGION_FROM_CANDIDATE_CHANGE')
        self.assertIn('INSUFFICIENT_SAFE_CHROMA_SAMPLES',report['finishReview'])
        changed=np.any(result['native']!=source,axis=-1)
        self.assertTrue(changed[45:55].any());self.assertFalse(changed[:30].any())

    def test_missing_lawn_mask_uses_the_candidate_greened_ground_region(self):
        source,guide,candidate,_=self.scene()
        result=chroma.compose(source,guide,candidate,[],self.empty(),self.assessment())
        report=self.assert_viewable(result,'LAWN_MASK_UNAVAILABLE')
        self.assertIn('LAWN_REGION_FROM_CANDIDATE_CHANGE',report['finishReview'])

    def test_lawn_at_the_upper_pole_is_trimmed_not_discarded(self):
        source,guide,candidate,lawn=self.scene()
        lawn[0:2,:]=255
        result=chroma.compose(source,guide,candidate,[lawn],self.empty(),self.assessment())
        report=self.assert_viewable(result,'LAWN_SUPPORT_UPPER_POLE_TRIMMED')
        self.assertFalse(np.any(result['native'][:4]!=source[:4]))

    def test_protected_exclusions_stay_protected_in_the_fallback_region(self):
        source,guide,candidate,_=self.scene()
        paving=np.zeros(source.shape[:2],np.uint8);paving[40:60,0:40]=255
        exclusions={**self.empty(),'paving':[paving]};assessment=self.assessment();assessment['exclusions']['paving']='supplied'
        result=chroma.compose(source,guide,candidate,[],exclusions,assessment)
        self.assert_viewable(result,'LAWN_REGION_FROM_CANDIDATE_CHANGE')
        self.assertFalse(np.any(result['native'][40:60,0:40]!=source[40:60,0:40]))
        self.assertEqual(result['report']['statistics']['protectedMaskChangedPixels'],0)

    def test_a_candidate_that_greened_nothing_still_stops(self):
        source,guide,_,_=self.scene()
        report=chroma.compose(source,guide,source.copy(),[],self.empty(),self.assessment())['report']
        self.assertEqual(report['status'],'needs_review')
        self.assertIn('NO_GREENED_GROUND_IN_CANDIDATE',report['semanticBlockers'])


class LawnMaskMayBeUnavailableTests(unittest.TestCase):
    def test_a_failed_lawn_mask_is_a_caveat_and_composition_continues(self):
        helper=finish.SyntheticAssessmentTests('test_text_only_lawn_receipt_associates_its_single_mask');helper.setUp()
        report,plans=helper.lawn_plan()
        def ready(plan):
            masks=[helper.mask(plan['maskRole']+str(i),(b['x_min'],b['y_min'],b['x_max'],b['y_max']),10) for i,b in enumerate(plan['payload']['box_prompts'])]
            return protocol.bind_segmentation_receipt(plan,helper.receipt(plan,masks))
        record=protocol.composition_assessment_record(report,plans,[ready(plan) for plan in plans[1:]],['source-grass-lawn'])
        self.assertIn('LAWN_MASK_UNAVAILABLE',record['caveats'])


if __name__=='__main__':unittest.main()
