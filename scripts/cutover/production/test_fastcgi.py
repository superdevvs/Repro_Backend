"""The controller's FastCGI client (design v2, 5.3 and 5.4; build plan D08).

- EncodingTests and ProtocolTests run on any POSIX system as any user: a scripted FastCGI server on a
  private Unix socket reads what the client sends with its own decoder and plays records back to it,
  broken ones included.
- FpmClientTests need Linux root, php-fpm and the www-data account (the pinned QA image): a private
  PHP-FPM master whose pool runs as www-data, as on the host.
- RouteHashVectorTests also need php, runuser, the account `nobody` (the deploy user's stand-in) and a
  vendor/ tree readable by www-data. fpm-engine.php, sent into the pool through this client, prints the
  route values of every shared vector (testdata/route-hash-vectors.json, the one copy) from the live
  bootstrap/cache/config.php of a private app root, and live_config hashes them to the vector's pin.
  Every absolute path in a vector is moved under a private folder first (review B7), so the probe never
  reaches a production path. For example:

    docker run --rm --pull never --network none --cpus 2 --memory 2g \\
      --mount type=bind,source=<checkout>,target=/app,readonly \\
      --mount type=volume,source=<vendor volume>,target=/vendor-src,readonly,volume-subpath=vendor \\
      --tmpfs /app/vendor:rw,exec,size=600m,mode=0755 --tmpfs /tmp:rw,exec,size=512m,mode=1777 \\
      -w /app --entrypoint /bin/sh repro-mysql-rehearsal-qa:20261122 -c \\
      'cp -a /vendor-src/. /app/vendor/ && chmod -R a+rX /app/vendor &&
       python3 -B -m unittest discover -s scripts/cutover/production -p test_fastcgi.py -v'

  (a read-only checkout needs an empty vendor/ folder as the mount point).
"""

from __future__ import annotations

import contextlib
import hashlib
import io
import json
import os
import pwd
import re
import shutil
import signal
import socket
import subprocess
import sys
import tempfile
import threading
import time
import unittest
import urllib.parse
from contextlib import redirect_stdout
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path[:0] = [str(HERE)]
import fastcgi  # noqa: E402

APP = HERE.parents[2]
PHP_FPM = next((path for path in ("/usr/local/sbin/php-fpm", "/usr/sbin/php-fpm8.3") if os.path.exists(path)), None)
VECTORS = HERE / "testdata" / "route-hash-vectors.json"
UNIX = hasattr(socket, "AF_UNIX") and os.name == "posix"


def can_run_fpm() -> str | None:
    if sys.platform != "linux" or os.geteuid() != 0:
        return "needs Linux root to start a php-fpm master"
    if PHP_FPM is None:
        return "needs php-fpm"
    try:
        pwd.getpwnam("www-data")
    except KeyError:
        return "needs a www-data account"
    return None


# ------------------------------------------------------------------ an independent FastCGI encoder and decoder

def rec(kind: int, content: bytes = b"", *, request_id: int = 1, version: int = 1, padding: int = 0) -> bytes:
    """One record, built by hand from the FastCGI 1.0 layout (not with fastcgi.py's own code)."""
    return (bytes([version, kind]) + request_id.to_bytes(2, "big") + len(content).to_bytes(2, "big")
            + bytes([padding, 0]) + content + b"\xa5" * padding)


def end(app_status: int = 0, protocol_status: int = 0) -> bytes:
    return rec(3, app_status.to_bytes(4, "big") + bytes([protocol_status, 0, 0, 0]))


def decode_pairs(data: bytes) -> list[tuple[bytes, bytes]]:
    pairs, index = [], 0

    def length() -> int:
        nonlocal index
        if data[index] < 0x80:
            index += 1
            return data[index - 1]
        index += 4
        return int.from_bytes(data[index - 4:index], "big") & 0x7FFFFFFF

    while index < len(data):
        name_length, value_length = length(), length()
        name = data[index:index + name_length]
        value = data[index + name_length:index + name_length + value_length]
        index += name_length + value_length
        pairs.append((name, value))
    return pairs


