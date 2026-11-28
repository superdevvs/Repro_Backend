#!/usr/bin/env python3
"""Local C11 integration drill. Run e2e/run-drill.sh --w2c [--case short|export].

Two disconnected disposable cases exercise the actual cutover and recovery CLIs.
The encrypted remote is represented by an in-memory readback transport. A W2c
result is evidence about the local controller, never an off-site or host receipt.
"""
import argparse
import datetime as dt
import hashlib
import json
from pathlib import Path
import stat
import shutil
import sqlite3
import subprocess
import sys
import time

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE / "e2e" if (HERE / "e2e").is_dir() else HERE.parent / "e2e"))
import run_drill as drill

SCRIPT = drill.SANDBOX / "w2c.py"
SOURCE = Path("/src")
FIXTURE = Path("/fixture")
SOCKET = str(FIXTURE / drill.FIXTURE_SOCKET)
CASES = {"short": 1, "export": 2}
NATIVE_TOOLS = {
    "bin/mysqlbinlog": "1087ee9d26ea42f396a1a7f302eb2cc549b2e05c32c62f8125f9d1b40bc08048",
    "lib/private/libssl.so.3": "78ae74e4cc70d4e19478065ee00c5b1864cf8528c725eb6230ce0e6ec0d9feb5",
    "lib/private/libcrypto.so.3": "a087e2cafe703f8417ded60f57949dfde4b0ec99a112a9b421a3ca44c7cea514",
}
require = drill.require


def record(path, value):
    drill.write(path, json.dumps(value, sort_keys=True) + "\n", 0o600)


