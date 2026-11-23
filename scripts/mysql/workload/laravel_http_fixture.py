"""Disposable synthetic Laravel/FPM HTTP proof. Runs only inside its local fixture."""
from __future__ import annotations

import argparse
import base64
import hashlib
import importlib.util
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import socket
import socketserver
import stat
import struct
import subprocess
import sys
import tarfile
import threading
import time


def load_module(name, filename):
    spec = importlib.util.spec_from_file_location(name, Path(__file__).with_name(filename))
    result = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(result)
    return result


transport = load_module('real_laravel_http_transport', 'http_execute.py')
contract = transport.profile_tools
need = contract.need
PHASE = 'startup'


def sha(path):
    result = hashlib.sha256()
    with path.open('rb') as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b''): result.update(chunk)
    return result.hexdigest()


def private_json(path, value):
    with path.open('xb') as stream:
        os.chmod(path, 0o600)
        stream.write(contract.canonical(value))


def environment(baseline):
    return {'PATH': '/usr/local/bin:/usr/bin:/bin', 'HOME': '/runs', 'TMPDIR': '/tmp', 'LANG': 'C.UTF-8',
            'APP_ENV': 'testing', 'REPRO_HTTP_PARENT_NET': baseline, 'TZ': 'UTC'}


def php(mode, config, env):
    child = subprocess.run(['/usr/local/bin/php', '-d', 'memory_limit=384M', '-d', 'display_errors=0',
                            '/fixture-tools/laravel-http-fixture.php', mode], input=contract.canonical(config),
                           env=env, cwd='/workspace', capture_output=True, timeout=180)
    need(len(child.stdout) <= 65536 and len(child.stderr) <= 65536, 'php-output-budget')
    try:
        result = json.loads(child.stdout)
    except (ValueError, UnicodeError):
        raise contract.Refusal('php-fixture-invalid-result')
    if child.returncode != 0 or result.get('result') == 'refused':
        # The reviewed PHP wrapper returns only fixed phase/class/source metadata.
        print(json.dumps({'scope': 'synthetic-laravel-http', 'php_refusal': result}), flush=True)
        raise contract.Refusal('php-fixture-refused')
    return result


def extract(archive, expected, root, *, vendor_only=False):
    need(sha(archive) == expected, 'archive-digest')
    with tarfile.open(archive) as bundle:
        members = bundle.getmembers()
        need(len(members) < 100000 and sum(member.size for member in members) < 600 * 1024 * 1024, 'archive-budget')
        for member in members:
            path = PurePosixPath(member.name)
            need(not path.is_absolute() and '..' not in path.parts and
                 (member.isdir() or member.isfile()), 'archive-entry')
            need('.env' not in path.parts and not member.name.startswith('.git/'), 'archive-environment')
            if vendor_only:
                need(bool(path.parts) and path.parts[0] == 'vendor', 'dependency-archive-scope')
    result = subprocess.run(['/usr/bin/tar', '--no-same-owner', '-xf', str(archive), '-C', str(root)], capture_output=True, timeout=120)
    need(result.returncode == 0, 'archive-extraction')


def runtime(path):
    path.mkdir(mode=0o700)
    for name in ('cache', 'storage/logs/scheduling', 'storage/app/private', 'storage/framework/cache/data',
                 'storage/framework/sessions', 'storage/framework/views'):
        (path / name).mkdir(parents=True, mode=0o700)


def fcgi_record(kind, data=b''):
    need(len(data) <= 65535, 'fastcgi-record-budget')
    return struct.pack('!BBHHBB', 1, kind, 1, len(data), 0, 0) + data


def fcgi_parameters(values):
    result = bytearray()
    for name, value in values.items():
        name, value = name.encode('ascii'), value.encode('ascii')
        for length in (len(name), len(value)):
            result.extend(bytes([length]) if length < 128 else struct.pack('!I', length | 0x80000000))
        result.extend(name); result.extend(value)
    return bytes(result)


