"""Run the production nginx hold include under the real nginx binary (Linux container only)."""

import http.client
import json
import os
import shutil
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
MARKER = "/run/repro-cutover-ingress/open"


class UnixConnection(http.client.HTTPConnection):
    def __init__(self, path, host):
        super().__init__(host, timeout=5)
        self.path = path

    def connect(self):
        self.sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        self.sock.settimeout(self.timeout)
        self.sock.connect(self.path)


@unittest.skipUnless(sys.platform == "linux" and os.path.exists(NGINX), "Requires the nginx binary on Linux")
class NginxHoldIncludeTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.marker = self.root / "open"
        self.socket = self.root / "nginx.sock"
        hold = (HERE / "nginx" / "repro-cutover-hold.conf").read_text(encoding="ascii")
        self.assertEqual(1, hold.count(MARKER))
        (self.root / "hold.conf").write_text(hold.replace(MARKER, str(self.marker)), encoding="ascii")
        (self.root / "gate.conf").write_text((HERE / "nginx" / "repro-cutover-gate.conf").read_text(), encoding="ascii")
        temp = self.root / "temp"
        temp.mkdir()
        config = self.root / "nginx.conf"
        config.write_text(f"""
daemon off;
master_process off;
pid {self.root}/nginx.pid;
error_log stderr;
events {{ worker_connections 16; }}
http {{
    access_log off;
    client_body_temp_path {temp}; fastcgi_temp_path {temp}; proxy_temp_path {temp};
    uwsgi_temp_path {temp}; scgi_temp_path {temp};
    include {self.root}/gate.conf;
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
        checked = subprocess.run([NGINX, "-t", "-c", str(config), "-e", "/dev/stderr"], capture_output=True, text=True)
        self.assertEqual(0, checked.returncode, checked.stderr)
        self.nginx = subprocess.Popen([NGINX, "-c", str(config), "-e", "/dev/stderr"],
                                      stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
        self.addCleanup(self.stop)
        for _ in range(100):
            if self.socket.exists():
                break
            time.sleep(0.02)

    def stop(self):
        self.nginx.terminate()
        self.nginx.communicate(timeout=5)

    def request(self, host, method, path, body=None):
        with closing(UnixConnection(str(self.socket), host)) as client:
            client.request(method, path, body, {"Content-Type": "application/json"} if body else {})
            response = client.getresponse()
            return response.status, response.getheader("Retry-After"), response.read().decode()

    ROUTES = (
        ("dashboard.fixture.test", "GET", "/"),
        ("dashboard.fixture.test", "POST", "/api/webhooks/resend"),
        ("dashboard.fixture.test", "GET", "/api/public/crm/quotes/AAAA"),
        ("legacy-api.fixture.test", "POST", "/api/webhooks/resend"),
        ("legacy-api.fixture.test", "POST", "/cubicasa_webhook.php"),
        ("legacy-api.fixture.test", "POST", "/iguide_webhook.php"),
    )

    def test_every_route_on_both_vhosts_is_held_until_the_marker_exists(self):
        for host, method, path in self.ROUTES:
            with self.subTest(host=host, path=path):
                status, retry, body = self.request(host, method, path, b"{}" if method == "POST" else None)
                self.assertEqual((503, "30"), (status, retry))
                self.assertEqual({"admission_held": True}, json.loads(body))

        self.marker.write_text("{}", encoding="ascii")
        expected = {"/": "spa", "/cubicasa_webhook.php": "php", "/iguide_webhook.php": "php"}
        for host, method, path in self.ROUTES:
            with self.subTest(host=host, path=path, marker="open"):
                status, retry, body = self.request(host, method, path, b"{}" if method == "POST" else None)
                default = "php" if host.startswith("legacy") else "api"
                self.assertEqual((200, None, expected.get(path, default)), (status, retry, body))

        # Removing the marker closes ingress at once, without a reload.
        self.marker.unlink()
        self.assertEqual(503, self.request("legacy-api.fixture.test", "POST", "/cubicasa_webhook.php", b"{}")[0])


if __name__ == "__main__":
    unittest.main()
