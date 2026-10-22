"""Linux filesystem fixtures for destructive boundaries and deployment budgets."""
from contextlib import redirect_stdout, nullcontext
from datetime import datetime, timedelta
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import tarfile
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location('deployment_storage', Path(__file__).with_name('deployment-storage.py'))
guard = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(guard)


class RetentionTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.base = Path(self.temporary.name) / 'backups'
        self.base.mkdir()
        self.now = datetime(2026, 10, 22, 12).timestamp()
        for component in ('backend', 'frontend'):
            (self.base / component).mkdir()

    def tearDown(self):
        self.temporary.cleanup()

    def bundle(self, age, component='backend', success=False, suffix=0):
        date = datetime.fromtimestamp(self.now) - timedelta(days=age, seconds=suffix)
        directory = self.base / component / date.strftime('%Y%m%d-%H%M%S')
        directory.mkdir()
        (directory / 'source.tar.gz').write_bytes(b'source fixture')
        if component == 'backend':
            (directory / 'database.sqlite').write_bytes(b'database fixture')
        if success:
            (directory / guard.MARKER).write_text(json.dumps({'component': component, 'backup': directory.name,
                                                           'status': 'verified', 'commit': 'a' * 40}))
        for path in [*directory.iterdir(), directory]:
            os.utime(path, (date.timestamp(), date.timestamp()))
        return directory

    def run_retention(self, apply=True):
        with redirect_stdout(io.StringIO()):
            guard.retention(self.base, apply=apply, now=self.now)

    def test_old_complete_pairs_removed_but_recent_and_boundary_retained(self):
        old = self.bundle(15)
        boundary = self.bundle(14)
        recent = self.bundle(1)
        self.run_retention()
        self.assertFalse(old.exists())
        self.assertTrue(boundary.exists())
        self.assertTrue(recent.exists())

    def test_newest_complete_and_newest_verified_rollback_survive_forever(self):
        obsolete = self.bundle(40, success=True)
        verified = self.bundle(30, success=True)
        latest = self.bundle(20)
        self.run_retention()
        self.assertFalse(obsolete.exists())
        self.assertTrue(verified.exists())
        self.assertTrue(latest.exists())

    def test_future_and_recently_touched_old_labels_are_preserved(self):
        future = self.bundle(-2)
        old = self.bundle(30)
        os.utime(old / 'database.sqlite', (self.now, self.now))
        self.run_retention()
        self.assertTrue(future.exists())
        self.assertTrue(old.exists())

    def test_unknown_content_incomplete_and_invalid_labels_preserved(self):
        self.bundle(1)
        unknown = self.bundle(30)
        (unknown / 'private-original.jpg').write_bytes(b'original')
        incomplete = self.bundle(31)
        (incomplete / 'database.sqlite').unlink()
        odd = self.base / 'backend' / 'service55-20260930-backup'
        odd.mkdir()
        self.run_retention()
        for directory in (unknown, incomplete, odd):
            self.assertTrue(directory.exists())

    def test_symlink_hardlink_nested_directory_and_marker_preserve_whole_bundle(self):
        self.bundle(1)
        external = self.base / 'never-delete'
        external.write_bytes(b'original')
        for age, kind in enumerate(('symlink', 'hardlink', 'directory', 'marker'), 30):
            directory = self.bundle(age)
            source = directory / 'source.tar.gz'
            if kind in ('symlink', 'hardlink', 'directory'):
                source.unlink()
            if kind == 'symlink':
                source.symlink_to(external)
            elif kind == 'hardlink':
                os.link(external, source)
            elif kind == 'directory':
                source.mkdir()
            else:
                (directory / guard.MARKER).write_text('{"status":"unknown"}')
        self.run_retention()
        self.assertEqual(len(list((self.base / 'backend').iterdir())), 5)
        self.assertEqual(external.read_bytes(), b'original')

    def test_redirected_component_or_parent_refused(self):
        (self.base / 'frontend').rmdir()
        (self.base / 'frontend').symlink_to(self.base / 'backend')
        with self.assertRaises(RuntimeError):
            self.run_retention()

    def test_dry_run_changes_nothing_including_lockfiles(self):
        self.bundle(30)
        self.bundle(1)
        before = sorted(str(path) for path in Path(self.temporary.name).rglob('*'))
        self.run_retention(apply=False)
        self.assertEqual(before, sorted(str(path) for path in Path(self.temporary.name).rglob('*')))

    def test_frontend_pairs_use_same_retention_but_ad_hoc_backups_are_preserved(self):
        old = self.bundle(30, component='frontend')
        newest = self.bundle(20, component='frontend')
        odd = self.base / 'frontend' / 'empty-20260921-005527'
        odd.mkdir()
        self.run_retention()
        self.assertFalse(old.exists())
        self.assertTrue(newest.exists())
        self.assertTrue(odd.exists())

    def test_concurrent_change_between_scan_and_delete_aborts(self):
        old = self.bundle(30)
        self.bundle(1)
        real_inspect = guard.inspect_backup
        calls = 0
        def inspect(path, component):
            nonlocal calls
            if path == old:
                calls += 1
                if calls == 2:
                    (old / 'database.sqlite').write_bytes(b'changed')
            return real_inspect(path, component)
        with patch.object(guard, 'inspect_backup', side_effect=inspect):
            with self.assertRaises(RuntimeError):
                self.run_retention()
        self.assertEqual((old / 'database.sqlite').read_bytes(), b'changed')

    def test_non_object_json_marker_is_preserved(self):
        self.bundle(1)
        old = self.bundle(30)
        (old / guard.MARKER).write_text('[]')
        self.run_retention()
        self.assertTrue(old.exists())

    def test_lock_is_shared_and_rejects_hardlinks_and_symlinks(self):
        home = Path(self.temporary.name)
        with guard.deployment_lock('backend', home, timeout=0):
            with self.assertRaises(RuntimeError):
                with guard.deployment_lock('backend', home, timeout=0):
                    self.fail('Lock should be held')
        lock = home / '.repro-backend-deploy.lock'
        os.link(lock, home / 'linked-lock')
        with self.assertRaises(RuntimeError):
            with guard.deployment_lock('backend', home, timeout=0):
                pass


