"""Run inside the dedicated image. Exercises real Nginx/PHP-FPM with 20 workers."""
from concurrent.futures import ThreadPoolExecutor
import hashlib
import json
import os
from pathlib import Path
import statistics
import subprocess
import threading
import time
import urllib.error
import urllib.request

ROOT = Path('/tmp/repro-media-integration')
ROOT.mkdir(exist_ok=True)
for name in ('private', 'legacy', 'originals', 'downloads'):
    (ROOT / name).mkdir(exist_ok=True)
fixture = ROOT / 'private/shoots/991/web/été photo #1%.jpg'
fixture.parent.mkdir(parents=True, exist_ok=True)
payload = (bytes(range(256)) * 32768)  # 8 MiB: larger than the per-client socket buffer.
fixture.write_bytes(payload)
expected = hashlib.sha256(payload).hexdigest()
outside = ROOT / 'secret'
outside.write_text('must stay private')
escape = fixture.parent / 'escape.jpg'
if not escape.exists():
    escape.symlink_to(outside)

fpm = ROOT / 'php-fpm.conf'
fpm.write_text('''[global]
daemonize = no
error_log = /tmp/repro-media-integration/fpm.log
[www]
listen = 127.0.0.1:9000
user = www-data
group = www-data
pm = static
pm.max_children = 20
pm.status_path = /fpm-status
clear_env = no
catch_workers_output = yes
''')
http = Path('/app/scripts/deploy/media-transfers-http.nginx.conf').read_text()
locations = Path('/app/scripts/deploy/media-transfers-locations.nginx.conf').read_text()
locations = locations.replace('/var/www/backend/storage/app/private/downloads/', str(ROOT / 'downloads') + '/')
locations = locations.replace('/var/www/backend/storage/app/private/', str(ROOT / 'private') + '/')
locations = locations.replace('/var/www/backend/storage/app/public/', str(ROOT / 'legacy') + '/')
locations = locations.replace('/mnt/16tb/repro/media-originals/', str(ROOT / 'originals') + '/')
locations = locations.replace('/var/log/nginx/repro-transfers.jsonl', str(ROOT / 'transfers.jsonl'))
locations = locations.replace('/var/log/nginx/access.log', str(ROOT / 'access.log'))
configuration = ROOT / 'nginx.conf'
configuration.write_text('''user www-data;
worker_processes 2;
pid /tmp/repro-media-integration/nginx.pid;
error_log /tmp/repro-media-integration/nginx-error.log;
events { worker_connections 512; }
http {
    include /etc/nginx/mime.types;
    default_type application/octet-stream;
''' + http + '''
    server {
        listen 8080;
        server_name 127.0.0.1;
''' + locations + '''
        location = /fpm-status {
            include /etc/nginx/fastcgi_params;
            fastcgi_param SCRIPT_FILENAME /fpm-status;
            fastcgi_pass 127.0.0.1:9000;
        }
        location / {
            include /etc/nginx/fastcgi_params;
            fastcgi_param SCRIPT_FILENAME /app/tests/Integration/MediaTransfers/front.php;
            fastcgi_param SCRIPT_NAME /index.php;
            fastcgi_param HTTP_HOST $http_host;
            fastcgi_pass 127.0.0.1:9000;
        }
    }
}
''')
environment = dict(os.environ, APP_ENV='testing', APP_DEBUG='false', CACHE_STORE='array',
                   SESSION_DRIVER='array', QUEUE_CONNECTION='sync', MAIL_MAILER='array',
                   APP_URL='http://127.0.0.1:8080', LOG_CHANNEL='stderr')
urls = json.loads(subprocess.check_output(['php', '/app/tests/Integration/MediaTransfers/front.php'], env=environment))
subprocess.run(['nginx', '-t', '-c', str(configuration)], check=True)
workers = subprocess.Popen(['php-fpm', '-F', '-y', str(fpm)], env=environment)
web = subprocess.Popen(['nginx', '-c', str(configuration), '-g', 'daemon off;'])


