"""Generated algorithm fixture only: no room, style or material quality claim."""
import argparse
from pathlib import Path
import os
for k in ('OMP_NUM_THREADS','OPENBLAS_NUM_THREADS','MKL_NUM_THREADS'):os.environ[k]='1'
import cv2
import numpy as np
from PIL import Image
BOXES=((300,400,850,1100),(1150,600,1700,1300))
p=argparse.ArgumentParser();p.add_argument('operation',choices=['native','donor']);p.add_argument('output');p.add_argument('--source');p.add_argument('--palette-only',action='store_true');a=p.parse_args()
out=Path(a.output);out.parent.mkdir(parents=True,exist_ok=True)
if a.operation=='native':
    rng=np.random.default_rng(218);noise=rng.normal(size=(512,1024)).astype(np.float32)
    coarse=cv2.GaussianBlur(noise,(0,0),1.1);coarse=coarse/coarse.std()*23
    base=np.clip(124+cv2.resize(coarse,(4096,2048),interpolation=cv2.INTER_CUBIC),55,195)
    rgb=np.stack([base+8,base,base-5],-1).astype(np.uint8);Image.fromarray(rgb).save(out)
else:
    source=np.array(Image.open(a.source).convert('RGB'));assert source.shape==(2048,2048,3)
    y,x=np.mgrid[:2048,:2048];pattern=np.zeros_like(x) if a.palette_only else 7*np.sin(x*1.1)+6*np.sin(y*.9)
    donor=source.copy()
    for i,(x0,y0,x1,y1) in enumerate(BOXES):
        mask=np.zeros((2048,2048),np.uint8);mask[y0:y1,x0:x1]=255
        for c,shift in enumerate((7,3,-2)):
            v=source[...,c].astype(np.float32)+pattern+shift;donor[...,c][mask>0]=np.clip(v[mask>0],0,255).astype(np.uint8)
        Image.fromarray(mask).save(out.parent/('material_'+str(i+1)+'.png'))
    Image.fromarray(donor).save(out)
