#!/usr/bin/env python3
"""Inspect CRM coverage and loose links in a pinned, private SQLite snapshot.

Pass --snapshot, --snapshot-sha256 and --key-file. The key file must contain
32 random bytes and stay private. Optionally pass --compare-snapshot and
--compare-snapshot-sha256 for a separately verified SQLite reverse export.
Stdout contains aggregate counts and keyed fingerprints. Missing references
are measured for independent review. The audit does not read attachment bytes,
certify provider state, verify all fields, or connect to a database service.
"""
from __future__ import annotations

import argparse
from contextlib import closing
import hashlib
import hmac
import importlib.util
import json
import os
from pathlib import Path
import re
import sqlite3
import stat


SPEC = importlib.util.spec_from_file_location("sqlite_schema_drift", Path(__file__).with_name("schema-drift.py"))
schema_drift = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(schema_drift)
need = schema_drift.need
q = schema_drift.q

REPORT_SCHEMA = "repro.mysql-crm-snapshot.audit.v1"
# Every migration that creates or changes a CRM table, as in crm_migrations of
# scripts/cutover/nightly/nightly-config.json (a test keeps the two equal).
MIGRATIONS = (
    "2026_11_24_100000_create_crm_core_tables",
    "2026_11_24_110000_create_crm_event_tables",
    "2026_11_24_120000_create_crm_email_tables",
    "2026_11_24_130000_create_crm_drip_and_campaign_tables",
    "2026_11_24_140000_create_recruit_tables",
    "2026_11_24_150000_create_crm_feature_tables",
    "2026_11_24_160000_create_crm_intake_receipts",
    "2026_11_24_161000_add_booking_details_to_crm_quotes",
    "2026_11_24_180000_add_crm_communication_receipts",
    "2026_11_25_140000_add_replay_identity_to_recruit_application_receipts",
)
# A ledger row with a CRM table's name in it is a CRM migration. One not in MIGRATIONS is refused:
# the reviewed layout pin catches one that changes a table, but not a data-only one.
CRM_MIGRATION_NAME = re.compile(r"(?:^|_)(?:crm|recruit)_|email_suppressions")
CRM_TABLES = frozenset({
    "crm_activities", "crm_ai_proposals", "crm_assignment_changes", "crm_campaign_recipients", "crm_campaigns",
    "crm_companies", "crm_contacts", "crm_drip_campaign_versions", "crm_drip_campaigns", "crm_drip_enrollments",
    "crm_drip_steps", "crm_duplicate_alerts", "crm_email_events", "crm_email_sends", "crm_email_template_versions",
    "crm_email_templates", "crm_event_calendar_links", "crm_event_types", "crm_events", "crm_goals",
    "crm_note_versions", "crm_notes", "crm_notices", "crm_quote_items", "crm_quotes", "crm_signatures",
    "crm_sms_requests", "crm_stage_changes", "crm_website_intake_receipts", "email_suppressions",
    "recruit_applicant_files", "recruit_applicants", "recruit_application_receipts", "recruit_job_postings",
    "recruit_notes", "recruit_roster", "recruit_stage_changes",
})
SHARED_TABLES = frozenset({
    "external_booking_submissions", "google_calendar_connections", "messages", "services", "shoots", "users",
    "website_email_deliveries",
})

