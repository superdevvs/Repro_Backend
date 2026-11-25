"""Recovery kit: staging, change detection, no secret output, and restore-side decryption."""
from __future__ import annotations

import base64
import contextlib
import io
import json
import os
from pathlib import Path
import secrets
import shutil
import subprocess
import tempfile
import time
import unittest
from unittest.mock import patch

import publish_backup as backup

PHP = shutil.which("php")
VERIFIER = Path(__file__).with_name("verify_recovery_kit.php")
ENCRYPT = r"""
$key = base64_decode(substr($argv[1], 7));
$iv = random_bytes(16);
$value = openssl_encrypt($argv[2], 'aes-256-cbc', $key, 0, $iv);
$iv = base64_encode($iv);
$mac = hash_hmac('sha256', $iv.$value, $key);
echo base64_encode(json_encode(['iv' => $iv, 'value' => $value, 'mac' => $mac, 'tag' => ''], JSON_UNESCAPED_SLASHES));
"""


@unittest.skipUnless(os.name == "posix", "the kit producer uses Linux private-file checks")
class RecoveryKitTests(unittest.TestCase):
    def setUp(self):
        import produce_recovery_kit as kit

        self.kit = kit
        folder = tempfile.TemporaryDirectory(dir=Path.home())
        self.addCleanup(folder.cleanup)
        self.root = Path(folder.name)
        os.chmod(self.root, 0o700)
        self.app_key = "base64:" + base64.b64encode(secrets.token_bytes(32)).decode()
        self.env = self.root / "app.env.source"
        self.env.write_text(f"APP_NAME=Repro\nAPP_KEY={self.app_key}\nDB_PASSWORD=hunter2-fixture\n")
        self.cnf = self.root / "mysql.cnf.source"
        self.cnf.write_text("[mysqld]\nserver-id=64084\n")
        self.state = self.root / "state"
        self.state.mkdir(mode=0o700)
        self.plan = {"version": 1, "release": "a" * 40, "identity": "repro-app-recovery-kit",
                     "state_dir": str(self.state),
                     "files": [{"source": str(self.env), "name": "app.env"},
                               {"source": str(self.cnf), "name": "mysql.cnf"}],
                     "rclone_config": "/var/lib/repro-backup/credentials/rclone-daily.conf",
                     "rclone": "/usr/bin/rclone", "crypt_remote": "repro_encrypted",
                     "destination": "storage:bucket/daily", "transfer_timeout_seconds": 600,
                     "run_timeout_seconds": 900}
        self.published = []
        self.bucket = self.root / "bucket"

    def publish(self, batch: Path, manifest_sha256: str) -> dict:
        manifest = backup.parse_manifest((batch / "input-manifest.json").read_bytes(), manifest_sha256,
                                         "application", self.plan["release"], self.plan["identity"])
        copy = self.bucket / manifest["backup_id"] / "artifacts"
        copy.mkdir(parents=True)
        for item in manifest["artifacts"]:
            shutil.copyfile(batch / item["path"], copy / item["path"])
        self.published.append(manifest["backup_id"])
        return {"result": "published-readback-verified", "backup_id": manifest["backup_id"],
                "artifact_count": len(manifest["artifacts"])}

    def run_kit(self):
        stdout = io.StringIO()
        with contextlib.redirect_stdout(stdout):
            result = self.kit.run(self.kit.parse_plan(json.dumps(self.plan).encode()), publish=self.publish)
        return result, stdout.getvalue() + json.dumps(result)

    def test_kit_holds_exact_files_and_metadata_and_publishes_application_scope(self):
        result, output = self.run_kit()
        self.assertEqual(result["result"], "recovery-kit-published")
        restored = self.bucket / self.published[0] / "artifacts"
        self.assertEqual((restored / "app.env").read_bytes(), self.env.read_bytes())
        self.assertEqual((restored / "mysql.cnf").read_bytes(), self.cnf.read_bytes())
        meta = json.loads((restored / "kit.json").read_text())
        self.assertEqual(meta["schema"], "repro.recovery-kit.v1")
        self.assertTrue(meta["app_key_present"])
        self.assertEqual([item["name"] for item in meta["files"]], ["app.env", "mysql.cnf"])
        self.assertEqual(list(self.state.glob("stage-*")), [])

    def test_output_never_contains_env_values(self):
        _, output = self.run_kit()
        for secret in (self.app_key, self.app_key[7:], "hunter2-fixture"):
            self.assertNotIn(secret, output)

    def test_unchanged_files_are_not_republished(self):
        self.run_kit()
        result, _ = self.run_kit()
        self.assertEqual(result["result"], "recovery-kit-unchanged")
        self.assertEqual(len(self.published), 1)
        self.cnf.write_text("[mysqld]\nserver-id=64085\n")
        self.assertEqual(self.run_kit()[0]["result"], "recovery-kit-published")
        self.assertEqual(len(self.published), 2)

    def test_an_unchanged_kit_is_republished_before_the_bucket_expires_it(self):
        # The kit lives under daily/, whose lifecycle rule deletes objects after 31
        # days. Publishing only on change would leave no kit once .env sat unchanged
        # for a month, so an unchanged kit is published again every week.
        import install_backup_host as installer

        self.run_kit()
        start = time.time()
        with patch.object(self.kit.time, "time", return_value=start + 6 * 86400):
            self.assertEqual(self.run_kit()[0]["result"], "recovery-kit-unchanged")
        with patch.object(self.kit.time, "time", return_value=start + 8 * 86400):
            self.assertEqual(self.run_kit()[0]["result"], "recovery-kit-published")
        self.assertEqual(len(self.published), 2)
        prefix = self.plan_for_installer()["destination"].split("/", 1)[1] + "/"
        self.assertLessEqual(4 * self.kit.REPUBLISH_AFTER_SECONDS, installer.RETENTION_DAYS[prefix] * 86400)

    def plan_for_installer(self):
        import install_backup_host as installer

        settings = installer.parse_settings(json.dumps({
            "version": 1, "release": "a" * 40, "identity": "repro-sqlite-maverick", "bucket": "repro-db-backups",
            "endpoint": "https://" + "5" * 32 + ".r2.cloudflarestorage.com"}).encode())
        job = installer.build_job("recovery-kit", settings)
        return json.loads(next(item.data for item in job.files if item.path.endswith("recovery-kit.json")))

    def test_env_without_app_key_is_refused(self):
        self.env.write_text("APP_NAME=Repro\nAPP_KEY=\n")
        with self.assertRaises(backup.Refusal):
            self.run_kit()
        self.assertEqual(self.published, [])

    def test_failed_publication_keeps_no_state(self):
        def broken(batch, manifest_sha256):
            raise backup.Refusal("rclone operation failed: copy")
        self.publish = broken
        with self.assertRaises(backup.Refusal):
            self.run_kit()
        self.assertFalse((self.state / self.kit.STATE_FILE).exists())

    @unittest.skipUnless(PHP, "php is needed for the restore-side decryption check")
    def test_restored_kit_decrypts_a_stored_value_and_rejects_a_wrong_key(self):
        self.run_kit()
        restored = self.bucket / self.published[0]
        token = subprocess.run([PHP, "-r", ENCRYPT, self.app_key, "fixture stored secret"], check=True,
                               capture_output=True).stdout
        cipher = self.root / "cipher.txt"
        cipher.write_bytes(token)
        expected = backup.sha256(b"fixture stored secret")
        good = subprocess.run([PHP, str(VERIFIER), str(restored), str(cipher), expected], capture_output=True)
        self.assertEqual(good.returncode, 0, good.stdout)
        self.assertEqual(json.loads(good.stdout), {"result": "stored-value-decrypted", "plaintext_sha256": expected,
                                                   "kit_files_verified": 2})
        for secret in (self.app_key[7:], b"fixture stored secret".decode()):
            self.assertNotIn(secret, (good.stdout + good.stderr).decode())
        other = "base64:" + base64.b64encode(secrets.token_bytes(32)).decode()
        env = restored / "artifacts" / "app.env"
        os.chmod(env, 0o600)
        env.write_text(env.read_text().replace(self.app_key, other))
        bad = subprocess.run([PHP, str(VERIFIER), str(restored), str(cipher), expected], capture_output=True)
        self.assertEqual((bad.returncode, json.loads(bad.stdout)["reason"]), (1, "kit file hash differs"))
        meta_path = restored / "artifacts" / "kit.json"
        meta = json.loads(meta_path.read_text())
        meta["files"][0].update(bytes=env.stat().st_size, sha256=backup.sha256(env.read_bytes()))
        os.chmod(meta_path, 0o600)
        meta_path.write_text(json.dumps(meta))
        wrong = subprocess.run([PHP, str(VERIFIER), str(restored), str(cipher), expected], capture_output=True)
        self.assertEqual(wrong.returncode, 1)
        self.assertEqual(json.loads(wrong.stdout), {"result": "REFUSED",
                                                     "reason": "stored value did not decrypt with the recovered key"})


if __name__ == "__main__":
    unittest.main()