def read_records(connection: socket.socket) -> list[tuple[int, int, int, bytes]]:
    """(version, type, request id, content) of every record up to and including the empty STDIN record."""
    buffer, records = b"", []
    while True:
        while len(buffer) >= 8:
            length, padding = int.from_bytes(buffer[4:6], "big"), buffer[6]
            if len(buffer) < 8 + length + padding:
                break
            records.append((buffer[0], buffer[1], int.from_bytes(buffer[2:4], "big"), buffer[8:8 + length]))
            buffer = buffer[8 + length + padding:]
            if records[-1][1] == 5 and records[-1][3] == b"":
                return records
        chunk = connection.recv(65536)
        if not chunk:
            return records
        buffer += chunk


class ScriptedServer:
    """One FastCGI connection on a private Unix socket: read the whole request, then play the script.

    A script step is bytes to send, a number of seconds to wait, or "close".
    """

    def __init__(self, directory: Path, script: list):
        self.path = Path(directory) / "fcgi.sock"
        self.script = script
        self.records: list = []
        self.stopped = threading.Event()
        self.listener = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        self.listener.bind(str(self.path))
        self.listener.listen(1)
        # close() must not wait for a client that never comes: accept wakes up every 0.1 s.
        self.listener.settimeout(0.1)
        self.thread = threading.Thread(target=self.serve, daemon=True)
        self.thread.start()

    def serve(self):
        while not self.stopped.is_set():
            try:
                connection, _ = self.listener.accept()
                break
            except socket.timeout:
                continue
            except OSError:
                return
        else:
            return
        with connection:
            connection.settimeout(10)
            try:
                self.records = read_records(connection)
            except OSError:
                return
            for step in self.script:
                if self.stopped.is_set():
                    return
                if step == "close":
                    return
                if isinstance(step, (int, float)):
                    time.sleep(step)
                    continue
                try:
                    connection.sendall(step)
                except OSError:
                    return

    def close(self):
        self.stopped.set()
        self.thread.join(15)
        self.listener.close()


def cgi(body: bytes, headers: str = "Content-type: text/html; charset=UTF-8") -> bytes:
    return headers.encode("latin-1") + b"\r\n\r\n" + body


def full_backlog(path: Path) -> tuple[socket.socket, list[socket.socket]]:
    """A Unix socket that listens with listen(0) and never accepts, its backlog filled by idle clients."""
    listener = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    listener.bind(str(path))
    listener.listen(0)
    clients = []
    for _ in range(64):
        client = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        client.setblocking(False)
        try:
            client.connect(str(path))
        except BlockingIOError:
            client.close()
            return listener, clients
        clients.append(client)
    for client in clients:
        client.close()
    listener.close()
    raise AssertionError("the backlog of a listen(0) socket never filled")


@unittest.skipUnless(os.name == "posix", "FastCGI over Unix sockets")
class EncodingTests(unittest.TestCase):
    def test_name_value_lengths_use_one_or_four_bytes(self):
        encoded = fastcgi.encode_params({"A": "x" * 127, "B" * 128: "", "C": "é"})
        expected = (bytes([1, 127]) + b"A" + b"x" * 127
                    + bytes([0x80, 0, 0, 128, 0]) + b"B" * 128
                    + bytes([1, 2]) + b"C" + "é".encode("utf-8"))
        self.assertEqual(encoded, expected)
        self.assertEqual(decode_pairs(encoded), [(b"A", b"x" * 127), (b"B" * 128, b""), (b"C", "é".encode())])

    def test_names_and_values_must_be_text_without_nul(self):
        for params in ({"A": None}, {1: "x"}, {"A\0B": "x"}, {"A": "x\0y"}, {"": "x"}):
            with self.subTest(params=params), self.assertRaises(fastcgi.FastCgiError):
                fastcgi.encode_params(params)


