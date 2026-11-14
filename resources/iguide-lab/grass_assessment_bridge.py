"""Private filesystem bridge for the frozen grass assessment protocol.

No HTTP, provider dispatch, acceptance, client-supplied authority or image edits.
The caller supplies paths to its persisted context, execution and SAM receipts.
"""
from __future__ import annotations
import argparse
import base64
import hashlib
import io
import json
import os
from pathlib import Path
import platform
import shutil
import tempfile
from PIL import Image, __version__ as PILLOW_VERSION
import grass_assessment_protocol as protocol

VERSION='grass-assessment-filesystem/1'
MAX_IMAGES=24*1024**2
MAX_PAYLOAD=36*1024**2
LIMITS={'context':256*1024,'execution':64*1024,'response':128*1024,'compiledPayload':MAX_PAYLOAD,
    'assessment':1024*1024,'reservations':16*1024,'plans':256*1024,'step':128*1024,'receipt':256*1024}
FIELDS={
    'compile':{'context','images'},
    'parse':{'context','response','execution','compiledPayload'},
    'bind-plans':{'assessment','reservations'},
    'associate':{'step','receipt'},
    'finalize-support':{'assessment','plans','receipts'},
}
COMMON={'schemaVersion','operation','outputDirectory'}


def sha(path):
    with Path(path).open('rb') as stream:
        h=hashlib.sha256()
        for block in iter(lambda:stream.read(1024**2),b''):h.update(block)
    return h.hexdigest()


def canonical(value):return json.dumps(value,sort_keys=True,separators=(',',':'),ensure_ascii=True,allow_nan=False).encode()


def runtime():
    return {'python':platform.python_version(),'pillow':PILLOW_VERSION,'platform':platform.system(),
        'bridgeVersion':VERSION,'bridgeSha256':sha(__file__),'protocolVersion':protocol.VERSION,
        'protocolSha256':sha(protocol.__file__),'sharedProtocolSha256':sha(protocol.shared.__file__)}


def validate_runtime():
    if platform.python_version()!='3.12.3' or PILLOW_VERSION!='13.0.0':raise ValueError('UNVALIDATED_ASSESSMENT_BRIDGE_RUNTIME')


def safe_path(value,root,must_exist=True):
    if not isinstance(value,str):raise ValueError('PATH_STRING_REQUIRED')
    raw=Path(value)
    if not raw.is_absolute() or any(p.is_symlink() or (hasattr(p,'is_junction') and p.is_junction()) for p in [raw,*raw.parents]):
        raise ValueError('RELATIVE_OR_LINK_PATH_REJECTED')
    path=raw.resolve(strict=must_exist)
    if path==root or not path.is_relative_to(root):raise ValueError('PATH_OUTSIDE_TRUSTED_ROOT')
    return path


def decode_json(raw):
    if not isinstance(raw,bytes):raise ValueError('JSON_BYTES_REQUIRED')
    try:return protocol.shared._loads(raw.decode('utf-8'))
    except UnicodeDecodeError as exc:raise ValueError('JSON_UTF8_REQUIRED') from exc


def _validate_image(raw,view):
    if len(raw)>MAX_IMAGES:raise ValueError('IMAGE_BYTES_LIMIT')
    with Image.open(io.BytesIO(raw)) as im:
        if im.format not in {'PNG','JPEG'} or im.mode not in {'RGB','RGBA'} or getattr(im,'n_frames',1)!=1 or im.size!=(view['width'],view['height']):
            raise ValueError('EVIDENCE_FORMAT_OR_DIMENSIONS_INVALID')
        if im.mode=='RGBA' and im.getchannel('A').getextrema()!=(255,255):raise ValueError('TRANSPARENT_EVIDENCE_UNSUPPORTED')
        if im.info.get('icc_profile') or im.getexif().get(274,1)!=1:raise ValueError('EVIDENCE_NORMALIZATION_REQUIRED')
        im.load()


