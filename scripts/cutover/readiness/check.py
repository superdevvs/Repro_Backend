"""Check isolated migration readiness receipts against a reviewed policy."""

from __future__ import annotations

import argparse
import hashlib
import json
import re
from dataclasses import asdict
from datetime import date
from pathlib import Path
import time
from typing import Callable

from scripts.cutover.runner import Identity, digest, is_sha256

MINUTE = 60_000
TIMINGS = {
    "reopen_decision_ms": 40 * MINUTE,
    "prewrite_rollback_ms": 10 * MINUTE,
    "postwrite_failback_ms": 60 * MINUTE,
}
# The approved plan's pass marks for both dress rehearsals ("Speed",
# "Stress and crash", "Data" and "Way back"). They apply in addition to the
# engineering thresholds above and the critical-endpoint p95 ratio below.
SPEED_ENDPOINT_COUNT = 50
SPEED_MIN_WITHIN = 45
SPEED_WITHIN_US = 20_000
SPEED_MAX_SLOWER_US = 100_000
STRESS_MIN_MS = 10 * MINUTE
STRESS_MIN_CLIENTS = 20
STRESS_ZERO_COUNTS = ("server_errors", "unhandled_lock_errors", "confirmed_writes_missing",
                      "duplicate_business_effects")
CRASH_KILL_COUNT = 3
CRASH_RECOVERY_MS = MINUTE
DRESS_DATA_PROOFS = ("source_scope_verified", "source_integrity_scan_passed", "field_verifiers_agree")
DRESS_WAY_BACK_PROOFS = ("sqlite_reverse_parity_verified", "invoice_and_payout_parity_verified")
PREREQUISITES = (
    "sqlite_ci_passed",
    "mysql_ci_passed",
    "field_and_schema_parity_passed",
    "role_finance_delivery_workflows_passed",
    "crm_workflow_parity_passed",
    "crm_admission_and_replay_passed",
    "provider_egress_isolation_passed",
    "native_host_capacity_and_isolation_passed",
    "host_writer_fence_restart_passed",
    "mysql_writer_serialization_passed",
    "database_crash_recovery_passed",
)
RECOVERY_PROOFS = (
    "offsite_only",
    "independent_restore",
    "contiguous_binlog_chain",
    "chosen_transaction_verified",
    "stored_value_decrypted",
)
BACKUP_WINDOW_SECONDS = 7 * 24 * 60 * 60
BACKUP_MAX_AGE_SECONDS = 26 * 60 * 60
BACKUP_LAG_SECONDS = 5 * 60
BACKUP_LAG_PERCENT = 99
ALERT_LIMITS_MS = dict.fromkeys((
    "database_down", "wrong_database_engine", "site_unreachable", "disk_nearly_full",
    "backup_lag", "server_error_rate", "historical_money_totals_changed", "rehearsal_failed",
    "restore_overdue", "backup_stale", "database_contention", "mysql_restarted",
    "queue_stale", "cache_write_failed",
), 5 * MINUTE) | {"server_silent": 10 * MINUTE}
ALERT_MILESTONES = ("before_installer", "before_drill")
OPERATIONS_REPORTS = ("backup_health", "offsite_retention", "external_alerts", "point_in_time_drills", "nightly_data_parity")
PITR_DRILL_COUNT = 2
PITR_REPLAY_PROOFS = ("offsite_only", "independent_restore", "chosen_transaction_verified")
PITR_PROOFS = (*PITR_REPLAY_PROOFS, "contiguous_binlog_chain")
NIGHTLY_RUN_COUNT = 5
FROZEN_NIGHT_COUNT = 2
NIGHTLY_SUMMARY_SCHEMA = "repro.nightly-data-parity.summary.v2"
NIGHTLY_CRM_SCHEMA = "repro.nightly-crm-parity.review.v1"
MIN_CRM_TABLES = 37
NIGHTLY_IDENTITY_FIELDS = ("scheduled_day_utc", "release_sha", "snapshot_sha256", "nightly_scope_sha256")
NIGHTLY_COUNTERS = ("errors", "warnings", "notes", "unexpected_field_differences")
NIGHTLY_PROOFS = (
    "fresh_snapshot_verified", "source_scope_verified", "source_integrity_scan_passed",
    "field_verifiers_agree", "row_counts_match",
    "historical_exceptions_match", "target_settings_verified", "target_engine_verified",
    "sqlite_reverse_parity_verified", "invoice_and_payout_parity_verified",
)
NIGHTLY_REPORT_FIELDS = {
    "import_and_verify_ms", *NIGHTLY_IDENTITY_FIELDS, *NIGHTLY_COUNTERS, *NIGHTLY_PROOFS,
}
NIGHTLY_CRM_PROOFS = (
    "crm_field_and_identity_parity_verified", "crm_relationship_and_exception_parity_verified",
    "crm_replay_state_parity_verified", "crm_client_import_dry_run_verified",
    "crm_applicant_file_parity_verified",
)
NIGHTLY_CRM_FIELDS = {
    "schema", "audit_report_sha256", "parity_evidence_sha256", "crm_tables_verified", "verified_linked_client_count",
    *NIGHTLY_IDENTITY_FIELDS, *NIGHTLY_CRM_PROOFS,
}


