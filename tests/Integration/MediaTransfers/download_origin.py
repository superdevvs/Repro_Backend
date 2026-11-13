"""Exercise the generated download vhost with real Nginx, without production files."""
import importlib.util
from pathlib import Path
import re
import subprocess
import tempfile
import urllib.error
import urllib.request

source = Path(__file__).resolve().parents[3]
spec = importlib.util.spec_from_file_location('download_origin', source / 'scripts/deploy/install-download-origin.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
with tempfile.TemporaryDirectory() as temporary:
    root = Path(temporary)
    vhost = module.https_config().replace('listen 80;', 'listen 18081;').replace('listen 443 ssl http2;', 'listen 18080;')
    vhost = re.sub(r'^\s*(ssl_|include /etc/letsencrypt/).*\n', '', vhost, flags=re.M)
    vhost = vhost.replace('include fastcgi_params;', 'include /etc/nginx/fastcgi_params;')
    vhost = vhost.replace('/var/log/nginx/repro-transfers.jsonl', str(root / 'transfers.log'))
    http = (source / 'scripts/deploy/media-transfers-http.nginx.conf').read_text()
    configuration = root / 'nginx.conf'
    configuration.write_text(f'pid {root}/nginx.pid;\nerror_log {root}/error.log;\nevents {{}}\nhttp {{\n{http}\n{vhost}\n}}')
    subprocess.run(['nginx', '-c', str(configuration), '-t'], check=True)
    subprocess.run(['nginx', '-c', str(configuration)], check=True)
    try:
        def request(method, path, origin='https://reprodashboard.com'):
            req = urllib.request.Request('http://127.0.0.1:18080' + path, method=method, headers={
                'Origin': origin, 'Access-Control-Request-Method': 'GET', 'Access-Control-Request-Headers': 'range,if-range'})
            try:
                return urllib.request.urlopen(req, timeout=5)
            except urllib.error.HTTPError as error:
                return error
        media = '/api/public/shoot-media/file/shoots/1/archive.zip'
        preflight = request('OPTIONS', media)
        assert preflight.status == 204, preflight.status
        assert preflight.headers['Access-Control-Allow-Origin'] == 'https://reprodashboard.com'
        assert 'If-Range' in preflight.headers['Access-Control-Allow-Headers']
        assert request('OPTIONS', media, 'https://unrelated.example').headers.get('Access-Control-Allow-Origin') is None
        assert request('POST', media).status == 403
        assert request('GET', '/_repro-media/private/secrets').status == 404
        assert request('GET', '/api/user').status == 404
        print('Download origin Nginx: preflight, origin restriction, method restriction and internal-path checks passed.')
    finally:
        subprocess.run(['nginx', '-c', str(configuration), '-s', 'quit'], check=True)
