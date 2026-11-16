"""Finish-first declutter: only a missing target or one touching the cut edge stops before an image.

Every other doubt is kept as a recorded caveat. One mask request per item; the
source-declared local shadow is folded into the support instead of a second SAM call.
"""
import copy,sys,unittest
from pathlib import Path
import numpy as np
sys.path[:0]=[str(Path(__file__).resolve().parents[2]/'resources/iguide-lab'),str(Path(__file__).parent)]
import declutter_removal_protocol as p
import declutter_support as s
from test_declutter_removal_protocol import fixture,parsed
from test_declutter_materials_v2 import material_fixture


class FinishFirstProtocolTests(unittest.TestCase):
    def setUp(self):self.context,self.images,self.proposal=copy.deepcopy(material_fixture())
    def test_soft_doubts_become_caveats_and_still_plan_one_mask(self):
        v=self.proposal
        v['target'].update(count='multiple_or_uncertain',opacity='transparent_or_uncertain')
        v['receiver'].update(material='structured_or_uncertain',specularDependency='possible_or_uncertain',samples=[])
        v['optics']['status']='possible_dependency_or_insufficient_context';v['foreground']['status']='overlap_or_uncertain'
        v['contextCoverage']['status']='needs_more_context';v['shadow'].update(status='needs_review',box=None)
        v['uncertainties']=['A faint reflection may exist.']
        self.context['sourcePlan']['uncertainties']=['Possible off-cut reflection.'];v['contextSha256']=p.digest(self.context)
        a=parsed(self.context,v)
        self.assertEqual(a['status'],'ready_for_segmentation');self.assertEqual(a['reviewReasons'],[])
        for code in ['TARGET_PURPOSE_OR_COUNT_UNSUPPORTED','TARGET_SILHOUETTE_UNSUPPORTED','RECEIVER_UNSUPPORTED','RECEIVER_REFLECTION_UNRESOLVED',
                     'RECEIVER_SAMPLES_UNSUPPORTED','OPTICAL_DEPENDENCY_REQUIRES_REVIEW','FOREGROUND_REQUIRES_REVIEW','MORE_CONTEXT_REQUIRED',
                     'SHADOW_UNRESOLVED','UNRESOLVED_UNCERTAINTY','SOURCE_PLAN_UNRESOLVED_UNCERTAINTY']:
            self.assertIn(code,a['caveats'],code)
        self.assertEqual([x['stepKey'] for x in a['segmentationPlan']],['target_object'])
    def test_declared_shadow_is_folded_into_one_mask_request(self):
        self.proposal['shadow'].update(status='single_local_attributable',box={'xMin':.4,'yMin':.5,'xMax':.6,'yMax':.6})
        a=parsed(self.context,self.proposal)
        self.assertEqual(a['status'],'ready_for_segmentation')
        self.assertEqual([x['stepKey'] for x in a['segmentationPlan']],['target_object'])
    def test_missing_target_stops_before_any_image(self):
        self.proposal['target']['box']=None;a=parsed(self.context,self.proposal)
        self.assertEqual(a['status'],'needs_review');self.assertEqual(a['reviewReasons'],['TARGET_SELECTION_MISSING']);self.assertEqual(a['segmentationPlan'],[])
    def test_degenerate_target_box_is_a_recorded_stop_not_a_parser_break(self):
        self.proposal['target']['box']={'xMin':.5,'yMin':.3,'xMax':.5,'yMax':.4};a=parsed(self.context,self.proposal)
        self.assertEqual(a['reviewReasons'],['TARGET_SELECTION_MISSING'])
    def test_target_touching_the_cut_edge_stops(self):
        self.proposal['target']['box'].update(xMin=.005);a=parsed(self.context,self.proposal)
        self.assertEqual(a['status'],'needs_review');self.assertIn('TARGET_TOUCHES_CUT_EDGE',a['reviewReasons'])
    def test_target_inside_the_ten_percent_guard_stops_before_the_image(self):
        # workflow_qa recompose-section (pinned by the lighting workflow manifest) aborts inside this band after the
        # image, so it is a stop before any money is spent, not a caveat
        self.proposal['target']['box'].update(xMin=.05);self.proposal['receiver']['samples']=[]
        a=parsed(self.context,self.proposal)
        self.assertEqual(a['status'],'needs_review');self.assertIn('SUPPORT_SELECTION_TOUCHES_GUARD',a['reviewReasons'])
    def test_bad_shadow_or_protected_box_is_a_caveat_not_a_parser_break(self):
        self.proposal['shadow'].update(status='single_local_attributable',box={'xMin':.6,'yMin':.5,'xMax':.6,'yMax':.6})
        self.proposal['protectedFeatures']=[{'id':'cup','box':{'xMin':.7,'yMin':.7,'xMax':.7,'yMax':.8},'evidenceIds':['source_cut'],'notes':'Keep.'},
                                            {'id':'tap','box':None,'evidenceIds':['source_cut'],'notes':'Keep.'}]
        a=parsed(self.context,self.proposal)
        self.assertEqual(a['status'],'ready_for_segmentation')
        for code in ['SHADOW_SELECTION_INVALID','PROTECTED_FEATURE_LOCATION_UNRESOLVED']:self.assertIn(code,a['caveats'])
    def test_material_instructions_pick_loose_items_and_record_doubts(self):
        text=p.MATERIALS_INSTRUCTIONS
        self.assertIn('small loose',text);self.assertIn('caveat',text);self.assertIn('null',text)
    def test_legacy_v1_route_uses_the_same_finish_first_gates(self):
        c,_,v=fixture();v['target']['purpose']='functional_or_uncertain';a=parsed(c,v)
        self.assertEqual(a['status'],'ready_for_segmentation');self.assertIn('TARGET_PURPOSE_OR_COUNT_UNSUPPORTED',a['caveats'])


