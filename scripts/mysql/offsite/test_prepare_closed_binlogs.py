"""Closed archive staging tests with native-format synthetic event frames."""
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

import prepare_closed_binlogs as archive
import publish_backup as backup
from test_publish_backup import FixtureRemote
from test_verify_mysql_binlog_chain import SERVER_ID, UUID, segment


class PrepareClosedBinlogsTest(unittest.TestCase):
    def setUp(self):
        base = os.environ.get("REPRO_OFFSITE_TEST_ROOT", str(Path.home()))
        temporary = tempfile.TemporaryDirectory(dir=base, prefix="repro-archive-fixture-")
        self.addCleanup(temporary.cleanup)
        self.addCleanup(os.umask, os.umask(0o077))
        self.root = Path(temporary.name) / "staged"
        self.root.mkdir(mode=0o700)
        self.release = "a" * 40
        self.files = ["binlog.000041", "binlog.000042", "binlog.000043"]
        self.inventory = {"schema": archive.SCHEMA, "source_uuid": UUID, "server_id": SERVER_ID,
                          "sealed_at": "2026-11-24T08:03:01Z", "next_file": "binlog.000044", "segments": []}
        for name in self.files:
            self.inventory["segments"].append(self.write_file(name, segment(archive.chain.next_log(name))[0]))
        if os.name != "posix":
            # Exercise real event verification and manifest validation locally.
            # Linux uses the actual permission checks and atomic manifest writer.
            self.enterContext(patch.object(backup, "private_path"))
            self.enterContext(patch.object(backup, "write_input_manifest", side_effect=self.portable_write))

    def portable_write(self, root, manifest):
        raw = backup.encode(manifest)
        digest = backup.sha256(raw)
        backup.parse_manifest(raw, digest, manifest["scope"], manifest["release"], manifest["identity"])
        with (root / archive.OUTPUT).open("xb") as stream:
            stream.write(raw)
        return digest, len(raw)

    def write_file(self, name, content):
        (self.root / name).write_bytes(content)
        return {"file": name, "bytes": len(content), "sha256": backup.sha256(content)}

    def prepare(self, **changes):
        raw = backup.encode(self.inventory)
        (self.root / archive.INVENTORY).write_bytes(raw)
        kwargs = {"inventory_sha256": backup.sha256(raw), "release": self.release, "identity": UUID,
                  "server_id": SERVER_ID, "first_file": self.files[0]}
        return archive.prepare(self.root, **(kwargs | changes))

    def assert_no_manifest(self):
        self.assertFalse(os.path.lexists(self.root / archive.OUTPUT))

    def test_verifies_all_closed_logs_and_hands_them_to_ordinary_publisher(self):
        report = self.prepare()
        self.assertEqual(report["first_file"], self.files[0])
        self.assertEqual(report["next_file"], "binlog.000044")
        self.assertEqual(report["segments"], 3)
        self.assertEqual(report["identity"], UUID + ".closed-binlogs")
        self.assertIs(report["remote_accessed"], False)
        self.assertIs(report["archive_publication_verified"], False)
        raw = (self.root / archive.OUTPUT).read_bytes()
        manifest = backup.parse_manifest(raw, report["manifest_sha256"], "database", self.release, report["identity"])
        self.assertEqual(manifest["created_at"], self.inventory["sealed_at"])
        self.assertEqual([item["path"] for item in manifest["artifacts"]], self.files + [archive.INVENTORY])
        with self.assertRaisesRegex(backup.Refusal, "identity"):
            backup.parse_manifest(raw, report["manifest_sha256"], "database", self.release, UUID)
        remote = FixtureRemote()
        with tempfile.TemporaryDirectory(dir=self.root.parent) as scratch:
            published = backup.publish(manifest, raw, self.root, "encrypted", remote, Path(scratch))
        self.assertEqual(published["artifact_count"], 4)
        for item in manifest["artifacts"]:
            self.assertEqual(remote.objects[published["remote"] + "/artifacts/" + item["path"]],
                             (self.root / item["path"]).read_bytes())
        with self.assertRaisesRegex(backup.Refusal, "already exists"):
            self.prepare()
        self.assertEqual((self.root / archive.OUTPUT).read_bytes(), raw)

    def test_refuses_wrong_independently_reviewed_receipt_source_or_anchor(self):
        for options in ({"inventory_sha256": "0" * 64}, {"server_id": SERVER_ID + 1},
                        {"identity": "21111111-2222-3333-4444-555555555555"},
                        {"first_file": self.files[1]}):
            with self.subTest(options=options), self.assertRaises(backup.Refusal):
                self.prepare(**options)
            self.assert_no_manifest()

    def test_refuses_sequence_gaps_forged_next_file_and_unlisted_entries(self):
        original = self.inventory["segments"]
        self.inventory["segments"] = [original[0], original[2]]
        with self.assertRaisesRegex(backup.Refusal, "continue at the expected file"):
            self.prepare()
        self.inventory["segments"] = original
        self.inventory["next_file"] = "binlog.000045"
        with self.assertRaisesRegex(backup.Refusal, "next file disagrees"):
            self.prepare()
        self.inventory["next_file"] = "binlog.000044"
        (self.root / "binlog.000044").write_bytes(b"unlisted active file")
        with self.assertRaisesRegex(backup.Refusal, "unexpected entries"):
            self.prepare()
        (self.root / "binlog.000044").unlink()
        (self.root / self.files[1]).unlink()
        with self.assertRaisesRegex(backup.Refusal, "missing or unexpected entries"):
            self.prepare()
        self.assert_no_manifest()

    def test_checks_native_bytes_even_when_each_modified_inventory_is_re_pinned(self):
        name = self.files[1]
        content, start, _ = segment(self.files[2])
        content[start + archive.chain.EVENT_HEADER.size] ^= 1
        for changed, reason in (
            (content, "checksum"),
            (segment(self.files[2], open_flag=1)[0], "still open"),
            (segment("binlog.000049")[0], "rotation target"),
        ):
            with self.subTest(reason=reason), self.assertRaisesRegex(backup.Refusal, reason):
                self.inventory["segments"][1] = self.write_file(name, changed)
                self.prepare()
            self.assert_no_manifest()

    @unittest.skipUnless(os.name == "posix", "current staging permission contract requires Linux")
    def test_refuses_symlinked_segment_even_with_correct_inventory_digest(self):
        path = self.root / self.files[0]
        hidden = self.root.parent / "substitute"
        path.rename(hidden)
        path.symlink_to(hidden)
        with self.assertRaisesRegex(backup.Refusal, "unsafe path"):
            self.prepare()
        self.assert_no_manifest()


if __name__ == "__main__":
    unittest.main()
