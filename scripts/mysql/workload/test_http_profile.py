"""Synthetic, network-free workload-contract and measurement regression tests."""
import copy
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

SPEC = importlib.util.spec_from_file_location("http_profile", Path(__file__).with_name("http_profile.py"))
workload = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(workload)


def profile():
    # Explicit synthetic fixture weights: never a proposed production profile.
    return {
        "version": 1, "scope": "isolated-http-read-workload", "release_sha": "a" * 40,
        "app_source_sha256": "b" * 64, "source_snapshot_sha256": "c" * 64,
        "host_profile_sha256": "d" * 64, "fixture_manifest_sha256": "e" * 64,
        "response_contract_sha256": "f" * 64,
        "mysql_server_uuid": "11111111-2222-3333-4444-555555555555",
        "logical_time_utc": "2026-11-22T12:00:00Z", "max_in_flight": 2, "request_timeout_ms": 1000,
        "routes": [
            {"label": "common", "method": "GET", "path_template": "/api/fixture/{item}",
             "role": "staff", "fixture": "common-fixture", "weight": 3, "expected_status": 200},
            {"label": "rare", "method": "GET", "path_template": "/api/other-fixture",
             "role": "client", "fixture": "rare-fixture", "weight": 1, "expected_status": 200},
        ],
        "phases": [
            {"name": "warmup", "duration_ms": 4000, "arrival_rate_milli_rps": 1000},
            {"name": "peak", "duration_ms": 8000, "arrival_rate_milli_rps": 1000},
            {"name": "surge", "duration_ms": 4000, "arrival_rate_milli_rps": 2000},
        ],
    }


def receipt(policy, engine):
    schedule = workload.plan(policy)
    return {
        "version": 1, "engine": engine, "profile_sha256": schedule["profile_sha256"],
        "schedule_sha256": schedule["schedule_sha256"], "executor_sha256": "0" * 64,
        "database_identity": policy["source_snapshot_sha256"] if engine == "sqlite" else policy["mysql_server_uuid"],
        "runs": [
            {"round": number + 1, "engine_order": list(order), "samples": [
                {"sequence": op["sequence"], "started_us": op["due_us"] + 10,
                 "completed_us": op["due_us"] + 110, "status": 200, "outcome": "ok",
                 "query_count": 3, "database_warning_count": 0,
                 "logical_result_sha256": workload.digest({"sequence": op["sequence"], "synthetic": True})}
                for op in schedule["operations"]]}
            for number, order in enumerate(workload.ORDERS)
        ],
    }


