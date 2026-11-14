"""Default-off D2 worker. Uses frozen source/QA primitives, never a provider."""
import argparse,json,os,shutil,tempfile,time,hashlib
from pathlib import Path
import numpy as np
import workflow_qa as qa
import lighting_transfer_d2 as d2
g=qa.g
require=qa.require
checked=qa.checked
read_image=qa.read_image
canonical=qa.canonical
qa_arrays=qa.qa_arrays
_save=qa._save
_descriptor=qa._descriptor

def runtime():
    return {**qa.runtime(), 'lightingD2Recipe':d2.RECIPE,
            'lightingD2CodeSha256':g.sha256(d2.__file__), 'lightingD2WorkerSha256':g.sha256(__file__)}

def run(request):
    qa.validate_runtime()
    require(request.get("schemaVersion") == 1 and request.get("operation") == "transfer-lighting-d2", "D2_OPERATION_REQUIRED")
    allowed = {'schemaVersion','operation','privateRoot','source','guidanceSource','candidate','preset','outputDirectory',
               'editScope','recipe','sourcePreparation'}
    require(set(request) == allowed, 'REQUEST_FIELDS_INVALID')
    require(request['preset'] in {'golden-hour','twilight'} and request['editScope'] == 'entire_view', 'LIGHTING_SCOPE_INVALID')
    selected_recipe = d2.RECIPE
    require(request['recipe'] == selected_recipe, 'LIGHTING_RECIPE_UNSUPPORTED')
    prep = request['sourcePreparation']
    require(set(prep) == {'nativeSourceSha256','guidanceSourceSha256','preparerSha256','method','verifiedByCaller'}, 'SOURCE_PREPARATION_DESCRIPTOR_INVALID')
    require(prep['nativeSourceSha256'] == request['source']['sha256'] and prep['guidanceSourceSha256'] == request['guidanceSource']['sha256']
            and prep['method'] == 'LabImages::prepared/GD' and prep['verifiedByCaller'] is True
            and isinstance(prep['preparerSha256'],str) and len(prep['preparerSha256']) == 64
            and all(c in '0123456789abcdef' for c in prep['preparerSha256']), 'SOURCE_PREPARATION_BINDING_INVALID')
    root = Path(request['privateRoot']).resolve(strict=True)
    assets = {key:checked(request[key],root) for key in ['source','guidanceSource','candidate']}
    out = Path(request['outputDirectory']).resolve()
    require(out != root and out.is_relative_to(root) and not any(out == p or out in p.parents for p in assets.values()), 'OUTPUT_PATH_INVALID')
    identity = runtime()
    fingerprint = hashlib.sha256(canonical({'request':request,'runtime':identity})).hexdigest()
    if out.exists():
        report = json.loads((out/'report.json').read_text())
        require(report.get('requestFingerprint') == fingerprint and report.get('status') == 'completed'
                and report.get('reviewStatus') == 'unreviewed' and report.get('automaticAcceptance') is False, 'CACHE_IDENTITY_MISMATCH')
        require(report.get('operation') == request['operation'] and report.get('recipe') == selected_recipe
                and report.get('runtime') == identity and report.get('sourceSha256') == request['source']['sha256']
                and report.get('candidateSha256') == request['candidate']['sha256']
                and report.get('guidanceSourceSha256') == request['guidanceSource']['sha256']
                and report.get('sourcePreparation') == prep and report.get('editScope') == 'entire_view'
                and report.get('providerCalls') == 0 and report.get('parameters') == d2.PARAMETERS, 'CACHE_EXECUTION_BINDING_INVALID')
        require(set(report.get('artifacts',{})) == {'native','preview','lighting-field','highlight-confirmation'} and isinstance(report.get('qa'),dict), 'CACHE_ARTIFACT_SET_INVALID')
        for item in report['artifacts'].values():
            p=Path(item['path']).resolve(strict=True)
            require(p.is_relative_to(out) and g.sha256(p)==item['sha256'], 'CACHE_ASSET_MISMATCH')
        return report
    start=time.monotonic()
    source=read_image(assets['source'],panorama=True)
    guide=read_image(assets['guidanceSource'],expected=(2048,1024),panorama=True)
    donor=read_image(assets['candidate'],expected=(2048,1024),panorama=True)
    field=d2.derive_field(guide,donor,g.to_linear)
    confirmation=d2.confirmation(donor,g.to_linear)
    result,telemetry=d2.apply_field(source,field,confirmation,g)
    require(telemetry['nonfiniteChannels'] == 0 and telemetry['policyProtectedNadirChangedPixels'] == 0
            and telemetry['preclipLinearMinMax'][0] >= -1e-6 and telemetry['preclipLinearMinMax'][1] <= 1+1e-6,
            'LIGHTING_NUMERICAL_INVARIANT_FAILED')
    qa_report=qa_arrays(source,result,None,request['preset'])
    require(all(g.sha256(p)==request[key]['sha256'] for key,p in assets.items()), 'INPUT_CHANGED_DURING_WORK')
    out.parent.mkdir(parents=True,exist_ok=True)
    temp=Path(tempfile.mkdtemp(prefix='.automation-lighting-',dir=out.parent))
    try:
        _save(temp/'native.png',result)
        _save(temp/'preview.png',g.resize_rgb(result,2048))
        np.save(temp/'lighting-field.npy',field,allow_pickle=False)
        np.save(temp/'highlight-confirmation.npy',confirmation,allow_pickle=False)
        artifacts={name:_descriptor(temp/f'{name}.png',out/f'{name}.png') for name in ['native','preview']}
        artifacts['lighting-field']={'path':str(out/'lighting-field.npy'),'sha256':g.sha256(temp/'lighting-field.npy'),
            'dimensions':[field.shape[1],field.shape[0],3],'dtype':'float32','role':'Low-frequency guidance only; no generated RGB pixels copied.'}
        artifacts['highlight-confirmation']={'path':str(out/'highlight-confirmation.npy'),'sha256':g.sha256(temp/'highlight-confirmation.npy'),'dimensions':[confirmation.shape[1],confirmation.shape[0],3],'dtype':'float32','role':'Donor mutual-luminance/channel confirmation only; not semantic lamp support.'}
        report={'schemaVersion':1,'status':'completed','operation':request['operation'],'recipe':selected_recipe,
            'requestFingerprint':fingerprint,'runtime':identity,'reviewStatus':'unreviewed','automaticAcceptance':False,
            'providerCalls':0,'editScope':'entire_view','preset':request['preset'],'sourceSha256':request['source']['sha256'],
            'candidateSha256':request['candidate']['sha256'],'guidanceSourceSha256':request['guidanceSource']['sha256'],
            'sourcePreparation':prep,'sourcePreparationEvidence':'Exact preparation relation asserted and checked by trusted PHP caller; this worker checks asset hashes, not a different resampler.',
            'parameters':d2.PARAMETERS,
            'telemetry':telemetry,'qa':qa_report,'artifacts':artifacts,
            'fieldMinMax':[float(field.min()),float(field.max())], 'elapsedMs':round((time.monotonic()-start)*1000),
            'kind':'derived_source_detail_lighting_composite',
            'limitations':['Lighting is an artistic inference, not calibrated radiance or recovered material properties.',
                'Smooth lighting can retain source daylight contrast or unsupported donor shadow/light patterns; optical checks remain mandatory.',
                'Only source spatial detail is used, but RGB and local contrast change. No pixel-identical texture or whole-image pixel lock claim.',
                'Fixed nadir preservation can leave source-colored capture surroundings; it does not identify every scanner artifact.',
                'Mutual highlight protection is a color/luminance heuristic, not semantic lamp identification; matched non-lamp highlights can be protected.',
                'No lamp-emission synthesis, new sky structure, body reflections or cross-camera consistency is supplied by this operation.']}
        (temp/'report.json').write_text(json.dumps(report,indent=2,allow_nan=False))
        if os.name=='posix':
            os.chmod(temp,0o2770)
            for p in temp.rglob('*'):
                os.chmod(p,0o2770 if p.is_dir() else 0o660)
        os.rename(temp,out)
        return report
    finally:
        if temp.exists() and temp.parent==out.parent and temp.name.startswith('.automation-lighting-'):
            shutil.rmtree(temp)


def main():
    parser=argparse.ArgumentParser();parser.add_argument('--request',required=True);args=parser.parse_args()
    try:
        if os.name=='posix':
            import resource
            resource.setrlimit(resource.RLIMIT_AS,(3*1024**3,3*1024**3))
            resource.setrlimit(resource.RLIMIT_CPU,(220,220))
        path=Path(args.request);require(path.stat().st_size<=65536,'REQUEST_TOO_LARGE')
        print(json.dumps(run(json.loads(path.read_text(encoding='utf-8-sig'))),separators=(',',':')))
        return 0
    except (qa.WorkerError,OSError,ValueError,KeyError,TypeError,MemoryError) as exc:
        print(json.dumps({'status':'failed','code':getattr(exc,'code',type(exc).__name__),'automaticAcceptance':False,'providerCalls':0}));return 1
if __name__=='__main__':raise SystemExit(main())
