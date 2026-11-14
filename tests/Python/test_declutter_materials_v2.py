import copy,sys,unittest
from pathlib import Path
import numpy as np
sys.path[:0]=[str(Path(__file__).resolve().parents[2]/'resources/iguide-lab'),str(Path(__file__).parent)]
import declutter_removal_protocol as p
import declutter_support as support
from test_declutter_removal_protocol import fixture,parsed

def material_fixture():
    context,images,proposal=fixture()
    context['schemaVersion']=p.MATERIALS_VERSION
    proposal['contextSha256']=p.digest(context)
    proposal['target'].update(purpose='portable_clutter',description='one opaque freestanding knife block')
    proposal['receiver'].update(material='stochastic_stone_continuous',structuralDetail='no_structural_detail_crosses_edit',specularDependency='no_target_reflection_observed',
        samples=[{'xMin':.23,'yMin':.36,'xMax':.28,'yMax':.41},{'xMin':.47,'yMin':.36,'xMax':.52,'yMax':.41}])
    proposal['generationPrompt']='Remove only the selected opaque freestanding knife block and its explicitly attributable local shadow. Plausibly continue the sampled stochastic stone material and broad illumination while preserving every non-selected object, counter edge, joint, viewpoint and image border.'
    return context,images,proposal

class MaterialProtocolTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):cls.base=material_fixture()
    def setUp(self):self.context,self.images,self.proposal=copy.deepcopy(self.base)
    def test_legacy_wire_contract_is_unchanged(self):
        c,images,_=fixture()
        self.assertEqual(p.digest(p.compile_request(c,images)),'bfc95d87e43eb5adea99410c233e9178aae0a15b8e83244ae2d235095898f0ce')
        self.assertEqual(p.digest(p.response_schema()),'46d60eda796e2b932dc914b9d3bbdf12fae4f0c1e356acc8bbcd4c639a4c4233')
    def test_portable_item_and_stone_use_distinct_wire_and_authority(self):
        body=p.compile_request(self.context,self.images);self.assertEqual(body['instructions'],p.MATERIALS_INSTRUCTIONS)
        result=parsed(self.context,self.proposal)
        self.assertEqual(result['status'],'ready_for_segmentation');self.assertEqual(result['schemaVersion'],p.MATERIALS_VERSION)
        self.assertFalse(result['hiddenSurfaceVerified']);self.assertFalse(result['automaticAcceptance'])
        self.context['schemaVersion']=p.VERSION;self.proposal['contextSha256']=p.digest(self.context)
        with self.assertRaises(ValueError):parsed(self.context,self.proposal)
    def test_material_extension_keeps_optics_opacity_structure_and_fixed_item_holds(self):
        for section,key,value in [('target','purpose','fixed_or_uncertain'),('target','opacity','transparent_or_uncertain'),
            ('target','silhouette','clipped_thin_holes_or_uncertain'),('receiver','structuralDetail','crossing_or_uncertain'),
            ('receiver','specularDependency','possible_or_uncertain'),('receiver','material','structured_or_uncertain'),
            ('optics','status','possible_dependency_or_insufficient_context')]:
            with self.subTest(key=key):
                v=copy.deepcopy(self.proposal);v[section][key]=value;r=parsed(self.context,v)
                self.assertEqual(r['status'],'needs_review');self.assertEqual(r['segmentationPlan'],[])
    def test_one_sided_diagonal_small_distant_and_overlapping_samples_hold(self):
        cases=[
            [{'xMin':.20,'xMax':.26,'yMin':.31,'yMax':.36},{'xMin':.20,'xMax':.26,'yMin':.40,'yMax':.45}],
            [{'xMin':.23,'xMax':.28,'yMin':.23,'yMax':.28},{'xMin':.23,'xMax':.28,'yMin':.57,'yMax':.62}],
            [{'xMin':.27,'xMax':.28,'yMin':.36,'yMax':.41},self.proposal['receiver']['samples'][1]],
            [{'xMin':.00,'xMax':.04,'yMin':.36,'yMax':.41},self.proposal['receiver']['samples'][1]],
            [self.proposal['target']['box'],self.proposal['receiver']['samples'][1]],
            [self.proposal['receiver']['samples'][0],self.proposal['receiver']['samples'][0]],
            [self.proposal['receiver']['samples'][0]],
        ]
        for boxes in cases:
            with self.subTest(boxes=boxes):
                v=copy.deepcopy(self.proposal);v['receiver']['samples']=boxes
                self.assertIn('RECEIVER_SAMPLES_UNSUPPORTED',parsed(self.context,v)['reviewReasons'])
    def test_samples_cannot_overlap_known_protection_or_shadow(self):
        v=copy.deepcopy(self.proposal);v['protectedFeatures']=[{'id':'cup','box':v['receiver']['samples'][0],'notes':'Keep.','evidenceIds':['source_cut']}]
        self.assertIn('SAMPLE_PROTECTED_FEATURE_OVERLAP',p.receiver_sample_reasons(v))
        v=copy.deepcopy(self.proposal);v['shadow'].update(status='single_local_attributable',box=v['receiver']['samples'][0])
        self.assertIn('SAMPLE_OVERLAP',p.receiver_sample_reasons(v))
    def test_direct_support_helper_rejects_nonfinite_reversed_and_out_of_bounds_boxes(self):
        for key,value in [('xMin',-.1),('xMin',float('nan')),('xMax',float('inf')),('xMax',.2),('xMin',True)]:
            v=copy.deepcopy(self.proposal);v['receiver']['samples'][0][key]=value
            self.assertIn('SAMPLE_BOX_INVALID',p.receiver_sample_reasons(v))
    def test_original_uncertainty_still_holds_v2(self):
        self.context['sourcePlan']['uncertainties']=['Possible target reflection outside the cut.'];self.proposal['contextSha256']=p.digest(self.context)
        self.assertIn('SOURCE_PLAN_UNRESOLVED_UNCERTAINTY',parsed(self.context,self.proposal)['reviewReasons'])

class MaterialSupportTests(unittest.TestCase):
    def test_final_expanded_mask_can_invalidate_approximate_clear_samples(self):
        _,_,v=material_fixture();mask=np.zeros((2048,2048),bool);mask[750:950,650:850]=True
        v['target']['box']={'xMin':650/2048,'yMin':750/2048,'xMax':850/2048,'yMax':950/2048}
        v['receiver']['samples']=[{'xMin':612/2048,'yMin':800/2048,'xMax':648/2048,'yMax':840/2048},{'xMin':860/2048,'yMin':800/2048,'xMax':896/2048,'yMax':840/2048}]
        self.assertEqual(p.receiver_sample_reasons(v),[])
        self.assertIn('RECEIVER_SAMPLE_INTERSECTS_ACTUAL_SUPPORT',support.derive({'target_object':mask},v)[1])
        v['receiver']['samples'][0].update(xMin=600/2048,xMax=640/2048)
        _,reasons,stats=support.derive({'target_object':mask},v);self.assertEqual(reasons,[]);self.assertLess(stats['positiveSupportPixels'],131072)
    def test_invalid_box_never_uses_negative_numpy_slicing(self):
        _,_,v=material_fixture();mask=np.zeros((2048,2048),bool);mask[750:950,650:850]=True
        v['receiver']['samples'][0]['xMin']=-.1
        self.assertIn('SAMPLE_BOX_INVALID',support.derive({'target_object':mask},v)[1])

if __name__=='__main__':unittest.main()
