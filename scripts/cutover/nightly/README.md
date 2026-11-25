# Nightly parity runner and dress-rehearsal profile

`runner.py` runs one scheduled UTC night and writes every input a reviewer
needs for the nightly streak. `dress_profile.py` derives and pins the
dress-rehearsal host profile, workload and endpoint lists. `policy.py` checks
the draft review policy and manifest and pins reviewed nights into them.

None of these tools uses ssh, a network connection or a provider. The runner
does nothing on a server unless someone with approval starts it there.

## What one night produces

The runner works in `<state-dir>/<YYYY-MM-DD>/`, a private directory (mode 0700).

| Stage | Work | Main outputs |
| --- | --- | --- |
| `capture` | Read-only online backup of the live SQLite file with `scripts/deploy/backup-sqlite.php`, then checks of the CRM migrations, CRM tables and linked clients. | `copy-receipt.json` (the dated capture), `snapshot.sqlite` |
| `plan` | Scope audit, source scan with `migrate.py plan`, reverse schema template and the finance date census. The converted layout must equal `reviewed_schema_sha256`. | `scope-audit.json`, `forward-plan/`, `reverse-schema/`, `week-census.json` |
| `target` | Starts a disposable socket-only MySQL 8.4.13 with durable binary logs and checks its settings. | `target.json` |
| `import_and_verify` | `migrate.py import-empty` (import and checksum verification), then the independent PHP reader. Timed against the budget. | `forward-import-result.json`, `forward-fields-private.jsonl` |
| `reverse` | Optional reviewed trigger helper, export back to SQLite, PHP verification of the export and the CRM relationship audit. The disposable MySQL is then stopped. | `reverse-export-result.json`, `reverse-fields-private.jsonl`, `crm-audit-private.json` |
| `finance` | The configured finance step, after the reverse step. Its receipt is checked like the reviewed finance verifier. | `finance-proof.json` |
| `applicant_files` | `verify-crm-applicant-files.py` against the configured private disk. | `applicant-files.json` |
| `crm_review` | Draft CRM review and its evidence index. | `crm-review.json`, `crm-parity-evidence.json` |
| `report` | Draft nightly report and manifest entry, checked with `verify_nightly_capture` and the per-night gate. | `night.json`, `manifest-entry.json` |

`run-receipt.json` records the status, each stage's time, the import and
verification split, the budget, the projection, the tuning and the tool
digests. `night.json` has exactly the fields in `NIGHTLY_REPORT_FIELDS` and
`crm-review.json` exactly those in `NIGHTLY_CRM_FIELDS`. A reviewer still
confirms them against the evidence before pinning their digests.

The night's `release_sha` is the commit of the clean candidate checkout whose
tools ran. The capture's `release` is the live backend commit read from
`deploy-meta.json` at run time, before and after the copy. A deploy during the
copy stops the night.

## Safety

- `run` is a dry run unless `--execute` is given. A dry run prints the stages,
  their commands and the guard results. It writes nothing and runs no tool.
- `--execute` needs `--approval` with a file the owner approved:

  ```json
  {"schema": "repro.nightly-runner.approval.v1", "first_day_utc": "2026-11-27",
   "last_day_utc": "2026-12-05", "config_sha256": "<from check-config>"}
  ```

  The night must fall inside the dates and the configuration digest must match.
- One runner at a time holds `runner.lock` in the state directory.
- Before the copy: clean candidate checkout, every pinned file unchanged, deploy
  lock free, the scheduled date is today in UTC, the live database is SQLite,
  and free space is above `max(45 GB, 9 x live size)`. Before MySQL starts:
  `MemAvailable` covers the buffer pool plus an 8 GiB margin (at least 12 GiB).
- The capture must finish on the scheduled UTC date. A copy that crosses
  midnight is deleted.
- Every command runs under `nice` and `ionice`. MySQL runs with
  `--skip-networking`. A test checks that no command names ssh, curl, rclone,
  sudo, systemctl or a URL.
- The runner refuses to run as root.

## Budget and fail fast

Import plus both forward verifications must finish within 12 minutes
(720,000 ms). Measured runs took 593 to 754 seconds, so the runner:

- projects the time from the baseline in the config (596.7 s for 4,460,900,352
  bytes) and stops before the import when the projection is over budget;
- gives the import a timeout equal to the budget and the PHP reader only what
  is left, and stops the night when either reaches it;
- skips the reverse, finance and CRM stages after a budget stop, so the host
  is freed early;
- records `import_ms`, `verify_ms`, `import_and_verify_ms`, `projected_ms` and
  `headroom_ms`, plus every stage's time.

Set `budget.fail_fast` to `false` to measure an over-budget night to the end.
That night still cannot qualify.

## Tuning without weakening durability

`mysql_tuning` accepts only options that do not change what a commit
guarantees: `innodb_buffer_pool_size` (1G to 16G), `innodb_redo_log_capacity`
(512M to 16G), `innodb_log_buffer_size`, `innodb_io_capacity`,
`innodb_io_capacity_max`, `innodb_flush_method` (`fsync` or `O_DIRECT`) and
`innodb_adaptive_hash_index`. `priority` can move the timed stages from the
idle IO class to best-effort level 7 (`ionice_class: 2, ionice_level: 7`),
which still yields to the live site but is not starved by it.

