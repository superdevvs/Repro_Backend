import copy
from pathlib import Path
import sys
import unittest
sys.path.insert(0,str(Path(__file__).resolve().parents[2]/'resources'/'iguide-lab'))
import lifestyle_support as s
import numpy as np
import cv2

def fixture():
    size=512;source=np.full((size,size,3),130,np.uint8);source[300:]=(120,100,80);candidate=source.copy();masks=[];people=[]
    for i,x in enumerate([100,310]):
        mask=np.zeros((size,size),np.uint8)
        cv2.circle(mask,(x+22,128),17,255,-1)
        cv2.rectangle(mask,(x+8,140),(x+36,272),255,-1)
        cv2.rectangle(mask,(x,155),(x+8,245),255,-1);cv2.rectangle(mask,(x+36,155),(x+44,245),255,-1)
        cv2.rectangle(mask,(x+8,267),(x+18,402),255,-1);cv2.rectangle(mask,(x+27,267),(x+37,402),255,-1)
        cv2.rectangle(mask,(x+4,398),(x+20,408),255,-1);cv2.rectangle(mask,(x+25,398),(x+42,408),255,-1)
        candidate[mask>0]=(25+30*i,35,55);masks.append(mask)
        shoes=[]
        for side,a,b in [('left',x+5,x+19),('right',x+26,x+41)]:
            shoes.append({'side':side,'status':'visible_contact','sourceFloorRelation':'visible_level_floor','soleSegment':[{'x':a/size,'y':408/size},{'x':b/size,'y':408/size}]})
        people.append({'id':'adult_'+str(i+1),'status':'complete_standing_adult','anatomy':'plausible_visible_limbs','scale':'plausible_source_relative','topology':'clear_visible_boundaries_and_holes','shoes':shoes})
    floor=np.zeros((size,size),np.uint8);floor[300:]=255
    statuses={'sourceRegion':'visible_level_floor_no_existing_people','group':'two_separate_standing_adults_no_props','foreground':'no_foreground_overlap','protectedFeatures':'no_conflict_observed','optics':'no_relevant_dependency_observed','lighting':'diffuse_tiny_contact_only','contextCoverage':'sufficient_same_capture_views'}
    assessment={'schemaVersion':'automatic-standing-pair-assessment/1','status':'ready_for_segmentation','reviewReasons':[],
        'proposal':{'people':people,'uncertainties':[],**{k:{'status':v} for k,v in statuses.items()}}}
    return source,candidate,masks,floor,{'yaw':0,'pitch':0,'hFov':90},assessment

class SupportTests(unittest.TestCase):
    def test_visible_shoes_have_bounded_original_floor_contacts(self):
        result=s.derive_support(*fixture());r=result['report']
        self.assertEqual(r['status'],'ready_for_unreviewed_composition',r['reviewReasons'])
        self.assertEqual([len(x['contacts']) for x in r['people']],[2,2]);self.assertFalse(r['automaticAcceptance'])
        self.assertGreater(np.count_nonzero(result['scalarShadow']),0);self.assertEqual(r['shadowOutsideSafeFloorPixels'],0)
        self.assertEqual(r['alphaOutsidePersonMasksPixels'],0)
    def test_deterministic_exact_array_replay(self):
        a=s.derive_support(*fixture());b=s.derive_support(*fixture())
        for k in ['objectAlpha','scalarShadow','safeFloorAlpha']:self.assertTrue(np.array_equal(a[k],b[k]))
    def assertCaveat(self,result,code):
        # The paid people image is always composited; doubts are caveats for the final full-sphere check.
        r=result['report'];self.assertEqual(r['status'],'ready_for_unreviewed_composition');self.assertEqual(r['reviewReasons'],[])
        self.assertIn(code,r['caveats'])
    def test_ready_support_records_no_caveats(self):
        self.assertEqual(s.derive_support(*fixture())['report']['caveats'],[])
    def test_no_floor_contact_drops_only_that_shadow(self):
        args=list(fixture());args[3][395:420,90:150]=0
        result=s.derive_support(*args);self.assertCaveat(result,'SHOE_CONTACT_LACKS_INWARD_ORIGINAL_FLOOR')
        self.assertFalse(np.any(result['scalarShadow'][380:440,80:160]));self.assertTrue(np.any(result['scalarShadow']))
        self.assertEqual(result['report']['shadowOutsideSafeFloorPixels'],0)
    def test_hand_edge_cannot_be_used_as_shoe(self):
        args=list(fixture());shoe=args[-1]['proposal']['people'][0]['shoes'][0];shoe['soleSegment']=[{'x':101/512,'y':245/512},{'x':108/512,'y':245/512}]
        result=s.derive_support(*args);r=result['report'];self.assertEqual(r['status'],'ready_for_unreviewed_composition');self.assertTrue(r['caveats'])
        self.assertFalse(np.any(result['scalarShadow'][230:265,90:120]));self.assertEqual([len(x['contacts']) for x in r['people']],[1,2])
    def test_no_occluded_shoe_invention(self):
        args=list(fixture());args[-1]['proposal']['people'][0]['shoes'][0]['status']='occluded'
        result=s.derive_support(*args);self.assertCaveat(result,'VISIBLE_SHOE_CONTACT_UNRESOLVED')
        self.assertFalse(np.any(result['scalarShadow'][400:425,103:121]));self.assertTrue(np.any(result['scalarShadow']))
    def test_broad_shadow_and_reflection_doubts_are_caveats_with_tiny_contacts_only(self):
        for name,status in [('lighting','broad_or_directional_shadow_required'),('optics','visible_or_possible_dependency'),('foreground','overlap')]:
            args=list(fixture());args[-1]['proposal'][name]['status']=status;result=s.derive_support(*args)
            with self.subTest(name=name):
                self.assertCaveat(result,name.upper()+'_REQUIRES_REVIEW');self.assertTrue(np.any(result['scalarShadow']))
                self.assertEqual(result['report']['shadowOutsideSafeFloorPixels'],0)
    def test_unready_typed_assessment_is_a_hard_stop(self):
        args=list(fixture());args[-1]['status']='needs_review';args[-1]['reviewReasons']=['PERSON_BOX_MISSING']
        result=s.derive_support(*args);r=result['report']
        self.assertEqual(r['status'],'needs_review');self.assertIn('TYPED_PEOPLE_ASSESSMENT_NOT_READY',r['reviewReasons']);self.assertFalse(np.any(result['scalarShadow']))
    def test_exactly_two_masks_and_binary_only(self):
        args=list(fixture());args[2]=args[2][:1]
        with self.assertRaisesRegex(ValueError,'EXACTLY_TWO'):s.derive_support(*args)
        args=list(fixture());args[2][0][200,110]=128
        with self.assertRaisesRegex(ValueError,'MUST_BE_BINARY'):s.derive_support(*args)
    def test_disconnected_extra_person_piece_is_a_caveat(self):
        args=list(fixture());args[2][0][180:220,160:180]=255;args[1][180:220,160:180]=(25,35,55)
        result=s.derive_support(*args);self.assertCaveat(result,'PERSON_MASK_FRAGMENTED')

if __name__=='__main__':unittest.main()
