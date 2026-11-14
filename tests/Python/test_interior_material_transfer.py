import importlib.util
from pathlib import Path
import sys
import unittest
import cv2
import numpy as np

ROOT=Path(__file__).resolve().parents[2]/'resources'/'iguide-lab'
sys.path.insert(0,str(ROOT))
import interior_material_transfer as core


def fixture(size=512, warp=True):
    """Synthetic preserved coarse folds/structure and genuinely new fine weave.

    These are generated correctness fixtures, not automatically segmented photos.
    """
    rng=np.random.default_rng(123)
    y,x=np.mgrid[:size,:size].astype(np.float32)
    coarse=cv2.GaussianBlur(rng.normal(0,1,(size,size)).astype(np.float32),(0,0),3)
    coarse=coarse/coarse.std()*20
    base=np.clip(125+coarse+8*np.sin(x/19)+6*np.cos(y/27),55,195)
    source=np.stack([base+8,base,base-5],axis=-1).astype(np.uint8)
    masks=[]
    for box in ((80,100,220,330),(290,150,430,380)):
        m=np.zeros((size,size),np.uint8);x0,y0,x1,y1=box;m[y0:y1,x0:x1]=255;masks.append(m)
    candidate=source.copy()
    for m in masks:
        pattern=5*np.sin(x*1.15)+4*np.sin(y*.91)
        for c,shift in enumerate((8,3,-2)):
            values=source[...,c].astype(np.float32)+pattern+shift
            candidate[...,c][m>0]=np.clip(values[m>0],0,255).astype(np.uint8)
    H=np.array([[1.001,.001,3],[-.001,1.002,-2],[.000002,-.000002,1]],np.float32) if warp else np.eye(3,dtype=np.float32)
    candidate=cv2.warpPerspective(candidate,H,(size,size))
    targets=[{'id':f'material_{i+1}','sourceMask':m,'candidateMask':cv2.warpPerspective(m,H,(size,size),flags=cv2.INTER_NEAREST),'registrationSurface':'planar'} for i,m in enumerate(masks)]
    return source,candidate,targets,np.zeros((size,size),np.uint8)


