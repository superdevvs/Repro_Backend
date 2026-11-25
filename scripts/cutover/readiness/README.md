# Migration readiness receipts

This offline check compares isolated rehearsal and recovery receipts against a
reviewed release policy. It reads local JSON files. It cannot collect production
evidence or authorize the cutover. The existing cutover runner retains the
final-snapshot checks, operator approval, and minute-45 rollback decision.

```sh
python -B -m scripts.cutover.readiness.check --policy review-policy.json --receipt readiness-receipt.json
```

Exit zero means the supplied evidence meets the review gates. Exit one reports
`no_go` and identifies gates as `missing` or `failed`. Review the originating
reports before accepting the receipt. Report digests identify those reports.
This checker does not authenticate their author or validate measurements against
raw traffic and backup logs.

## Required input

Both files are version 1 JSON objects with a complete cutover runner `identity`.
It binds the proposed run ID, release commit, release archive, source identity,
configuration and target server UUID. Use the runner's canonical JSON digest
for all identity and policy bindings. The test fixture shows the full record
shape and supplies synthetic values only.

| Record | Required evidence |
| --- | --- |
| Policy | `critical_endpoints` as reviewed unique labels, `host_profile_sha256`, and `workload_sha256`. Pin the environment and comparable SQLite/MySQL peak and surge workloads before testing. |
| Receipt header | `policy_sha256` and the same cutover identity. Any change to the policy or target invalidates a prior receipt. |
| `prerequisites` | The reviewed report digest and identity digest, both engine CI results, exact data and schema parity, critical business workflows, isolated CRM workflow parity, CRM ingress fencing and replay, provider isolation, native host capacity and isolation, actual writer-fence restart, the first-release MySQL writer serialization policy, and crash recovery. Each result uses the corresponding field in `PREREQUISITES`. |
| `dress_rehearsals` | Exactly two distinct trial IDs and report digests. Each records its source snapshot, pinned identity, host and workload digests, peak and surge completion, results for the timed operations and workload metrics below. |
| `offsite_recovery` | Independent restore report and identity digests, proof that the restore used off-site artifacts alone, contiguous binlogs, replay to a chosen transaction, successful decryption of a stored value, database recovery time and measured loss. |
| `operations` in policy | `destination_sha256`, `monitor_profile_sha256`, `phone_recipient_sha256`, `nightly_scope_sha256`, the reviewed `last_nightly_day_utc`, `window_end_unix_s`, `interval_s`, and `evidence_valid_until_unix_s`. Pin both the source report and canonical receipt summary digest for each operational report. Hash approved destination configuration, monitoring profile, delivery identity, and data scope without embedding credentials. |
| `backup_health` | External observations on the pinned profile and destination for the full seven days ending at the reviewed time. Record complete elapsed coverage and distinct scheduled samples, worst verified snapshot age, and elapsed seconds during which the independently recoverable binlog position was at most five minutes behind. |
| `offsite_retention` | An exact configured-provider policy review and scoped test of the approved writer's overwrite and delete refusals. Preserve separate evidence that a delete-permitted credential was blocked by the lock and that the file's bytes stayed identical. Use the [R2 retention verifier](../../mysql/offsite/README.md#verify-bucket-retention) against the pinned provider capture. |
| `external_alerts` | Distinct `before_installer` and `before_drill` reports with every named case in `ALERT_LIMITS_MS`, measured from the induced condition to confirmed receipt on the approved phone. Record an independent delivery receipt digest for each case. |
| `point_in_time_drills` | Two distinct off-site-only restore reports, contiguous logs, verified chosen transactions, recovery duration, heartbeat distance from target, and verified exclusion of a planted bad write in one drill. |
| `nightly_data_parity` | Five chronological reports for consecutive UTC schedule dates ending on `last_nightly_day_utc`. Each identifies its release and fresh snapshot, elapsed import and independent verification, source scope and integrity, row and exception parity, target settings and engine, SQLite reverse parity, invoice and payout parity, and a CRM review bound to the same night and input. |

The rehearsal clock starts when write admissions first close. Report the total
time to the reopen decision, verified SQLite rollback before writes, and verified
failback after MySQL writes. Milliseconds keep timing boundaries exact. Record
SQLite and MySQL p95 measurements in integer microseconds for every pinned
critical endpoint. Write metrics include attempt and contention counts and p95
latency for each engine. Use the same load profile on both engines. Capture
peak queue depth at the beginning and end of the normal peak test.

The writer serialization result requires the application policy to be active
on the exact candidate. Exercise explicit transactions, autocommit writes,
the bounded queue-bookkeeping exemption, and recovery from a failed or lost
commit. The cutover fence and isolated writer-token component have their
own evidence.

Bind CRM workflow parity to the pinned release and include the result of
private dry-run parity and isolated role, inquiry, booking, recruiting,
template, send, reply, opt-out and calendar tests. Bind the CRM admission and
replay result to the exercised host fence and reviewed producer ledger.
Verify restart, replay using the original provider and website identities,
attachment recovery, and the hold on outgoing sends until consent,
suppression and ambiguous sends are reconciled.

## Derive the seven-day backup health summary

Review the external monitor's original reports and retain them with the evidence.
Normalize the reviewed observations to UTF-8 JSON Lines. The first row has
`schema: "repro.backup-health.observations.v1"`, `identity_sha256`,
`destination_sha256`, `monitor_profile_sha256`, `window_start_unix_s`,
`window_end_unix_s`, `interval_s`, and `external_observer_verified: true`.
These fields must match the approved release, monitor, destination, and actual
seven-day schedule. Use integer UTC epoch seconds throughout.

After the header, supply exactly one observation per scheduled slot, in time
order, from the window start up to the window end. Each row has these fields.

- `slot_start_unix_s` is the exact scheduled slot boundary. Timestamped evidence
  may establish what was independently usable at the boundary even when the
  collector executed later. Receipts for availability achieved later apply only
  to subsequent slots. Missing coverage prevents the summary.
- `sample_sha256` identifies the reviewed original evidence for this slot. Retain
  those original bytes and confirm the provenance and scope during review.
- `newest_verified_base_unix_s` is the creation time of the newest verified base
  snapshot usable at the slot boundary. Unknown base age prevents the summary.
- `recoverable_through_unix_s` is the newest point independently recoverable from
  that base and its verified contiguous binlogs at the slot boundary. Use `null`
  for a covered slot with an unverified recovery point. It contributes zero
  timely seconds.

After independent review, pin the exact normalized file bytes as the policy's
`backup_health_report_sha256`, then generate the summary locally.

```sh
python -B -m scripts.cutover.readiness.summarize_backup_health --policy review-policy.json --observations backup-health.jsonl
```

The command uses each sample only for its own slot, keeps the recovery point
fixed within that slot, and measures worst verified base age through the slot
end. Review the resulting `backup_health` object and pin the emitted canonical
`summary_sha256` as `backup_health_summary_sha256` in the final policy. Insert the
object into the receipt and update `policy_sha256` after final review. A complete
report that misses a threshold still emits its summary with `thresholds_met`
false and exits one. Invalid input exits one without a summary. The adapter
checks the supplied evidence and its digest. Independent review establishes the
raw monitor provenance. The adapter's tests use synthetic observations.

## Assemble five nightly parity reports

For each actual nightly run, retain the original evidence and have a reviewer
record its results in a UTF-8 JSON object. Include `scheduled_day_utc`
(`YYYY-MM-DD`), `release_sha`, `snapshot_sha256`, `nightly_scope_sha256`,
and `import_and_verify_ms` (elapsed time from import start to the end of both
independent verifications). Include each integer counter in `NIGHTLY_COUNTERS`
and each boolean result in `NIGHTLY_PROOFS` from
[`check.py`](check.py). Keep the snapshot creation evidence even when two
fresh snapshots have identical contents.

When the capture uses `scripts/deploy/backup-database.php`, independently
review its `database-backup.json` and pin its exact bytes alongside the
nightly report. Check the UTC capture date and snapshot checksum locally.

```sh
python -B -m scripts.cutover.readiness.verify_nightly_capture \
  --night night1.json --night-sha256 "$NIGHT_SHA" \
  --producer database-backup.json --producer-sha256 "$PRODUCER_SHA"
```

Retain the original producer receipt for each night. A receipt used on a
different UTC date fails even if the snapshot bytes are identical. This
supplemental check verifies the exact supplied records. Reviewers still
confirm the capture's provenance and the complete nightly parity results.
For the private online-copy workflow, supply its original date-bearing
receipt as `--producer` and the independently observed deployed source
release as `--expected-source-release`. That workflow records the UTC
completion of the copy and integrity checks. The nightly report separately
identifies the candidate release under test. The check emits only the UTC
time and digests for review.

Review the same night's CRM evidence and save a separate JSON object with
`schema` set to `repro.nightly-crm-parity.review.v1`. Copy the night's exact
`scheduled_day_utc`, `release_sha`, `snapshot_sha256` and `nightly_scope_sha256`.
Set `audit_report_sha256` to the independently reviewed CRM source reference
audit and `parity_evidence_sha256` to the independently reviewed evidence
index for the MySQL comparisons, client dry run and applicant files. Both
refer to exact protected report bytes. Record the full CRM table total as
`crm_tables_verified` and the positive count of verified linked clients as
`verified_linked_client_count`. Include every boolean in `NIGHTLY_CRM_PROOFS`
from [`check.py`](check.py). Confirm each from the reviewed source evidence.

| CRM proof | What review verifies |
| --- | --- |
| `crm_field_and_identity_parity_verified` | All CRM migrations, table counts, fields and issued identities match across engines. Compare shared records, consent and suppression, ownership, dates, decimals, JSON and high-water marks. |
| `crm_relationship_and_exception_parity_verified` | CRM and shared links, including existing imported client links and nullable links, match by stable identity. Reviewed historical exceptions are preserved. |
| `crm_replay_state_parity_verified` | Receipt references, provider identities, idempotency keys, reply tokens and pending, completed and uncertain states match. |
| `crm_client_import_dry_run_verified` | Both copied engines report the same client-import dry-run counts, including the count of already-linked clients. Existing client-to-contact identities are verified independently. |
| `crm_applicant_file_parity_verified` | Private file metadata, file bytes and checksums match, and permitted and denied access checks pass on the isolated target. Record an empty inventory when no applicant files exist. |

Review the exact bytes of all ten reports and the actual migration scope.
Pin the scope file digest as `operations.nightly_scope_sha256` after auditing
its table names against the fresh snapshot. All CRM and required shared
tables are preserved in class A. A manifest with schema
`repro.nightly-data-parity.manifest.v2` carries `identity_sha256` for the cutover
identity, `nightly_scope_sha256`, and `nights`. Each entry lists the
`scheduled_day_utc`, the general report's `report_sha256` and the CRM review's
`crm_report_sha256` in chronological order. Pin the manifest's exact file
digest as `operations.nightly_data_parity_report_sha256`.

```sh
python -B -m scripts.cutover.readiness.summarize_nightly_parity \
  --policy review-policy.json --manifest reviewed-nights.json --scope migration-scope.json \
  --nights night1.json night2.json night3.json night4.json night5.json \
  --crm-reports crm1.json crm2.json crm3.json crm4.json crm5.json
```

The adapter verifies the supplied file digests and the exact scope, date,
release and snapshot bindings, then applies the nightly and CRM parity gates.
Only the CRM-aware summary schema can satisfy the nightly gate. Put the emitted
`nightly_data_parity` object in the readiness receipt. After review, pin its
`summary_sha256` as `operations.nightly_data_parity_summary_sha256` and update
the receipt's `policy_sha256`. Independent reviewers establish the provenance
and parity results of the protected artifacts referred to by the digests. Keep
CRM rows, applicant files, secrets and raw webhook payloads in private storage.

## Assemble point-in-time recovery drills

Retain each drill's tool output and a reviewed native replay report in a
separate evidence directory.

- `artifacts.json` is the database artifact recovery receipt from
  `restore_backup.py`.
- `chain.json` is the result of `verify_mysql_binlog_chain.py` for the selected
  stopping point.
- `heartbeat.json` is the result of `heartbeat.py verify` on the recovered
  instance at that target.
- `replay.json` records the independently observed native SQL replay. Set
  `result` to `native-mysql-replay-verified` only after executing and verifying
  the replay. Include `run_id`, `destination_sha256`, `source_uuid`,
  `restored_uuid`, the exact UTC second `target_utc`, and `replayed_stop` as
  the verified binlog file and position. Include each boolean in
  `PITR_REPLAY_PROOFS` and `planted_bad_write_excluded`. Include
  `recovery_started_monotonic_ns` from the start of off-site retrieval and
  `recovery_verified_monotonic_ns` from completion of target verification,
  taken on the same monotonic clock. Bind the exact bytes of the other three
  files in `artifacts_sha256`, `chain_sha256`, and `heartbeat_sha256`.

Create a reviewed JSON manifest with schema
`repro.point-in-time-drills.manifest.v1`, `identity_sha256`,
`destination_sha256`, and `replay_report_sha256s` containing the two replay
file digests in directory order. Pin the manifest's exact digest under
`operations.point_in_time_drills_report_sha256`.

```sh
python -B -m scripts.cutover.readiness.summarize_point_in_time --policy review-policy.json --manifest reviewed-drills.json --drills drill-a drill-b
```

The adapter matches source and recovery identities, binds the replay stop to
the verified chain, and derives the recovery duration and heartbeat gap. The
artifact download and chain verification cannot replace the replay report.
Review the original execution records, including destination and recovery
host isolation. Place the emitted `point_in_time_drills` object in the receipt,
pin the reviewed `summary_sha256` as
`operations.point_in_time_drills_summary_sha256`, and update `policy_sha256`.

## Derive the external alert summary

Keep the original induced-condition and handset observations. After reviewing
them, record their SHA-256 digests in a JSON observation report with schema
`repro.external-alerts.observations.v1`. Include `identity_sha256`,
`monitor_profile_sha256`, and `phone_recipient_sha256` from the reviewed scope.
Use digests in place of addresses, phone numbers, or credentials.

The `rounds` record contains `before_installer` and `before_drill`. Each has
the original round's `report_sha256` and a `cases` record containing all names
in `ALERT_LIMITS_MS`. For each case record `triggered_at_unix_ms`,
`condition_evidence_sha256`, `phone_observed_at_unix_ms`, and
`delivery_receipt_sha256`. Use UTC epoch milliseconds. Record the original
start of the induced condition, including when the server is made silent.
Use null for the time and receipt of an unconfirmed handset delivery. A
provider's delivery report alone leaves the handset observation pending.

Pin the exact observation file as `external_alerts_report_sha256` in the
reviewed policy. The command derives elapsed times, checks both rounds against
the existing alert deadlines, and emits a receipt only with complete proof.

```sh
python -B -m scripts.cutover.readiness.summarize_external_alerts --policy review-policy.json --observations alert-observations.json
```

Pin the emitted `summary_sha256` as `external_alerts_summary_sha256` after
review, add `external_alerts` to the readiness receipt, and update
`policy_sha256`. Verify receipt provenance independently.

## Pass marks

- Both rehearsals reach the reopen decision within **40 minutes**, restore
  SQLite before MySQL writes within **10 minutes**, and verify failback after
  MySQL writes within **60 minutes**.
- Both have zero lost acknowledged writes and duplicate business effects. Peak
  backlog does not grow and no critical endpoint p95 regresses by more than
  **10%**. Either write p95 or the proportion of writes experiencing contention
  must be strictly lower on MySQL in both rehearsals.
- The independent database recovery takes at most **60 minutes** and loses at
  most **five minutes** of data in the measured host-loss case. Application and
  media recovery require separate evidence.
- Every scheduled observation in the reviewed **seven-day** window exists and
  the youngest verified backup always has age strictly under **26 hours**.
  Recoverable off-site binlog lag is at most **five minutes** for at least
  **99%** of elapsed time. A matching provider lock protects the approved writer's
  objects and the observed ciphertext remains intact.
- Both alert rounds prove phone receipt in **five minutes** for each condition
  and **ten minutes** for a silent server. A systemd journal alert alone leaves
  external delivery unverified.
- The two point-in-time restores each finish within **30 minutes** and recover
  a heartbeat within **60 seconds** of target. One proves exclusion of the
  planted change. These drills supplement the separate host-loss measurement.
- All five nightly runs are data-green, with import and both verifications in
  **12 minutes** or less and zero unexpected field differences, errors, warnings
  or notes. Both final runs use the exact frozen release.

All operational reports must match their independently reviewed digests
in the policy and the current cutover identity. The clock must be between the
reviewed window end and evidence expiry. A changed summary requires a new
reviewed digest. Keep the original raw monitoring samples, provider responses,
phone receipt records and restore comparisons for that review. Older receipts
can still report their component results. Missing operational evidence leaves
the overall decision at `no_go`.

```sh
python -B -m unittest discover -s scripts/cutover/readiness -p test_check.py -v
```
