"""Linux tests for the production admission wrapper: real flock, privilege drop and signals."""

import json
import os
import signal
import subprocess
import sys
import tempfile
import time
import unittest
from contextlib import contextmanager
from pathlib import Path
from unittest.mock import patch

sys.path[:0] = [str(Path(__file__).resolve().parents[1]), str(Path(__file__).resolve().parent)]
from fence import guard
from rehearse import FixtureAdapter
from runner import Identity, Journal, Runner, UnsafeCutover

IDENTITY = Identity("admit-fixture", "a" * 40, "b" * 64, "c" * 64, "d" * 64, "ec219d68-61fd-4a9e-ba98-43ddb4008383")
MACHINE_ID = b"synthetic-admit-fixture"
PHP = "/usr/local/bin/php"


class Adapter(FixtureAdapter):
    MACHINE_SHA256 = guard.sha256(MACHINE_ID)


@contextmanager
def fixture_guard(directory):
    # The test is not installed at /opt. The real trust, admission and flock
    # checks still apply to every policy, proof and release marker.
    with (
        patch.multiple(
            guard, STATE=directory,
            POLICY=directory / "policy.json", EVIDENCE=directory / "fixture-authority.json",
            MACHINE_ID=directory / "machine-id", BOOT_ID=directory / "boot-id",
        ),
        patch.object(guard, "_require_root_installation"),
    ):
        yield


def child_main(directory, profile):
    import admit

    directory = Path(directory)
    with fixture_guard(directory), patch.object(admit, "SCHEDULER_RELEASE", directory / "scheduler-released"):
        return admit.run(profile, wrappers=directory / "wrappers.json")


class WrapperExampleTests(unittest.TestCase):
    def test_wrapper_files_differ_only_in_engine(self):
        directory = Path(__file__).resolve().parent
        sqlite = json.loads((directory / "wrappers.sqlite.example.json").read_bytes())
        mysql = json.loads((directory / "wrappers.mysql.example.json").read_bytes())
        self.assertTrue({"telnyx-replay", "cutover-smoke"} <= sqlite["profiles"].keys())
        for profile in sqlite["profiles"].values():
            self.assertEqual("sqlite", profile["engine"])
            profile["engine"] = "mysql"
        self.assertEqual(sqlite, mysql)

    @unittest.skipUnless(sys.platform == "linux", "The host profiles use Linux accounts")
    def test_every_profile_loads(self):
        import admit

        directory = Path(__file__).resolve().parent
        # The synthetic host uses nobody for the deploy user and the real www-data group.
        users = {"maverick": admit.pwd.getpwnam("nobody"), "www-data": admit.pwd.getpwnam("www-data")}
        with patch.object(admit.pwd, "getpwnam", side_effect=users.__getitem__):
            for engine in ("sqlite", "mysql"):
                raw = (directory / f"wrappers.{engine}.example.json").read_bytes()
                for name in json.loads(raw)["profiles"]:
                    with self.subTest(engine=engine, name=name):
                        self.assertEqual(engine, admit.load_profile(name, raw)["engine"])