class Missing(ValueError):
    """A required measurement or report is absent."""


class Failed(ValueError):
    """Evidence failed a requirement or could not be interpreted."""


def field(record: dict, name: str):
    if name not in record or record[name] is None:
        raise Missing(f"{name} is missing")
    return record[name]


def object_field(record: dict, name: str) -> dict:
    value = field(record, name)
    if not isinstance(value, dict):
        raise Failed(f"{name} must be a record")
    return value


def sha_field(record: dict, name: str) -> str:
    value = field(record, name)
    if not is_sha256(value):
        raise Failed(f"{name} must be a SHA-256 digest")
    return value


def number(record: dict, name: str, minimum: int = 0) -> int:
    value = field(record, name)
    if type(value) is not int or value < minimum:
        raise Failed(f"{name} must be an integer of at least {minimum}")
    return value


def confirmed(record: dict, name: str) -> None:
    if field(record, name) is not True:
        raise Failed(f"{name} did not pass")


def cap(record: dict, name: str, limit: int) -> None:
    if number(record, name, minimum=1) > limit:
        raise Failed(f"{name} exceeds {limit}")


def zero(record: dict, name: str) -> None:
    if number(record, name) != 0:
        raise Failed(f"{name} must be zero")


def version(record: dict) -> None:
    if number(record, "version", minimum=1) != 1:
        raise Failed("Unsupported receipt version")


def load_identity(record: dict) -> dict:
    values = object_field(record, "identity")
    for name in Identity.__dataclass_fields__:
        field(values, name)
    try:
        return asdict(Identity(**values))
    except (ValueError, AttributeError, TypeError) as error:
        raise Failed("Malformed release, source, configuration or target identity") from error


def endpoints(policy: dict) -> list[str]:
    values = field(policy, "critical_endpoints")
    if (not isinstance(values, list) or not values
            or any(not isinstance(value, str) or not value.strip() for value in values)
            or len(values) != len(set(values))):
        raise Failed("Critical endpoints must be a nonempty list of unique labels")
    return values


class Gates:
    def __init__(self) -> None:
        self.results = {}

    def check(self, name: str, validate: Callable[[], object]) -> bool:
        try:
            validate()
        except Missing as error:
            self.results[name] = {"status": "missing", "detail": str(error)}
        except Failed as error:
            self.results[name] = {"status": "failed", "detail": str(error)}
        else:
            self.results[name] = {"status": "passed"}
            return True
        return False

    def report(self, **binding) -> dict:
        passed = bool(self.results) and all(result["status"] == "passed" for result in self.results.values())
        return {"decision": "meets_review_gates" if passed else "no_go", **binding, "gates": self.results}


def review_policy(policy: dict) -> None:
    version(policy)
    load_identity(policy)
    endpoints(policy)
    sha_field(policy, "host_profile_sha256")
    sha_field(policy, "workload_sha256")


def review_binding(policy: dict, receipt: dict) -> None:
    version(receipt)
    if sha_field(receipt, "policy_sha256") != digest(policy) or load_identity(receipt) != load_identity(policy):
        raise Failed("Receipt does not match the approved policy or cutover identity")


