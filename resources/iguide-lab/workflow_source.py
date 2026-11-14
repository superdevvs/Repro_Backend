"""Unpaid encoding preflight for immutable native sources, before any provider claim."""
import json,os,sys
from pathlib import Path
if os.name == 'posix':
    import resource
    resource.setrlimit(resource.RLIMIT_AS,(2*1024**3,2*1024**3))
    resource.setrlimit(resource.RLIMIT_CPU,(30,30))
import workflow_qa as worker

def run(request):
    worker.validate_runtime()
    worker.require(set(request)=={'source','privateRoot'},'SOURCE_PREFLIGHT_FIELDS')
    root=Path(request['privateRoot']).resolve(strict=True)
    path=worker.checked(request['source'],root,30*1024*1024)
    source=worker.read_image(path,panorama=True)
    worker.require(source.shape[1]>=2048,'SOURCE_TOO_SMALL')
    return {'status':'completed','sourceSha256':request['source']['sha256'],
        'dimensions':[source.shape[1],source.shape[0]],'runtime':worker.runtime(),
        'sourceAdapterSha256':worker.g.sha256(__file__),'providerCalls':0}

if __name__=='__main__':
    try:
        path=Path(sys.argv[1]);worker.require(path.stat().st_size<=65536,'REQUEST_TOO_LARGE')
        print(json.dumps(run(json.loads(path.read_text())),separators=(',',':')))
    except (worker.WorkerError,OSError,ValueError,KeyError,TypeError,MemoryError) as error:
        print(json.dumps({'status':'failed','code':getattr(error,'code',type(error).__name__),'providerCalls':0}))
        sys.exit(1)
