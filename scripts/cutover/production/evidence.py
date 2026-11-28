"""prepare's evidence report and the record evidence() returns (design v2, 3.2 steps 11 and 12, 3.3, 5.3, 7.1; D11).

Step 11 of prepare runs the checks (adapter.py runs the commands); this module reads what each printed and compares it
with what the host must show, and builds step 12's report from the records. evidence() is then a pure function of the
report's bytes (`record`), with the live preconditions checked by the adapter around it.

The report is canonical JSON (keys sorted, no spaces, ASCII, a final newline), root 0600 at paths.evidence_report.
Its keys are a contract (README "The evidence report"): REPORT_KEYS, CHECK_KEYS, CHECK_RECORD_KEYS, RESULT_KEYS.
Every check record is one that passed (`ok` true): a failed check refuses step 11, so no report holds one. Texts that
come from a probe are quoted cut to PROBLEM_CHARACTERS; none holds a secret (passwords are never printed by the
probes, and the token check's password never reaches this module).

Standard library only; this module never imports adapter.py (which imports it).
"""

from __future__ import annotations

import hashlib
import json
import re
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path[:0] = [str(HERE), str(HERE.parent)]
import live_config  # noqa: E402
from runner import Evidence, UnsafeCutover, is_sha256  # noqa: E402

VERSION = 1
PROBLEM_CHARACTERS = 500
MODES = ("drill", "cutover")
INPUT_NAMES = ("snapshot", "import", "verify_fields", "migrate_status", "complete_schema", "backup_check")
# The two snapshots under check_inputs' names (D10): forward parity (drill: S_copy; cutover: the frozen one) and the
# frozen snapshot the SQLite publication binds.
REPORT_KEYS = {"version", "run_id", "mode", "offsite_waiver", "snapshot_sha256", "frozen_snapshot_sha256", "backup",
               "receipt_sha256", "armed_from", "inputs", "switch_at", "fpm_drain", "checks", "results", "written_at"}
BACKUP_KEYS = {"file", "position", "gtid_executed"}
ARMED_KEYS = {"file", "position"}
CHECK_KEYS = {"profile_probes", "fastcgi", "readonly_smoke", "token_check", "mysql"}
# The values every route check records: what the process or pool reads, compared with the target and the MySQL pin.
ROUTE_KEYS = ("engine", "database", "server_uuid", "route_sha256")
CHECK_RECORD_KEYS = {
    "profile_probes": {"profiles", "scripts", "user", "group", "cwd", "umask", "env", *ROUTE_KEYS, "ok"},
    "fastcgi": {"socket", *ROUTE_KEYS, "sapi", "pid", "opcache", "ok"},
    "readonly_smoke": {"user", "group", *ROUTE_KEYS, "client_id", "shoot_id", "client_read", "shoot_read",
                       "encrypted_value_decrypts", "smoke_queue_pending", "smoke_queue_paused", "ok"},
    "token_check": {"account", "user", "engine", "writer_policy", "token_acquired", "problems", "ok"},
    "mysql": {"server_uuid", "database", "gate", "writable", "business_activity", "foreign_activity", "receipt", "mode",
              "gate_account", "ok"},
}
# The checks that are lists (one record per context or user); the others are one record.
LISTED = ("profile_probes", "readonly_smoke")
RESULT_KEYS = {"forward_parity", "final_schema", "readonly_smoke", "sqlite_backup_offsite", "mysql_backup_offsite"}
DRAIN_KEYS = {"purpose", "outcome", "active_processes", "waited_seconds", "polls", "problem"}
# What a freeze goes on after (adapter.FREEZE_DRAIN_OUTCOMES, copied: adapter imports this module).
FREEZE_DRAIN_OUTCOMES = ("drained", "timed_out", "stopped")
# The read-only smoke's own checks (cutover:smoke --read-only, design 5.3) that must each be true.
SMOKE_READS = ("client_read", "shoot_read", "encrypted_value_decrypts")
GATE_ACCOUNT = "repro_cutover_gate@localhost"
GATE_OBSERVED = 0


