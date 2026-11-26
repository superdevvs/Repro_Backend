"""The `.env` switch (design v2, 3.2 steps 5 to 7; build plan D10): envfile.py.

`prepare` writes the switch keys into the app's `.env` and `seal_sqlite` restores them; nothing else in the
file changes. These tests check that against the identity probe's own digest (review A2-11): the switched
file has the same `config_sha256` and is never refused by the probe, and restoring the recorded SQLite
values gives back the original bytes.

- ValueTests, AssignTests, DigestTests run on any system as any user (pure functions over bytes).
- ReplaceTests need Linux root: owner, group, mode and every extended attribute (the POSIX ACL is
  `system.posix_acl_access`, written here as its kernel blob) are kept across the atomic replacement.

    python3 -B -m unittest discover -s scripts/cutover/production -p test_envfile.py -v
"""

from __future__ import annotations

import hashlib
import os
import shutil
import stat
import struct
import sys
import tempfile
import unittest
import uuid
from pathlib import Path
from unittest.mock import patch

HERE = Path(__file__).resolve().parent
sys.path[:0] = [str(HERE.parent), str(HERE)]
import envfile  # noqa: E402
import identity_probe  # noqa: E402
from test_adapter import NIGHT_FLAGS, SWITCH_KEYS, example_config  # noqa: E402

LINUX_ROOT = sys.platform == "linux" and hasattr(os, "geteuid") and os.geteuid() == 0
DENYLIST = sorted(NIGHT_FLAGS + SWITCH_KEYS)
# What prepare writes: host.json's switch plus the app password from its root-only file.
SWITCH = {**example_config()["mysql"]["switch"], "DB_PASSWORD": "fixture-app-password-0123456789"}

# A production-shaped SQLite .env: the keys production has (DB_CONNECTION, DB_DATABASE, DB_BUSY_TIMEOUT,
# DB_JOURNAL_MODE, DB_SYNCHRONOUS), night flags, comments, quoting and an inline comment on other keys.
PRODUCTION = (
    b"APP_NAME=RePro\n"
    b"APP_ENV=production\n"
    b"APP_KEY=base64:Zml4dHVyZS1rZXktbm90LXJlYWwtMzItYnl0ZXMhIQ==\n"
    b"APP_DEBUG=false\n"
    b"\n"
    b"# Database\n"
    b"DB_CONNECTION=sqlite\n"
    b"DB_DATABASE=/var/www/backend/database/database.sqlite\n"
    b"DB_BUSY_TIMEOUT=5000\n"
    b"DB_JOURNAL_MODE=wal\n"
    b"DB_SYNCHRONOUS=normal\n"
    b"MAIL_FROM_NAME=\"Re Pro \\\"Media\\\"\"\n"
    b"QUEUE_CONNECTION=database # the jobs table\n"
    b"CRM_OUTBOUND_HOLD=true\n"
    b"   # an indented comment\n"
    b"SERVICES_NOTE='caf\xc3\xa9 = ok'\n"
)
# Shapes the identity probe accepts, each with something a careless writer would change.
CORPUS = {
    "production": PRODUCTION,
    "crlf": PRODUCTION.replace(b"\n", b"\r\n"),
    "no switch key at all": b"APP_NAME=RePro\nAPP_KEY=base64:abc==\n",
    "every switch key present": b"".join(f"{key}=old-{key.lower()}\n".encode() for key in SWITCH) + b"APP_ENV=x\n",
    "switch keys last, empty values": b"APP_ENV=x\nDB_SOCKET=\nDB_CONNECTION=sqlite\n",
    "night flags around switch keys": (b"SCHEDULER_HOLD_ENABLED=true\nDB_CONNECTION=sqlite\n"
                                       b"PRODUCER_INGRESS_HOLD=1\nDB_USERNAME=\n"),
    "only comments": b"# nothing\n\n   \n",
    "empty": b"",
    "no newline at the end, every key present": b"".join(f"{key}=v\n".encode() for key in SWITCH).rstrip(b"\n"),
}


