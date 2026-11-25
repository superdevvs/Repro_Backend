"""Off-server backup health observer against a synthetic bucket listing (no network)."""
from __future__ import annotations

import bisect
from dataclasses import asdict
import datetime as dt
import hashlib
import io
import json
from pathlib import Path
import sys
import tempfile
import unittest
import uuid

import observe_backup_health as observer
import publish_backup as backup

REPO = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(REPO))
from scripts.cutover.readiness import check, summarize_backup_health as health  # noqa: E402
from scripts.cutover.runner import Identity, digest  # noqa: E402

SOURCE = "11111111-2222-4333-8444-555555555555"
ARCHIVE = SOURCE + ".closed-binlogs"
INTERVAL = 60
STOP = 1_800_000_000 - 1_800_000_000 % 300
START = STOP - check.BACKUP_WINDOW_SECONDS
ROTATION = 120


def stamp(seconds: int) -> str:
    return dt.datetime.fromtimestamp(seconds, dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


class FakeBucket:
    """Completion records and small artifacts, as the publisher leaves them, with arrival times."""

    def __init__(self):
        self.visible = {}  # identity -> sorted [(visible_at, backup_id)]
        self.objects = {}
        self.ids = {}
        self.reads = 0
        self.uploading = {}  # identity -> backup IDs whose folder is listed before completion.json exists

    def publish(self, identity: str, created: int, artifacts: dict, *, delay: int = 30) -> None:
        backup_id = str(uuid.UUID(int=len(self.objects) + 1))
        manifest = {"schema": backup.INPUT_SCHEMA, "backup_id": backup_id, "created_at": stamp(created),
                    "scope": "database", "release": "a" * 40, "identity": identity,
                    "artifacts": [{"path": name, "bytes": len(data), "sha256": backup.sha256(data)}
                                  for name, data in artifacts.items()]}
        completion = {"schema": backup.COMPLETE_SCHEMA, "status": "artifact-readback-verified",
                      "input_manifest_sha256": "1" * 64, "input": manifest, "verified_at": stamp(created + delay),
                      "proof_limits": []}
        self.objects[(identity, backup_id)] = (backup.encode(completion), artifacts)
        bisect.insort(self.visible.setdefault(identity, []), (created + delay, backup_id))

    def list_backups(self, identity: str, now: int) -> list[str]:
        items = self.visible.get(identity, [])
        if len(self.ids.get(identity, ())) != len(items):
            self.ids[identity] = [backup_id for _, backup_id in items]
        return self.ids[identity][:bisect.bisect_right(items, (now, "~"))] + self.uploading.get(identity, [])

    def completion(self, identity: str, backup_id: str) -> bytes | None:
        if backup_id in self.uploading.get(identity, []):
            return None  # The publisher writes completion.json last.
        self.reads += 1
        return self.objects[(identity, backup_id)][0]

    def artifact(self, identity: str, backup_id: str, path: str) -> bytes:
        return self.objects[(identity, backup_id)][1][path]


def receipt(number: int) -> bytes:
    return json.dumps({"pitr": {"binlog_file": f"binlog.{number:06d}", "binlog_position": 4}}).encode()


class ObserverTests(unittest.TestCase):
    def setUp(self):
        folder = tempfile.TemporaryDirectory()
        self.addCleanup(folder.cleanup)
        self.root = Path(folder.name)
        (self.root / "samples").mkdir()
        self.plan = {"version": 1, "interval_s": INTERVAL, "journal": str(self.root / "observations.jsonl"),
                     "samples_dir": str(self.root / "samples"), "database_identity": SOURCE,
                     "binlog_identity": ARCHIVE}
        self.bucket = FakeBucket()

    def history(self, *, outage=None):
        """Daily bases at 03:30 and a closed binlog published every two minutes, from a day before the window."""
        number, pending = 1, []
        for moment in range(START - 86400, STOP + ROTATION, ROTATION):
            if (moment - START) % 86400 == 3 * 3600 + 1800 - ROTATION * 0:
                self.bucket.publish(SOURCE, moment, {"database-backup.json": receipt(number), "database.sql.gz": b"x"})
            pending.append(f"binlog.{number:06d}")
            number += 1
            if outage and outage[0] <= moment < outage[1]:
                continue  # The archive is down; closed files wait for the catch-up batch.
            artifacts = {name: b"log" for name in pending} | {"closed-binlogs.json": b"{}"}
            self.bucket.publish(ARCHIVE, moment, artifacts)
            pending = []

    def observe_window(self, *, skip=()):
        state = observer.State.load(self.plan)
        for slot in range(START, STOP, INTERVAL):
            if slot not in skip:
                observer.observe(self.plan, self.bucket, now=slot + 5, state=state)
        state.save()

    def summarize(self, raw: bytes):
        identity = asdict(Identity("health-fixture", "a" * 40, "b" * 64, "c" * 64, "d" * 64, SOURCE))
        policy = {"version": 1, "identity": identity, "operations": {
            "window_end_unix_s": STOP, "interval_s": INTERVAL, "evidence_valid_until_unix_s": STOP + 3600,
            "destination_sha256": "e" * 64, "monitor_profile_sha256": "f" * 64,
            "backup_health_report_sha256": hashlib.sha256(raw).hexdigest()}}
        return health.summarize(policy, io.BytesIO(raw), now_unix_s=STOP)

    def export(self):
        identity = asdict(Identity("health-fixture", "a" * 40, "b" * 64, "c" * 64, "d" * 64, SOURCE))
        return observer.export(self.plan, window_end=STOP, identity_sha256=digest(identity),
                               destination_sha256="e" * 64, monitor_profile_sha256="f" * 64)

    def test_seven_clean_days_meet_the_readiness_thresholds(self):
        self.history()
        self.observe_window()
        summary = self.summarize(self.export())
        self.assertTrue(health.thresholds_met(summary), summary)
        self.assertEqual(summary["distinct_scheduled_observations"], check.BACKUP_WINDOW_SECONDS // INTERVAL)
        self.assertLess(summary["max_snapshot_age_s"], check.BACKUP_MAX_AGE_SECONDS)
        last = STOP - INTERVAL + 5
        visible = sum(1 for items in self.bucket.visible.values() for moment, _ in items if moment <= last)
        self.assertEqual(self.bucket.reads, visible, "each completion is read once and cached")

    def test_a_two_hour_archive_outage_breaks_the_99_percent_rule(self):
        self.history(outage=(START + 86400, START + 86400 + 7200))
        self.observe_window()
        summary = self.summarize(self.export())
        self.assertFalse(health.thresholds_met(summary))
        self.assertLess(summary["duration_with_lag_at_most_five_minutes_s"], check.BACKUP_WINDOW_SECONDS * 99 // 100)

    def test_export_refuses_a_window_with_a_missing_slot(self):
        self.history()
        self.observe_window(skip={START + 3000})
        with self.assertRaises(backup.Refusal) as caught:
            self.export()
        self.assertIn("1 scheduled slot", str(caught.exception))

    def test_observation_is_as_of_the_slot_start_and_idempotent(self):
        self.history()
        state = observer.State.load(self.plan)
        slot = START + 3 * 3600 + 1800
        first = observer.observe(self.plan, self.bucket, now=slot + 50, state=state)
        self.assertIsNone(observer.observe(self.plan, self.bucket, now=slot + 55, state=state))
        self.assertLessEqual(first["newest_verified_base_unix_s"], slot)
        self.assertLessEqual(first["recoverable_through_unix_s"], slot)
        raw = (self.root / "samples" / f"{slot}.json").read_bytes()
        self.assertEqual(hashlib.sha256(raw).hexdigest(), first["sample_sha256"])

    def test_a_publication_without_its_completion_marker_is_skipped_until_the_marker_lands(self):
        # The publisher uploads artifacts first and completion.json last, so every
        # base upload and every binlog batch is listed for a while without a marker.
        # An abandoned publication keeps no marker at all. Neither may cost a slot.
        self.history()
        uploading = "99999999-9999-4999-8999-999999999999"
        self.bucket.uploading = {SOURCE: [uploading], ARCHIVE: [uploading]}
        state = observer.State.load(self.plan)
        slot = START + 3 * 3600 + 1800
        row = observer.observe(self.plan, self.bucket, now=slot + 5, state=state)
        self.assertIsNotNone(row)
        self.assertGreater(row["newest_verified_base_unix_s"], 0)
        self.assertIsNotNone(row["recoverable_through_unix_s"])
        self.assertNotIn(uploading, state.cache["bases"])
        self.assertNotIn(uploading, state.cache["binlogs"])
        self.assertIsNotNone(observer.observe(self.plan, self.bucket, now=slot + INTERVAL + 5, state=state))

    def test_rclone_bucket_reports_a_missing_marker_without_reading_it(self):
        class Remote:
            def __init__(self, names):
                self.names, self.calls = names, []

            def run(self, action, *args, absent_ok=False):
                self.calls.append((action, args[0]))
                if action == "lsjson":
                    return json.dumps([{"Name": name, "IsDir": False} for name in self.names]).encode()
                if "completion.json" not in self.names:
                    raise backup.Refusal("rclone operation failed: cat")
                return b'{"marker": true}'

        bucket = observer.RcloneBucket.__new__(observer.RcloneBucket)
        bucket.crypt = "repro_encrypted"
        bucket.remotes = {SOURCE: Remote(["input-manifest.json"])}
        self.assertIsNone(bucket.completion(SOURCE, "b1"))
        self.assertEqual([action for action, _ in bucket.remotes[SOURCE].calls], ["lsjson"])
        bucket.remotes = {SOURCE: Remote(["input-manifest.json", "completion.json"])}
        self.assertEqual(bucket.completion(SOURCE, "b1"), b'{"marker": true}')
        self.assertEqual(bucket.remotes[SOURCE].calls[-1],
                         ("cat", f"repro_encrypted:database/{SOURCE}/b1/completion.json"))

    def test_sqlite_only_source_has_no_recoverable_through(self):
        self.history()
        self.plan["binlog_identity"] = None
        row = observer.observe(self.plan, self.bucket, now=START + 5, state=observer.State.load(self.plan))
        self.assertIsNone(row["recoverable_through_unix_s"])
        self.assertGreater(row["newest_verified_base_unix_s"], 0)


if __name__ == "__main__":
    unittest.main()
