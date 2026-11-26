"""FPM drain through the pool's status socket (design v2, 5.4; build plan D08; experiment E8 as tests).

- StatusDocumentTests, DrainLogicTests, SnippetTests and CliTests run on any POSIX system as any user:
  the drain's decisions against scripted status readings and a fake clock.
- FpmDrainTests need Linux root, php-fpm and the www-data account (the pinned QA image): a private
  PHP-FPM master laid out as Debian's (a [www] pool in pool.d/www.conf) with production/php-fpm/
  repro-status.conf added as pool.d/zz-repro-status.conf, its socket path moved to a private folder.
  No vendor/ tree is needed:

    docker run --rm --pull never --network none --cpus 2 --memory 2g \\
      --mount type=bind,source=<checkout>,target=/app,readonly --tmpfs /tmp:rw,exec,size=512m,mode=1777 \\
      -w /app --entrypoint /bin/sh repro-mysql-rehearsal-qa:20261122 -c \\
      'python3 -B -m unittest discover -s scripts/cutover/production -p test_fpm_drain.py -v'
"""

from __future__ import annotations

import contextlib
import io
import json
import os
import shutil
import signal
import socket
import sys
import tempfile
import threading
import time
import unittest
from pathlib import Path
from unittest.mock import patch

HERE = Path(__file__).resolve().parent
sys.path[:0] = [str(HERE)]
import fastcgi  # noqa: E402
import fpm_drain  # noqa: E402
from test_fastcgi import FpmPool, ScriptedServer, can_run_fpm, cgi, end, rec, script_params  # noqa: E402

SNIPPET = HERE / "php-fpm" / "repro-status.conf"
PRODUCTION_STATUS_SOCKET = "/run/php/php8.3-fpm-status.sock"
UNIX = hasattr(socket, "AF_UNIX") and os.name == "posix"
# What PHP-FPM 8.3 printed for an idle static pool in experiment E8 and in this image.
IDLE_DOCUMENT = (b'{"pool":"www","process manager":"static","start time":1795660709,"start since":0,'
                 b'"accepted conn":0,"listen queue":0,"max listen queue":0,"listen queue len":0,'
                 b'"idle processes":1,"active processes":0,"total processes":1,"max active processes":0,'
                 b'"max children reached":0,"slow requests":0}')
JSON_HEAD = "Expires: Thu, 01 Jan 1970 00:00:00 GMT\r\nContent-Type: application/json"


def response(body: bytes = IDLE_DOCUMENT, status: int = 200, content_type: str = "application/json"):
    headers = {"content-type": content_type} if content_type else {}
    return fastcgi.Response(status, headers, body, b"", b"", 0, 0)


def status(active: int = 0, accepted: int = 5, queue: int = 0) -> fpm_drain.PoolStatus:
    return fpm_drain.PoolStatus(pool="www", accepted=accepted, active=active, idle=2 - min(active, 2),
                                total=2, listen_queue=queue)


class Scripted:
    """Status readings in order (a reading, or an exception to raise); the last one repeats. A fake clock."""

    def __init__(self, *steps, listening: bool = False, read_seconds: float = 0.001):
        self.steps = list(steps)
        self.now = 1000.0
        self.reads = 0
        self.sleeps: list[float] = []
        self.woke: list[float] = []
        self.main_listening = listening
        self.read_seconds = read_seconds

    def read(self, path, timeout):
        self.reads += 1
        step = self.steps.pop(0) if len(self.steps) > 1 else self.steps[0]
        self.now += self.read_seconds
        if isinstance(step, BaseException):
            raise step
        return step

    def listening(self, path, timeout=2.0):
        return self.main_listening

    def clock(self):
        return self.now

    def sleep(self, seconds):
        self.sleeps.append(seconds)
        self.now += seconds
        self.woke.append(self.now)

    def drain(self, limit: float = 90):
        return fpm_drain.drain(Path("/run/php/status.sock"), limit, main_socket=Path("/run/php/main.sock"),
                               read=self.read, listening=self.listening, clock=self.clock, sleep=self.sleep)


