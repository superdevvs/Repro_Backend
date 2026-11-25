"""Run inside the disposable systemd container as root. Synthetic data only; no network.

Applies the installer, proves the sandboxed WAL read with the real producer,
drives watchdog failure -> OnFailure -> bridge -> loopback HTTPS, checks that no
ping URL reaches the journal, checks network isolation, then undoes both jobs.
"""
import json
import os
from pathlib import Path
import secrets
import shutil
import socket
import sqlite3
import subprocess
import sys
import time

SOURCE = Path("/bundle-src/scripts")
BUNDLE = Path("/opt/repro-bundle/scripts")
INSTALLER = BUNDLE / "mysql/offsite/install_backup_host.py"
APP = Path("/var/www/backend")
DATABASE = APP / "database/database.sqlite"
TOKEN = secrets.token_hex(12)
PORT = 9443
SEED_ROWS, WAL_ROWS = 5, 40
report = {"version": 1, "provider_contacted": False, "network": "none", "checks": {}}


def run(argv, check=True, **kwargs):
    result = subprocess.run(argv, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=180, **kwargs)
    if check and result.returncode != 0:
        raise RuntimeError(f"{argv[:3]} exited {result.returncode}: {result.stdout.decode()[-1500:]}")
    return result


def record(name, ok, detail=None):
    report["checks"][name] = {"ok": bool(ok)} | ({"detail": detail} if detail is not None else {})
    if not ok:
        raise RuntimeError(f"check failed: {name}: {detail}")


def acl_snapshot(paths):
    snapshot = {}
    for path in paths:
        for name in ("system.posix_acl_access", "system.posix_acl_default"):
            try:
                snapshot[f"{path}|{name}"] = os.getxattr(path, name).hex()
            except OSError:
                snapshot[f"{path}|{name}"] = None
    return snapshot


def wait_for(predicate, seconds=30):
    end = time.monotonic() + seconds
    while time.monotonic() < end:
        if predicate():
            return True
        time.sleep(0.3)
    return False


def prepare_bundle():
    shutil.copytree(SOURCE, BUNDLE)
    for root, dirs, files in os.walk("/opt/repro-bundle"):
        os.chown(root, 0, 0)
        os.chmod(root, 0o755)
        for name in files:
            os.chown(os.path.join(root, name), 0, 0)
            os.chmod(os.path.join(root, name), 0o644)


def prepare_app():
    run(["useradd", "--uid", "1000", "--gid", "www-data", "--no-create-home", "maverick"])
    for folder, mode in ((APP, 0o750), (APP / "database", 0o775), (APP / "storage", 0o775),
                         (APP / "storage/logs", 0o775), (APP / "storage/framework", 0o775),
                         (APP / "storage/framework/cache", 0o775), (APP / "storage/framework/cache/data", 0o775),
                         (APP / "storage/framework/cache/data/ab", 0o775)):
        folder.mkdir(exist_ok=True)
        os.chown(folder, 1000, 33)
        os.chmod(folder, mode)
    # A deploy leaves vendor world-readable; the shared test volume is root-only, so copy it.
    shutil.copytree("/vendor-src", APP / "vendor", symlinks=True)
    for root, dirs, files in os.walk(APP / "vendor"):
        os.chown(root, 1000, 33)
        os.chmod(root, 0o755)
        for name in files:
            path = os.path.join(root, name)
            if not os.path.islink(path):
                os.chown(path, 1000, 33)
                os.chmod(path, 0o644)
    key = "base64:" + __import__("base64").b64encode(secrets.token_bytes(32)).decode()
    env = APP / ".env"
    env.write_text(f"APP_KEY={key}\nDB_CONNECTION=sqlite\nDB_DATABASE={DATABASE}\n")
    os.chown(env, 1000, 33)
    os.chmod(env, 0o640)
    log = APP / "storage/logs/laravel.log"
    log.write_text("")
    os.chown(log, 33, 33)
    os.chmod(log, 0o664)
    Path("/var/log/nginx").mkdir(parents=True, exist_ok=True)
    access = Path("/var/log/nginx/access.log")
    access.write_text("")
    os.chown(access, 0, 4)
    os.chmod(access, 0o640)
    writer = Path("/usr/local/lib/fixture-writer.py")
    writer.write_text(f"""import sqlite3, time
db = sqlite3.connect({str(DATABASE)!r}, isolation_level=None)
db.execute("PRAGMA journal_mode=WAL")
db.execute("PRAGMA wal_autocheckpoint=0")
db.execute("CREATE TABLE IF NOT EXISTS items (id INTEGER PRIMARY KEY, note TEXT)")
db.execute("CREATE TABLE IF NOT EXISTS jobs (id INTEGER PRIMARY KEY, queue TEXT, available_at INTEGER, reserved_at INTEGER, created_at INTEGER)")
for i in range({SEED_ROWS}):
    db.execute("INSERT INTO items (note) VALUES (?)", ("seed",))
db.execute("PRAGMA wal_checkpoint(TRUNCATE)")
for i in range({WAL_ROWS}):
    db.execute("INSERT INTO items (note) VALUES (?)", ("committed in WAL only",))
open({str(APP / 'database/.writer-ready')!r}, "w").write("ready")
time.sleep(3600)
""")
    run(["systemd-run", "--unit", "offsite-fixture-writer", "-p", "User=maverick", "-p", "Group=www-data",
         "-p", "UMask=0002", "/usr/bin/python3", str(writer)])
    record("writer_holds_wal_open", wait_for(lambda: (APP / "database/.writer-ready").exists())
           and (APP / "database/database.sqlite-wal").stat().st_size > 0)