class TransferTests(unittest.TestCase):
    def test_source_support_has_no_donor_or_transfer_authority(self):
        s,_,t,p=fixture()
        targets=[{k:v for k,v in item.items() if k!='candidateMask'} for item in t]
        r=core.inspect_source_support(s,targets,p)
        self.assertEqual(r['status'],'eligible_for_donor_request',r)
        self.assertFalse(r['imageGenerationAuthorized']);self.assertFalse(r['materialTransferAuthorized'])
        self.assertNotIn('deltaLab',r);self.assertNotIn('effectiveAlpha',r)
        s[:]=128
        self.assertIn('INSUFFICIENT_COMMON_STRUCTURE',core.inspect_source_support(s,targets,p)['reasons'])

    def test_source_support_rejects_holes_foreground_and_frame_margin(self):
        for mutation,code in [
            (lambda t,p:t[0]['sourceMask'].__setitem__((slice(190,210),slice(140,160)),0),'SOURCE_UNSUPPORTED_HOLE'),
            (lambda t,p:p.__setitem__((slice(100,350),slice(70,240)),255),'INSUFFICIENT_UNOCCLUDED_SUPPORT'),
            (lambda t,p:t[0]['sourceMask'].__setitem__((slice(20,120),slice(80,180)),255),'TARGET_OUTER_GUARD')]:
            s,_,t,p=fixture(warp=False);targets=[{k:v for k,v in item.items() if k!='candidateMask'} for item in t]
            mutation(targets,p);r=core.inspect_source_support(s,targets,p)
            self.assertEqual(r['status'],'needs_review',r)
            self.assertIn(code,r['reasons'])

    def test_measured_translation_projective_pattern_and_boundary(self):
        s,c,t,p=fixture();r=core.derive_transfer(s,c,t,p)
        self.assertEqual(r['status'],'unreviewed',r)
        for d in r['diagnostics']['targets']:
            self.assertGreaterEqual(d['registration']['heldOutLandmarks'],6)
            self.assertLess(d['registration']['heldOutP95Pixels'],.9)
            self.assertGreater(d['newDetailRmsL'],.65)
        out=core.apply_section(s,r['deltaLab'],r['effectiveAlpha'])
        self.assertTrue(np.array_equal(out[r['effectiveAlpha']==0],s[r['effectiveAlpha']==0]))
        self.assertTrue(np.any(out!=s))
        self.assertEqual(int(r['effectiveAlpha'][100,150]),0)

    def test_constant_colour_donor_holds(self):
        s,c,t,p=fixture(warp=False)
        for target in t:c[target['candidateMask']>0]=[140,130,120]
        r=core.derive_transfer(s,c,t,p)
        self.assertEqual(r['status'],'needs_review')
        self.assertFalse(r['effectiveAlpha'].any())

    def test_palette_only_is_not_material_evidence(self):
        s,c,t,p=fixture(warp=False)
        c=s.copy()
        for target in t:
            a=target['candidateMask']>0;c[a]=np.clip(s[a].astype(int)+[4,4,4],0,255)
        r=core.derive_transfer(s,c,t,p)
        self.assertEqual(r['status'],'needs_review')
        self.assertIn('NON_COLOUR_DETAIL_INSUFFICIENT',r['reasons'])

    def test_impossible_registration_and_nonrigid_hold(self):
        s,c,t,p=fixture()
        c=np.random.default_rng(2).integers(0,256,c.shape,dtype=np.uint8)
        self.assertEqual(core.derive_transfer(s,c,t,p)['status'],'needs_review')
        t[0]['registrationSurface']='nonrigid_cloth'
        self.assertIn('NONRIGID_SURFACE_UNSUPPORTED',core.derive_transfer(s,c,t,p)['reasons'])

    def test_overlap_hole_and_edge_fail_closed(self):
        for mutation,code in [('overlap','TARGET_OVERLAP'),('hole','SOURCE_UNSUPPORTED_HOLE'),('edge','TARGET_OUTER_GUARD')]:
            with self.subTest(mutation=mutation):
                s,c,t,p=fixture(warp=False)
                if mutation=='overlap':t[1]['sourceMask']=t[0]['sourceMask'].copy();t[1]['candidateMask']=t[0]['candidateMask'].copy()
                if mutation=='hole':t[0]['sourceMask'][180:190,140:150]=0
                if mutation=='edge':t[0]['sourceMask'][100:200,:100]=255
                self.assertIn(code,core.derive_transfer(s,c,t,p)['reasons'])

    def test_protected_source_foreground_is_exact(self):
        s,c,t,p=fixture(warp=False);p[190:210,140:160]=255
        r=core.derive_transfer(s,c,t,p)
        self.assertEqual(r['status'],'unreviewed',r)
        self.assertFalse(r['effectiveAlpha'][p>0].any())
        out=core.apply_section(s,r['deltaLab'],r['effectiveAlpha'])
        self.assertTrue(np.array_equal(out[p>0],s[p>0]))

    def test_native_exterior_and_source_boundary_contact_preserved(self):
        s,c,t,p=fixture();r=core.derive_transfer(s,c,t,p)
        self.assertEqual(r['status'],'unreviewed',r['reasons'])
        native=np.full((400,800,3),[125,120,115],np.uint8)
        native[...,0]+=np.tile(np.arange(800,dtype=np.uint16)%9,(400,1)).astype(np.uint8)
        out,a,stats=core.compose_native(native,r['deltaLab'],r['effectiveAlpha'],{'yaw':170,'pitch':-15,'hFov':100})
        self.assertGreater(stats['editablePixels'],0)
        self.assertEqual(stats['outsideAlphaChangedPixels'],0)
        self.assertTrue(np.array_equal(out[a==0],native[a==0]))
        # The source-based sampling crosses longitude wrap without a special seam fill.
        out2,a2,_=core.compose_native(native,r['deltaLab'],r['effectiveAlpha'],{'yaw':170,'pitch':-15,'hFov':100})
        self.assertTrue(np.array_equal(out,out2));self.assertTrue(np.array_equal(a,a2))

    def test_bad_shapes_and_native_bounds(self):
        s,c,t,p=fixture()
        with self.assertRaises(ValueError):core.derive_transfer(s.astype(float),c,t,p)
        with self.assertRaises(ValueError):core.compose_native(s,np.zeros_like(s,dtype=np.float32),p,{'yaw':0,'pitch':0,'hFov':100})
        with self.assertRaises(ValueError):core.compose_native(np.zeros((32,64,3),np.uint8),np.zeros_like(s,dtype=np.float32),p,{'yaw':0,'pitch':90,'hFov':100})

    def test_observed_foreground_hole_can_be_retained(self):
        s,c,t,p=fixture(warp=False);p[190:210,140:160]=255
        t[0]['sourceMask'][p>0]=0;t[0]['candidateMask'][p>0]=0
        r=core.derive_transfer(s,c,t,p)
        self.assertEqual(r['status'],'unreviewed',r['reasons'])
        self.assertFalse(r['effectiveAlpha'][p>0].any())

    def test_native_clipping_and_nonfinite_fields_hold(self):
        native=np.full((128,256,3),230,np.uint8);delta=np.full((512,512,3),80,np.float32);a=np.zeros((512,512),np.uint8);a[150:350,150:350]=255
        with self.assertRaisesRegex(core.Hold,'NATIVE_CLIPPING_OPERATIONAL_LIMIT'):
            core.compose_native(native,delta,a,{'yaw':0,'pitch':0,'hFov':80})
        delta[200,200,0]=np.nan
        with self.assertRaisesRegex(ValueError,'FIELD_INVALID'):core.compose_native(native,delta,a,{'yaw':0,'pitch':0,'hFov':80})

    def test_excessive_warp_and_missing_structure_never_identity_fallback(self):
        s,c,t,p=fixture(warp=False)
        H=np.float32([[1,0,35],[0,1,0],[0,0,1]])
        c=cv2.warpPerspective(c,H,(512,512))
        for target in t:target['candidateMask']=cv2.warpPerspective(target['candidateMask'],H,(512,512),flags=cv2.INTER_NEAREST)
        self.assertEqual(core.derive_transfer(s,c,t,p)['status'],'needs_review')
        s,c,t,p=fixture(warp=False);s[:]=128
        self.assertIn('INSUFFICIENT_COMMON_STRUCTURE',core.derive_transfer(s,c,t,p)['reasons'])


if __name__=='__main__':unittest.main()
