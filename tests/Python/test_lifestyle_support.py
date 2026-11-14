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
    def test_no_floor_contact_stops_shadow(self):
        args=list(fixture());args[3][395:420,90:150]=0
        result=s.derive_support(*args);self.assertEqual(result['report']['status'],'needs_review');self.assertFalse(np.any(result['scalarShadow']))
    def test_hand_edge_cannot_be_used_as_shoe(self):
        args=list(fixture());shoe=args[-1]['proposal']['people'][0]['shoes'][0];shoe['soleSegment']=[{'x':101/512,'y':245/512},{'x':108/512,'y':245/512}]
        result=s.derive_support(*args);self.assertEqual(result['report']['status'],'needs_review');self.assertFalse(np.any(result['scalarShadow']))
    def test_no_occluded_shoe_invention(self):
        args=list(fixture());args[-1]['proposal']['people'][0]['shoes'][0]['status']='occluded'
        result=s.derive_support(*args);self.assertIn('VISIBLE_SHOE_CONTACT_UNRESOLVED',result['report']['reviewReasons']);self.assertFalse(np.any(result['scalarShadow']))
    def test_broad_shadow_and_reflections_hold(self):
        for name,status in [('lighting','broad_or_directional_shadow_required'),('optics','visible_or_possible_dependency'),('foreground','overlap')]:
            args=list(fixture());args[-1]['proposal'][name]['status']=status;result=s.derive_support(*args)
            with self.subTest(name=name):self.assertEqual(result['report']['status'],'needs_review');self.assertFalse(np.any(result['scalarShadow']))
    def test_exactly_two_masks_and_binary_only(self):
        args=list(fixture());args[2]=args[2][:1]
        with self.assertRaisesRegex(ValueError,'EXACTLY_TWO'):s.derive_support(*args)
        args=list(fixture());args[2][0][200,110]=128
        with self.assertRaisesRegex(ValueError,'MUST_BE_BINARY'):s.derive_support(*args)
    def test_disconnected_extra_person_piece_holds(self):
        args=list(fixture());args[2][0][180:220,160:180]=255;args[1][180:220,160:180]=(25,35,55)
        result=s.derive_support(*args);self.assertIn('PERSON_MASK_FRAGMENTED',result['report']['reviewReasons'])

if __name__=='__main__':unittest.main()