def settings():
    path = Path("/root/settings.json")
    path.write_text(json.dumps({"version": 1, "release": "a" * 40, "identity": "fixture-sqlite-lineage",
                                "bucket": "fixture-bucket", "endpoint": "https://" + "0" * 32 + ".r2.cloudflarestorage.com",
                                "php": "/usr/local/bin/php"}))
    return path


def installer(*args, user=None):
    argv = ["/usr/bin/python3", "-I", "-B", str(INSTALLER), *args]
    if user:
        argv = ["runuser", "-u", user, "--", *argv]
    return run(argv, check=False)


def mock_server():
    tls = Path("/root/tls")
    tls.mkdir()
    run(["openssl", "req", "-x509", "-nodes", "-days", "1", "-newkey", "rsa:2048", "-subj", "/CN=127.0.0.1",
         "-addext", "subjectAltName=IP:127.0.0.1", "-keyout", str(tls / "key.pem"), "-out", str(tls / "cert.pem")])
    with open("/etc/ssl/certs/ca-certificates.crt", "a") as bundle:
        bundle.write((tls / "cert.pem").read_text())
    script = Path("/root/mock.py")
    script.write_text(f"""import http.server, ssl
class H(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        open("/root/mock-paths.log", "a").write(self.path + "\\n")
        self.send_response(200); self.end_headers(); self.wfile.write(b"OK")
    def log_message(self, *a): pass
s = http.server.ThreadingHTTPServer(("127.0.0.1", {PORT}), H)
c = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); c.load_cert_chain("/root/tls/cert.pem", "/root/tls/key.pem")
s.socket = c.wrap_socket(s.socket, server_side=True)
s.serve_forever()
""")
    run(["systemd-run", "--unit", "offsite-mock-https", "/usr/bin/python3", str(script)])
    record("mock_https_listening", wait_for(lambda: connect_ok()))


def connect_ok():
    try:
        socket.create_connection(("127.0.0.1", PORT), timeout=1).close()
        return True
    except OSError:
        return False


def pings():
    path = Path("/root/mock-paths.log")
    return path.read_text().split() if path.exists() else []


