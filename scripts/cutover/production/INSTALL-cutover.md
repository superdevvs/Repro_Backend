# MySQL cutover installation and operator decision sheet

**Status on 28 November 2026 — Hold.** The isolated drill release `e18f8ba3` passed the SQLite-return scenario. The cutover branch has separate MySQL write-open, restart and reverse-export acceptance criteria. The production application requires explicit cutover approval. This sheet is for rehearsal and release review.

## Package for administrator review

Build one archive at the selected cutover commit. Verify the release SHA, application fingerprint, Composer lock, file hashes, destination ownership and installed configuration on the independent host. Install the shared files from the same tree as these additional files. A mix of drill and cutover files can prevent the controller from starting. [Shared file manifest](INSTALL-W1.md)

| Source | Destination | Mode or action |
| --- | --- | --- |
| `mysql_reopen.py` | `/opt/repro-cutover-fence/production/mysql_reopen.py` | 0644 |
| `mysql_reopen_smoke.py` | `/opt/repro-cutover-fence/production/mysql_reopen_smoke.py` | 0644 |
| `retire.py` | `/opt/repro-cutover-fence/production/retire.py` | 0644 |
| `wayback_policy.py` | `/opt/repro-cutover-fence/production/wayback_policy.py` | 0644 |
| `worker_start.py` | `/opt/repro-cutover-fence/production/worker_start.py` | 0644 |
| `systemd/repro-cutover-retired-ingress.service` | `/etc/systemd/system/repro-cutover-retired-ingress.service` | 0644 |

Review the fixed operator wrapper and sudoers before installation. Use the administrator session for recovery and retirement actions. Keep policy pairs, recovery manifests and verifier input files root-owned in private directories. Preserve the current files for undo, validate systemd, FPM, nginx and sudoers, and exercise the reviewed commands in the isolated host sandbox. Enable host units and switch into cutover mode only in the separately approved window.

## Evidence to put on the decision sheet

| Evidence | Acceptance |
| --- | --- |
| Release and data | Exact candidate CI on both engines, application key reference available on the recovery host, post-CRM SQLite integrity and migration ledger, independent per-field parity, matching ID high-water marks, all reviewed historical exceptions retained. |
| Finance and CRM | Invoice and payout calculations agree, client-import dry-run finds existing links, encrypted fields open under the retained key, applicant storage checks agree, and authorization and retry cases pass. |
| Writers and external effects | Review the deployed website revision, outbox scheduler owner and durable retry behavior. Account for HTTP writes, jobs, cron, long-running workers, CRM and Calendar, callbacks, public quote views, opt-outs and uncertain sends. Keep outbound delivery held through reconciliation. |
| Backups, restore and paging | Five qualifying parity nights, final two on the frozen release. Seven actual days of off-site evidence. Latest snapshot under 26 hours old, log lag within five minutes for at least 99% of observations, two independent restores within 30 minutes and both failure types visible on the phone. |
| Load and reliability | Replay the same representative concurrent workload on both engines. Record request throughput, median and p95 latency, queue age, database lock waits, deadlocks, retries and duplicate or missing effects. Compare against the agreed service objectives before claiming an improvement. |
| Host and clock | Pinned separate MySQL 8.4.13 service, restricted accounts, real timers and restart behavior, adequate space, measured P4 uplink and base sizes, two complete representative outages within 60 minutes, including replay and verified service reopening. |

Record the SQLite-compatible release sequence for the tenth CRM migration before the final schema pin. Store only aggregate counts, hashes, UTC timestamps, decisions and evidence references in shared reports. [CRM checks, privacy and writer inventory](../../../docs/mysql-production-cutover.md#sales-crm-on-sqlite-before-cutover)

## Window measured from the first held business write

The times below are targets for the two host rehearsals. Record actual UTC hold, verified reopen and successful write admission for each. Include any earlier producer hold that removes the ability to complete a business write. Public downtime depends on the critical path across every producer.

| Elapsed | Operator checkpoint | If the checkpoint fails |
| --- | --- | --- |
| Before the hold | Record the owner, verifier and rollback operator, active release and flags, capacity, replay ledger, validated independent recovery, and observed upload throughput. Inventory long jobs and pause them as reviewed. Generate and review a spare policy for the same frozen inode using a fresh run ID. | Reschedule if prerequisites, decision makers or the 60-minute recovery margin are missing. |
| 0–12 min | Approved hold, stop and drain writers, capture the frozen SQLite snapshot, checksum and integrity result. Start its approved off-site publication. | Reopen SQLite through the verified pre-write rollback. |
| Import window | Arm the 20-minute frozen-phase deadline when import starts. Import to the intended empty target, verify independently, then finalize schema, historical exceptions, CRM, finance and MySQL backup coordinates. | On a missed deadline, error, warning or unapproved difference, use pre-write rollback. |
| By 38 min | Verify both backup publications and read-only smoke. Bind the evidence to the current run, reviewed snapshot, server UUID and coordinates. Finish prepare. | Abort while SQLite can safely resume. |
| By 40 min | Independent verifier reads the evidence. Request the owner’s five-minute promotion approval. Record elapsed time and remaining recovery budget. | Expiry rolls back a matching pre-write phase. |
| By 45 min | Act only on the owner’s current approval with enough time for the observed recovery path. The real watchdog handles a held pre-write run. | After MySQL write capability becomes possible, keep traffic held and use verified post-write recovery. |
| By 60 min | Confirm routed writes on the chosen engine, drains, both replay passes, queue health and the scheduler disposition. Record the actual outage and reconcile every external producer before enabling outbound effects. | Report an outage breach and retain the evidence. Either dress rehearsal exceeding 60 minutes keeps cutover on hold. |

Calculate P4 using the measured sustained upload rate and both actual compressed bases. Record whether the real uploads overlap and when each independent check completes. A local synthetic result or a drill waiver supplies no production upload budget. Stop before promotion if the measured worst-case failure path cannot fit the remaining outage budget.

Commit the scheduler release and finish any interrupted MySQL reopen before retiring into normal service. Record who reconciled the final producer and provider ledger. Rehearse service interruption after ingress opens, when real writes may already have occurred.

## Recovery decision

- **Before MySQL can accept a business write**, verify the frozen SQLite checksum, route and configuration, reopen in stages, reconcile the ledger and release the scheduler last. The optional unattended SQLite reopen requires pre-hold owner authorization. Its default is off.
- **After any possible MySQL business write**, close ingress and all writer authority, drain writers, close the gate, record the exact log coordinates and reconcile external effects with the owner. The normal path exports into a new SQLite file, verifies it independently, and checks a staged application read on that file. Pin the template and exceptions for `export-sqlite.py --writers-stopped`. The reviewed messaging overlay uses `--reviewed-reverse-layout` only with `verify-fields.php`. Record both file identity and SHA-256.
- **A failed first reopen may qualify for the frozen-file path** only when a complete authenticated audit proves every application transaction came from the approved disposable smoke, the frozen file is unchanged and the owner approves that evidence. Missing logs, an ordinary write, replay, an uncertain external effect or ambiguous attribution means use the new-file path. A preserved smoke queue job requires owner review before cleanup. [Smoke queue procedure](../probe/README.md)
- After installing the verified file, measure a fresh policy for the installed inode, review its matching identity, retire under hold and use `init --from-held`. The direct FPM check keeps ingress closed throughout initialization. Refresh current-boot authority, reopen in stages and confirm provider reconciliation before releasing the scheduler. Retain the frozen input, MySQL backup, binary logs and signed-off ledger for the agreed rollback period.
