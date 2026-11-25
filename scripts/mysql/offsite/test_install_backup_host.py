"""Installer: preview changes nothing, jobs pin exact bytes, apply and undo are symmetric (fake system)."""
from __future__ import annotations

import contextlib
import hashlib
import io
import json
from pathlib import Path, PurePosixPath
import unittest

import install_backup_host as installer
import verify_retention

OFFSITE = Path(__file__).resolve().parent
SETTINGS = {"version": 1, "release": "8bb478e66b3c1eb8a78969f513e38901a2d860e8",
            "identity": "repro-sqlite-maverick", "bucket": "repro-db-backups",
            "endpoint": "https://" + "5" * 32 + ".r2.cloudflarestorage.com"}


class FakeSystem:
    """In-memory Linux: users, files, modes and ACL xattrs. Records every mutation."""

    def __init__(self):
        self.users = {"root": (0, 0), "maverick": (1000, 1000)}
        self.groups = {"root": 0, "www-data": 33, "maverick": 1000, "adm": 4}
        self.files = {}
        self.dirs = {"/": (0, 0, 0o755)}
        self.acls = {}
        self.mutations = []
        self.commands = []
        for path in ("/opt", "/etc", "/etc/systemd", "/etc/systemd/system", "/var", "/var/lib", "/var/www",
                     "/var/www/backend", "/var/www/backend/storage", "/var/www/backend/storage/logs",
                     "/var/log", "/var/log/nginx"):
            self.dirs[path] = (0, 0, 0o755)
        self.dirs["/var/www/backend/database"] = (1000, 33, 0o775)
        self.files["/var/www/backend/.env"] = (b"APP_KEY=base64:x\n", 1000, 33, 0o640)
        for suffix in ("", "-wal", "-shm"):
            self.files["/var/www/backend/database/database.sqlite" + suffix] = (b"SQLite format 3\x00", 1000, 33, 0o664)
        self.files["/var/www/backend/storage/logs/laravel.log"] = (b"", 33, 33, 0o664)

    def mutate(self, *what):
        self.mutations.append(what)

    def user_id(self, name):
        return self.users.get(name, (None, None))[0]

    def group_id(self, name):
        return self.groups.get(name)

    def add_group(self, name):
        self.mutate("add_group", name)
        self.groups[name] = 900 + len(self.groups)

    def add_user(self, name, group):
        self.mutate("add_user", name)
        self.users[name] = (800 + len(self.users), self.groups[group])

    def del_user(self, name):
        self.mutate("del_user", name)
        del self.users[name]

    def del_group(self, name):
        self.mutate("del_group", name)
        del self.groups[name]

    def lstat(self, path):
        if path in self.files:
            data, uid, gid, mode = self.files[path]
            return installer.Stat("file", uid, gid, mode, len(data))
        if path in self.dirs:
            uid, gid, mode = self.dirs[path]
            return installer.Stat("dir", uid, gid, mode, 0)
        return None

    def read(self, path):
        return self.files[path][0] if path in self.files else None

    def write_file(self, path, data, uid, gid, mode):
        self.mutate("write_file", path)
        assert str(PurePosixPath(path).parent) in self.dirs, path
        self.files[path] = (data, uid, gid, mode)

    def remove_file(self, path):
        self.mutate("remove_file", path)
        del self.files[path]

    def make_dir(self, path, uid, gid, mode):
        self.mutate("make_dir", path)
        self.dirs[path] = (uid, gid, mode)

    def remove_tree(self, path):
        self.mutate("remove_tree", path)
        for item in [p for p in self.files if p.startswith(path + "/")]:
            del self.files[item]
        for item in [p for p in self.dirs if p == path or p.startswith(path + "/")]:
            del self.dirs[item]

    def is_empty_dir(self, path):
        return not any(item.startswith(path + "/") for item in list(self.files) + list(self.dirs))

    def get_acl(self, path, default):
        return self.acls.get((path, default))

    def set_acl(self, path, default, raw):
        self.mutate("set_acl", path, default)
        if raw is None:
            self.acls.pop((path, default), None)
        else:
            self.acls[(path, default)] = raw

    def run(self, argv, *, input_tty=False, env=None):
        self.commands.append(list(argv))
        if argv[:1] != ["/usr/bin/systemd-analyze"] and argv[:2] != ["/usr/bin/systemctl", "is-enabled"]:
            self.mutate("run", argv[0])
        if "/usr/bin/test" in argv:
            return 1, b""  # repro-backup cannot write the database folder; no credentials entered.
        if argv[:2] == ["/usr/bin/systemctl", "is-enabled"]:
            return 1, b"disabled\n"
        return 0, b""


