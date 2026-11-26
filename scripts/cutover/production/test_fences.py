"""Tests for the deploy marker publisher, the SQLite seal and the cron entry wrapper."""

import json
import os
import re
import sqlite3
import stat
import subprocess
import sys
import tempfile
import time
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path[:0] = [str(Path(__file__).resolve().parents[1]), str(Path(__file__).resolve().parent)]
import crontab
import deploy_marker
import sqlite_seal
from runner import UnsafeCutover

REPO = Path(__file__).resolve().parents[3]
WRAPPER = "/usr/bin/sudo -n /usr/bin/python3 -I -S /opt/repro-cutover-fence/production/admit.py"
STORAGE_GUARD = "/usr/local/sbin/repro-storage-guard --live && "
LINUX_ROOT = sys.platform == "linux" and hasattr(os, "geteuid") and os.geteuid() == 0


def deploy_snippet(component: str, marker: Path) -> str:
    """The exact lock and marker check from Deploy-App.ps1, pointed at a test marker."""
    source = (REPO / "scripts/deploy/Deploy-App.ps1").read_text(encoding="utf-8")
    found = re.search(
        r'(exec 9>"\$HOME/\.repro-' + component + r'-deploy\.lock"\n'
        r'flock -w 300 9 .*\n)(?:.*\n)*?(if \[ -e /etc/repro-database-cutover/active \].*\n(?:.*\n)*?fi\n)',
        source,
    )
    assert found, f"Deploy-App.ps1 no longer has the {component} lock and marker check"
    check = found.group(2).replace("/etc/repro-database-cutover/active", str(marker))
    return "set -eu\n" + found.group(1) + check + 'echo deploying\nwhile [ ! -e "$HOME/release" ]; do sleep 0.05; done\n'


@unittest.skipUnless(LINUX_ROOT, "Requires Linux root and flock")
class DeployMarkerTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.root.chmod(0o755)
        self.home = self.root / "home"
        self.home.mkdir()
        os.chown(self.home, 33, 33)
        self.marker = self.root / "etc" / "active"
        self.marker.parent.mkdir()
        self.locks = [self.home / ".repro-backend-deploy.lock", self.home / ".repro-frontend-deploy.lock"]

    def deploy(self, component):
        child = subprocess.Popen(
            ["/usr/bin/setpriv", "--reuid=33", "--regid=33", "--clear-groups", "/bin/bash", "-c",
             deploy_snippet(component, self.marker)],
            env={"HOME": str(self.home), "PATH": "/usr/bin:/bin"}, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            text=True,
        )
        self.addCleanup(lambda: child.poll() is None and child.kill())
        return child

    def publish(self, seconds=0.3, run_id="run-1"):
        deploy_marker.publish(self.marker, self.locks, run_id, seconds, owner=(33, 33))

    def test_publish_waits_for_each_deploy_inside_its_lock_then_holds_new_ones(self):
        for component in ("backend", "frontend"):
            with self.subTest(component=component):
                deploying = self.deploy(component)
                self.assertEqual("deploying\n", deploying.stdout.readline())
                with self.assertRaisesRegex(UnsafeCutover, "deployment holds its lock"):
                    self.publish()
                self.assertFalse(self.marker.exists())
                (self.home / "release").touch()
                deploying.communicate(timeout=5)
                self.assertEqual(0, deploying.returncode)
                (self.home / "release").unlink()

        self.publish()
        self.assertEqual({"version": 1, "run_id": "run-1"}, json.loads(self.marker.read_text()))
        self.assertEqual((0, 0o644), (self.marker.stat().st_uid, stat.S_IMODE(self.marker.stat().st_mode)))
        self.assertTrue(deploy_marker.published(self.marker, "run-1"))
        self.assertFalse(deploy_marker.published(self.marker, "run-2"))
        for component in ("backend", "frontend"):
            held = self.deploy(component)
            _, errors = held.communicate(timeout=5)
            self.assertEqual(95, held.returncode)
            self.assertIn(f"Database cutover holds the {component} deployment fence.", errors)

    def test_missing_lock_files_are_created_for_the_deploy_user(self):
        self.publish()
        for lock in self.locks:
            info = lock.stat()
            self.assertEqual((33, 33, 0o600), (info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode)))

    def test_withdraw_requires_the_same_run_and_the_deploy_locks(self):
        self.publish()
        with self.assertRaisesRegex(UnsafeCutover, "another cutover run"):
            deploy_marker.withdraw(self.marker, self.locks, "run-2", 0.3)
        self.assertTrue(self.marker.exists())
        deploy_marker.withdraw(self.marker, self.locks, "run-1", 0.3)
        self.assertFalse(self.marker.exists())
        deploy_marker.withdraw(self.marker, self.locks, "run-1", 0.3)
        (self.home / "release").touch()
        deploying = self.deploy("backend")
        deploying.communicate(timeout=5)
        self.assertEqual(0, deploying.returncode)