def exact(stream, count):
    data = bytearray()
    while len(data) < count:
        chunk = stream.recv(count - len(data))
        need(bool(chunk), 'incomplete-fastcgi')
        data.extend(chunk)
    return bytes(data)


def fastcgi(path, pid, params):
    with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as stream:
        stream.settimeout(20)
        stream.connect(str(path))
        peer_pid, peer_uid, _gid = struct.unpack('3i', stream.getsockopt(socket.SOL_SOCKET, socket.SO_PEERCRED, 12))
        need(peer_pid == pid and peer_uid == os.geteuid(), 'fpm-peer-identity')
        stream.sendall(fcgi_record(1, struct.pack('!HB5x', 1, 0)) + fcgi_record(4, fcgi_parameters(params)) + fcgi_record(4) + fcgi_record(5))
        output = bytearray()
        while True:
            version, kind, request_id, length, padding, _reserved = struct.unpack('!BBHHBB', exact(stream, 8))
            need(version == 1 and request_id == 1, 'fastcgi-response-identity')
            data = exact(stream, length)
            if padding: exact(stream, padding)
            if kind == 6:
                output.extend(data)
                need(len(output) <= transport.MAX_BODY + transport.MAX_HEADERS, 'fastcgi-output-budget')
            elif kind == 7:
                need(not data, 'fpm-stderr-refused')
            elif kind == 3:
                need(len(data) == 8 and data[4] == 0, 'fpm-protocol-failed')
                need(data[:4] == bytes(4), 'fpm-application-status-' + str(int.from_bytes(data[:4], 'big')))
                break
            else:
                raise contract.Refusal('unsupported-fastcgi-record')
    need(b'\r\n\r\n' in output, 'fpm-http-headers')
    head, body = bytes(output).split(b'\r\n\r\n', 1)
    lines = head.decode('ascii').split('\r\n')
    status_line = next((line[8:] for line in lines if line.lower().startswith('status: ')), '200 OK')
    headers = [line for line in lines if not line.lower().startswith('status: ')]
    if not any(line.lower().startswith('content-length:') for line in headers): headers.append('Content-Length: ' + str(len(body)))
    # Response bytes and application headers are retained, never normalized here.
    return ('HTTP/1.0 ' + status_line + '\r\n' + '\r\n'.join(headers) + '\r\n\r\n').encode('ascii') + body


class Proxy(socketserver.ThreadingMixIn, socketserver.UnixStreamServer):
    daemon_threads = True
    block_on_close = False

    def handle_error(self, _request, _address):
        self.failures.append({'reason': 'proxy-handler-failed'})