class StatusDocumentTests(unittest.TestCase):
    def test_reads_the_pool_counters(self):
        self.assertEqual(fpm_drain.parse_status(response()),
                         fpm_drain.PoolStatus(pool="www", accepted=0, active=0, idle=1, total=1, listen_queue=0))

    def test_anything_but_a_status_document_is_refused(self):
        document = json.loads(IDLE_DOCUMENT)
        cases = {
            "a 404 (another pm.status_path)": response(b"File not found.\n", 404, "text/html; charset=UTF-8"),
            "a 500 with a JSON body": response(IDLE_DOCUMENT, 500),
            "the text page": response(b"pool: www\n", 200, "text/plain;charset=UTF-8"),
            "no content type": response(IDLE_DOCUMENT, 200, ""),
            "not JSON": response(b"{", 200),
            "a list": response(b"[]", 200),
            "NaN": response(IDLE_DOCUMENT.replace(b'"listen queue":0', b'"listen queue":NaN'), 200),
            "no active count": response(json.dumps({k: v for k, v in document.items()
                                                    if k != "active processes"}).encode()),
            "a negative count": response(json.dumps({**document, "active processes": -1}).encode()),
            "a true count": response(json.dumps({**document, "listen queue": True}).encode()),
            "a float count": response(json.dumps({**document, "accepted conn": 1.0}).encode()),
            "no pool name": response(json.dumps({**document, "pool": ""}).encode()),
        }
        for label, answer in cases.items():
            with self.subTest(label), self.assertRaises(fpm_drain.StatusError):
                fpm_drain.parse_status(answer)


