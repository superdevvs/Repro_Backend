import importlib.util
import json
from pathlib import Path
import unittest
from unittest.mock import Mock

SPEC = importlib.util.spec_from_file_location("notification_comparison", Path(__file__).with_name("compare.py"))
compare = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(compare)


def workers(plan):
    result = []
    for slot in range(plan["workers"]):
        samples = []
        for index in range(plan["measured_operations"]):
            write = index % 5 == 0
            read_index = index // 5 * 4 + index % 5 - 1
            user = 1 if write else 1 + ((read_index + slot * (100 // plan["workers"])) % 100)
            samples.append(["write" if write else "read", 100 + index, user])
        result.append({"worker": slot, "samples": samples, "measured_started_ns": 1000000 + slot * 1000,
                       "measured_ended_ns": 101000000 + slot * 1000, "sqlite_locked_write_retry_events": slot})
    return result


class ComparisonTest(unittest.TestCase):
    def test_profile_is_bounded_and_has_no_custom_sql_or_customer_identifiers(self):
        plan = compare.profile()
        self.assertEqual(100, plan["users"])
        self.assertEqual(10, plan["warmup_operations"])
        for count in (0, 5, True):
            with self.assertRaises(compare.isolation.Refusal):
                compare.profile(workers=count)
        for count in (24, 501, True):
            with self.assertRaises(compare.isolation.Refusal):
                compare.profile(operations=count)

    def test_default_measurement_counts_latency_and_all_users(self):
        plan = compare.profile()
        result = compare.summarize(workers(plan), plan)
        self.assertEqual(200, result["operations"])
        self.assertEqual(160, result["latency_us"]["read"]["count"])
        self.assertEqual(40, result["latency_us"]["write"]["count"])
        self.assertEqual(100, result["read_user_coverage"])
        self.assertGreater(result["all_worker_overlap_us"], 0)
        self.assertEqual(1, result["sqlite_locked_write_retry_events"])

    def test_missing_failed_wrong_kind_and_wrong_owner_samples_are_not_hidden(self):
        plan = compare.profile()
        for change in ("missing", "failure", "owner", "duration"):
            with self.subTest(change=change):
                samples = workers(plan)
                if change == "missing":
                    samples[0]["samples"].pop()
                elif change == "failure":
                    samples[0]["samples"][0][0] = "failed"
                elif change == "owner":
                    samples[0]["samples"][0][2] = 2
                else:
                    samples[0]["samples"][0][1] = -1
                with self.assertRaises(compare.isolation.Refusal):
                    compare.summarize(samples, plan)

    def test_serial_execution_cannot_claim_concurrent_evidence(self):
        plan = compare.profile()
        samples = workers(plan)
        samples[1]["measured_started_ns"] = samples[0]["measured_ended_ns"] + 1
        samples[1]["measured_ended_ns"] = samples[1]["measured_started_ns"] + 1000000
        with self.assertRaisesRegex(compare.isolation.Refusal, "workers-did-not-overlap"):
            compare.summarize(samples, plan)

    def test_only_needed_mysql_principal_is_sent_to_each_child(self):
        config = {"database": "repro_mysql84_acceptance", "socket": "/fixture/mysql.sock", "server_uuid": "fixture",
                  "accounts": {role: {"username": role, "password": "private-" + role} for role in ("admin", "worker", "reader")}}
        for action, role in (("setup", "admin"), ("worker", "worker"), ("verify", "reader"), ("cleanup", "admin")):
            value = compare.request_payload(config, compare.profile(), "mysql", action, "0" * 32)
            self.assertEqual(role, value["account"]["username"])
            for other in {"admin", "worker", "reader"} - {role}:
                self.assertNotIn("private-" + other, json.dumps(value))
        sqlite = compare.request_payload(config, compare.profile(), "sqlite", "worker", "0" * 32)
        self.assertNotIn("private-", json.dumps(sqlite))

    def test_dead_worker_at_barrier_is_not_retried(self):
        future = Mock()
        future.done.return_value = True
        future.result.side_effect = compare.isolation.Refusal("fixture-deadlock")
        with self.assertRaisesRegex(compare.isolation.Refusal, "fixture-deadlock"):
            compare.wait_barrier(Path("/unused"), "ready-", 1, [future])
        future.result.assert_called_once()

    def test_percentiles_use_nearest_rank_and_do_not_average_rounds(self):
        self.assertEqual(95, compare.percentile(list(range(1, 101)), .95))
        self.assertEqual(1, compare.percentile([1], .95))
        with self.assertRaises(compare.isolation.Refusal):
            compare.percentile([], .95)


if __name__ == "__main__":
    unittest.main()