def review_prerequisites(policy: dict, receipt: dict) -> None:
    report = object_field(receipt, "prerequisites")
    sha_field(report, "report_sha256")
    if sha_field(report, "identity_sha256") != digest(load_identity(policy)):
        raise Failed("Prerequisite report is for a different release or target")


def review_rehearsal_pair(receipt: dict) -> None:
    values = field(receipt, "dress_rehearsals")
    if not isinstance(values, list):
        raise Failed("Dress rehearsals must be a list")
    if len(values) < 2:
        raise Missing("Two dress rehearsals are required")
    if len(values) != 2 or any(not isinstance(value, dict) for value in values):
        raise Failed("Select exactly two dress rehearsal records")
    ids = [field(value, "id") for value in values]
    if any(not isinstance(value, str) or not re.fullmatch(r"[A-Za-z0-9_-]{1,64}", value) for value in ids):
        raise Failed("Each dress rehearsal requires a valid run ID")
    if len(set(ids)) != 2 or len({sha_field(value, "report_sha256") for value in values}) != 2:
        raise Failed("Dress rehearsals must use distinct run IDs and reports")


def review_write_outage(rehearsal: dict) -> None:
    timeline = object_field(rehearsal, "write_outage")
    sha_field(timeline, "writer_replay_ledger_sha256")
    app_hold = number(timeline, "app_first_hold_utc_ms", 1)
    producer_hold = number(timeline, "producer_first_hold_utc_ms", 1)
    first_hold = min(app_hold, producer_hold)
    decision = number(timeline, "decision_utc_ms", 1)
    app_ready = number(timeline, "app_write_verified_utc_ms", 1)
    producers_ready = number(timeline, "producer_writes_verified_utc_ms", 1)
    replay_ready = number(timeline, "replay_reconciled_utc_ms", 1)
    if not max(app_hold, producer_hold) <= decision <= min(app_ready, producers_ready) or replay_ready < first_hold:
        raise Failed("The decision, replay or verified write times conflict with the admission holds")
    if decision - first_hold != number(rehearsal, "reopen_decision_ms", 1):
        raise Failed("Elapsed decision time must start at the first business write hold")
    if max(app_ready, producers_ready, replay_ready) - first_hold > 60 * MINUTE:
        raise Failed("End-to-end business write outage exceeds 60 minutes")


def review_rehearsal_scope(policy: dict, rehearsal: dict) -> None:
    sha_field(rehearsal, "snapshot_sha256")
    for name in ("host_profile_sha256", "workload_sha256"):
        if sha_field(rehearsal, name) != policy[name]:
            raise Failed(f"{name} does not match the approved benchmark")
    if sha_field(rehearsal, "identity_sha256") != digest(load_identity(policy)):
        raise Failed("Dress rehearsal identity differs from the approved release and target")
    confirmed(rehearsal, "peak_and_surge_profile_completed")


def review_p95(policy: dict, rehearsal: dict) -> None:
    measurements = object_field(rehearsal, "endpoint_p95_us")
    expected = set(endpoints(policy))
    if expected - measurements.keys():
        raise Missing("P95 measurements do not cover every critical endpoint")
    if measurements.keys() - expected:
        raise Failed("P95 measurements differ from the approved endpoint inventory")
    for label in sorted(expected):
        endpoint = object_field(measurements, label)
        if number(endpoint, "mysql", minimum=1) * 100 > number(endpoint, "sqlite", minimum=1) * 110:
            raise Failed("A critical endpoint p95 is more than 10% slower")


def busiest_endpoints(policy: dict) -> list[str]:
    values = field(policy, "busiest_endpoints")
    if (not isinstance(values, list) or len(values) != SPEED_ENDPOINT_COUNT
            or any(not isinstance(value, str) or not value.strip() for value in values)
            or len(values) != len(set(values))):
        raise Failed("The busiest endpoints must be the reviewed list of 50 unique labels")
    return values


