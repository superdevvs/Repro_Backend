"""Archive boundaries for the disposable real-application HTTP fixture."""
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import socketserver
import tarfile
import tempfile
import unittest
from unittest.mock import Mock, patch


if not hasattr(socketserver, 'UnixStreamServer'):
    raise unittest.SkipTest('The real fixture module requires Linux Unix sockets')

spec = importlib.util.spec_from_file_location('laravel_http_fixture', Path(__file__).with_name('laravel_http_fixture.py'))
fixture = importlib.util.module_from_spec(spec)
spec.loader.exec_module(fixture)
copied_fixture = fixture.load_module('copied_http_fixture_tests', 'copied_http_fixture.py')


class DependencyArchiveBoundaryTest(unittest.TestCase):
    def archive(self, directory, name, *, link=False):
        path = Path(directory) / 'dependency.tar'
        with tarfile.open(path, 'w') as archive:
            member = tarfile.TarInfo(name)
            if link:
                member.type = tarfile.SYMTYPE
                member.linkname = '../app'
                archive.addfile(member)
            else:
                payload = b'synthetic-content'
                member.size = len(payload)
                archive.addfile(member, io.BytesIO(payload))
        return path, hashlib.sha256(path.read_bytes()).hexdigest()

    def test_locked_vendor_entries_reach_extractor(self):
        with tempfile.TemporaryDirectory() as directory:
            archive, checksum = self.archive(directory, 'vendor/composer/installed.json')
            with patch.object(fixture.subprocess, 'run', return_value=Mock(returncode=0)) as run:
                fixture.extract(archive, checksum, Path(directory), vendor_only=True)
            run.assert_called_once()

    def test_dependency_cannot_replace_application_or_lockfile(self):
        for name in ('app/Http/Kernel.php', 'composer.lock', 'bootstrap/app.php', 'vendor/../app/Http/Kernel.php', '/vendor/file'):
            with self.subTest(name=name), tempfile.TemporaryDirectory() as directory:
                archive, checksum = self.archive(directory, name)
                with patch.object(fixture.subprocess, 'run') as run:
                    with self.assertRaises(fixture.contract.Refusal):
                        fixture.extract(archive, checksum, Path(directory), vendor_only=True)
                run.assert_not_called()

    def test_dependency_cannot_introduce_symlink_or_environment(self):
        for name, link in (('vendor/package', True), ('vendor/.env', False)):
            with self.subTest(name=name), tempfile.TemporaryDirectory() as directory:
                archive, checksum = self.archive(directory, name, link=link)
                with patch.object(fixture.subprocess, 'run') as run:
                    with self.assertRaises(fixture.contract.Refusal):
                        fixture.extract(archive, checksum, Path(directory), vendor_only=True)
                run.assert_not_called()

    def test_wrong_archive_digest_prevents_extraction(self):
        with tempfile.TemporaryDirectory() as directory:
            archive, _ = self.archive(directory, 'vendor/package/file.php')
            with patch.object(fixture.subprocess, 'run') as run:
                with self.assertRaises(fixture.contract.Refusal):
                    fixture.extract(archive, '0' * 64, Path(directory), vendor_only=True)
            run.assert_not_called()


class RehearsalBearerBoundaryTest(unittest.TestCase):
    def test_bridge_refuses_a_non_fixture_bearer_before_fastcgi(self):
        handler = fixture.Handler.__new__(fixture.Handler)
        handler.request = Mock()
        handler.request.recv.return_value = (
            b'GET /api/voice/incoming-offers HTTP/1.0\r\nAuthorization: Bearer unrelated-copied-token\r\n\r\n'
        )
        handler.server = Mock()
        handler.server.client_config = {}
        handler.server.request_paths = {fixture.VOICE_OFFERS}
        handler.server.authorizations = {'voice-operator': 'Bearer synthetic-token'}
        handler.server.schedule = {}
        with patch.object(fixture.transport, 'metric_headers', return_value={}), patch.object(fixture, 'fastcgi') as forward:
            handler.handle()
        forward.assert_not_called()
        self.assertTrue(handler.request.sendall.call_args.args[0].startswith(b'HTTP/1.0 403'))

    def test_only_synthetic_tokens_and_the_intentional_probe_have_an_actor(self):
        tokens = {'owner-1': 'Bearer synthetic-token'}
        self.assertEqual(fixture.request_actor(tokens['owner-1'], tokens), 'owner-1')
        self.assertEqual(fixture.request_actor(fixture.INVALID_BEARER, tokens), 'denied')
        self.assertIsNone(fixture.request_actor(None, tokens))


class CopiedDataCalibrationTest(unittest.TestCase):
    def calibration(self, root, change):
        _, routes, _, _ = copied_fixture.workload(root, None)
        data = {'version': 1, 'scope': 'isolated-host-two-route-pilot-calibration',
                'proposed_routes': [{**route, 'weight': weight} for route, weight in zip(routes, (100, 100, 3727))],
                'proposed_phases': [
                    {'name': name, 'duration_ms': duration, 'arrival_rate_milli_rps': rate}
                    for name, duration, rate in (('warmup', 120000, 1000), ('peak', 180000, 2750), ('surge', 180000, 5417))
                ], 'proposed_max_in_flight': 20}
        change(data)
        (root / 'inputs').mkdir()
        file = root / 'inputs/calibration.json'
        file.write_text(json.dumps(data))
        return hashlib.sha256(file.read_bytes()).hexdigest()

    def test_pins_routes_scheduler_budgets_and_mixed_weights(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            checksum = self.calibration(root, lambda data: None)
            scope, routes, phases, concurrency = copied_fixture.workload(root, checksum)
            self.assertEqual(scope, 'native-copied-two-route-fpm-load')
            self.assertEqual([value['weight'] for value in routes], [100, 100, 3727])
            self.assertEqual(sum(value['duration_ms'] for value in phases), 480000)
            self.assertEqual(concurrency, 20)
            with self.assertRaises(copied_fixture.fixture.contract.Refusal):
                copied_fixture.workload(root, '0' * 64)

    def test_calibration_cannot_create_a_new_route_or_unbounded_surge(self):
        for field, value in (('path_template', '/api/unreviewed'), ('arrival_rate_milli_rps', 6001)):
            with self.subTest(field=field), tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                def change(data):
                    if field == 'path_template':
                        data['proposed_routes'][0][field] = value
                    else:
                        data['proposed_phases'][2][field] = value
                checksum = self.calibration(root, change)
                with self.assertRaises(copied_fixture.fixture.contract.Refusal):
                    copied_fixture.workload(root, checksum)

    def test_upload_summary_requires_a_separate_uncalibrated_smoke(self):
        root = Path('/unused')
        scope, routes, _, _ = copied_fixture.workload(root, None, media=True)
        self.assertEqual(scope, 'native-copied-upload-summary-fpm-smoke')
        self.assertIn(fixture.UPLOAD_SUMMARIES, [route['path_template'] for route in routes])
        with self.assertRaises(copied_fixture.fixture.contract.Refusal):
            copied_fixture.workload(root, '0' * 64, media=True)


if __name__ == '__main__':
    unittest.main()