def digest_without(data: bytes, names) -> str:
    """The SHA-256 of the file with every `NAME=...` line of `names` left out, computed independently."""
    kept = [line for line in data.split(b"\n")
            if not any(line.startswith(name.encode() + b"=") for name in names)]
    return hashlib.sha256(b"\n".join(kept)).hexdigest()


class ValueTests(unittest.TestCase):
    """envfile.values: the raw value of each switch key, as the line holds it, or None when absent."""

    def test_values_are_the_raw_text_after_the_first_equals_sign(self):
        self.assertEqual({"DB_CONNECTION": "sqlite", "DB_DATABASE": "/var/www/backend/database/database.sqlite",
                          "DB_SOCKET": None, "DB_PASSWORD": None},
                         envfile.values(PRODUCTION, ["DB_CONNECTION", "DB_DATABASE", "DB_SOCKET", "DB_PASSWORD"]))
        # Kept as written, quotes, carriage return and a second `=` included, so a restore is byte for byte.
        self.assertEqual({"DB_PASSWORD": "\"a=b\" # x\r"}, envfile.values(b"DB_PASSWORD=\"a=b\" # x\r\n",
                                                                         ["DB_PASSWORD"]))

    def test_lines_the_identity_probe_refuses_are_refused(self):
        # Every one of these is refused by identity_probe.config_sha256 too: a .env the controller can switch
        # is always one the identity probe reads.
        for name, data in {
            "export prefix": b"export DB_CONNECTION=sqlite\n",
            "space before =": b"DB_CONNECTION =sqlite\n",
            "indented assignment": b"  DB_CONNECTION=sqlite\n",
            "multi-line value": b"APP_NAME=\"Re\nPro\"\n",
            "no equals sign": b"DB_CONNECTION\n",
        }.items():
            with self.subTest(name):
                with self.assertRaises(identity_probe.guard.Refused):
                    identity_probe.config_sha256(data, DENYLIST)
                # The multi-line value's second line is the one that is not an assignment.
                with self.assertRaisesRegex(envfile.Refused, r"^\.env line [12] is not KEY=VALUE$"):
                    envfile.values(data, ["DB_CONNECTION"])

    def test_a_repeated_switch_key_is_refused(self):
        # The identity probe drops denylisted lines however often they appear; the switch could not tell
        # which one phpdotenv keeps, so it refuses instead of setting one of them.
        data = b"DB_CONNECTION=sqlite\nAPP_ENV=x\nDB_CONNECTION=mysql\n"
        identity_probe.config_sha256(data, DENYLIST)
        with self.assertRaisesRegex(envfile.Refused, "line 3 repeats DB_CONNECTION"):
            envfile.values(data, ["DB_CONNECTION"])
        with self.assertRaisesRegex(envfile.Refused, "repeats DB_CONNECTION"):
            envfile.assign(data, {"DB_CONNECTION": "mysql"})

    def test_values_that_json_cannot_keep_are_refused(self):
        for data in (b"DB_DATABASE=/var/\xff.sqlite\n", b"DB_DATABASE=a\0b\n"):
            with self.subTest(data=data):
                with self.assertRaisesRegex(envfile.Refused, "DB_DATABASE"):
                    envfile.values(data, ["DB_DATABASE"])

    def test_refusals_name_lines_never_values(self):
        with self.assertRaises(envfile.Refused) as refused:
            envfile.values(b"APP_KEY=base64:secret-value\nexport DB_PASSWORD=hunter2-secret\n", ["DB_PASSWORD"])
        self.assertIn("line 2", str(refused.exception))
        self.assertNotIn("secret", str(refused.exception))
        self.assertNotIn("hunter2", str(refused.exception))

    def test_line_pattern_is_the_identity_probes(self):
        self.assertEqual(identity_probe.ENV_LINE.pattern, envfile.ENV_LINE.pattern)
        self.assertEqual(identity_probe.ENV_LINE.flags, envfile.ENV_LINE.flags)