@unittest.skipUnless(UNIX, "FastCGI over Unix sockets")
class ProtocolTests(unittest.TestCase):
    def setUp(self):
        self.work = Path(tempfile.mkdtemp(prefix="fastcgi-test-"))
        self.addCleanup(shutil.rmtree, self.work, True)

    def serve(self, *script, label: str = "default") -> ScriptedServer:
        folder = self.work / re.sub(r"\W", "-", label)
        folder.mkdir()
        server = ScriptedServer(folder, list(script))
        self.addCleanup(server.close)
        return server

    def test_request_is_begin_params_and_stdin_streams(self):
        server = self.serve(rec(6, cgi(b"ok")), end())
        params = {"SCRIPT_FILENAME": "/srv/probe.php", "LONG": "v" * 70000, "REQUEST_METHOD": "POST"}
        body = bytes(range(256)) * 300
        fastcgi.request(server.path, params, body, timeout=10)
        server.thread.join(10)
        records = server.records
        self.assertEqual({(version, request_id) for version, _, request_id, _ in records}, {(1, 1)})
        kinds = [kind for _, kind, _, _ in records]
        # BEGIN_REQUEST as a responder without keep-alive, then PARAMS and STDIN, each closed by an empty record.
        self.assertEqual(kinds[0], 1)
        self.assertEqual(records[0][3], bytes([0, 1, 0, 0, 0, 0, 0, 0]))
        params_records = [content for _, kind, _, content in records if kind == 4]
        stdin_records = [content for _, kind, _, content in records if kind == 5]
        self.assertEqual(kinds, [1] + [4] * len(params_records) + [5] * len(stdin_records))
        self.assertEqual((params_records[-1], stdin_records[-1]), (b"", b""))
        self.assertTrue(all(0 < len(content) <= 65535 for content in params_records[:-1] + stdin_records[:-1]))
        self.assertGreater(len(params_records), 2)
        self.assertEqual(decode_pairs(b"".join(params_records)),
                         [(name.encode(), value.encode()) for name, value in params.items()])
        self.assertEqual(b"".join(stdin_records), body)

    def test_split_padded_and_interleaved_records_are_reassembled(self):
        head = b"Status: 404 Not Found\r\nX-A: 1\r\nX-A: 2\r\nContent-Type: application/json\r\n\r\n"
        server = self.serve(rec(6, head[:10], padding=3), rec(7, b"PHP message: one", padding=7),
                            rec(6, head[10:] + b'{"a"', padding=1), 0.05, rec(6, b""),
                            rec(6, b":1}"), rec(7, b"; two"), end(app_status=7))
        response = fastcgi.request(server.path, {"SCRIPT_FILENAME": "/x.php"}, timeout=10)
        self.assertEqual(response.stdout, head + b'{"a":1}')
        self.assertEqual(response.stderr, b"PHP message: one; two")
        self.assertEqual((response.app_status, response.protocol_status), (7, 0))
        self.assertEqual(response.status, 404)
        self.assertEqual(response.headers, {"status": "404 Not Found", "x-a": "1, 2", "content-type": "application/json"})
        self.assertEqual(response.body, b'{"a":1}')

    def test_missing_status_header_means_200(self):
        server = self.serve(rec(6, cgi(b"fine")), end())
        response = fastcgi.request(server.path, {"SCRIPT_FILENAME": "/x.php"}, timeout=10)
        self.assertEqual((response.status, response.body), (200, b"fine"))

    def test_stream_closed_before_the_end_record_is_refused(self):
        whole = rec(6, cgi(b"partial"))
        cases = {"no end record": [whole, "close"], "half a header": [whole[:5], "close"],
                 "half a record": [rec(6, b"0123456789")[:12], "close"], "nothing at all": ["close"]}
        for label, script in cases.items():
            with self.subTest(label):
                server = self.serve(*script, label=label)
                with self.assertRaisesRegex(fastcgi.FastCgiError, "before the end of the request"):
                    fastcgi.request(server.path, {"SCRIPT_FILENAME": "/x.php"}, timeout=10)

    def test_unexpected_records_are_refused(self):
        body = rec(6, cgi(b"x"))
        cases = {
            "version 2": [rec(6, cgi(b"x"), version=2), end()],
            "another request id": [rec(6, cgi(b"x"), request_id=2), end()],
            "a management record": [rec(10, b"", request_id=0), body, end()],
            "an unknown type": [rec(11, b"\x09" + b"\0" * 7), body, end()],
            "a short end record": [body, rec(3, b"\0\0\0\0")],
            "an overloaded server": [body, end(protocol_status=2)],
            "a role it cannot play": [body, end(protocol_status=3)],
            "data after the end record": [body, end() + rec(6, b"more")],
        }
        for label, script in cases.items():
            with self.subTest(label):
                server = self.serve(*script, label=label)
                with self.assertRaises(fastcgi.FastCgiError):
                    fastcgi.request(server.path, {"SCRIPT_FILENAME": "/x.php"}, timeout=10)

    def test_cgi_header_block_is_required(self):
        cases = {"no blank line": b"Content-Type: text/plain\r\nbody",
                 "a line without a colon": b"Content-Type text/plain\r\n\r\nbody",
                 "a status that is not a number": b"Status: abc\r\n\r\nbody",
                 "a status out of range": b"Status: 99\r\n\r\nbody"}
        for label, stdout in cases.items():
            with self.subTest(label):
                server = self.serve(rec(6, stdout), end(), label=label)
                with self.assertRaisesRegex(fastcgi.FastCgiError, "CGI header"):
                    fastcgi.request(server.path, {"SCRIPT_FILENAME": "/x.php"}, timeout=10)

    def test_output_over_the_limit_is_refused(self):
        chunk = b"x" * 65535
        server = self.serve(rec(6, b"Content-Type: text/plain\r\n\r\n"), *[rec(6, chunk)] * 20, end())
        with self.assertRaisesRegex(fastcgi.FastCgiError, "more than 1048576 bytes"):
            fastcgi.request(server.path, {"SCRIPT_FILENAME": "/x.php"}, timeout=10, max_output=1024 * 1024)

    def test_deadline_bounds_the_whole_exchange(self):
        # A valid record, one byte every 0.1 s, never trips a per-read timeout; the deadline covers everything.
        record = rec(6, b"x" * 1000)
        server = self.serve(*[step for index in range(100) for step in (record[index:index + 1], 0.1)])
        started = time.monotonic()
        with self.assertRaisesRegex(fastcgi.FastCgiError, "timed out"):
            fastcgi.request(server.path, {"SCRIPT_FILENAME": "/x.php"}, timeout=0.8)
        self.assertLess(time.monotonic() - started, 3.0)

    def test_nothing_listening_is_reported_as_such(self):
        missing = self.work / "missing.sock"
        stale = self.work / "stale.sock"
        bound = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        bound.bind(str(stale))
        bound.close()  # The file stays; nothing listens on it (a master killed without cleanup).
        for path in (missing, stale):
            with self.subTest(path=path.name):
                self.assertFalse(fastcgi.listening(path))
                with self.assertRaises(fastcgi.NotListening):
                    fastcgi.request(path, {"SCRIPT_FILENAME": "/x.php"}, timeout=2)
        server = self.serve()
        self.assertTrue(fastcgi.listening(server.path))

    @unittest.skipUnless(sys.platform == "linux", "Linux refuses a connect to a full Unix backlog with EAGAIN")
    def test_a_full_backlog_counts_as_listening(self):
        # A pool whose backlog is full listens: connect(2) fails with EAGAIN, not ECONNREFUSED (review A2-12).
        listener, clients = full_backlog(self.work / "busy.sock")
        self.addCleanup(listener.close)
        for client in clients:
            self.addCleanup(client.close)
        self.assertTrue(fastcgi.listening(listener.getsockname()))
        with self.assertRaises(fastcgi.FastCgiError) as caught:
            fastcgi.request(listener.getsockname(), {"SCRIPT_FILENAME": "/x.php"}, timeout=1)
        self.assertNotIsInstance(caught.exception, fastcgi.NotListening)

    def test_engine_probe_sends_the_fixed_request_and_reads_the_report(self):
        report = {"version": 1, "probe": "fpm-engine", "ok": True, "engine": "sqlite", "problems": []}
        server = self.serve(rec(6, cgi(json.dumps(report).encode(), "Content-Type: application/json")), end())
        self.assertEqual(fastcgi.engine_probe(server.path, "/var/www/backend", timeout=10), report)
        server.thread.join(10)
        params = dict(decode_pairs(b"".join(content for _, kind, _, content in server.records if kind == 4)))
        # Only the probe script and the request line: nothing the client sends can stand in for FPM's own
        # environment (Laravel reads $_SERVER), so the probe reports the pool's effective config.
        self.assertEqual(params, {
            b"SCRIPT_FILENAME": b"/var/www/backend/scripts/cutover/probe/fpm-engine.php",
            b"SCRIPT_NAME": b"/fpm-engine.php", b"REQUEST_URI": b"/fpm-engine.php", b"REQUEST_METHOD": b"GET",
            b"SERVER_PROTOCOL": b"HTTP/1.1", b"GATEWAY_INTERFACE": b"CGI/1.1", b"QUERY_STRING": b""})

    def test_engine_probe_refuses_anything_but_its_report(self):
        good = {"version": 1, "probe": "fpm-engine", "ok": False, "engine": "mysql", "problems": ["database: x"]}
        json_head = "Status: 500 Internal Server Error\r\nContent-Type: application/json"
        cases = {
            "an HTML page": cgi(b"<html>File not found.</html>", "Status: 404 Not Found\r\nContent-type: text/html"),
            "another probe": cgi(json.dumps({**good, "probe": "other"}).encode(), json_head),
            "a list": cgi(b"[1]", json_head),
            "not JSON": cgi(b"{", json_head),
            "ok with status 500": cgi(json.dumps({**good, "ok": True}).encode(), json_head),
            "not ok with status 200": cgi(json.dumps(good).encode(), "Content-Type: application/json"),
            "a status of 502": cgi(json.dumps(good).encode(), "Status: 502\r\nContent-Type: application/json"),
            # The report's own body, but not served as JSON (review A2-12): not the probe's answer.
            "an ok report as text/html": cgi(json.dumps({**good, "ok": True}).encode(),
                                             "Content-Type: text/html; charset=UTF-8"),
            "an ok report without a content type": cgi(json.dumps({**good, "ok": True}).encode(),
                                                       "X-Powered-By: PHP/8.3"),
        }
        for label, stdout in cases.items():
            with self.subTest(label):
                server = self.serve(rec(6, stdout), end(), label=label)
                with self.assertRaises(fastcgi.ProbeFailed):
                    fastcgi.engine_probe(server.path, "/var/www/backend", timeout=10)
        server = self.serve(rec(6, cgi(json.dumps(good).encode(), json_head)), end())
        self.assertEqual(fastcgi.engine_probe(server.path, "/var/www/backend", timeout=10), good)

    def test_cli_exit_codes(self):
        report = {"version": 1, "probe": "fpm-engine", "ok": True, "engine": "sqlite", "problems": [],
                  "route_values": {"database.default": "sqlite"}}
        not_ok = {**report, "ok": False, "problems": ["database: gone"]}
        cases = (("ok", cgi(json.dumps(report).encode(), "Content-Type: application/json"), 0),
                 ("not ok", cgi(json.dumps(not_ok).encode(), "Status: 500\r\nContent-Type: application/json"), 2),
                 ("no report", cgi(b"File not found.", "Status: 404 Not Found"), 1))
        for label, stdout, expected in cases:
            with self.subTest(label):
                server = self.serve(rec(6, stdout), end(), label=label)
                output = io.StringIO()
                with redirect_stdout(output), contextlib.redirect_stderr(io.StringIO()):
                    code = fastcgi.main(["--socket", str(server.path), "--app-root", "/var/www/backend"])
                self.assertEqual(code, expected)
                if expected != 1:
                    printed = json.loads(output.getvalue())
                    self.assertEqual(printed["report"]["ok"], expected == 0)
                    self.assertEqual(printed["route_sha256"], hashlib.sha256(b'{"database.default":"sqlite"}').hexdigest())
                else:
                    self.assertEqual(output.getvalue(), "")

    def test_engine_probe_needs_an_absolute_app_root(self):
        for root in ("var/www/backend", "", "/var/www/backend/../etc"):
            with self.subTest(root=root), self.assertRaises(fastcgi.ProbeFailed):
                fastcgi.engine_probe(self.work / "unused.sock", root, timeout=1)


