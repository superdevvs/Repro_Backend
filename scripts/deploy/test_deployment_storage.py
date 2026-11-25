"""Linux filesystem fixtures for destructive boundaries and deployment budgets."""
from contextlib import redirect_stdout, nullcontext
from datetime import datetime, timedelta
import gzip
import importlib.util
import io
import json
import os
from pathlib import Path
import re
import shutil
import sqlite3
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

    def bundle(self, age, component='backend', success=False, suffix=0, database='database.sqlite', extra=()):
        date = datetime.fromtimestamp(self.now) - timedelta(days=age, seconds=suffix)
        directory = self.base / component / date.strftime('%Y%m%d-%H%M%S')
        directory.mkdir()
        (directory / 'source.tar.gz').write_bytes(b'source fixture')
        if component == 'backend' and database:
            (directory / database).write_bytes(b'database fixture')
        for name in extra:
            (directory / name).write_bytes(b'{"fixture": true}')
        if success:
            (directory / guard.MARKER).write_text(json.dumps({'component': component, 'backup': directory.name,
                                                           'status': 'verified', 'commit': 'a' * 40}))
        for path in [*directory.iterdir(), directory]:
            os.utime(path, (date.timestamp(), date.timestamp()))
        return directory

    def run_retention(self, apply=True, **options):
        output = io.StringIO()
        with redirect_stdout(output):
            guard.retention(self.base, apply=apply, now=self.now, **options)
        return output.getvalue()

    def names(self, component='backend'):
        return sorted(path.name for path in (self.base / component).iterdir())

    def test_compressed_sqlite_and_mysql_bundles_are_recognized_and_removed_when_old(self):
        self.bundle(1, success=True)
        compressed = self.bundle(30, database='database.sqlite.gz', success=True)
        mysql = self.bundle(31, database='database.sql.gz', extra=('database-backup.json',), success=True)
        migrated = self.bundle(32, extra=('database-backup.json',))
        self.run_retention()
        for directory in (compressed, mysql, migrated):
            self.assertFalse(directory.exists(), directory.name)

    def test_bundle_without_database_is_kept_by_default_policy(self):
        self.bundle(1, success=True)
        source_only = self.bundle(30, database=None, success=True)
        output = self.run_retention()
        self.assertTrue(source_only.exists())
        self.assertIn('unrecognized or incomplete', output)

    def test_bundle_without_database_is_removed_by_tiered_policy(self):
        for age in range(1, 6):
            self.bundle(age, success=True)
        source_only = self.bundle(30, database=None, success=True)
        self.run_retention(policy='tiered')
        self.assertFalse(source_only.exists())

    def test_applied_retention_clears_a_partial_gzip_left_by_a_killed_compression(self):
        # A compression killed by a reboot leaves the partial file. If the flag
        # is then switched off, the compress step never runs again, so applied
        # retention (which holds both deploy locks) must clear it, or the bundle
        # and its raw copy are kept forever as unrecognized.
        self.bundle(1, success=True)
        old = self.bundle(30, success=True)
        (old / guard.PARTIAL_GZIP).write_bytes(b'\x1f\x8b truncated')
        output = self.run_retention(apply=False)
        self.assertTrue((old / guard.PARTIAL_GZIP).exists(), 'A dry run changes nothing.')
        self.assertIn(f'preserve backend/{old.name}', output)
        output = self.run_retention()
        self.assertFalse((old / guard.PARTIAL_GZIP).exists())
        self.assertIn(f'removed-stale-partial bundle={old.name}', output)
        self.assertIsNotNone(guard.inspect_backup(old, 'backend'))
        # Removing the file touched the folder, so it is kept for review like any
        # recently touched old label, then ages out.
        later = datetime.now().timestamp() + timedelta(days=guard.RETENTION_DAYS + 1).total_seconds()
        with redirect_stdout(io.StringIO()):
            guard.retention(self.base, apply=True, now=later)
        self.assertFalse(old.exists())

    def test_applied_retention_clears_a_partial_gzip_still_linked_to_the_published_gzip(self):
        # Killed between os.link(partial, gz) and os.unlink(partial): both names
        # point at the verified gzip (link count 2). Dropping the partial name
        # keeps the data under database.sqlite.gz and lets the bundle age out.
        self.bundle(1, success=True)
        old = self.bundle(30, success=True)
        published = old / guard.SQLITE_GZIP
        published.write_bytes(gzip.compress(b'database fixture'))
        os.link(published, old / guard.PARTIAL_GZIP)
        # A partial name linked to anything else is not ours to judge: kept.
        foreign = self.bundle(31, success=True)
        outside = Path(self.temporary.name) / 'outside.gz'
        outside.write_bytes(b'not ours to delete')
        os.link(outside, foreign / guard.PARTIAL_GZIP)
        stamp = (datetime.fromtimestamp(self.now) - timedelta(days=30)).timestamp()
        for path in [*old.iterdir(), old]:
            os.utime(path, (stamp, stamp))
        output = self.run_retention()
        self.assertFalse((old / guard.PARTIAL_GZIP).exists())
        self.assertEqual(published.stat().st_nlink, 1)
        self.assertEqual(gzip.decompress(published.read_bytes()), b'database fixture')
        self.assertIn(f'removed-stale-partial bundle={old.name}', output)
        self.assertIsNotNone(guard.inspect_backup(old, 'backend'))
        self.assertTrue((foreign / guard.PARTIAL_GZIP).exists())
        self.assertEqual(outside.read_bytes(), b'not ours to delete')
        later = datetime.now().timestamp() + timedelta(days=guard.RETENTION_DAYS + 1).total_seconds()
        with redirect_stdout(io.StringIO()):
            guard.retention(self.base, apply=True, now=later)
        self.assertFalse(old.exists())

    def test_tiered_policy_keeps_last_five_and_one_a_day_for_fourteen_days(self):
        created = {}
        for day in range(20):
            created[(day, 12)] = self.bundle(day)
            created[(day, 11)] = self.bundle(day, suffix=3600, success=(day == 5))
        output = self.run_retention(policy='tiered')
        # The newest five, then one per day inside the 14-day window. A day's
        # verified rollback point wins over a newer unverified bundle that day.
        expected = {created[(day, 12)].name for day in range(15) if day != 5}
        expected |= {created[(0, 11)].name, created[(1, 11)].name, created[(5, 11)].name}
        self.assertEqual(set(self.names()), expected)
        self.assertEqual(len(expected), 17)
        self.assertIn('policy=tiered keep_latest=5', output)

    def test_tiered_policy_keeps_newest_bundle_that_still_holds_a_database(self):
        with_database = self.bundle(40)
        for age in range(30, 36):
            self.bundle(age, database=None)
        self.run_retention(policy='tiered')
        self.assertTrue(with_database.exists())

    def test_tiered_day_without_a_verified_bundle_keeps_one_that_holds_a_database(self):
        for age in range(1, 6):
            self.bundle(age)
        with_database = self.bundle(10, suffix=3600)
        source_only = self.bundle(10, database=None)
        self.run_retention(policy='tiered')
        self.assertTrue(with_database.exists())
        self.assertFalse(source_only.exists())

    def test_tiered_policy_keeps_future_and_recently_touched_old_labels(self):
        future = self.bundle(-2)
        old = self.bundle(30)
        os.utime(old / 'database.sqlite', (self.now, self.now))
        for age in range(1, 7):
            self.bundle(0, suffix=age)
        self.run_retention(policy='tiered')
        self.assertTrue(future.exists())
        self.assertTrue(old.exists())

    def test_tiered_dry_run_changes_nothing(self):
        for age in range(20):
            self.bundle(age)
            self.bundle(age, suffix=60)
        before = self.names()
        output = self.run_retention(apply=False, policy='tiered')
        self.assertEqual(before, self.names())
        self.assertIn('would-remove backend/', output)

    def test_unknown_policy_is_refused(self):
        self.bundle(30)
        self.bundle(1)
        with self.assertRaises(RuntimeError):
            self.run_retention(policy='keep-nothing')
        self.assertEqual(len(self.names()), 2)

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
        def inspect(path, component, *policy):
            nonlocal calls
            if path == old:
                calls += 1
                if calls == 2:
                    (old / 'database.sqlite').write_bytes(b'changed')
            return real_inspect(path, component, *policy)
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


class SettingsTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.home = Path(self.temporary.name)
        self.state = self.home / '.repro-deploy-retention'
        self.state.mkdir(mode=0o700)

    def tearDown(self):
        self.temporary.cleanup()

    def test_new_behaviour_is_off_without_a_settings_file(self):
        self.assertEqual(guard.load_settings(self.home), {'compress_sqlite_backups': False, 'tiered_retention': False})

    def test_settings_file_turns_on_each_flag(self):
        (self.state / 'settings.json').write_text('{"compress_sqlite_backups": true, "tiered_retention": true}')
        self.assertEqual(guard.load_settings(self.home), {'compress_sqlite_backups': True, 'tiered_retention': True})

    def test_malformed_or_redirected_settings_fail_closed(self):
        path = self.state / 'settings.json'
        for content in ('[]', '{"tiered_retention": "yes"}', '{"keep_nothing": true}', 'not json'):
            with self.subTest(content=content):
                path.write_text(content)
                with self.assertRaises(RuntimeError):
                    guard.load_settings(self.home)
        path.unlink()
        target = self.home / 'elsewhere.json'
        target.write_text('{"tiered_retention": true}')
        path.symlink_to(target)
        with self.assertRaises(RuntimeError):
            guard.load_settings(self.home)

    def test_scheduled_and_deploy_retention_use_the_configured_policy(self):
        calls = []
        with patch.object(guard, 'HOME', self.home), patch.object(guard, 'deployment_lock', return_value=nullcontext()), \
                patch.object(guard, 'retention', side_effect=lambda **kwargs: calls.append(kwargs)):
            guard.apply_retention()
            (self.state / 'settings.json').write_text('{"tiered_retention": true}')
            guard.apply_retention()
        self.assertEqual([call['policy'] for call in calls], ['days', 'tiered'])
        self.assertTrue(all(call['apply'] for call in calls))


class CompressionTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.base = Path(self.temporary.name) / 'backups'
        (self.base / 'backend').mkdir(parents=True)
        (self.base / 'frontend').mkdir()

    def tearDown(self):
        self.temporary.cleanup()

    def bundle(self, stamp, rows=200):
        directory = self.base / 'backend' / stamp
        directory.mkdir()
        (directory / 'source.tar.gz').write_bytes(b'source fixture')
        database = directory / 'database.sqlite'
        connection = sqlite3.connect(database)
        connection.execute('CREATE TABLE items (id INTEGER PRIMARY KEY, value TEXT)')
        connection.executemany('INSERT INTO items (value) VALUES (?)', [('row %d' % i,) for i in range(rows)])
        connection.commit()
        connection.close()
        os.chmod(database, 0o600)
        os.utime(database, (1_700_000_000, 1_700_000_000))
        return directory

    def compress(self, **options):
        output = io.StringIO()
        with redirect_stdout(output):
            guard.compress_latest_sqlite_backup(self.base, **{'reserve': 0, **options})
        return output.getvalue()

    def test_newest_backend_copy_is_replaced_by_a_verified_gzip(self):
        older = self.bundle('20261020-100000')
        newest = self.bundle('20261021-100000')
        original = (newest / 'database.sqlite').read_bytes()
        output = self.compress()
        self.assertFalse((newest / 'database.sqlite').exists())
        with gzip.open(newest / 'database.sqlite.gz', 'rb') as stream:
            self.assertEqual(stream.read(), original)
        info = (newest / 'database.sqlite.gz').stat()
        self.assertEqual(info.st_mode & 0o777, 0o600)
        self.assertEqual(int(info.st_mtime), 1_700_000_000)
        self.assertEqual(sorted(path.name for path in newest.iterdir()), ['database.sqlite.gz', 'source.tar.gz'])
        self.assertIsNotNone(guard.inspect_backup(newest, 'backend'))
        self.assertTrue((older / 'database.sqlite').exists())
        self.assertIn('sqlite_backup_compression=done', output)

    def test_nothing_to_do_for_mysql_or_already_compressed_bundles(self):
        newest = self.base / 'backend' / '20261021-100000'
        newest.mkdir()
        (newest / 'source.tar.gz').write_bytes(b'source fixture')
        (newest / 'database.sql.gz').write_bytes(b'dump fixture')
        output = self.compress()
        self.assertIn('sqlite_backup_compression=skipped', output)
        self.assertEqual(sorted(path.name for path in newest.iterdir()), ['database.sql.gz', 'source.tar.gz'])

    def test_failed_verification_keeps_the_original_and_removes_the_partial_file(self):
        newest = self.bundle('20261021-100000')
        original = (newest / 'database.sqlite').read_bytes()
        with patch.object(guard, 'gzip_digest', return_value=('0' * 64, 0)):
            with self.assertRaises(RuntimeError):
                self.compress()
        self.assertEqual((newest / 'database.sqlite').read_bytes(), original)
        self.assertEqual(sorted(path.name for path in newest.iterdir()), ['database.sqlite', 'source.tar.gz'])

    def test_a_partial_file_left_by_a_killed_run_is_cleared_and_the_copy_is_compressed(self):
        # A run killed before its own cleanup (reboot, OOM) leaves the partial
        # file. Retention would then call the bundle unrecognized and keep its
        # raw copy forever. The next run holds the lock, so the file is stale.
        older = self.bundle('20261020-100000')
        (older / guard.PARTIAL_GZIP).write_bytes(b'')
        newest = self.bundle('20261021-100000')
        (newest / guard.PARTIAL_GZIP).write_bytes(b'\x1f\x8b truncated')
        original = (newest / 'database.sqlite').read_bytes()
        output = self.compress()
        self.assertFalse((older / guard.PARTIAL_GZIP).exists())
        self.assertIsNotNone(guard.inspect_backup(older, 'backend'))
        self.assertEqual(sorted(path.name for path in newest.iterdir()), ['database.sqlite.gz', 'source.tar.gz'])
        with gzip.open(newest / 'database.sqlite.gz', 'rb') as stream:
            self.assertEqual(stream.read(), original)
        self.assertIn('removed-stale-partial', output)

    def test_only_a_plain_owned_partial_file_is_cleared(self):
        newest = self.bundle('20261021-100000')
        outside = Path(self.temporary.name) / 'outside.gz'
        outside.write_bytes(b'not ours to delete')
        (newest / guard.PARTIAL_GZIP).symlink_to(outside)
        output = self.compress()
        self.assertTrue((newest / guard.PARTIAL_GZIP).is_symlink())
        self.assertEqual(outside.read_bytes(), b'not ours to delete')
        self.assertTrue((newest / 'database.sqlite').exists())
        self.assertIn('sqlite_backup_compression=skipped', output)

    def test_insufficient_space_skips_without_changes(self):
        newest = self.bundle('20261021-100000')
        with patch.object(guard.os, 'statvfs', return_value=SimpleNamespace(f_bavail=1, f_frsize=1)):
            output = self.compress()
        self.assertIn('reason=insufficient-space', output)
        self.assertEqual(sorted(path.name for path in newest.iterdir()), ['database.sqlite', 'source.tar.gz'])

    def test_redirected_database_copy_is_refused(self):
        newest = self.bundle('20261021-100000')
        outside = Path(self.temporary.name) / 'outside.sqlite'
        (newest / 'database.sqlite').rename(outside)
        os.link(outside, newest / 'database.sqlite')
        output = self.compress()
        self.assertIn('sqlite_backup_compression=skipped', output)
        self.assertFalse((newest / 'database.sqlite.gz').exists())
        self.assertTrue(outside.exists())

    def test_cli_does_nothing_unless_the_flag_is_on(self):
        newest = self.bundle('20261021-100000')
        home = Path(self.temporary.name)
        output = io.StringIO()
        with patch.object(guard, 'HOME', home), patch.object(guard, 'BASE', self.base), patch.object(guard, 'RESERVE', 0), \
                patch.object(guard, 'deployment_lock', return_value=nullcontext()), redirect_stdout(output):
            guard.compress_backup()
        self.assertIn('sqlite_backup_compression=disabled', output.getvalue())
        self.assertTrue((newest / 'database.sqlite').exists())
        (home / '.repro-deploy-retention').mkdir(mode=0o700)
        (home / '.repro-deploy-retention/settings.json').write_text('{"compress_sqlite_backups": true}')
        with patch.object(guard, 'HOME', home), patch.object(guard, 'BASE', self.base), patch.object(guard, 'RESERVE', 0), \
                patch.object(guard, 'deployment_lock', return_value=nullcontext()), redirect_stdout(io.StringIO()):
            guard.compress_backup()
        self.assertTrue((newest / 'database.sqlite.gz').exists())


class EnginePreflightTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.app = Path(self.temporary.name) / 'backend'
        (self.app / 'database').mkdir(parents=True)
        (self.app / 'vendor').mkdir()
        (self.app / 'bootstrap/cache').mkdir(parents=True)

    def tearDown(self):
        self.temporary.cleanup()

    def test_engine_falls_back_to_the_environment_file_without_a_config_cache(self):
        self.assertEqual(guard.database_driver(self.app), 'sqlite')
        (self.app / '.env').write_text('APP_NAME=Fixture\nDB_CONNECTION="mysql" # cutover\n')
        self.assertEqual(guard.database_driver(self.app), 'mysql')

    def test_environment_file_uses_the_last_assignment_like_laravel(self):
        # phpdotenv keeps the last of duplicate keys, so the app runs on that one.
        (self.app / '.env').write_text('DB_CONNECTION=mysql\nAPP_NAME=Fixture\nDB_CONNECTION=sqlite\n')
        self.assertEqual(guard.database_driver(self.app), 'sqlite')
        (self.app / '.env').write_text('DB_CONNECTION=sqlite\nexport DB_CONNECTION="mysql"\n')
        self.assertEqual(guard.database_driver(self.app), 'mysql')

    @unittest.skipUnless(shutil.which('php'), 'php is required to read a cached Laravel config')
    def test_engine_comes_from_the_cached_config_when_present(self):
        (self.app / '.env').write_text('DB_CONNECTION=sqlite\n')
        (self.app / 'bootstrap/cache/config.php').write_text(
            "<?php return ['database' => ['default' => 'main', 'connections' => ['main' => ['driver' => 'mysql']]]];\n")
        self.assertEqual(guard.database_driver(self.app), 'mysql')

    def test_unknown_engine_fails_closed(self):
        (self.app / '.env').write_text('DB_CONNECTION=pgsql\n')
        with self.assertRaisesRegex(RuntimeError, 'Unsupported database driver'):
            guard.database_driver(self.app)

    def run_preflight(self, driver):
        requirements = []
        with patch.dict(guard.APPS, backend=self.app), patch.object(guard, 'database_driver', return_value=driver), \
                patch.object(guard, 'safe_directory', side_effect=lambda path: Path(path)), \
                patch.object(guard, 'assert_capacity', side_effect=requirements.extend), redirect_stdout(io.StringIO()) as output:
            guard.preflight('backend', 100, 200)
        self.assertIn(f'deploy-database-engine={driver}', output.getvalue())
        return requirements

    def test_mysql_preflight_ignores_the_retired_sqlite_path(self):
        # After cutover a directory sits where the SQLite file was.
        (self.app / 'database/database.sqlite').mkdir()
        requirements = self.run_preflight('mysql')
        self.assertNotIn(self.app / 'database', [path for path, _ in requirements])

    def test_mysql_preflight_reserves_room_for_a_dump_like_the_newest_one(self):
        backups = Path(self.temporary.name) / 'backups'
        (backups / 'backend').mkdir(parents=True)
        with patch.object(guard, 'BASE', backups):
            baseline = dict(self.run_preflight('mysql'))[backups / 'backend']
            for stamp, size in (('20261020-100000', 1000), ('20261021-100000', 4000)):
                (backups / 'backend' / stamp).mkdir()
                (backups / 'backend' / stamp / 'database.sql.gz').write_bytes(b'x' * size)
            # A newer bundle without a dump (a failed deploy) does not hide the last dump.
            (backups / 'backend' / '20261022-100000').mkdir()
            requirements = dict(self.run_preflight('mysql'))
        self.assertEqual(requirements[backups / 'backend'] - baseline, 5000)

    def test_sqlite_preflight_still_requires_a_regular_file(self):
        (self.app / 'database/database.sqlite').mkdir()
        with self.assertRaisesRegex(RuntimeError, 'regular, non-hardlinked'):
            self.run_preflight('sqlite')
        (self.app / 'database/database.sqlite').rmdir()
        (self.app / 'database/database.sqlite').write_bytes(b'x' * 4096)
        requirements = dict(self.run_preflight('sqlite'))
        self.assertEqual(requirements[self.app / 'database'], 4096)


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

    def test_deploy_compresses_before_retention_and_a_compression_error_does_not_stop_retention(self):
        workflow = Path(__file__).with_name('Deploy-App.ps1').read_text()
        function = workflow.split('function Invoke-DeploymentRetention {', 1)[1].split('\n}', 1)[0]
        command = re.search(r"Invoke-Ssh -Command '([^']*)'", function).group(1)
        self.assertLess(command.index('compress-backup'), command.index('retention --apply'))
        backend = workflow.split('function Deploy-Backend {', 1)[1].split('function Deploy-Frontend {', 1)[0]
        self.assertNotIn('compress-backup', backend)
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            log = root / 'calls.log'
            fake = root / 'python3'
            fake.write_text('#!/bin/sh\necho "$*" >> "$CALL_LOG"\ncase "$*" in *compress-backup*) exit 1;; esac\n')
            fake.chmod(0o755)
            env = {**os.environ, 'PATH': f'{root}:{os.environ["PATH"]}', 'CALL_LOG': str(log)}
            result = subprocess.run(['bash', '-c', command], capture_output=True, text=True, env=env)
            self.assertEqual(result.returncode, 0, result.stderr)
            calls = log.read_text().splitlines()
            self.assertEqual([call.split()[1] for call in calls], ['install-cron', 'compress-backup', 'retention'])
            self.assertIn('uncompressed copy is kept', result.stderr)

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
