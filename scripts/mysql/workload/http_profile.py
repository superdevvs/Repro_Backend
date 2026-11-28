"""Plan and compare isolated HTTP read workloads; never execute HTTP or a database."""
from __future__ import annotations

import argparse
from datetime import datetime
import hashlib
import json
import math
from pathlib import Path
import re
import sys

MAX_FILE_BYTES = 32 * 1024 * 1024
MAX_OPERATIONS = 20_000
SHA = re.compile(r"[a-f0-9]{64}")
LABEL = re.compile(r"[a-z][a-z0-9_-]{0,63}")
PROFILE_FIELDS = {
    "version", "scope", "release_sha", "app_source_sha256", "source_snapshot_sha256",
    "host_profile_sha256", "fixture_manifest_sha256", "response_contract_sha256",
    "mysql_server_uuid", "logical_time_utc", "routes", "phases", "max_in_flight",
    "request_timeout_ms",
}
PHASES = ("warmup", "peak", "surge")
ENGINES = ("sqlite", "mysql")
ORDERS = (("sqlite", "mysql"), ("mysql", "sqlite"))


class Refusal(ValueError):
    pass


def need(condition, reason):
    if not condition:
        raise Refusal(reason)


def keys(value, expected, reason):
    need(isinstance(value, dict) and set(value) == set(expected), reason)


def integer(value, minimum, maximum, reason):
    need(type(value) is int and minimum <= value <= maximum, reason)


def sha(value, reason):
    need(isinstance(value, str) and SHA.fullmatch(value) is not None, reason)


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(",", ":"), ensure_ascii=True).encode("ascii")


def digest(value):
    return hashlib.sha256(canonical(value)).hexdigest()


def pairs(values):
    result = {}
    for name, value in values:
        need(name not in result, "duplicate-json-key")
        result[name] = value
    return result


def reject_constant(_value):
    raise Refusal("nonstandard-json")


def load(path):
    # Bound reads before JSON decoding. The files are review inputs, never code.
    try:
        with path.open("rb") as stream:
            raw = stream.read(MAX_FILE_BYTES + 1)
        need(len(raw) <= MAX_FILE_BYTES, "input-size")
        return json.loads(raw, object_pairs_hook=pairs, parse_constant=reject_constant)
    except Refusal:
        raise
    except (OSError, UnicodeError, ValueError) as error:
        raise Refusal("unreadable-or-invalid-json") from error


def validate_profile(value):
    keys(value, PROFILE_FIELDS, "profile-fields")
    integer(value["version"], 1, 1, "profile-version")
    need(value["scope"] == "isolated-http-read-workload", "profile-scope")
    need(isinstance(value["release_sha"], str) and
         re.fullmatch(r"[a-f0-9]{40}", value["release_sha"]), "release-sha")
    for name in PROFILE_FIELDS:
        if name.endswith("_sha256"):
            sha(value[name], "profile-digest")
    need(isinstance(value["mysql_server_uuid"], str) and
         re.fullmatch(r"[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}", value["mysql_server_uuid"]), "mysql-uuid")
    clock = value["logical_time_utc"]
    need(isinstance(clock, str) and re.fullmatch(r"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z", clock), "logical-clock")
    try:
        datetime.strptime(clock, "%Y-%m-%dT%H:%M:%SZ")
    except ValueError as error:
        raise Refusal("logical-clock") from error
    integer(value["max_in_flight"], 1, 64, "concurrency-budget")
    integer(value["request_timeout_ms"], 1, 120_000, "request-timeout")
    routes = value["routes"]
    need(isinstance(routes, list) and 1 <= len(routes) <= 100, "route-count")
    labels = set()
    for route in routes:
        keys(route, {"label", "method", "path_template", "role", "fixture", "weight", "expected_status"}, "route-fields")
        for name in ("label", "role", "fixture"):
            need(isinstance(route[name], str) and LABEL.fullmatch(route[name]), "route-label")
        need(route["label"] not in labels, "duplicate-route-label")
        labels.add(route["label"])
        need(route["method"] == "GET", "read-method-required")
        path = route["path_template"]
        # Reviewed route templates only: no hosts, queries, fragments, concrete
        # numeric record IDs, path traversal, or request payloads in this file.
        need(isinstance(path, str) and len(path) <= 512 and
             re.fullmatch(r"/api(?:/(?:[a-z][a-z0-9_-]*|\{[a-z][a-z0-9_]*\}))+", path), "route-template")
        integer(route["weight"], 1, 10_000, "route-weight")
        integer(route["expected_status"], 200, 299, "expected-success-status")
    need(sum(route["weight"] for route in routes) <= 100_000, "total-route-weight")
    phases = value["phases"]
    need(isinstance(phases, list) and len(phases) == 3, "phase-count")
    for name, phase in zip(PHASES, phases):
        keys(phase, {"name", "duration_ms", "arrival_rate_milli_rps"}, "phase-fields")
        need(phase["name"] == name, "phase-order")
        integer(phase["duration_ms"], 1, 600_000, "phase-duration")
        integer(phase["arrival_rate_milli_rps"], 1, 1_000_000, "phase-rate")
    need(phases[2]["arrival_rate_milli_rps"] >= phases[1]["arrival_rate_milli_rps"], "surge-below-peak")