class DrainLogicTests(unittest.TestCase):
    def test_two_idle_readings_with_the_same_accepted_count_drain(self):
        fake = Scripted(status(active=1), status(), status())
        result = fake.drain()
        self.assertEqual((result.outcome, result.polls), ("drained", 3))
        self.assertEqual(fake.sleeps, [fpm_drain.POLL_SECONDS] * 2)
        self.assertEqual(result.status, status())
        self.assertIsNone(result.problem)

    def test_a_connection_accepted_between_idle_readings_keeps_waiting(self):
        # On a Unix socket PHP-FPM never counts the listen queue (E8 rerun: a request waiting for the
        # one busy child left "listen queue" at 0). A queued request shows only as a moved accepted count.
        fake = Scripted(status(accepted=5), status(accepted=6), status(accepted=6))
        self.assertEqual(fake.drain().polls, 3)

    def test_listen_queue_keeps_waiting(self):
        fake = Scripted(status(queue=1), status(), status())
        self.assertEqual(fake.drain().polls, 3)

    def test_an_active_reading_between_idle_ones_starts_over(self):
        fake = Scripted(status(), status(active=1, accepted=6), status(accepted=6), status(accepted=6))
        self.assertEqual(fake.drain().polls, 4)

    def test_limit_reached_reports_last_counters(self):
        fake = Scripted(status(active=1))
        result = fake.drain(limit=2)
        self.assertEqual(result.outcome, "timed_out")
        self.assertEqual(result.status, status(active=1))
        self.assertAlmostEqual(result.waited_seconds, 2.0, delta=0.1)
        self.assertGreaterEqual(result.polls, 8)
        report = result.report()
        self.assertEqual((report["outcome"], report["active_processes"], report["limit_seconds"]), ("timed_out", 1, 2))

    def test_no_wait_runs_past_the_limit(self):
        # Slow status answers leave less than a poll interval before the limit: the last wait is shortened.
        fake = Scripted(status(active=1), read_seconds=0.1)
        result = fake.drain(limit=1)
        self.assertEqual(result.outcome, "timed_out")
        self.assertLessEqual(max(fake.woke), 1000.0 + 1 + 1e-9, fake.woke)
        self.assertLess(fake.sleeps[-1], fpm_drain.POLL_SECONDS)

    def test_missing_status_socket_means_stopped(self):
        fake = Scripted(fastcgi.NotListening("nothing listens"))
        result = fake.drain()
        self.assertEqual((result.outcome, result.polls, result.status), ("stopped", 0, None))
        self.assertEqual(fake.sleeps, [])

    def test_status_socket_gone_mid_drain_means_stopped(self):
        fake = Scripted(status(active=1), status(active=1), fastcgi.NotListening("gone"))
        result = fake.drain()
        self.assertEqual((result.outcome, result.polls), ("stopped", 2))

    def test_missing_status_socket_while_the_pool_listens_refuses(self):
        # A pool without the snippet (P8 not done): "stopped" would let the stop kill in-flight requests.
        fake = Scripted(fastcgi.NotListening("nothing listens"), listening=True)
        with self.assertRaisesRegex(fpm_drain.Refused, "repro-status.conf"):
            fake.drain()

    def test_status_socket_gone_mid_drain_while_the_pool_listens_keeps_waiting(self):
        fake = Scripted(status(active=1), fastcgi.NotListening("gone"), status(), status(), listening=True)
        result = fake.drain()
        self.assertEqual((result.outcome, result.polls), ("drained", 3))

    def test_transient_errors_are_retried_until_the_limit(self):
        fake = Scripted(fastcgi.FastCgiError("the request timed out"))
        result = fake.drain(limit=1)
        self.assertEqual((result.outcome, result.status), ("timed_out", None))
        self.assertIn("timed out", result.problem)
        self.assertGreater(fake.reads, 2)

    def test_an_error_between_idle_readings_starts_over(self):
        fake = Scripted(status(), fastcgi.FastCgiError("reset"), status(), status())
        result = fake.drain()
        self.assertEqual((result.outcome, result.polls), ("drained", 3))

    def test_status_document_errors_refuse(self):
        fake = Scripted(status(active=1), fpm_drain.StatusError("the status page answered 404"))
        with self.assertRaisesRegex(fpm_drain.Refused, "404"):
            fake.drain()

    def test_limit_must_be_positive_seconds(self):
        for limit in (0, -1, True, "90", float("nan")):
            with self.subTest(limit=limit), self.assertRaises(fpm_drain.Refused):
                Scripted(status()).drain(limit=limit)

    def test_report_shape(self):
        fake = Scripted(status(active=1), status(), status())
        report = fake.drain().report()
        self.assertEqual(report, {"version": 1, "outcome": "drained", "limit_seconds": 90,
                                  "waited_seconds": round(fake.now - 1000.0, 3), "polls": 3, "pool": "www",
                                  "accepted_conn": 5, "active_processes": 0, "idle_processes": 2,
                                  "total_processes": 2, "listen_queue": 0, "problem": None})
        self.assertEqual(json.loads(json.dumps(report)), report)
        stopped = Scripted(fastcgi.NotListening("gone")).drain().report()
        self.assertEqual({key: stopped[key] for key in ("outcome", "pool", "active_processes", "polls")},
                         {"outcome": "stopped", "pool": None, "active_processes": None, "polls": 0})


class SnippetTests(unittest.TestCase):
    def test_snippet_reopens_the_www_pool_with_the_status_page_on_its_own_socket(self):
        lines = [line.strip() for line in SNIPPET.read_text(encoding="ascii").splitlines()
                 if line.strip() and not line.lstrip().startswith(";")]
        self.assertEqual(lines, ["[www]", f"pm.status_path = {fpm_drain.STATUS_PATH}",
                                 f"pm.status_listen = {PRODUCTION_STATUS_SOCKET}"])

    @unittest.skipUnless(os.name == "posix", "host config paths are POSIX paths")
    def test_snippet_socket_is_the_host_config_status_socket(self):
        from test_adapter import example_config

        fpm = example_config()["fpm"]
        self.assertEqual(fpm["status_socket"], PRODUCTION_STATUS_SOCKET)
        self.assertNotEqual(fpm["socket"], fpm["status_socket"])


def host_files(config: dict) -> dict[Path, bytes]:
    from test_adapter import example_identity

    return {Path("/etc/repro-cutover-fence/host.json"): json.dumps(config).encode(),
            Path(config["paths"]["identity_config"]): json.dumps(example_identity(config)).encode()}