class AclTests(unittest.TestCase):
    def test_round_trip_and_named_user_keeps_group_effective_rights(self):
        acl = installer.acl_from_mode(0o640)
        updated = installer.with_named_user(acl, 801, installer.READ)
        decoded = installer.decode_acl(installer.encode_acl(updated))
        self.assertEqual(decoded, updated)
        self.assertEqual(installer.describe_acl(decoded),
                         "user::rw-,user:801:r--,group::r--,mask::r--,other::---")

    def test_existing_tighter_mask_is_not_widened_beyond_the_grant(self):
        acl = installer.with_named_user(installer.acl_from_mode(0o660), 700, installer.READ)
        acl = [(tag, ident, 0o4 if tag == installer.MASK else perm) for tag, ident, perm in acl]
        again = installer.with_named_user(acl, 801, installer.READ | installer.EXECUTE)
        self.assertEqual([perm for tag, _, perm in again if tag == installer.MASK], [0o5])

    def test_default_acl_mirrors_the_directory_mode(self):
        acl = installer.with_named_user(installer.acl_from_mode(0o775), 801, installer.READ)
        self.assertEqual(installer.describe_acl(acl),
                         "user::rwx,user:801:r--,group::rwx,mask::rwx,other::r-x")


class JobTests(unittest.TestCase):
    def setUp(self):
        self.settings = installer.parse_settings(json.dumps(SETTINGS).encode())

    def test_settings_need_the_reviewed_values(self):
        for field in ("release", "identity", "bucket", "endpoint"):
            with self.assertRaises(installer.Refusal):
                installer.parse_settings(json.dumps({k: v for k, v in SETTINGS.items() if k != field}).encode())
        with self.assertRaises(installer.Refusal):
            installer.parse_settings(json.dumps(SETTINGS | {"upload_credential": "shared-read-write"}).encode())
        self.assertEqual(self.settings["upload_credential"], "derived-no-delete")

    def test_base_job_pins_exact_tool_and_unit_bytes(self):
        job = installer.build_job("base-backup", self.settings)
        files = {item.path: item for item in job.files}
        for name in installer.BASE_TOOLS:
            item = files["/opt/repro-backup-tools/" + name]
            source = OFFSITE / name if (OFFSITE / name).exists() else OFFSITE.parents[1] / "deploy" / name
            self.assertEqual(item.data, source.read_bytes())
            self.assertEqual((item.owner, item.group, item.mode), ("root", "root", 0o444))
        for name in installer.BASE_UNITS:
            item = files["/etc/systemd/system/" + name]
            self.assertEqual(item.data, (OFFSITE / "systemd" / "system" / name).read_bytes())
            self.assertEqual(item.mode, 0o644)
        plan = files["/etc/repro-backup/plan.json"]
        self.assertEqual((plan.owner, plan.group, plan.mode), ("root", "repro-backup", 0o640))
        record = json.loads(plan.data)
        self.assertEqual(record["destination"], "storage:repro-db-backups/daily")
        self.assertEqual(record["rclone_config"], "/var/lib/repro-backup/credentials/rclone-daily.conf")
        self.assertEqual(record["state_dir"], "/var/lib/repro-backup")
        self.assertIsNone(record["mysql"])

    def test_base_job_grants_read_only_access_to_env_and_the_wal_database(self):
        job = installer.build_job("base-backup", self.settings)
        grants = {(grant.path, grant.default): grant.perm for grant in job.grants}
        self.assertEqual(grants[("/var/www/backend/.env", False)], installer.READ)
        self.assertEqual(grants[("/var/www/backend/database", False)], installer.READ | installer.EXECUTE)
        self.assertEqual(grants[("/var/www/backend/database", True)], installer.READ)
        for suffix in ("", "-wal", "-shm"):
            self.assertEqual(grants[("/var/www/backend/database/database.sqlite" + suffix, False)], installer.READ)
        self.assertFalse(any(grant.perm & 0o2 for grant in job.grants))

    def test_alert_job_has_bridge_units_and_its_own_account(self):
        job = installer.build_job("alerts", self.settings)
        self.assertEqual([account.name for account in job.accounts], ["repro-alert"])
        paths = {item.path for item in job.files}
        for name in installer.ALERT_UNITS:
            self.assertIn("/etc/systemd/system/" + name, paths)
        bridge = json.loads(next(item for item in job.files if item.path == "/etc/repro-alert/bridge.json").data)
        self.assertEqual(bridge["urls_file"], "/var/lib/repro-alert/ping-urls.json")
        inbox = next(item for item in job.directories if item.path == "/var/lib/repro-alert-inbox")
        self.assertEqual((inbox.owner, inbox.group, inbox.mode), ("repro-backup", "repro-alert", 0o2750))

    def test_r2_rules_match_the_retention_verifier_format(self):
        locks, lifecycle = installer.r2_rules()
        expected = {"daily/": 30, "binlog/": 30, "monthly/": 365, "final-sqlite/": 365}
        for prefix, days in expected.items():
            self.assertEqual(verify_retention.matching_age_rule(locks, prefix, lifecycle=False), days * 86400)
            self.assertGreaterEqual(verify_retention.matching_age_rule(lifecycle, prefix, lifecycle=True),
                                    days * 86400)
        self.assertEqual(lifecycle["result"]["rules"][0]["id"], "Default Multipart Abort Rule")


