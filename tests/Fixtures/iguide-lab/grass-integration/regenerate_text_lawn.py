"""Regenerate the synthetic grass fixtures after the lawn became one text-prompted mask.

Run with the pinned runtime (Python 3.12.3, Pillow 13.0.0) from the repository root:
    python -B tests/Fixtures/iguide-lab/grass-integration/regenerate_text_lawn.py
Only the lawn plan, the lawn receipt and their derived records change; exclusion plans are unchanged.
"""
import hashlib
import json
from pathlib import Path
import sys

import numpy as np
from PIL import Image

ROOT=Path(__file__).resolve().parents[4]
HERE=Path(__file__).resolve().parent
sys.path.insert(0,str(ROOT/'resources/iguide-lab'))
import grass_assessment_protocol as protocol


def load(name):return json.loads((HERE/name).read_text(encoding='utf-8'))
def dump(name,value):(HERE/name).write_text(json.dumps(value,indent=2)+'\n',encoding='utf-8',newline='\n')


def descriptor(path):
    raw=path.read_bytes();pixels=np.asarray(Image.open(path).convert('L'))>0
    ys,xs=np.nonzero(pixels)
    return {'sha256':hashlib.sha256(raw).hexdigest(),'width':2048,'height':1024,
        'bounds':[int(xs.min()),int(ys.min()),int(xs.max())+1,int(ys.max())+1],'positivePixels':int(pixels.sum())}


def main():
    first=np.asarray(Image.open(HERE/'masks/lawn-0.png').convert('L'));second=np.asarray(Image.open(HERE/'masks/lawn-1.png').convert('L'))
    assert descriptor(HERE/'masks/lawn-0.png')==load('masks-index.json')[0]['descriptor']
    union=np.where((first>0)|(second>0),255,0).astype(np.uint8)
    Image.fromarray(union,'L').save(HERE/'masks/lawn-text.png',optimize=False,compress_level=6)
    lawn_mask=descriptor(HERE/'masks/lawn-text.png')
    response=(HERE/'response.SYNTHETIC.json').read_text(encoding='utf-8')
    # The synthetic fixtures were requested with gpt-6-astra (the server passes the requested model in).
    assessment=protocol.parse_response(response,load('context.json'),load('execution.SYNTHETIC.json'),'gpt-6-astra')
    assert assessment['status']=='ready_for_source_segmentation'
    dump('assessment.json',assessment)
    plans=protocol.bind_reserved_plans(assessment,load('reservations.mock-database.json'))
    dump('plans.json',plans)
    receipts=load('receipts.json');old=receipts[0]
    lawn=plans[0];assert lawn['maskRole']=='lawn'
    receipt={**old,'requestId':lawn['requestId'],'inputFingerprint':lawn['inputFingerprint'],'inputSha256':lawn['inputSha256'],'masks':[lawn_mask]}
    receipts[0]=receipt;dump('receipts.json',receipts);dump('receipts/lawn.json',receipt)
    for plan,value in zip(plans[1:],receipts[1:]):
        assert value['inputFingerprint']==plan['inputFingerprint'],plan['maskRole']
    associations=[protocol.bind_segmentation_receipt(plan,value) for plan,value in zip(plans,receipts)]
    assert all(x['status']=='ready_for_source_support' for x in associations)
    dump('associations.json',associations)
    dump('composition-assessment.json',protocol.composition_assessment_record(assessment,plans,associations))
    index=[x for x in load('masks-index.json') if x['role']!='lawn-text']
    index.append({'path':'fixtures/masks/lawn-text.png','sha256':lawn_mask['sha256'],'width':2048,'height':1024,'mode':'L','role':'lawn-text','descriptor':lawn_mask})
    dump('masks-index.json',index)
    vectors=load('cross-language-vectors.json')
    vectors['plans']=[{'stepKey':plan['stepKey'],'requestId':plan['requestId'],'inputFingerprint':plan['inputFingerprint'],
        'canonicalJsonWithoutFingerprint':json.dumps({k:v for k,v in plan.items() if k!='inputFingerprint'},sort_keys=True,separators=(',',':'))}
        for plan in plans]
    for entry in vectors['plans']:
        assert hashlib.sha256(entry['canonicalJsonWithoutFingerprint'].encode()).hexdigest()==entry['inputFingerprint']
    vectors['lawnMaskIndexes']=[0]
    dump('cross-language-vectors.json',vectors)
    print('regenerated',len(plans),'plans; lawn mask',lawn_mask)


if __name__=='__main__':main()