def review_speed(policy: dict, rehearsal: dict) -> None:
    """Plan pass mark: none of the 50 busiest requests makes a layout lookup,
    at least 45 are within 20 ms of SQLite at p95 and none is over 100 ms slower."""
    expected = set(busiest_endpoints(policy))
    measurements = object_field(rehearsal, "busiest_p95_us")
    if expected - measurements.keys():
        raise Missing("P95 measurements do not cover the 50 busiest endpoints")
    if measurements.keys() - expected:
        raise Failed("P95 measurements differ from the reviewed busiest endpoint list")
    within = 0
    for label in sorted(expected):
        endpoint = object_field(measurements, label)
        if number(endpoint, "mysql_layout_lookups") != 0:
            raise Failed("A busiest endpoint made a layout lookup on MySQL")
        slower_us = number(endpoint, "mysql", minimum=1) - number(endpoint, "sqlite", minimum=1)
        if slower_us > SPEED_MAX_SLOWER_US:
            raise Failed("A busiest endpoint p95 is more than 100 ms slower on MySQL")
        within += slower_us <= SPEED_WITHIN_US
    if within < SPEED_MIN_WITHIN:
        raise Failed("Fewer than 45 of the busiest endpoints are within 20 ms of SQLite at p95")


def review_stress_and_crash(rehearsal: dict) -> None:
    """Plan pass mark: ten minutes at 20 parallel clients while workers clear a
    backlog, then MySQL killed mid-write three times and back within a minute."""
    stress = object_field(rehearsal, "stress")
    if number(stress, "duration_ms") < STRESS_MIN_MS or number(stress, "parallel_clients") < STRESS_MIN_CLIENTS:
        raise Failed("Stress run was shorter than ten minutes or used fewer than 20 clients")
    start = number(stress, "backlog_start_depth")
    # The backlog must shrink: an unchanged backlog shows workers keeping pace, not clearing it.
    if start < 1 or number(stress, "backlog_end_depth") >= start:
        raise Failed("Workers did not clear a queue backlog during the stress run")
    for name in STRESS_ZERO_COUNTS:
        if number(stress, name) != 0:
            raise Failed(f"Stress run recorded {name}")
    kills = field(rehearsal, "mysql_kills")
    if not isinstance(kills, list) or any(not isinstance(kill, dict) for kill in kills):
        raise Failed("MySQL kills must be a list of records")
    if len(kills) < CRASH_KILL_COUNT:
        raise Failed("MySQL must be killed mid-write at least three times")
    for kill in kills:
        confirmed(kill, "killed_during_write")
        cap(kill, "recovery_ms", CRASH_RECOVERY_MS)
        for name in ("confirmed_writes_missing", "duplicate_business_effects"):
            zero(kill, name)


def review_dress_data(rehearsal: dict) -> None:
    """Plan pass mark: import and both verifications within 12 minutes, zero
    errors, warnings and notes, no unexplained cell difference, scan clean and
    the tables moved equal the reviewed scope. The record must be for this
    rehearsal's own source snapshot."""
    data = object_field(rehearsal, "data")
    if sha_field(data, "snapshot_sha256") != sha_field(rehearsal, "snapshot_sha256"):
        raise Failed("The data record is for a different snapshot than this dress rehearsal")
    cap(data, "import_and_verify_ms", 12 * MINUTE)
    for name in NIGHTLY_COUNTERS:
        zero(data, name)
    for proof in DRESS_DATA_PROOFS:
        confirmed(data, proof)


def review_way_back(rehearsal: dict) -> None:
    """Plan pass mark: the export to SQLite reproduces every stored value and
    the weekly invoice and payout runs give the same result on it."""
    way_back = object_field(rehearsal, "way_back")
    for proof in DRESS_WAY_BACK_PROOFS:
        confirmed(way_back, proof)


def review_queue(rehearsal: dict) -> None:
    if number(rehearsal, "peak_queue_end_depth") > number(rehearsal, "peak_queue_start_depth"):
        raise Failed("The queue backlog grew under normal peak load")


