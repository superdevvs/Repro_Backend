"""Small synthetic application comparison, not HTTP/FPM or production evidence."""
from __future__ import annotations

import argparse
from concurrent.futures import ThreadPoolExecutor
import hashlib
import importlib.util
import json
import math
import os
from pathlib import Path
import secrets
import signal
import sys
import time

SPEC = importlib.util.spec_from_file_location("isolated_workload", Path(__file__).with_name("run.py"))
isolation = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(isolation)


def profile(workers=2, operations=100):
    isolation.need(type(workers) is int and 1 <= workers <= 4, "worker-budget")
    isolation.need(type(operations) is int and 25 <= operations <= 500, "operation-budget")
    return {"version": 1, "users": 100, "baseline_ids": 10, "workers": workers,
            "warmup_operations": 10, "measured_operations": operations, "write_every": 5}


def percentile(values, fraction):
    isolation.need(bool(values), "missing-latency-samples")
    ordered = sorted(values)
    return ordered[max(0, math.ceil(len(ordered) * fraction) - 1)]


def summarize(workers, plan):
    isolation.need(len(workers) == plan["workers"] and
                   sorted(item.get("worker") for item in workers) == list(range(plan["workers"])), "worker-result-set")
    latencies = {"read": [], "write": []}
    readers = set()
    retries = 0
    starts, ends = [], []
    for worker in workers:
        samples = worker.get("samples")
        isolation.need(isinstance(samples, list) and len(samples) == plan["measured_operations"], "measured-sample-count")
        for index, sample in enumerate(samples):
            expected = "write" if index % plan["write_every"] == 0 else "read"
            isolation.need(isinstance(sample, list) and len(sample) == 3 and sample[0] == expected
                           and type(sample[1]) is int and 0 < sample[1] <= 120000000
                           and type(sample[2]) is int and 1 <= sample[2] <= plan["users"], "measured-sample-shape")
            if expected == "write":
                isolation.need(sample[2] == 1, "shared-write-user")
            else:
                readers.add(sample[2])
            latencies[expected].append(sample[1])
        start, end = worker.get("measured_started_ns"), worker.get("measured_ended_ns")
        isolation.need(type(start) is int and type(end) is int and 0 < start < end, "monotonic-worker-window")
        starts.append(start)
        ends.append(end)
        count = worker.get("sqlite_locked_write_retry_events")
        isolation.need(type(count) is int and count >= 0, "retry-event-count")
        retries += count
    overlap = max(0, (min(ends) - max(starts)) // 1000)
    if plan["workers"] > 1:
        isolation.need(overlap > 0, "workers-did-not-overlap")
    measured_window = (max(ends) - min(starts)) / 1000
    return {"operations": sum(map(len, latencies.values())),
            "latency_us": {kind: {"count": len(values), "p50": percentile(values, .5),
                                  "p95": percentile(values, .95), "max": max(values)}
                           for kind, values in latencies.items()},
            "read_user_coverage": len(readers), "measured_window_us": math.ceil(measured_window),
            "all_worker_overlap_us": overlap,
            "operations_per_second": round(sum(map(len, latencies.values())) * 1e6 / measured_window, 3),
            "sqlite_locked_write_retry_events": retries}


def request_payload(config, plan, engine, action, token, slot=0):
    role = {"setup": "admin", "cleanup": "admin", "verify": "reader", "worker": "worker"}[action]
    return {"target": {key: config[key] for key in ("database", "socket", "server_uuid")},
            "account": config["accounts"][role] if engine == "mysql" else None,
            "profile": plan, "engine": engine, "action": action, "token": token, "worker": slot}


def wait_barrier(directory, prefix, count, futures):
    deadline = time.monotonic() + 40
    while True:
        for future in futures:
            if future.done():
                # Any early completion is a failure, including a worker that
                # incorrectly bypassed the barrier. No benchmark retries.
                future.result()
                raise isolation.Refusal("worker-finished-before-barrier")
        ready = [directory / (prefix + str(slot)) for slot in range(count)]
        if all(path.is_file() and not path.is_symlink() for path in ready):
            return
        isolation.need(time.monotonic() < deadline, "worker-barrier-timeout")
        time.sleep(.02)


def invoke(args, config, plan, engine, action, shared, token, slot=0):
    runtime = shared / (action + "-" + str(slot))
    isolation.prepare_runtime(runtime, args.app)
    env = isolation.clean_environment(runtime)
    env["REPRO_WORKLOAD_PARENT_NETWORK_NAMESPACE"] = args.supervised_parent_net
    command = [str(args.php), "-d", "display_errors=0", "-d", "log_errors=0", "-d", "memory_limit=192M",
               "-d", "auto_prepend_file=", "-d", "auto_append_file=", "-d", "opcache.enable_cli=0",
               "-d", "max_execution_time=90", str(Path(__file__).with_name("compare.php")),
               str(args.app), str(runtime), str(shared)]
    raw = json.dumps(request_payload(config, plan, engine, action, token, slot), separators=(",", ":")).encode()
    result = isolation.bounded_process(command, raw, env, runtime)
    isolation.need(result.get("kind") == "notification-comparison" and result.get("action") == action
                   and result.get("engine") == engine, "comparison-child-contract")
    return result


def run_engine(args, config, plan, engine, index):
    shared = args.runtime / (str(index) + "-" + engine)
    shared.mkdir(mode=0o700)
    token = secrets.token_hex(16)
    started = False
    try:
        invoke(args, config, plan, engine, "setup", shared, token)
        started = True
        with ThreadPoolExecutor(max_workers=plan["workers"]) as pool:
            workers = [pool.submit(invoke, args, config, plan, engine, "worker", shared, token, slot)
                       for slot in range(plan["workers"])]
            wait_barrier(shared, "boot-ready-", plan["workers"], workers)
            (shared / "warmup-go").touch(exist_ok=False)
            wait_barrier(shared, "ready-", plan["workers"], workers)
            (shared / "go").touch(exist_ok=False)
            results = [future.result() for future in workers]
        summary = summarize(results, plan)
        verified = invoke(args, config, plan, engine, "verify", shared, token)
        isolation.need(verified.get("exact_receipt_union_verified") is True
                       and verified.get("owner_isolation_verified") is True and verified.get("watermarks_verified") is True,
                       "receipt-verification-required")
        result = {"engine": engine, "round": index, "measurement": summary,
                  "verification": {key: verified[key] for key in ("logical_receipt_sha256", "verified_users",
                      "verified_contended_user_receipts", "exact_receipt_union_verified", "owner_isolation_verified", "watermarks_verified")},
                  "workers": results}
    finally:
        # A failed setup may have created only the marker. Never blindly drop a
        # pre-existing schema; cleanup verifies this invocation's random token.
        if started:
            cleanup = invoke(args, config, plan, engine, "cleanup", shared, token)
            isolation.need(cleanup.get("fixture_tables_removed") is True, "fixture-cleanup-required")
    return result


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--app", type=Path, required=True)
    parser.add_argument("--runtime", type=Path, required=True)
    parser.add_argument("--php", type=Path, default=Path("/usr/bin/php"))
    parser.add_argument("--supervised-parent-net", required=True)
    parser.add_argument("--workers", type=int, default=2)
    parser.add_argument("--operations", type=int, default=100)
    args = parser.parse_args(argv)
    os.umask(0o077)
    isolation.need(sys.platform == "linux", "isolated-linux-runtime-required")
    proof = isolation.namespace_check(args.supervised_parent_net)
    proof.update(isolation.supervised_status_check())
    config = isolation.credentials(sys.stdin.buffer.read(isolation.MAX_INPUT + 1))
    isolation.validate_source(args.app)
    isolation.prepare_runtime(args.runtime, args.app)
    source_hash = isolation.source_digest(args.app)
    plan = profile(args.workers, args.operations)
    profile_hash = hashlib.sha256(json.dumps(plan, sort_keys=True, separators=(",", ":")).encode()).hexdigest()
    plan["watermark_ms"] = int(time.time() * 1000) - 60000
    results = []
    # Two fresh rounds, with engine order reversed to expose warm-cache/order
    # sensitivity. Results are not pooled into a universal speedup assertion.
    for index, order in enumerate((("sqlite", "mysql"), ("mysql", "sqlite")), 1):
        pair = [run_engine(args, config, plan, engine, index) for engine in order]
        isolation.need(pair[0]["verification"]["logical_receipt_sha256"] == pair[1]["verification"]["logical_receipt_sha256"],
                       "cross-engine-logical-receipt-mismatch")
        results.extend(pair)
    isolation.need(isolation.source_digest(args.app) == source_hash, "source-changed-during-comparison")
    report = {"version": 1, "scope": "local-synthetic-notification-comparison", "result": "passed",
              "source_sha256": source_hash, "profile_sha256": profile_hash, "profile": plan,
              "coordinator_sha256": hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),
              "worker_sha256": hashlib.sha256(Path(__file__).with_name("compare.php").read_bytes()).hexdigest(),
              "isolation_runner_sha256": hashlib.sha256(Path(__file__).with_name("run.py").read_bytes()).hexdigest(),
              "server_uuid": config["server_uuid"], "isolation": proof, "rounds": results,
              "http_fpm_load_tested": False, "queue_load_tested": False, "copied_data_tested": False,
              "host_capacity_tested": False, "production_cutover": False,
              "timing_scope": "controller-or-service-call-and-serialization; bootstrap/warmup/verification excluded",
              "retry_scope": "only the application's SQLite LockedWrite events; not total database lock waits"}
    isolation.save_report(args.runtime, report)
    compact = {key: value for key, value in report.items() if key != "rounds"}
    compact["rounds"] = [{key: value for key, value in item.items() if key != "workers"} for item in results]
    print(json.dumps(compact, sort_keys=True))


if __name__ == "__main__":
    def interrupted(_signum, _frame):
        raise isolation.Refusal("comparison-interrupted")
    for sig in (signal.SIGTERM, signal.SIGINT, getattr(signal, "SIGHUP", signal.SIGTERM)):
        signal.signal(sig, interrupted)
    try:
        main()
    except BaseException as error:
        print(json.dumps({"scope": "local-synthetic-notification-comparison", "result": "refused",
                          "reason": str(error) if isinstance(error, isolation.Refusal) else type(error).__name__,
                          "production_cutover": False}))
        sys.exit(1)