class AssignTests(unittest.TestCase):
    """envfile.assign: the switch keys set (or removed), every other line byte for byte (design 3.2 step 6)."""

    def test_only_the_named_keys_change_byte_for_byte(self):
        switched = envfile.assign(PRODUCTION, SWITCH)
        before, after = PRODUCTION.split(b"\n"), switched.split(b"\n")

        def others(lines):
            return [line for line in lines if not any(line.startswith(f"{key}=".encode()) for key in SWITCH)]

        self.assertEqual(others(before), others(after))
        # In place where the key was, appended (sorted) where it was not; one line each.
        self.assertEqual(b"DB_CONNECTION=mysql", after[before.index(b"DB_CONNECTION=sqlite")])
        self.assertEqual(b"DB_DATABASE=repro_dashboard", after[before.index(
            b"DB_DATABASE=/var/www/backend/database/database.sqlite")])
        appended = sorted(set(SWITCH) - {"DB_CONNECTION", "DB_DATABASE"})
        self.assertEqual([f"{key}={SWITCH[key]}".encode() for key in appended] + [b""], after[len(before) - 1:])
        self.assertEqual(SWITCH, envfile.values(switched, SWITCH))

    def test_none_removes_the_line(self):
        self.assertEqual(b"APP_ENV=x\n", envfile.assign(b"APP_ENV=x\nDB_SOCKET=/run/x.sock\n", {"DB_SOCKET": None}))
        self.assertEqual(b"APP_ENV=x\n", envfile.assign(b"APP_ENV=x\n", {"DB_SOCKET": None}))

    def test_restoring_the_recorded_values_gives_back_the_original_bytes(self):
        # seal_sqlite (D12) sets each switch key back to its recorded SQLite value: the file prepare read.
        for name, data in CORPUS.items():
            with self.subTest(name):
                recorded = envfile.values(data, SWITCH)
                switched = envfile.assign(data, SWITCH)
                self.assertEqual(SWITCH, envfile.values(switched, SWITCH))
                self.assertEqual(data, envfile.assign(switched, recorded))
                # Twice is the same as once.
                self.assertEqual(switched, envfile.assign(switched, SWITCH))

    def test_switched_env_keeps_the_identity_digest_and_is_never_refused(self):
        # Review A2-11: every identity probe refusal also blocks the rollback, so the switched file must read
        # exactly as the original did: plain NAME=VALUE lines, no repeated name outside the denylist.
        for name, data in CORPUS.items():
            with self.subTest(name):
                before = identity_probe.config_sha256(data, DENYLIST)
                switched = envfile.assign(data, SWITCH)
                self.assertEqual(before, identity_probe.config_sha256(switched, DENYLIST))
                self.assertEqual(before, identity_probe.config_sha256(envfile.assign(switched, envfile.values(
                    data, SWITCH)), DENYLIST))

    def test_the_digest_moves_when_a_switch_key_is_not_denylisted(self):
        # The v1 bug (critic RC-H1): without DB_PASSWORD on the denylist the switch changes config_sha256.
        without = [name for name in DENYLIST if name != "DB_PASSWORD"]
        self.assertNotEqual(identity_probe.config_sha256(PRODUCTION, without),
                            identity_probe.config_sha256(envfile.assign(PRODUCTION, SWITCH), without))

    def test_values_that_would_break_a_line_are_refused(self):
        for value in ("mysql\nAPP_KEY=base64:x", "a\0b", 5):
            with self.subTest(value=value):
                with self.assertRaises(envfile.Refused):
                    envfile.assign(PRODUCTION, {"DB_CONNECTION": value})
        with self.assertRaisesRegex(envfile.Refused, "name"):
            envfile.assign(PRODUCTION, {"db connection": "mysql"})

    def test_appending_to_a_file_without_a_final_newline_is_refused(self):
        # The restore could not give back the original bytes; prepare refuses before any change instead.
        with self.assertRaisesRegex(envfile.Refused, "end with a newline"):
            envfile.assign(b"APP_ENV=x", {"DB_SOCKET": "/run/x.sock"})
        self.assertEqual(b"DB_SOCKET=/run/y.sock", envfile.assign(b"DB_SOCKET=/run/x.sock", {"DB_SOCKET": "/run/y.sock"}))