def write_improvement(rehearsal: dict) -> tuple[bool, bool]:
    sqlite = object_field(rehearsal, "sqlite_writes")
    mysql = object_field(rehearsal, "mysql_writes")
    sqlite_attempts = number(sqlite, "attempts", minimum=1)
    mysql_attempts = number(mysql, "attempts", minimum=1)
    sqlite_contentions = number(sqlite, "contended_attempts")
    mysql_contentions = number(mysql, "contended_attempts")
    if sqlite_contentions > sqlite_attempts or mysql_contentions > mysql_attempts:
        raise Failed("Contention count exceeds measured write attempts")
    faster = number(mysql, "p95_us", minimum=1) < number(sqlite, "p95_us", minimum=1)
    less_contended = mysql_contentions * sqlite_attempts < sqlite_contentions * mysql_attempts
    return faster, less_contended


def review_repeatability(rehearsals: list[dict]) -> None:
    first, second = [write_improvement(rehearsal) for rehearsal in rehearsals]
    if not any(a and b for a, b in zip(first, second)):
        raise Failed("Neither write latency nor contention improved in both rehearsals")


def review_recovery(policy: dict, receipt: dict) -> None:
    recovery = object_field(receipt, "offsite_recovery")
    sha_field(recovery, "report_sha256")
    if sha_field(recovery, "identity_sha256") != digest(load_identity(policy)):
        raise Failed("Off-site recovery report is for a different release or target")
    for proof in RECOVERY_PROOFS:
        confirmed(recovery, proof)
    cap(recovery, "database_recovery_ms", 60 * MINUTE)
    if number(recovery, "offsite_database_data_loss_ms") > 5 * MINUTE:
        raise Failed("Measured off-site database data loss exceeds five minutes")


def utc_day(record: dict, name: str) -> date:
    value = field(record, name)
    if isinstance(value, str) and re.fullmatch(r"[0-9]{4}-[0-9]{2}-[0-9]{2}", value):
        try:
            return date.fromisoformat(value)
        except ValueError:
            pass
    raise Failed(f"{name} must be a valid UTC calendar date")


def review_operations_policy(policy: dict, *, now_unix_s: int) -> None:
    operations = object_field(policy, "operations")
    for name in ("destination_sha256", "monitor_profile_sha256", "phone_recipient_sha256", "nightly_scope_sha256"):
        sha_field(operations, name)
    end = number(operations, "window_end_unix_s", minimum=BACKUP_WINDOW_SECONDS)
    expires = number(operations, "evidence_valid_until_unix_s", minimum=end)
    if not end <= now_unix_s <= expires:
        raise Failed("The reviewed observation window is in the future or has expired")
    today_ordinal = date(1970, 1, 1).toordinal() + now_unix_s // 86400
    if utc_day(operations, "last_nightly_day_utc").toordinal() > today_ordinal:
        raise Failed("The reviewed nightly streak ends in the future")
    cap(operations, "interval_s", BACKUP_LAG_SECONDS)
    for kind in OPERATIONS_REPORTS:
        sha_field(operations, f"{kind}_report_sha256")
        sha_field(operations, f"{kind}_summary_sha256")


def operations_report(policy: dict, receipt: dict, kind: str, *, scope: str) -> dict:
    report = object_field(receipt, kind)
    expected = policy["operations"]
    if (sha_field(report, "identity_sha256") != digest(load_identity(policy))
            or sha_field(report, "report_sha256") != expected[f"{kind}_report_sha256"]
            or digest(report) != expected[f"{kind}_summary_sha256"]
            or sha_field(report, scope) != expected[scope]):
        raise Failed("Operational summary does not match the reviewed evidence, identity or scope")
    return report


def review_backup_health(policy: dict, receipt: dict) -> None:
    report = operations_report(policy, receipt, "backup_health", scope="destination_sha256")
    expected = policy["operations"]
    if sha_field(report, "monitor_profile_sha256") != expected["monitor_profile_sha256"]:
        raise Failed("Backup health observations use a different monitor profile")
    stop = number(report, "window_end_unix_s")
    if stop != expected["window_end_unix_s"] or number(report, "window_start_unix_s") != stop - BACKUP_WINDOW_SECONDS:
        raise Failed("Backup health must cover the reviewed seven-day window")
    confirmed(report, "external_observer_verified")
    if number(report, "covered_duration_s") != BACKUP_WINDOW_SECONDS:
        raise Failed("Backup health is missing part of the reviewed window")
    interval = expected["interval_s"]
    expected_observations = (BACKUP_WINDOW_SECONDS + interval - 1) // interval
    if number(report, "distinct_scheduled_observations") != expected_observations:
        raise Failed("Scheduled backup health observations were missing or duplicated")
    if number(report, "max_snapshot_age_s") >= BACKUP_MAX_AGE_SECONDS:
        raise Failed("A verified backup reached 26 hours of age")
    timely = number(report, "duration_with_lag_at_most_five_minutes_s")
    if timely > BACKUP_WINDOW_SECONDS or timely * 100 < BACKUP_WINDOW_SECONDS * BACKUP_LAG_PERCENT:
        raise Failed("Recovery point lag was within five minutes for less than 99% of the window")


