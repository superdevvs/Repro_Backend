"""Portable fixture tests. No rclone installation, network, credentials or database."""
import copy
import importlib.util
import io
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location("publish_backup", Path(__file__).with_name("publish_backup.py"))
p = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(p)

CONFIG = b"""[encrypted]
type = crypt
remote = storage:example-bucket/repro-fixture
password = fixture-not-a-real-secret
password2 = fixture-not-a-real-secret
[storage]
type = s3
provider = Cloudflare
endpoint = https://example.invalid
env_auth = false
access_key_id = fixture-only
secret_access_key = fixture-only
no_check_bucket = true
"""


class FixtureRemote:
    def __init__(self, corrupt=None, fail=None):
        self.objects, self.calls = {}, []
        self.corrupt, self.fail = corrupt, fail

    def empty(self, prefix):
        self.calls.append(("empty", prefix))
        p.need(not self.objects, "prefix exists")

    def copy(self, source, destination):
        target = destination.rstrip("/") + "/" + source.name
        self.calls.append(("copy", target))
        p.need(not target.endswith(self.fail or "IMPOSSIBLE"), "fixture copy failure")
        self.objects[target] = source.read_bytes()

    def readback(self, remote, size, digest):
        self.calls.append(("readback", remote))
        data = self.objects[remote]
        if remote.endswith(self.corrupt or "IMPOSSIBLE"):
            data = b"X" * len(data)  # Same-size corruption must fail.
        p.need(len(data) == size and p.sha256(data) == digest, "fixture readback mismatch")


class BackupTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.source = self.root / "source"
        self.source.mkdir()
        (self.source / "base.sql.zst").write_bytes(b"closed database fixture")
        self.manifest = {"schema": p.INPUT_SCHEMA,
                         "backup_id": "7ca011d3-b197-48c6-99d0-af07d99d3c08",
                         "created_at": "2026-11-22T10:00:00Z", "scope": "database",
                         "release": "a" * 40, "identity": "fixture-instance",
                         "artifacts": [{"path": "base.sql.zst", "bytes": 23,
                                        "sha256": p.sha256(b"closed database fixture")}]}

    def parse(self, value=None, **overrides):
        value = value or self.manifest
        raw = p.encode(value)
        args = dict(expected_sha256=p.sha256(raw), scope="database", release="a" * 40,
                    identity="fixture-instance")
        args.update(overrides)
        return p.parse_manifest(raw, **args)

    def publish(self, remote):
        def fixture_hash(path):
            data = path.read_bytes()
            return len(data), p.sha256(data)
        with patch.object(p, "local_hash", side_effect=fixture_hash):
            return p.publish(self.parse(), p.encode(self.manifest), self.source,
                             "encrypted", remote, self.root)

    def test_complete_is_last_write_and_every_object_read_back(self):
        remote = FixtureRemote()
        report = self.publish(remote)
        writes = [target for action, target in remote.calls if action == "copy"]
        reads = [target for action, target in remote.calls if action == "readback"]
        self.assertEqual(writes, reads)
        self.assertTrue(writes[-1].endswith("/completion.json"))
        self.assertEqual(report["artifact_bytes"], 23)
        self.assertFalse(report["restore_verified"])
        self.assertFalse(report["retention_verified"])
        marker = json.loads(remote.objects[writes[-1]])
        self.assertEqual(marker["input_manifest_sha256"], p.sha256(p.encode(self.manifest)))

    def test_same_size_remote_corruption_prevents_completion(self):
        remote = FixtureRemote(corrupt="base.sql.zst")
        with self.assertRaises(p.Refusal):
            self.publish(remote)
        self.assertFalse(any(path.endswith("completion.json") for path in remote.objects))

    def test_input_manifest_readback_failure_prevents_completion(self):
        remote = FixtureRemote(corrupt="input-manifest.json")
        with self.assertRaises(p.Refusal):
            self.publish(remote)
        self.assertFalse(any(path.endswith("completion.json") for path in remote.objects))

    def test_completion_readback_failure_never_returns_success(self):
        with self.assertRaises(p.Refusal):
            self.publish(FixtureRemote(corrupt="completion.json"))

    def test_prefix_reuse_refuses_before_writes(self):
        remote = FixtureRemote()
        remote.objects["old/completion.json"] = b"old"
        with self.assertRaises(p.Refusal):
            self.publish(remote)
        self.assertEqual([action for action, _ in remote.calls], ["empty"])

    def test_all_local_artifacts_checked_before_first_upload(self):
        self.manifest["artifacts"].append({"path": "missing.zst", "bytes": 0, "sha256": p.sha256(b"")})
        remote = FixtureRemote()
        with self.assertRaises(FileNotFoundError):
            self.publish(remote)
        self.assertFalse(any(action == "copy" for action, _ in remote.calls))

    def test_local_corruption_refuses_upload(self):
        (self.source / "base.sql.zst").write_bytes(b"corrupt")
        remote = FixtureRemote()
        with self.assertRaises(p.Refusal):
            self.publish(remote)
        self.assertFalse(remote.objects)

    def test_scope_release_identity_and_manifest_hash_must_match(self):
        for key, value in (("scope", "application"), ("release", "b" * 40),
                           ("identity", "other-instance"), ("expected_sha256", "0" * 64)):
            with self.subTest(key=key), self.assertRaises(p.Refusal):
                self.parse(**{key: value})

    def test_impossible_transfer_budget_is_rejected_before_publication(self):
        p.transfer_budget(self.manifest, 60)
        self.manifest["artifacts"][0]["bytes"] = 10 * 1024**4
        with self.assertRaises(p.Refusal):
            p.transfer_budget(self.manifest, 21600)
        self.manifest["artifacts"][0]["bytes"] = 100 * 1024**3
        with self.assertRaises(p.Refusal):
            p.transfer_budget(self.manifest, 3600)
        p.transfer_budget(self.manifest, 21600)

    def test_local_only_flag_is_available_without_invoking_transport(self):
        with patch("sys.argv", ["publish_backup.py", "--help"]), \
             patch("sys.stdout", new_callable=io.StringIO) as output, \
             patch.object(p.subprocess, "run") as run, \
             self.assertRaises(SystemExit) as ended:
            p.main()
        self.assertEqual(ended.exception.code, 0)
        self.assertIn("--check-local-only", output.getvalue())
        run.assert_not_called()

    def test_paths_duplicates_and_file_directory_collisions_refused(self):
        for path in ("../secret", "/secret", "C:\\secret", "a//b", "a/./b", "a/../b", "--flag"):
            value = copy.deepcopy(self.manifest)
            value["artifacts"][0]["path"] = path
            with self.subTest(path=path), self.assertRaises(p.Refusal):
                self.parse(value)
        for path in ("base.sql.zst", "base.sql.zst/child"):
            value = copy.deepcopy(self.manifest)
            extra = dict(value["artifacts"][0], path=path)
            value["artifacts"].append(extra)
            with self.assertRaises(p.Refusal):
                self.parse(value)

    def test_crypt_scope_and_encryption_controls(self):
        p.validate_config(CONFIG, "encrypted", "storage:example-bucket/repro-fixture")
        for before, after in ((b"type = crypt", b"type = alias"),
                              (b"type = s3", b"type = local"),
                              (b"env_auth = false", b"env_auth = true"),
                              (b"no_check_bucket = true", b"no_check_bucket = false"),
                              (b"type = crypt", b"type = crypt\nno_data_encryption = true"),
                              (b"type = crypt", b"type = crypt\npass_bad_blocks = true")):
            with self.subTest(after=after), self.assertRaises(p.Refusal):
                p.validate_config(CONFIG.replace(before, after), "encrypted", "storage:example-bucket/repro-fixture")
        with self.assertRaises(p.Refusal):
            p.validate_config(CONFIG, "encrypted", "storage:other-bucket/prefix")

    def test_rclone_copy_is_immutable_and_environment_is_scrubbed(self):
        remote = p.Rclone(Path("/usr/bin/rclone"), Path("/private/rclone.conf"), 60)
        with patch.object(p.subprocess, "run", return_value=subprocess.CompletedProcess([], 0, b"")) as run:
            remote.copy(Path("/private/base.zst"), "encrypted:database/id/uuid/artifacts")
        args, kwargs = run.call_args
        self.assertEqual(args[0][1], "copy")
        self.assertIn("--immutable", args[0])
        self.assertIn("--no-update-modtime", args[0])
        self.assertIn("--crypt-strict-names", args[0])
        self.assertEqual(kwargs["env"], p.SAFE_ENV)
        self.assertEqual(kwargs["stderr"], subprocess.DEVNULL)

    def test_only_directory_not_found_is_treated_as_empty(self):
        remote = p.Rclone(Path("/usr/bin/rclone"), Path("/private/rclone.conf"), 60)
        for code in (1, 2, 4, 5, 7):
            with patch.object(p.subprocess, "run", return_value=subprocess.CompletedProcess([], code, b"")):
                with self.subTest(code=code), self.assertRaises(p.Refusal):
                    remote.empty("encrypted:database/id/uuid")
        with patch.object(p.subprocess, "run", return_value=subprocess.CompletedProcess([], 3, b"")):
            remote.empty("encrypted:database/id/uuid")

    def test_stream_readback_checks_size_hash_and_exit_code(self):
        class Process:
            def __init__(self, content, code):
                self.stdout, self.code = io.BytesIO(content), code
            def wait(self, **kwargs): return self.code
            def poll(self): return self.code
            def kill(self): self.code = -9
        remote = p.Rclone(Path("/usr/bin/rclone"), Path("/private/rclone.conf"), 60)
        for data, code, valid in ((b"hello", 0, True), (b"HELLO", 0, False),
                                  (b"hell", 0, False), (b"helloo", 0, False), (b"hello", 1, False)):
            with patch.object(p.subprocess, "Popen", return_value=Process(data, code)):
                if valid:
                    remote.readback("encrypted:file", 5, p.sha256(b"hello"))
                else:
                    with self.subTest(data=data, code=code), self.assertRaises(p.Refusal):
                        remote.readback("encrypted:file", 5, p.sha256(b"hello"))


if __name__ == "__main__":
    unittest.main()