def _validate_saved_payload(context,payload):
    """Recompile exact ordered embedded evidence, not merely the context label."""
    protocol.validate_context(context)
    try:
        messages=payload['input'];content=messages[0]['content']
        if len(messages)!=1 or messages[0]['role']!='user' or len(content)!=1+2*len(context['evidence']):raise ValueError()
        raws=[];total=0
        for index,view in enumerate(context['evidence']):
            label=content[index*2+1];image=content[index*2+2]
            if label!={'type':'input_text','text':'Evidence ID: '+view['id']} or set(image)!={'type','detail','image_url'} or image['type']!='input_image' or image['detail']!='high':raise ValueError()
            url=image['image_url'];prefix,encoded=url.split(',',1)
            if prefix not in {'data:image/png;base64','data:image/jpeg;base64'}:raise ValueError()
            raw=base64.b64decode(encoded,validate=True);total+=len(raw)
            if total>MAX_IMAGES:raise ValueError('IMAGE_BYTES_LIMIT')
            if hashlib.sha256(raw).hexdigest()!=view['sha256']:raise ValueError('PAYLOAD_EVIDENCE_HASH_MISMATCH')
            _validate_image(raw,view);raws.append(raw)
    except (KeyError,IndexError,TypeError,AttributeError) as exc:raise ValueError('COMPILED_PAYLOAD_SHAPE_INVALID') from exc
    expected=protocol.compile_request(context,raws)
    if payload!=expected:raise ValueError('COMPILED_PAYLOAD_CONTEXT_OR_POLICY_CHANGED')


def _output_keys(operation,status):
    if operation=='compile' and status=='compiled':return {'payload'}
    if operation=='parse' and status in {'needs_review','ready_for_source_segmentation'}:return {'assessment'}
    if operation=='bind-plans' and status=='server_reserved':return {'plans'}
    if operation=='associate' and status in {'needs_review','ready_for_source_support'}:return {'association'}
    if operation=='finalize-support' and status in {'needs_review','ready_for_source_support'}:
        return {'associations','support-assessment'} if status=='ready_for_source_support' else {'associations'}
    raise ValueError('CACHE_TERMINAL_STATUS_INVALID')


