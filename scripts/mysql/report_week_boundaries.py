#!/usr/bin/env python3
"""Count the effects of the weekly scheduled-date predicates on a reviewed copy.

python -B scripts/mysql/report_week_boundaries.py --snapshot /private/copy.sqlite
  --snapshot-sha256 HEX --as-of-utc 2026-11-22T00:00:00Z

Supply an independently accepted, closed SQLite copy. Eight complete weeks
end before the Sunday containing the supplied UTC time. The legacy counts use
SQLite's actual BETWEEN semantics over the saved column. The comparison uses
the calendar day of valid date-only or space-separated timestamp text (with
up to six fractional digits). Calendar/clock errors in those supported
shapes are malformed. Other text formats, including offsets, are unsupported.

Counts cover all shoots. This measures the date predicates in isolation from
client/payee selection, cancellation, compensation, invoice state, billing
history and other eligibility rules. It does not identify work to catch up or
verify application finance parity. No rows, IDs or monetary values are emitted.
"""
from __future__ import annotations

import argparse
from contextlib import closing
from datetime import date, datetime, time, timedelta
import importlib.util
import json
from pathlib import Path
import re
import sqlite3


SPEC = importlib.util.spec_from_file_location("sqlite_schema_drift", Path(__file__).with_name("schema-drift.py"))
schema_drift = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(schema_drift)

REPORT_SCHEMA = "repro.sqlite.week-boundary-census.v1"
PREDICATE_USES = {
    "datetime_between": ["client_invoice", "client_invoice_complimentary_receipt_fallback",
                         "sales_rep_invoice", "sales_rep_payout_report"],
    "date_between": ["client_invoice_historical_payment_fallback"],
}
REPRESENTATIONS = ("date_only", "datetime_seconds", "datetime_fractional", "null", "non_text",
                   "malformed_text", "unsupported_text")
DATE_ONLY = re.compile(rb"[0-9]{4}-[0-9]{2}-[0-9]{2}")
DATE_TIME = re.compile(rb"[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}(\.[0-9]{1,6})?")
AS_OF_UTC = re.compile(r"[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z")


def completed_weeks(as_of_utc: str) -> list[tuple[date, date]]:
    schema_drift.need(AS_OF_UTC.fullmatch(as_of_utc) is not None, "explicit-utc-as-of-required")
    try:
        anchor = datetime.strptime(as_of_utc, "%Y-%m-%dT%H:%M:%SZ").date()
        this_sunday = anchor - timedelta(days=(anchor.weekday() + 1) % 7)
        weeks = [this_sunday - timedelta(weeks=offset) for offset in range(8, 0, -1)]
        return [(sunday, sunday + timedelta(days=6)) for sunday in weeks]
    except (ValueError, OverflowError):
        raise schema_drift.Refusal("invalid-utc-as-of") from None


def representation(storage_type: bytes, value) -> tuple[str, date | None]:
    if storage_type == b"null":
        return "null", None
    if storage_type != b"text":
        return "non_text", None
    timestamp = DATE_TIME.fullmatch(value)
    if not timestamp and not DATE_ONLY.fullmatch(value):
        return "unsupported_text", None
    try:
        day = date.fromisoformat(value[:10].decode("ascii"))
        if not timestamp:
            return "date_only", day
        time.fromisoformat(value[11:].decode("ascii"))
        return ("datetime_fractional" if timestamp[1] else "datetime_seconds"), day
    except ValueError:
        return "malformed_text", None


def period_counts(start: date, end: date) -> dict:
    return {
        "start_day_utc": start.isoformat(), "end_day_utc": end.isoformat(), "valid_day_rows": 0,
        "legacy_predicates": {
            name: {"selected_rows": 0, "valid_day_rows_omitted": 0, "outside_week_rows_selected": 0,
                   "unclassifiable_rows_selected": 0}
            for name in PREDICATE_USES
        },
    }


def count_periods(db: sqlite3.Connection, weeks: list[tuple[date, date]]) -> tuple[dict, list[dict]]:
    shapes = dict.fromkeys(REPRESENTATIONS, 0)
    periods = [period_counts(start, end) for start, end in weeks]
    bounds = []
    for start, end in weeks:
        # Carbon::toDateTimeString() truncates the end of day to seconds.
        bounds.extend([f"{start} 00:00:00", f"{end} 23:59:59", str(start), str(end)])
    comparisons = ", ".join(["scheduled_date BETWEEN ? AND ?"] * (len(bounds) // 2))
    # Inspect the stored date only. SQLite computes the exact bound-parameter
    # comparisons so affinity and any column collation are the original ones.
    db.text_factory = bytes
    for storage_type, value, *selected in db.execute(
        f"SELECT typeof(scheduled_date), scheduled_date, {comparisons} FROM main.shoots", bounds
    ):
        shape, day = representation(storage_type, value)
        shapes[shape] += 1
        matches_by_period = iter(selected)
        for (start, end), period in zip(weeks, periods, strict=True):
            within_week = day is not None and start <= day <= end
            period["valid_day_rows"] += within_week
            for counts in period["legacy_predicates"].values():
                matches = bool(next(matches_by_period))
                counts["selected_rows"] += matches
                counts["valid_day_rows_omitted"] += within_week and not matches
                counts["outside_week_rows_selected"] += matches and day is not None and not within_week
                counts["unclassifiable_rows_selected"] += matches and day is None
    return shapes, periods


def report(snapshot: Path, snapshot_sha256: str, as_of_utc: str) -> dict:
    weeks = completed_weeks(as_of_utc)
    schema_drift.checked_file(snapshot, snapshot_sha256)
    with closing(sqlite3.connect(snapshot.as_uri() + "?mode=ro&immutable=1", uri=True)) as db:
        db.execute("PRAGMA query_only=ON")
        db.execute("PRAGMA trusted_schema=OFF")
        schema_drift.need(db.execute("PRAGMA query_only").fetchone() == (1,), "query-only-not-enabled")
        schema_drift.need(any(row[:3] == ("main", "shoots", "table") for row in db.execute("PRAGMA main.table_list")),
                          "ordinary-shoots-table-required")
        schema_drift.need(any(row[1] == "scheduled_date" and row[6] == 0
                              for row in db.execute("PRAGMA main.table_xinfo('shoots')")),
                          "stored-scheduled-date-required")
        shapes, periods = count_periods(db, weeks)
    schema_drift.checked_file(snapshot, snapshot_sha256)
    return {"schema": REPORT_SCHEMA, "result": "observed", "snapshot_sha256": snapshot_sha256,
            "as_of_utc": as_of_utc, "scope": "shoots.scheduled_date predicates only",
            "representations": shapes, "predicate_uses": PREDICATE_USES, "weeks": periods,
            "finance_parity_verified": False, "database_writes": False}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--snapshot", type=Path, required=True)
    parser.add_argument("--snapshot-sha256", required=True)
    parser.add_argument("--as-of-utc", required=True)
    args = parser.parse_args()
    try:
        print(json.dumps(report(args.snapshot, args.snapshot_sha256, args.as_of_utc), sort_keys=True))
        return 0
    except schema_drift.Refusal as error:
        reason = str(error)
    except (OSError, sqlite3.Error, ValueError):
        reason = "snapshot-read-failure"
    print(json.dumps({"schema": REPORT_SCHEMA, "result": "refused", "reason": reason,
                      "finance_parity_verified": False, "database_writes": False}, sort_keys=True))
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