class Handler(socketserver.BaseRequestHandler):
    def handle(self):
        self.request.settimeout(20)
        try:
            wire = bytearray()
            while b'\r\n\r\n' not in wire:
                block = self.request.recv(4096)
                need(bool(block), 'empty-http-request')
                wire.extend(block)
                need(len(wire) <= transport.MAX_HEADERS, 'http-request-budget')
            lines = bytes(wire).decode('ascii').split('\r\n')
            need(lines[0] == 'GET /api/notifications HTTP/1.0', 'fixed-request-route')
            headers = {}
            for line in lines[1:]:
                if not line: continue
                name, value = line.split(':', 1)
                name = name.lower()
                need(name not in headers, 'duplicate-request-header')
                headers[name] = value.strip()
            allowed = {'host', 'accept', 'accept-encoding', 'authorization'} | set(transport.metric_headers(
                self.server.client_config, self.server.schedule, {'sequence': 0}, '0' * 32))
            need(set(headers) <= allowed, 'unexpected-request-header')
            params = {'GATEWAY_INTERFACE': 'CGI/1.1', 'SERVER_PROTOCOL': 'HTTP/1.0', 'REQUEST_METHOD': 'GET',
                      'REQUEST_URI': '/api/notifications', 'QUERY_STRING': '', 'SCRIPT_NAME': '/index.php',
                      'SCRIPT_FILENAME': '/fixture-tools/laravel-http-fixture.php', 'DOCUMENT_ROOT': '/workspace/public',
                      'SERVER_NAME': 'repro-http-workload.invalid', 'SERVER_PORT': '80', 'REMOTE_ADDR': '127.0.0.1',
                      'REMOTE_PORT': '12345', 'CONTENT_LENGTH': '0', 'REQUEST_SCHEME': 'http'}
            params.update({'HTTP_' + key.upper().replace('-', '_'): value for key, value in headers.items()})
            role = next((role for role, token in self.server.client_config['synthetic_authorization'].items()
                         if token == headers.get('authorization')), 'denied')
            params['HTTP_X_SYSTEM_SESSION_ID'] = 'synthetic-http-' + role
            result = fastcgi(self.server.fpm_socket, self.server.fpm_pid, params)
            status_code = int(result.split(b' ', 2)[1])
            if status_code >= 500:
                raw = result.split(b'\r\n\r\n', 1)[1]
                try:
                    safe = json.loads(raw)
                    if isinstance(safe, dict) and set(safe) <= {'result', 'phase', 'class', 'source', 'line', 'code', 'reason'}:
                        self.server.failures.append(safe)
                except ValueError:
                    pass
            self.server.statuses.append(status_code)
            self.request.sendall(result)
        except BaseException as error:
            self.server.failures.append({'reason': str(error) if isinstance(error, contract.Refusal) else type(error).__name__})
            try: self.request.sendall(b'HTTP/1.0 502 Fixture refused\r\nContent-Type: application/json\r\nContent-Length: 2\r\n\r\n{}')
            except OSError: pass