def _require(condition: bool, message: str) -> None:
    if not condition:
        raise UnsafeCutover(message)


def _cut(text) -> str:
    text = str(text)
    return text if len(text) <= PROBLEM_CHARACTERS else text[:PROBLEM_CHARACTERS - 3] + "..."


def _problems(value) -> str:
    """A probe's own `problems` texts, joined and cut, or "" when it gave none."""
    if isinstance(value, list) and value and all(isinstance(item, str) for item in value):
        return _cut("; ".join(value))
    return ""


def json_object(text: str) -> dict | None:
    """One JSON object, or None: no NaN or Infinity, no repeated key, no nesting deeper than the parser allows."""
    def unique(pairs: list) -> dict:
        record = dict(pairs)
        if len(record) != len(pairs):
            raise ValueError("repeated key")
        return record

    def constant(name: str):
        raise ValueError(f"{name} is not JSON")

    try:
        value = json.loads(text, object_pairs_hook=unique, parse_constant=constant)
    except (ValueError, RecursionError):
        return None
    return value if isinstance(value, dict) else None


def last_json_line(output: str) -> dict | None:
    """The JSON object an artisan command printed as its last line (a PHP notice before it does not hide it)."""
    lines = [line for line in (output or "").splitlines() if line.strip()]
    return json_object(lines[-1]) if lines else None


def _route_values(report: dict, expected: dict, what: str) -> dict:
    """engine, database, server_uuid and the route hash of a cutover:smoke or fpm-engine report, each the expected."""
    engine = report.get("engine")
    _require(engine == expected["engine"], f"{what} reports engine {str(engine)[:20]}, not {expected['engine']}")
    _require(report.get("database") == expected["database"],
             f"{what} reports database {str(report.get('database'))[:80]}, not {expected['database']}")
    _require(report.get("server_uuid") == expected["server_uuid"],
             f"{what} reports server {str(report.get('server_uuid'))[:40]}, not the target {expected['server_uuid']}")
    values = report.get("route_values")
    try:
        route = live_config.route_sha256(values) if isinstance(values, dict) else None
    except (TypeError, ValueError, RecursionError):
        route = None
    _require(route == expected["route_sha256"],
             f"{what} reports another route hash ({str(route)[:12]}) than the MySQL pin ({expected['route_sha256'][:12]})")
    return {"engine": engine, "database": report["database"], "server_uuid": report["server_uuid"],
            "route_sha256": route}


def _smoke_report(output: str, code: int, what: str, mode: str) -> dict:
    """A cutover:smoke report that passed: its mode, ok true, no problems, exit 0. A refusal quotes its problems."""
    report = last_json_line(output)
    _require(report is not None, f"{what} printed no report (exit {code})")
    problems = _problems(report.get("problems"))
    _require(report.get("ok") is True and report.get("problems") == [],
             f"{what} failed (exit {code}): {problems or 'no problem given'}")
    _require(code == 0, f"{what} exited {code} with an ok report")
    _require(report.get("mode") == mode, f"{what} ran in mode {str(report.get('mode'))[:20]}, not {mode}")
    return report


def _process(report: dict, user: str, what: str) -> dict:
    process = report.get("process")
    _require(isinstance(process, dict), f"{what} reports no process")
    _require(type(process.get("euid")) is int and process["euid"] > 0,
             f"{what} ran as root: application PHP never runs as root")
    _require(process.get("user") == user, f"{what} ran as {str(process.get('user'))[:32]}, not {user}")
    return process


def engine_check(output: str, code: int, context: dict, expected: dict, what: str) -> dict:
    """`cutover:smoke --engine-only --json` in a wrapper profile's context (design 3.2 step 11): the route values the
    profile's processes would read, from a process that ran as the profile's user, group, cwd and umask."""
    report = _smoke_report(output, code, what, "engine-only")
    process = _process(report, context["user"], what)
    _require(process.get("group") == context["group"],
             f"{what} ran in group {str(process.get('group'))[:32]}, not {context['group']}")
    _require(process.get("umask") == context["umask"],
             f"{what} ran with umask {str(process.get('umask'))[:8]}, not {context['umask']}")
    _require(process.get("cwd") == context["cwd"], f"{what} ran in {str(process.get('cwd'))[:200]}, not {context['cwd']}")
    return _route_values(report, expected, what)


