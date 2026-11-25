"""Run the production nginx hold include under the real nginx binary (Linux container only).

Two server blocks include the production files unchanged except for the paths
under /run and /opt, which move into a private directory: the frontend
(same-origin '^~ /api') and the legacy front controller ('~ \\.php$'). The
hold-time Telnyx capture runs in its own PHP-FPM master started from the
production pool file. These tests use a stub capture script that echoes what
it received; test_capture.py runs the real capture.php behind the same harness.
"""

from __future__ import annotations

import http.client
import json
import os
import re
import shutil
import signal
import socket
import subprocess
import sys
import tempfile
import time
import unittest
from contextlib import closing
from pathlib import Path

HERE = Path(__file__).resolve().parent
NGINX = shutil.which("nginx") or "/usr/sbin/nginx"
PHP_FPM = next((path for path in (shutil.which("php-fpm8.3"), shutil.which("php-fpm"), "/usr/local/sbin/php-fpm")
                if path and os.path.exists(path)), None)
FASTCGI_PARAMS = Path("/etc/nginx/fastcgi_params")

MARKER = "/run/repro-cutover-ingress/open"
CAPTURE_SOCKET = "/run/repro-telnyx-capture/php-fpm.sock"
CAPTURE_PID = "/run/repro-telnyx-capture/php-fpm.pid"
CAPTURE_SCRIPT = "/opt/repro-telnyx-capture/capture.php"
CAPTURE_SETTINGS = "/etc/repro-telnyx-capture/capture.json"

SHARED_MMS = "/api/webhooks/telnyx/shared-mms/0b7d6a52-5d0e-4c9e-9a51-2f7a3f3d9c11"
TELNYX_PATHS = ("/api/webhooks/telnyx/messaging", "/api/webhooks/telnyx/status",
                "/api/webhooks/telnyx/voice", SHARED_MMS)
HOSTS = ("dashboard.fixture.test", "legacy-api.fixture.test")
HELD = {"admission_held": True}

STUB = """<?php
if (is_file(__DIR__.'/fail')) { http_response_code(500); echo 'stub failure'; exit; }
header('Content-Type: application/json');
echo json_encode(['captured' => $_SERVER['REQUEST_URI'], 'method' => $_SERVER['REQUEST_METHOD'],
    'script' => $_SERVER['SCRIPT_NAME'], 'body' => file_get_contents('php://input')]);
"""


def replace_once(text: str, old: str, new: str) -> str:
    # Each production path is moved exactly once, so a test can never pass by
    # leaving a second copy of it pointing at the real host path.
    if text.count(old) != 1:
        raise AssertionError(f"{old!r} occurs {text.count(old)} times, expected once")
    return text.replace(old, new)


class UnixConnection(http.client.HTTPConnection):
    def __init__(self, path, host, timeout=10):
        super().__init__(host, timeout=timeout)
        self.unix_path = path

    def connect(self):
        self.sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        self.sock.settimeout(self.timeout)
        self.sock.connect(self.unix_path)


