"""Archive boundaries for the disposable real-application HTTP fixture."""
import hashlib
import importlib.util
import io
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


if __name__ == '__main__':
    unittest.main()