The runner always passes `--sync-binlog=1`,
`--innodb-flush-log-at-trx-commit=1`, `--innodb-doublewrite=ON`, full-row
binary logs and GTIDs, and checks them on the running server. Any other option,
such as `sync_binlog`, `skip_log_bin` or `O_DIRECT_NO_FSYNC`, is refused.

The committed config keeps the measured profile (4G buffer pool, 2G redo, nice
10, idle IO). Change one option at a time and compare `run-receipt.json`
timings across nights without other jobs running.

## Resume and re-run

Each finished stage is journaled with the digests of its outputs. Running the
same night again:

- refuses if any journaled output changed, or the config or candidate changed;
- reuses the capture and plan;
- restarts `target`, `import_and_verify` and `reverse` together, because they
  share one disposable MySQL, after stopping a leftover `mysqld` that belongs to
  this night's work directory;
- returns the stored receipt without running anything once the night is done.

## Configuration

Validate a config and print the digest the approval must carry:

```sh
python3 -B -m scripts.cutover.nightly.runner check-config --config scripts/cutover/nightly/nightly-config.json
```

| Field | Committed value | Notes |
| --- | --- | --- |
| `candidate_root` | `/home/maverick/repro-nightly-candidate` | A clean checkout of the candidate release. |
| `scope` | `database/schema/migration-scope.json`, `a3807732…` | Re-pin after any reviewed scope change. |
| `reviewed_schema_sha256` | `87e79c75…` | The reviewed converted layout. A schema change stops the night until it is reviewed. |
| `reverse_triggers` | `null` | The reviewed trigger helper is not committed yet. Pin its path and digest here. |
| `finance` | `null` | The finance rehearsal needs an administrator sandbox. Until a step is configured, `invoice_and_payout_parity_verified` is false and the night cannot qualify. |
| `applicant_files_root` | `null` | Set to the private disk root to read applicant files read-only. |
| `retain_snapshots` | `null` | Keep every snapshot. A number keeps only that many of the newest runner snapshots; receipts are always kept. |

## Commands

```sh
# Dry run: guards and commands only.
python3 -B -m scripts.cutover.nightly.runner run --config nightly-config.json \
  --state-dir /home/maverick/repro-nightly --day 2026-11-27

# The night itself, with the owner's approval file.
python3 -B -m scripts.cutover.nightly.runner run --config nightly-config.json \
  --state-dir /home/maverick/repro-nightly --day 2026-11-27 --execute --approval approval.json
```

## After each night

1. Review the outputs, then add the night to the manifest:
   `python3 -B -m scripts.cutover.nightly.policy add-night --manifest reviewed-nights.json --entry <night>/manifest-entry.json --output reviewed-nights.next.json`.
2. After five consecutive nights, with the cutover identity set in the policy,
   pin the manifest: `policy pin-manifest --policy … --manifest … --policy-output … --manifest-output …`.
3. Build the summary with all five capture receipts:
   `python3 -B -m scripts.cutover.readiness.summarize_nightly_parity … --producer-receipts <five copy-receipt.json>`.
4. Pin its `summary_sha256` with `policy pin-summary` and run `readiness.check`.

## Dress-rehearsal profile

`dress/host-profile.json` and `dress/workload.json` are drafts for owner
review. The workload encodes the plan's stress and crash marks (ten minutes at
20 clients, three mid-write MySQL kills, 60-second recovery), the observed rates
(p95 minute 125, peak 237 requests) and a proposed surge of twice the peak.
`critical_endpoints` names the write workflows and money reports.

The 50 busiest endpoints need the private telemetry, so `busiest_endpoints` is
unset. On a private copy, rank them read-only and fill a new workload file:

```sh
python3 -B -m scripts.cutover.nightly.dress_profile busiest --snapshot snapshot.sqlite \
  --snapshot-sha256 <sha> --as-of-utc 2026-11-27T05:00:00Z > busiest.json
python3 -B -m scripts.cutover.nightly.dress_profile fill-workload --workload dress/workload.json \
  --busiest busiest.json --busiest-sha256 <sha of busiest.json> --output workload.reviewed.json
python3 -B -m scripts.cutover.nightly.dress_profile pins --host-profile dress/host-profile.json \
  --workload workload.reviewed.json
```

`pins` prints `host_profile_sha256`, `workload_sha256`, `critical_endpoints` and
`busiest_endpoints` for the policy.

## Policy and manifest drafts

`review-policy.draft.json` pins the nightly scope and the two profile files and
leaves the identity, the busiest list and the operations evidence unset.
`reviewed-nights.draft.json` is the empty v2 manifest for that scope.

```sh
python3 -B -m scripts.cutover.nightly.policy check-drafts --policy scripts/cutover/nightly/review-policy.draft.json \
  --manifest scripts/cutover/nightly/reviewed-nights.draft.json --scope database/schema/migration-scope.json \
  --host-profile scripts/cutover/nightly/dress/host-profile.json --workload scripts/cutover/nightly/dress/workload.json
```

It fails when a pin no longer matches its file and lists what is still unset.

## Tests

```sh
python3 -B -m unittest discover -s scripts/cutover/nightly -p 'test_*.py' -v
```

The runner tests use a fake host that simulates each tool and records every
command. `test_local_host.py` tests the real host adapter on Linux as an
unprivileged user and skips those cases elsewhere.
