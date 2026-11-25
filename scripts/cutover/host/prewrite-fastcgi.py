"""Run the pre-write journal rehearsal through private nginx and PHP-FPM sockets."""

from __future__ import annotations

import http.client
import json
import os
import select
import signal
import socket
import struct
import subprocess
import sys
import time
from contextlib import closing
from pathlib import Path

from rehearse import PRIVATE, ContainerRehearsal, exercise, file_digest, load_pymysql, require
from scripts.cutover.runner import UnsafeCutover


FPM = Path("/usr/local/sbin/php-fpm")
FPM_SOCKET = PRIVATE / "writer-fastcgi.sock"
FPM_CONFIG = PRIVATE / "writer-fastcgi.conf"
NGINX = Path("/usr/sbin/nginx")
NGINX_SOCKET = PRIVATE / "writer-nginx.sock"
NGINX_CONFIG = PRIVATE / "writer-nginx.conf"
INGRESS_OPEN = PRIVATE / "authority" / "nginx-writers-open"

NGINX_SETTINGS = """
daemon off;
master_process off;
pid /private/writer-nginx.pid;
error_log stderr;
events { worker_connections 32; }
http {
    access_log off;
    client_body_temp_path /private/nginx-client;
    fastcgi_temp_path /private/nginx-fastcgi;
    proxy_temp_path /private/nginx-proxy;
    uwsgi_temp_path /private/nginx-uwsgi;
    scgi_temp_path /private/nginx-scgi;
    map $request_method $require_writer_gate { GET 0; default 1; }
    server {
        listen unix:/private/writer-nginx.sock;
        server_name fixture.test;
        location = /fixture/notification-state {
            default_type application/json;
            set $held $require_writer_gate;
            if (-f /private/authority/nginx-writers-open) { set $held 0; }
            if ($held) { return 503 '{"admission_held":true}'; }
            add_header X-Fixture-Upstream $upstream_addr always;
            fastcgi_pass unix:/private/writer-fastcgi.sock;
            fastcgi_param SCRIPT_FILENAME /host/probe.php;
            fastcgi_param SCRIPT_NAME /probe.php;
            fastcgi_param DOCUMENT_ROOT /host;
            fastcgi_param REQUEST_METHOD $request_method;
            fastcgi_param REQUEST_URI $request_uri;
            fastcgi_param SERVER_NAME $server_name;
            fastcgi_param SERVER_PROTOCOL $server_protocol;
            fastcgi_param REDIRECT_STATUS 200;
            fastcgi_param CONTENT_TYPE $content_type;
            fastcgi_param CONTENT_LENGTH $content_length;
        }
        location / { return 404; }
    }
}
"""


class NginxConnection(http.client.HTTPConnection):
    def __init__(self, expected_pid: int):
        self.expected_pid = expected_pid
        super().__init__("fixture.test", timeout=7)

    def connect(self):
        peer = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        try:
            peer.settimeout(self.timeout)
            peer.connect(str(NGINX_SOCKET))
            pid, uid, _ = struct.unpack("3i", peer.getsockopt(socket.SOL_SOCKET, socket.SO_PEERCRED, 12))
            require((pid, uid) == (self.expected_pid, os.geteuid()), "The owned nginx socket identity changed")
        except BaseException:
            peer.close()
            raise
        self.sock = peer