def now():
    return dt.datetime.now(dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def coordinate_hash(value):
    return hashlib.sha256(json.dumps(value, sort_keys=True, separators=(",", ":")).encode("ascii")).hexdigest()


class Outer(drill.Outer):
    mode = "cutover"

    def prepare_container(self):
        super().prepare_container()
        for name, digest in NATIVE_TOOLS.items():
            target = Path("/usr/local") / name
            if not target.exists():
                source = Path("/w2c-tools") / name
                require(source.is_file() and drill.sha256_file(source) == digest,
                        f"Supply the pinned {name} in REPRO_W2C_MYSQL_TOOLS")
                target.parent.mkdir(parents=True, exist_ok=True)
                drill.write(target, source.read_bytes(), 0o755 if name.startswith("bin/") else 0o644)
            require(not target.is_symlink() and target.stat().st_uid == 0
                    and not target.stat().st_mode & 0o022 and drill.sha256_file(target) == digest,
                    f"Unexpected installed native decoder component {name}")
        version = drill.run(["/usr/local/bin/mysqlbinlog", "--version"]).stdout.decode()
        require("8.4.13" in version, "Native row decoder version differs")

    def controller_copy(self, paths):
        super().controller_copy(paths)
        target = paths.root / "binds/opt/repro-cutover-fence/production"
        drill.write(target / "verify-fields.php", (self.src / "scripts/mysql/verify-fields.php").read_bytes())

    def make_config(self, n, measured):
        names = super().make_config(n, measured)
        # The independent PHP reader requires the server's @@socket, including its
        # exact spelling. Use the fixture path consistently in both the app and policy.
        paths = drill.Paths(drill.root_for(n, self.srv))
        path = paths.etc / "host.json"
        host = drill.read_json(path)
        host["mysql"]["socket"] = SOCKET
        host["mysql"]["switch"]["DB_SOCKET"] = SOCKET
        drill.write(path, json.dumps(host) + "\n", 0o644)
        return names

    def execute(self, case):
        n = CASES[case]
        root = drill.root_for(n, self.srv)
        require(not root.exists(), "W2c needs a fresh disposable volume")
        started = time.monotonic()
        for name in ("root", "config", "measure", "policy"):
            print(f"w2c {case}: {name}: {getattr(self, 'step_' + name)(n)}", flush=True)
        for stage in (("first", "reboot") if case == "export" else ("first",)):
            reboot = ["--reboot"] if stage == "reboot" else []
            command = ["/bin/sh", drill.SANDBOX / "run-sandbox.sh", *reboot, root, "--", drill.PY,
                       "-I", "-S", "-B", SCRIPT, "inside", "--root", root, "--case", case, "--stage", stage]
            result = subprocess.run(list(map(str, command)), env=drill.CLEAN_ENV, timeout=7200)
            require(result.returncode == 0, f"{case}/{stage} failed, exit {result.returncode}")
        receipt = drill.read_json(drill.Paths(root).e2e / "w2c-result.json")
        require(receipt["case"] == case and receipt["result"] == "local-recovery-verified"
                and receipt["release"] == self.args.release_sha, "Missing or stale W2c receipt")
        receipt["local_fixture_elapsed_seconds"] = round(time.monotonic() - started, 3)
        print(json.dumps(receipt, sort_keys=True), flush=True)


class Inside(drill.Inside):
    def note(self, text):
        print(f"w2c {self.args.case}/{self.args.stage}: {text}", flush=True)

    def copy_snapshot(self, night):
        require(self.phase() == "frozen", "W2c requires the final sealed snapshot")
        path = night / "copy.sqlite"
        shutil.copyfile(self.paths.sqlite, path)
        path.chmod(0o600)
        digest = drill.sha256_file(path)
        require(digest == drill.read_json(drill.SEALED_HASH)["sha256"], "Copy differs from sealed bytes")
        drill.write(night / drill.COPY_RECORD, f"{digest}  {path}\n", 0o600)
        return path, digest

    def wheel(self):
        path = Path("/opt/repro-drill-tools/pymysql-1.1.2-py3-none-any.whl")
        expected = drill.read_json(SOURCE / "scripts/cutover/nightly/nightly-config.json")["pymysql_wheel"]["sha256"]
        require(drill.sha256_file(path) == expected, "Wrong offline PyMySQL wheel")
        return str(path)

    def empty_target(self, maint, database):
        count = self.mysql(maint, "-e", f"SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='{database}'")
        require(count.stdout.strip() == b"0", "W2c requires a new MySQL fixture")
        return False

    def load_copy(self, night, maint, scripts, database, target, reset):
        snapshot, digest = self.copy_snapshot(night)
        plan = night / "plan"
        program = SOURCE / "scripts/mysql/migrate.py"
        drill.run([drill.PY, "-E", "-s", "-S", "-B", program, "plan", "--snapshot", snapshot,
                   "--snapshot-sha256", digest, "--output", plan], timeout=900)
        planned = drill.read_json(plan / "result.json")
        exceptions = plan / "exceptions-private.json"
        require(planned["source_sha256"] == digest and drill.read_json(exceptions) == [],
                "Wrong source or unexpected synthetic orphans")
        access = night / "import-credentials.json"
        record(access, {"unix_socket": SOCKET, "user": "repro_maint", "password": self.fixture_password("maint")})
        try:
            result = drill.run([drill.PY, "-s", "-S", "-B", program, "import-empty",
                                "--snapshot", snapshot, "--snapshot-sha256", digest, "--credentials", access,
                                "--database", database, "--expected-server-uuid", target,
                                "--approved-exceptions", exceptions, "--exceptions-file-sha256", drill.sha256_file(exceptions),
                                "--schema-sha256", planned["schema_sha256"], "--output", night / "import"],
                               env={**drill.CLEAN_ENV, "PYTHONPATH": self.wheel()}, timeout=1800)
        finally:
            access.unlink()
        imported = json.loads(result.stdout.decode().splitlines()[-1])
        require(imported["import_verified"] is True and imported["mysql_warning_count"] == 0,
                "The release importer did not verify a clean import")
        record(night / drill.NIGHT_FILES["import"], imported)
        verified, _ = self.verify_copy(night, plan, snapshot, digest, scripts, database, target)
        return f"{verified['tables']} tables and {verified['fields']} fields verified on MySQL"

    def publications(self, frozen_at):
        cutover = SOURCE / "scripts/cutover"
        sys.path.insert(0, str(cutover))
        import final_backup_check as gate
        from test_publish_backup import FixtureRemote

        out = drill.private_dir(self.paths.e2e / "base-publications")
        night = self.paths.e2e / "night"
        mysql = self.host["mysql"]
        identity = self.policy["identity"]
        digest = drill.sha256_file(night / "frozen.sqlite")
        bases = {}
        for engine, lineage in (("sqlite", identity["source_sha256"]), ("mysql", mysql["target_uuid"])):
            directory = drill.private_dir(out / engine)
            if engine == "sqlite":
                database = directory / "database.sqlite"
                shutil.copyfile(night / "frozen.sqlite", database)
                database.chmod(0o600)
                with sqlite3.connect(f"file:{database}?mode=ro&immutable=1", uri=True) as db:
                    count = db.execute("select count(*) from sqlite_master where type='table' and "
                                       "name not like 'sqlite_%'").fetchone()[0]
                record(directory / "database-backup.json", {"format_version": 1, "driver": "sqlite",
                       "created_utc": now(), "application_booted": False, "tables": count,
                       "file": database.name, "bytes": database.stat().st_size, "sha256": digest})
                preparer, extra = gate.sqlite_base, {}
            else:
                # The producer reads the private temporary app configuration without booting Laravel.
                config = drill.private_dir(out / "dump-config")
                (config / "vendor").symlink_to(self.app / "vendor", target_is_directory=True)
                drill.write(config / ".env", f"DB_CONNECTION=mysql\nDB_DATABASE={mysql['database']}\nDB_SOCKET={SOCKET}\n", 0o600)
                drill.run(["/usr/bin/php", SOURCE / "scripts/deploy/backup-database.php", config, directory,
                           "--pitr", "--expected-server-uuid", lineage], env={**drill.CLEAN_ENV,
                           "REPRO_MYSQL_BACKUP_DEFAULTS_FILE": str(FIXTURE / "root.cnf"),
                           "REPRO_MYSQL_CLIENT": "/usr/local/bin/mysql", "REPRO_MYSQLDUMP": "/usr/local/bin/mysqldump"})
                preparer, extra = gate.mysql_base, {"expected_database": mysql["database"]}
            producer = directory / "database-backup.json"
            preparer.prepare(directory, producer_sha256=drill.sha256_file(producer),
                             release=identity["release_sha"], identity=lineage, **extra)
            raw = (directory / "input-manifest.json").read_bytes()
            remote = f"w2c-{engine}-crypt"
            receipt = gate.backup.publish(json.loads(raw), raw, directory, remote, FixtureRemote(),
                                          drill.private_dir(out / (engine + "-scratch")))
            packed = gate.backup.encode(receipt)
            bases[engine] = gate.Publication(packed, gate.backup.sha256(packed), raw, producer.read_bytes(), remote)
        coordinate = self.coordinate()
        bound = gate.FrozenCutover(frozen_at, identity["release_sha"], identity["source_sha256"], digest,
                                   mysql["target_uuid"], mysql["database"], coordinate["file"], coordinate["position"])
        result = gate.verify(bound, bases["sqlite"], bases["mysql"], now=dt.datetime.now(dt.timezone.utc))
        record(night / drill.NIGHT_FILES["backup_check"], result)

    def first(self):
        for name in ("marker", "init", "init_live_config", "rehold", "refresh", "reopen_init", "predrain", "approval_hold"):
            if name == "reopen_init":
                # No synthetic webhook has been submitted yet. Probe this isolated
                # replay wrapper before admission so a failure has a useful diagnostic.
                command = self.host["commands"]["telnyx_replay"]
                done = drill.run(command, check=False)
                require(done.returncode == 0, "Initial empty replay failed: " + drill._tail(done.stderr or done.stdout))
            self.note(getattr(self, "step_" + name)())
        config = r"""$config = require $argv[1];
$name = $config['database']['default'];
$db = $config['database']['connections'][$name];
echo json_encode([$db['driver'], $db['database'], $db['journal_mode']], JSON_THROW_ON_ERROR);"""
        staged = drill.run(["/usr/bin/php", "-r", config, self.app / "bootstrap/cache/config.php"],
                           user=drill.WEB_USER, group=drill.APP_GROUP, cwd=self.app)
        driver, path, journal = json.loads(staged.stdout)
        observed = self.sqlite_query("PRAGMA journal_mode")
        require(driver == "sqlite" and path == str(self.paths.sqlite) and isinstance(journal, str)
                and journal.lower() in ("wal", "delete") and observed == [[journal.lower()]]
                and self.live_config()["live_config_sha256"] == self.policy["sqlite_live_config_sha256"],
                "The measured SQLite journal mode differs from its pinned app configuration")
        record(self.paths.e2e / "sqlite-journal.json", {"mode": journal.lower()})
        self.cli("hold", "--issued-approval")
        self.require_held("after approved hold")
        self.note(self.step_freeze())
        frozen_at = now()
        self.note(self.step_copy_load())
        self.note(self.step_snapshot())
        self.publications(frozen_at)
        for name in ("check_evidence", "prepare", "prepare_checks", "approval_promote"):
            self.note(getattr(self, "step_" + name)())
        self.cli("promote", "--issued-approval")
        state = self.journal()
        require(state["phase"] == "mysql_authoritative" and state["writes_possible"] is True
                and state["pending"] is None, "Promotion did not commit MySQL authority")
        self.require_held("after promotion")
        self.note("promoted, ingress held")

    def reopen(self):
        self.cli("reopen")
        self.require_open("after MySQL reopen")
        activated = drill.read_json(Path(self.host["paths"]["activated"]))
        require(activated["server_uuid"] == self.host["mysql"]["target_uuid"]
                and activated["run_id"] == self.policy["identity"]["run_id"]
                and drill.read_json(drill.INGRESS_OPEN)["engine"] == "mysql"
                and self.live_config()["engine"] == "mysql" and not self.paths.sqlite.stat().st_mode & 0o222,
                "Reopen did not activate pinned MySQL with SQLite sealed")
        drill.wait_for(lambda: self.supervisor_running(drill.PROGRAMS), 30, "reopened workers")

    def failed_reopen(self):
        # Trigger the real telnyx:replay-spool error for an inaccessible lock.
        # Preserve the approved wrapper command, both profiles and the host configuration.
        spool = self.app / "storage/app/telnyx-spool"
        created = not spool.exists()
        spool.mkdir(exist_ok=True)
        previous_mode = stat.S_IMODE(spool.stat().st_mode)
        spool.chmod(0)
        try:
            self.cli("reopen", expect=1)
        finally:
            spool.chmod(previous_mode)
            if created:
                spool.rmdir()
        require("python3 exited 1" in self.notes["last_cli"][1], "Unexpected reopen error: " + self.notes["last_cli"][1])
        window = drill.read_json(Path(self.host["paths"]["smoke_window"]))
        attempts = window["attempts"]
        require(window["binding"]["run_id"] == self.policy["identity"]["run_id"]
                and attempts and attempts[-1]["report"] is not None
                and attempts[-1]["gtid_before"] != attempts[-1]["gtid_after"], "No completed ORM smoke before failure")
        self.require_held("after post-smoke failure")
        for key in ("activation", "activated", "scheduler_release", "ingress_open"):
            require(not Path(self.host["paths"][key]).exists(), f"Failed reopen left {key}")
        require(not self.writers(), "Failed reopen left writers")

    def business(self, write=False):
        schema = self.host["mysql"]["database"]
        client = FIXTURE / "repro_app.cnf"
        marker = "w2c-after-promotion-" + self.policy["identity"]["run_id"]
        # A distinct non-smoke row in the framework cache. The audit must count this GTID.
        if write:
            self.mysql(client, schema, "-e", f"INSERT INTO cache (`key`,value,expiration) VALUES ('{marker}','w2c',2147483647)")
        row = self.mysql(client, schema, "-e", f"SELECT value FROM cache WHERE `key`='{marker}'").stdout.strip()
        require(row == b"w2c", "Synthetic business row is missing on MySQL")
        return marker

    def close_audit(self, eligible):
        close = self.cli("way-back", "close")
        self.require_held("after way-back close")
        audit = self.cli("way-back", "audit", expect=None)
        if self.notes["last_cli"][0] != 0:
            require(not eligible and "Multiple verified write-smoke windows require a full reverse export"
                    in self.notes["last_cli"][1], "Unexpected audit refusal: " + self.notes["last_cli"][1])
            path = drill.STATE.parent / "way-back.json"
            require(close == drill.read_json(path) and close["run_id"] == self.policy["identity"]["run_id"]
                    and close["server_uuid"] == self.host["mysql"]["target_uuid"] and close["gate"] == "closed",
                    "Missing closed coordinate for reverse export")
            return {"close_sha256": drill.sha256_file(path), "coordinate": close["coordinate"],
                    "counts": None, "short_path_eligible": False,
                    "result": "multiple authenticated smoke windows require reverse export"}
        require(audit["short_path_eligible"] is eligible
                and (audit["counts"]["business"] == 0 if eligible else audit["counts"]["business"] >= 1)
                and (not eligible or audit["counts"]["smoke"] > 0),
                f"Unexpected closed-coordinate audit: counts={audit['counts']}, short={audit['short_path_eligible']}")
        return audit

    def reverse(self, frozen):
        night = self.paths.e2e / "night"
        out = self.paths.e2e / "reverse"
        exceptions = night / "plan/exceptions-private.json"
        # SHOW TRIGGERS must see the imported triggers. Independent comparison below
        # uses the distinct SELECT-only account.
        access = self.paths.e2e / "export-credentials.json"
        record(access, {"user": "repro_maint", "password": self.fixture_password("maint"), "unix_socket": SOCKET})
        try:
            drill.run([drill.PY, "-s", "-S", "-B", SOURCE / "scripts/mysql/export-sqlite.py", "--writers-stopped",
                       "--schema-template", frozen, "--schema-template-sha256", drill.sha256_file(frozen),
                       "--sqlite-journal-mode", drill.read_json(self.paths.e2e / "sqlite-journal.json")["mode"],
                       "--approved-exceptions", exceptions, "--exceptions-file-sha256", drill.sha256_file(exceptions),
                       "--credentials", access, "--database", self.host["mysql"]["database"],
                       "--expected-server-uuid", self.host["mysql"]["target_uuid"], "--output", out],
                     env={**drill.CLEAN_ENV, "PYTHONPATH": self.wheel()}, timeout=1200)
        finally:
            access.unlink()
        return out / "database.sqlite"

    def recover(self, eligible, marker=None):
        audit = self.close_audit(eligible)
        night = self.paths.e2e / "night"
        frozen = night / "frozen.sqlite"
        before = self.paths.sqlite.stat().st_ino
        mysql = self.host["mysql"]
        review = {"version": 1, "run_id": self.policy["identity"]["run_id"], "server_uuid": mysql["target_uuid"],
                  "database": mysql["database"], "close_sha256": audit["close_sha256"], "owner_approved": True}
        effects = dict.fromkeys(("website", "providers", "payments", "calendar", "outbound", "queues"), "reconciled")
        report = self.paths.e2e / "effects.json"
        require(not self.writers() and not drill.INGRESS_OPEN.exists(), "External-effects review requires a hold")
        self.require_no_route_out()
        record(report, {"scope": "disconnected synthetic fixture", "effects": effects, "audit": audit,
                        "pending_spool": self.spool_count("texts")})
        require(self.spool_count("texts") == 0, "Unreconciled synthetic messages")
        review.update(effects=effects, reconciliation_report=str(report), reconciliation_sha256=drill.sha256_file(report))
        if eligible:
            candidate = frozen
            args = ["--keep-frozen"]
            method = "verified-prewrite"
        else:
            refused = self.paths.e2e / "prewrite-review.json"
            sealed = drill.sha256_file(frozen)
            record(refused, {**review, "installed_sha256": sealed, "method": "verified-prewrite"})
            self.cli("way-back", "install-sqlite", "--keep-frozen", "--sha256", sealed,
                     "--review", str(refused), expect=1)
            require(any(reason in self.notes["last_cli"][1] for reason in (
                    "require a new reverse export", "Multiple verified write-smoke windows require a full reverse export"))
                    and not (drill.STATE.parent / "wayback-installed.json").exists()
                    and self.paths.sqlite.stat().st_ino == before and drill.sha256_file(self.paths.sqlite) == sealed,
                    "The unsafe prewrite request did not leave the sealed original intact")
            reader = self.paths.e2e / "wayback-reader.json"
            record(reader, {"user": "repro_parity_reader", "password": self.fixture_password("reader"), "unix_socket": SOCKET})
            candidate = self.reverse(frozen)
            plan = night / "plan/schema-plan.json"
            review.update(schema_plan=str(plan), schema_plan_sha256=drill.sha256_file(plan),
                          reader_credentials=str(reader), reviewed_reverse_layout=False)
            args = ["--file", str(candidate)]
            method = "verified-export"
        digest = drill.sha256_file(candidate)
        review.update(installed_sha256=digest, method=method)
        owner = self.paths.e2e / "wayback-review.json"
        record(owner, review)
        self.cli("way-back", "install-sqlite", *args, "--sha256", digest, "--review", str(owner))
        result = drill.read_json(drill.STATE.parent / "wayback-installed.json")
        require(result["method"] == method and result["writers_held"] is True
                and result["installed_sha256"] == digest == drill.sha256_file(self.paths.sqlite)
                and (self.paths.sqlite.stat().st_ino == before) is eligible
                and self.live_config()["engine"] == "sqlite", "Installed SQLite recovery proof differs")
        self.require_held("installed recovery")
        if marker:
            require(self.sqlite_query(f"SELECT value FROM cache WHERE key='{marker}'") == [["w2c"]],
                    "Reverse-exported SQLite lost the confirmed business write")
        new_id = self.policy["identity"]["run_id"] + "-recover"
        draft = self.cli("draft-policy", "--run-id", new_id)
        inputs = self.paths.e2e / "next-identity.json"
        policy = self.paths.e2e / "next-policy.json"
        record(inputs, draft["identity"])
        record(policy, draft["policy"])
        self.cli("retire", "--next-policy", str(policy), "--next-identity", str(inputs))
        self.cli("init", "--from-held")
        self.require_held("new planned SQLite journal")
        self.cli("refresh")
        self.cli("reopen")
        self.require_open("new SQLite run")
        require(self.live_config()["engine"] == "sqlite" and drill.read_json(drill.INGRESS_OPEN)["engine"] == "sqlite",
                "New run did not admit SQLite")
        if marker is not None:
            source = r"""require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = $app->make('db')->connection();
echo json_encode(['driver' => $db->getDriverName(), 'database' => $db->getDatabaseName(),
                  'value' => $db->table('cache')->where('key', $argv[1])->value('value')], JSON_THROW_ON_ERROR);"""
            measured = drill.run(["/usr/bin/php", "-r", source, marker], cwd=self.app, env=drill.CLEAN_ENV,
                                 user=drill.WEB_USER, group=drill.APP_GROUP, timeout=60)
            require(json.loads(measured.stdout) == {"driver": "sqlite", "database": str(self.paths.sqlite), "value": "w2c"},
                    "Reopened Laravel web identity could not read the recovered MySQL write from the pinned SQLite file")
        verification = drill.read_json(night / "plan/result.json")
        record(self.paths.e2e / "w2c-result.json",
               {"version": 1, "result": "local-recovery-verified", "case": self.args.case, "method": method,
                "release": self.policy["identity"]["release_sha"], "closed_counts": audit["counts"],
                "original_run_id": self.policy["identity"]["run_id"], "server_uuid": mysql["target_uuid"],
                "close_sha256": audit["close_sha256"], "closed_coordinate_sha256": coordinate_hash(audit["coordinate"]),
                "source_tables": verification["tables"], "frozen_sha256": drill.sha256_file(frozen),
                "installed_sha256": digest, "source_inode_preserved": eligible,
                "business_write_preserved": marker is not None, "post_reopen_business_read": marker is not None,
                "backup_transport": "disconnected-readback-fixture"})

    def execute(self):
        require(self.host["mysql"]["mode"] == "cutover" and self.host["mysql"]["offsite_waiver"] is False
                and self.host["mysql"]["socket"] == SOCKET and (FIXTURE / ".repro-mysql-fixture").is_file(),
                "W2c requires the dedicated cutover-mode socket fixture")
        self.note(self.boot())
        old = self.paths.e2e / "before-reboot.json"
        if self.args.stage == "first":
            self.first()
            if self.args.case == "short":
                self.failed_reopen()
                self.recover(True)
            else:
                self.reopen()
                marker = self.business(write=True)
                record(old, {"boot": drill.read_json(drill.EVIDENCE)["boot_id"], "marker": marker})
        else:
            require(self.args.case == "export" and old.is_file(), "No export case to restart")
            before = drill.read_json(old)
            require(not drill.EVIDENCE.exists() and not drill.INGRESS_OPEN.exists() and not self.writers(),
                    "Restart retained authority or admissions")
            self.require_held("simulated reboot")
            self.cli("refresh")
            require(drill.read_json(drill.EVIDENCE)["boot_id"] != before["boot"], "Boot identity was reused")
            self.reopen()
            require(self.business() == before["marker"], "Restart lost the confirmed MySQL write")
            self.recover(False, before["marker"])


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="role", required=True)
    outer = sub.add_parser("run")
    outer.add_argument("--release-sha", required=True)
    outer.add_argument("--case", choices=tuple(CASES), required=True)
    inside = sub.add_parser("inside")
    inside.add_argument("--root", required=True)
    inside.add_argument("--case", choices=tuple(CASES), required=True)
    inside.add_argument("--stage", choices=("first", "reboot"), required=True)
    args = parser.parse_args()
    args.fixture, args.time_scale = str(FIXTURE), 1.0
    try:
        if args.role == "inside":
            refusal = drill.sandbox_refusal(args.root)
            require(refusal is None, refusal)
            drill.Inside.require_no_route_out()
            Inside(args).execute()
        else:
            args.src, args.vendor, args.srv = str(SOURCE), "/vendor-src", "/srv"
            driver = Outer(args)
            driver.prepare_container()
            drill.write(SCRIPT, Path(__file__).read_bytes())
            driver.execute(args.case)
    except (drill.StepFailed, ValueError, OSError, KeyError, subprocess.TimeoutExpired) as error:
        print(f"w2c: failed: {error}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