# ------------------------------------------------------------------ a private PHP-FPM master, laid out as on the host

class FpmPool:
    """A php-fpm master with Debian's layout: a main file that includes pool.d/*.conf and a [www] pool.

    `snippet` is the text of a pool.d file added after www.conf (production/php-fpm/repro-status.conf with
    its socket path moved here). The pool's sockets belong to www-data with mode 0660, as Debian's
    www.conf sets them; root, the controller, connects anyway.
    """

    def __init__(self, work: Path, *, children: int = 1, www_extra: str = "", snippet: str | None = None):
        self.work = work
        self.work.mkdir(parents=True, exist_ok=True)
        self.work.chmod(0o755)
        self.socket = work / "php8.3-fpm.sock"
        self.status_socket = work / "php8.3-fpm-status.sock"
        self.conf = work / "php-fpm.conf"
        pool_d = work / "pool.d"
        pool_d.mkdir(exist_ok=True)
        self.conf.write_text(f"[global]\nerror_log = {work}/fpm.log\ndaemonize = no\npid = {work}/fpm.pid\n"
                             f"include = {pool_d}/*.conf\n")
        (pool_d / "www.conf").write_text(
            f"[www]\nuser = www-data\ngroup = www-data\nlisten = {self.socket}\nlisten.owner = www-data\n"
            f"listen.group = www-data\nlisten.mode = 0660\npm = static\npm.max_children = {children}\n{www_extra}")
        if snippet is not None:
            (pool_d / "zz-repro-status.conf").write_text(snippet)
        self.process: subprocess.Popen | None = None

    def config_test(self) -> subprocess.CompletedProcess:
        return subprocess.run([PHP_FPM, "-t", "-y", str(self.conf)], capture_output=True, text=True, timeout=60)

    def start(self, *wait_for: Path) -> "FpmPool":
        self.process = subprocess.Popen([PHP_FPM, "-y", str(self.conf), "-F"], stdout=subprocess.DEVNULL,
                                        stderr=subprocess.DEVNULL)
        sockets = wait_for or (self.socket,)
        for _ in range(400):
            if self.process.poll() is not None:
                raise RuntimeError(f"php-fpm exited {self.process.returncode}: {self.log()}")
            if all(path.exists() for path in sockets):
                break
            time.sleep(0.025)
        else:
            raise RuntimeError(f"php-fpm never listened: {self.log()}")
        time.sleep(0.2)
        return self

    def stop(self, graceful: bool = True) -> None:
        if self.process is None or self.process.poll() is not None:
            return
        self.process.send_signal(signal.SIGQUIT if graceful else signal.SIGTERM)
        try:
            self.process.wait(15)
        except subprocess.TimeoutExpired:
            self.process.kill()
            self.process.wait(5)

    def log(self) -> str:
        path = self.work / "fpm.log"
        return path.read_text(errors="replace")[-2000:] if path.exists() else ""

    def script(self, name: str, text: str) -> Path:
        path = self.work / name
        path.write_text(text)
        path.chmod(0o644)
        return path