def review_retention(policy: dict, receipt: dict) -> None:
    report = operations_report(policy, receipt, "offsite_retention", scope="destination_sha256")
    for name in ("exact_provider_policy_verified", "configured_writer_credential_verified",
                 "writer_overwrite_blocked", "writer_delete_blocked", "independent_lock_delete_blocked",
                 "probe_bytes_unchanged"):
        confirmed(report, name)


def review_alerts(policy: dict, receipt: dict) -> None:
    report = operations_report(policy, receipt, "external_alerts", scope="monitor_profile_sha256")
    if sha_field(report, "phone_recipient_sha256") != policy["operations"]["phone_recipient_sha256"]:
        raise Failed("Alert delivery is for a different phone recipient")
    review_alert_rounds(report)


def review_alert_rounds(report: dict) -> None:
    rounds = {milestone: object_field(report, milestone) for milestone in ALERT_MILESTONES}
    if len({sha_field(round_report, "report_sha256") for round_report in rounds.values()}) != len(rounds):
        raise Failed("Alert milestones require distinct report digests")
    deliveries = set()
    for milestone, round_report in rounds.items():
        cases = object_field(round_report, "cases")
        for name, limit in ALERT_LIMITS_MS.items():
            event = object_field(cases, name)
            confirmed(event, "condition_tested")
            confirmed(event, "phone_delivery_verified")
            delivery = sha_field(event, "delivery_receipt_sha256")
            if delivery in deliveries:
                raise Failed("A phone delivery receipt was reused across alert checks")
            deliveries.add(delivery)
            if number(event, "delivery_ms") > limit:
                raise Failed(f"{milestone} {name} phone delivery exceeds {limit} ms")


def review_point_in_time_drills(policy: dict, receipt: dict) -> None:
    report = operations_report(policy, receipt, "point_in_time_drills", scope="destination_sha256")
    review_point_in_time_runs(field(report, "drills"))


def review_point_in_time_runs(drills: object) -> None:
    if not isinstance(drills, list) or any(not isinstance(drill, dict) for drill in drills):
        raise Failed("Point-in-time drills must be a list of reports")
    if len(drills) < PITR_DRILL_COUNT:
        raise Missing("Two point-in-time drills are required")
    if (len(drills) != PITR_DRILL_COUNT
            or len({sha_field(drill, "report_sha256") for drill in drills}) != PITR_DRILL_COUNT):
        raise Failed("Expected exactly two distinct point-in-time drill reports")
    for drill in drills:
        for proof in PITR_PROOFS:
            confirmed(drill, proof)
        cap(drill, "database_recovery_ms", 30 * MINUTE)
        if number(drill, "absolute_heartbeat_target_delta_ms") > MINUTE:
            raise Failed("Recovered heartbeat differs from the chosen target by over one minute")
    if not any(drill.get("planted_bad_write_excluded") is True for drill in drills):
        raise Failed("A point-in-time drill must verify exclusion of a planted bad write")


def review_nightly_data_parity(policy: dict, receipt: dict) -> None:
    report = operations_report(policy, receipt, "nightly_data_parity", scope="nightly_scope_sha256")
    if report.get("schema") != NIGHTLY_SUMMARY_SCHEMA:
        raise Failed("Nightly parity summary requires the CRM-aware schema")
    review_nightly_runs(policy, field(report, "nights"), number(report, "crm_scope_table_count", MIN_CRM_TABLES))


