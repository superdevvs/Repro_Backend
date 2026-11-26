import hashlib
import io
import json
import unittest
from dataclasses import asdict

from scripts.cutover.readiness import check, summarize_backup_health as health
from scripts.cutover.runner import Identity, digest


class BackupHealthSummaryTests(unittest.TestCase):
    def setUp(self):
        self.identity = asdict(Identity("health-fixture", "a" * 40, "b" * 64, "c" * 64,
                                        "d" * 64, "f1d53bff-40ce-46ba-a35b-4591b2be65b7"))
        self.stop = 1_800_000_000
        self.start = self.stop - check.BACKUP_WINDOW_SECONDS
        self.policy = {"version": 1, "identity": self.identity, "operations": {
            "window_end_unix_s": self.stop, "interval_s": 300,
            "evidence_valid_until_unix_s": self.stop + 3600,
            "destination_sha256": "e" * 64, "monitor_profile_sha256": "f" * 64,
        }}

    def records(self, *, interval=300):
        operations = self.policy["operations"]
        operations["interval_s"] = interval
        header = {"schema": health.SCHEMA, "identity_sha256": digest(self.identity),
                  "window_start_unix_s": self.start, "window_end_unix_s": self.stop,
                  "interval_s": interval, "external_observer_verified": True,
                  "destination_sha256": operations["destination_sha256"],
                  "monitor_profile_sha256": operations["monitor_profile_sha256"]}
        rows = [{"slot_start_unix_s": slot,
                 "sample_sha256": hashlib.sha256(str(slot).encode("ascii")).hexdigest(),
                 "newest_verified_base_unix_s": slot - 1800,
                 "recoverable_through_unix_s": slot} for slot in range(self.start, self.stop, interval)]
        return [header, *rows]

    def summarize(self, records, *, pin=True, raw_suffix=b"", now=None):
        payload = b"".join((json.dumps(record) + "\n").encode("utf-8") for record in records) + raw_suffix
        if pin:
            self.policy["operations"]["backup_health_report_sha256"] = hashlib.sha256(payload).hexdigest()
        return health.summarize(self.policy, io.BytesIO(payload), now_unix_s=self.stop if now is None else now)

    def test_partial_slot_and_exact_existing_receipt_shape(self):
        interval = 299
        records = self.records(interval=interval)
        summary = self.summarize(records)
        self.assertEqual(summary["covered_duration_s"], check.BACKUP_WINDOW_SECONDS)
        self.assertEqual(summary["distinct_scheduled_observations"], len(records) - 1)
        self.assertEqual(summary["max_snapshot_age_s"], 1800 + interval)
        self.assertEqual(summary["duration_with_lag_at_most_five_minutes_s"], check.BACKUP_WINDOW_SECONDS)
        self.assertTrue(health.thresholds_met(summary))
        self.policy["operations"]["backup_health_summary_sha256"] = digest(summary)
        check.review_backup_health(self.policy, {"backup_health": summary})

    def test_lag_exhausts_during_one_slot_and_unknown_stays_unknown(self):
        records = self.records()
        records[1]["recoverable_through_unix_s"] = self.start - 240
        records[2]["recoverable_through_unix_s"] = None
        records[3]["recoverable_through_unix_s"] = records[3]["slot_start_unix_s"] - 300
        summary = self.summarize(records)
        self.assertEqual(summary["duration_with_lag_at_most_five_minutes_s"],
                         check.BACKUP_WINDOW_SECONDS - 240 - 300 - 300)

    def test_age_reaches_strict_limit_at_end_of_slot(self):
        records = self.records()
        records[1]["newest_verified_base_unix_s"] = self.start - check.BACKUP_MAX_AGE_SECONDS + 300
        summary = self.summarize(records)
        self.assertEqual(summary["max_snapshot_age_s"], check.BACKUP_MAX_AGE_SECONDS)
        self.assertFalse(health.thresholds_met(summary))
        self.policy["operations"]["backup_health_summary_sha256"] = digest(summary)
        with self.assertRaisesRegex(check.Failed, "reached 26 hours"):
            check.review_backup_health(self.policy, {"backup_health": summary})

    def test_exact_elapsed_lag_threshold(self):
        records = self.records()
        # 6,048 untimely seconds are exactly one percent of seven days.
        for record in records[1:21]:
            record["recoverable_through_unix_s"] = None
        records[21]["recoverable_through_unix_s"] = records[21]["slot_start_unix_s"] - 48
        self.assertTrue(health.thresholds_met(self.summarize(records)))
        records[21]["recoverable_through_unix_s"] -= 1
        self.assertFalse(health.thresholds_met(self.summarize(records)))

    def test_missing_duplicate_and_extra_slots_refused_even_when_pinned(self):
        records = self.records()
        for changed in (records[:5] + records[6:], records[:6] + records[5:], records + [records[-1]]):
            with self.subTest(count=len(changed)), self.assertRaisesRegex(check.Failed, "observation"):
                self.summarize(changed)
        with self.assertRaisesRegex(check.Failed, "outside its exact schedule"):
            self.summarize(records, raw_suffix=b"\n")
        records[2]["sample_sha256"] = records[1]["sample_sha256"]
        with self.assertRaisesRegex(check.Failed, "sample was reused"):
            self.summarize(records)

    def test_impossible_timestamps_and_unknown_base_refused(self):
        for field, value in (("newest_verified_base_unix_s", None),
                             ("newest_verified_base_unix_s", self.start + 1),
                             ("recoverable_through_unix_s", self.start + 1),
                             ("recoverable_through_unix_s", self.start - 1801)):
            with self.subTest(field=field, value=value), self.assertRaisesRegex(check.Failed, "timestamp"):
                records = self.records()
                records[1][field] = value
                self.summarize(records)

    def test_raw_pin_scope_and_review_window_enforced(self):
        records = self.records()
        self.summarize(records)
        records[1]["sample_sha256"] = "1" * 64
        with self.assertRaisesRegex(check.Failed, "report digest"):
            self.summarize(records, pin=False)
        for field, value in (("monitor_profile_sha256", "2" * 64), ("external_observer_verified", 1)):
            with self.subTest(field=field), self.assertRaisesRegex(check.Failed, "reviewed scope or window"):
                changed = [records[0] | {field: value}, *records[1:]]
                self.summarize(changed)
        for now in (self.stop - 1, self.stop + 3601):
            with self.subTest(now=now), self.assertRaisesRegex(check.Failed, "future or has expired"):
                self.summarize(records, now=now)

    def test_bounded_stream_and_duplicate_json_fields(self):
        records = self.records()
        records[1]["sample_sha256"] = "a" * health.OBSERVATION_LIMIT
        with self.assertRaisesRegex(check.Failed, "record limit"):
            self.summarize(records)
        with self.assertRaisesRegex(check.Failed, "observation is malformed"):
            health.read_record(io.BytesIO(b'{"key":1,"key":2}\n'), health.OBSERVATION_LIMIT)


if __name__ == "__main__":
    unittest.main()