# Application references from the CRM models, intake, calendar and recruiting services.
# Missing historical parents are compared by row identity with the same protected key.
REFERENCES = {
    "crm_activities": {"contact_id": "crm_contacts", "actor_id": "users"},
    "crm_ai_proposals": {"contact_id": "crm_contacts", "user_id": "users"},
    "crm_assignment_changes": {"contact_id": "crm_contacts", "from_rep_id": "users", "to_rep_id": "users", "changed_by": "users"},
    "crm_campaign_recipients": {"campaign_id": "crm_campaigns", "contact_id": "crm_contacts", "send_id": "crm_email_sends"},
    "crm_campaigns": {"owner_id": "users", "template_id": "crm_email_templates", "template_version_id": "crm_email_template_versions", "reviewed_by": "users"},
    "crm_companies": {"created_by": "users"},
    "crm_contacts": {"client_user_id": "users", "assigned_rep_id": "users", "company_id": "crm_companies", "created_by": "users"},
    "crm_drip_campaign_versions": {"campaign_id": "crm_drip_campaigns", "published_by": "users"},
    "crm_drip_campaigns": {"published_version_id": "crm_drip_campaign_versions", "draft_version_id": "crm_drip_campaign_versions", "created_by": "users"},
    "crm_drip_enrollments": {"contact_id": "crm_contacts", "campaign_id": "crm_drip_campaigns", "active_contact_id": "crm_contacts", "enrolled_by": "users"},
    "crm_drip_steps": {"campaign_version_id": "crm_drip_campaign_versions", "template_id": "crm_email_templates", "template_version_id": "crm_email_template_versions"},
    "crm_duplicate_alerts": {"matched_contact_id": "crm_contacts", "attempted_by": "users", "resolved_by": "users"},
    "crm_email_events": {"send_id": "crm_email_sends"},
    "crm_email_sends": {"contact_id": "crm_contacts", "applicant_id": "recruit_applicants", "sender_user_id": "users", "template_version_id": "crm_email_template_versions", "signature_id": "crm_signatures"},
    "crm_email_template_versions": {"template_id": "crm_email_templates", "created_by": "users", "reviewed_by": "users"},
    "crm_email_templates": {"approved_version_id": "crm_email_template_versions", "latest_version_id": "crm_email_template_versions", "owner_id": "users"},
    "crm_event_calendar_links": {"event_id": "crm_events", "user_id": "users"},
    "crm_events": {"contact_id": "crm_contacts", "applicant_id": "recruit_applicants", "type_id": "crm_event_types", "assigned_user_id": "users", "next_event_id": "crm_events", "created_by": "users"},
    "crm_goals": {"user_id": "users", "set_by": "users"},
    "crm_note_versions": {"note_id": "crm_notes", "saved_by": "users"},
    "crm_notes": {"contact_id": "crm_contacts", "author_id": "users"},
    "crm_notices": {"user_id": "users", "contact_id": "crm_contacts"},
    "crm_quote_items": {"quote_id": "crm_quotes", "service_id": "services"},
    "crm_quotes": {"contact_id": "crm_contacts", "created_by": "users", "approved_by": "users", "follow_up_event_id": "crm_events", "booking_shoot_id": "shoots"},
    "crm_signatures": {"user_id": "users", "reviewed_by": "users"},
    "crm_sms_requests": {"contact_id": "crm_contacts", "user_id": "users", "message_id": "messages"},
    "crm_stage_changes": {"contact_id": "crm_contacts", "changed_by": "users"},
    "email_suppressions": {"contact_id": "crm_contacts"},
    "recruit_applicant_files": {"applicant_id": "recruit_applicants", "uploaded_by": "users"},
    "recruit_applicants": {"posting_id": "recruit_job_postings", "hired_user_id": "users", "created_by": "users"},
    "recruit_application_receipts": {"applicant_id": "recruit_applicants"},
    "recruit_job_postings": {"created_by": "users"},
    "recruit_notes": {"applicant_id": "recruit_applicants", "author_id": "users"},
    "recruit_roster": {"user_id": "users", "applicant_id": "recruit_applicants"},
    "recruit_stage_changes": {"applicant_id": "recruit_applicants", "changed_by": "users"},
    "external_booking_submissions": {"shoot_id": "shoots"},
    "google_calendar_connections": {"user_id": "users"},
    "website_email_deliveries": {"message_id": "messages"},
}
ACTIVITY_SUBJECTS = {"event": "crm_events", "note": "crm_notes", "quote": "crm_quotes"}
EMAIL_SOURCES = {
    "campaign": "crm_campaigns", "drip": "crm_drip_enrollments", "quote": "crm_quotes",
    "recruiting": "recruit_applicants", "reply_fwd": "crm_email_sends",
}


def read_key(path):
    need(path.is_absolute() and not path.is_symlink() and path.resolve(strict=True) == path, "canonical-key-file-required")
    info = path.stat()
    need(stat.S_ISREG(info.st_mode) and info.st_nlink == 1 and info.st_size == 32
         and (os.name == "nt" or info.st_mode & 0o077 == 0), "private-32-byte-key-file-required")
    with path.open("rb") as stream:
        key = stream.read(33)
    need(len(key) == 32, "private-32-byte-key-file-required")
    return key


def add_digest(digest, values):
    digest.update(schema_drift.packed(values).encode("utf-8") + b"\n")


def ledger(db, key):
    marks = ",".join("?" * len(MIGRATIONS))
    rows = list(db.execute(
        f"SELECT migration, id, batch FROM migrations WHERE migration IN ({marks}) ORDER BY migration COLLATE BINARY",
        MIGRATIONS,
    ))
    need(tuple(row[0] for row in rows) == MIGRATIONS, "crm-migration-ledger-differs")
    need(not any(isinstance(name, str) and CRM_MIGRATION_NAME.search(name) and name not in MIGRATIONS
                 for name, in db.execute("SELECT migration FROM migrations")), "crm-migration-ledger-differs")
    need(all(type(row[1]) is int and type(row[2]) is int for row in rows), "crm-migration-ledger-types-differ")
    digest = hmac.new(key, b"repro.crm.migration-ledger.v1\n", hashlib.sha256)
    for row in rows:
        add_digest(digest, row)
    return {"count": len(rows), "hmac_sha256": digest.hexdigest()}