def request(url, headers=None, method=None, data=None):
    query = urllib.request.Request(url, data=data, headers=headers or {}, method=method)
    try:
        with urllib.request.urlopen(query, timeout=30) as response:
            return response.status, response.headers, response.read()
    except urllib.error.HTTPError as response:
        return response.code, response.headers, response.read()


release = threading.Event()
opened = threading.Condition()
ready = 0


def slow_download(_):
    global ready
    with urllib.request.urlopen(urls['valid'], timeout=30) as response:
        digest = hashlib.sha256(response.read(65536))
        with opened:
            ready += 1
            opened.notify_all()
        # Holding consumption keeps large responses open while the API is probed.
        assert release.wait(30)
        while chunk := response.read(262144):
            digest.update(chunk)
        assert digest.hexdigest() == expected


try:
    for _ in range(100):
        try:
            if request('http://127.0.0.1:8080/__integration/ping')[0] == 200:
                break
        except (urllib.error.URLError, ConnectionError):
            pass
        time.sleep(0.1)
    assert request('http://127.0.0.1:8080/_repro-media/private/shoots/991/web/escape.jpg')[0] == 404
    assert request(urls['expired'])[0] == 403
    status, headers, body = request(urls['valid'])
    assert status == 200 and hashlib.sha256(body).hexdigest() == expected, (status, body[:200], headers.get('X-Integration-Url'), urls['valid'].split('?')[0])
    assert 'no-store' in headers['Cache-Control'] and 'X-Accel-Redirect' not in headers
    assert headers.get('X-Content-Type-Options') == 'nosniff'
    status, headers, body = request(urls['valid'], method='HEAD')
    assert status == 200 and len(body) == 0 and int(headers['Content-Length']) == len(payload)
    assert headers.get('X-Content-Type-Options') == 'nosniff'
    status, headers, body = request(urls['valid'], {'Range': 'bytes=123-456'})
    assert status == 206 and body == payload[123:457] and headers['Content-Range'] == f'bytes 123-456/{len(payload)}'
    assert headers.get('X-Content-Type-Options') == 'nosniff'
    status, headers, body = request(urls['valid'], {'Range': 'bytes=99999999-'})
    assert status == 416
    assert headers.get('X-Content-Type-Options') == 'nosniff'
    with ThreadPoolExecutor(max_workers=25) as pool:
        pending = [pool.submit(slow_download, index) for index in range(25)]
        with opened:
            assert opened.wait_for(lambda: ready == 25, timeout=25), (ready, [future.exception() for future in pending if future.done()])
        measurements = []
        for index in range(40):
            started = time.perf_counter()
            assert request('http://127.0.0.1:8080/__integration/ping')[0] == 200
            measurements.append(time.perf_counter() - started)
            if index % 8 == 0:
                uploaded = request('http://127.0.0.1:8080/__integration/upload', method='POST', data=b'u' * 65536)
                assert uploaded[0] == 200 and json.loads(uploaded[2])['received_bytes'] == 65536
        status = json.loads(request('http://127.0.0.1:8080/fpm-status?json')[2])
        p95 = sorted(measurements)[37]
        assert p95 < 1.0, p95
        assert status['max children reached'] == 0, status
        assert status['active processes'] < 20, status
        assert all(not future.done() for future in pending)
        release.set()
        for future in pending:
            future.result(timeout=30)
    time.sleep(0.2)
    records = (ROOT / 'transfers.jsonl').read_text()
    assert 'signature=' not in records and 'expires=' not in records and 'été' not in records
    parsed = [json.loads(line) for line in records.splitlines()]
    assert any(entry['status'] == 206 and entry['body_bytes'] == 334 for entry in parsed)
    print(json.dumps({'result': 'passed', 'downloads': 25, 'bytes_each': len(payload),
                      'api_probes': len(measurements), 'synthetic_upload_probes': 5,
                      'api_p95_seconds': p95, 'fpm_max_children_reached': status['max children reached'],
                      'active_php_during_downloads': status['active processes'],
                      'head_range_unicode_signatures_private_locations': 'passed',
                      'complete_download_sha256': expected}, indent=2))
finally:
    release.set()
    web.terminate()
    workers.terminate()
    web.wait(timeout=10)
    workers.wait(timeout=10)
