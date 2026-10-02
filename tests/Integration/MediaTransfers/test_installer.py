import importlib.util
from pathlib import Path
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

SOURCE = Path(__file__).resolve().parents[3]
spec = importlib.util.spec_from_file_location('installer', SOURCE / 'scripts/deploy/install-media-transfers.py')
installer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(installer)


class InstallerTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        root = Path(self.temporary.name)
        self.state = {'offload': False, 'archives': False, 'canaries': [], 'archive_jobs': 0,
                      'retry_after': 2100, 'queue_driver': 'database'}
        roots = {key: str(root / key) for key in ('private', 'legacy', 'originals')}
        for path in roots.values():
            Path(path).mkdir()
        self.state.update(roots)
        paths = {name: root / name for name in ('FRONTEND', 'BACKEND', 'HTTP', 'LOCATIONS', 'ROTATE', 'SERVICE')}
        self.patches = [patch.object(installer, name, value) for name, value in
                        {**paths, 'ALLOWED': set(paths.values()), 'MEDIA_ROOTS': roots, 'BACKUPS': root / 'backups'}.items()]
        self.patches += [patch.object(installer, 'application_state', lambda: self.state),
                         patch.object(installer, 'worker_state', lambda: {'enabled': False, 'active': False}),
                         patch.object(installer.subprocess, 'run', return_value=SimpleNamespace(stderr='--with-threads', returncode=0))]
        for active in self.patches:
            active.start()
            self.addCleanup(active.stop)
        self.original_front = 'server {\n    root /var/www/frontend/dist;\n}\n'
        self.original_back = 'server {\n    root /var/www/backend/public;\n}\n'
        installer.FRONTEND.write_text(self.original_front)
        installer.BACKEND.write_text(self.original_back)

    def test_invalid_nginx_configuration_restores_every_original_file(self):
        calls = []

        def run(*command):
            calls.append(command)
            if command == ('nginx', '-t') and calls.count(command) == 1:
                raise RuntimeError('invalid test configuration')
            return ''

        with patch.object(installer, 'run', run):
            with self.assertRaisesRegex(RuntimeError, 'invalid test configuration'):
                installer.install('downloads')
        self.assertEqual(self.original_front, installer.FRONTEND.read_text())
        self.assertEqual(self.original_back, installer.BACKEND.read_text())
        self.assertFalse(installer.HTTP.exists())
        self.assertFalse(installer.LOCATIONS.exists())
        self.assertIn(('systemctl', 'reload', 'nginx'), calls)

    def test_duplicate_root_anchor_refuses_to_change_site(self):
        installer.FRONTEND.write_text(self.original_front * 2)
        with patch.object(installer, 'run', return_value=''):
            with self.assertRaisesRegex(RuntimeError, 'Expected one'):
                installer.install('downloads')
        self.assertEqual(self.original_front * 2, installer.FRONTEND.read_text())
        self.assertFalse(installer.HTTP.exists())

    def test_restore_refuses_to_remove_live_offload_locations(self):
        with patch.object(installer, 'run', return_value=''):
            installer.install('downloads')
            saved = next(installer.BACKUPS.iterdir())
            self.state['offload'] = True
            with self.assertRaisesRegex(RuntimeError, 'Disable MEDIA_DOWNLOAD_OFFLOAD'):
                installer.restore(saved)
        self.assertTrue(installer.LOCATIONS.exists())

    def test_archive_restore_requires_drained_queue_and_restores_disabled_service_state(self):
        installer.SERVICE.write_text('previous worker configuration')
        with patch.object(installer, 'run', return_value='') as run:
            installer.install('archives')
            saved = next(installer.BACKUPS.iterdir())
            self.state['archive_jobs'] = 1
            with self.assertRaisesRegex(RuntimeError, 'drain media-archives'):
                installer.restore(saved)
            self.state['archive_jobs'] = 0
            installer.restore(saved)
            self.assertEqual('previous worker configuration', installer.SERVICE.read_text())
            run.assert_any_call('systemctl', 'disable', installer.SERVICE.name)
            run.assert_any_call('systemctl', 'stop', installer.SERVICE.name)


if __name__ == '__main__':
    unittest.main()
