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
import threading
import time
from contextlib import closing
from pathlib import Path

from rehearse import (
    PRIVATE, SNAPSHOT, SOURCE, ContainerRehearsal, exercise, file_digest, load_pymysql, require, sqlite_rows,
)
from scripts.cutover.runner import Runner, UnsafeCutover


FPM = Path("/usr/local/sbin/php-fpm")
FPM_SOCKET = PRIVATE / "writer-fastcgi.sock"
FPM_CONFIG = PRIVATE / "writer-fastcgi.conf"
NGINX = Path("/usr/sbin/nginx")
NGINX_SOCKET = PRIVATE / "writer-nginx.sock"
NGINX_CONFIG = PRIVATE / "writer-nginx.conf"
NGINX_LOCATIONS = PRIVATE / "writer-nginx-locations.conf"
NGINX_DIRECT_PHP = PRIVATE / "writer-nginx-direct-php.conf"
NGINX_HOLD = PRIVATE / "writer-nginx-hold.conf"
# The production server-level include, loaded verbatim except for its marker path.
PRODUCTION_HOLD = Path("/workspace/scripts/cutover/production/nginx/repro-cutover-hold.conf")
PRODUCTION_MARKER = "/run/repro-cutover-ingress/open"
INGRESS_OPEN = PRIVATE / "authority" / "nginx-writers-open"
SLOW_INFLIGHT = PRIVATE / "slow-write-inflight"
VHOSTS = ("dashboard.fixture.test", "legacy-api.fixture.test")
LEGACY = VHOSTS[1]
SMOKE_PATH = "/fixture/notification-state"
SLOW_PATH = "/fixture/slow-write"
QUOTE_PATH = "/api/public/crm/quotes/" + "A" * 48
WEBHOOK_PATH = "/api/webhooks/resend"
WEBHOOK_PAYLOAD = b'{"id":"synthetic-resend-event"}'
WRITING_ROUTES = (
    ("write_on_read", "GET", QUOTE_PATH + "?source=cutover-fixture", None),
    ("webhook", "POST", WEBHOOK_PATH, WEBHOOK_PAYLOAD),
)
# Legacy host only: '~ \.php$' passes these straight to FastCGI, and the real
# files answer 200 before Laravel boots.
DIRECT_ROUTES = (
    ("cubicasa_direct", "POST", "/cubicasa_webhook.php", b'{"id":"synthetic-cubicasa"}'),
    ("iguide_direct", "POST", "/iguide_webhook.php", b'{"id":"synthetic-iguide"}'),
)

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
    # Fixture-only exemption for the read-only smoke route; production keeps default 1.
    map "$request_method:$uri" $repro_cutover_gate {
        "GET:/fixture/notification-state" 0;
        default 1;
    }
    server { listen unix:/private/writer-nginx.sock default_server; return 404; }
    server {
        listen unix:/private/writer-nginx.sock;
        server_name dashboard.fixture.test;
        include /private/writer-nginx-hold.conf;
        include /private/writer-nginx-locations.conf;
    }
    server {
        listen unix:/private/writer-nginx.sock;
        server_name legacy-api.fixture.test;
        include /private/writer-nginx-hold.conf;
        include /private/writer-nginx-direct-php.conf;
        include /private/writer-nginx-locations.conf;
    }
}
"""

FASTCGI = """
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
"""
NGINX_ROUTE = "\n    default_type application/json;" + FASTCGI


class NginxConnection(http.client.HTTPConnection):
    def __init__(self, expected_pid: int, host: str):
        self.expected_pid = expected_pid
        super().__init__(host, timeout=7)

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
        hold = PRODUCTION_HOLD.read_text(encoding="ascii")
        require(hold.count(PRODUCTION_MARKER) == 1, "The production hold include changed its marker")
        self.hold_include = hold.replace(PRODUCTION_MARKER, str(INGRESS_OPEN))
        self.worker_cohorts_drained = 0
        self.edge_holds = 0
        self.route_holds = {host: {kind: {"nginx": 0, "fpm": 0} for kind, *_ in WRITING_ROUTES} for host in VHOSTS}
        self.route_holds[LEGACY].update({kind: {"nginx": 0, "fpm_acknowledged": 0} for kind, *_ in DIRECT_ROUTES})
        self.slow = None
        self.held_while_inflight = None
        self.nginx = None
        super().__init__(*args)

    def close_server(self):
        try:
            if self.nginx is not None and self.nginx.poll() is None:
                # Graceful: nginx waits for in-flight requests, including a slow writer.
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
        NGINX_HOLD.write_text(self.hold_include, encoding="ascii")
        locations = [f"location = {path} {{{NGINX_ROUTE}}}" for path in (SMOKE_PATH, SLOW_PATH, QUOTE_PATH, WEBHOOK_PATH)]
        locations.append("location / { return 404; }")
        NGINX_LOCATIONS.write_text("\n".join(locations), encoding="ascii")
        NGINX_DIRECT_PHP.write_text("location ~ \\.php$ {" + NGINX_ROUTE + "}\n", encoding="ascii")
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

    def before_hold(self):
        """Admit a slow SQLite writer through nginx and FPM, still in flight when the hold lands."""
        SLOW_INFLIGHT.unlink(missing_ok=True)
        result = {}

        def slow_write():
            try:
                result["response"] = self.http("POST", path=SLOW_PATH, body=b"{}")
            except BaseException as error:  # Reported by finish_slow_write.
                result["error"] = repr(error)

        self.slow = (threading.Thread(target=slow_write, daemon=True), result)
        self.slow[0].start()
        for _ in range(200):
            if SLOW_INFLIGHT.exists():
                return
            time.sleep(0.01)
        raise UnsafeCutover("The slow writer never entered its admitted lease")

    def finish_slow_write(self):
        thread, result = self.slow
        thread.join(10)
        require(not thread.is_alive() and result.get("response") == {"receipt": "synthetic-slow-write", "host": VHOSTS[0]},
                f"The admitted slow writer did not finish once: {result}")

    def close_admissions(self, lease, deadline):
        super().close_admissions(lease, deadline)
        self.assert_route_holds()
        if self.slow is not None and self.held_while_inflight is None:
            # Both vhosts answered 503 right after the hold while the old writer still ran.
            self.held_while_inflight = self.slow[0].is_alive()

    def freeze(self, lease, deadline):
        super().freeze(lease, deadline)
        if self.slow is not None:
            self.finish_slow_write()
            effects = sqlite_rows(SNAPSHOT)["fixture_effects"]
            require(effects.count(("synthetic-slow-write",)) == 1, "The drained writer's effect is not in the snapshot once")
        # Restart both services while the ingress file is absent. The later
        # stale-gate check independently exercises the journal guard in FPM.
        self.restart_and_check_hold("sqlite")

    def prepare(self, lease, deadline):
        super().prepare(lease, deadline)
        self.assert_route_holds()

    def assert_writer_hold(self):
        super().assert_writer_hold()
        self.assert_route_holds()

    def assert_route_holds(self):
        layer = "fpm" if INGRESS_OPEN.is_file() else "nginx"
        for host in VHOSTS:
            for kind, method, path, body in WRITING_ROUTES:
                require(self.http(method, 503, host=host, path=path, body=body) == {"admission_held": True},
                        f"The {host} {kind} request escaped the {layer} writer hold")
                self.route_holds[host][kind][layer] += 1
        for kind, method, path, body in DIRECT_ROUTES:
            if layer == "nginx":
                require(self.http(method, 503, host=LEGACY, path=path, body=body) == {"admission_held": True},
                        f"The direct {kind} file escaped the server-level hold")
                self.route_holds[LEGACY][kind]["nginx"] += 1
            else:
                # Behind a stale open marker the PHP gate holds the write but the
                # file has already told the provider 200: the event would be lost.
                response = self.http(method, 200, host=LEGACY, path=path, body=body)
                require(response == {"admission_held": True, "acknowledged": True},
                        f"The direct {kind} file was not held at the PHP layer")
                self.route_holds[LEGACY][kind]["fpm_acknowledged"] += 1

    def replay_routes(self):
        for host in VHOSTS:
            require(self.http(host=host)["engine"] == "sqlite", "The restored host no longer reads SQLite")
            for kind, method, path, body in WRITING_ROUTES:
                receipt = "synthetic-quote-view" if kind == "write_on_read" else "synthetic-resend-event"
                expected = {"receipt": receipt, "host": host}
                require(self.http(method, host=host, path=path, body=body) == expected,
                        f"The restored {host} {kind} replay differed")
        for kind, method, path, body in DIRECT_ROUTES:
            receipt = "synthetic-cubicasa-direct" if kind == "cubicasa_direct" else "synthetic-iguide-direct"
            require(self.http(method, host=LEGACY, path=path, body=body) == {"receipt": receipt, "host": LEGACY},
                    f"The restored direct {kind} replay differed")
        effects = sorted(row[0] for row in sqlite_rows(SOURCE)["fixture_effects"])
        expected = sorted(["synthetic-quote-view", "synthetic-resend-event", "synthetic-cubicasa-direct",
                           "synthetic-iguide-direct", "synthetic-slow-write"] + self.queue_effects())
        require(effects == expected, f"SQLite lost or duplicated an ingress effect: {effects}")
        require(self.rows_mysql() == self.imported, "Reopened route writes reached the sealed MySQL target")

    def admitted_effects(self):
        return ["synthetic-slow-write"] if self.slow is not None else []

    def queue_effects(self):
        return ["synthetic-queue-effect"] if getattr(self, "queue_drained", False) else []

    def http(self, method="GET", code=200, *, host=VHOSTS[0], path=SMOKE_PATH, body=None):
        with closing(NginxConnection(self.nginx.pid, host)) as client:
            headers = {"Content-Type": "application/json"} if body is not None else {}
            client.request(method, path, body, headers)
            response = client.getresponse()
            require(response.status == code, f"Unexpected private nginx status {response.status} for {host}{path}")
            forwarded = response.getheader("X-Fixture-Upstream") == f"unix:{FPM_SOCKET}"
            if code == 503 and not INGRESS_OPEN.is_file():
                require(not forwarded, "Held request reached PHP-FPM")
                require(response.getheader("Retry-After") == "30", "The edge 503 did not tell the producer when to retry")
                if path == SMOKE_PATH:
                    self.edge_holds += 1
            else:
                require(forwarded, "The private nginx probe did not reach FastCGI")
            body = response.read(64 * 1024 + 1)
            require(len(body) <= 64 * 1024, "Oversized private nginx response")
            return json.loads(body)

    def reconnect_admin(self):
        try:
            self.admin.close()
        except Exception:
            pass
        self.admin = self.mysql("root", "", None)


def matrix(adapter):
    return {
        "fpm_worker_cohorts_drained_during_restarts": adapter.worker_cohorts_drained,
        "nginx_503_without_upstream": adapter.edge_holds + sum(
            counts.get("nginx", 0) for host in adapter.route_holds.values() for counts in host.values()),
        "route_503s_by_host_and_layer": adapter.route_holds,
        "fastcgi_harness_sha256": file_digest(Path(__file__)),
        "production_hold_include_sha256": file_digest(PRODUCTION_HOLD),
        "php_fpm_sha256": file_digest(FPM),
        "nginx_sha256": file_digest(NGINX),
    }


def require_matrix(adapter):
    for host, routes in adapter.route_holds.items():
        for kind, counts in routes.items():
            require(all(count > 0 for count in counts.values()),
                    f"The {host} {kind} route was not exercised through every hold layer: {counts}")


def exercise_hold_first(adapter):
    """Hold first with an already-admitted slow writer, then drain, freeze, prepare and abort."""
    report = exercise(adapter)
    adapter.queue_drained = True
    require(adapter.held_while_inflight is True, "The hold did not land while the admitted writer was in flight")
    require(adapter.edge_holds >= 3, "Expected each closed nginx edge probe to be verified")
    require_matrix(adapter)
    adapter.replay_routes()
    report.update(matrix(adapter))
    report.update({
        "scope": "synthetic journal gate on private nginx/PHP-FPM Unix sockets and one-shot Laravel queue",
        "order": ["hold", "close_admissions", "drain", "freeze", "prepare", "abort", "reopen"],
        "admitted_writer_in_flight_at_hold": True, "slow_write_effects_in_snapshot": 1,
        "replayed_sqlite_effects": {"write_on_read": 1, "webhook": 1, "cubicasa_direct": 1, "iguide_direct": 1},
    })
    return report


def exercise_recovery(adapter):
    """Kill the runner mid-drain, restart nginx and FPM while held, then let the watchdog roll back."""
    runner = Runner(adapter.journal, adapter.identity(), adapter)
    require(runner.initialize()["phase"] == "planned", "Runner did not start planned")
    adapter.install_planned_proof()
    require(set(adapter.http("POST")["receipts"]) == {"synthetic-http-receipt"}, "Synthetic write not acknowledged")
    adapter.baseline = adapter.http()
    adapter.before_hold()
    held = runner.perform("hold", runner.approval_request("hold"))
    hold_at = time.monotonic()
    require(held["phase"] == "holding" and adapter.held_while_inflight is True, "The hold did not land first")

    # A second runner process starts the freeze and is killed while it drains.
    child = os.fork()
    if child == 0:
        try:
            adapter.require_quiescent = lambda: None  # The parent owns the HTTP processes.
            Runner(adapter.journal, adapter.identity(), adapter).perform("freeze")
        finally:
            os._exit(0)
    for _ in range(300):
        state = runner.status()
        if state["pending"] is not None and state["pending"]["action"] == "freeze":
            break
        time.sleep(0.01)
    else:
        raise UnsafeCutover("The child runner never journaled its freeze")
    time.sleep(0.2)
    os.kill(child, signal.SIGKILL)
    _, status = os.waitpid(child, 0)
    require(os.WIFSIGNALED(status) and os.WTERMSIG(status) == signal.SIGKILL, "The runner was not killed mid-drain")
    killed = runner.status()
    require(killed["phase"] == "holding" and killed["pending"]["action"] == "freeze" and "drain" not in killed,
            "The killed runner did not leave an interrupted drain")
    adapter.reconnect_admin()

    # Real nginx and FPM restarts while held; graceful stops let the slow writer finish.
    restarts = 0
    for _ in range(2):
        adapter.restart_and_check_hold("sqlite")
        restarts += 1
    adapter.finish_slow_write()
    # A stale permissive launch record cannot reopen the held journal after a restart.
    adapter.set_gate("sqlite", True)
    try:
        adapter.restart_and_check_hold("sqlite")
        restarts += 1
    finally:
        adapter.set_gate("sqlite", False)
    require(sqlite_rows(SOURCE)["fixture_effects"].count(("synthetic-slow-write",)) == 1,
            "The in-flight writer's effect was lost or duplicated")

    # Before the deadline the watchdog keeps the hold; at 45 minutes from the hold it rolls back.
    require(Runner(adapter.journal, adapter.identity(), adapter).perform("tick")["phase"] == "holding",
            "An early watchdog tick changed the held journal")
    adapter.assert_writer_hold()
    late = Runner(adapter.journal, adapter.identity(), adapter, lambda: time.time() + Runner.DECISION_SECONDS)
    recovered = late.perform("tick")
    require(recovered["phase"] == "sqlite_authoritative"
            and [entry["phase"] for entry in recovered["history"]] == ["holding", "interrupted", "sqlite_authoritative"],
            "The watchdog did not roll back to SQLite authority")
    rolled_back = time.monotonic()
    adapter.reopen()
    adapter.queue_drained = False
    require_matrix(adapter)
    adapter.replay_routes()
    report = matrix(adapter)
    report.update({
        "result": "hold-recovery-rehearsal-passed", "release": adapter.identity().release_sha,
        "scope": "synthetic journal gate on private nginx/PHP-FPM; runner killed with SIGKILL during the writer drain",
        "runner_killed_mid_drain": True, "restarts_while_held": restarts, "early_tick_kept_hold": True,
        "watchdog_rollback": recovered["phase"], "hold_to_watchdog_rollback_ms": round((rolled_back - hold_at) * 1000),
        "slow_write_effects": 1, "mysql_business_writes": 0, "authority": runner.status()["phase"],
        "harness_sha256": file_digest(Path("/host/rehearse.py")), "php_probe_sha256": file_digest(Path("/host/probe.php")),
        "unproven": ["production nginx and FPM services", "systemd watchdog timer", "host reboot", "real workloads"],
    })
    return report


def main() -> None:
    mode = sys.argv[3] if len(sys.argv) > 3 else "prewrite-fastcgi"
    require(mode in {"prewrite-fastcgi", "prewrite-fastcgi-recovery"}, "Unknown FastCGI scenario")
    with closing(FastCgiRehearsal(load_pymysql(), *sys.argv[1:3])) as adapter:
        report = exercise_recovery(adapter) if mode.endswith("recovery") else exercise_hold_first(adapter)
    print(json.dumps(report, sort_keys=True))


if __name__ == "__main__":
    main()
