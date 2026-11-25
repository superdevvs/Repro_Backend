"""Installer: preview changes nothing, jobs pin exact bytes, apply and undo are symmetric (fake system)."""
from __future__ import annotations

import contextlib
import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import stat
import sys
import tempfile
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
        minimal = raw is not None and not default and {tag for tag, _, _ in installer.decode_acl(raw)} == {
            installer.USER_OBJ, installer.GROUP_OBJ, installer.OTHER}
        if raw is None or minimal:
            # Like the kernel: an access ACL of only the three base entries is just the mode.
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

    def test_a_narrowed_mask_is_not_reopened_for_the_owning_group(self):
        # chmod g-r on a file that has an ACL sets the mask to ---. Adding read to
        # that mask would give the owning group and user 700 their read back.
        undefined = installer.UNDEFINED
        acl = [(installer.USER_OBJ, undefined, 6), (installer.USER, 700, 4), (installer.GROUP_OBJ, undefined, 4),
               (installer.MASK, undefined, 0), (installer.OTHER, undefined, 0)]
        with self.assertRaises(installer.Refusal):
            installer.with_named_user(acl, 801, installer.READ)

    def test_default_acl_mirrors_the_directory_mode(self):
        acl = installer.with_named_user(installer.acl_from_mode(0o775), 801, installer.READ)
        self.assertEqual(installer.describe_acl(acl),
                         "user::rwx,user:801:r--,group::rwx,mask::rwx,other::r-x")