class SpaceAndWorkflowTest(unittest.TestCase):
    def test_capacity_is_summed_per_device_and_reserve_is_not_double_counted(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            with patch.object(guard.os, 'statvfs', return_value=SimpleNamespace(f_bavail=150, f_frsize=1)):
                with redirect_stdout(io.StringIO()):
                    guard.assert_capacity([(root, 20), (root, 30)], reserve=100)
                    with self.assertRaisesRegex(RuntimeError, 'Insufficient deployment space'):
                        guard.assert_capacity([(root, 21), (root, 30)], reserve=100)

    def test_each_filesystem_requires_its_own_reserve(self):
        a, b = Path('/fixture-a'), Path('/fixture-b')
        with patch.object(guard, 'safe_directory'), patch.object(Path, 'stat', side_effect=[SimpleNamespace(st_dev=1), SimpleNamespace(st_dev=2)]), \
                patch.object(guard.os, 'statvfs', side_effect=[SimpleNamespace(f_bavail=120, f_frsize=1), SimpleNamespace(f_bavail=119, f_frsize=1)]):
            with redirect_stdout(io.StringIO()), self.assertRaises(RuntimeError):
                guard.assert_capacity([(a, 20), (b, 20)], reserve=100)

    def test_cron_preserves_existing_jobs_and_refuses_unknown_tagged_command(self):
        current = 'MAILTO=operations@example.test\n* * * * * existing-command\n'
        result = guard.updated_crontab(current)
        self.assertTrue(result.startswith(current))
        self.assertEqual(result.count(guard.CRON_TAG), 1)
        self.assertEqual(guard.updated_crontab(result), result)
        with self.assertRaises(RuntimeError):
            guard.updated_crontab(current + '0 * * * * unknown ' + guard.CRON_TAG)

    def test_unexpected_crontab_read_error_makes_no_crontab_or_state_changes(self):
        with tempfile.TemporaryDirectory() as directory:
            app = Path(directory)
            helper = app / 'scripts/deploy/deployment-storage.py'
            helper.parent.mkdir(parents=True)
            helper.write_text('fixture')
            failure = SimpleNamespace(returncode=2, stdout='', stderr='permission denied')
            with patch.dict(guard.APPS, backend=app), patch.object(guard, 'deployment_lock', return_value=nullcontext()), \
                    patch.object(guard.subprocess, 'run', return_value=failure) as run, patch.object(guard, 'retention_state') as state:
                with self.assertRaisesRegex(RuntimeError, 'Unable to read'):
                    guard.install_cron()
                self.assertEqual(run.call_count, 1)
                state.assert_not_called()

    def test_scheduled_run_overwrites_one_log_and_records_failure_without_mail(self):
        with tempfile.TemporaryDirectory() as directory, patch.object(guard, 'HOME', Path(directory)):
            with patch.object(guard, 'apply_retention', side_effect=RuntimeError('fixture failure')):
                with self.assertRaises(RuntimeError):
                    guard.scheduled_retention()
            logfile = Path(directory) / '.repro-deploy-retention/last-run.log'
            self.assertIn('retention_failed: fixture failure', logfile.read_text())
            with patch.object(guard, 'apply_retention'):
                guard.scheduled_retention()
            self.assertNotIn('fixture failure', logfile.read_text())
            self.assertIn('finished_at=', logfile.read_text())
            self.assertIn('>/dev/null 2>&1', guard.CRON_JOB)

    def test_log_hardlink_is_rejected_before_truncation(self):
        with tempfile.TemporaryDirectory() as directory:
            state = Path(directory)
            target = state / 'original'
            target.write_text('preserve')
            os.link(target, state / 'last-run.log')
            with self.assertRaises(RuntimeError):
                guard.open_last_run_log(state, truncate=True)
            self.assertEqual(target.read_text(), 'preserve')

    def test_preflight_precedes_transfer_and_locked_mutation_in_both_wrappers(self):
        workflow = Path(__file__).with_name('Deploy-App.ps1').read_text()
        self.assertLess(workflow.index('Test-DeploymentSpace -Sizes $releaseSizes'), workflow.index('Invoke-Scp -LocalPath $archivePath'))
        backend = workflow.split('function Deploy-Backend {', 1)[1].split('function Deploy-Frontend {', 1)[0]
        frontend = workflow.split('function Deploy-Frontend {', 1)[1].split('\ntry {', 1)[0]
        self.assertLess(backend.index('flock -w 300 9'), backend.index('python3 - preflight backend'))
        self.assertLess(backend.index('python3 - preflight backend'), backend.index('php artisan down'))
        self.assertLess(backend.index('python3 - preflight backend'), backend.index('tar -czf "$backup/source.tar.gz"'))
        self.assertLess(frontend.index('flock -w 300 9'), frontend.index('deployment-storage.py preflight frontend'))
        self.assertLess(frontend.index('deployment-storage.py preflight frontend'), frontend.index('rsync -rltD'))
        self.assertNotIn('retention --apply', backend)
        self.assertNotIn('retention --apply', frontend)
        self.assertIn('Invoke-DeploymentRetention\n        Write-Host', workflow)
        self.assertEqual(guard.RESERVE, 20 * 1024 ** 3)

    def test_tar_stream_and_python_failures_stop_before_remote_mutation(self):
        workflow = Path(__file__).with_name('Deploy-App.ps1').read_text()
        command = next(line for line in workflow.splitlines() if line.startswith('tar -xOf "$archive"'))
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            archive = root / 'release.tar.gz'
            with tarfile.open(archive, 'w:gz') as output:
                content = b'raise SystemExit(23)\n'
                entry = tarfile.TarInfo('scripts/deploy/deployment-storage.py')
                entry.size = len(content)
                output.addfile(entry, io.BytesIO(content))
            script = 'set -Eeuo pipefail\narchive="$1"\narchive_bytes=1\nrelease_bytes=1\n' + command + '\ntouch "$2"\n'
            marker = root / 'mutation-started'
            result = subprocess.run(['bash', '-c', script, 'fixture', str(archive), str(marker)], capture_output=True)
            self.assertEqual(result.returncode, 23)
            self.assertFalse(marker.exists())
            archive.write_bytes(b'corrupt release')
            result = subprocess.run(['bash', '-c', script, 'fixture', str(archive), str(marker)], capture_output=True)
            self.assertNotEqual(result.returncode, 0)
            self.assertFalse(marker.exists())


if __name__ == '__main__':
    unittest.main()