def plan(profile):
    validate_profile(profile)
    routes = profile["routes"]
    weight_sum = sum(route["weight"] for route in routes)
    operations = []
    offset = 0
    for phase in profile["phases"]:
        rate = phase["arrival_rate_milli_rps"]
        count = (phase["duration_ms"] * rate + 999_999) // 1_000_000
        need(len(operations) + count <= MAX_OPERATIONS, "operation-budget")
        current = [0] * len(routes)
        covered = set()
        for index in range(count):
            for slot, route in enumerate(routes):
                current[slot] += route["weight"]
            chosen = max(range(len(routes)), key=current.__getitem__)
            current[chosen] -= weight_sum
            route = routes[chosen]
            covered.add(route["label"])
            operations.append({"sequence": len(operations), "phase": phase["name"],
                               "route": route["label"], "due_us": offset + index * 1_000_000_000 // rate})
        if phase["name"] != "warmup":
            need(covered == {route["label"] for route in routes}, "measured-route-uncovered")
        offset += phase["duration_ms"] * 1000
    schedule = {"version": 1, "profile_sha256": digest(profile), "operations": operations}
    return {**schedule, "schedule_sha256": digest(schedule)}


def percentile(values, fraction):
    need(bool(values), "missing-latency-samples")
    ordered = sorted(values)
    return ordered[max(0, math.ceil(len(ordered) * fraction) - 1)]


def distribution(values):
    return {"p50": percentile(values, .5), "p95": percentile(values, .95),
            "p99": percentile(values, .99), "max": max(values)}


def validate_receipt(profile, schedule, receipt, engine):
    keys(receipt, {"version", "engine", "profile_sha256", "schedule_sha256", "database_identity",
                   "executor_sha256", "runs"}, "receipt-fields")
    integer(receipt["version"], 1, 1, "receipt-version")
    need(receipt["engine"] == engine, "receipt-engine")
    need(receipt["profile_sha256"] == schedule["profile_sha256"] and
         receipt["schedule_sha256"] == schedule["schedule_sha256"], "receipt-binding")
    expected_identity = profile["source_snapshot_sha256"] if engine == "sqlite" else profile["mysql_server_uuid"]
    need(receipt["database_identity"] == expected_identity, "database-identity")
    sha(receipt["executor_sha256"], "executor-digest")
    runs = receipt["runs"]
    need(isinstance(runs, list) and len(runs) == 2, "two-rounds-required")
    indexed = []
    deadline = sum(phase["duration_ms"] for phase in profile["phases"]) * 1000 + profile["request_timeout_ms"] * 1000
    for number, run in enumerate(runs, 1):
        keys(run, {"round", "engine_order", "samples"}, "run-fields")
        integer(run["round"], number, number, "round-order")
        need(run["engine_order"] == list(ORDERS[number - 1]), "engine-order")
        samples = run["samples"]
        need(isinstance(samples, list) and len(samples) == len(schedule["operations"]), "sample-count")
        by_sequence = {}
        for sample in samples:
            keys(sample, {"sequence", "started_us", "completed_us", "status", "outcome", "query_count",
                          "database_warning_count", "logical_result_sha256"}, "sample-fields")
            sequence = sample["sequence"]
            integer(sequence, 0, len(samples) - 1, "sample-sequence")
            need(sequence not in by_sequence, "duplicate-sample")
            operation = schedule["operations"][sequence]
            integer(sample["started_us"], operation["due_us"], deadline, "sample-start")
            integer(sample["completed_us"], sample["started_us"] + 1, deadline, "sample-completion")
            integer(sample["query_count"], 0, 1_000_000, "query-count")
            integer(sample["database_warning_count"], 0, 1_000_000, "warning-count")
            need(sample["outcome"] in ("ok", "http_error", "timeout", "transport_error", "isolation_refused"), "sample-outcome")
            if sample["status"] is not None:
                integer(sample["status"], 100, 599, "http-status")
            if sample["outcome"] == "ok":
                sha(sample["logical_result_sha256"], "logical-result-digest")
                need(sample["status"] is not None, "successful-http-status-required")
            else:
                need(sample["logical_result_sha256"] is None, "failure-result-digest")
            by_sequence[sequence] = sample
        # Completion sorts before a new start at the same microsecond. This
        # validates the supplied observations, not the real executor's identity.
        events = sorted(event for sample in samples for event in
                        ((sample["started_us"], 1), (sample["completed_us"], -1)))
        active = 0
        for _instant, delta in events:
            active += delta
            need(active <= profile["max_in_flight"], "observed-concurrency-budget")
        indexed.append(by_sequence)
    return indexed


def summarize(profile, operations, samples):
    routes = {route["label"]: route for route in profile["routes"]}
    result = {}
    for phase in PHASES:
        phase_result = {}
        for label, route in routes.items():
            pairs_for_route = [(operation, samples[operation["sequence"]]) for operation in operations
                               if operation["phase"] == phase and operation["route"] == label]
            if not pairs_for_route:
                continue
            values = [sample for _operation, sample in pairs_for_route]
            phase_result[label] = {
                "count": len(values),
                "errors": sum(sample["outcome"] != "ok" or sample["status"] != route["expected_status"] or
                              sample["completed_us"] - sample["started_us"] > profile["request_timeout_ms"] * 1000
                              for sample in values),
                "database_warnings": sum(sample["database_warning_count"] for sample in values),
                "query_count": distribution([sample["query_count"] for sample in values]),
                "service_latency_us": distribution([sample["completed_us"] - sample["started_us"] for sample in values]),
                "arrival_latency_us": distribution([sample["completed_us"] - operation["due_us"] for operation, sample in pairs_for_route]),
                "scheduler_delay_us": distribution([sample["started_us"] - operation["due_us"] for operation, sample in pairs_for_route]),
            }
        result[phase] = phase_result
    return result


def compare(profile, sqlite, mysql):
    schedule = plan(profile)
    receipts = {"sqlite": sqlite, "mysql": mysql}
    samples = {engine: validate_receipt(profile, schedule, receipts[engine], engine) for engine in ENGINES}
    need(sqlite["executor_sha256"] == mysql["executor_sha256"], "different-executors")
    rounds = []
    errors = warnings = mismatches = 0
    for index, order in enumerate(ORDERS):
        measurements = {engine: summarize(profile, schedule["operations"], samples[engine][index]) for engine in ENGINES}
        comparisons = {}
        for phase in PHASES:
            mismatch_by_route = {}
            for label in measurements["sqlite"][phase]:
                left, right = (measurements[engine][phase][label] for engine in ENGINES)
                errors += left["errors"] + right["errors"]
                warnings += left["database_warnings"] + right["database_warnings"]
                different = sum(samples["sqlite"][index][op["sequence"]]["logical_result_sha256"] !=
                                samples["mysql"][index][op["sequence"]]["logical_result_sha256"]
                                for op in schedule["operations"] if op["phase"] == phase and op["route"] == label)
                mismatches += different
                mismatch_by_route[label] = different
            if phase == "warmup":
                continue
            comparisons[phase] = {}
            for label in measurements["sqlite"][phase]:
                left, right = (measurements[engine][phase][label] for engine in ENGINES)
                comparisons[phase][label] = {
                    "logical_result_mismatches": mismatch_by_route[label],
                    "arrival_p95_ratio_mysql_to_sqlite": round(right["arrival_latency_us"]["p95"] / left["arrival_latency_us"]["p95"], 6),
                    "service_p95_ratio_mysql_to_sqlite": round(right["service_latency_us"]["p95"] / left["service_latency_us"]["p95"], 6),
                }
        rounds.append({"round": index + 1, "engine_order": list(order), "measurements": measurements, "comparisons": comparisons})
    return {"version": 1, "scope": "isolated-http-read-measurements-only",
            "result": "comparable" if errors == warnings == mismatches == 0 else "failed",
            "profile_sha256": schedule["profile_sha256"], "schedule_sha256": schedule["schedule_sha256"],
            "receipt_sha256": {engine: digest(receipt) for engine, receipt in receipts.items()},
            "errors": errors, "database_warnings": warnings, "logical_result_mismatches": mismatches,
            "rounds": rounds, "http_execution_verified": False, "copied_data_execution_verified": False,
            "write_or_queue_workload_tested": False, "production_readiness": False}


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="action", required=True)
    planning = sub.add_parser("plan", help="Emit a deterministic schedule; performs no requests")
    planning.add_argument("--profile", type=Path, required=True)
    reduction = sub.add_parser("compare", help="Validate and reduce existing paired executor receipts")
    reduction.add_argument("--profile", type=Path, required=True)
    reduction.add_argument("--sqlite", type=Path, required=True)
    reduction.add_argument("--mysql", type=Path, required=True)
    args = parser.parse_args(argv)
    try:
        profile = load(args.profile)
        result = plan(profile) if args.action == "plan" else compare(profile, load(args.sqlite), load(args.mysql))
        print(json.dumps(result, sort_keys=True, separators=(",", ":")))
        return 1 if result.get("result") == "failed" else 0
    except (Refusal, RecursionError, OverflowError) as error:
        # Never echo paths, raw profile values, URLs, bodies, credentials or SQL.
        reason = str(error) if isinstance(error, Refusal) else "input-complexity"
        print(json.dumps({"result": "refused", "reason": reason, "production_readiness": False}))
        return 2


if __name__ == "__main__":
    sys.exit(main())