def script_params(path: Path, query: str = "") -> dict[str, str]:
    return {"SCRIPT_FILENAME": str(path), "SCRIPT_NAME": "/" + path.name, "REQUEST_URI": "/" + path.name,
            "QUERY_STRING": query, "REQUEST_METHOD": "GET", "SERVER_PROTOCOL": "HTTP/1.1"}


@unittest.skipIf(can_run_fpm() is not None, can_run_fpm() or "")
class FpmClientTests(unittest.TestCase):
    def setUp(self):
        self.work = Path(tempfile.mkdtemp(prefix="fastcgi-fpm-"))
        self.work.chmod(0o755)  # The pool's www-data children read the scripts below it.
        self.addCleanup(shutil.rmtree, self.work, True)
        # fastcgi.logging sends error_log() to the web server as FCGI_STDERR (PHP 8.3's default is off).
        self.pool = FpmPool(self.work / "fpm", www_extra="php_admin_flag[fastcgi.logging] = on\n")
        self.addCleanup(self.pool.stop)
        self.pool.start()

    def test_client_reads_stdout_stderr_and_end_records(self):
        script = self.pool.script("records.php", "<?php\n"
                                  "error_log('first stderr line');\n"
                                  "header('X-A: 1'); header('X-A: 2', false); http_response_code(418);\n"
                                  "echo str_repeat('0123456789', 20000);\n"
                                  "error_log(str_repeat('e', 500));\n"
                                  "echo 'end';\n")
        response = fastcgi.request(self.pool.socket, script_params(script), timeout=30)
        self.assertEqual(response.status, 418)
        self.assertEqual(response.headers["x-a"], "1, 2")
        # 200 kB of STDOUT arrive in several records, with the STDERR records between them.
        self.assertEqual(response.body, b"0123456789" * 20000 + b"end")
        self.assertTrue(response.stderr.startswith(b"PHP message: first stderr line"), response.stderr[:200])
        self.assertIn(b"PHP message: " + b"e" * 500, response.stderr)
        # PHP-FPM always reports application status 0 and a completed request.
        self.assertEqual((response.app_status, response.protocol_status), (0, 0))

    def test_missing_script_is_fpm_404(self):
        response = fastcgi.request(self.pool.socket, script_params(self.work / "missing.php"), timeout=30)
        self.assertEqual((response.status, response.body.strip()), (404, b"File not found."))

    def test_post_body_reaches_the_script(self):
        script = self.pool.script("echo.php", "<?php echo strlen(file_get_contents('php://input')), ':',"
                                  " md5(file_get_contents('php://input'));")
        body = os.urandom(150000)
        params = {**script_params(script), "REQUEST_METHOD": "POST", "CONTENT_LENGTH": str(len(body)),
                  "CONTENT_TYPE": "application/octet-stream"}
        response = fastcgi.request(self.pool.socket, params, body, timeout=30)
        self.assertEqual(response.body, f"{len(body)}:{hashlib.md5(body).hexdigest()}".encode())


