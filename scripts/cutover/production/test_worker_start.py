"""Synthetic procfs cases for the post-switch writer age check."""
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

HERE = Path(__file__).resolve().parent
sys.path[:0] = [str(HERE), str(HERE.parent)]
import worker_start
from runner import UnsafeCutover


class WorkerStartTests(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.proc = Path(temp.name)
        (self.proc / "stat").write_bytes(b"cpu 0 0 0\nbtime 1000\nprocesses 5\n")
        self.patterns = ["artisan queue:work", "artisan schedule:run"]

    def stat(self, pid, ticks, comm=b"php", state=b"S"):
        # Field 3 is the state. Fields 4 through 21 are placeholders.
        return str(pid).encode() + b" (" + comm + b") " + b" ".join(
            [state] + [b"0"] * 18 + [str(ticks).encode()] + [b"0"] * 30) + b"\n"

    def process(self, pid, ticks, command=b"php\0/app/artisan\0queue:work\0", **stat):
        directory = self.proc / str(pid)
        directory.mkdir()
        (directory / "stat").write_bytes(self.stat(pid, ticks, **stat))
        (directory / "cmdline").write_bytes(command)
        return directory

    def verify(self, switch=1020, ticks=250):
        return worker_start.verify(self.proc, self.patterns, switch, clock_ticks=ticks)

    def test_stable_writers_are_sorted_and_intermittent_patterns_need_not_run(self):
        self.process(99902, 5300, comm=b"worker ) fake )\nname")
        self.process(99903, 5, command=b"unrelated\0queue:work\0")
        self.process(99901, 5250)
        self.assertEqual([99901, 99902], self.verify())

    def test_one_stale_writer_rejects_an_otherwise_fresh_group(self):
        self.process(99902, 6000)
        self.process(99903, 4)
        with self.assertRaisesRegex(UnsafeCutover, "99903.*newer"):
            self.verify()

    def test_same_second_as_switch_is_indeterminate_even_when_fraction_appears_newer(self):
        worker = self.process(99902, 5249)
        with self.assertRaisesRegex(UnsafeCutover, "newer"):
            self.verify()
        (worker / "stat").write_bytes(self.stat(99902, 5250))
        self.assertEqual([99902], self.verify())

    def test_worker_started_after_a_reboot_later_than_the_switch(self):
        self.process(99901, 10)
        self.assertEqual([99901], self.verify(switch=990))

    def test_zero_matches_or_unconfigured_inventory_refuses(self):
        self.process(99901, 6000, command=b"not-a-worker\0")
        for patterns in (self.patterns, [], [""]):
            with self.subTest(patterns=patterns), self.assertRaises(UnsafeCutover):
                worker_start.verify(self.proc, patterns, 1020, clock_ticks=250)

    def test_unverifiable_clock_or_switch_refuses(self):
        self.process(99901, 6000)
        for switch, ticks in ((False, 250), (0, 250), (1020.25, 250), (1020, 0), (1020, True)):
            with self.subTest(switch=switch, ticks=ticks), self.assertRaises(UnsafeCutover):
                self.verify(switch, ticks)
        for boot in (b"", b"btime X\n", b"btime 0\n", b"btime -97\n", b"btime 999 1200\n", b"btime 999\nbtime 1200\n"):
            with self.subTest(boot=boot), self.assertRaises(UnsafeCutover):
                (self.proc / "stat").write_bytes(boot)
                self.verify()

    def test_old_reused_dead_or_malformed_worker_metadata_refuses(self):
        worker = self.process(99902, 5250)
        for raw in (self.stat(99902, 9), self.stat(99903, 6000), self.stat(99902, 6000, state=b"Z"),
                    self.stat(99902, 6000, state=b"X"), self.stat(99902, "not-ticks"), b"99902 (php) R 0 0"):
            with self.subTest(raw=raw), self.assertRaises(UnsafeCutover):
                (worker / "stat").write_bytes(raw)
                self.verify()

    def test_permissions_or_missing_files_on_an_existing_pid_fail_closed(self):
        self.process(99901, 5250)
        other = self.process(99902, 5280, command=b"unrelated\0")
        read = Path.read_bytes

        def unreadable(path):
            if path == other / "cmdline":
                raise PermissionError()
            return read(path)

        with patch.object(Path, "read_bytes", unreadable), self.assertRaises(UnsafeCutover):
            self.verify()
        (other / "cmdline").unlink()
        with self.assertRaisesRegex(UnsafeCutover, "Incomplete process inventory"):
            self.verify()

    def test_pid_reuse_mid_read_fails_even_if_both_workers_appear_fresh(self):
        worker = self.process(99901, 5260)
        read = Path.read_bytes

        def replace_pid(path):
            if path == worker / "cmdline":
                (worker / "stat").write_bytes(self.stat(99901, 5500))
            return read(path)

        with patch.object(Path, "read_bytes", replace_pid), self.assertRaisesRegex(UnsafeCutover, "changed"):
            self.verify()

    def test_worker_that_disappears_after_matching_is_not_a_success(self):
        self.process(99901, 5300)
        other = self.process(99902, 5300)
        read = Path.read_bytes

        def gone(path):
            result = read(path)
            if path == other / "cmdline":
                (other / "stat").unlink()
                (other / "cmdline").unlink()
                other.rmdir()
            return result

        with patch.object(Path, "read_bytes", gone), self.assertRaises(UnsafeCutover):
            self.verify()

    def test_short_lived_unrelated_process_can_exit_during_inventory(self):
        self.process(99901, 5250)
        other = self.process(99902, 5300, command=b"unrelated\0")
        read = Path.read_bytes

        def exited(path):
            if path == other / "stat":
                (other / "stat").unlink()
                (other / "cmdline").unlink()
                other.rmdir()
            return read(path)

        with patch.object(Path, "read_bytes", exited):
            self.assertEqual([99901], self.verify())


if __name__ == "__main__":
    unittest.main()