@unittest.skipUnless(LINUX_ROOT, "Requires Linux root to test as the database owner")
class SqliteSealTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        folder = Path(temporary.name) / "database"
        folder.mkdir()
        os.chown(folder, 33, 33)
        Path(temporary.name).chmod(0o755)
        self.database = folder / "database.sqlite"
        self.record = Path(temporary.name) / "sqlite-seal.json"
        self.as_owner("PRAGMA journal_mode=WAL", "CREATE TABLE effects (receipt TEXT)", "INSERT INTO effects VALUES ('one')")
        self.modes = {path: stat.S_IMODE(path.stat().st_mode) for path in self.files()}

    def files(self):
        return [path for path in (self.database, Path(f"{self.database}-wal"), Path(f"{self.database}-shm"))
                if path.exists()]

    def as_owner(self, *statements):
        code = ("import sqlite3, sys\nconnection = sqlite3.connect(sys.argv[1], timeout=0.2)\n"
                "for statement in sys.argv[2:]:\n    connection.execute(statement)\nconnection.commit()\n"
                "print(connection.execute('SELECT count(*) FROM sqlite_master').fetchone()[0])\n")
        return subprocess.run(
            ["/usr/bin/setpriv", "--reuid=33", "--regid=33", "--clear-groups", sys.executable, "-c", code,
             str(self.database), *statements],
            capture_output=True, text=True, timeout=10,
        )

    def test_seal_checkpoints_and_refuses_owner_writes_then_unseal_restores_modes(self):
        self.as_owner("INSERT INTO effects VALUES ('two')")
        self.assertTrue(sqlite_seal.writable(self.database))
        sqlite_seal.seal(self.database, self.record)
        self.assertFalse(sqlite_seal.writable(self.database))
        wal = Path(f"{self.database}-wal")
        self.assertTrue(not wal.exists() or wal.stat().st_size == 0)
        refused = self.as_owner("INSERT INTO effects VALUES ('must-not-commit')")
        self.assertNotEqual(0, refused.returncode)
        self.assertIn("readonly", refused.stderr)
        with sqlite3.connect(f"file:{self.database}?mode=ro", uri=True) as reader:
            self.assertEqual([("one",), ("two",)], reader.execute("SELECT receipt FROM effects").fetchall())

        sqlite_seal.seal(self.database, self.record)  # Idempotent: keeps the original modes.
        sqlite_seal.unseal(self.database, self.record)
        # Companions created by the refused writer get the database's own mode.
        self.assertEqual({path: self.modes.get(path, self.modes[self.database]) for path in self.files()},
                         {path: stat.S_IMODE(path.stat().st_mode) for path in self.files()})
        self.assertEqual({33}, {path.stat().st_uid for path in self.files()})
        self.assertFalse(self.record.exists())
        self.assertEqual(0, self.as_owner("INSERT INTO effects VALUES ('three')").returncode)

    def traced(self, action):
        """Run action while recording fsync targets, renames, chmods and unlinks in order."""
        events = []
        real_fsync, real_replace, real_chmod, real_unlink = os.fsync, os.replace, os.chmod, Path.unlink

        def fsync(descriptor):
            events.append(("fsync", os.readlink(f"/proc/self/fd/{descriptor}")))
            return real_fsync(descriptor)

        def replace(source, target, *args, **kwargs):
            real_replace(source, target, *args, **kwargs)
            events.append(("replace", str(target)))

        def chmod(path, mode, *args, **kwargs):
            events.append(("chmod", str(path)))
            return real_chmod(path, mode, *args, **kwargs)

        def unlink(path, *args, **kwargs):
            real_unlink(path, *args, **kwargs)
            events.append(("unlink", str(path)))

        with patch.object(os, "fsync", fsync), patch.object(os, "replace", replace), \
                patch.object(os, "chmod", chmod), patch.object(Path, "unlink", unlink):
            action()
        return events

    def test_seal_record_is_durable_before_write_bits_are_removed(self):
        # A power loss after the chmod must not leave a read-only database without its saved modes.
        events = self.traced(lambda: sqlite_seal.seal(self.database, self.record))
        published = events.index(("replace", str(self.record)))
        first_chmod = next(index for index, event in enumerate(events) if event[0] == "chmod")
        self.assertLess(published, first_chmod)
        self.assertIn(("fsync", str(self.record.parent)), events[published:first_chmod])

    def test_unseal_restored_modes_are_durable_before_the_record_is_removed(self):
        sqlite_seal.seal(self.database, self.record)
        events = self.traced(lambda: sqlite_seal.unseal(self.database, self.record))
        removed = events.index(("unlink", str(self.record)))
        last_chmod = max(index for index, event in enumerate(events) if event[0] == "chmod")
        self.assertLess(last_chmod, removed)
        synced = {path for kind, path in events[last_chmod:removed] if kind == "fsync"}
        self.assertTrue({str(path) for path in self.files()} <= synced, f"Restored modes not synced: {events}")
        self.assertIn(("fsync", str(self.record.parent)), events[removed:])

    def test_seal_refuses_while_an_idle_connection_keeps_the_database_open(self):
        # A tinker or sqlite3 session opened before the freeze holds no lock while idle, so the
        # checkpoint and BEGIN IMMEDIATE succeed. Its descriptors stay writable after the chmod.
        holder = subprocess.Popen(
            ["/usr/bin/setpriv", "--reuid=33", "--regid=33", "--clear-groups", sys.executable, "-c",
             "import sqlite3, sys\nc = sqlite3.connect(sys.argv[1], isolation_level=None)\n"
             "c.execute('SELECT count(*) FROM effects').fetchone()\nprint('idle', flush=True)\n"
             "sys.stdin.readline()\n"
             "try:\n    c.execute(\"INSERT INTO effects VALUES ('after-seal')\")\n    print('wrote')\n"
             "except sqlite3.Error as error:\n    print('refused', error)\n", str(self.database)],
            stdin=subprocess.PIPE, stdout=subprocess.PIPE, text=True,
        )
        self.addCleanup(lambda: holder.poll() is None and holder.kill())
        self.assertEqual("idle\n", holder.stdout.readline())
        try:
            sqlite_seal.seal(self.database, self.record)
        except UnsafeCutover as refused:
            self.assertIn("still has a writer", str(refused))
        else:
            written, _ = holder.communicate("go\n", timeout=5)
            self.fail(f"The seal succeeded while an idle connection kept the database open; "
                      f"its write after the seal: {written.strip()}")
        holder.communicate("go\n", timeout=5)
        sqlite_seal.unseal(self.database, self.record)
        self.assertTrue(sqlite_seal.writable(self.database))

    def fake_proc(self, handles):
        """A /proc tree with one open descriptor per process: {pid: (target, open flags)}."""
        proc = Path(tempfile.mkdtemp(prefix="proc-", dir=self.record.parent))
        for pid, (target, flags) in handles.items():
            (proc / str(pid) / "fd").mkdir(parents=True)
            (proc / str(pid) / "fdinfo").mkdir()
            (proc / str(pid) / "fd" / "3").symlink_to(target)
            (proc / str(pid) / "fdinfo" / "3").write_text(f"pos:\t0\nflags:\t{flags:o}\nmnt_id:\t1\n")
        return proc

    def test_seal_finds_writable_descriptors_and_ignores_read_only_ones(self):
        wal = Path(f"{self.database}-wal")
        proc = self.fake_proc({
            999991: (self.database, os.O_RDONLY | os.O_CLOEXEC),
            999992: (self.record.parent, os.O_RDWR),  # Another file.
            999993: (wal if wal.exists() else self.database, os.O_RDWR | os.O_CLOEXEC),
        })
        self.assertEqual([999993], sqlite_seal.open_writers(self.database, proc))
        with self.assertRaisesRegex(UnsafeCutover, "still has a writer: process 999993"):
            sqlite_seal.seal(self.database, self.record, proc=proc)
        # Refused before any change: modes kept, no record.
        self.assertTrue(sqlite_seal.writable(self.database))
        self.assertFalse(self.record.exists())
        self.assertEqual([], sqlite_seal.open_writers(self.database, self.fake_proc({})))

    def test_seal_refuses_while_a_writer_holds_the_database(self):
        holder = subprocess.Popen(
            ["/usr/bin/setpriv", "--reuid=33", "--regid=33", "--clear-groups", sys.executable, "-c",
             "import sqlite3, sys\nc = sqlite3.connect(sys.argv[1], isolation_level=None)\n"
             "c.execute('BEGIN IMMEDIATE')\nc.execute(\"INSERT INTO effects VALUES ('pending')\")\n"
             "print('holding', flush=True)\nsys.stdin.read(1)\n", str(self.database)],
            stdin=subprocess.PIPE, stdout=subprocess.PIPE, text=True,
        )
        self.addCleanup(lambda: holder.poll() is None and holder.kill())
        self.assertEqual("holding\n", holder.stdout.readline())
        with self.assertRaisesRegex(UnsafeCutover, "still has a writer"):
            sqlite_seal.seal(self.database, self.record, busy_seconds=0.2)
        self.assertTrue(sqlite_seal.writable(self.database))
        self.assertFalse(self.record.exists())
        holder.communicate("x", timeout=5)