# ------------------------------------------------------------------ the shared route-hash vectors through FPM

def can_run_vectors() -> str | None:
    if can_run_fpm() is not None:
        return can_run_fpm()
    import test_config_build

    if test_config_build.can_build_real() is not None:
        return test_config_build.can_build_real()
    vendor = APP / "vendor"
    if not (os.stat(vendor).st_mode & 0o005 == 0o005 and os.stat(vendor / "autoload.php").st_mode & 0o004):
        return "needs a vendor/ tree that www-data can read (copy the root-only volume into a tmpfs)"
    return None


# The route subtrees fpm-engine.php reads, laid over the app's own cached config, as
# tests/Feature/CutoverSmokeTest.php::probeConfig does on the PHP side.
OVERLAY_PHP = r"""
$config = require $argv[1];
$vector = json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
foreach (['database.default', 'database.connections', 'cache.default', 'cache.stores', 'queue.default',
          'queue.connections'] as $key) {
    [$top, $name] = explode('.', $key);
    $config[$top][$name] = $vector[$top][$name] ?? null;
}
$config['app']['key'] = $vector['app']['key'] ?? null;
$config['database']['repro_leftover_transactions'] = $vector['database']['repro_leftover_transactions'] ?? null;
$temporary = $argv[3].'.tmp';
file_put_contents($temporary, '<?php return '.var_export($config, true).';'.PHP_EOL);
rename($temporary, $argv[3]);
"""
ENCODED_PATH = re.compile(r"(?i)([?&][^=&#]+=)(%2F)")
SECRETS = ("vector-app-password", "nested-secret", "store-secret")