def reference(db, key, child, column, parent, *, target="id", selector=None):
    identity = "reference" if child == "recruit_application_receipts" else "id"
    metadata = db.execute(f"PRAGMA main.table_info({q(child)})")
    need([row[1] for row in metadata if row[5]] == [identity], "crm-reference-primary-key-differs")
    label = f"{child}.{column}->{parent}.{target}"
    where, parameters = "", ()
    if selector:
        field, value = selector
        where = f"WHERE child.{q(field)} COLLATE BINARY = ?"
        parameters = (value,)
        label += f"[{field}={value}]"
    sql = (
        f"SELECT child.{q(identity)},child.{q(column)},EXISTS (SELECT 1 FROM {q(parent)} parent "
        f"WHERE parent.{q(target)}=child.{q(column)}) FROM {q(child)} child "
        f"{where} ORDER BY child.{q(identity)} COLLATE BINARY"
    )
    full = hmac.new(key, f"repro.crm.reference.v1\n{label}\n".encode(), hashlib.sha256)
    missing = hmac.new(key, f"repro.crm.missing-reference.v1\n{label}\n".encode(), hashlib.sha256)
    counts = {"null": 0, "linked": 0, "missing": 0}
    key_type = str if identity == "reference" else int
    for row_id, parent_id, found in db.execute(sql, parameters):
        need(type(row_id) is key_type and (parent_id is None or type(parent_id) is int), "crm-reference-types-differ")
        state = "null" if parent_id is None else "linked" if found else "missing"
        counts[state] += 1
        add_digest(full, (row_id, parent_id, state))
        if state == "missing":
            add_digest(missing, (row_id, parent_id))
    return label, counts | {"hmac_sha256": full.hexdigest(), "missing_hmac_sha256": missing.hexdigest()}


def audit(snapshot, snapshot_sha256, key):
    schema_drift.checked_file(snapshot, snapshot_sha256)
    with closing(sqlite3.connect(snapshot.as_uri() + "?mode=ro&immutable=1", uri=True)) as db:
        db.execute("PRAGMA query_only=ON")
        db.execute("PRAGMA trusted_schema=OFF")
        need(db.execute("PRAGMA query_only").fetchone()[0] == 1, "crm-query-only-not-enabled")
        catalog = {name: kind for schema, name, kind, *_ in db.execute("PRAGMA main.table_list") if schema == "main"}
        tables = {name for name, kind in catalog.items() if kind == "table"}
        crm = {name for name, kind in catalog.items() if kind != "view"
               and (name.lower().startswith(("crm_", "recruit_")) or name.lower() == "email_suppressions")}
        need(crm == CRM_TABLES and crm <= tables, "crm-table-set-differs")
        need((SHARED_TABLES | {"migrations"}) <= tables, "crm-shared-tables-missing")
        migrations = ledger(db, key)
        counts = {table: db.execute(f"SELECT COUNT(*) FROM {q(table)}").fetchone()[0] for table in sorted(crm | SHARED_TABLES)}
        links = dict(reference(db, key, child, column, parent)
                     for child, fields in REFERENCES.items() for column, parent in fields.items())
        for source, column, selector, mapping in (
            ("crm_activities", "subject_id", "subject_type", ACTIVITY_SUBJECTS),
            ("crm_email_sends", "source_id", "source_type", EMAIL_SOURCES),
        ):
            links.update(reference(db, key, source, column, parent, selector=(selector, kind)) for kind, parent in mapping.items())
        label, result = reference(db, key, "crm_event_calendar_links", "user_id", "google_calendar_connections", target="user_id")
        links[label] = result
        need(links["crm_contacts.client_user_id->users.id"]["linked"] > 0, "crm-client-links-unpopulated")
    schema_drift.checked_file(snapshot, snapshot_sha256)
    return {"table_counts": counts, "migrations": migrations, "references": links}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--snapshot", required=True, type=Path)
    parser.add_argument("--snapshot-sha256", required=True)
    parser.add_argument("--key-file", required=True, type=Path)
    parser.add_argument("--compare-snapshot", type=Path)
    parser.add_argument("--compare-snapshot-sha256")
    args = parser.parse_args()
    try:
        need(bool(args.compare_snapshot) == bool(args.compare_snapshot_sha256), "comparison-input-pair-required")
        need(args.compare_snapshot != args.snapshot, "distinct-comparison-snapshot-required")
        key = read_key(args.key_file)
        result = audit(args.snapshot, args.snapshot_sha256, key)
        report = {"schema": REPORT_SCHEMA, "result": "verified", "snapshot_sha256": args.snapshot_sha256, **result}
        if args.compare_snapshot:
            other = audit(args.compare_snapshot, args.compare_snapshot_sha256, key)
            parity = {section: result[section] == other[section] for section in result}
            report.update(comparison_snapshot_sha256=args.compare_snapshot_sha256, parity=parity)
            if not all(parity.values()):
                report.update(result="refused", reason="crm-snapshot-parity-differs")
    except schema_drift.Refusal as error:
        report = {"schema": REPORT_SCHEMA, "result": "refused", "reason": str(error)}
    except (OSError, RuntimeError, sqlite3.Error, UnicodeError):
        report = {"schema": REPORT_SCHEMA, "result": "refused", "reason": "crm-snapshot-or-key-unreadable"}
    print(json.dumps(report, sort_keys=True))
    return 0 if report["result"] == "verified" else 1


if __name__ == "__main__":
    raise SystemExit(main())