def review_nightly_crm(crm: dict, night: dict, scope_sha: str, crm_scope_table_count: int) -> None:
    if set(crm) != NIGHTLY_CRM_FIELDS | {"report_sha256"} or crm["schema"] != NIGHTLY_CRM_SCHEMA:
        raise Failed("Malformed nightly CRM parity review")
    for name in ("report_sha256", "audit_report_sha256", "parity_evidence_sha256"):
        if sha_field(crm, name) == "0" * 64:
            raise Failed(f"{name} requires a reviewed evidence digest")
    identity = {name: night[name] for name in NIGHTLY_IDENTITY_FIELDS if name != "nightly_scope_sha256"}
    identity["nightly_scope_sha256"] = scope_sha
    if any(crm[name] != value for name, value in identity.items()):
        raise Failed("Nightly CRM review does not match the scheduled date, release, snapshot or scope")
    if number(crm, "crm_tables_verified", MIN_CRM_TABLES) != crm_scope_table_count:
        raise Failed("Nightly CRM review did not cover every scoped CRM table")
    number(crm, "verified_linked_client_count", minimum=1)
    for proof in NIGHTLY_CRM_PROOFS:
        confirmed(crm, proof)


def review_nightly_runs(policy: dict, nights: object, crm_scope_table_count: int) -> None:
    if not isinstance(nights, list) or any(not isinstance(night, dict) for night in nights):
        raise Failed("Nightly evidence must be a list of reports")
    if len(nights) < NIGHTLY_RUN_COUNT:
        raise Missing("Five nightly parity runs are required")
    if len(nights) != NIGHTLY_RUN_COUNT:
        raise Failed("Select the five reviewed nightly parity reports")
    if len({sha_field(night, "report_sha256") for night in nights}) != NIGHTLY_RUN_COUNT:
        raise Failed("Nightly runs require distinct report digests")
    operations = policy["operations"]
    last = utc_day(operations, "last_nightly_day_utc").toordinal()
    scope_sha = sha_field(operations, "nightly_scope_sha256")
    release = load_identity(policy)["release_sha"]
    crm_digests = set()
    for index, night in enumerate(nights, 1):
        sha_field(night, "snapshot_sha256")
        if utc_day(night, "scheduled_day_utc").toordinal() != last - NIGHTLY_RUN_COUNT + index:
            raise Failed("Nightly parity reports must cover five consecutive reviewed dates")
        tested_release = field(night, "release_sha")
        if not isinstance(tested_release, str) or not re.fullmatch(r"[a-f0-9]{40}", tested_release):
            raise Failed("Nightly parity report requires an exact release commit")
        if index > NIGHTLY_RUN_COUNT - FROZEN_NIGHT_COUNT and tested_release != release:
            raise Failed("Both final nightly runs must use the frozen release")
        review_nightly_night(night, scope_sha, crm_scope_table_count)
        crm_digests.add(night["crm"]["report_sha256"])
    if len(crm_digests) != NIGHTLY_RUN_COUNT:
        raise Failed("Nightly runs require distinct CRM review digests")


def review_nightly_night(night: dict, scope_sha: str, crm_scope_table_count: int) -> None:
    """The data gates for one night, independent of its place in the streak."""
    cap(night, "import_and_verify_ms", 12 * MINUTE)
    for name in NIGHTLY_COUNTERS:
        zero(night, name)
    for proof in NIGHTLY_PROOFS:
        confirmed(night, proof)
    review_nightly_crm(object_field(night, "crm"), night, scope_sha, crm_scope_table_count)