def relocate(value, root: str):
    """Every absolute path, and every url query value that is one, moved under root."""
    if isinstance(value, dict):
        return {key: relocate(item, root) for key, item in value.items()}
    if isinstance(value, list):
        return [relocate(item, root) for item in value]
    if isinstance(value, str):
        if value.startswith("/"):
            return root + value
        return ENCODED_PATH.sub(lambda match: match.group(1) + urllib.parse.quote(root, safe="") + match.group(2),
                                value)
    return value


def unrelocate(value, root: str):
    if isinstance(value, dict):
        return {key: unrelocate(item, root) for key, item in value.items()}
    if isinstance(value, list):
        return [unrelocate(item, root) for item in value]
    if isinstance(value, str):
        if value.startswith(root + "/"):
            return value[len(root):]
        return value.replace("=" + urllib.parse.quote(root, safe="") + "%2F", "=%2F")
    return value


def paths_in(value) -> list[str]:
    """Every absolute path in value, url query values included."""
    if isinstance(value, dict):
        return [path for item in value.values() for path in paths_in(item)]
    if isinstance(value, list):
        return [path for item in value for path in paths_in(item)]
    if not isinstance(value, str):
        return []
    if value.startswith("/"):
        return [value]
    query = urllib.parse.urlsplit(value).query if "://" in value else ""
    return [item for _, item in urllib.parse.parse_qsl(query) if item.startswith("/")]


