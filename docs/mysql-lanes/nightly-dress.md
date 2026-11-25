# Lane nightly-dress: nightly runner and dress-rehearsal gates

Branch `claude/nightly-dress-20261125`, based on `a6e799bb` (draft PR 46 head).
Items N1, N2, D1 and T1 from the nightly and dress-rehearsal area. Everything
here was built and tested offline. Nothing ran against the production host,
the live site or any provider.

## What changed

| Item | Change | Where |
| --- | --- | --- |
| N1 | One committed nightly runner that writes every reviewer input for a night. Dry run by default, owner approval file required to execute, lock, guards, journal with resume, fail fast on the 12-minute budget, durability-neutral tuning only. | `scripts/cutover/nightly/runner.py`, `nightly-config.json` |
| N1 | `summarize_nightly_parity` now requires `--producer-receipts`, and every manifest entry must pin its capture receipt. | `scripts/cutover/readiness/summarize_nightly_parity.py` |
| N2 | Draft review policy with the nightly scope pin, empty v2 manifest, and a tool that checks the drafts, adds reviewed nights and pins the manifest and summary. | `scripts/cutover/nightly/review-policy.draft.json`, `reviewed-nights.draft.json`, `policy.py` |
| D1 | Draft dress-rehearsal host profile and pinned workload, with critical endpoints. A tool ranks the 50 busiest endpoints from a private copy and prints the policy pins. | `scripts/cutover/nightly/dress/`, `dress_profile.py` |
| D1 | The readiness checker now applies the plan's "Speed", "Stress and crash", "Data" and "Way back" marks to each dress rehearsal. | `scripts/cutover/readiness/check.py` |
| T1 | Readiness suite run on Windows and Linux. | See evidence below. |
| CI | `deploy.yml` also runs `scripts/cutover/nightly` tests. | `.github/workflows/deploy.yml` |

No PHP file changed. No production behaviour changes when this branch is
deployed: the new tools only run when someone starts them.

## Fold into docs/mysql-production-readiness.md

Links inside the quoted text are relative to `docs/`, ready to paste.

Suggested text for the nightly streak status:

> A committed runner now produces every input for one qualifying night:
> the dated private capture, import with both forward verifications,
> reverse SQLite parity, finance parity, applicant files and the CRM review,
> bound to the snapshot, candidate release and reviewed scope
> ([runner](../scripts/cutover/nightly/README.md)). It has only run against
> fakes. The streak count is still zero. Each night needs an approved run on
> the host, then review of its outputs.
>
> The nightly summary now refuses to run without the five dated capture
> receipts. Every manifest entry pins its capture.

Suggested text for the dress-rehearsal gates:

> The readiness checker now tests the plan's pass marks in both dress
> rehearsals, in addition to the earlier engineering thresholds. Speed: none
> of the 50 busiest requests makes a layout lookup, at least 45 are within
> 20 ms of SQLite at p95 and none is more than 100 ms slower. Stress and crash:
> ten minutes at 20 clients while workers clear a backlog with no server error,
> unhandled lock error, missing confirmed write or duplicate, then three
> mid-write MySQL kills each recovered within 60 seconds. Data: import and both
> verifications within 12 minutes, clean scan, zero errors, warnings, notes and
> unexplained differences. Way back: the exported SQLite file reproduces every
> value and the weekly money runs match on it. The critical-endpoint 10% p95
> ratio and the write-improvement check remain.

Suggested text for the dress-rehearsal profile:

> Draft host and workload profiles are committed for review
> ([profile](../scripts/cutover/nightly/README.md#dress-rehearsal-profile)).
> The workload uses the observed rates (p95 minute 125, peak 237 requests),
> proposes a surge of twice the peak, and encodes the plan's stress and crash
> test. The 50 busiest endpoints must be ranked from the request traces on a
> private copy before the policy can be completed.

## Fold into docs/mysql-production-cutover.md

Suggested runbook section, after "Import into the empty target":

> ### Run a qualifying night
>
> 1. Keep a clean checkout of the candidate release on the host. Check the
>    config and note its digest:
>    `python3 -B -m scripts.cutover.nightly.runner check-config --config scripts/cutover/nightly/nightly-config.json`.
> 2. Dry run for the night and review the guards:
>    `python3 -B -m scripts.cutover.nightly.runner run --config … --state-dir /home/maverick/repro-nightly --day YYYY-MM-DD`.
> 3. With the owner's approval file for that date range and config digest, run
>    it with `--execute --approval approval.json` on the scheduled UTC date.
> 4. Read `run-receipt.json`. A stopped night does not count. A budget stop
>    names the stage and its time.
> 5. Review `night.json`, `crm-review.json` and their evidence, then add
>    `manifest-entry.json` with `policy.py add-night`.
> 6. After five consecutive reviewed nights, the last two on the frozen
>    release, pin the manifest, build the summary with
>    `--producer-receipts`, pin the summary, and run the readiness check.

Suggested addition to "Validate before reopening traffic": each dress
rehearsal record must carry `busiest_p95_us`, `stress`, `mysql_kills`, `data`
and `way_back` as described in the
[readiness README](../../scripts/cutover/readiness/README.md#required-input).

## The 12-minute budget

Measured import plus both forward verifications, durable full-row binary logs:
754.160 s (failed), 681.935 s, 593.230 s, 597.791 s, 700.066 s and 596.7 s on
the latest 328-table copy. Headroom on the latest run is 123 s (17%), smaller
than the spread between runs.

What the runner does about it:

- Stops before the import when the projection from the configured baseline is
  over budget.
- Gives the import a timeout equal to the budget and the PHP reader only the
  time left. A step that reaches the budget is killed and later stages are
  skipped, so the host is freed early.
- Runs finance after the reverse step, with the disposable MySQL stopped, so
  the two never overlap.
- Holds `runner.lock`, so two nights never overlap, and refuses to start while
  the deploy lock is held.
- Records every stage's time, `import_ms`, `verify_ms`, `projected_ms` and
  `headroom_ms` in `run-receipt.json`.

Tuning options from the area's risks, none of which weakens durability:

| Option | Why | Default |
| --- | --- | --- |
| `ionice_class: 2, ionice_level: 7` | The idle IO class can starve the import behind the live site and widen the spread. Best-effort level 7 still yields to the site. | idle class 3 (measured profile) |
| `innodb_buffer_pool_size` up to 16G | About 26 GiB was available in the last probe. The memory guard adds an 8 GiB margin. | 4G |
| `innodb_redo_log_capacity` up to 16G | Fewer checkpoint stalls during the bulk load. | 2G |
| `innodb_io_capacity`, `innodb_io_capacity_max` | Let background flushing keep up on NVMe. | server default |
| `innodb_flush_method: O_DIRECT` | Avoids double caching; commits still sync. | server default |
| `innodb_adaptive_hash_index: OFF` | Removes hash maintenance during a load. | server default |

`sync_binlog=1`, `innodb_flush_log_at_trx_commit=1`, doublewrite, full-row
binary logs and GTIDs are fixed and checked on the running server. Any other
option is refused. Change one option at a time and compare nights without
other jobs running.

## Owner decisions

1. **Finance step.** The finance rehearsal used an administrator sandbox
   (`systemd-run` system unit, private network). The committed config leaves
   `finance` unset, so no night can qualify until a reviewed finance command
   is pinned in the config. Decide who runs it and how.
2. **Reverse trigger helper.** The helper that installs the reviewed MySQL
   triggers before the export is not committed. The config leaves
   `reverse_triggers` unset. Decide whether to commit it or pin the reviewed
   copy on the host.
3. **Applicant files.** `applicant_files_root` is unset. Setting it lets the
   runner read applicant files on the live private disk, read-only.
4. **Snapshot retention.** `retain_snapshots` is unset, so nothing is ever
   deleted. Each night keeps about 4.5 GB. A number keeps only that many of the
   newest snapshots; deleting older copies on the server needs approval.
5. **Budget headroom (N6).** Keep the measured durable profile, try the tuning
   options above one at a time, or decide otherwise. `budget.fail_fast` stays on.
6. **Scheduling (N4).** The runner is ready for either a PC-started session or
   a host timer. Either way a reviewer must confirm each night.
7. **Dress profiles.** Approve the host profile, the proposed surge (twice the
   observed peak), the critical endpoint labels and the busiest-endpoint
   ranking method.
8. **Extra dress gates.** The checker keeps the 10% critical-endpoint p95 ratio
   and the write-improvement check on top of the plan's marks. Keep or drop them.

## Approvals needed before any server run

None of these were done in this lane.

- Copy the candidate checkout and the pinned PyMySQL wheel to the host, under
  `/home/maverick`.
- For each night (at least five, more if one fails): run
  `scripts/cutover/nightly/runner.py run --execute` as user `maverick`. It reads
  the live SQLite file through the online backup API (about 21 s), reads
  `deploy-meta.json` and the three `DB_*` keys, writes about 4.5 GB plus the
  verification files under the state directory, and runs a disposable
  socket-only MySQL 8.4.13 with a 4G buffer pool for about 10 to 16 minutes.
  Needs 45 GB free and 12 GiB available memory.
- Ranking the 50 busiest endpoints with `dress_profile.py busiest` on a private
  copy on the host.
- The final identity needs the installed target's server UUID (N7).

## Evidence

Tests run in this lane. Results are filled in below.

| Command | Result |
| --- | --- |
| `python -B -m unittest discover -s scripts/cutover/readiness -p 'test_*.py'` (Windows, Python 3.12.10) | 67 tests OK |
| Same, Linux container `repro-mysql-rehearsal-qa:20261122`, Python 3.11.2, network none | 67 tests OK |
| `python -B -m unittest discover -s scripts/cutover/nightly -p 'test_*.py'` (Windows) | 52 tests OK, 7 Linux-only skipped |
| Same, Linux container as uid 1000, network none | 52 tests OK, none skipped |
| `python -B -m unittest discover -s scripts/cutover -p 'test_*.py'` (Windows) | 41 tests OK, 4 skipped |
| `phpunit -c phpunit.mysql.xml tests/MySql/ConnectionIdentityTest.php tests/MySql/DatabaseBackupTest.php` on MySQL 8.4.13, network none | Identity test passed. Two backup tests fail at the "configuration" stage; the same two fail on `a6e799bb` with the same output (the local image has no `mysqldump`). No PHP file differs from the base. |
| Full SQLite suite `vendor/bin/phpunit` | See the lane report. |

Mutation checks on the runner: dropping `--sync-binlog=1`, the import timeout,
the MySQL stop before finance, the deploy lock check, the evidence check or the
pre-capture date check each made at least one test fail.

## Left to do

- Run five approved nights on the host and review them (N3, N4, N5).
- Commit or pin the reverse trigger helper and the finance step (owner
  decisions 1 and 2). Until then the runner stops at `reverse` without the
  helper, and nights cannot qualify without finance.
- Rank the 50 busiest endpoints on a private copy, fill the workload, re-pin
  the policy (D1 follow-up).
- Set the identity once the frozen release and target UUID exist (N7), then
  pin the manifest and summary.
- The dress rehearsals themselves (D2, D3, D4).
