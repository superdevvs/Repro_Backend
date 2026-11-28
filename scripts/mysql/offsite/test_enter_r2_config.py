"""No-echo R2 config entry: separate upload and restore roles, one config per prefix."""
from __future__ import annotations

import contextlib
import io
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

import enter_r2_config as entry
import publish_backup as backup

ENDPOINT = "https://" + "0" * 32 + ".r2.cloudflarestorage.com"
ACCESS = "a" * 32
SECRET = "b" * 64


class RenderTests(unittest.TestCase):
    def test_each_prefix_gets_its_own_reviewed_crypt_and_s3_pair(self):
        configs = entry.render_configs(ENDPOINT, "repro-db-backups", ["daily", "binlog"], ACCESS, SECRET, None,
                                       "obscured-password-123456", "obscured-salt-1234567890")
        self.assertEqual(sorted(configs), ["rclone-binlog.conf", "rclone-daily.conf"])
        for name, raw in configs.items():
            prefix = name[len("rclone-"):-len(".conf")]
            backup.validate_config(raw, "repro_encrypted", f"storage:repro-db-backups/{prefix}")

    def test_session_token_is_written_only_when_supplied(self):
        plain = entry.render_configs(ENDPOINT, "repro-db-backups", ["daily"], ACCESS, SECRET, None, "p" * 24, "s" * 24)
        token = entry.render_configs(ENDPOINT, "repro-db-backups", ["daily"], ACCESS, SECRET, "t0k3n", "p" * 24, "s" * 24)
        self.assertNotIn(b"session_token", plain["rclone-daily.conf"])
        self.assertIn(b"session_token=t0k3n\n", token["rclone-daily.conf"])

    def test_destination_rules(self):
        for endpoint, bucket, prefix in ((ENDPOINT.replace("https", "http"), "b-1", "daily"),
                                         (ENDPOINT + "/x", "b-1", "daily"), (ENDPOINT, "B", "daily"),
                                         (ENDPOINT, "repro-db-backups", "../daily"), (ENDPOINT, "repro-db-backups", "")):
            with self.assertRaises(entry.Refusal):
                entry.validate_destination(endpoint, bucket, prefix)

    def test_prompts_name_the_role(self):
        self.assertIn("UPLOAD", entry.PROMPTS["upload"]["access"])
        self.assertIn("READ-ONLY", entry.PROMPTS["restore"]["access"])

    def test_restore_role_refuses_the_server_upload_paths(self):
        with self.assertRaises(entry.Refusal):
            entry.check_role("restore", Path("/var/lib/repro-backup/credentials"))
        entry.check_role("restore", Path("/var/lib/repro-recovery/credentials"))
        entry.check_role("upload", Path("/var/lib/repro-backup/credentials"))


@unittest.skipUnless(os.name == "posix", "private file creation needs Linux")
class CreateTests(unittest.TestCase):
    def test_creates_private_files_and_prints_only_the_key_fingerprint(self):
        answers = iter([ACCESS, SECRET, "correct horse battery staple one", "correct horse battery staple two"])
        with tempfile.TemporaryDirectory(dir=Path.home()) as name:
            parent = Path(name)
            os.chmod(parent, 0o700)
            target = parent / "credentials"
            stdout = io.StringIO()
            with (patch.object(entry, "read_secret", lambda *_a, **_k: next(answers)),
                  patch.object(entry, "obscure", lambda _rclone, value: "obscured" + str(len(value)) * 20),
                  patch.object(entry, "terminal", contextlib.nullcontext),
                  contextlib.redirect_stdout(stdout)):
                code = entry.main(["--role", "upload", "--endpoint", ENDPOINT, "--bucket", "repro-db-backups",
                                   "--prefix", "daily", "--prefix", "binlog", "--config-dir", str(target),
                                   "--rclone", "/usr/bin/true", "--skip-rclone-check"])
            self.assertEqual(code, 0)
            self.assertEqual(os.stat(target).st_mode & 0o777, 0o700)
            for item in target.iterdir():
                self.assertEqual(item.stat().st_mode & 0o777, 0o600)
            text = stdout.getvalue()
            for secret in (ACCESS, SECRET, "correct horse"):
                self.assertNotIn(secret, text)
            self.assertIn(backup.sha256(ACCESS.encode()), text)


if __name__ == "__main__":
    unittest.main()