@unittest.skipIf(can_run_vectors() is not None, can_run_vectors() or "")
class RouteHashVectorTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        import test_config_build
        import live_config

        cls.live_config = live_config
        cls.work = Path(tempfile.mkdtemp(prefix="fastcgi-vectors-"))
        cls.work.chmod(0o755)
        cls.pool = None
        try:
            cls.app = test_config_build.private_app_root(cls.work)
            data = cls.work / "data"
            data.mkdir(mode=0o755)
            deploy = pwd.getpwnam(test_config_build.DEPLOY)
            (cls.app / ".env").write_text(
                "APP_NAME=Repro\nAPP_ENV=production\nAPP_KEY=base64:" + "QUJD" * 11 + "QUI=\nAPP_DEBUG=false\n"
                f"DB_CONNECTION=sqlite\nDB_DATABASE={data}/database.sqlite\nCACHE_STORE=file\n"
                "QUEUE_CONNECTION=database\nSESSION_DRIVER=file\nMAIL_MAILER=array\n")
            os.chown(cls.app / ".env", deploy.pw_uid, -1)
            test_config_build.deploy_cache(cls.app)
            cls.base = cls.work / "base-config.php"
            shutil.copyfile(cls.app / "bootstrap" / "cache" / "config.php", cls.base)
            cls.base.chmod(0o644)
            cls.root = str(cls.work / "vector-root")
            cls.deploy = test_config_build.DEPLOY
            cls.runuser = test_config_build.RUNUSER
            cls.php = test_config_build.REAL_PHP
            # A pool like production's: clear_env, no OPcache (each vector rewrites the cache file at once).
            cls.pool = FpmPool(cls.work / "fpm", www_extra="clear_env = yes\nphp_admin_value[opcache.enable] = 0\n")
            cls.pool.start()
        except BaseException:
            cls.tearDownClass()
            raise

    @classmethod
    def tearDownClass(cls):
        if cls.pool is not None:
            cls.pool.stop()
        shutil.rmtree(cls.work, ignore_errors=True)

    def live_cache(self, config: dict) -> None:
        """bootstrap/cache/config.php: the app's cached config with the vector's route subtrees, written by PHP."""
        source = self.work / "vector.json"
        source.write_text(json.dumps(config))
        source.chmod(0o644)
        done = subprocess.run([self.runuser, "-u", self.deploy, "--", self.php, "-r", OVERLAY_PHP, str(self.base),
                               str(source), str(self.app / "bootstrap" / "cache" / "config.php")],
                              capture_output=True, text=True, timeout=60, env={"PATH": "/usr/bin:/bin"})
        self.assertEqual(done.returncode, 0, done.stdout + done.stderr)

    def test_route_hash_vectors(self):
        vectors = json.loads(VECTORS.read_text(encoding="utf-8"))["vectors"]
        self.assertGreaterEqual(len(vectors), 4)
        for vector in vectors:
            with self.subTest(vector["name"]):
                config = relocate(vector["config"], self.root)
                self.assertEqual(unrelocate(config, self.root), vector["config"])
                self.live_cache(config)
                report = fastcgi.engine_probe(self.pool.socket, self.app, timeout=60)
                raw = json.dumps(report)
                self.assertEqual((report["probe"], report["sapi"], report["process"]["user"]),
                                 ("fpm-engine", "fpm-fcgi", "www-data"), raw[:2000])
                self.assertTrue(report["config_cached"])
                self.assertEqual(report["config_path"], str(self.app / "bootstrap" / "cache" / "config.php"))
                self.assertEqual(report["engine"], vector["engine"])
                values = report["route_values"]
                # PHP printed the vector's values (paths moved); unmoved, they hash to the vector's pin.
                self.assertEqual(self.live_config.canonical(values),
                                 self.live_config.canonical(relocate(vector["route_values"], self.root)))
                self.assertEqual(self.live_config.route_sha256(unrelocate(values, self.root)), vector["route_sha256"])
                # The controller's own reading of the same live file gives the hash of what FPM printed.
                measured = self.live_config.measure(self.app)
                self.assertEqual((measured.source, measured.engine), ("cache", vector["engine"]))
                self.assertEqual(measured.live_config_sha256, self.live_config.route_sha256(values))
                # No path outside the private folder was reached, and no vector database exists there.
                paths = paths_in(values)
                self.assertTrue(paths)
                self.assertEqual([path for path in paths if not path.startswith(self.root + "/")], [])
                self.assertFalse(report["ok"])
                self.assertIn("database: ", " ".join(report["problems"]))
                for secret in SECRETS:
                    self.assertNotIn(secret, raw)

    def test_cli_prints_the_probe_and_its_route_hash(self):
        vector = next(item for item in json.loads(VECTORS.read_text(encoding="utf-8"))["vectors"]
                      if item["name"] == "sqlite-production-shape")
        self.live_cache(relocate(vector["config"], self.root))
        output = io.StringIO()
        with redirect_stdout(output):
            code = fastcgi.main(["--socket", str(self.pool.socket), "--app-root", str(self.app)])
        printed = json.loads(output.getvalue())
        # The report is printed; it is not ok because no vector's database exists here.
        self.assertEqual(code, 2)
        self.assertEqual(set(printed), {"version", "report", "route_sha256"})
        self.assertEqual(printed["report"]["engine"], "sqlite")
        self.assertEqual(printed["route_sha256"], self.live_config.route_sha256(printed["report"]["route_values"]))
        self.assertEqual(printed["route_sha256"], self.live_config.measure(self.app).live_config_sha256)


if __name__ == "__main__":
    unittest.main()