class DigestTests(unittest.TestCase):
    """envfile.digest: the file's SHA-256 with the named lines left out (the switch record's env_sha256)."""

    def test_digest_is_the_file_without_the_named_lines(self):
        self.assertEqual(hashlib.sha256(PRODUCTION).hexdigest(), envfile.digest(PRODUCTION))
        self.assertEqual(digest_without(PRODUCTION, NIGHT_FLAGS), envfile.digest(PRODUCTION, NIGHT_FLAGS))

    def test_only_the_named_lines_may_differ(self):
        flagged = PRODUCTION + b"SCHEDULER_HOLD_ENABLED=true\n"
        self.assertEqual(envfile.digest(PRODUCTION, NIGHT_FLAGS), envfile.digest(flagged, NIGHT_FLAGS))
        self.assertEqual(envfile.digest(PRODUCTION, NIGHT_FLAGS),
                         envfile.digest(PRODUCTION.replace(b"CRM_OUTBOUND_HOLD=true", b"CRM_OUTBOUND_HOLD=false"),
                                        NIGHT_FLAGS))
        for changed in (PRODUCTION.replace(b"5000", b"5001"), PRODUCTION + b"\n", PRODUCTION.replace(b"\n", b"\r\n"),
                        PRODUCTION.replace(b"DB_CONNECTION=sqlite", b"DB_CONNECTION=mysql")):
            with self.subTest(changed=changed[-40:]):
                self.assertNotEqual(envfile.digest(PRODUCTION, NIGHT_FLAGS), envfile.digest(changed, NIGHT_FLAGS))


def posix_acl(*entries) -> bytes:
    """A `system.posix_acl_access` value as the kernel stores it: version 2, then (tag, permissions, id)."""
    undefined = 0xFFFFFFFF
    blob = struct.pack("<I", 2)
    for tag, permissions, identifier in entries:
        blob += struct.pack("<HHI", tag, permissions, undefined if identifier is None else identifier)
    return blob


# user::rw-, user:1000:r-- (the backup account's grant on production), group::r--, mask::r--, other::---
BACKUP_ACL = posix_acl((0x01, 6, None), (0x02, 4, 1000), (0x04, 4, None), (0x10, 4, None), (0x20, 0, None))


def metadata(path: Path) -> tuple:
    info = path.lstat()
    return (info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode),
            {name: os.getxattr(path, name, follow_symlinks=False) for name in os.listxattr(path, follow_symlinks=False)})


