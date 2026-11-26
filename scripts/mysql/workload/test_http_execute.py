"""Portable contract tests and an inert real Linux AF_UNIX HTTP fixture."""
import copy
import importlib.util
import json
import os
from pathlib import Path
import re
import socket
import socketserver
import stat
import subprocess
import sys
import tempfile
import threading
import time
import unittest
from unittest.mock import patch


def module(name, filename):
    spec = importlib.util.spec_from_file_location(name, Path(__file__).with_name(filename))
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


executor = module("http_executor_under_test", "http_execute.py")
helpers = module("http_executor_profile_fixture", "test_http_profile.py")


def configuration(path="/private/http.sock", uid=1000):
    profile = helpers.profile()
    for phase in profile["phases"]:
        phase.update(duration_ms=20, arrival_rate_milli_rps=200_000)
    manifest = {"version": 1, "seed_receipt_sha256": "1" * 64, "routes": {
        route["label"]: {"role": route["role"], "fixture": route["fixture"], "method": "GET",
                         "path": route["path_template"].replace("{item}", "synthetic"), "query": {}}
        for route in profile["routes"]}}
    contract = {"version": 1, "routes": {route["label"]: {"kind": "exact-json-numeric-lexemes-v1"}
                                         for route in profile["routes"]}}
    profile["fixture_manifest_sha256"] = executor.profile_tools.digest(manifest)
    profile["response_contract_sha256"] = executor.profile_tools.digest(contract)
    schedule = executor.profile_tools.plan(profile)
    config = {
        "version": 1, "engine": "sqlite", "round": 1, "profile_sha256": schedule["profile_sha256"],
        "schedule_sha256": schedule["schedule_sha256"], "database_identity": profile["source_snapshot_sha256"],
        "server_kind": "inert-http-fixture", "socket": {"path": path, "device": 1, "inode": 2, "uid": uid, "pid": 1},
        "run_token": "4" * 64, "fixture_manifest": manifest, "response_contract": contract,
        "synthetic_authorization": {role: "Bearer repro-fixture-" + ("a" if role == "staff" else "b") * 32
                                    for role in {route["role"] for route in profile["routes"]}},
    }
    return profile, schedule, config