class FinishFirstSupportTests(unittest.TestCase):
    def setUp(self):
        self.mask=np.zeros((2048,2048),bool);self.mask[750:950,650:850]=True
        self.proposal={'protectedFeatures':[]}
    def test_group_of_touching_or_nearby_items_and_holes_are_caveats(self):
        split=self.mask.copy();split[960:1000,860:900]=True;hole=self.mask.copy();hole[800:810,700:710]=False
        a,reasons,stats=s.derive({'target_object':split},self.proposal)
        self.assertEqual(reasons,[]);self.assertIn('OBJECT_MASK_DISCONNECTED',stats['caveats'])
        a,reasons,stats=s.derive({'target_object':hole},self.proposal)
        self.assertEqual(reasons,[]);self.assertIn('OBJECT_MASK_HOLES_FILLED',stats['caveats']);self.assertEqual(a[805,705],255)
    def test_empty_mask_is_still_a_hard_stop(self):
        self.assertIn('OBJECT_MASK_EMPTY_OR_TOO_SMALL',s.derive({'target_object':np.zeros_like(self.mask)},self.proposal)[1])
    def test_an_object_100px_from_the_cut_edge_stops_before_the_image(self):
        near=np.zeros_like(self.mask);near[100:200,600:700]=True
        _,reasons,stats=s.derive({'target_object':near},self.proposal)
        self.assertEqual(reasons,['SUPPORT_TOUCHES_GUARD']);self.assertNotIn('SUPPORT_TOUCHES_GUARD',stats['caveats'])
        edge=np.zeros_like(self.mask);edge[5:200,600:700]=True
        self.assertIn('SUPPORT_TOUCHES_CUT_EDGE',s.derive({'target_object':edge},self.proposal)[1])
    def test_large_domain_and_protected_overlap_are_caveats(self):
        large=self.mask.copy();large[600:1100,600:1100]=True
        self.proposal['protectedFeatures']=[{'box':{'xMin':.3,'xMax':.4,'yMin':.3,'yMax':.5}},{'box':None}]
        _,reasons,stats=s.derive({'target_object':large},self.proposal)
        self.assertEqual(reasons,[])
        for code in ['EDIT_DOMAIN_TOO_LARGE','PROTECTED_FEATURE_OVERLAP','PROTECTED_FEATURE_UNLOCATED']:self.assertIn(code,stats['caveats'])
    def test_declared_shadow_box_is_folded_in_locally(self):
        self.proposal['shadow']={'status':'single_local_attributable','box':{'xMin':820/2048,'yMin':900/2048,'xMax':1400/2048,'yMax':1000/2048}}
        a,reasons,stats=s.derive({'target_object':self.mask},self.proposal)
        self.assertEqual(reasons,[]);self.assertEqual(a[960,880],255)  # inside box and next to the object
        self.assertEqual(a[960,1300],0)  # inside the box but far from the object: never a long-shadow paste
        self.assertGreater(stats['shadowPixels'],0)
    def test_a_selected_group_replaces_its_whole_box_so_unmasked_neighbours_go_too(self):
        # One mask request may outline only one item of a touching group; the analysis box covers them all.
        box={'xMin':640/2048,'yMin':740/2048,'xMax':1000/2048,'yMax':960/2048}
        self.proposal['target']={'count':'multiple_or_uncertain','box':box}
        a,reasons,stats=s.derive({'target_object':self.mask},self.proposal)
        self.assertEqual(reasons,[]);self.assertEqual(a[850,980],255);self.assertIn('GROUP_SELECTION_BOX_REPLACED',stats['caveats'])
        self.proposal['target']={'count':'exactly_one','box':box}
        a,_,stats=s.derive({'target_object':self.mask},self.proposal);self.assertEqual(a[850,980],0)
    def test_no_declared_shadow_adds_nothing(self):
        self.proposal['shadow']={'status':'none_observed','box':None}
        a,_,stats=s.derive({'target_object':self.mask},self.proposal);self.assertEqual(stats['shadowPixels'],0)


if __name__=='__main__':unittest.main()
