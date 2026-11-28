# Restore an active cutover run to SQLite

The owner approves each operation. Run the installed controller as root on the approved host. These commands operate on the active post-write journal. They leave ingress, application workers, outbound work, deployments and the scheduler held. Keep the database, reconciliation records, raw logs and credentials on the private host.

1. Pause long jobs and Studio according to the release checklist. Run `way-back close`. It withdraws all four admissions, stops writers, takes the exclusive writer lease, revokes the MySQL application grants, checks for zero application sessions and writes the server UUID, GTIDs and binary-log position to the root-private `state/way-back.json`. A repeated close must observe the same coordinate. Investigate a changed coordinate before continuing.
2. Run `way-back audit`. Save the counts, receipt and close SHA-256. The controller reads from the promotion's armed coordinate and keeps SQL statements and row data out of its output. A purged log, an incomplete smoke, multiple completed smoke windows, an unknown event or business activity requires the full export path. The optional short path accepts foreign activity before the gate first opened and exactly one authenticated application smoke with its four consecutive GTIDs on this target. Its first transaction writes the marked user, shoot and Aryeo change. The next three push, reserve and delete the smoke job. The audit also permits a quiet window.
3. For the full path, run the pinned `scripts/mysql/export-sqlite.py --writers-stopped` into a **new** private output directory. Supply the socket-bound source credentials, target schema and server UUID, reviewed exceptions and their SHA-256, and a standalone copy of the original frozen SQLite file whose checksum matches the run's sealed hash. Verify the converter's `result.json`, the approved schema plan and the new `database.sqlite` checksum. Provide the parity reader as a separate protected credentials file. Check the filename and contents before use.
4. Reconcile website submissions, provider events, payments, Calendar changes, outbound messages and queued work against their durable references. Resolve uncertain deliveries, duplicates and pending changes. Save a private report, hash it and prepare the run-bound owner review. Keep inputs and their parent paths protected by root. Use mode `0600` for each input file and `0700` for private directories.
5. Run `way-back install-sqlite --file /private/new-export/database.sqlite --sha256 H --review /private/owner.json`. The controller reruns the independent PHP field reader over both databases, checks totals and identity, retains the frozen SQLite inode beside the database in a private directory and atomically installs the new bytes. It restores the original switched environment keys and pinned cache, exercises the SQLite read-only smoke as both application accounts, and records the new file digest and measured source identity. For an explicitly approved short path use `--keep-frozen` in place of `--file`, with the original sealed digest.
6. Review the protected `state/wayback-installed.json` and retained artifacts. Use the next-policy and held-initialization procedure to stage the new authority. Reopening remains a separate decision. A failed verification leaves admissions closed. Retry the same reviewed install after resolving the error. If a prior close, approved digest or source evidence changed, preserve the files and investigate.

The root-private owner JSON has the following fields. `database` is the MySQL schema here. In the resulting installed receipt, `database` is the absolute SQLite file path.

```json
{
  "version": 1,
  "run_id": "<active-run>",
  "server_uuid": "<pinned-MySQL-UUID>",
  "database": "<MySQL-schema>",
  "method": "verified-export",
  "installed_sha256": "<new-SQLite-file-digest>",
  "close_sha256": "<digest-of-exact-state/way-back.json-bytes>",
  "owner_approved": true,
  "effects": {
    "website": "reconciled",
    "providers": "reconciled",
    "payments": "reconciled",
    "calendar": "reconciled",
    "outbound": "reconciled",
    "queues": "reconciled"
  },
  "reconciliation_report": "/private/reconciled.json",
  "reconciliation_sha256": "<reviewed-report-digest>",
  "schema_plan": "/private/schema-plan.json",
  "schema_plan_sha256": "<plan-digest>",
  "reader_credentials": "/private/parity-reader.json",
  "reviewed_reverse_layout": false
}
```

For the approved short path, set `method` to `verified-prewrite`, use the original sealed digest, and omit the four fields beginning with `schema_plan` through `reviewed_reverse_layout`. Enable `reviewed_reverse_layout` only for the reviewed messaging overlay in the release's PHP field verifier. The receipt's `source_sha256` identifies the installed runtime file by its host, filesystem, inode and path. Its `installed_sha256` hashes all database bytes.