class ExecutorContractTest(unittest.TestCase):
    def test_exact_json_keeps_arrays_fields_and_numeric_precision(self):
        first = b'{"b":[2,1],"a":9007199254740993,"decimal":1.00000000000000001}'
        same = b'{"decimal":1.00000000000000001,"a":9007199254740993,"b":[2,1]}'
        self.assertEqual(executor.response_digest(first), executor.response_digest(same))
        for changed in (first.replace(b'[2,1]', b'[1,2]'), first.replace(b'993', b'992'),
                        first.replace(b'00001', b'00002'), first.replace(b'"a":', b'"different":')):
            self.assertNotEqual(executor.response_digest(first), executor.response_digest(changed))
        for invalid in (b'{"a":1,"a":2}', b'{"a":NaN}', b'{"a":Infinity}', b'not-json', b'\xff'):
            with self.assertRaises(executor.Refusal): executor.response_digest(invalid)
        with self.assertRaises(executor.Refusal): executor.response_digest(b'[' * 70 + b'0' + b']' * 70)

    def test_fixed_bindings_and_no_arbitrary_normalization_or_credentials(self):
        profile, schedule, config = configuration()
        with patch.object(executor.os, "geteuid", return_value=1000, create=True):
            executor.validate_input(profile, schedule, config)
            for change in ("url", "query-injection", "role", "method", "normalization", "real-token", "target"):
                with self.subTest(change=change):
                    data = copy.deepcopy(config)
                    if change == "url": data["fixture_manifest"]["routes"]["common"]["path"] = "https://example.com"
                    if change == "query-injection": data["fixture_manifest"]["routes"]["common"]["query"] = {"page": "1\r\nHost: bad"}
                    if change == "role": data["fixture_manifest"]["routes"]["common"]["role"] = "other"
                    if change == "method": data["fixture_manifest"]["routes"]["common"]["method"] = "POST"
                    if change == "normalization": data["response_contract"]["routes"]["common"]["ignore"] = ["money"]
                    if change == "real-token": data["synthetic_authorization"]["staff"] = "Bearer not-a-synthetic-token"
                    if change == "target": data["database_identity"] = "0" * 64
                    # Rebind the fixture hashes so semantic checks are exercised.
                    policy = copy.deepcopy(profile)
                    policy["fixture_manifest_sha256"] = executor.profile_tools.digest(data["fixture_manifest"])
                    policy["response_contract_sha256"] = executor.profile_tools.digest(data["response_contract"])
                    planned = executor.profile_tools.plan(policy)
                    data.update(profile_sha256=planned["profile_sha256"], schedule_sha256=planned["schedule_sha256"])
                    with self.assertRaises(executor.Refusal): executor.validate_input(policy, planned, data)

    def test_redirect_hop_headers_duplicates_and_non_json_refused(self):
        good = b'HTTP/1.0 200 OK\r\nContent-Type: application/json\r\nContent-Length: 2'
        self.assertEqual(200, executor.parse_headers(good)[0])
        self.assertEqual('close', executor.parse_headers(good + b'\r\nConnection: close')[1]['connection'])
        bad = [good.replace(b'200', b'302'), good.replace(b'application/json', b'text/html'),
               good + b'\r\nContent-Length: 2', good + b'\r\n folded: yes',
               good + b'\r\nConnection: close, upgrade', good + b'\r\nConnection: close\r\nConnection: close']
        bad.extend(good + b'\r\n' + name.encode() + b': forbidden' for name in executor.HOP_HEADERS)
        for value in bad:
            with self.subTest(value=value), self.assertRaises(executor.Refusal): executor.parse_headers(value)

    def test_assembly_requires_same_executor_target_and_two_different_round_numbers(self):
        profile, _schedule, _config = configuration()
        receipts = helpers.receipt(profile, "sqlite")
        fragments = []
        for run in receipts["runs"]:
            fragments.append({**{key: value for key, value in receipts.items() if key != "runs"},
                              "scope": "isolated-http-round", "server_kind": "inert-http-fixture", "run": run,
                              "isolation": {}, "transport": "http-over-pinned-af-unix",
                              "application_execution_verified": False, "database_metrics_independently_verified": False,
                              "production_readiness": False})
        self.assertEqual(receipts, executor.assemble(profile, *fragments))
        for change in ("round", "target", "executor", "claim"):
            data = copy.deepcopy(fragments)
            if change == "round": data[1]["run"]["round"] = 1
            if change == "target": data[1]["database_identity"] = "0" * 64
            if change == "executor": data[1]["executor_sha256"] = "1" * 64
            if change == "claim": data[1]["production_readiness"] = True
            with self.subTest(change=change), self.assertRaises(executor.Refusal): executor.assemble(profile, *data)

    def test_live_webroot_is_refused_without_reading_its_metadata(self):
        with self.assertRaisesRegex(executor.Refusal, 'live-webroot-refused'):
            executor.private_parent(Path('/var/www/backend/private/http.sock'))


if hasattr(socketserver, "UnixStreamServer"):
    class InertServer(socketserver.ThreadingMixIn, socketserver.UnixStreamServer):
        daemon_threads = True
        block_on_close = False

        def handle_error(self, _request, _address):
            self.handler_failed = True

    class InertHandler(socketserver.BaseRequestHandler):
        def handle(self):
            self.request.settimeout(2)
            raw = bytearray()
            while b'\r\n\r\n' not in raw:
                block = self.request.recv(4096)
                if not block: return
                raw.extend(block)
                if len(raw) > executor.MAX_HEADERS: return
            lines = bytes(raw).decode('ascii').split('\r\n')
            method, target, protocol = lines[0].split(' ')
            headers = dict((key.lower(), value.strip()) for key, value in
                           (line.split(':', 1) for line in lines[1:] if line))
            with self.server.record_lock:
                self.server.sequences.append(int(headers['x-repro-sequence']))
            config = self.server.config
            allowed = {bound['path'] for bound in config['fixture_manifest']['routes'].values()}
            if method != 'GET' or protocol != 'HTTP/1.0' or target not in allowed:
                raise AssertionError('Unexpected fixture request')
            if headers['authorization'] not in config['synthetic_authorization'].values():
                raise AssertionError('Invalid synthetic fixture authorization')
            if headers['x-repro-run-token'] != config['run_token']:
                raise AssertionError('Invalid fixture run binding')
            bound_headers = {key: value for key, value in headers.items() if key.startswith('x-repro-')}
            # The inert process performs no database work: these zero counters
            # describe this fixture only, not instrumentation of a real app.
            bound_headers.update({'x-repro-query-count': '0', 'x-repro-database-warning-count': '0'})
            fault = self.server.fault
            if fault == 'missing-metric': del bound_headers['x-repro-query-count']
            if fault == 'wrong-nonce': bound_headers['x-repro-request-nonce'] = '0' * 32
            if fault == 'wrong-engine': bound_headers['x-repro-engine'] = 'different'
            if fault == 'warning': bound_headers['x-repro-database-warning-count'] = '1'
            if fault == 'hop-header': bound_headers['connection'] = 'keep-alive'
            if fault in ('close', 'eof'): bound_headers['connection'] = 'close'
            if fault == 'oversize-header': bound_headers['x-large'] = 'x' * executor.MAX_HEADERS
            body = b'{"data":{"items":[1,2],"amount":1.00000000000000001},"synthetic":true}'
            if fault == 'different-body': body = body.replace(b'[1,2]', b'[2,1]')
            if fault == 'bad-json': body = b'{"same":1,"same":2}'
            if fault == 'oversize-body': body = b'x' * (executor.MAX_BODY + 1)
            code = 302 if fault == 'redirect' else (503 if fault == 'http-error' else 200)
            response = 'HTTP/1.0 ' + str(code) + ' Fixture\r\nContent-Type: application/json\r\n'
            if fault != 'eof': response += 'Content-Length: ' + str(len(body) + (1 if fault == 'truncated' else 0)) + '\r\n'
            response += ''.join(key + ': ' + value + '\r\n' for key, value in bound_headers.items()) + '\r\n'
            if fault == 'slow': time.sleep(.15)
            if self.server.delay: time.sleep(self.server.delay)
            try:
                self.request.sendall(response.encode('ascii') + body)
            except OSError:
                pass  # Expected when a refusal cancels other active requests.