def fastcgi_check(report: dict, expected: dict, socket: str) -> dict:
    """fpm-engine.php's report through the pool's own socket (design 5.3): FPM's effective config, plus OPcache."""
    what = "The FastCGI probe of FPM"
    _require(report.get("ok") is True and report.get("problems") == [],
             f"{what} failed: {_problems(report.get('problems')) or 'no problem given'}")
    values = _route_values(report, expected, what)
    opcache = report.get("opcache")
    pid = report.get("pid")
    return {"socket": socket, **values, "sapi": str(report.get("sapi"))[:40],
            "pid": pid if type(pid) is int else None,
            "opcache": {key: value for key, value in opcache.items() if isinstance(key, str)
                        and (value is None or type(value) in (bool, int))} if isinstance(opcache, dict) else None,
            "ok": True}


def readonly_check(output: str, code: int, user: str, group: str | None, smoke: dict, expected: dict) -> dict:
    """`cutover:smoke --read-only --json` as one user (design 5.3): the marked test client and shoot read through the
    app's models, the encrypted value decrypts, nothing queued on the smoke queue."""
    what = f"The read-only smoke as {user}"
    report = _smoke_report(output, code, what, "read-only")
    process = _process(report, user, what)
    if group is not None:
        _require(process.get("group") == group, f"{what} ran in group {str(process.get('group'))[:32]}, not {group}")
    values = _route_values(report, expected, what)
    checks = report.get("checks")
    _require(isinstance(checks, dict), f"{what} printed no checks")
    _require(all(type(checks.get(key)) is int and checks[key] == smoke[key] for key in ("client_id", "shoot_id")),
             f"{what} read client {str(checks.get('client_id'))[:20]} and shoot {str(checks.get('shoot_id'))[:20]}, "
             f"not the marked test client {smoke['client_id']} and shoot {smoke['shoot_id']} (host.json smoke)")
    for name in SMOKE_READS:
        _require(checks.get(name) is True, f"{what}: {name} is not true")
    pending = checks.get("smoke_queue_pending")
    _require(checks.get("smoke_queue") == "database:cutover-smoke", f"{what} did not inspect database:cutover-smoke")
    _require(type(pending) is int and pending == 0, f"{what}: {str(pending)[:20]} jobs wait on database:cutover-smoke")
    return {"user": user, "group": process.get("group"), **values, "client_id": checks["client_id"],
            "shoot_id": checks["shoot_id"], **{name: True for name in SMOKE_READS}, "smoke_queue_pending": 0,
            "smoke_queue_paused": checks.get("smoke_queue_paused") if type(checks.get("smoke_queue_paused")) is bool
            else None, "ok": True}


def token_check(output: str, code: int, account: str, user: str) -> dict:
    """`db:writer-policy-check` on the check account's private config cache (design 3.3): no problems, the writer
    token taken in an empty transaction."""
    what = f"The token check as {account}"
    report = last_json_line(output)
    _require(report is not None, f"{what} printed no report (exit {code})")
    problems = report.get("problems")
    _require(problems == [], f"{what} found problems (exit {code}): {_problems(problems) or 'malformed problems'}")
    _require(report.get("engine") == "mysql", f"{what} reports engine {str(report.get('engine'))[:20]}, not mysql")
    _require(report.get("writer_policy") is True, f"{what}: the writer policy is not on")
    token = report.get("token")
    _require(isinstance(token, dict) and token.get("acquired") is True, f"{what} did not take the writer token")
    _require(code == 0, f"{what} exited {code} with no problem")
    return {"account": account, "user": user, "engine": "mysql", "writer_policy": True, "token_acquired": True,
            "problems": [], "ok": True}


