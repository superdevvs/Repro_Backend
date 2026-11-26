"""Real-nginx check of the producer replay hold snippet (P2-04, P2-07).

Runs the reviewed snippet in a private nginx on a Unix socket. While the hold
file is included, every producer path must answer 503 with Retry-After, never
403, 404 or a redirect, because the website and the providers treat those as
permanent. Telnyx paths stay open for their own capture route. Skips where
/usr/sbin/nginx is absent (Windows); run it in the pinned Linux QA image.
"""

import http.client
import shutil
import socket
import subprocess
import tempfile
import time
import unittest
from pathlib import Path

NGINX = Path("/usr/sbin/nginx")
SNIPPET = Path(__file__).with_name("producer-hold.nginx.conf")

HELD = (
    ("POST", "/api/external/recruiting/application"),
    ("POST", "/api/external/website-email"),
    ("POST", "/api/external/book-shoot"),
    ("POST", "/api/external/check-client"),
    ("GET", "/api/external/services"),
    ("POST", "/api/webhooks/resend"),
    ("POST", "/api/webhooks/stripe"),
    ("POST", "/api/webhooks/stripe/listing-studio"),
    ("POST", "/api/integrations/aryeo/webhooks/0b9e7a52-5f43-4c43-9d8e-1d2c3b4a5f60"),
)
OPEN = (
    ("POST", "/api/webhooks/telnyx/messaging"),
    ("GET", "/api/health"),
    ("POST", "/api/externals"),
)


class UnixConnection(http.client.HTTPConnection):
    def __init__(self, path: Path):
        super().__init__("dashboard.fixture.test", timeout=5)
        self.path = path

    def connect(self):
        self.sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        self.sock.settimeout(5)
        self.sock.connect(str(self.path))


@unittest.skipUnless(NGINX.is_file(), "pinned nginx is only in the Linux QA image")
class ProducerHoldSnippetTest(unittest.TestCase):
    def setUp(self):
        self.root = Path(tempfile.mkdtemp(prefix="producer-hold-"))
        self.holds = self.root / "holds"
        self.holds.mkdir()
        for name in ("client", "fastcgi", "proxy", "uwsgi", "scgi"):
            (self.root / name).mkdir()
        self.socket = self.root / "nginx.sock"
        (self.root / "nginx.conf").write_text(f"""
daemon off;
master_process off;
pid {self.root}/nginx.pid;
error_log stderr;
events {{ worker_connections 16; }}
http {{
    access_log off;
    client_body_temp_path {self.root}/client;
    fastcgi_temp_path {self.root}/fastcgi;
    proxy_temp_path {self.root}/proxy;
    uwsgi_temp_path {self.root}/uwsgi;
    scgi_temp_path {self.root}/scgi;
    server {{
        listen unix:{self.socket};
        server_name dashboard.fixture.test;
        include {self.holds}/*.conf;
        location / {{ default_type text/plain; return 200 'application'; }}
    }}
}}
""", encoding="ascii")
        self.nginx = None

    def tearDown(self):
        if self.nginx is not None and self.nginx.poll() is None:
            self.nginx.terminate()
            self.nginx.wait(5)
        shutil.rmtree(self.root, ignore_errors=True)

    def start(self):
        check = subprocess.run((str(NGINX), "-t", "-c", str(self.root / "nginx.conf"), "-e", "/dev/stderr"),
                               capture_output=True, text=True)
        self.assertEqual(check.returncode, 0, check.stderr)
        self.nginx = subprocess.Popen((str(NGINX), "-c", str(self.root / "nginx.conf"), "-e", "/dev/stderr"),
                                      stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        for _ in range(100):
            if self.socket.exists():
                return
            time.sleep(0.05)
        self.fail("nginx did not start")

    def request(self, method, path):
        connection = UnixConnection(self.socket)
        try:
            connection.request(method, path, body=b"{}" if method == "POST" else None,
                               headers={"Content-Type": "application/json"})
            response = connection.getresponse()
            return response.status, dict(response.getheaders()), response.read()
        finally:
            connection.close()

    def test_held_producer_paths_answer_503_with_retry_after(self):
        shutil.copyfile(SNIPPET, self.holds / "producer-hold.conf")
        self.start()
        for method, path in HELD:
            status, headers, body = self.request(method, path)
            with self.subTest(path=path):
                self.assertEqual(status, 503)
                self.assertEqual(headers.get("Retry-After"), "60")
                self.assertIn(b'"retryable":true', body)
        for method, path in OPEN:
            with self.subTest(path=path):
                self.assertEqual(self.request(method, path)[0], 200)

    def test_without_the_hold_file_every_path_reaches_the_application(self):
        self.start()
        for method, path in HELD + OPEN:
            with self.subTest(path=path):
                self.assertEqual(self.request(method, path)[0], 200)


if __name__ == "__main__":
    unittest.main()