class CronEntryTests(unittest.TestCase):
    RULES = json.loads((Path(__file__).resolve().parent / "cron-rules.example.json").read_text(encoding="utf-8"))
    APP = "/var/www/backend"
    STATE = "/home/maverick/.repro-cubicasa-revision-poll"
    RUNTIME = "/home/maverick/.local/share/repro-studio"

    def installed_lines(self):
        # The exact lines the repository's installers add.
        return [
            "17 * * * * cd /var/www/backend && /usr/bin/php artisan auth:prune-security-limits --limit=1000 >/dev/null 2>&1",
            f"7,37 * * * * /usr/local/sbin/repro-storage-guard --live && /usr/bin/flock -n {self.STATE}/poll.lock "
            f"/usr/bin/sg www-data -c 'umask 0002; cd {self.APP} && /usr/bin/php artisan cubicasa:refresh-deliveries "
            f"--limit=25' >> {self.STATE}/poll.log 2>&1 # repro-cubicasa-revision-poll",
            f"@reboot {self.RUNTIME}/ensure-supervisor.sh >> {self.RUNTIME}/logs/bootstrap.log 2>&1 # repro-studio-supervisor",
            f"* * * * * {self.RUNTIME}/ensure-supervisor.sh >> {self.RUNTIME}/logs/bootstrap.log 2>&1 # repro-studio-supervisor",
        ]

    def test_application_lines_go_through_the_wrapper_and_keep_schedule_output_and_markers(self):
        text = "\n".join(["MAILTO=\"\"", "# keep this comment",
                          "* * * * * cd /var/www/backend && php artisan schedule:run >> /dev/null 2>&1",
                          *self.installed_lines()]) + "\n"
        wrapped, unknown = crontab.wrap(text, self.RULES, WRAPPER)
        self.assertEqual([], unknown)
        lines = wrapped.splitlines()
        self.assertEqual(["MAILTO=\"\"", "# keep this comment"], lines[:2])
        self.assertEqual(f"* * * * * {WRAPPER} scheduler >> /dev/null 2>&1", lines[2])
        self.assertEqual(f"17 * * * * {WRAPPER} auth-prune >/dev/null 2>&1", lines[3])
        # The installer's storage guard stays in front of the wrapper (D20).
        self.assertEqual(f"7,37 * * * * {STORAGE_GUARD}{WRAPPER} cubicasa-poll >> {self.STATE}/poll.log 2>&1 "
                         "# repro-cubicasa-revision-poll", lines[4])
        # The Supervisor bootstrap starts wrapped programs, so it stays as it was.
        self.assertEqual(self.installed_lines()[2:], lines[5:])
        self.assertEqual((wrapped, []), crontab.wrap(wrapped, self.RULES, WRAPPER))

    def test_commands_chained_after_a_matched_entry_are_refused_not_dropped(self):
        # The wrapper runs only the profile's command; anything chained after the match would be lost.
        text = ("* * * * * cd /var/www/backend && php artisan schedule:run && /usr/local/bin/ping-check >> /dev/null 2>&1\n"
                "17 * * * * cd /var/www/backend && /usr/bin/php artisan auth:prune-security-limits --limit=1000; "
                "/usr/bin/touch /var/tmp/pruned\n"
                "* * * * * cd /var/www/backend && php artisan schedule:run 2>&1 | /usr/bin/logger -t schedule\n"
                "* * * * * cd /var/www/backend && php artisan schedule:run || /usr/bin/logger failed\n")
        wrapped, unknown = crontab.wrap(text, self.RULES, WRAPPER)
        self.assertEqual(text.splitlines(), unknown)
        self.assertNotIn(WRAPPER, wrapped)

    def test_unknown_application_lines_are_reported_not_installed(self):
        text = "*/5 * * * * cd /var/www/backend && php artisan tinker --execute='User::query()->delete()'\n" \
               "0 3 * * * /usr/bin/sqlite3 /var/www/backend/database/database.sqlite 'VACUUM'\n" \
               "0 4 * * * /usr/bin/certbot renew -q\n"
        wrapped, unknown = crontab.wrap(text, self.RULES, WRAPPER)
        self.assertEqual(2, len(unknown))
        self.assertTrue(all("/var/www/backend" in line for line in unknown))
        self.assertIn("certbot renew", wrapped)

    # D20: production runs the deploy user's application cron lines behind the storage guard.

    def guarded_lines(self):
        return [
            f"* * * * * {STORAGE_GUARD}cd /var/www/backend && php artisan schedule:run >> /dev/null 2>&1",
            f"17 * * * * {STORAGE_GUARD}cd /var/www/backend && /usr/bin/php artisan auth:prune-security-limits "
            "--limit=1000 >/dev/null 2>&1",
            self.installed_lines()[1],
        ]

    def test_guard_prefix_is_carried_over_to_the_wrapped_line(self):
        text = "\n".join(self.guarded_lines()) + "\n"
        wrapped, unknown = crontab.wrap(text, self.RULES, WRAPPER)
        self.assertEqual([], unknown)
        self.assertEqual([f"* * * * * {STORAGE_GUARD}{WRAPPER} scheduler >> /dev/null 2>&1",
                          f"17 * * * * {STORAGE_GUARD}{WRAPPER} auth-prune >/dev/null 2>&1",
                          f"7,37 * * * * {STORAGE_GUARD}{WRAPPER} cubicasa-poll >> {self.STATE}/poll.log 2>&1 "
                          "# repro-cubicasa-revision-poll"],
                         wrapped.splitlines())
        self.assertEqual((wrapped, []), crontab.wrap(wrapped, self.RULES, WRAPPER))

    def test_unknown_prefix_before_a_known_command_is_refused(self):
        # Only the exact guard form is carried over; the wrapper would silently drop anything else.
        scheduler = "cd /var/www/backend && php artisan schedule:run >> /dev/null 2>&1"
        prefixes = (
            "/usr/local/sbin/repro-storage-guard && ",
            "/usr/local/sbin/repro-storage-guard --dry-run && ",
            "/usr/local/sbin/repro-storage-guard --live; ",
            "/usr/local/sbin/repro-storage-guard --live || ",
            "/usr/local/sbin/repro-storage-guard --live  && ",
            "/usr/local/sbin/repro-storage-guard --live &&",
            "/usr/local/bin/repro-storage-guard --live && ",
            "repro-storage-guard --live && ",
            f"{STORAGE_GUARD}{STORAGE_GUARD}",
            f"/usr/bin/test -e /var/tmp/ok && {STORAGE_GUARD}",
            "/usr/bin/nice -n 10 ",
            "cd /tmp && ",
        )
        lines = [f"* * * * * {prefix}{scheduler}" for prefix in prefixes]
        # Something between the guard and the rule's command, or inside the command, is a prefix too.
        lines += [f"* * * * * {STORAGE_GUARD}/usr/bin/timeout 50 {scheduler}",
                  f"* * * * * {STORAGE_GUARD}cd /var/www/backend && /usr/bin/nice php artisan schedule:run",
                  f"17 * * * * /usr/bin/flock -n /tmp/prune.lock {STORAGE_GUARD}cd /var/www/backend && "
                  "/usr/bin/php artisan auth:prune-security-limits --limit=1000 >/dev/null 2>&1",
                  self.installed_lines()[1].replace("/usr/bin/flock -n", "/usr/bin/flock -w 5", 1)]
        text = "\n".join(lines) + "\n"
        wrapped, unknown = crontab.wrap(text, self.RULES, WRAPPER)
        self.assertEqual(lines, unknown)
        self.assertEqual(text, wrapped)
        # From the command line: exit 1, every refused line named, nothing on stdout to install.
        rules = Path(__file__).resolve().parent / "cron-rules.example.json"
        refused = subprocess.run([sys.executable, "-I", "-S", "-B", str(Path(crontab.__file__).resolve()),
                                  "--rules", str(rules), "--wrapper", WRAPPER],
                                 input=text.encode("utf-8"), capture_output=True)
        self.assertEqual((1, b""), (refused.returncode, refused.stdout))
        self.assertEqual(lines, [line[2:] for line in refused.stderr.decode("utf-8").splitlines()[1:]])

    def test_reverse_rewrite_restores_the_original_line_byte_for_byte(self):
        original = "\n".join([
            # A line saved with a Windows line end keeps its carriage return, a Latin-1 byte stays that byte.
            'MAILTO=""', "# keep this comment", "# edited elsewhere\r", "# caf\udce9", "",
            *self.guarded_lines(),
            # Unguarded, as the repository's installer writes it, and with tabs and double spaces.
            "17  3 * * *\tcd /var/www/backend && /usr/bin/php artisan auth:prune-security-limits --limit=1000",
            f"*/5 * * * *  {STORAGE_GUARD}cd /var/www/backend && php artisan schedule:run  >>  /dev/null  2>&1",
            *self.installed_lines()[2:],
            "0 4 * * * /usr/bin/certbot renew -q",
            "47 3 * * * /usr/local/sbin/repro-storage-guard --live && /usr/bin/python3 "
            "/var/www/backend/scripts/deploy/deployment-storage.py scheduled-retention >/dev/null 2>&1 "
            "# repro-deploy-backup-retention",
        ]) + "\n"
        wrapped, unknown = crontab.wrap(original, self.RULES, WRAPPER)
        self.assertEqual([], unknown)
        self.assertEqual(5, wrapped.count(WRAPPER))
        restored, unknown = crontab.unwrap(wrapped, self.RULES, WRAPPER)
        self.assertEqual([], unknown)
        raw = original.encode("utf-8", "surrogateescape")
        self.assertEqual(raw, restored.encode("utf-8", "surrogateescape"))
        # The operator's path: stdin to stdout through the command line, compared as bytes (in the caller's
        # locale; a UTF-8 locale's text streams would refuse the Latin-1 byte).
        rules = Path(__file__).resolve().parent / "cron-rules.example.json"
        command = [sys.executable, "-I", "-S", "-B", str(Path(crontab.__file__).resolve()), "--rules", str(rules),
                   "--wrapper", WRAPPER]
        forward = subprocess.run(command, input=raw, capture_output=True)
        self.assertEqual((0, b""), (forward.returncode, forward.stderr))
        self.assertEqual(wrapped.encode("utf-8", "surrogateescape"), forward.stdout)
        reverse = subprocess.run(command + ["--reverse"], input=forward.stdout, capture_output=True)
        self.assertEqual((0, b""), (reverse.returncode, reverse.stderr))
        self.assertEqual(raw, reverse.stdout)

    def test_reverse_refuses_a_wrapped_profile_without_exactly_one_rule(self):
        text = f"* * * * * {STORAGE_GUARD}{WRAPPER} unknown-profile >> /dev/null 2>&1\n" \
               f"17 * * * * {WRAPPER} auth-prune >/dev/null 2>&1\n"
        restored, unknown = crontab.unwrap(text, self.RULES, WRAPPER)
        self.assertEqual(text.splitlines()[:1], unknown)
        twice = {**self.RULES, "rules": self.RULES["rules"] + [
            {"match": "artisan schedule:run --verbose", "profile": "auth-prune",
             "command": "cd /var/www/backend && php artisan schedule:run --verbose"}]}
        restored, unknown = crontab.unwrap(text, twice, WRAPPER)
        self.assertEqual(text.splitlines(), unknown)

    def test_a_rewrite_that_could_not_be_reversed_is_refused(self):
        # Two rules for one profile: the reverse could not tell which command the line held.
        rules = {**self.RULES, "rules": [
            {"match": "artisan schedule:run --verbose", "profile": "scheduler",
             "command": "cd /var/www/backend && php artisan schedule:run --verbose"}, *self.RULES["rules"]]}
        text = "* * * * * cd /var/www/backend && php artisan schedule:run --verbose >> /dev/null 2>&1\n"
        wrapped, unknown = crontab.wrap(text, rules, WRAPPER)
        self.assertEqual(text.splitlines(), unknown)
        self.assertEqual(text, wrapped)

    def test_rule_without_its_exact_command_is_refused(self):
        for rule in ({"match": "artisan schedule:run", "profile": "scheduler"},
                     {"match": "artisan schedule:run", "profile": "scheduler", "command": ""},
                     {"match": "artisan schedule:run", "profile": "scheduler", "command": "x", "allow": "both"},
                     {"match": "artisan schedule:run"},
                     # An allow rule names its exact command too (review C2-8).
                     {"match": "/ensure-supervisor\\.sh", "allow": "Starts the user Supervisor"},
                     {"match": "/ensure-supervisor\\.sh", "allow": "Starts the user Supervisor", "command": ""}):
            with self.subTest(rule=rule):
                with self.assertRaisesRegex(ValueError, "cron rule"):
                    crontab.wrap("", {**self.RULES, "rules": [rule]}, WRAPPER)
                with self.assertRaisesRegex(ValueError, "cron rule"):
                    crontab.unwrap("", {**self.RULES, "rules": [rule]}, WRAPPER)

    # Wave-2 review (C2-8, C2-9, 26 Nov).

    RETENTION = ("47 3 * * * /usr/bin/python3 /var/www/backend/scripts/deploy/deployment-storage.py scheduled-retention "
                 ">/dev/null 2>&1 # repro-deploy-backup-retention")

    def cli(self, rules: dict, text: str, *extra: str) -> subprocess.CompletedProcess:
        directory = tempfile.TemporaryDirectory()
        self.addCleanup(directory.cleanup)
        path = Path(directory.name) / "cron-rules.json"
        path.write_text(json.dumps(rules), encoding="utf-8")
        return subprocess.run([sys.executable, "-I", "-S", "-B", str(Path(crontab.__file__).resolve()),
                               "--rules", str(path), "--wrapper", WRAPPER, *extra],
                              input=text.encode("utf-8"), capture_output=True)

    def test_allow_rules_keep_only_their_exact_command(self):
        # An allow rule used to match anywhere in the command and skip every exactness check, so a line
        # holding an allowed script name was kept whatever else it ran.
        kept = [*self.installed_lines()[2:], self.RETENTION,
                self.RETENTION.replace("47 3 * * * ", f"47 3 * * * {STORAGE_GUARD}", 1)]
        refused = [
            # The reviewer's two lines.
            "*/5 * * * * cd /var/www/backend && php artisan telnyx:prune-webhook-events; "
            "/home/maverick/.local/bin/ensure-supervisor.sh",
            f"35 3 * * * {STORAGE_GUARD}/usr/bin/python3 /var/www/backend/scripts/deploy/deployment-storage.py "
            "scheduled-retention && sqlite3 /var/www/backend/database/database.sqlite 'VACUUM'",
            # An unknown artisan command chained before and after the allowed script itself.
            f"* * * * * cd /var/www/backend && php artisan telnyx:prune-webhook-events; {self.RUNTIME}/ensure-supervisor.sh "
            f">> {self.RUNTIME}/logs/bootstrap.log 2>&1 # repro-studio-supervisor",
            f"* * * * * {self.RUNTIME}/ensure-supervisor.sh && cd /var/www/backend && php artisan "
            f"telnyx:prune-webhook-events >> {self.RUNTIME}/logs/bootstrap.log 2>&1",
            # Another option, another prefix.
            "47 3 * * * /usr/bin/python3 /var/www/backend/scripts/deploy/deployment-storage.py retention --apply "
            ">/dev/null 2>&1",
            f"47 3 * * * /usr/bin/nice {self.RETENTION[len('47 3 * * * '):]}",
        ]
        text = "\n".join(kept + refused) + "\n"
        wrapped, unknown = crontab.wrap(text, self.RULES, WRAPPER)
        self.assertEqual(refused, unknown)
        self.assertEqual(text, wrapped)
        self.assertEqual([], crontab.wrap("\n".join(kept) + "\n", self.RULES, WRAPPER)[1])
        result = self.cli(self.RULES, text)
        self.assertEqual((1, b""), (result.returncode, result.stdout))
        self.assertEqual(refused, [line[2:] for line in result.stderr.decode("utf-8").splitlines()[1:]])

    def test_a_command_hidden_in_a_redirection_target_is_refused(self):
        # The trailer kept behind the wrapper is output redirections to plain paths and a marker comment only:
        # a target holding ;, &&, |, $( ), backquotes or cron's % would run (or feed) something unfenced.
        scheduler = "cd /var/www/backend && php artisan schedule:run"
        lines = [
            f"* * * * * {scheduler} >/dev/null;/usr/local/bin/ping-check",
            f"* * * * * {STORAGE_GUARD}{scheduler} >> /dev/null&&/usr/local/bin/ping-check",
            "17 * * * * cd /var/www/backend && /usr/bin/php artisan auth:prune-security-limits --limit=1000 "
            ">/dev/null 2>&1|/usr/bin/logger",
            f"* * * * * {scheduler} >> /tmp/$(/usr/local/bin/ping-check).log 2>&1",
            f"* * * * * {scheduler} >> /tmp/`/usr/local/bin/ping-check`.log 2>&1",
            f"* * * * * {scheduler} >> /dev/null%input 2>&1",
            f"* * * * * {scheduler} >> /dev/null 2>&1 # marker%input",
            f"* * * * * {self.RUNTIME}/ensure-supervisor.sh >> {self.RUNTIME}/logs/bootstrap.log;"
            "/usr/bin/sqlite3 2>&1 # repro-studio-supervisor",
        ]
        text = "\n".join(lines) + "\n"
        wrapped, unknown = crontab.wrap(text, self.RULES, WRAPPER)
        self.assertEqual(lines, unknown)
        self.assertEqual(text, wrapped)

    def test_a_bad_match_expression_means_the_rules_cannot_be_used(self):
        rules = {**self.RULES, "rules": [{"match": "(", "profile": "scheduler",
                                          "command": "cd /var/www/backend && php artisan schedule:run"}]}
        line = "* * * * * cd /var/www/backend && php artisan schedule:run >> /dev/null 2>&1\n"
        for extra in ((), ("--reverse",)):
            with self.subTest(extra=extra):
                result = self.cli(rules, line, *extra)
                self.assertEqual((2, b""), (result.returncode, result.stdout), result.stderr)
                self.assertIn(b"the cron rules cannot be used", result.stderr)
                self.assertNotIn(b"Traceback", result.stderr)

    def test_rule_command_that_starts_with_the_storage_guard_is_refused(self):
        # The natural mistake: the rule's command copied from the live line, guard included. The guard is
        # carried over separately, so such a rule could never match and every guarded line would be refused.
        scheduler = "cd /var/www/backend && php artisan schedule:run"
        for rule in ({"match": "artisan schedule:run", "profile": "scheduler", "command": STORAGE_GUARD + scheduler},
                     {"match": "artisan schedule:run", "profile": "scheduler",
                      "command": "/usr/local/sbin/repro-storage-guard && " + scheduler},
                     {"match": "deployment-storage\\.py", "allow": "Deploy backup retention",
                      "command": STORAGE_GUARD + "/usr/bin/python3 /var/www/backend/scripts/deploy/deployment-storage.py "
                                                 "scheduled-retention"}):
            with self.subTest(rule=rule):
                rules = {**self.RULES, "rules": [rule]}
                for rewrite in (crontab.wrap, crontab.unwrap):
                    with self.assertRaisesRegex(ValueError, "carried over separately"):
                        rewrite("", rules, WRAPPER)
                result = self.cli(rules, f"* * * * * {STORAGE_GUARD}{scheduler}\n")
                self.assertEqual((2, b""), (result.returncode, result.stdout))
                self.assertIn(b"carried over separately", result.stderr)


if __name__ == "__main__":
    unittest.main()