class PreviewAndApplyTests(unittest.TestCase):
    def setUp(self):
        self.settings = installer.parse_settings(json.dumps(SETTINGS).encode())

    def preview(self, system, job="base-backup"):
        output = io.StringIO()
        with contextlib.redirect_stdout(output):
            code = installer.preview(installer.build_job(job, self.settings), system, self.settings)
        return code, output.getvalue()

    def test_preview_changes_nothing_and_prints_hashes_rules_and_undo(self):
        system = FakeSystem()
        code, text = self.preview(system)
        self.assertEqual(code, 0)
        self.assertEqual(system.mutations, [])
        job = installer.build_job("base-backup", self.settings)
        for item in job.files:
            self.assertIn(item.path, text)
            self.assertIn(hashlib.sha256(item.data).hexdigest(), text)
        for phrase in ("No changes were made.", "daily/ lock 30 days", "final-sqlite/ lock 365 days",
                       "--undo --job base-backup", "UPLOAD key", "READ-ONLY", "user:repro-backup:r--"):
            self.assertIn(phrase, text)

    def test_apply_then_undo_restores_the_exact_previous_state(self):
        system = FakeSystem()
        before = (dict(system.files), dict(system.dirs), dict(system.acls), dict(system.users), dict(system.groups))
        job = installer.build_job("base-backup", self.settings)
        with contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(installer.apply(job, system, self.settings, enter_credentials=False), 0)
        self.assertIn("repro-backup", system.users)
        self.assertIsNotNone(system.get_acl("/var/www/backend/.env", False))
        self.assertIn(["/usr/bin/systemctl", "daemon-reload"], system.commands)
        self.assertFalse(any(argv[:2] == ["/usr/bin/systemctl", "enable"] for argv in system.commands))
        with contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(installer.undo("base-backup", system), 0)
        after = (dict(system.files), dict(system.dirs), dict(system.acls), dict(system.users), dict(system.groups))
        self.assertEqual(after, before)

    def test_apply_is_idempotent_and_never_overwrites_a_changed_file(self):
        system = FakeSystem()
        job = installer.build_job("base-backup", self.settings)
        with contextlib.redirect_stdout(io.StringIO()):
            installer.apply(job, system, self.settings, enter_credentials=False)
            count = len(system.mutations)
            self.assertEqual(installer.apply(job, system, self.settings, enter_credentials=False), 0)
        self.assertEqual([m for m in system.mutations[count:] if m[0] != "run"], [])
        data, uid, gid, mode = system.files["/etc/repro-backup/plan.json"]
        system.files["/etc/repro-backup/plan.json"] = (data + b" ", uid, gid, mode)
        output = io.StringIO()
        with contextlib.redirect_stdout(output):
            self.assertEqual(installer.apply(job, system, self.settings, enter_credentials=False), 1)
        self.assertIn("FAIL", output.getvalue())
        self.assertIn("--undo --job base-backup", output.getvalue())

    def test_alerts_job_requires_the_base_job(self):
        system = FakeSystem()
        output = io.StringIO()
        with contextlib.redirect_stdout(output):
            code = installer.apply(installer.build_job("alerts", self.settings), system, self.settings,
                                   enter_credentials=False)
        self.assertEqual(code, 1)
        self.assertEqual([m for m in system.mutations if m[0] != "run"], [])


if __name__ == "__main__":
    unittest.main()


class ToolClosureTests(unittest.TestCase):
    def test_every_installed_tool_finds_its_sibling_modules(self):
        import re

        settings = installer.parse_settings(json.dumps(SETTINGS).encode())
        siblings = {path.stem for path in OFFSITE.glob("*.py")} | {"rotation_attempt"}
        for name in ("base-backup", "alerts", "recovery-kit", "mysql-recovery"):
            installed = set()
            for job in (name, *installer.REQUIRES[name]):
                installed |= {PurePosixPath(item.path).name for item in installer.build_job(job, settings).files}
            for tool in sorted(item for item in installed if item.endswith(".py")):
                text = installer.source_bytes(tool).decode()
                imports = set(re.findall(r"^\s*(?:import|from) ([a-z_]+)", text, re.MULTILINE)) & siblings
                self.assertLessEqual({module + ".py" for module in imports}, installed, (name, tool))
        self.assertIn("rotate_closed_binlogs.py", {PurePosixPath(item.path).name for item in
                                                    installer.build_job("mysql-recovery", settings).files})


class BundleOwnershipTests(unittest.TestCase):
    @unittest.skipUnless(hasattr(__import__("os"), "geteuid") and OFFSITE.stat().st_uid != 0,
                         "needs a Linux checkout owned by a non-root account")
    def test_apply_refuses_a_bundle_another_account_could_change(self):
        self.assertFalse(installer.bundle_is_protected())
        output = io.StringIO()
        with contextlib.redirect_stdout(output):
            self.assertEqual(installer.main(["--job", "base-backup", "--undo"]), 1)
        self.assertIn("FAIL", output.getvalue())
