"""Exercise the Windows deployment guard using generated, non-secret archives."""
import io
from pathlib import Path
import subprocess
import tarfile
import tempfile
import unittest

GUARD = Path(__file__).with_name('Test-ReleaseArchive.ps1')


class ReleaseArchiveTest(unittest.TestCase):
    def check_member(self, name):
        with tempfile.TemporaryDirectory() as directory:
            archive = Path(directory) / 'synthetic-release.tar.gz'
            with tarfile.open(archive, 'w:gz') as tar:
                data = b'synthetic test fixture\n'
                member = tarfile.TarInfo(name)
                member.size = len(data)
                tar.addfile(member, io.BytesIO(data))
            return subprocess.run(
                ['pwsh', '-NoProfile', '-File', str(GUARD), '-ArchivePath', str(archive)],
                capture_output=True, text=True,
            ).returncode

    def test_source_and_placeholder_allowed(self):
        for name in ['app/Services/Sample.php', '.env.example', 'nested/.env.example']:
            with self.subTest(name=name):
                self.assertEqual(self.check_member(name), 0)

    def test_secret_and_local_artifacts_rejected(self):
        for name in ['.env', './.env.production', 'nested/.env.local',
                     'keys/server.pem', 'nested/id_ed25519', 'database/database.sqlite',
                     'bootstrap/cache/config.php', 'output/old.tar.gz',
                     'quarantine-2026/archive.tar.gz', 'nested/.git/config', '../outside.php']:
            with self.subTest(name=name):
                self.assertNotEqual(self.check_member(name), 0)

    def test_workflow_protects_backups_and_opt_in_integrations(self):
        workflow = GUARD.with_name('Deploy-App.ps1').read_text()
        self.assertIn("--exclude='.env*'", workflow)
        self.assertNotIn("--exclude='./.env' ", workflow)
        self.assertIn('if [ "$configure_stripe" = "1" ]; then', workflow)
        self.assertIn('if (-not $PreparedRelease)', workflow)
        self.assertIn("@('push', 'origin', 'HEAD:main')", workflow)
        self.assertIn('chmod 0700 "$backup"', workflow)
        self.assertNotIn('umask 077', workflow)


if __name__ == '__main__':
    unittest.main()
