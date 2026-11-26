"""Linux filesystem fixtures for destructive boundaries and deployment budgets."""
import ast
from contextlib import redirect_stdout, nullcontext
from datetime import datetime, timedelta
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import sys
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

    def test_exact_guarded_cron_and_surrounding_jobs_are_preserved_verbatim(self):
        guarded = ('47 3 * * * /usr/local/sbin/repro-storage-guard --live && /usr/bin/python3 '
                   '/var/www/backend/scripts/deploy/deployment-storage.py scheduled-retention >/dev/null 2>&1 '
                   '# repro-deploy-backup-retention')
        current = 'MAILTO=operations@example.test\n\n' + guarded + '\n* * * * * existing-command\n\n'
        self.assertEqual(guard.updated_crontab(current), current)

    def test_duplicate_retention_jobs_are_rejected_even_when_both_are_known(self):
        for first in (guard.CRON_JOB, guard.GUARDED_CRON_JOB):
            for second in (guard.CRON_JOB, guard.GUARDED_CRON_JOB):
                with self.subTest(first=first, second=second), self.assertRaises(RuntimeError):
                    guard.updated_crontab(first + '\n' + second + '\n')

    def test_modified_guarded_retention_jobs_are_rejected(self):
        for old, new in (('--live', '--check'), ('&&', '||'), ('&&', ';'),
                         ('/usr/local/sbin/', '/tmp/'), ('47 3 ', '48 3 '),
                         ('scheduled-retention', 'retention --apply')):
            with self.subTest(change=new), self.assertRaises(RuntimeError):
                guard.updated_crontab(guard.GUARDED_CRON_JOB.replace(old, new) + '\n')

    def test_install_preserves_existing_guard_without_rewriting_crontab(self):
        with tempfile.TemporaryDirectory() as directory:
            app = Path(directory)
            helper = app / 'scripts/deploy/deployment-storage.py'
            helper.parent.mkdir(parents=True)
            helper.write_text('fixture')
            current = guard.GUARDED_CRON_JOB + '\n'
            result = SimpleNamespace(returncode=0, stdout=current, stderr='')
            with patch.dict(guard.APPS, backend=app), patch.object(guard, 'HOME', app), \
                    patch.object(guard, 'deployment_lock', return_value=nullcontext()), \
                    patch.object(guard.subprocess, 'run', return_value=result) as run, redirect_stdout(io.StringIO()):
                guard.install_cron()
            self.assertEqual(run.call_count, 1)
            self.assertEqual(run.call_args.args[0], ['crontab', '-l'])
            self.assertFalse((app / '.repro-deploy-retention/crontab-before-retention.txt').exists())

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


class StandardLibraryOnlyTest(unittest.TestCase):
    """deployment-storage.py imports nothing but the standard library (review W2V-5).

    The retention line in the deploy user's crontab (CRON_JOB, GUARDED_CRON_JOB) runs it at 03:47 as
    /usr/bin/python3 without -B, and it is an allow rule, so it keeps running through the database cutover
    window; Deploy-App also runs it from stdin (python3 -). Python never caches the main script, but it caches
    every module the script imports from its own folder or from a package beside it. Such an import would
    write scripts/deploy/__pycache__ into the fingerprinted app tree, and the cutover runner would refuse
    every action until it was removed.
    """

    SCRIPT = Path(__file__).with_name('deployment-storage.py')
    # Standard-library modules that load other code by name or path.
    LOADERS = {'importlib', 'imp', 'pkgutil', 'runpy', 'zipimport'}
    WHY = ('deployment-storage.py runs without -B at 03:47 (the retention cron line stays in force through a '
           'cutover window) and from stdin in Deploy-App: an import from outside the standard library or from '
           'scripts/deploy would write scripts/deploy/__pycache__ into the fingerprinted tree and stall every '
           'cutover action. Keep it to the standard library, or first change the cron line to python3 -B '
           '(install-cron accepting both forms, the guarded line rewritten on the host, cron-rules updated).')

    def problems(self, source, folder):
        """Every import that is not a plain standard-library module, with its line."""
        siblings = {path.stem for path in folder.iterdir() if path.suffix == '.py' or (path / '__init__.py').is_file()}
        found = []
        for node in ast.walk(ast.parse(source)):
            if isinstance(node, ast.Import):
                names = [alias.name for alias in node.names]
            elif isinstance(node, ast.ImportFrom):
                if node.level:
                    found.append(f'line {node.lineno}: relative import')
                    continue
                names = [node.module]
            elif isinstance(node, (ast.Name, ast.Attribute)) and '__import__' in (getattr(node, 'id', None),
                                                                                   getattr(node, 'attr', None)):
                found.append(f'line {node.lineno}: __import__')
                continue
            else:
                continue
            for name in names:
                top = name.split('.')[0]
                if top in siblings:
                    # A file beside the script shadows even a standard-library name (the script's folder is sys.path[0]).
                    found.append(f'line {node.lineno}: {name} is a module in the script\'s own folder')
                elif top not in sys.stdlib_module_names:
                    found.append(f'line {node.lineno}: {name} is not in the standard library')
                elif top in self.LOADERS:
                    found.append(f'line {node.lineno}: {name} loads other code')
        return found

    def test_the_check_reports_every_kind_of_import_that_could_write_a_cache(self):
        with tempfile.TemporaryDirectory() as directory:
            folder = Path(directory)
            (folder / 'helper.py').write_text('VALUE = 1\n')
            (folder / 'json.py').write_text('VALUE = 1\n')
            (folder / 'package').mkdir()
            (folder / 'package' / '__init__.py').write_text('')
            source = '\n'.join([
                'import os, sys', 'from pathlib import Path', 'import xml.etree.ElementTree',  # fine
                'import helper',  # line 4
                'from . import helper',
                'from package import thing',
                'import json',  # shadowed by json.py beside it
                'import requests',
                'import importlib.util',
                'from importlib import import_module',
                "module = __import__('helper')",
                "module = builtins.__import__('helper')",
            ])
            self.assertEqual([
                "line 4: helper is a module in the script's own folder",
                'line 5: relative import',
                "line 6: package is a module in the script's own folder",
                "line 7: json is a module in the script's own folder",
                'line 8: requests is not in the standard library',
                'line 9: importlib.util loads other code',
                'line 10: importlib loads other code',
                'line 11: __import__',
                'line 12: __import__',
            ], sorted(self.problems(source, folder), key=lambda text: int(text.split(':')[0][5:])))

    def test_deployment_storage_imports_only_the_standard_library(self):
        problems = self.problems(self.SCRIPT.read_text(encoding='utf-8'), self.SCRIPT.parent)
        self.assertEqual([], problems, self.WHY)


if __name__ == '__main__':
    unittest.main()