class FastCgiRehearsal(ContainerRehearsal):
    def __init__(self, *args):
        require(FPM.is_file() and NGINX.is_file() and hasattr(os, "pidfd_open"),
                "The pinned nginx, PHP-FPM and Linux pidfd support are required")
        self.worker_cohorts_drained = 0
        self.edge_holds = 0
        self.nginx = None
        super().__init__(*args)

    def close_server(self):
        try:
            if self.nginx is not None and self.nginx.poll() is None:
                self.nginx.send_signal(signal.SIGQUIT)
                try:
                    require(self.nginx.wait(6) == 0, "The owned nginx did not exit gracefully")
                finally:
                    if self.nginx.poll() is None:
                        self.nginx.kill()
                        self.nginx.wait(3)
        finally:
            self.nginx = None
            self.close_fpm()

    def close_fpm(self):
        master = self.server
        if master is None or master.poll() is not None:
            self.server = None
            return
        child_path = Path(f"/proc/{master.pid}/task/{master.pid}/children")
        worker_ids = child_path.read_text().split()
        require(len(worker_ids) == 1, "Expected one owned static PHP-FPM worker")
        worker = os.pidfd_open(int(worker_ids[0]))
        try:
            master.send_signal(signal.SIGQUIT)
            require(master.wait(6) == 0 and select.select([worker], [], [], 1)[0] == [worker],
                    "The owned PHP-FPM master and worker did not finish")
            self.worker_cohorts_drained += 1
        finally:
            os.close(worker)
            if master.poll() is None:
                master.kill()
                master.wait(3)
            self.server = None

    def open_server(self, engine):
        self.close_server()
        require(not FPM_SOCKET.exists() and not NGINX_SOCKET.exists(), "An old private ingress socket remains")
        FPM_CONFIG.write_text(
            "[global]\nerror_log=/proc/self/fd/2\ndaemonize=no\nprocess_control_timeout=4s\n"
            f"[fixture]\nlisten={FPM_SOCKET}\nlisten.mode=0600\npm=static\npm.max_children=1\n"
            "clear_env=no\nchdir=/workspace\n", encoding="ascii",
        )
        self.server = subprocess.Popen(
            (str(FPM), "-F", "-y", str(FPM_CONFIG)), cwd="/workspace", env=self.environment(engine),
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        )
        for _ in range(60):
            require(self.server.poll() is None, "The owned PHP-FPM master exited")
            if FPM_SOCKET.exists():
                break
            time.sleep(0.05)
        else:
            raise UnsafeCutover("The owned PHP-FPM socket never became ready")
        NGINX_CONFIG.write_text(NGINX_SETTINGS, encoding="ascii")
        self.nginx = subprocess.Popen(
            (str(NGINX), "-c", str(NGINX_CONFIG), "-e", "/dev/stderr"), cwd=PRIVATE,
            env={"PATH": "/usr/sbin:/usr/bin:/bin"}, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        )
        for _ in range(60):
            require(self.nginx.poll() is None, "The owned nginx exited")
            try:
                NGINX_SOCKET.chmod(0o600)
                result = self.http()
            except (ConnectionRefusedError, FileNotFoundError):
                time.sleep(0.05)
            else:
                return result
        raise UnsafeCutover("The owned nginx socket never became ready")

    def set_gate(self, engine, opened):
        if not opened:
            INGRESS_OPEN.unlink(missing_ok=True)
        super().set_gate(engine, opened)
        if opened:
            self.journal.write_record(INGRESS_OPEN.name, {"open": True})

    def freeze(self, lease, deadline):
        super().freeze(lease, deadline)
        # Restart both services while the ingress file is absent. The later
        # stale-gate check independently exercises the journal guard in FPM.
        self.restart_and_check_hold("sqlite")

    def http(self, method="GET", code=200):
        with closing(NginxConnection(self.nginx.pid)) as client:
            client.request(method, "/fixture/notification-state")
            response = client.getresponse()
            require(response.status == code, f"Unexpected private nginx status {response.status}")
            forwarded = response.getheader("X-Fixture-Upstream") == f"unix:{FPM_SOCKET}"
            if method == "POST" and code == 503 and not INGRESS_OPEN.is_file():
                require(not forwarded, "Held request reached PHP-FPM")
                self.edge_holds += 1
            else:
                require(forwarded, "The private nginx probe did not reach FastCGI")
            body = response.read(64 * 1024 + 1)
            require(len(body) <= 64 * 1024, "Oversized private nginx response")
            return json.loads(body)


def main() -> None:
    with closing(FastCgiRehearsal(load_pymysql(), *sys.argv[1:])) as adapter:
        report = exercise(adapter)
        require(adapter.worker_cohorts_drained == 7 and adapter.edge_holds == 3,
                "Expected every replaced PHP-FPM cohort and each closed nginx edge probe to be verified")
        report.update({
            "scope": "synthetic journal gate on private nginx/PHP-FPM Unix sockets and one-shot Laravel queue",
            "fpm_worker_cohorts_drained_during_restarts": adapter.worker_cohorts_drained,
            "nginx_503_without_upstream": adapter.edge_holds,
            "fastcgi_harness_sha256": file_digest(Path(__file__)),
            "php_fpm_sha256": file_digest(FPM),
            "nginx_sha256": file_digest(NGINX),
        })
    print(json.dumps(report, sort_keys=True))


if __name__ == "__main__":
    main()