class HoldHarness:
    """Real nginx with the production gate and hold includes, and the capture's PHP-FPM master."""

    def __init__(self, test: unittest.TestCase, capture_script: Path, settings: Path | None = None):
        temporary = tempfile.TemporaryDirectory(prefix="repro-hold-")
        test.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.root.chmod(0o755)  # PHP-FPM children run as www-data and read from here.
        self.marker = self.root / "open"
        self.socket = self.root / "nginx.sock"
        self.capture_socket = self.root / "capture.sock"
        self.capture_log = self.root / "capture-fpm.log"
        self.nginx_log = self.root / "nginx-error.log"
        self.settings = settings or self.root / "capture.json"
        self.nginx = None
        self.capture = None
        test.addCleanup(self.stop)

        hold = (HERE / "nginx" / "repro-cutover-hold.conf").read_text(encoding="ascii")
        hold = replace_once(hold, MARKER, str(self.marker))
        hold = replace_once(hold, f"unix:{CAPTURE_SOCKET};", f"unix:{self.capture_socket};")
        hold = replace_once(hold, CAPTURE_SCRIPT, str(capture_script))
        (self.root / "hold.conf").write_text(hold, encoding="ascii")
        self.gate = self.root / "gate.conf"
        self.gate.write_text((HERE / "nginx" / "repro-cutover-gate.conf").read_text(encoding="ascii"), encoding="ascii")
        # 'include fastcgi_params;' resolves next to the main configuration file.
        shutil.copyfile(FASTCGI_PARAMS, self.root / "fastcgi_params")

        pool = (HERE / "capture" / "php-fpm.conf").read_text(encoding="ascii")
        pool = replace_once(pool, f"listen = {CAPTURE_SOCKET}\n", f"listen = {self.capture_socket}\n")
        pool = replace_once(pool, f"pid = {CAPTURE_PID}\n", f"pid = {self.root / 'capture-fpm.pid'}\n")
        pool = replace_once(pool, "error_log = syslog\n", f"error_log = {self.capture_log}\n")
        pool = replace_once(pool, f" = {CAPTURE_SETTINGS}\n", f" = {self.settings}\n")
        self.pool = self.root / "capture-fpm.conf"
        self.pool.write_text(pool, encoding="ascii")
        (self.root / "temp").mkdir()

    def config(self, gate: Path) -> Path:
        temp = self.root / "temp"
        config = self.root / f"nginx-{gate.stem}.conf"
        config.write_text(f"""
daemon off;
master_process off;
pid {self.root}/nginx.pid;
# The capture's PHP messages arrive here too ("FastCGI sent in stderr").
error_log {self.nginx_log};
events {{ worker_connections 64; }}
http {{
    access_log off;
    client_body_temp_path {temp}; fastcgi_temp_path {temp}; proxy_temp_path {temp};
    uwsgi_temp_path {temp}; scgi_temp_path {temp};
    include {gate};
    server {{
        listen unix:{self.socket};
        server_name dashboard.fixture.test;
        include {self.root}/hold.conf;
        location ^~ /api {{ default_type text/plain; return 200 'api'; }}
        location / {{ default_type text/plain; return 200 'spa'; }}
    }}
    server {{
        listen unix:{self.socket};
        server_name legacy-api.fixture.test;
        include {self.root}/hold.conf;
        location / {{ rewrite ^ /index.php last; }}
        location ~ \\.php$ {{ default_type text/plain; return 200 'php'; }}
    }}
}}
""", encoding="ascii")
        return config

    def check(self, gate: Path | None = None) -> subprocess.CompletedProcess:
        return subprocess.run([NGINX, "-t", "-c", str(self.config(gate or self.gate)), "-e", "/dev/stderr"],
                              capture_output=True, text=True)

    def start(self, *, capture: bool = True) -> "HoldHarness":
        checked = self.check()
        if checked.returncode != 0:
            raise AssertionError(checked.stderr)
        self.nginx = subprocess.Popen([NGINX, "-c", str(self.config(self.gate)), "-e", "/dev/stderr"],
                                      stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        self._wait_for(self.socket, self.nginx, "nginx")
        if capture:
            self.start_capture()
        return self

    def start_capture(self) -> None:
        # The same flags as repro-telnyx-capture.service.
        self.capture = subprocess.Popen([PHP_FPM, "--nodaemonize", "--fpm-config", str(self.pool)],
                                        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        self._wait_for(self.capture_socket, self.capture, "capture PHP-FPM")

    def stop_capture(self) -> None:
        if self.capture is not None and self.capture.poll() is None:
            self.capture.send_signal(signal.SIGQUIT)
            try:
                self.capture.wait(10)
            except subprocess.TimeoutExpired:
                self.capture.kill()
                self.capture.wait()
        self.capture = None

    def stop(self) -> None:
        self.stop_capture()
        if self.nginx is not None and self.nginx.poll() is None:
            self.nginx.terminate()
            self.nginx.wait(10)
        self.nginx = None

    def _wait_for(self, path: Path, process: subprocess.Popen, name: str) -> None:
        for _ in range(250):
            if path.exists():
                return
            if process.poll() is not None:
                break
            time.sleep(0.02)
        log = self.capture_log.read_text(errors="replace") if self.capture_log.exists() else ""
        raise AssertionError(f"{name} did not start: {log[-2000:]}")

    def request(self, host: str, method: str, path: str, body: bytes | None = None,
                headers: dict | None = None, timeout: float = 10) -> tuple[int, str | None, str]:
        if body is None and method == "POST":
            body = b"{}"
        sent = {"Content-Type": "application/json"} if body is not None else {}
        sent.update(headers or {})
        with closing(UnixConnection(str(self.socket), host, timeout)) as client:
            client.request(method, path, body, sent)
            response = client.getresponse()
            return response.status, response.getheader("Retry-After"), response.read().decode("utf-8", "replace")


def runnable() -> bool:
    return sys.platform == "linux" and os.path.exists(NGINX) and FASTCGI_PARAMS.exists()


def capture_runnable() -> bool:
    # The capture's master must run as root to hand its socket to www-data, as on the host.
    return runnable() and PHP_FPM is not None and os.geteuid() == 0


needs_capture = unittest.skipUnless(capture_runnable(), "Starts the capture's PHP-FPM master: php-fpm and root")


class NginxGateFileTests(unittest.TestCase):
    def test_gate_map_holds_every_request_and_the_old_telnyx_include_is_gone(self):
        gate = (HERE / "nginx" / "repro-cutover-gate.conf").read_text(encoding="ascii")
        body = re.search(r"map \$uri \$repro_cutover_gate \{(.*?)\}", gate, re.DOTALL)
        self.assertIsNotNone(body)
        self.assertEqual(["default 1;"], [line.strip() for line in body.group(1).splitlines() if line.strip()])
        # The v1 second include must not exist: a server block could include the hold without it.
        self.assertEqual(["repro-cutover-gate.conf", "repro-cutover-hold.conf"],
                         sorted(path.name for path in (HERE / "nginx").iterdir()))


@unittest.skipUnless(runnable(), "Requires nginx on Linux")
class NginxHoldIncludeTests(unittest.TestCase):
    ROUTES = (
        ("dashboard.fixture.test", "GET", "/"),
        ("dashboard.fixture.test", "POST", "/api/webhooks/resend"),
        ("dashboard.fixture.test", "GET", "/api/public/crm/quotes/AAAA"),
        ("legacy-api.fixture.test", "POST", "/api/webhooks/resend"),
        ("legacy-api.fixture.test", "POST", "/cubicasa_webhook.php"),
        ("legacy-api.fixture.test", "POST", "/iguide_webhook.php"),
    )
    # Near misses of the Telnyx paths, Vapi (captured in the app during deploys, not here) and
    # the capture's internal URI: all held with the rest of the site.
    NOT_TELNYX = (
        "/api/webhooks/telnyx/messaging/", "/api/webhooks/telnyx/messagingx", "/api/webhooks/telnyx",
        "/api/webhooks/telnyx/shared-mms/not-a-uuid", f"{SHARED_MMS}/extra", f"{SHARED_MMS}0",
        "/api/webhooks/vapi", "/api/webhooks/stripe", "/__repro_telnyx_capture", "/__repro_telnyx_capture/x",
    )

    def setUp(self):
        self.stub_dir = Path(tempfile.mkdtemp(prefix="repro-stub-"))
        self.addCleanup(shutil.rmtree, self.stub_dir, True)
        self.stub_dir.chmod(0o755)
        self.stub = self.stub_dir / "capture.php"
        self.stub.write_text(STUB, encoding="ascii")
        self.stub.chmod(0o644)
        self.hold = HoldHarness(self, self.stub)

    def assertHeld(self, answer, label=""):
        status, retry, body = answer
        self.assertEqual((503, "30"), (status, retry), f"{label}: {body}")
        self.assertEqual(HELD, json.loads(body), label)

    def test_every_route_on_both_vhosts_is_held_until_the_marker_exists(self):
        self.hold.start(capture=False)
        for host, method, path in self.ROUTES:
            with self.subTest(host=host, path=path):
                self.assertHeld(self.hold.request(host, method, path), f"{host} {path}")

        self.hold.marker.write_text("{}", encoding="ascii")
        expected = {"/": "spa", "/cubicasa_webhook.php": "php", "/iguide_webhook.php": "php"}
        for host, method, path in self.ROUTES:
            with self.subTest(host=host, path=path, marker="open"):
                status, retry, body = self.hold.request(host, method, path)
                default = "php" if host.startswith("legacy") else "api"
                self.assertEqual((200, None, expected.get(path, default)), (status, retry, body))

        # Removing the marker closes ingress at once, without a reload.
        self.hold.marker.unlink()
        self.assertHeld(self.hold.request("legacy-api.fixture.test", "POST", "/cubicasa_webhook.php"))

    @needs_capture
    def test_both_server_blocks_capture_telnyx_and_hold_the_rest(self):
        self.hold.start()
        for host in HOSTS:
            for path in TELNYX_PATHS:
                with self.subTest(host=host, path=path):
                    status, retry, body = self.hold.request(host, "POST", path, b'{"data":{"id":"evt"}}')
                    self.assertEqual((200, None), (status, retry), body)
                    # The capture sees the original URI and body; SCRIPT_NAME is the internal one.
                    self.assertEqual({"captured": path, "method": "POST", "script": "/__repro_telnyx_capture",
                                      "body": '{"data":{"id":"evt"}}'}, json.loads(body))
            # A query string does not change the route (nginx maps $uri); the capture gets it as sent.
            status, _, body = self.hold.request(host, "POST", "/api/webhooks/telnyx/status?attempt=2")
            self.assertEqual((200, "/api/webhooks/telnyx/status?attempt=2"), (status, json.loads(body)["captured"]))
            # The capture, not nginx, answers other methods on a Telnyx path.
            status, _, body = self.hold.request(host, "GET", "/api/webhooks/telnyx/messaging")
            self.assertEqual((200, "GET"), (status, json.loads(body)["method"]))
            for path in self.NOT_TELNYX:
                with self.subTest(host=host, path=path):
                    self.assertHeld(self.hold.request(host, "POST", path), f"{host} {path}")
            for _, method, path in self.ROUTES:
                with self.subTest(host=host, path=path):
                    self.assertHeld(self.hold.request(host, method, path), f"{host} {path}")

    @needs_capture
    def test_capture_down_answers_503_with_retry_after(self):
        self.hold.start(capture=False)
        for host in HOSTS:
            for path in TELNYX_PATHS:
                with self.subTest(host=host, path=path, capture="never started"):
                    self.assertHeld(self.hold.request(host, "POST", path), f"{host} {path}")

        # A socket left behind by a crashed master refuses connections.
        stale = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        self.addCleanup(stale.close)
        stale.bind(str(self.hold.capture_socket))
        for host in HOSTS:
            with self.subTest(host=host, capture="stale socket"):
                self.assertHeld(self.hold.request(host, "POST", TELNYX_PATHS[0]), host)
        stale.close()
        self.hold.capture_socket.unlink()

        # A capture that fails inside PHP, or a body nginx will not pass on, is not saved either.
        self.hold.start_capture()
        self.assertEqual(200, self.hold.request(HOSTS[0], "POST", TELNYX_PATHS[0])[0])
        (self.stub_dir / "fail").write_text("", encoding="ascii")
        for host in HOSTS:
            with self.subTest(host=host, capture="error 500"):
                self.assertHeld(self.hold.request(host, "POST", TELNYX_PATHS[0]), host)
        (self.stub_dir / "fail").unlink()
        for host in HOSTS:
            with self.subTest(host=host, capture="body over 1 MiB"):
                big = b'{"data":{"id":"evt"},"pad":"' + b"x" * (1024 * 1024) + b'"}'
                self.assertHeld(self.hold.request(host, "POST", TELNYX_PATHS[0], big), host)

        # Stopped again (as by a crash or 'systemctl stop'): held, never a bare 502.
        self.hold.stop_capture()
        for host in HOSTS:
            with self.subTest(host=host, capture="stopped"):
                self.assertHeld(self.hold.request(host, "POST", SHARED_MMS), host)

    def test_missing_telnyx_map_fails_nginx_t(self):
        # The gate file as it was before the capture: only the hold map. A server block with the
        # hold include then cannot load, so the hold cannot be installed without the capture.
        old_gate = self.hold.root / "gate-hold-only.conf"
        old_gate.write_text("map $uri $repro_cutover_gate {\n    default 1;\n}\n", encoding="ascii")
        checked = self.hold.check(old_gate)
        self.assertNotEqual(0, checked.returncode, checked.stderr)
        self.assertIn('unknown "repro_cutover_telnyx" variable', checked.stderr)
        self.assertEqual(0, self.hold.check().returncode, self.hold.check().stderr)

    @needs_capture
    def test_marker_sends_telnyx_to_app_and_hides_internal_uri(self):
        self.hold.start()
        self.hold.marker.write_text("{}", encoding="ascii")
        for host in HOSTS:
            app = "php" if host.startswith("legacy") else "api"
            for path in TELNYX_PATHS:
                with self.subTest(host=host, path=path):
                    self.assertEqual((200, None, app), self.hold.request(host, "POST", path))
            with self.subTest(host=host, path="internal"):
                status, retry, _ = self.hold.request(host, "POST", "/__repro_telnyx_capture")
                self.assertEqual((404, None), (status, retry))
        # Removing the marker sends Telnyx back to the capture at once, without a reload.
        self.hold.marker.unlink()
        status, _, body = self.hold.request(HOSTS[1], "POST", TELNYX_PATHS[0])
        self.assertEqual((200, TELNYX_PATHS[0]), (status, json.loads(body)["captured"]))


if __name__ == "__main__":
    unittest.main()
