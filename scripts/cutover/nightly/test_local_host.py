"""Tests for the real host adapter. Linux-only parts run inside the offline container."""
import os
import subprocess
import sys
import tempfile
import time
import unittest
from pathlib import Path

from scripts.cutover.nightly import runner as nightly

HERE = Path(__file__).resolve().parent
POSIX = os.name == "posix"


class DatabaseSettingsTests(unittest.TestCase):
    def test_reads_only_the_three_database_keys(self):
        settings = nightly.database_settings(
            "APP_KEY=base64:secret\nDB_CONNECTION=sqlite\nDB_DATABASE='database/database.sqlite'\nMAIL_PASSWORD=x\n")
        self.assertEqual(settings, {"DB_CONNECTION": "sqlite", "DB_DATABASE": "database/database.sqlite"})

    def test_refuses_repeated_or_non_sqlite_settings(self):
        with self.assertRaisesRegex(nightly.Refused, "repeated"):
            nightly.database_settings("DB_CONNECTION=sqlite\nDB_CONNECTION=mysql\n")
        for text in ("DB_CONNECTION=mysql\n", "DB_CONNECTION=sqlite\nDB_URL=mysql://x\n", ""):
            with self.subTest(text=text):
                with self.assertRaisesRegex(nightly.Refused, "not-sqlite"):
                    nightly.resolve_live_database(Path("/nonexistent"), nightly.database_settings(text))

    def test_live_database_must_be_a_sqlite_file_under_database(self):
        with tempfile.TemporaryDirectory() as directory:
            backend = Path(directory).resolve()
            (backend / "database").mkdir()
            database = backend / "database" / "database.sqlite"
            database.write_bytes(b"sqlite")
            live = nightly.resolve_live_database(backend, {"DB_CONNECTION": "sqlite"})
            self.assertEqual(live.path, database)
            self.assertEqual(live.size, 6)
            (backend / "storage").mkdir()
            (backend / "storage" / "other.sqlite").write_bytes(b"x")
            with self.assertRaisesRegex(nightly.Refused, "path-unexpected"):
                nightly.resolve_live_database(backend, {"DB_CONNECTION": "sqlite",
                                                        "DB_DATABASE": "storage/other.sqlite"})


@unittest.skipUnless(POSIX, "Linux permissions")
class PrivateStateTests(unittest.TestCase):
    def test_state_directory_must_be_private_to_the_runner(self):
        with tempfile.TemporaryDirectory() as directory:
            shared = Path(directory) / "state"
            shared.mkdir(mode=0o755)
            shared.chmod(0o755)
            with self.assertRaisesRegex(nightly.Refused, "state-dir-unsafe"):
                nightly.private_directory(shared)
            shared.chmod(0o700)
            nightly.private_directory(shared)
            created = Path(directory) / "created"
            nightly.private_directory(created)
            self.assertEqual(created.stat().st_mode & 0o777, 0o700)


@unittest.skipUnless(POSIX and hasattr(os, "geteuid") and os.geteuid() != 0, "needs an unprivileged Linux user")
class LocalHostTests(unittest.TestCase):
    def setUp(self):
        folder = tempfile.TemporaryDirectory()
        self.addCleanup(folder.cleanup)
        self.root = Path(folder.name)
        config = nightly.Config.load(HERE / "nightly-config.json").data
        config |= {"candidate_root": str(self.root / "candidate"), "deploy_lock": str(self.root / "deploy.lock"),
                   "backend_root": str(self.root / "backend")}
        self.host = nightly.LocalHost(nightly.Config(config))

    def test_deploy_lock_probe_never_creates_the_lock_and_sees_a_holder(self):
        import fcntl
        lock = self.root / "deploy.lock"
        self.assertTrue(self.host.deploy_lock_free())
        self.assertFalse(lock.exists())
        lock.write_bytes(b"")
        self.assertTrue(self.host.deploy_lock_free())
        with lock.open("rb") as holder:
            fcntl.flock(holder.fileno(), fcntl.LOCK_EX)
            self.assertFalse(self.host.deploy_lock_free())
        self.assertTrue(self.host.deploy_lock_free())

    def test_checkout_reports_head_and_tracked_changes(self):
        candidate = self.root / "candidate"
        candidate.mkdir()
        env = {"PATH": "/usr/bin:/bin", "HOME": str(self.root), "GIT_CONFIG_NOSYSTEM": "1",
               "GIT_AUTHOR_NAME": "t", "GIT_AUTHOR_EMAIL": "t@example.invalid",
               "GIT_COMMITTER_NAME": "t", "GIT_COMMITTER_EMAIL": "t@example.invalid"}
        for command in (["init", "-q"], ["add", "-A"], ["commit", "-q", "--allow-empty", "-m", "x"]):
            subprocess.run(["git", "-C", str(candidate), *command], env=env, check=True)
        (candidate / "tool.py").write_text("v1\n", encoding="utf-8")
        subprocess.run(["git", "-C", str(candidate), "add", "tool.py"], env=env, check=True)
        subprocess.run(["git", "-C", str(candidate), "commit", "-q", "-m", "tool"], env=env, check=True)
        head = subprocess.run(["git", "-C", str(candidate), "rev-parse", "HEAD"], env=env, check=True,
                              capture_output=True, text=True).stdout.strip()
        self.assertEqual(self.host.checkout(), (head, True))
        (candidate / "tool.py").write_text("v2\n", encoding="utf-8")
        self.assertEqual(self.host.checkout(), (head, False))

    def test_memory_and_timeouts(self):
        meminfo = self.root / "meminfo"
        meminfo.write_text("MemTotal:       32000000 kB\nMemAvailable:   26000000 kB\n", encoding="ascii")
        self.assertEqual(self.host.mem_available_kib(meminfo), 26_000_000)
        result = self.host.run([sys.executable, "-c", "print('ok')"], timeout_s=30)
        self.assertEqual((result.returncode, result.stdout), (0, "ok\n"))
        with self.assertRaises(subprocess.TimeoutExpired):
            self.host.run([sys.executable, "-c", "import time; time.sleep(5)"], timeout_s=0.5)
        with self.assertRaises(subprocess.TimeoutExpired):
            self.host.run([sys.executable, "-c", "print(1)"], timeout_s=0)

    def test_stale_mysqld_stop_only_targets_the_recorded_work_directory(self):
        work = self.root / "work-abc"
        sleeper = [sys.executable, "-c", "import time; time.sleep(60)"]
        ours = subprocess.Popen([*sleeper, f"--datadir={work}/data"])
        other = subprocess.Popen([*sleeper, f"--datadir={self.root}/elsewhere/data"])
        self.addCleanup(other.kill)
        self.addCleanup(ours.kill)
        time.sleep(0.2)
        self.host.stop_stale_mysqld(other.pid, work)
        self.assertIsNone(other.poll())
        self.host.stop_stale_mysqld(ours.pid, work)
        self.assertIsNotNone(ours.wait(timeout=10))


if __name__ == "__main__":
    unittest.main()