@contextlib.contextmanager
def as_root_with(config: dict):
    """main() as root on Linux, reading host.json and identity.json from memory (guard.read_protected)."""
    files = host_files(config)
    with patch.object(fpm_drain.os, "geteuid", return_value=0), patch.object(fpm_drain.sys, "platform", "linux"), \
            patch.object(fpm_drain.guard, "read_protected", side_effect=lambda path: files[Path(path)]):
        yield


def run_main(argv) -> tuple[int, str, str]:
    stdout, stderr = io.StringIO(), io.StringIO()
    with contextlib.redirect_stdout(stdout), contextlib.redirect_stderr(stderr):
        code = fpm_drain.main(argv)
    return code, stdout.getvalue(), stderr.getvalue()


@unittest.skipUnless(UNIX, "FastCGI over Unix sockets")
class CliTests(unittest.TestCase):
    def setUp(self):
        from test_adapter import example_config

        self.work = Path(tempfile.mkdtemp(prefix="fpm-drain-cli-"))
        self.addCleanup(shutil.rmtree, self.work, True)
        self.config = example_config()
        self.config["fpm"].update(socket=str(self.work / "main.sock"), status_socket=str(self.work / "status.sock"))
        self.argv = ["--config", "/etc/repro-cutover-fence/host.json"]

    def test_cli_refuses_without_root(self):
        with patch.object(fpm_drain.os, "geteuid", return_value=1000):
            code, stdout, stderr = run_main(self.argv)
        self.assertEqual((code, stdout), (1, ""))
        self.assertIn("root", stderr)

    def test_cli_prints_the_report_and_exits_0_when_stopped(self):
        with as_root_with(self.config):
            code, stdout, _ = run_main(self.argv)
        self.assertEqual(code, 0)
        report = json.loads(stdout)
        self.assertEqual((report["outcome"], report["limit_seconds"]), ("stopped", 90))

    def test_cli_exits_1_when_the_pool_listens_without_its_status_socket(self):
        main = ScriptedServer(self.work, [])
        self.addCleanup(main.close)
        self.config["fpm"]["socket"] = str(main.path)
        with as_root_with(self.config):
            code, stdout, stderr = run_main(self.argv)
        self.assertEqual((code, stdout), (1, ""))
        self.assertIn("repro-status.conf", stderr)

    def test_cli_refuses_a_limit_the_adapter_would_cut_short(self):
        # adapter._run kills a host command after command_seconds; the stop after the drain must still run.
        self.config["fpm"]["drain_seconds"] = self.config["command_seconds"] - 1
        with as_root_with(self.config):
            code, stdout, stderr = run_main(self.argv)
        self.assertEqual((code, stdout), (1, ""))
        self.assertIn("command_seconds", stderr)

    def test_cli_refuses_the_pool_socket_as_the_status_socket(self):
        # On the pool's own socket every status request is an active process: the pool never reads idle.
        self.config["fpm"]["status_socket"] = self.config["fpm"]["socket"]
        with as_root_with(self.config):
            code, stdout, stderr = run_main(self.argv)
        self.assertEqual((code, stdout), (1, ""))
        self.assertIn("status_socket", stderr)

    def test_cli_refuses_a_config_load_config_refuses(self):
        self.config["fpm"]["drain_seconds"] = 0
        with as_root_with(self.config):
            code, stdout, stderr = run_main(self.argv)
        self.assertEqual((code, stdout), (1, ""))
        self.assertIn("drain_seconds", stderr)


def slow_script(pool: FpmPool) -> Path:
    return pool.script("slow.php", "<?php usleep((int) ($_GET['ms'] ?? 1000) * 1000); echo 'slow-done';\n")


class SlowRequest(threading.Thread):
    """A request to the pool's main socket, as nginx would have passed it before the hold."""

    def __init__(self, pool: FpmPool, milliseconds: int):
        super().__init__(daemon=True)
        self.pool, self.milliseconds = pool, milliseconds
        self.body: bytes | None = None
        self.error: BaseException | None = None
        self.finished_at: float | None = None

    def run(self):
        try:
            answer = fastcgi.request(self.pool.socket, script_params(slow_script(self.pool), f"ms={self.milliseconds}"),
                                     timeout=60)
            self.body = answer.body
        except BaseException as error:  # Reported by the test.
            self.error = error
        self.finished_at = time.monotonic()