def mysql_check(reading: dict, gate: tuple, mysql: dict, receipt_sha256: str) -> dict:
    """The MySQL probe's reading (exit 0) and gate.check's result (design 3.2 step 11, 3.3, review B2-1): the target,
    the gate closed, nothing writable, no business or foreign activity, armed on this run's receipt; the gate account
    logs in read-only with exactly its accounts.sql grants."""
    _require((reading.get("server_uuid"), reading.get("database")) == (mysql["target_uuid"], mysql["database"]),
             "The MySQL probe read another server or database than the target")
    _require(reading.get("gate") == "closed" and reading.get("writable") is False,
             f"The MySQL gate is not closed (it reads {str(reading.get('gate'))[:20]})")
    _require(reading.get("business_activity") is False, "The MySQL probe reports business activity")
    _require(reading.get("foreign_activity") is False,
             "The MySQL probe reports foreign activity: the copy changed after the verified backup")
    _require(reading.get("receipt") == receipt_sha256,
             "The MySQL probe is not armed on this run's receipt, so its activity readings prove nothing")
    return {"server_uuid": reading["server_uuid"], "database": reading["database"], "gate": "closed", "writable": False,
            "business_activity": False, "foreign_activity": False, "receipt": receipt_sha256,
            "mode": str(reading.get("mode"))[:20], "gate_account": gate_result(gate), "ok": True}


def gate_result(gate: tuple) -> dict:
    """gate.check's (output, exit code, message) (review B2-1): exit 0, the gate closed, the gate account logged in as
    itself with exactly its accounts.sql grants. Returns the self-check's record."""
    output, code, message = gate
    account = output.get("gate_account") if isinstance(output, dict) else None
    _require(code == GATE_OBSERVED, f"gate.check refused (exit {code}): {_cut(message or 'no message')}")
    _require(isinstance(output, dict), "gate.check returned no reading")
    _require(output.get("gate") == "closed", f"gate.check reads the gate {str(output.get('gate'))[:20]}, not closed")
    _require(isinstance(account, dict), "The gate account's self-check failed: no reading")
    _require(account.get("current_user") == GATE_ACCOUNT and account.get("problems") == [],
             f"The gate account's self-check failed: {_problems(account.get('problems')) or 'no reading'}")
    return {"current_user": GATE_ACCOUNT, "problems": []}


def results(report: dict) -> dict:
    """The runner's five results, from the report's inputs and checks alone (design 3.3).

    forward parity: the import and verify-fields inputs bound; final schema: migrate:status and complete-schema bound;
    read-only smoke: every step 11 check passed; off-site: the final backup check bound, or the waiver in drill mode.
    """
    inputs, checks = report["inputs"], report["checks"]
    passed = all(record.get("ok") is True for name in CHECK_KEYS
                 for record in (checks[name] if name in LISTED else [checks[name]]))
    offsite = inputs["backup_check"] is not None or (report["offsite_waiver"] is True and report["mode"] == "drill")
    return {"forward_parity": inputs["import"] is not None and inputs["verify_fields"] is not None,
            "final_schema": inputs["migrate_status"] is not None and inputs["complete_schema"] is not None,
            "readonly_smoke": passed, "sqlite_backup_offsite": offsite, "mysql_backup_offsite": offsite}


def build(*, run_id: str, mode: str, offsite_waiver: bool, bound: dict, receipt_sha256: str, armed_from: dict,
          switch_at: int, fpm_drain: dict, checks: dict, written_at: int) -> dict:
    """Step 12's report from check_inputs' bindings (`bound`), the receipt, the switch, the freeze's drain record and
    step 11's check records; refused (as parse refuses) unless it is complete and consistent."""
    report = {"version": VERSION, "run_id": run_id, "mode": mode, "offsite_waiver": offsite_waiver,
              "snapshot_sha256": bound["snapshot_sha256"], "frozen_snapshot_sha256": bound["frozen_snapshot_sha256"],
              "backup": dict(bound["backup"]), "receipt_sha256": receipt_sha256, "armed_from": dict(armed_from),
              "inputs": {name: bound["inputs"][name] for name in INPUT_NAMES}, "switch_at": switch_at,
              "fpm_drain": dict(fpm_drain), "checks": checks, "written_at": written_at}
    report["results"] = results(report)
    _check(report)
    return report