def main():
    started = time.monotonic()
    report["systemd"] = run(["systemctl", "--version"]).stdout.decode().splitlines()[0]
    prepare_bundle()
    prepare_app()
    watched = [str(APP), str(APP / ".env"), str(APP / "database"), str(DATABASE), str(DATABASE) + "-wal",
               str(DATABASE) + "-shm", str(APP / "storage/logs"), str(APP / "storage/logs/laravel.log")]
    before = acl_snapshot(watched)
    modes_before = {path: oct(os.lstat(path).st_mode) for path in watched if os.path.exists(path)}
    config = settings()
    shutil.copy(config, "/tmp/settings.json")
    os.chmod("/tmp/settings.json", 0o644)

    preview = installer("--job", "base-backup", "--settings", "/tmp/settings.json", user="maverick")
    text = preview.stdout.decode()
    record("preview_as_deploy_user_changes_nothing",
           preview.returncode == 0 and "No changes were made." in text and not Path("/opt/repro-backup-tools").exists()
           and run(["id", "repro-backup"], check=False).returncode != 0 and acl_snapshot(watched) == before, text[-800:])
    hidden = installer("--job", "base-backup", "--settings", "/tmp/settings.json", user="nobody")
    record("preview_as_nobody_reports_hidden_paths", hidden.returncode == 0
           and "not visible to this user" in hidden.stdout.decode(), hidden.stdout.decode()[-400:])
    record("preview_lists_traverse_grant_for_0750_app_root", f"access  user:repro-backup:--x {APP}" in text)

    applied = installer("--job", "base-backup", "--settings", str(config), "--apply", "--skip-credentials")
    out = applied.stdout.decode()
    report["base_apply_output_tail"] = out.splitlines()[-8:]
    record("base_apply_self_tests_pass", applied.returncode == 0 and "FAIL" not in out, out[-1200:])
    record("sandboxed_read_self_test_ran",
           "PASS repro-backup reads vendor, .env and the WAL database inside the unit sandbox" in out)

    batch = Path("/var/lib/repro-backup/fixture-batch")
    run(["runuser", "-u", "repro-backup", "--", "mkdir", "-m", "0700", str(batch)])
    produced = run(["systemd-run", "--wait", "--pipe", "--quiet", "--collect", "-p", "User=repro-backup",
                    "-p", "Group=repro-backup", "-p", "ProtectSystem=strict", "-p", "ProtectHome=read-only",
                    "-p", "PrivateNetwork=yes", "-p", "PrivateTmp=yes", "-p", "UMask=0077",
                    "-p", "ReadWritePaths=/var/lib/repro-backup", "/usr/local/bin/php",
                    "/opt/repro-backup-tools/backup-database.php", str(APP), str(batch)], check=False)
    copy = batch / "database.sqlite"
    rows = sqlite3.connect(f"file:{copy}?mode=ro", uri=True).execute("SELECT COUNT(*) FROM items").fetchone()[0] \
        if copy.exists() else None
    record("producer_in_unit_sandbox_copies_committed_wal_rows", produced.returncode == 0 and rows == SEED_ROWS + WAL_ROWS,
           {"exit": produced.returncode, "rows": rows, "expected": SEED_ROWS + WAL_ROWS})
    shutil.rmtree(batch)

    applied = installer("--job", "alerts", "--settings", str(config), "--apply", "--skip-credentials")
    out = applied.stdout.decode()
    record("alerts_apply_self_tests_pass", applied.returncode == 0 and "FAIL" not in out, out[-1200:])
    mock_server()
    checks = {name: f"https://127.0.0.1:{PORT}/ping/{TOKEN}-{name}" for name in
              ("server_silent", "backup_stale", "backup_failed", "database_down", "wrong_database_engine",
               "disk_nearly_full", "server_error_rate", "queue_stale", "cache_write_failed")}
    urls = Path("/var/lib/repro-alert/ping-urls.json")
    urls.write_text(json.dumps({"version": 1, "checks": checks}))
    os.chown(urls, int(run(["id", "-u", "repro-alert"]).stdout), int(run(["id", "-g", "repro-alert"]).stdout))
    os.chmod(urls, 0o600)

    run(["systemctl", "start", "repro-backup-watchdog.service"], check=False)
    record("watchdog_failure_reaches_provider_as_fail_ping",
           wait_for(lambda: f"/ping/{TOKEN}-backup_stale/fail" in pings()), pings())
    record("journal_marker_still_written", b"REPRO_BACKUP_ALERT" in run(
        ["journalctl", "-u", "repro-backup-alert.service", "-o", "cat", "--no-pager"]).stdout)
    run(["systemctl", "start", "repro-alert-heartbeat.service"])
    record("server_alive_ping_delivered", wait_for(lambda: f"/ping/{TOKEN}-server_silent" in pings()), pings())
    probe_run = run(["systemctl", "start", "repro-alert-probe.service"], check=False)
    probe_log = run(["journalctl", "-u", "repro-alert-probe.service", "-o", "cat", "--no-pager"]).stdout.decode()
    record("probe_unit_writes_statuses", probe_run.returncode == 0, probe_log[-800:])
    run(["systemctl", "start", "repro-alert-forward.service"], check=False)
    expected = {f"/ping/{TOKEN}-disk_nearly_full/fail"} | {f"/ping/{TOKEN}-{name}" for name in
               ("database_down", "wrong_database_engine", "server_error_rate", "queue_stale", "cache_write_failed")}
    record("detector_statuses_relayed", wait_for(lambda: expected <= set(pings())), sorted(set(pings()) - expected))
    journal = run(["journalctl", "-o", "cat", "--no-pager"]).stdout
    inbox = b"".join(path.read_bytes() for path in Path("/var/lib/repro-alert-inbox").iterdir())
    record("no_ping_url_in_journal_or_inbox", TOKEN.encode() not in journal and TOKEN.encode() not in inbox)

    networks = {}
    for unit in ("repro-backup-watchdog.service", "repro-backup-alert.service", "repro-alert-probe.service",
                 "repro-alert-backup-stale.service", "repro-alert-heartbeat.service", "repro-alert-forward.service"):
        networks[unit] = run(["systemctl", "show", "-p", "PrivateNetwork", "--value", unit]).stdout.decode().strip()
    record("network_only_for_bridge_units", networks == {
        "repro-backup-watchdog.service": "yes", "repro-backup-alert.service": "yes", "repro-alert-probe.service": "yes",
        "repro-alert-backup-stale.service": "no", "repro-alert-heartbeat.service": "no",
        "repro-alert-forward.service": "no"}, networks)
    probe = ["/usr/bin/python3", "-c", f"import socket; socket.create_connection(('127.0.0.1', {PORT}), timeout=2)"]
    isolated = run(["systemd-run", "--wait", "--pipe", "--quiet", "--collect", "-p", "PrivateNetwork=yes", *probe], check=False)
    shared = run(["systemd-run", "--wait", "--pipe", "--quiet", "--collect", *probe], check=False)
    record("private_network_blocks_the_loopback_provider", isolated.returncode != 0 and shared.returncode == 0)

    # Observation: every app connection closed, so SQLite removed -wal and -shm. Can the sandbox still read?
    run(["systemctl", "stop", "offsite-fixture-writer.service"], check=False)
    run(["runuser", "-u", "maverick", "--", "/usr/bin/python3", "-c",
         f"import sqlite3; c = sqlite3.connect({str(DATABASE)!r}); c.execute('PRAGMA wal_checkpoint(TRUNCATE)'); c.close()"])
    for suffix in ("-wal", "-shm"):  # No connection is open and the WAL is checkpointed and empty.
        Path(str(DATABASE) + suffix).unlink(missing_ok=True)
    batch.parent.joinpath("fixture-closed").mkdir(mode=0o700)
    shutil.chown(batch.parent / "fixture-closed", "repro-backup", "repro-backup")
    closed = run(["systemd-run", "--wait", "--pipe", "--quiet", "--collect", "-p", "User=repro-backup",
                  "-p", "Group=repro-backup", "-p", "ProtectSystem=strict", "-p", "ProtectHome=read-only",
                  "-p", "PrivateNetwork=yes", "-p", "PrivateTmp=yes", "-p", "UMask=0077",
                  "-p", "ReadWritePaths=/var/lib/repro-backup", "/usr/local/bin/php",
                  "/opt/repro-backup-tools/backup-database.php", str(APP), str(batch.parent / "fixture-closed")], check=False)
    observed = {"wal_present": Path(str(DATABASE) + "-wal").exists(), "shm_present": Path(str(DATABASE) + "-shm").exists(),
                "exit": closed.returncode, "copy_present": (batch.parent / "fixture-closed/database.sqlite").exists()}
    # Known limit: with no -shm on disk the read-only sandbox cannot create one. The run must fail loudly.
    record("producer_without_open_wal_fails_loudly", observed == {"wal_present": False, "shm_present": False,
                                                                   "exit": 1, "copy_present": False}, observed)
    shutil.rmtree(batch.parent / "fixture-closed")

    for job in ("alerts", "base-backup"):
        undone = installer("--undo", "--job", job)
        record(f"undo_{job}", undone.returncode == 0 and "FAIL" not in undone.stdout.decode(), undone.stdout.decode()[-600:])
    after = acl_snapshot([path for path in watched if os.path.exists(path)])
    record("acl_bytes_restored", all(after[key] == before[key] for key in after), {k: (before[k], after[k])
           for k in after if after[k] != before[k]})
    modes_after = {path: oct(os.lstat(path).st_mode) for path in watched if os.path.exists(path)}
    record("modes_restored", all(modes_after[key] == modes_before.get(key, modes_after[key]) for key in modes_after),
           {key: (modes_before.get(key), value) for key, value in modes_after.items() if modes_before.get(key) != value})
    leftovers = [path for path in ("/opt/repro-backup-tools", "/etc/repro-backup", "/etc/repro-alert", "/var/lib/repro-backup",
                                   "/var/lib/repro-alert", "/var/lib/repro-alert-inbox", "/var/lib/repro-alert-probe",
                                   "/var/lib/repro-backup-installer",
                                   "/etc/systemd/system/repro-backup.service", "/etc/systemd/system/repro-alert-heartbeat.timer")
                 if os.path.lexists(path)]
    accounts = [name for name in ("repro-backup", "repro-alert") if run(["id", name], check=False).returncode == 0]
    record("undo_leaves_no_files_or_accounts", not leftovers and not accounts, {"files": leftovers, "accounts": accounts})
    report["elapsed_seconds"] = round(time.monotonic() - started, 1)
    report["result"] = "passed"


if __name__ == "__main__":
    try:
        main()
    except Exception as error:  # noqa: BLE001 - the report must say what failed
        report["result"] = "failed"
        report["error"] = str(error)[:2000]
    print(json.dumps(report, indent=1, sort_keys=True))
    sys.exit(0 if report.get("result") == "passed" else 1)