def snippet_for(pool: FpmPool) -> str:
    text = SNIPPET.read_text(encoding="ascii")
    if text.count(PRODUCTION_STATUS_SOCKET) != 1:
        raise AssertionError("The snippet changed its status socket")
    return text.replace(PRODUCTION_STATUS_SOCKET, str(pool.status_socket))


@unittest.skipIf(can_run_fpm() is not None, can_run_fpm() or "")
class FpmDrainTests(unittest.TestCase):
    def setUp(self):
        self.work = Path(tempfile.mkdtemp(prefix="fpm-drain-"))
        self.work.chmod(0o755)
        self.addCleanup(shutil.rmtree, self.work, True)

    def pool(self, *, children: int = 1, snippet: bool = True, graceful_stop: bool = True) -> FpmPool:
        pool = FpmPool(self.work / f"fpm{len(list(self.work.iterdir()))}", children=children)
        if snippet:
            (pool.work / "pool.d" / "zz-repro-status.conf").write_text(snippet_for(pool))
        self.addCleanup(pool.stop, graceful_stop)
        slow_script(pool)
        return pool.start(pool.socket, *([pool.status_socket] if snippet else []))

    def drain(self, pool: FpmPool, limit: float) -> fpm_drain.Drain:
        return fpm_drain.drain(pool.status_socket, limit, main_socket=pool.socket)

    def wait_active(self, pool: FpmPool, active: int = 1) -> None:
        for _ in range(200):
            if fpm_drain.read_status(pool.status_socket, 2).active >= active:
                return
            time.sleep(0.025)
        raise AssertionError("the slow request never became active")

    def test_snippet_merges_into_the_www_pool(self):
        pool = self.pool(children=2)
        tested = pool.config_test()
        self.assertEqual(tested.returncode, 0, tested.stdout + tested.stderr)
        readings = [fpm_drain.read_status(pool.status_socket, 2) for _ in range(3)]
        self.assertEqual({reading.pool for reading in readings}, {"www"})
        # E8: a status request on pm.status_listen occupies no pool child and is not an accepted connection.
        self.assertEqual({(reading.active, reading.accepted, reading.total) for reading in readings}, {(0, 0, 2)})
        # The status socket takes the pool's listen.owner, listen.group and listen.mode (www-data 0660).
        info = pool.status_socket.stat()
        self.assertEqual((oct(info.st_mode & 0o777), info.st_uid), ("0o660", pool_uid()))

    def test_snippet_without_the_www_pool_fails_the_config_test(self):
        pool = FpmPool(self.work / "other", children=1)
        www = pool.work / "pool.d" / "www.conf"
        www.write_text(www.read_text().replace("[www]", "[app]"))
        (pool.work / "pool.d" / "zz-repro-status.conf").write_text(snippet_for(pool))
        tested = pool.config_test()
        self.assertNotEqual(tested.returncode, 0, tested.stdout + tested.stderr)

    def test_reload_adds_the_status_socket_to_a_running_pool(self):
        # W1: install the snippet, `php-fpm8.3 -t`, then one reload (USR2): the same master, now drainable.
        pool = self.pool(snippet=False)
        master = pool.process.pid
        (pool.work / "pool.d" / "zz-repro-status.conf").write_text(snippet_for(pool))
        self.assertEqual(pool.config_test().returncode, 0)
        pool.process.send_signal(signal.SIGUSR2)
        for _ in range(200):
            if pool.status_socket.exists():
                break
            time.sleep(0.025)
        time.sleep(0.2)
        self.assertEqual((pool.process.poll(), pool.process.pid), (None, master))
        self.assertEqual(self.drain(pool, 30).outcome, "drained")

    def test_drain_waits_for_in_flight_request(self):
        pool = self.pool()
        slow = SlowRequest(pool, 3000)
        slow.start()
        self.wait_active(pool)
        result = self.drain(pool, 30)
        returned = time.monotonic()
        self.assertEqual(result.outcome, "drained", result.report())
        self.assertEqual((slow.body, slow.error), (b"slow-done", None))
        self.assertLessEqual(slow.finished_at, returned)
        self.assertGreater(result.waited_seconds, 1.5)
        self.assertLess(result.waited_seconds, 10)
        self.assertEqual((result.status.active, result.status.listen_queue), (0, 0))

    def test_drain_waits_for_a_queued_request(self):
        pool = self.pool(children=1)
        first, queued = SlowRequest(pool, 2000), SlowRequest(pool, 2000)
        first.start()
        self.wait_active(pool)
        queued.start()
        time.sleep(0.3)  # The second request waits in the socket's backlog for the one child.
        result = self.drain(pool, 30)
        returned = time.monotonic()
        self.assertEqual(result.outcome, "drained", result.report())
        for request in (first, queued):
            self.assertEqual((request.body, request.error), (b"slow-done", None))
            self.assertLessEqual(request.finished_at, returned)
        self.assertGreater(result.waited_seconds, 2.5)

    def test_drain_stops_waiting_at_limit_and_reports(self):
        pool = self.pool(graceful_stop=False)
        slow = SlowRequest(pool, 8000)
        slow.start()
        self.wait_active(pool)
        result = self.drain(pool, 2)
        self.assertEqual(result.outcome, "timed_out", result.report())
        self.assertGreaterEqual(result.waited_seconds, 2.0)
        self.assertLess(result.waited_seconds, 4.0)
        self.assertEqual(result.status.active, 1)
        self.assertTrue(slow.is_alive(), "the drain waited for the request it should have given up on")
        report = result.report()
        self.assertEqual((report["outcome"], report["active_processes"]), ("timed_out", 1))

    def test_missing_status_socket_means_stopped(self):
        result = fpm_drain.drain(self.work / "status.sock", 30, main_socket=self.work / "main.sock")
        self.assertEqual((result.outcome, result.polls), ("stopped", 0))
        self.assertLess(result.waited_seconds, 1)
        # A pool stopped as systemd stops it (SIGQUIT, then the master removes its sockets).
        pool = self.pool()
        self.assertEqual(self.drain(pool, 30).outcome, "drained")
        pool.stop()
        self.assertFalse(pool.status_socket.exists())
        self.assertEqual(self.drain(pool, 30).outcome, "stopped")

    def test_pool_without_the_status_socket_refuses(self):
        pool = self.pool(snippet=False)
        self.assertFalse(pool.status_socket.exists())
        with self.assertRaisesRegex(fpm_drain.Refused, "repro-status.conf"):
            self.drain(pool, 30)

    def test_cli_drains_and_prints_the_report(self):
        from test_adapter import example_config

        pool = self.pool()
        config = example_config()
        config["fpm"].update(socket=str(pool.socket), status_socket=str(pool.status_socket))
        with as_root_with(config):
            code, stdout, stderr = run_main(["--config", "/etc/repro-cutover-fence/host.json"])
        self.assertEqual(code, 0, stderr)
        report = json.loads(stdout)
        self.assertEqual((report["outcome"], report["pool"], report["active_processes"]), ("drained", "www", 0))

    def test_cli_timed_out_still_exits_0_so_the_stop_runs(self):
        from test_adapter import example_config

        pool = self.pool(graceful_stop=False)
        slow = SlowRequest(pool, 6000)
        slow.start()
        self.wait_active(pool)
        config = example_config()
        config["fpm"].update(socket=str(pool.socket), status_socket=str(pool.status_socket), drain_seconds=1)
        with as_root_with(config):
            code, stdout, stderr = run_main(["--config", "/etc/repro-cutover-fence/host.json"])
        self.assertEqual(code, 0, stderr)
        report = json.loads(stdout)
        self.assertEqual((report["outcome"], report["active_processes"], report["limit_seconds"]), ("timed_out", 1, 1))
        self.assertIn("1 request(s) still active after 1 s", stderr)


def pool_uid() -> int:
    import pwd

    return pwd.getpwnam("www-data").pw_uid


if __name__ == "__main__":
    unittest.main()