@unittest.skipUnless(sys.platform == "linux" and os.geteuid() == 0, "Requires Linux root to drop privileges")
class AdmissionWrapperTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix="repro-cutover-admit-", dir="/var/lib")
        self.addCleanup(temporary.cleanup)
        self.directory = Path(temporary.name).resolve()
        self.out = self.directory / "out"
        self.out.mkdir()
        self.out.chmod(0o777)
        self.journal = Journal(self.directory / "state")
        self.adapter = Adapter(self.journal, IDENTITY)
        self.runner = Runner(self.journal, IDENTITY, self.adapter)
        self.runner.initialize()
        state = self.journal.directory
        (state / "machine-id").write_bytes(MACHINE_ID)
        (state / "boot-id").write_text(self.adapter.BOOT_ID, encoding="ascii")
        self.journal.write_record("policy.json", self.adapter.policy())
        self.journal.write_record("fixture-authority.json",
                                  self.adapter.authority_evidence("sqlite", self.journal.path.read_bytes()))

    def profiles(self, **profiles):
        record = {"version": 1, "profiles": {
            name: {"action": "queue-worker", "engine": "sqlite", "user": "www-data", "group": "www-data",
                   "cwd": str(self.out), "umask": "0007", "env": {"REPRO_PROFILE": name}, "timeout_seconds": None,
                   **fields}
            for name, fields in profiles.items()
        }}
        (self.journal.directory / "wrappers.json").write_text(json.dumps(record), encoding="utf-8")

    def start(self, profile):
        child = subprocess.Popen(
            [sys.executable, "-B", str(Path(__file__).resolve()), "--admit-child", str(self.journal.directory), profile],
            stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True,
        )
        self.addCleanup(lambda: child.poll() is None and child.kill())
        return child

    def wait_for(self, name, seconds=5):
        limit = time.monotonic() + seconds
        while not (self.out / name).exists():
            self.assertLess(time.monotonic(), limit, f"{name} never appeared")
            time.sleep(0.02)
        return (self.out / name).read_text().strip()

    def writers_drain(self, seconds=0.1):
        with self.journal.acquire() as (_, lease):
            try:
                self.journal.drain_writers(lease, seconds)
            except UnsafeCutover:
                return False
        return True

    def test_guard_refuses_sqlite_profile_in_mysql_authority(self):
        import admit

        self.runner.perform("hold", self.runner.approval_request("hold"))
        self.runner.perform("freeze")
        self.runner.perform("prepare")
        self.runner.perform("promote", self.runner.approval_request("promote"))
        directory = Path(__file__).resolve().parent
        with fixture_guard(self.journal.directory):
            for engine in ("mysql", "sqlite"):
                raw = (directory / f"wrappers.{engine}.example.json").read_bytes()
                profile = admit.load_profile("cutover-smoke", raw)
                if engine == "mysql":
                    guard.check(profile["action"], profile["engine"])
                else:
                    with self.assertRaisesRegex(guard.Refused, "not authoritative"):
                        guard.check(profile["action"], profile["engine"])

    def test_planned_journal_runs_the_profile_as_its_user_and_holds_the_lease(self):
        self.profiles(worker={"argv": ["/bin/sh", "-c",
                      "id -u > uid; id -g > gid; umask > umask; pwd > cwd; echo $REPRO_PROFILE > env; "
                      "echo ready > started; while [ ! -e release ]; do sleep 0.05; done; exit 3"]})
        wrapper = self.start("worker")
        self.wait_for("started")
        self.assertEqual(("33", "33", "0007", str(self.out), "worker"),
                         tuple((self.out / name).read_text().strip() for name in ("uid", "gid", "umask", "cwd", "env")))
        self.assertFalse(self.writers_drain(), "The drain finished while the admitted worker ran")
        (self.out / "release").touch()
        wrapper.communicate(timeout=5)
        self.assertEqual(3, wrapper.returncode)
        self.assertTrue(self.writers_drain())

    def test_held_journal_refuses_without_running_the_command(self):
        self.profiles(worker={"argv": ["/bin/sh", "-c", "echo ran > started"]})
        self.runner.perform("hold", self.runner.approval_request("hold"))
        wrapper = self.start("worker")
        _, errors = wrapper.communicate(timeout=5)
        self.assertEqual(75, wrapper.returncode, errors)
        self.assertIn("holds all admissions", errors)
        self.assertFalse((self.out / "started").exists())

    def test_scheduler_profiles_require_a_trusted_release_marker(self):
        names = ("scheduler", "auth-prune", "cubicasa-poll")
        self.profiles(**{name: {"action": "scheduler", "argv": ["/usr/bin/touch", name]} for name in names})
        release = self.journal.directory / "scheduler-released"
        for name in names:
            wrapper = self.start(name)
            _, errors = wrapper.communicate(timeout=5)
            self.assertEqual(75, wrapper.returncode, errors)
            self.assertFalse((self.out / name).exists())
        release.touch(mode=0o600)
        release.chmod(0o666)
        wrapper = self.start(names[0])
        _, errors = wrapper.communicate(timeout=5)
        self.assertEqual(75, wrapper.returncode, errors)
        release.unlink()
        release.symlink_to(self.journal.path)
        wrapper = self.start(names[0])
        _, errors = wrapper.communicate(timeout=5)
        self.assertEqual(75, wrapper.returncode, errors)
        self.assertFalse((self.out / names[0]).exists())
        release.unlink()
        release.touch(mode=0o600)
        for name in names:
            wrapper = self.start(name)
            _, errors = wrapper.communicate(timeout=5)
            self.assertEqual(0, wrapper.returncode, errors)
            self.assertTrue((self.out / name).exists())

    def test_detached_children_keep_the_lease_after_the_command_exits(self):
        detached = {
            "shell": ["/bin/sh", "-c", "(sleep 1.5; echo done > shell-done) > /dev/null 2>&1 & exit 0"],
            "php": [PHP, "-r", "exec('(sleep 1.5; echo done > php-done) > /dev/null 2>&1 &');"],
            # Laravel's runInBackground goes through Symfony Process: proc_open with pipes.
            "php-proc-open": [PHP, "-r", "$p = proc_open('(sleep 1.5; echo done > php-proc-open-done) "
                              "> /dev/null 2>&1 &', [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes); "
                              "foreach ($pipes as $pipe) { fclose($pipe); } exit(proc_close($p));"],
        }
        self.profiles(**{name: {"argv": argv} for name, argv in detached.items()})
        for name in detached:
            with self.subTest(launcher=name):
                wrapper = self.start(name)
                _, errors = wrapper.communicate(timeout=5)
                self.assertEqual(0, wrapper.returncode, errors)
                self.assertFalse(self.writers_drain(), "A detached child lost the inherited lease")
                self.wait_for(f"{name}-done")
                time.sleep(0.1)
                self.assertTrue(self.writers_drain())

    def test_terminate_signal_is_forwarded_and_status_returned(self):
        self.profiles(worker={"argv": ["/bin/sh", "-c",
                      "trap 'echo term > term; exit 7' TERM; echo ready > started; while :; do sleep 0.05; done"]})
        wrapper = self.start("worker")
        self.wait_for("started")
        wrapper.send_signal(signal.SIGTERM)
        wrapper.communicate(timeout=5)
        self.assertEqual(7, wrapper.returncode)
        self.assertEqual("term", (self.out / "term").read_text().strip())
        self.assertTrue(self.writers_drain())

    def test_timeout_stops_the_command_and_releases_the_lease(self):
        self.profiles(worker={"argv": ["/bin/sleep", "30"], "timeout_seconds": 1})
        started = time.monotonic()
        wrapper = self.start("worker")
        wrapper.communicate(timeout=10)
        self.assertEqual(124, wrapper.returncode)
        self.assertLess(time.monotonic() - started, 8)
        self.assertTrue(self.writers_drain())

    def test_unknown_profile_or_unsafe_definition_is_a_configuration_error(self):
        for profiles, name in (
            ({"worker": {"argv": ["/bin/true"]}}, "missing"),
            ({"worker": {"argv": ["relative/true"]}}, "worker"),
            ({"worker": {"argv": ["/bin/true"], "action": "anything"}}, "worker"),
            ({"worker": {"argv": ["/bin/true"], "user": "root"}}, "worker"),
        ):
            with self.subTest(profiles=profiles, name=name):
                self.profiles(**profiles)
                wrapper = self.start(name)
                _, errors = wrapper.communicate(timeout=5)
                self.assertEqual(78, wrapper.returncode, errors)


if __name__ == "__main__":
    if len(sys.argv) == 4 and sys.argv[1] == "--admit-child":
        sys.exit(child_main(*sys.argv[2:]))
    unittest.main()