def encode(report: dict) -> bytes:
    """The report's canonical bytes: report_sha256 is their SHA-256."""
    return json.dumps(report, sort_keys=True, separators=(",", ":"), ensure_ascii=True,
                      allow_nan=False).encode("ascii") + b"\n"


def _records(checks: dict, name: str) -> list:
    value = checks[name]
    if name in LISTED:
        _require(isinstance(value, list) and value and all(isinstance(item, dict) for item in value),
                 f"The evidence report's {name} check holds no record")
        return value
    _require(isinstance(value, dict), f"The evidence report's {name} check is not one record")
    return [value]


def _coordinate(value, keys: set) -> bool:
    return (isinstance(value, dict) and value.keys() == keys and isinstance(value["file"], str)
            and re.fullmatch(r"[A-Za-z0-9_.-]{1,128}", value["file"]) is not None
            and type(value["position"]) is int and value["position"] >= 4
            and ("gtid_executed" not in keys or isinstance(value["gtid_executed"], str)))


def _check(report: dict) -> None:
    """Every rule of the report's contract (README "The evidence report")."""
    _require(isinstance(report, dict) and report.keys() == REPORT_KEYS,
             "The evidence report's keys are not the contract's")
    _require(type(report["version"]) is int and report["version"] == VERSION, "The evidence report's version is unknown")
    mode, waiver = report["mode"], report["offsite_waiver"]
    _require(isinstance(report["run_id"], str) and mode in MODES and type(waiver) is bool,
             "The evidence report's run, mode or waiver is malformed")
    _require(not waiver or mode == "drill", "The off-site waiver is allowed only in drill mode")
    _require(is_sha256(report["snapshot_sha256"]) and is_sha256(report["frozen_snapshot_sha256"]),
             "The evidence report's snapshots are malformed")
    if mode == "cutover":
        _require(report["snapshot_sha256"] == report["frozen_snapshot_sha256"],
                 "Cutover mode needs one snapshot: forward parity and the frozen snapshot differ")
    _require(_coordinate(report["backup"], BACKUP_KEYS) and _coordinate(report["armed_from"], ARMED_KEYS),
             "The evidence report's backup or armed_from coordinate is malformed")
    _require(is_sha256(report["receipt_sha256"]), "The evidence report's receipt digest is malformed")
    inputs = report["inputs"]
    _require(isinstance(inputs, dict) and inputs.keys() == set(INPUT_NAMES)
             and all(entry is None or (isinstance(entry, dict) and entry.keys() == {"path", "sha256"}
                                       and isinstance(entry["path"], str) and is_sha256(entry["sha256"]))
                     for entry in inputs.values()), "The evidence report's inputs are malformed")
    _require(all(inputs[name] is not None for name in INPUT_NAMES if name != "backup_check"),
             "The evidence report misses one of the night's inputs")
    if waiver:
        _require(inputs["backup_check"] is None, "The off-site waiver replaces the final backup check: the report "
                                                 "must name none")
    else:
        _require(inputs["backup_check"] is not None, "Without the off-site waiver the final backup check is required")
    for name in ("switch_at", "written_at"):
        _require(type(report[name]) is int and report[name] > 0, f"The evidence report's {name} is malformed")
    drain = report["fpm_drain"]
    _require(isinstance(drain, dict) and set(drain) <= DRAIN_KEYS and drain.get("purpose") == "freeze"
             and drain.get("outcome") in FREEZE_DRAIN_OUTCOMES
             and (drain["outcome"] != "timed_out" or bool(drain.get("polls"))),
             "The evidence report's FPM drain record is not one the freeze goes on after")
    checks = report["checks"]
    _require(isinstance(checks, dict) and checks.keys() == CHECK_KEYS, "The evidence report's checks are not the "
                                                                       "contract's")
    routes = set()
    for name in sorted(CHECK_KEYS):
        for record in _records(checks, name):
            _require(record.keys() == CHECK_RECORD_KEYS[name], f"The evidence report's {name} record keys are not the "
                                                               "contract's")
            _require(record["ok"] is True, f"The evidence report holds a failed {name} check")
            if "route_sha256" in record:
                _require(all(isinstance(record[key], str) for key in ROUTE_KEYS),
                         f"The evidence report's {name} route is malformed")
                routes.add((record["engine"], record["database"], record["server_uuid"], record["route_sha256"]))
    smokes = checks["readonly_smoke"]
    _require(len(smokes) == 2 and all(isinstance(s["user"], str) and s["user"] and s["user"] != "root" for s in smokes)
             and smokes[0]["user"] != smokes[1]["user"], "The report requires two distinct read-only smoke users")
    for smoke in smokes:
        _require(all(smoke[name] is True for name in SMOKE_READS)
                 and type(smoke["smoke_queue_pending"]) is int and smoke["smoke_queue_pending"] == 0,
                 "The evidence report's read-only smoke did not complete all reads on an empty queue")
        _require(all(type(smoke[k]) is int and smoke[k] > 0 and smoke[k] == smokes[0][k]
                     for k in ("client_id", "shoot_id")), "The read-only smokes must agree on the marked records")
    _require(len(routes) == 1, "The evidence report's checks read more than one route or server")
    [(engine, database, server, route)] = routes
    _require(engine == "mysql" and is_sha256(route), "The evidence report's checks did not read the MySQL route")
    _require((checks["mysql"]["server_uuid"], checks["mysql"]["database"]) == (server, database),
             "The evidence report's checks read another server than the MySQL probe")
    _require(checks["mysql"]["receipt"] == report["receipt_sha256"],
             "The evidence report's MySQL reading was armed on another receipt")
    mysql, token = checks["mysql"], checks["token_check"]
    _require(mysql["gate"] == "closed" and all(mysql[k] is False for k in ("writable", "business_activity", "foreign_activity"))
             and mysql["gate_account"] == {"current_user": GATE_ACCOUNT, "problems": []},
             "The evidence report has no closed, quiet MySQL gate with a verified gate account")
    _require(token["token_acquired"] is True and token["writer_policy"] is True and token["problems"] == []
             and token["engine"] == "mysql" and token["account"] == "repro_token_check@localhost"
             and token["user"] in [s["user"] for s in smokes],
             "The evidence report's token check did not verify the writer policy and acquire the token")
    _require(isinstance(report["results"], dict) and all(type(v) is bool for v in report["results"].values())
             and report["results"] == results(report),
             "The evidence report's results do not follow from its inputs and checks")