@unittest.skipUnless(sys.platform == "linux", "needs Linux POSIX ACL xattrs")
class RealAclUndoTests(unittest.TestCase):
    """Undo on the real kernel: removing an access ACL leaves the group bits at the mask."""

    def test_undo_returns_a_file_that_had_no_acl_to_its_exact_mode(self):
        recorded = {"version": 1, "job": "base-backup", "groups": [], "users": [], "directories": [],
                    "files": {}, "acls": {}}

        class System(installer.LinuxSystem):
            def user_id(self, name):
                return 12345 if name == installer.ACCOUNT else None

            def read(self, path):
                if path.startswith(installer.RECORD_DIR):
                    return json.dumps(recorded).encode() if path == installer.record_path("base-backup") else None
                return super().read(path)

            def remove_file(self, path):
                assert path.startswith(installer.RECORD_DIR), path

            def remove_tree(self, path):
                assert path == installer.RECORD_DIR, path

        with tempfile.TemporaryDirectory() as folder:
            path = os.path.join(folder, "private.env")
            Path(path).write_text("APP_KEY=fixture\n")
            os.chmod(path, 0o600)
            system = System()
            self.assertTrue(installer.apply_grant(system, installer.Grant(path, installer.ACCOUNT, installer.READ),
                                                  recorded))
            self.assertIsNotNone(system.get_acl(path, False))
            with contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(installer.undo("base-backup", system), 0)
            self.assertIsNone(system.get_acl(path, False))
            self.assertEqual(stat.S_IMODE(os.stat(path).st_mode), 0o600, "undo widened the group bits")


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

    @unittest.skipUnless(os.name == "posix", "the tools check POSIX absolute paths")
    def test_rendered_alert_configs_are_ones_the_bridge_and_probe_accept(self):
        import alert_bridge
        import alert_probe

        job = installer.build_job("alerts", self.settings)
        data = {item.path: item.data for item in job.files}
        alert_bridge.parse_config(data["/etc/repro-alert/bridge.json"])
        plan = alert_probe.parse_plan(data["/etc/repro-backup/alert-probe.json"])
        self.assertEqual(plan["conditions"], installer.SETTING_DEFAULTS["probe_conditions"])

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

    def test_undo_removes_the_probe_state_folder(self):
        # repro-alert-probe.service keeps its memory in StateDirectory=repro-alert-probe.
        # Left behind, it would outlive the account and pass to whoever gets the uid next.
        system = FakeSystem()
        with contextlib.redirect_stdout(io.StringIO()):
            for name in ("base-backup", "alerts"):
                self.assertEqual(installer.apply(installer.build_job(name, self.settings), system, self.settings,
                                                 enter_credentials=False), 0)
        uid, gid = system.user_id("repro-backup"), system.group_id("repro-backup")
        system.dirs.setdefault("/var/lib/repro-alert-probe", (uid, gid, 0o700))
        system.files["/var/lib/repro-alert-probe/memory.json"] = (b"{}", uid, gid, 0o600)
        with contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(installer.undo("alerts", system), 0)
            self.assertEqual(installer.undo("base-backup", system), 0)
        self.assertNotIn("/var/lib/repro-alert-probe", system.dirs)
        self.assertNotIn("/var/lib/repro-alert-probe/memory.json", system.files)

    class CredentialSystem(FakeSystem):
        """The no-echo entry exits with entry_code; `test -r` passes only for the readable paths."""

        def __init__(self, *, entry_code=0, readable=()):
            super().__init__()
            self.entry_code, self.readable = entry_code, set(readable)

        def run(self, argv, *, input_tty=False, env=None):
            if input_tty:
                self.commands.append(list(argv))
                return self.entry_code, b""
            if argv[-2:-1] == ["-r"]:
                self.commands.append(list(argv))
                return (0 if argv[-1] in self.readable else 1), b""
            return super().run(argv, input_tty=input_tty, env=env)

    def apply_with_credentials(self, system, *jobs):
        output, codes = io.StringIO(), []
        with contextlib.redirect_stdout(output):
            for name in jobs:
                codes.append(installer.apply(installer.build_job(name, self.settings), system, self.settings,
                                             enter_credentials=True))
        return codes, output.getvalue()

    def test_a_failed_credential_entry_makes_apply_fail(self):
        # It printed FAIL but exited 0, so a script or a quick look saw a finished install.
        system = self.CredentialSystem(entry_code=1)
        codes, text = self.apply_with_credentials(system, "base-backup")
        self.assertEqual(codes, [1])
        self.assertIn("FAIL credential entry", text)
        self.assertIn("FAIL upload credentials entered", text)

    def test_alerts_self_test_checks_the_ping_url_file(self):
        rclone = "/var/lib/repro-backup/credentials/rclone-daily.conf"
        urls = "/var/lib/repro-alert/ping-urls.json"
        system = self.CredentialSystem(readable={rclone})
        codes, text = self.apply_with_credentials(system, "base-backup", "alerts")
        self.assertEqual(codes, [0, 1], text)
        self.assertIn("FAIL ping URLs entered (" + urls + ")", text)
        self.assertIn(["/usr/sbin/runuser", "-u", "repro-alert", "--", "/usr/bin/test", "-r", urls], system.commands)
        system.readable.add(urls)
        codes, text = self.apply_with_credentials(system, "alerts")
        self.assertEqual(codes, [0], text)
        self.assertIn("PASS ping URLs entered", text)

    class CreateOnlySystem(CredentialSystem):
        """The real entry steps only create: enter_r2_config refuses an existing config folder and
        alert_bridge enter-urls an existing URL file. A successful entry leaves its file readable."""

        TARGETS = {"enter_r2_config.py": "/var/lib/repro-backup/credentials/rclone-daily.conf",
                   "alert_bridge.py": "/var/lib/repro-alert/ping-urls.json"}

        def run(self, argv, *, input_tty=False, env=None):
            if input_tty:
                self.commands.append(list(argv))
                target = next(path for tool, path in self.TARGETS.items() if any(item.endswith(tool) for item in argv))
                if target in self.readable:
                    return 1, b""
                self.readable.add(target)
                return 0, b""
            return super().run(argv, input_tty=input_tty, env=env)

    def test_a_second_apply_keeps_the_entered_values_and_passes(self):
        # Re-running apply is how a lost ACL is repaired, and how the self-tests are repeated after
        # entering the values by hand. It ran the create-only entry again, which refused the
        # existing values, so a correct install exited 1 on every later apply.
        system = self.CreateOnlySystem()
        codes, _ = self.apply_with_credentials(system, "base-backup", "alerts")
        self.assertEqual(codes, [0, 0])
        def entries():
            return sum(1 for argv in system.commands if any(item.endswith(tool) for item in argv
                                                            for tool in self.CreateOnlySystem.TARGETS))

        self.assertEqual(entries(), 2)
        codes, text = self.apply_with_credentials(system, "base-backup", "alerts")
        self.assertEqual(codes, [0, 0], text)
        self.assertEqual(entries(), 2, "the values already entered are not asked for again")
        self.assertIn("PASS upload credentials entered", text)
        self.assertIn("PASS ping URLs entered", text)
        self.assertNotIn("FAIL", text)

    def test_skipped_credentials_are_a_todo_not_a_failure(self):
        system = FakeSystem()
        output = io.StringIO()
        with contextlib.redirect_stdout(output):
            for name in ("base-backup", "alerts"):
                self.assertEqual(installer.apply(installer.build_job(name, self.settings), system, self.settings,
                                                 enter_credentials=False), 0)
        self.assertIn("TODO upload credentials entered", output.getvalue())
        self.assertIn("TODO ping URLs entered", output.getvalue())

    def test_alerts_job_requires_the_base_job(self):
        system = FakeSystem()
        output = io.StringIO()
        with contextlib.redirect_stdout(output):
            code = installer.apply(installer.build_job("alerts", self.settings), system, self.settings,
                                   enter_credentials=False)
        self.assertEqual(code, 1)
        self.assertEqual([m for m in system.mutations if m[0] != "run"], [])



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

    @unittest.skipUnless(hasattr(os, "geteuid") and os.geteuid() == 0 and os.path.isdir("/opt"),
                         "needs root to build a root-owned bundle")
    def test_a_writable_folder_between_the_bundle_and_a_source_is_refused(self):
        import shutil
        import subprocess

        folder = Path(tempfile.mkdtemp(dir="/opt", prefix="repro-bundle-check-"))
        self.addCleanup(shutil.rmtree, folder)
        os.chmod(folder, 0o755)
        shutil.copytree(OFFSITE.parents[1], folder / "scripts")
        for root, dirs, files in os.walk(folder):
            os.chown(root, 0, 0)
            os.chmod(root, 0o755)
            for name in files:
                os.chown(os.path.join(root, name), 0, 0)
                os.chmod(os.path.join(root, name), 0o644)
        offsite = folder / "scripts" / "mysql" / "offsite"
        probe = ("import sys; sys.path.insert(0, sys.argv[1]); import install_backup_host as i; "
                 "print(i.bundle_is_protected())")

        def protected():
            done = subprocess.run([sys.executable, "-I", "-B", "-c", probe, str(offsite)], capture_output=True)
            return done.stdout.strip().decode()

        self.assertEqual(protected(), "True")
        # Another account could swap the units or the rotation wrapper through these folders.
        for middle in (offsite / "systemd", offsite / "systemd" / "binlog-fixture"):
            os.chmod(middle, 0o775)
            self.assertEqual(protected(), "False", middle)
            os.chmod(middle, 0o755)


if __name__ == "__main__":
    unittest.main()