def run_round(profile, schedule, client, application, env, path):
    application['http_contract'] = {'profile-sha256': schedule['profile_sha256'], 'schedule-sha256': schedule['schedule_sha256'],
                                    'run-token': client['run_token'], 'round': client['round']}
    config_file = path / 'app-config.json'
    private_json(config_file, application)
    fpm_socket = path / 'fpm.sock'
    fpm_config = path / 'fpm.conf'
    fpm_config.write_text('[global]\ndaemonize=no\nerror_log=' + str(path / 'fpm.log') + '\n[fixture]\n'
                          'listen=' + str(fpm_socket) + '\nlisten.mode=0600\npm=static\npm.max_children=2\nclear_env=no\n'
                          'chdir=/workspace\nphp_admin_value[memory_limit]=192M\nphp_admin_flag[opcache.enable]=off\n'
                          'php_admin_flag[display_errors]=off\nphp_admin_flag[log_errors]=on\n'
                          'php_admin_value[error_log]=' + str(path / 'php-error.log') + '\n', encoding='utf-8')
    log = (path / 'fpm-launch.log').open('wb')
    fpm = subprocess.Popen(['/usr/local/sbin/php-fpm', '-F', '-y', str(fpm_config)],
                           env={**env, 'REPRO_HTTP_CONFIG': str(config_file)}, stdout=log, stderr=log)
    proxy = thread = None
    try:
        deadline = time.monotonic() + 10
        while not fpm_socket.exists():
            need(fpm.poll() is None and time.monotonic() < deadline, 'fpm-startup-failed')
            time.sleep(.05)
        http_socket = path / 'http.sock'
        proxy = Proxy(str(http_socket), Handler)
        os.chmod(http_socket, 0o600)
        info = http_socket.stat()
        client['socket'] = {'path': str(http_socket), 'device': info.st_dev, 'inode': info.st_ino, 'uid': os.geteuid(), 'pid': os.getpid()}
        proxy.fpm_socket, proxy.fpm_pid = fpm_socket, fpm.pid
        proxy.client_config, proxy.schedule = client, schedule
        proxy.failures, proxy.statuses = [], []
        thread = threading.Thread(target=proxy.serve_forever, kwargs={'poll_interval': .01}, daemon=True)
        thread.start()
        # A real invalid bearer goes through the same route/auth middleware.
        denied = json.loads(json.dumps(client))
        denied['synthetic_authorization'] = {role: 'Bearer repro-fixture-' + 'x' * 32 for role in client['synthetic_authorization']}
        active = transport.ActiveSockets()
        try:
            result = transport.request(profile, schedule, denied, schedule['operations'][0], time.monotonic_ns(), active)
        finally:
            active.cancel()
        need(result['status'] == 401 and result['outcome'] == 'http_error', 'real-auth-denial-required')
        output = path / 'round.json'
        command = ['/usr/bin/python3', '-B', '/fixture-tools/http_execute.py', 'run', '--profile', '/runs/profile.json',
                   '--schedule', '/runs/schedule.json', '--supervised-parent-net', env['REPRO_HTTP_PARENT_NET'], '--output', str(output)]
        executed = subprocess.run(command, input=contract.canonical(client), env=env, capture_output=True, timeout=90)
        if executed.returncode != 0:
            error_log = (path / 'php-error.log').read_text(errors='replace') if (path / 'php-error.log').exists() else ''
            facts = {'memory_limit_hit': 'Allowed memory size' in error_log,
                     'fixture_lines': re.findall(r'laravel-http-fixture.php(?: on line |:)([0-9]+)', error_log),
                     'undefined_method': re.findall(r'Call to undefined method ([A-Za-z0-9_\\:]+)', error_log)}
            print(json.dumps({'scope': 'synthetic-laravel-http', 'proxy_failures': proxy.failures[:4], 'php_error_facts': facts}), flush=True)
        need(executed.returncode == 0 and not proxy.failures, 'real-http-round-failed')
        fragment = json.loads(output.read_bytes())
        need(all(item['status'] == 200 and item['outcome'] == 'ok' and item['query_count'] > 0 and
                 item['database_warning_count'] == 0 for item in fragment['run']['samples']), 'real-http-samples-failed')
        verified = php('verify', application, env)
        expected = len(schedule['operations'])
        need(verified['counts']['system_overview_request_traces'] == expected and verified['counts']['system_overview_sessions'] == 2
             and verified['counts']['system_overview_error_events'] == 0 and verified['owner_state_preserved']
             and all(verified['counts'][name] == 0 for name in ('jobs', 'messages', 'voice_calls')), 'persisted-request-effects')
        logs = list((path / 'storage/logs').glob('laravel*.log'))
        broadcasts = sum(item.read_bytes().count(b'SystemOverviewActivity') for item in logs)
        need(broadcasts >= expected, 'local-broadcast-proof-required')
        return fragment, {**verified, 'unauthenticated_status': 401, 'local_broadcast_records': broadcasts}
    finally:
        if proxy is not None: proxy.shutdown(); proxy.server_close()
        if thread is not None: thread.join(2)
        fpm.terminate()
        try: fpm.wait(timeout=10)
        except subprocess.TimeoutExpired: fpm.kill(); fpm.wait(timeout=5)
        log.close()
        if config_file.exists(): config_file.unlink()