LINUX_PEERS = sys.platform == 'linux' and hasattr(socket, 'SO_PEERCRED') and os.geteuid() != 0


@unittest.skipUnless(LINUX_PEERS, 'Real private AF_UNIX peer tests require unprivileged Linux')
class RealUnixHttpTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='repro-http-')
        self.base = Path(self.temp.name)
        self.path = self.base / 'http.sock'
        self.profile, self.schedule, self.config = configuration(str(self.path), os.geteuid())
        self.server = InertServer(str(self.path), InertHandler)
        os.chmod(self.path, 0o600)
        item = self.path.stat()
        self.config['socket'].update(device=item.st_dev, inode=item.st_ino, pid=os.getpid())
        self.server.config = self.config
        self.server.fault = None
        self.server.delay = 0
        self.server.sequences = []
        self.server.handler_failed = False
        self.server.record_lock = threading.Lock()
        self.thread = threading.Thread(target=self.server.serve_forever, kwargs={'poll_interval': .01}, daemon=True)
        self.thread.start()

    def tearDown(self):
        self.server.shutdown()
        self.server.server_close()
        self.thread.join(2)
        self.temp.cleanup()

    def run_round(self):
        return executor.execute(self.profile, self.schedule, self.config, {'fixture_direct_transport_only': True})

    def test_real_gets_timings_hashes_and_no_replays(self):
        self.server.delay = .01
        result = self.run_round()
        samples = result['run']['samples']
        self.assertEqual(list(range(len(self.schedule['operations']))), sorted(self.server.sequences))
        self.assertTrue(all(sample['query_count'] == sample['database_warning_count'] == 0 for sample in samples))
        self.assertTrue(all(sample['completed_us'] > sample['started_us'] for sample in samples))
        self.assertEqual(1, len({sample['logical_result_sha256'] for sample in samples}))
        self.assertFalse(self.server.handler_failed)
        self.assertFalse(result['application_execution_verified'])
        self.assertEqual('inert-http-fixture', result['server_kind'])

    def test_peer_pid_rejected_before_sending_request(self):
        self.config['socket']['pid'] += 1_000_000
        with self.assertRaisesRegex(executor.Refusal, 'http-peer-identity'): self.run_round()
        self.assertEqual([], self.server.sequences)

    def test_real_explicit_connection_close_and_eof_framing(self):
        for fault in ('close', 'eof'):
            with self.subTest(fault=fault):
                self.server.fault = fault
                self.server.sequences = []
                result = self.run_round()
                self.assertEqual(12, len(result['run']['samples']))
                self.assertEqual(list(range(12)), sorted(self.server.sequences))

    def test_socket_mode_symlink_and_replacement_are_refused(self):
        os.chmod(self.path, 0o660)
        with self.assertRaises(executor.Refusal): self.run_round()
        os.chmod(self.path, 0o600)
        alias = self.base / 'alias.sock'
        alias.symlink_to(self.path)
        self.config['socket']['path'] = str(alias)
        with self.assertRaises(executor.Refusal): self.run_round()
        self.config['socket']['path'] = str(self.path)
        self.path.rename(self.base / 'old.sock')
        replacement = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        try:
            replacement.bind(str(self.path)); os.chmod(self.path, 0o600)
            with self.assertRaisesRegex(executor.Refusal, 'socket-identity-or-mode'): self.run_round()
        finally:
            replacement.close()
        self.assertEqual([], self.server.sequences)

    def test_real_missing_unbound_malformed_and_redirect_responses_abort(self):
        for fault in ('missing-metric', 'wrong-nonce', 'wrong-engine', 'redirect', 'hop-header',
                      'oversize-header', 'oversize-body', 'truncated', 'bad-json'):
            with self.subTest(fault=fault):
                self.server.fault = fault
                self.server.sequences = []
                with self.assertRaises(executor.Refusal): self.run_round()
                self.assertEqual(len(self.server.sequences), len(set(self.server.sequences)))

    def test_real_absolute_timeout_is_bounded_and_not_retried(self):
        self.profile['request_timeout_ms'] = 30
        self.schedule = executor.profile_tools.plan(self.profile)
        self.config.update(profile_sha256=self.schedule['profile_sha256'], schedule_sha256=self.schedule['schedule_sha256'])
        self.server.fault = 'slow'
        started = time.monotonic()
        with self.assertRaisesRegex(executor.Refusal, 'http-deadline'): self.run_round()
        self.assertLess(time.monotonic() - started, .5)
        self.assertEqual(len(self.server.sequences), len(set(self.server.sequences)))

    @unittest.skipUnless(os.environ.get('REPRO_HTTP_TEST_BASELINE_NET'), 'CLI isolation proof needs a supervisor-observed distinct namespace')
    def test_cli_real_isolation_four_rounds_assembly_and_comparison(self):
        profile_path = self.base / 'profile.json'
        schedule_path = self.base / 'schedule.json'
        profile_path.write_text(json.dumps(self.profile), encoding='utf-8')
        schedule_path.write_text(json.dumps(self.schedule), encoding='utf-8')
        script = str(Path(__file__).with_name('http_execute.py'))
        fragments = {'sqlite': [], 'mysql': []}
        for number, order in enumerate(executor.profile_tools.ORDERS, 1):
            for engine in order:
                self.config.update(engine=engine, round=number,
                                   database_identity=self.profile['source_snapshot_sha256'] if engine == 'sqlite' else self.profile['mysql_server_uuid'])
                output = self.base / (engine + '-' + str(number) + '.json')
                command = [sys.executable, '-B', script, 'run', '--profile', str(profile_path), '--schedule', str(schedule_path),
                           '--supervised-parent-net', os.environ['REPRO_HTTP_TEST_BASELINE_NET'], '--output', str(output)]
                result = subprocess.run(command, input=json.dumps(self.config).encode(), capture_output=True, timeout=10)
                self.assertEqual(0, result.returncode, result.stdout.decode() + result.stderr.decode())
                self.assertNotIn(self.config['run_token'].encode(), result.stdout + result.stderr)
                self.assertNotIn(b'Bearer repro-fixture-', result.stdout + result.stderr)
                item = json.loads(output.read_bytes())
                self.assertTrue(item['isolation']['new_network_namespace'])
                self.assertTrue(item['isolation']['capability_sets_empty'])
                self.assertTrue(item['isolation']['no_new_privileges'])
                self.assertEqual(0o600, stat.S_IMODE(output.stat().st_mode))
                fragments[engine].append(output)
        receipts = {}
        for engine, paths in fragments.items():
            output = self.base / (engine + '-receipt.json')
            command = [sys.executable, '-B', script, 'assemble', '--profile', str(profile_path),
                       '--round-one', str(paths[0]), '--round-two', str(paths[1]), '--output', str(output)]
            result = subprocess.run(command, capture_output=True, timeout=10)
            self.assertEqual(0, result.returncode, result.stdout.decode() + result.stderr.decode())
            receipts[engine] = json.loads(output.read_bytes())
        result = executor.profile_tools.compare(self.profile, receipts['sqlite'], receipts['mysql'])
        self.assertEqual('comparable', result['result'])
        self.assertFalse(result['production_readiness'])
        self.assertEqual(0, result['errors'])
        self.assertEqual(0, result['logical_result_mismatches'])
        self.assertFalse(self.server.handler_failed)


if __name__ == '__main__':
    unittest.main()