def run(request,allowed_root):
    validate_runtime()
    raw_root=Path(allowed_root)
    if not raw_root.is_absolute() or any(p.is_symlink() or (hasattr(p,'is_junction') and p.is_junction()) for p in [raw_root,*raw_root.parents]):raise ValueError('TRUSTED_ROOT_INVALID')
    root=raw_root.resolve(strict=True)
    if not root.is_dir():raise ValueError('TRUSTED_ROOT_INVALID')
    if not isinstance(request,dict) or type(request.get('schemaVersion')) is not int or request.get('schemaVersion')!=1 or request.get('operation') not in FIELDS:raise ValueError('REQUEST_INVALID')
    operation=request['operation']
    if set(request)!=COMMON|FIELDS[operation]:raise ValueError('REQUEST_FIELDS_INVALID')
    inputs={}
    def load(desc,limit,json_value=True):
        if not isinstance(desc,dict) or set(desc)!={'path','sha256'}:raise ValueError('ASSET_DESCRIPTOR_INVALID')
        protocol._hash(desc['sha256']);path=safe_path(desc['path'],root)
        if not path.is_file() or path.stat().st_size>limit:raise ValueError('INPUT_FILE_OR_SIZE_INVALID')
        raw=path.read_bytes()
        if hashlib.sha256(raw).hexdigest()!=desc['sha256']:raise ValueError('INPUT_HASH_MISMATCH')
        inputs[str(path)]=desc['sha256']
        return decode_json(raw) if json_value else raw
    values={}
    for name in FIELDS[operation]-{'images','receipts'}:
        values[name]=load(request[name],LIMITS[name],json_value=name!='response')
        if name not in {'response','plans'} and not isinstance(values[name],dict):raise ValueError('INPUT_JSON_OBJECT_REQUIRED')
    if operation=='compile':
        protocol.validate_context(values['context']);views=values['context']['evidence'];records=request['images']
        if not isinstance(records,list) or len(records)!=len(views):raise ValueError('EVIDENCE_IMAGE_COUNT_INVALID')
        values['images']=[];total=0
        for view,record in zip(views,records):
            if not isinstance(record,dict) or set(record)!={'id','path','sha256'} or record['id']!=view['id'] or record['sha256']!=view['sha256']:
                raise ValueError('EVIDENCE_ORDER_OR_HASH_INVALID')
            raw=load({k:record[k] for k in ['path','sha256']},MAX_IMAGES,False);total+=len(raw)
            if total>MAX_IMAGES:raise ValueError('IMAGE_BYTES_LIMIT')
            _validate_image(raw,view);values['images'].append(raw)
    if operation=='finalize-support':
        if not isinstance(request['receipts'],list) or not 1<=len(request['receipts'])<=6:raise ValueError('RECEIPT_COUNT_INVALID')
        values['receipts']=[load(desc,LIMITS['receipt']) for desc in request['receipts']]
    output=safe_path(request['outputDirectory'],root,must_exist=False)
    if any(Path(path).is_relative_to(output) for path in inputs):raise ValueError('OUTPUT_CONTAINS_INPUT')
    identity=runtime();fingerprint=hashlib.sha256(canonical({'request':request,'runtime':identity})).hexdigest()
    if output.exists():
        if not output.is_dir():raise ValueError('OUTPUT_NOT_DIRECTORY')
        report_path=safe_path(str(output/'report.json'),root)
        if report_path.stat().st_size>256*1024:raise ValueError('CACHE_REPORT_TOO_LARGE')
        report=decode_json(report_path.read_bytes())
        if not isinstance(report,dict) or report.get('requestFingerprint')!=fingerprint or report.get('runtime')!=identity or report.get('inputAssets')!=inputs or report.get('operation')!=operation:raise ValueError('CACHE_REQUEST_RUNTIME_OR_INPUT_CHANGED')
        if report.get('schemaVersion')!=1 or report.get('bridgeVersion')!=VERSION or report.get('automaticAcceptance') is not False or report.get('providerCalls')!=0 or report.get('reviewStatus')!='unreviewed':raise ValueError('CACHE_AUTHORITY_INVALID')
        keys=_output_keys(operation,report.get('resultStatus'))
        if set(report.get('outputs',{}))!=keys:raise ValueError('CACHE_OUTPUT_SET_INVALID')
        if {x.name for x in output.iterdir()}!={*(key+'.json' for key in keys),'report.json'}:raise ValueError('CACHE_EXTRA_OR_MISSING_ASSET')
        for key,desc in report['outputs'].items():
            if not isinstance(desc,dict) or set(desc)!={'path','sha256','bytes'} or desc['path']!=str(output/(key+'.json')):raise ValueError('CACHE_OUTPUT_DESCRIPTOR_INVALID')
            path=safe_path(desc['path'],root);limit=MAX_PAYLOAD if key=='payload' else 1024*1024
            if not isinstance(desc['bytes'],int) or isinstance(desc['bytes'],bool) or not 1<=desc['bytes']<=limit or not path.is_file() or path.stat().st_size!=desc['bytes'] or sha(path)!=desc['sha256']:raise ValueError('CACHE_OUTPUT_CHANGED')
            decode_json(path.read_bytes())
        if any(sha(path)!=expected for path,expected in inputs.items()):raise ValueError('INPUT_CHANGED_DURING_REPLAY')
        return report
    if operation=='compile':
        payload=protocol.compile_request(values['context'],values['images'])
        outputs={'payload':payload};status='compiled'
    elif operation=='parse':
        payload=values['compiledPayload'];execution=values['execution']
        if execution.get('requestPayloadSha256')!=request['compiledPayload']['sha256']:raise ValueError('EXECUTION_REQUEST_PAYLOAD_HASH_INVALID')
        _validate_saved_payload(values['context'],payload)
        assessment=protocol.parse_response(values['response'].decode('utf-8'),values['context'],execution)
        outputs={'assessment':assessment};status=assessment['status']
    elif operation=='bind-plans':
        plans=protocol.bind_reserved_plans(values['assessment'],values['reservations'])
        outputs={'plans':plans};status='server_reserved'
    elif operation=='associate':
        association=protocol.bind_segmentation_receipt(values['step'],values['receipt'])
        outputs={'association':association};status=association['status']
    else:
        plans=values['plans']
        if not isinstance(plans,list) or not 1<=len(plans)<=6:raise ValueError('PLAN_COUNT_INVALID')
        # Reconstruct from the machine assessment and already persisted server UUIDs.
        expected=protocol.bind_reserved_plans(values['assessment'],{x['stepKey']:x['requestId'] for x in plans})
        if plans!=expected:raise ValueError('RESERVED_PLANS_CHANGED')
        if len(values['receipts'])!=len(plans):raise ValueError('RECEIPT_COUNT_INVALID')
        associations=[protocol.bind_segmentation_receipt(step,receipt) for step,receipt in zip(plans,values['receipts'])]
        outputs={'associations':associations};status='needs_review'
        if all(x['status']=='ready_for_source_support' for x in associations):
            outputs['support-assessment']=protocol.composition_assessment_record(values['assessment'],plans,associations)
            status='ready_for_source_support'
    if any(sha(path)!=expected for path,expected in inputs.items()):raise ValueError('INPUT_CHANGED_DURING_PROCESSING')
    output.parent.mkdir(parents=True,exist_ok=True)
    temp=Path(tempfile.mkdtemp(prefix='.grass-assessment-',dir=output.parent));os.chmod(temp,0o2770)
    try:
        assets={}
        for name,value in outputs.items():
            raw=canonical(value)
            if len(raw)>(MAX_PAYLOAD if name=='payload' else 1024*1024):raise ValueError('OUTPUT_SIZE_LIMIT')
            path=temp/(name+'.json');path.write_bytes(raw);os.chmod(path,0o660)
            assets[name]={'path':str(output/path.name),'sha256':hashlib.sha256(raw).hexdigest(),'bytes':len(raw)}
        report={'schemaVersion':1,'bridgeVersion':VERSION,'operation':operation,'resultStatus':status,
            'requestFingerprint':fingerprint,'runtime':identity,'inputAssets':inputs,'outputs':assets,
            'automaticAcceptance':False,'providerCalls':0,'reviewStatus':'unreviewed'}
        path=temp/'report.json';path.write_bytes(canonical(report));os.chmod(path,0o660)
        if any(sha(path)!=expected for path,expected in inputs.items()):raise ValueError('INPUT_CHANGED_BEFORE_PUBLISH')
        temp.rename(output)
        return report
    finally:
        if temp.exists():shutil.rmtree(temp)


def main():
    if os.name=='posix':
        import resource
        resource.setrlimit(resource.RLIMIT_AS,(1024**3,1024**3));resource.setrlimit(resource.RLIMIT_CPU,(30,30));os.umask(0o007)
    parser=argparse.ArgumentParser();parser.add_argument('--request',required=True);parser.add_argument('--allowed-root',required=True);args=parser.parse_args()
    root=Path(args.allowed_root).resolve(strict=True);path=safe_path(args.request,root)
    if not path.is_file() or path.stat().st_size>64*1024:raise ValueError('REQUEST_FILE_OR_SIZE_INVALID')
    print(json.dumps(run(decode_json(path.read_bytes()),args.allowed_root),separators=(',',':')))


if __name__=='__main__':main()
