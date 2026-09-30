"""Concurrency guard checks use temporary fixtures and never access a real app."""
import importlib.util
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

sys.dont_write_bytecode = True
HELPER = Path(__file__).with_name('backend-source-fingerprint.py')
spec = importlib.util.spec_from_file_location('backend_fingerprint', HELPER)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class BackendFingerprintTest(unittest.TestCase):
    @unittest.skipUnless(Path('/proc').is_dir(), 'Linux process inspection')
    def test_active_non_cooperating_deployment_is_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            process = subprocess.Popen([sys.executable, '-c', 'import time; time.sleep(30)', 'artisan', 'migrate'], cwd=directory)
            try:
                with self.assertRaisesRegex(RuntimeError, 'Another backend deployment process is active'):
                    module.assert_no_deployment_process(directory)
            finally:
                process.terminate()
                process.wait(timeout=5)

    def test_modified_new_and_deleted_source_all_fail_expected_guard(self):
        for change in ('modified', 'new', 'deleted'):
            with self.subTest(change=change), tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                (root / 'app').mkdir()
                source = root / 'app' / 'Example.php'
                source.write_text('original')
                expected = module.fingerprint(root)
                if change == 'modified':
                    source.write_text('new code')
                elif change == 'new':
                    (root / 'app' / 'Concurrent.php').write_text('concurrent hotfix')
                else:
                    source.unlink()
                result = subprocess.run([sys.executable, str(HELPER), str(root), '--expected', expected], capture_output=True, text=True)
                self.assertEqual(result.returncode, 1)
                self.assertIn('Production source changed', result.stderr)

    def test_runtime_caches_database_and_uploads_do_not_change_source_fingerprint(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'bootstrap' / 'cache').mkdir(parents=True)
            (root / 'database').mkdir()
            (root / 'public' / 'storage').mkdir(parents=True)
            expected = module.fingerprint(root)
            (root / 'bootstrap' / 'cache' / 'config.php').write_text('runtime')
            (root / 'database' / 'database.sqlite').write_text('runtime')
            (root / 'public' / 'storage' / 'photo.jpg').write_text('runtime')
            self.assertEqual(module.fingerprint(root), expected)
            result = subprocess.run([sys.executable, str(HELPER), str(root), '--expected', expected], capture_output=True, text=True)
            self.assertEqual(result.returncode, 0)

    def test_deploy_guards_execute_before_maintenance(self):
        source = HELPER.with_name('Deploy-App.ps1').read_text()
        self.assertLess(source.index('flock -w 300 9'), source.index('php artisan down'))
        self.assertLess(source.index('--expected "$expected_source" --check-processes'), source.index('php artisan down'))
        self.assertLess(source.index('origin/main advanced during quality checks'), source.index("Invoke-Scp -LocalPath $archivePath"))


if __name__ == '__main__':
    unittest.main()