class HttpProfileTest(unittest.TestCase):
    def setUp(self):
        self.profile = profile()
        self.sqlite = receipt(self.profile, "sqlite")
        self.mysql = receipt(self.profile, "mysql")

    def compare(self):
        return workload.compare(self.profile, self.sqlite, self.mysql)

    def test_schedule_is_repeatable_weighted_and_phase_bounded(self):
        schedule = workload.plan(self.profile)
        self.assertEqual(schedule, workload.plan(copy.deepcopy(self.profile)))
        operations = schedule["operations"]
        self.assertEqual(20, len(operations))
        self.assertEqual(list(range(20)), [op["sequence"] for op in operations])
        self.assertEqual(6, sum(op["phase"] == "peak" and op["route"] == "common" for op in operations))
        self.assertEqual(2, sum(op["phase"] == "peak" and op["route"] == "rare" for op in operations))
        self.assertEqual(4_000_000, operations[4]["due_us"])
        self.assertEqual(500_000, operations[13]["due_us"] - operations[12]["due_us"])
        self.assertEqual(schedule["schedule_sha256"], workload.digest({key: value for key, value in schedule.items() if key != "schedule_sha256"}))

    def test_no_defaults_for_unreviewed_traffic_or_scope(self):
        cases = []
        item = profile(); del item["routes"][0]["weight"]; cases.append(item)
        item = profile(); item["routes"][0]["method"] = "POST"; cases.append(item)
        item = profile(); item["routes"][0]["path_template"] = "https://example.com/api/fixture"; cases.append(item)
        item = profile(); item["routes"][0]["path_template"] = "/api/fixture/123"; cases.append(item)
        item = profile(); item["routes"][0]["path_template"] = "/api/fixture?token=private"; cases.append(item)
        item = profile(); item["routes"][0]["authorization"] = "private"; cases.append(item)
        item = profile(); item["max_in_flight"] = True; cases.append(item)
        item = profile(); item["logical_time_utc"] = "2026-02-30T00:00:00Z"; cases.append(item)
        item = profile(); item["phases"][2]["arrival_rate_milli_rps"] = 500; cases.append(item)
        for item in cases:
            with self.subTest(item=cases.index(item)), self.assertRaises(workload.Refusal):
                workload.plan(item)

    def test_weighted_rare_route_cannot_disappear_or_exceed_operation_budget(self):
        self.profile["routes"][0]["weight"] = 1000
        with self.assertRaisesRegex(workload.Refusal, "measured-route-uncovered"):
            workload.plan(self.profile)
        self.profile = profile()
        self.profile["phases"][0].update(duration_ms=600_000, arrival_rate_milli_rps=1_000_000)
        with self.assertRaisesRegex(workload.Refusal, "operation-budget"):
            workload.plan(self.profile)

    def test_valid_samples_reduce_without_claiming_execution_or_readiness(self):
        result = self.compare()
        self.assertEqual("comparable", result["result"])
        self.assertEqual(0, result["errors"])
        self.assertEqual(2, len(result["rounds"]))
        self.assertEqual(1, result["rounds"][0]["comparisons"]["peak"]["rare"]["service_p95_ratio_mysql_to_sqlite"])
        self.assertFalse(result["http_execution_verified"])
        self.assertFalse(result["copied_data_execution_verified"])
        self.assertFalse(result["write_or_queue_workload_tested"])
        self.assertFalse(result["production_readiness"])

    def test_dropped_duplicate_and_invented_samples_are_refused(self):
        for change in ("missing", "duplicate", "extra", "bool"):
            with self.subTest(change=change):
                data = copy.deepcopy(self.mysql)
                samples = data["runs"][0]["samples"]
                if change == "missing": samples.pop()
                if change == "duplicate": samples[-1] = samples[0]
                if change == "extra": samples.append(samples[0])
                if change == "bool": samples[0]["sequence"] = False
                with self.assertRaises(workload.Refusal):
                    workload.compare(self.profile, self.sqlite, data)

    def test_receipt_order_does_not_affect_results(self):
        for run in self.mysql["runs"]:
            run["samples"].reverse()
        self.assertEqual("comparable", self.compare()["result"])

    def test_changed_release_snapshot_host_clock_and_fixture_invalidate_receipts(self):
        for key in ("release_sha", "source_snapshot_sha256", "host_profile_sha256", "fixture_manifest_sha256",
                    "response_contract_sha256", "logical_time_utc"):
            with self.subTest(key=key):
                data = profile()
                data[key] = "2026-11-22T12:00:01Z" if key == "logical_time_utc" else "1" * len(data[key])
                with self.assertRaisesRegex(workload.Refusal, "receipt-binding"):
                    workload.compare(data, self.sqlite, self.mysql)

    def test_wrong_identity_executor_engine_or_round_refused(self):
        for change in ("identity", "executor", "engine", "round", "order", "missing-round"):
            with self.subTest(change=change):
                data = copy.deepcopy(self.mysql)
                if change == "identity": data["database_identity"] = "different"
                if change == "executor": data["executor_sha256"] = "1" * 64
                if change == "engine": data["engine"] = "sqlite"
                if change == "round": data["runs"][1]["round"] = 1
                if change == "order": data["runs"][1]["engine_order"] = ["sqlite", "mysql"]
                if change == "missing-round": data["runs"].pop()
                with self.assertRaises(workload.Refusal):
                    workload.compare(self.profile, self.sqlite, data)

    def test_scheduler_delay_cannot_hide_behind_fast_service_latency(self):
        for sample in self.mysql["runs"][0]["samples"]:
            sample["started_us"] += 200_000
            sample["completed_us"] += 200_000
        result = self.compare()["rounds"][0]["comparisons"]["peak"]["common"]
        self.assertEqual(1, result["service_p95_ratio_mysql_to_sqlite"])
        self.assertGreater(result["arrival_p95_ratio_mysql_to_sqlite"], 1000)

    def test_rare_route_regression_is_not_hidden_in_pooled_numbers(self):
        operations = workload.plan(self.profile)["operations"]
        rare = next(op for op in operations if op["phase"] == "peak" and op["route"] == "rare")
        self.mysql["runs"][0]["samples"][rare["sequence"]]["completed_us"] += 10_000
        result = self.compare()["rounds"][0]["comparisons"]["peak"]
        self.assertEqual(1, result["common"]["arrival_p95_ratio_mysql_to_sqlite"])
        self.assertGreater(result["rare"]["arrival_p95_ratio_mysql_to_sqlite"], 90)

    def test_warmup_timings_do_not_pollute_measured_percentiles(self):
        self.mysql["runs"][0]["samples"][0]["completed_us"] += 100_000
        result = self.compare()["rounds"][0]
        self.assertEqual(1, result["comparisons"]["peak"]["common"]["service_p95_ratio_mysql_to_sqlite"])
        self.assertGreater(result["measurements"]["mysql"]["warmup"]["common"]["service_latency_us"]["max"], 100_000)

    def test_declared_concurrency_and_timeout_budgets_are_not_ignored(self):
        self.profile["max_in_flight"] = 1
        self.sqlite = receipt(self.profile, "sqlite")
        self.mysql = receipt(self.profile, "mysql")
        self.mysql["runs"][0]["samples"][0]["completed_us"] = 1_100_000
        with self.assertRaisesRegex(workload.Refusal, "observed-concurrency-budget"):
            self.compare()
        self.mysql = receipt(self.profile, "mysql")
        self.mysql["runs"][0]["samples"][-1]["completed_us"] += 1_000_000
        self.assertEqual("failed", self.compare()["result"])

    def test_warmup_logical_mismatch_is_not_silently_ignored(self):
        self.mysql["runs"][0]["samples"][0]["logical_result_sha256"] = "9" * 64
        result = self.compare()
        self.assertEqual("failed", result["result"])
        self.assertEqual(1, result["logical_result_mismatches"])

    def test_errors_unexpected_success_status_and_warnings_fail_even_in_warmup(self):
        for change in ("timeout", "status", "warning"):
            with self.subTest(change=change):
                data = copy.deepcopy(self.mysql)
                sample = data["runs"][0]["samples"][0]
                if change == "timeout": sample.update(outcome="timeout", status=None, logical_result_sha256=None)
                if change == "status": sample["status"] = 201
                if change == "warning": sample["database_warning_count"] = 1
                result = workload.compare(self.profile, self.sqlite, data)
                self.assertEqual("failed", result["result"])

    def test_different_logical_answer_fails_without_emitting_payload_or_hash(self):
        self.mysql["runs"][0]["samples"][4]["logical_result_sha256"] = "9" * 64
        result = self.compare()
        self.assertEqual("failed", result["result"])
        self.assertEqual(1, result["logical_result_mismatches"])
        self.assertNotIn("9" * 64, json.dumps(result))

    def test_bad_sample_types_and_early_time_fail_closed(self):
        for key, value in (("started_us", -1), ("completed_us", 10), ("query_count", True),
                           ("database_warning_count", -1), ("status", True), ("outcome", "unreviewed")):
            with self.subTest(key=key):
                data = copy.deepcopy(self.mysql)
                data["runs"][0]["samples"][0][key] = value
                with self.assertRaises(workload.Refusal):
                    workload.compare(self.profile, self.sqlite, data)

    def test_nearest_rank_percentiles(self):
        self.assertEqual({"p50": 50, "p95": 95, "p99": 99, "max": 100}, workload.distribution(list(range(1, 101))))

    def test_cli_outputs_sanitized_failures_and_valid_report(self):
        script = str(Path(__file__).with_name("http_profile.py"))
        with tempfile.TemporaryDirectory(prefix="repro-http-contract-") as directory:
            base = Path(directory)
            for name, data in (("profile", self.profile), ("sqlite", self.sqlite), ("mysql", self.mysql)):
                (base / (name + ".json")).write_text(json.dumps(data), encoding="utf-8")
            command = [sys.executable, "-B", script, "compare", "--profile", str(base / "profile.json"),
                       "--sqlite", str(base / "sqlite.json"), "--mysql", str(base / "mysql.json")]
            result = subprocess.run(command, capture_output=True, text=True, timeout=10)
            self.assertEqual(0, result.returncode, result.stderr)
            self.assertEqual("comparable", json.loads(result.stdout)["result"])
            (base / "mysql.json").write_text('{"private":"SECRET-NOT-PRINTED","private":null}', encoding="utf-8")
            result = subprocess.run(command, capture_output=True, text=True, timeout=10)
            self.assertEqual(2, result.returncode)
            self.assertEqual("duplicate-json-key", json.loads(result.stdout)["reason"])
            self.assertNotIn("SECRET-NOT-PRINTED", result.stdout + result.stderr)
            self.assertNotIn(str(base), result.stdout + result.stderr)

    def test_nonstandard_and_oversized_numeric_json_are_safely_refused(self):
        with tempfile.TemporaryDirectory(prefix="repro-http-json-") as directory:
            path = Path(directory) / "input.json"
            for raw in ('{"value":NaN}', '{"value":Infinity}', '{"value":' + '9' * 5000 + '}'):
                path.write_text(raw, encoding="utf-8")
                with self.assertRaises(workload.Refusal):
                    workload.load(path)


if __name__ == "__main__":
    unittest.main()
