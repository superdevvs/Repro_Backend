"""Alert bridge tests against a loopback HTTPS endpoint. No provider is contacted."""
from __future__ import annotations

import contextlib
import http.server
import io
import json
import os
from pathlib import Path
import shutil
import ssl
import subprocess
import tempfile
import threading
import time
import unittest

import alert_bridge as bridge

TOKEN = "0f7e2d4c-1111-4222-8333-944455556666"
OPENSSL = shutil.which("openssl")


class Recorder(http.server.BaseHTTPRequestHandler):
    paths: list[str] = []
    status = 200
    delay = 0.0
    redirect = False

    def do_GET(self):  # noqa: N802 - stdlib handler name
        type(self).paths.append(self.path)
        if type(self).delay:
            time.sleep(type(self).delay)
        if type(self).redirect:
            self.send_response(302)
            self.send_header("Location", "https://127.0.0.1:1/elsewhere")
            self.end_headers()
            return
        self.send_response(type(self).status)
        self.end_headers()
        self.wfile.write(b"OK")

    def log_message(self, *_args):
        pass


class QuietServer(http.server.ThreadingHTTPServer):
    def handle_error(self, *_args):
        pass  # The timeout case closes the client side on purpose.


@unittest.skipUnless(OPENSSL, "openssl is needed to mint a throwaway loopback certificate")
class BridgeTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.folder = tempfile.TemporaryDirectory()
        root = Path(cls.folder.name)
        cls.cert, key = root / "cert.pem", root / "key.pem"
        subprocess.run([OPENSSL, "req", "-x509", "-nodes", "-days", "1", "-newkey", "rsa:2048",
                        "-subj", "/CN=localhost", "-addext", "subjectAltName=DNS:localhost,IP:127.0.0.1",
                        "-keyout", str(key), "-out", str(cls.cert)],
                       check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        context.load_cert_chain(cls.cert, key)
        cls.server = QuietServer(("127.0.0.1", 0), Recorder)
        cls.server.socket = context.wrap_socket(cls.server.socket, server_side=True)
        cls.thread = threading.Thread(target=cls.server.serve_forever, daemon=True)
        cls.thread.start()
        cls.base = f"https://127.0.0.1:{cls.server.server_address[1]}/ping/{TOKEN}"

    @classmethod
    def tearDownClass(cls):
        cls.server.shutdown()
        cls.server.server_close()
        cls.folder.cleanup()

    def setUp(self):
        Recorder.paths = []
        Recorder.status = 200
        Recorder.delay = 0.0
        Recorder.redirect = False
        self.client = ssl.create_default_context(cafile=str(self.cert))
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.inbox = self.root / "inbox"
        self.inbox.mkdir()
        self.config = {"version": 1, "urls_file": str(self.root / "urls.json"), "inbox_dir": str(self.inbox),
                       "fail_suffix": "/fail", "timeout_seconds": 3, "max_status_age_seconds": 180,
                       "forward": ["disk_nearly_full", "queue_stale"]}
        self.urls = {"version": 1, "checks": {"server_silent": self.base, "backup_stale": self.base + "-b",
                                               "disk_nearly_full": self.base + "-d", "queue_stale": self.base + "-q"}}

    def signal(self, condition, state, **changes):
        urls = bridge.parse_urls(json.dumps(self.urls | changes).encode())
        return bridge.signal(bridge.parse_config(json.dumps(self.config).encode()), urls, condition, state,
                             context=self.client)

    def test_success_ping_uses_the_exact_check_url(self):
        result = self.signal("server_silent", "ok")
        self.assertEqual(result, {"result": "alert-ping-delivered", "condition": "server_silent",
                                  "state": "ok", "http_status": 200})
        self.assertEqual(Recorder.paths, [f"/ping/{TOKEN}"])

    def test_failure_ping_adds_the_provider_fail_suffix(self):
        result = self.signal("backup_stale", "fail")
        self.assertEqual(result["result"], "alert-ping-delivered")
        self.assertEqual(Recorder.paths, [f"/ping/{TOKEN}-b/fail"])

    def test_provider_without_fail_endpoint_withholds_the_ping(self):
        self.config["fail_suffix"] = None
        result = self.signal("backup_stale", "fail")
        self.assertEqual(result["result"], "alert-ping-withheld")
        self.assertEqual(Recorder.paths, [])

    def test_no_output_reveals_the_url_or_token(self):
        cases = []
        Recorder.status = 500
        cases.append(self.signal("server_silent", "ok"))
        Recorder.status = 200
        Recorder.redirect = True
        cases.append(self.signal("server_silent", "ok"))
        Recorder.redirect = False
        Recorder.delay = 1.5
        self.config["timeout_seconds"] = 1
        cases.append(self.signal("server_silent", "fail"))
        Recorder.delay = 0
        closed = "https://127.0.0.1:1/ping/" + TOKEN
        cases.append(self.signal("server_silent", "ok", checks=dict(self.urls["checks"], server_silent=closed)))
        for result in cases:
            self.assertEqual(result["result"], "alert-ping-failed")
            text = json.dumps(result)
            self.assertNotIn(TOKEN, text)
            self.assertNotIn("127.0.0.1", text)
        self.assertEqual(cases[0]["reason"], "provider returned HTTP 500")
        self.assertEqual(cases[1]["reason"], "provider returned HTTP 302")

    def test_main_prints_only_the_redacted_result(self):
        stdout, stderr = io.StringIO(), io.StringIO()
        with contextlib.redirect_stdout(stdout), contextlib.redirect_stderr(stderr):
            code = bridge.run(["signal", "--condition", "server_silent", "--state", "ok"],
                              config=json.dumps(self.config).encode(), urls=json.dumps(self.urls).encode(),
                              context=self.client)
        self.assertEqual(code, 0)
        self.assertNotIn(TOKEN, stdout.getvalue() + stderr.getvalue())
        self.assertEqual(json.loads(stdout.getvalue())["result"], "alert-ping-delivered")

    def test_unsafe_urls_are_refused_without_echoing_them(self):
        for url in ("http://hc-ping.com/" + TOKEN, "https://user:pw@hc-ping.com/" + TOKEN,
                    "https://hc-ping.com/" + TOKEN + "?x=1", "https://hc-ping.com/" + TOKEN + "#a",
                    "https://bad host/" + TOKEN, "https://hc-ping.com", "ftp://hc-ping.com/" + TOKEN):
            with self.assertRaises(bridge.Refusal) as caught:
                bridge.parse_urls(json.dumps({"version": 1, "checks": {"server_silent": url}}).encode())
            self.assertNotIn(TOKEN, str(caught.exception))

    def test_unknown_condition_is_refused(self):
        with self.assertRaises(bridge.Refusal):
            self.signal("site_unreachable", "ok")
        self.assertEqual(Recorder.paths, [])

    def write_status(self, condition, state, *, age=0, **extra):
        record = {"schema": bridge.STATUS_SCHEMA, "condition": condition, "state": state,
                  "observed_unix_s": int(time.time()) - age, "detail": "fixture"} | extra
        (self.inbox / f"{condition}.json").write_text(json.dumps(record))

    def forward(self):
        return bridge.forward(bridge.parse_config(json.dumps(self.config).encode()),
                              bridge.parse_urls(json.dumps(self.urls).encode()), context=self.client,
                              now=int(time.time()))

    def test_forward_relays_each_detector_state(self):
        self.write_status("disk_nearly_full", "fail")
        self.write_status("queue_stale", "ok")
        results = self.forward()
        self.assertEqual([item["state"] for item in results], ["fail", "ok"])
        self.assertEqual(sorted(Recorder.paths), sorted([f"/ping/{TOKEN}-d/fail", f"/ping/{TOKEN}-q"]))

    def test_forward_treats_missing_stale_or_mismatched_status_as_failure(self):
        self.write_status("disk_nearly_full", "ok", age=181)
        (self.inbox / "queue_stale.json").write_text(json.dumps({"schema": bridge.STATUS_SCHEMA,
            "condition": "disk_nearly_full", "state": "ok", "observed_unix_s": int(time.time()), "detail": ""}))
        results = self.forward()
        self.assertEqual([(item["state"], item["status"]) for item in results],
                         [("fail", "detector status stale"), ("fail", "detector status invalid")])
        (self.inbox / "queue_stale.json").unlink()
        self.assertEqual(self.forward()[1]["status"], "detector status missing")

    def test_config_refuses_unreviewed_fields(self):
        with self.assertRaises(bridge.Refusal):
            bridge.parse_config(json.dumps(self.config | {"proxy": "https://x"}).encode())
        with self.assertRaises(bridge.Refusal):
            bridge.parse_config(json.dumps(self.config | {"timeout_seconds": 0}).encode())


@unittest.skipUnless(os.name == "posix", "private file ownership checks require Linux")
class PrivateUrlFileTests(unittest.TestCase):
    def test_group_readable_url_file_is_refused(self):
        with tempfile.TemporaryDirectory(dir=Path.home()) as name:
            folder = Path(name)
            os.chmod(folder, 0o700)
            path = folder / "urls.json"
            path.write_text(json.dumps({"version": 1, "checks": {"server_silent": "https://hc-ping.com/" + TOKEN}}))
            os.chmod(path, 0o640)
            with self.assertRaises(bridge.Refusal):
                bridge.read_private_urls(path)
            os.chmod(path, 0o600)
            self.assertIn("server_silent", bridge.read_private_urls(path))


if __name__ == "__main__":
    unittest.main()


@unittest.skipUnless(os.name == "posix", "private file creation needs Linux")
class EnterUrlsTests(unittest.TestCase):
    def test_prompts_for_each_check_and_writes_a_private_file_without_echo(self):
        with tempfile.TemporaryDirectory(dir=Path.home()) as name:
            folder = Path(name)
            os.chmod(folder, 0o700)
            config = {"version": 1, "urls_file": str(folder / "ping-urls.json"), "inbox_dir": str(folder),
                      "fail_suffix": "/fail", "timeout_seconds": 5, "max_status_age_seconds": 180,
                      "forward": ["disk_nearly_full"]}
            asked = []

            def answer(prompt):
                asked.append(prompt)
                return "https://hc-ping.com/" + TOKEN + "-" + prompt.split()[0]

            stdout = io.StringIO()
            with contextlib.redirect_stdout(stdout):
                bridge.enter_urls(bridge.parse_config(json.dumps(config).encode()), read=answer)
            self.assertEqual([prompt.split()[0] for prompt in asked],
                             ["server_silent", "backup_stale", "backup_failed", "disk_nearly_full"])
            self.assertEqual(os.stat(folder / "ping-urls.json").st_mode & 0o777, 0o600)
            self.assertEqual(sorted(bridge.read_private_urls(folder / "ping-urls.json")),
                             ["backup_failed", "backup_stale", "disk_nearly_full", "server_silent"])
            self.assertNotIn(TOKEN, stdout.getvalue())
            with self.assertRaises(bridge.Refusal):
                bridge.enter_urls(bridge.parse_config(json.dumps(config).encode()), read=answer)