@unittest.skipUnless(LINUX_ROOT, "Needs Linux root to keep another account's owner and the ACL")
class ReplaceTests(unittest.TestCase):
    """envfile.replace: temp file in the same folder, owner, mode and ACL kept, fsync, rename, read back (E5)."""

    def setUp(self):
        self.folder = Path(tempfile.mkdtemp(prefix="repro-envfile-", dir="/var/lib"))
        self.addCleanup(shutil.rmtree, self.folder)
        os.chown(self.folder, 65534, 33)
        self.folder.chmod(0o755)
        self.env = self.folder / ".env"
        self.env.write_bytes(PRODUCTION)
        os.chown(self.env, 65534, 33)
        self.env.chmod(0o640)
        os.setxattr(self.env, "system.posix_acl_access", BACKUP_ACL)
        os.setxattr(self.env, "user.repro_test", b"kept")

    def others(self) -> list[str]:
        return sorted(entry.name for entry in self.folder.iterdir() if entry.name != ".env")

    def test_replace_keeps_owner_group_mode_and_every_extended_attribute(self):
        before, inode = metadata(self.env), self.env.lstat().st_ino
        self.assertIn("system.posix_acl_access", before[3])
        switched = envfile.assign(PRODUCTION, SWITCH)
        envfile.replace(self.env, switched, expected=PRODUCTION)
        self.assertEqual(switched, self.env.read_bytes())
        self.assertEqual(before, metadata(self.env))
        self.assertEqual(BACKUP_ACL, os.getxattr(self.env, "system.posix_acl_access"))
        # A new file renamed into place, nothing left beside it.
        self.assertNotEqual(inode, self.env.lstat().st_ino)
        self.assertEqual([], self.others())

    def test_a_file_that_changed_since_it_was_read_is_left_alone(self):
        before = metadata(self.env), self.env.read_bytes(), self.env.lstat().st_ino
        with self.assertRaisesRegex(envfile.Refused, "changed since"):
            envfile.replace(self.env, envfile.assign(PRODUCTION, SWITCH), expected=PRODUCTION + b"X=1\n")
        self.assertEqual(before, (metadata(self.env), self.env.read_bytes(), self.env.lstat().st_ino))
        self.assertEqual([], self.others())

    def test_a_failure_before_the_rename_leaves_the_file_and_no_temporary(self):
        before = metadata(self.env), self.env.read_bytes(), self.env.lstat().st_ino
        for name in ("setxattr", "fsync", "fchmod"):
            with self.subTest(failing=name), patch.object(envfile.os, name, side_effect=OSError(5, "I/O error")):
                with self.assertRaises(OSError):
                    envfile.replace(self.env, envfile.assign(PRODUCTION, SWITCH), expected=PRODUCTION)
            self.assertEqual(before, (metadata(self.env), self.env.read_bytes(), self.env.lstat().st_ino))
            self.assertEqual([], self.others())

    def test_a_copy_that_silently_loses_an_attribute_or_the_mode_is_refused_before_the_rename(self):
        # The self-check after the copy: a setxattr or chmod that reports success but changes nothing (a file
        # system without ACL support, say) never puts a .env without the backup account's ACL in place. Setting an
        # access ACL also sets the mode's bits, so the silent chmod is shown on a file without one.
        for name in ("setxattr", "fchmod"):
            if name == "fchmod":
                os.removexattr(self.env, "system.posix_acl_access")
            before = metadata(self.env), self.env.read_bytes(), self.env.lstat().st_ino
            with self.subTest(silent=name), patch.object(envfile.os, name, lambda *arguments, **keywords: None):
                with self.assertRaisesRegex(envfile.Refused, "did not keep the owner, mode and extended attributes"):
                    envfile.replace(self.env, envfile.assign(PRODUCTION, SWITCH), expected=PRODUCTION)
            self.assertEqual(before, (metadata(self.env), self.env.read_bytes(), self.env.lstat().st_ino))
            self.assertEqual([], self.others())

    def test_temporaries_an_interrupted_replace_left_are_removed(self):
        leftover = self.folder / f".env.repro-{uuid.uuid4().hex}"
        leftover.write_bytes(b"DB_PASSWORD=left-behind\n")
        unrelated = self.folder / ".env.backup"
        unrelated.write_bytes(b"x")
        envfile.replace(self.env, envfile.assign(PRODUCTION, SWITCH), expected=PRODUCTION)
        self.assertEqual([".env.backup"], self.others())

    def test_only_a_regular_file_is_read_or_replaced(self):
        link = self.folder / "link"
        link.symlink_to(self.env)
        fifo = self.folder / "fifo"
        os.mkfifo(fifo)
        for path in (link, fifo, self.folder):
            with self.subTest(path=path.name):
                with self.assertRaises((envfile.Refused, OSError)):
                    envfile.read(path)
                with self.assertRaises((envfile.Refused, OSError)):
                    envfile.replace(path, b"A=1\n", expected=b"")
        self.assertTrue(link.is_symlink())
        large = self.folder / "large"
        large.write_bytes(b"A=" + b"x" * envfile.MAX_BYTES + b"\n")
        with self.assertRaisesRegex(envfile.Refused, "larger"):
            envfile.read(large)

    def test_read_returns_the_bytes(self):
        self.assertEqual(PRODUCTION, envfile.read(self.env))


if __name__ == "__main__":
    unittest.main()
