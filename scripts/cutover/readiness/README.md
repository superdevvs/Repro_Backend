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
| `prerequisites` | The reviewed report digest and identity digest, both engine CI results, exact data and schema parity, critical business workflows, provider isolation, native host capacity and isolation, actual writer-fence restart, and crash recovery. Each result uses the corresponding field in `PREREQUISITES`. |
| `dress_rehearsals` | Exactly two distinct trial IDs and report digests. Each records its source snapshot, pinned identity, host and workload digests, peak and surge completion, results for the timed operations and workload metrics below. |
| `offsite_recovery` | Independent restore report and identity digests, proof that the restore used off-site artifacts alone, contiguous binlogs, replay to a chosen transaction, successful decryption of a stored value, database recovery time and measured loss. |
| `operations` in policy | `destination_sha256`, `monitor_profile_sha256`, `phone_recipient_sha256`, the reviewed `window_end_unix_s`, `interval_s`, and `evidence_valid_until_unix_s`. Pin both the source report and canonical receipt summary digest for each of the four operational reports. Hash the approved destination configuration, monitoring profile, and delivery identity without embedding credentials. |
| `backup_health` | External observations on the pinned profile and destination for the full seven days ending at the reviewed time. Record complete elapsed coverage and distinct scheduled samples, worst verified snapshot age, and elapsed seconds during which the independently recoverable binlog position was at most five minutes behind. |
| `offsite_retention` | An exact configured-provider policy review and scoped test of the approved writer's overwrite and delete refusals. Preserve separate evidence that a delete-permitted credential was blocked by the lock and that the file's bytes stayed identical. Use the [R2 retention verifier](../../mysql/offsite/README.md#verify-bucket-retention) against the pinned provider capture. |
| `external_alerts` | Distinct `before_installer` and `before_drill` reports with every named case in `ALERT_LIMITS_MS`, measured from the induced condition to confirmed receipt on the approved phone. Record an independent delivery receipt digest for each case. |
| `point_in_time_drills` | Two distinct off-site-only restore reports, contiguous logs, verified chosen transactions, recovery duration, heartbeat distance from target, and verified exclusion of a planted bad write in one drill. |

The rehearsal clock starts when write admissions first close. Report the total
time to the reopen decision, verified SQLite rollback before writes, and verified
failback after MySQL writes. Milliseconds keep timing boundaries exact. Record
SQLite and MySQL p95 measurements in integer microseconds for every pinned
critical endpoint. Write metrics include attempt and contention counts and p95
latency for each engine. Use the same load profile on both engines. Capture
peak queue depth at the beginning and end of the normal peak test.

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

All four operational reports must match their independently reviewed digests
in the policy and the current cutover identity. The clock must be between the
reviewed window end and evidence expiry. A changed summary requires a new
reviewed digest. Keep the original raw monitoring samples, provider responses,
phone receipt records and restore comparisons for that review. Older receipts
can still report their component results. Missing operational evidence leaves
the overall decision at `no_go`.

```sh
python -B -m unittest discover -s scripts/cutover/readiness -p test_check.py -v
```
