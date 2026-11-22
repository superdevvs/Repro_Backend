import hashlib
import json
import os
from pathlib import Path
import tempfile
from types import SimpleNamespace
import unittest

import restore_backup as restore
from publish_backup import COMPLETE_SCHEMA, INPUT_SCHEMA, Refusal, encode


def digest(data):
    return hashlib.sha256(data).hexdigest()


class MemoryRemote:
    def __init__(self, objects):
        self.objects = objects
        self.reads = []

    def readback(self, path, length, sha256, sink):
        self.reads.append(path)
        data = self.objects.get(path)
        if data is None or len(data) != length or digest(data) != sha256:
            raise Refusal("remote payload unavailable or corrupt")
        sink.write(data)


@unittest.skipUnless(os.name == "posix", "requires POSIX file permissions")
class RestoreTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        # Linux fixture runners can supply a protected base outside shared /tmp.
        self.root = Path(self.temp.name).resolve()
        self.output = self.root / "new-output"
        self.umask = os.umask(0o077)
        self.addCleanup(os.umask, self.umask)
        self.identity = "mysql-recovery-fixture"
        self.backup_id = "794d76f7-6487-4378-b492-5e87d6c6beb3"
        self.prefix = f"crypt:database/{self.identity}/{self.backup_id}"
        self.payload = b"only synthetic backup data"
        self.manifest = {
            "schema": INPUT_SCHEMA, "scope": "database", "backup_id": self.backup_id,
            "created_at": "2026-11-22T08:00:00Z", "release": "a" * 40,
            "identity": self.identity,
            "artifacts": [{"path": "sql/base.sql.gz", "bytes": len(self.payload), "sha256": digest(self.payload)}],
        }
        self.refresh_metadata()

    def refresh_metadata(self, nested_manifest=None):
        raw = encode(self.manifest)
        completion = encode({"schema": COMPLETE_SCHEMA, "status": "artifact-readback-verified",
                             "input": self.manifest if nested_manifest is None else nested_manifest,
                             "input_manifest_sha256": digest(raw)})
        self.remote = MemoryRemote({
            self.prefix + "/completion.json": completion,
            self.prefix + "/input-manifest.json": raw,
            self.prefix + "/artifacts/sql/base.sql.gz": self.payload,
        })
        self.args = SimpleNamespace(
            output=self.output, crypt_remote="crypt", scope="database", backup_id=self.backup_id,
            expected_identity=self.identity, expected_release=self.manifest["release"],
            completion_bytes=len(completion), completion_sha256=digest(completion),
            manifest_bytes=len(raw), manifest_sha256=digest(raw), timeout_seconds=60,
        )

    def test_verifies_then_exposes_private_tree(self):
        report = restore.restore(self.args, self.remote)
        self.assertEqual(report["artifact_count"], 1)
        self.assertFalse(report["database_restore_verified"])
        receipt = json.loads((self.output / "recovery-receipt.json").read_bytes())
        self.assertEqual(receipt, report)
        self.assertEqual((self.output / "artifacts/sql/base.sql.gz").read_bytes(), self.payload)
        self.assertEqual((self.output / "artifacts/sql/base.sql.gz").stat().st_mode & 0o777, 0o600)
        self.assertEqual(self.output.stat().st_mode & 0o777, 0o700)

    def test_refuses_completion_that_disagrees_with_pinned_manifest(self):
        self.refresh_metadata(self.manifest | {"scope": "application"})
        with self.assertRaisesRegex(Refusal, "completion record"):
            restore.restore(self.args, self.remote)
        self.assertEqual(len(self.remote.reads), 2)
        self.assertFalse(self.output.exists())

    def test_corrupt_or_missing_artifact_is_never_exposed(self):
        artifact = self.prefix + "/artifacts/sql/base.sql.gz"
        for remote_value in (None, b"x" * len(self.payload)):
            with self.subTest(remote_value=remote_value):
                self.remote.objects[artifact] = remote_value
                with self.assertRaisesRegex(Refusal, "unavailable or corrupt"):
                    restore.restore(self.args, self.remote)
                self.assertEqual(list(self.root.iterdir()), [])

    def test_refuses_modified_marker_before_reading_artifacts(self):
        self.remote.objects[self.prefix + "/completion.json"] += b"x"
        with self.assertRaises(Refusal):
            restore.restore(self.args, self.remote)
        self.assertEqual(len(self.remote.reads), 1)
        self.assertFalse(self.output.exists())

    def test_existing_target_is_preserved(self):
        self.output.mkdir()
        original = self.output / "original"
        original.write_bytes(b"preserve")
        with self.assertRaises(FileExistsError):
            restore.restore(self.args, self.remote)
        self.assertEqual(list(self.output.iterdir()), [original])
        self.assertEqual(original.read_bytes(), b"preserve")


if __name__ == "__main__":
    unittest.main()