def parse(raw: bytes) -> dict:
    """The report from its bytes: canonical JSON with every rule of the contract, else UnsafeCutover."""
    try:
        text = raw.decode("ascii")
    except UnicodeDecodeError:
        raise UnsafeCutover("The evidence report is not ASCII JSON") from None
    report = json_object(text)
    _require(report is not None, "The evidence report is not one JSON object")
    try:
        canonical = encode(report)
    except (TypeError, ValueError):
        raise UnsafeCutover("The evidence report holds a value JSON cannot hold") from None
    _require(canonical == raw, "The evidence report is not canonical JSON (keys sorted, no spaces, a final newline): "
                               "report_sha256 would not be reproducible")
    _check(report)
    return report


def record(raw: bytes) -> Evidence:
    """The runner's Evidence from the report's bytes alone (design 3.3): forward parity's snapshot (drill: S_copy;
    cutover: the frozen one), the report's SHA-256 (so Approval 2 binds it), the verified backup's coordinate (the
    receipt's `backup`) and the five results. Equal bytes give an equal record."""
    report = parse(raw)
    found = results(report)
    return Evidence(snapshot_sha256=report["snapshot_sha256"], report_sha256=hashlib.sha256(raw).hexdigest(),
                    binlog_file=report["backup"]["file"], binlog_position=report["backup"]["position"], **found)


def manifest(report: dict, run_id: str) -> dict:
    """The evidence inputs manifest (README "prepare") the report was bound from, for check_inputs to bind again."""
    return {"version": 1, "run_id": run_id, "mode": report["mode"], "backup": dict(report["backup"]),
            "inputs": {name: report["inputs"][name] for name in INPUT_NAMES}}