def evaluate(policy: dict, receipt: dict, *, now_unix_s: int | None = None) -> dict:
    gates = Gates()
    if not gates.check("approved_policy", lambda: review_policy(policy)):
        return gates.report()
    binding = {"policy_sha256": digest(policy), "receipt_sha256": digest(receipt)}
    if not gates.check("receipt_binding", lambda: review_binding(policy, receipt)):
        return gates.report(**binding)

    if gates.check("prerequisite_report", lambda: review_prerequisites(policy, receipt)):
        prerequisites = receipt["prerequisites"]
        for name in PREREQUISITES:
            gates.check(name, lambda: confirmed(prerequisites, name))

    if gates.check("distinct_dress_rehearsals", lambda: review_rehearsal_pair(receipt)):
        rehearsals = receipt["dress_rehearsals"]
        comparable = []
        for index, rehearsal in enumerate(rehearsals, 1):
            prefix = f"dress_rehearsal_{index}"
            if not gates.check(f"{prefix}.scope", lambda: review_rehearsal_scope(policy, rehearsal)):
                continue
            comparable.append(rehearsal)
            gates.check(f"{prefix}.write_outage", lambda: review_write_outage(rehearsal))
            for name, limit in TIMINGS.items():
                gates.check(f"{prefix}.{name}", lambda: cap(rehearsal, name, limit))
            for name in ("lost_acknowledged_writes", "duplicate_business_effects"):
                gates.check(f"{prefix}.{name}", lambda: zero(rehearsal, name))
            gates.check(f"{prefix}.queue", lambda: review_queue(rehearsal))
            gates.check(f"{prefix}.critical_p95", lambda: review_p95(policy, rehearsal))
            gates.check(f"{prefix}.speed", lambda: review_speed(policy, rehearsal))
            gates.check(f"{prefix}.stress_and_crash", lambda: review_stress_and_crash(rehearsal))
            gates.check(f"{prefix}.data", lambda: review_dress_data(rehearsal))
            gates.check(f"{prefix}.way_back", lambda: review_way_back(rehearsal))
        if len(comparable) == 2:
            gates.check("repeatable_write_improvement", lambda: review_repeatability(comparable))

    gates.check("offsite_database_recovery", lambda: review_recovery(policy, receipt))
    now_unix_s = int(time.time()) if now_unix_s is None else now_unix_s
    if gates.check("operations_policy", lambda: review_operations_policy(policy, now_unix_s=now_unix_s)):
        gates.check("seven_day_backup_health", lambda: review_backup_health(policy, receipt))
        gates.check("offsite_retention", lambda: review_retention(policy, receipt))
        gates.check("external_alert_delivery", lambda: review_alerts(policy, receipt))
        gates.check("point_in_time_drills", lambda: review_point_in_time_drills(policy, receipt))
        gates.check("nightly_data_parity", lambda: review_nightly_data_parity(policy, receipt))
    return gates.report(**binding)


def reject_constant(_value: str):
    raise Failed("Receipt numbers must be finite integers")


def unique_keys(pairs: list[tuple[str, object]]) -> dict:
    record = dict(pairs)
    if len(record) != len(pairs):
        raise Failed("Malformed JSON contains duplicate fields")
    return record


def load(path: Path, *, expected_sha256: str | None = None) -> dict:
    try:
        with path.open("rb") as stream:
            payload = stream.read(1024 * 1024 + 1)
    except FileNotFoundError as error:
        raise Missing("Evidence file was not supplied") from error
    except OSError as error:
        raise Failed("Evidence file could not be read") from error
    if not payload:
        raise Missing("Evidence file is empty")
    if len(payload) > 1024 * 1024:
        raise Failed("Evidence file exceeds the receipt size limit")
    if expected_sha256 is not None and hashlib.sha256(payload).hexdigest() != expected_sha256:
        raise Failed("Evidence file differs from its reviewed SHA-256 digest")
    try:
        record = json.loads(payload.decode("utf-8"), object_pairs_hook=unique_keys,
                            parse_constant=reject_constant, parse_float=reject_constant)
    except (ValueError, UnicodeError, RecursionError) as error:
        raise Failed("Malformed evidence JSON") from error
    if not isinstance(record, dict):
        raise Failed("Evidence JSON must be an object")
    return record


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--policy", required=True, type=Path)
    parser.add_argument("--receipt", required=True, type=Path)
    args = parser.parse_args()
    gates = Gates()
    inputs = {}
    for name in ("policy", "receipt"):
        gates.check(f"{name}_file", lambda: inputs.update({name: load(getattr(args, name))}))
    report = evaluate(**inputs) if len(inputs) == 2 else gates.report()
    print(json.dumps(report, indent=2))
    return 0 if report["decision"] == "meets_review_gates" else 1


if __name__ == "__main__":
    raise SystemExit(main())
