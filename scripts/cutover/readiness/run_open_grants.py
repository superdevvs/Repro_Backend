"""C10. Called by run-probe-fixture-tests.sh --open-grants in a fresh socket-only fixture."""
import base64
import hashlib
import json
import os
from pathlib import Path
import pwd
import shutil
import subprocess
import sys

SOURCE = Path("/src")
PRODUCTION = SOURCE / "scripts/cutover/production"
FIXTURE = Path("/fixture/socket")
sys.path[:0] = [str(PRODUCTION.parent), str(PRODUCTION), str(PRODUCTION / "e2e")]
from adapter import guard, load_config
import gate
from run_drill import locked_vendor_identity
from test_mysql_probe import CLOSED_GRANTS, Fixture, ProbeHost


def require(value, message):
    if not value:
        raise RuntimeError(message)


def run():
    require(Path("/.dockerenv").is_file() and os.geteuid() == 0 and (FIXTURE / ".repro-mysql-fixture").is_file(),
            "requires the isolated fixture launcher")
    require({p.name for p in Path("/sys/class/net").iterdir()} == {"lo"}, "unexpected network interface")
    require((FIXTURE / "variant").read_text().strip() == "socket-only", "unexpected fixture variant")
    account_hash = hashlib.sha256((PRODUCTION / "mysql/accounts.sql").read_bytes()).hexdigest()
    require((FIXTURE / "accounts.sha256").read_text().strip() == account_hash, "accounts.sql digest differs")
    fixture = Fixture(str(FIXTURE))
    target = fixture.sql("SELECT @@server_uuid, @@version, @@skip_networking, CURRENT_USER()", "repro_app").strip()
    require(target == f"{fixture.uuid}\t8.4.13\t1\trepro_app@localhost", "unexpected MySQL target or identity")
    require(fixture.sql("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='repro_dashboard'").strip()
            == "0", "repro_dashboard must be empty")
    require(fixture.snapshot()["grants"] == sorted(CLOSED_GRANTS), "unexpected initial application grants")
    print("fixture", fixture.uuid, "accounts_sha256", account_hash, "vendor",
          json.dumps(locked_vendor_identity(SOURCE / "composer.lock", SOURCE / "vendor/composer/installed.json")),
          flush=True)
    # Schema setup runs with the maintenance account. The workflow process receives only the app credential.
    # The schema's triggers are defined by repro_maint while binary logging is active, as in the A3 loader.
    original = int(fixture.sql("SELECT @@GLOBAL.log_bin_trust_function_creators"))
    fixture.sql("SET GLOBAL log_bin_trust_function_creators=ON")
    try:
        with (SOURCE / "database/schema/mysql-schema.sql").open("rb") as schema:
            subprocess.run(["/usr/local/bin/mysql", f"--defaults-file={FIXTURE}/repro_maint.cnf", "--no-login-paths",
                            "--protocol=SOCKET", f"--socket={fixture.socket}", "repro_dashboard"], stdin=schema,
                           check=True, timeout=300)
    finally:
        fixture.sql(f"SET GLOBAL log_bin_trust_function_creators={original}")
    host = ProbeHost(fixture)
    try:
        host.config["mysql"].update(mode="cutover", gate_defaults=str(host.etc / "mysql-gate.cnf"))
        shutil.copyfile(FIXTURE / "repro_cutover_gate.cnf", host.config["mysql"]["gate_defaults"])
        os.chmod(host.config["mysql"]["gate_defaults"], 0o600)
        _, closed, error = host.run("writes_possible")
        require(isinstance(closed, dict) and closed.get("gate") == "closed", f"fixture readback failed: {error}")
        config = load_config(guard.read_protected(host.etc / "host.json"), read=guard.read_protected)
        try:
            require(gate.open_gate(config)["granted"], "gate did not open from closed")
            run_application(fixture)
        finally:
            gate.close_gate(config)
            require(fixture.snapshot()["grants"] == sorted(CLOSED_GRANTS), "gate failed to restore original grants")
    finally:
        shutil.rmtree(host.root)
    print("C10: application workflows passed and closed grants restored", flush=True)


def run_application(fixture):
    web = pwd.getpwnam("www-data")
    for target in ("bootstrap/cache", "storage/framework/cache/data", "storage/framework/sessions",
                   "storage/framework/views", "storage/framework/testing", "storage/logs/scheduling",
                   "storage/app/public", "storage/app/private"):
        path = SOURCE / target
        path.mkdir(parents=True, exist_ok=True)
        for parent in (path, *path.parents):
            if parent in (SOURCE / "bootstrap", SOURCE):
                break
            os.chown(parent, web.pw_uid, web.pw_gid)
    env = {"PATH": "/usr/local/bin:/usr/bin:/bin", "HOME": "/tmp", "APP_ENV": "testing",
           "APP_KEY": "base64:" + base64.b64encode(os.urandom(32)).decode("ascii"), "DB_CONNECTION": "mysql",
           "DB_DATABASE": "repro_dashboard", "DB_USERNAME": "repro_app", "DB_SOCKET": fixture.socket,
           "DB_PASSWORD": (FIXTURE / "passwords/app").read_text().strip(), "DB_COLLATION": "utf8mb4_0900_bin",
           "REPRO_MYSQL_EXPECTED_SERVER_UUID": fixture.uuid, "REPRO_MYSQL_WRITER_POLICY": "true",
           "CACHE_STORE": "array", "QUEUE_CONNECTION": "sync", "SESSION_DRIVER": "array", "MAIL_MAILER": "array",
           "BCRYPT_ROUNDS": "4", "PULSE_ENABLED": "false", "TELESCOPE_ENABLED": "false", "VOICE_LOCK_STORE": "array",
           "VOICE_CACHE_STORE": "array", "PHOTOGRAPHER_RADIUS_ENFORCEMENT": "false"}
    subprocess.run(["php", "vendor/bin/phpunit", "-c", "scripts/cutover/readiness/open-grants/phpunit.xml",
                    "--no-progress", "--do-not-cache-result", "--fail-on-empty-test-suite", "--fail-on-skipped",
                    "--fail-on-incomplete", "--fail-on-risky", "--fail-on-warning"], cwd=SOURCE, env=env,
                   user=web.pw_uid, group=web.pw_gid, extra_groups=[],
                   check=True, timeout=1200)


if __name__ == "__main__":
    run()