def main():
    global PHASE
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--release', required=True)
    parser.add_argument('--baseline-net', required=True)
    args = parser.parse_args()
    os.umask(0o077)
    transport.isolation.namespace_check(args.baseline_net)
    transport.isolation.supervised_status_check()
    raw = sys.stdin.buffer.read(65537)
    need(len(raw) <= 65536, 'fixture-input-budget')
    setup = json.loads(raw)
    env = environment(args.baseline_net)
    PHASE = 'candidate-archive'
    extract(Path('/source.tar'), setup['source_archive_sha256'], Path('/workspace'))
    PHASE = 'locked-vendor-archive'
    extract(Path('/vendor.tar.gz'), setup['vendor_archive_sha256'], Path('/workspace'), vendor_only=True)
    PHASE = 'source-and-dependency-verification'
    transport.isolation.validate_source(Path('/workspace'))
    lock = json.loads(Path('/workspace/composer.lock').read_bytes())['packages']
    installed = json.loads(Path('/workspace/vendor/composer/installed.json').read_bytes())['packages']
    actual = {item['name']: item for item in installed}
    need(len(lock) == len(actual) and all(item['name'] in actual and item['version'] == actual[item['name']]['version']
         and item.get('source', {}).get('reference') == actual[item['name']].get('source', {}).get('reference') for item in lock), 'locked-dependency-inventory')
    source_hash = transport.isolation.source_digest(Path('/workspace'))
    source_token = os.urandom(32).hex()
    tokens = {str(number): 'repro-fixture-' + os.urandom(24).hex() for number in (1, 2)}
    app_key = 'base64:' + base64.b64encode(os.urandom(32)).decode()
    password = subprocess.check_output(['/usr/local/bin/php', '-r', "echo password_hash('unused-synthetic-password', PASSWORD_BCRYPT);"], env=env).decode()

    def config(path, engine, admin=False):
        return {'scope': 'synthetic-laravel-http-v1', 'runtime': str(path), 'sqlite': str(path / 'database.sqlite'),
                'engine': engine, 'database': setup['admin'] if admin else setup['app'], 'monitor': setup['monitor'],
                'uuid': setup['uuid'], 'owner_token': source_token, 'tokens': tokens, 'password_hash': password,
                'app_key': app_key, 'sqlite_source_sha256': snapshot_hash if 'snapshot_hash' in locals() else '0' * 64}

    base = Path('/runs/base-sqlite'); runtime(base)
    PHASE = 'sqlite-baseline'
    base_config = config(base, 'sqlite', True)
    php('migrate', base_config, env); php('seed', base_config, env)
    snapshot_hash = sha(base / 'database.sqlite')
    manifest = {'version': 1, 'seed_receipt_sha256': contract.digest({'source': source_hash, 'snapshot': snapshot_hash,
                'users': [1, 2], 'route': '/api/notifications', 'telemetry': 'enabled', 'broadcast': 'log'}), 'routes': {
        'owner-' + str(number): {'role': 'owner-' + str(number), 'fixture': 'owner-' + str(number), 'method': 'GET',
                                'path': '/api/notifications', 'query': {}} for number in (1, 2)}}
    response_contract = {'version': 1, 'routes': {label: {'kind': 'exact-json-numeric-lexemes-v1'} for label in manifest['routes']}}
    profile = {'version': 1, 'scope': 'isolated-http-read-workload', 'release_sha': args.release,
               'app_source_sha256': source_hash, 'source_snapshot_sha256': snapshot_hash,
               'host_profile_sha256': contract.digest({'fpm_children': 2, 'fpm_memory_mib': 192, 'opcache': False,
                   'storage': 'tmpfs', 'cache': 'array', 'telemetry': True, 'broadcast': 'log', 'server_monitor': False}),
               'fixture_manifest_sha256': contract.digest(manifest), 'response_contract_sha256': contract.digest(response_contract),
               'mysql_server_uuid': setup['uuid'], 'logical_time_utc': '2026-11-01T00:00:00Z',
               'max_in_flight': 2, 'request_timeout_ms': 20000,
               'routes': [{'label': label, 'method': 'GET', 'path_template': '/api/notifications', 'role': label,
                           'fixture': label, 'weight': 1, 'expected_status': 200} for label in manifest['routes']],
               'phases': [{'name': name, 'duration_ms': 1000, 'arrival_rate_milli_rps': rate}
                          for name, rate in (('warmup', 2000), ('peak', 4000), ('surge', 8000))]}
    schedule = contract.plan(profile)
    private_json(Path('/runs/profile.json'), profile); private_json(Path('/runs/schedule.json'), schedule)
    fragments = {'sqlite': [], 'mysql': []}; checks = []; prepared_mysql = False; evidence_hashes = {}
    for number, order in enumerate(contract.ORDERS, 1):
        for engine in order:
            PHASE = 'round-' + str(number) + '-' + engine
            path = Path('/runs/round-' + str(number) + '-' + engine); runtime(path)
            application = config(path, engine)
            application['sqlite_source_sha256'] = snapshot_hash
            if engine == 'sqlite':
                shutil.copyfile(base / 'database.sqlite', path / 'database.sqlite')
            else:
                administrator = {**application, 'database': setup['admin']}
                if prepared_mysql: php('reset', administrator, env)
                php('migrate', administrator, env); php('seed', administrator, env)
                php('warning-probe', application, env)
                prepared_mysql = True
            client = {'version': 1, 'profile_sha256': schedule['profile_sha256'], 'schedule_sha256': schedule['schedule_sha256'],
                      'engine': engine, 'round': number, 'database_identity': snapshot_hash if engine == 'sqlite' else setup['uuid'],
                      'server_kind': 'reviewed-http-instrumentation', 'run_token': os.urandom(32).hex(), 'socket': {},
                      'fixture_manifest': manifest, 'response_contract': response_contract,
                      'synthetic_authorization': {'owner-' + key: 'Bearer ' + value for key, value in tokens.items()}}
            fragment, verified = run_round(profile, schedule, client, application, env, path)
            fragments[engine].append(fragment)
            evidence_name = engine + '-round-' + str(number) + '.json'
            private_json(Path('/evidence') / evidence_name, fragment)
            evidence_hashes[evidence_name] = contract.digest(fragment)
            checks.append({'round': number, 'engine': engine, **verified})
            print(json.dumps({'scope': 'synthetic-laravel-http', 'round': number, 'engine': engine,
                              'requests': len(schedule['operations']), 'result': 'passed'}), flush=True)
    receipts = {engine: transport.assemble(profile, *values) for engine, values in fragments.items()}
    for engine, receipt in receipts.items():
        evidence_name = engine + '-receipt.json'
        private_json(Path('/evidence') / evidence_name, receipt)
        evidence_hashes[evidence_name] = contract.digest(receipt)
    comparison = contract.compare(profile, receipts['sqlite'], receipts['mysql'])
    need(comparison['result'] == 'comparable', 'paired-response-parity')
    need(transport.isolation.source_digest(Path('/workspace')) == source_hash, 'application-source-changed')
    result = {'version': 1, 'scope': 'local-synthetic-laravel-fpm-http', 'result': 'passed', 'release_sha': args.release,
              'source_sha256': source_hash, 'dependency_archive_sha256': setup['vendor_archive_sha256'],
              'source_archive_sha256': setup['source_archive_sha256'], 'comparison': comparison, 'checks': checks,
              'execution_evidence_sha256': evidence_hashes,
              'database_warning_injection_detected': True, 'fixed_clock_applied': False,
              'real_auth_and_existing_route_middleware': True, 'telemetry_writes_enabled': True, 'broadcast_driver': 'log',
              'normalization': 'all-fields-exact-json-preserving-numeric-lexemes',
              'query_metric_scope': 'default Laravel connection during handle and terminate; raw transaction control excluded',
              'warning_metric_scope': 'separate MySQL performance_schema thread history; SQLite has no warning channel',
              'timing_scope': 'Unix HTTP proxy plus FPM bootstrap, real request and termination, instrumentation; opcache disabled',
              'production_capacity_tested': False, 'copied_customer_data_tested': False, 'production_readiness': False}
    private_json(Path('/evidence/result.json'), result)
    for name in ('profile.json', 'schedule.json'): shutil.copyfile(Path('/runs') / name, Path('/evidence') / name)
    print(json.dumps({'scope': result['scope'], 'result': 'passed', 'report_sha256': contract.digest(result),
                      'total_measured_and_warmup_gets': 4 * len(schedule['operations']), 'denial_gets': 4,
                      'production_readiness': False}), flush=True)


if __name__ == '__main__':
    try:
        main()
    except BaseException as error:
        print(json.dumps({'scope': 'local-synthetic-laravel-fpm-http', 'result': 'refused',
                          'reason': str(error) if isinstance(error, contract.Refusal) else type(error).__name__,
                          'phase': PHASE, 'errno': error.errno if isinstance(error, OSError) else None,
                          'file': Path(error.filename).name if isinstance(error, OSError) and isinstance(error.filename, str) else None,
                          'production_readiness': False}), flush=True)
        sys.exit(1)
