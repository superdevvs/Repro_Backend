# Writer hold lane (H1 to H7)

Branch `claude/writer-hold-20261125`, based on `a6e799bb` (draft PR 46 head).
Local work only: nothing was pushed, deployed or run against production.
Production still uses SQLite. This lane closes the gap recorded in the
readiness doc: an external pre-close left the journal in `planned` with no
outage clock and no watchdog recovery.

Integrators: fold the "Runbook text" section into
`docs/mysql-production-cutover.md` ("Freeze all writers") and the "Readiness
text" section into `docs/mysql-production-readiness.md` (replacing the
paragraph that starts "The Runner currently takes the exclusive operator
lease").

## What changed

| Item | Change | Where |
| --- | --- | --- |
| H1 | Controllers (runner, watchdog tick, authority refresh) serialize on `controller.lock`. Writers share `operator.lock`. A controller takes the writer lock exclusively only to drain writers, by polling within a bounded time, and keeps it to the end of its lease. A tick waits up to 50 s for another controller. | `runner.py`, `fence/guard.py` |
| H2 | New approved `hold` action: the first journaled step. It writes `holding`, `started_at` and a pending hold (hold ID, boot ID) before any host effect, then closes admissions. `freeze` starts from `holding`, stops workers, drains every admitted writer (at most 10 min, configurable), then holds the scheduler and seals SQLite. An interrupted hold can be retried. Rollback works from `holding`. | `runner.py`, `rehearse.py` |
| H3 | `tick` in a held phase re-closes admissions after a crash or reboot and re-drains the scheduler under the new boot; at 45 min from the hold it rolls back. Production CLI (`hold`, `freeze`, `tick`, `abort`, `refresh`, `reopen`, ...), host adapter and a root systemd watchdog service and 1-minute timer. | `production/cli.py`, `production/adapter.py`, `production/systemd/` |
| H4 | The test that pinned the gap is replaced by `test_external_pre_close_is_refused_or_adopted_with_clock`. New runner, guard, refresh and Linux process tests (list below). | `test_runner.py`, `fence/test_*.py`, `test_process_contract.py` |
| H5 | Host fixture holds first, with an admitted slow writer in flight, server-level nginx hold from the production include, direct `.php` webhook routes, and a recovery scenario that kills the runner mid-drain, restarts nginx and FPM and lets the watchdog roll back. | `host/rehearse.py`, `host/prewrite-fastcgi.py`, `host/probe.php`, `host/run-local.ps1` |
| H6 | Root admission wrapper for Supervisor, cron and systemd writers; media-archives drop-in; Supervisor and sudoers templates; cron rewriter; nginx server-level include; deploy-fence publisher under both deploy locks; SQLite seal. | `production/` |
| H7 | Runbook and README text (below and in the READMEs). | this file, `scripts/cutover/**/README.md` |

## Runbook text: freeze all writers (hold first)

Maintenance mode alone is not a writer freeze. The approved hold replaces the
old order (drain, then pause, then stop, with no journal).

**Before the hold, no outage.**

1. 7:45 pm: pause Studio with its documented pause file; let its job finish.
2. 9:20 pm: pause the long-job queues with `queue:pause`, run as
   `www-data` (`sudo /usr/sbin/runuser -u www-data -- /usr/bin/php
   /var/www/backend/artisan queue:pause <connection:queue>`):
   `studio:studio`, `database:media-archives`, `database:default`,
   `database:integrations`, `database:watermarks`. The flags are files in
   the `file` cache under `www-data` 755 folders (measured 25 November),
   which the deploy user cannot write and root would leave root-owned. Wait
   for their running jobs, then stop those workers. The hold refuses while
   a long-job worker still runs (`require_long_job_predrain`, on by default).
3. 9:50 pm: preflight as before (no call in progress, Aryeo recorded and
   paused, no upload in flight).

**The hold: the outage clock starts here.**

4. Approval 1, "start freeze". The operator writes the approval from
   `cli.py approval-template --phase hold` to a root-owned 0600 file and runs
   `cli.py hold --approval FILE`. The journal records phase `holding`,
   `started_at` and the pending hold before anything changes. Then:
   - the nginx open marker `/run/repro-cutover-ingress/open` is removed, so
     both vhosts answer 503 with `Retry-After: 30` at server level, including
     `/cubicasa_webhook.php` and `/iguide_webhook.php`;
   - `/etc/repro-database-cutover/active` is published under both deploy
     locks, so no deploy is between its check and its changes;
   - all nine queues are paused;
   - the guard refuses every new admission (web, queue, scheduler, deploy).
   Writers admitted earlier keep running and keep their shared lease.

**Drain and freeze.**

5. `cli.py freeze` stops the worker groups, drains PHP-FPM, waits until no
   writer process remains and every admitted lease is released (at most 10
   minutes), waits for running scheduled commands, then seals SQLite:
   checkpoint, empty WAL, no write bits. If the drain does not finish, the
   run stays `holding` with admissions closed. Use `cli.py abort`, or let the
   watchdog roll back.
6. Data steps as in the cutover runbook. This lane's adapter refuses
   `prepare` and `promote`; it covers the hold, the freeze and the pre-write
   rollback only.

**Watchdog.** `repro-cutover-watchdog.timer` runs `cli.py tick` every minute
from boot (`OnCalendar=minutely`). In a held phase it closes admissions again
if a reboot or a restart reopened them, and re-drains the scheduler under the
new boot. At 45 minutes from the hold it rolls back to SQLite. All deadlines
count from the hold, so the drain uses the same 60-minute outage budget. The
service allows a tick 25 minutes, enough for the controller-lock wait and a
whole rollback; while a tick runs, an operator command is refused with
"Another host operator holds the cutover lock" and can be retried.

**Undo.** `cli.py abort` (or the watchdog) re-closes admissions, drains,
unseals SQLite and publishes boot-bound SQLite authority. `cli.py reopen`
verifies that authority through the guard, checks the live host again
(SQLite the only writable engine), resumes the queues, starts the workers,
recreates the open marker, writes the activation receipt and lifts the
deploy fence. If its check after opening fails, it closes ingress, pauses
the queues and stops the workers again. After any reboot, run
`cli.py refresh` first, then `cli.py reopen`; `tmpfiles.d` recreates the
`/run` directories at boot. `refresh` refuses while the open marker exists,
because the guard revokes the current proof before it measures the host; it
checks that and publishes the deploy fence inside the guard's locks. Deploys
stay held by the marker from `init` until `reopen` lifts it after a
completed rollback. The watchdog keeps the completed SQLite rollback as it
is after such a reboot; it does not escalate to recovery.

## Readiness text

**Writer hold: implemented and tested locally, not installed.** The
approved hold is now the first journaled step and starts the outage clock.
A second lock lets the runner journal it while earlier writers still hold
their shared lease; the freeze then waits for them. The watchdog closes
admissions again after a crash or reboot, before and after the handoff from
`holding` to `frozen`, and rolls back to SQLite 45 minutes after the hold.
An admission closed outside the journal is refused, not silently ignored.
Local evidence: runner, guard and refresh tests, Linux process tests with a
real already-admitted writer and a runner killed with SIGKILL, and the
private nginx/PHP-FPM fixture (results below). Production installation (W1)
and a production or staging drill with real services and a reboot (W2)
remain, each with its own approval. Keep production on SQLite.

## Evidence

All runs were local and offline. Linux tests ran as root in a disposable
`repro-mysql-rehearsal-qa:20261122` container with `--network none`.

| Suite | Command | Result |
| --- | --- | --- |
| Runner state machine (Windows) | `python -B -m unittest discover -s scripts/cutover -p test_runner.py` | 42 passed; 43 after the review |
| Guard (Windows) | `python -B -m unittest discover -s scripts/cutover/fence -p test_guard.py` | 20 passed, 3 Linux-only skipped |
| All Linux suites | `scripts/cutover/run-linux-tests.sh` in the container | runner 42, guard 20, process contract 6, admission wrapper 6, fences 7, adapter and CLI 14, root-only refresh 10; all passed, none skipped |
| All Linux suites, after the review (`51db2739`) | same | runner 43, guard 20, process contract 6, admission wrapper 6, fences 7, adapter and CLI 20, nginx include 1, root-only refresh 10; all passed, none skipped |
| Full SQLite PHPUnit suite (`git archive` of `6aebf499`, extracted in the container, shared read-only vendor volume, `php -d memory_limit=1536M`) | `vendor/bin/phpunit --no-progress` | 9,589 tests: 9,582 passed, 3 skipped, 2 errors and 2 failures. The same four fail identically on the base `a6e799bb` in the same setup (privilege-drop tests that cannot read the root-owned vendor volume as uid 33), so they are pre-existing. This lane changes no PHP application, test or config file, so the MySQL profile has no touched area. The default 512 MB PHP limit ends the whole run with a fatal in a GD image test; the bind-mounted run was stopped after 95 minutes of slow 9p file access. |
| Full SQLite PHPUnit suite, review run (`git archive` of `695dcbef`, extracted in the container, shared read-only vendor volume, `php -d memory_limit=1536M`; later commits change only `scripts/cutover` Python and docs) | `vendor/bin/phpunit --no-progress` | 9,589 tests in 85 min: 2 errors and 2 failures, 3 skipped. The same four tests (`ListingStudioPaidStorageTest` and `ListingStudioPrivateStorageTest` `test_fpm_upload_and_separate_worker_can_share_private_files`, `WorkspacePhotoLogoPermissionsTest::test_php_can_upload_a_logo_when_worker_outputs_are_not_group_writable`, `IguideLabImportTest::test_concurrent_retry_cannot_enter_publication_window_or_delete_canonical_chunk`) fail the same way on `a6e799bb` in the same setup, so they are pre-existing. |
| All Linux suites, after the Codex round (`e2513923`) | same | runner 43, guard 20, process contract 6, admission wrapper 6, fences 10, adapter and CLI 22, nginx include 1, host replay 3, root-only refresh 10; all passed, none skipped |
| All Linux suites, after Codex round 2 (`adabedee`) | same | runner 43, guard 20, process contract 6, admission wrapper 6, fences 12, adapter and CLI 30, nginx include 1, host replay 3, root-only refresh 10; launcher exit 0 |
| Boot directories under systemd 252 | `systemd-tmpfiles --create --root=<tmp>` on `tmpfiles.d/repro-cutover-fence.conf` | exit 0; both `/run` directories created root-owned 0755 |
| Watchdog units under systemd 252 (Debian 12, offline in the same image) | `systemd-analyze verify` on both units; `calendar minutely`; `timespan 25min` | verify exit 0; `minutely` = `*-*-* *:*:00`; 25min = 1,500 s. Timer firing after a skipped condition still needs a real systemd (W2). |
| Production nginx include under nginx 1.22.1 | `test_nginx_hold.py` (also in the launcher) | passed: six routes on both vhosts, including the direct `.php` files, return 503 with `Retry-After: 30` while the marker is absent, pass through once it exists, and close again as soon as it is removed, with no reload |

New tests that prove the hold. They were written before the code; the
runner, refresh, wrapper, fence and adapter tests were run and seen failing
before their implementation. On the base commit `hold` does not exist, so
none of them can pass there.

- `test_runner.py`: `test_hold_is_first_journaled_step_and_starts_outage_clock`,
  `test_external_pre_close_is_refused_or_adopted_with_clock`,
  `test_crash_after_hold_intent_before_close_rolls_back_on_tick`,
  `test_crash_during_drain_after_hold_rolls_back_at_45_minutes_from_hold`,
  `test_freeze_deadline_counts_from_hold_not_freeze`,
  `test_reboot_during_hold_reasserts_hold_and_redrains_scheduler`,
  `test_crash_after_journal_handoff_from_holding_to_frozen`,
  `test_reboot_after_handoff_to_frozen_redrains_scheduler_before_prepare`,
  `test_hold_retry_is_idempotent`, `test_hold_approval_binds_challenge_and_expires`,
  `test_separate_freeze_approval_binds_the_hold`,
  `test_abort_from_holding_reopens_only_sqlite`,
  `test_drain_timeout_keeps_admissions_closed`,
  `test_long_job_queues_must_be_predrained_before_the_hold`, and four lock
  tests (`test_admitted_writer_does_not_block_a_planned_watchdog_tick`,
  `test_second_controller_is_refused_while_writers_stay_admitted`,
  `test_drain_writers_waits_for_admitted_writer_then_excludes_new_ones`,
  `test_watchdog_tick_waits_for_the_controller_lock`).
- `test_process_contract.py` (real processes and flock):
  `test_already_admitted_wrapper_drains_after_hold_and_new_admissions_refused`,
  `test_runner_killed_mid_drain_is_recovered_by_the_watchdog` (SIGKILL),
  `test_watchdog_tick_waits_for_controller_lock`,
  `test_drain_under_refused_admission_load`.
- `fence/test_guard.py`: `test_holding_and_pending_hold_refuse_every_admission`;
  `fence/test_refresh.py`: `test_holding_journal_revokes_old_evidence_without_probing`,
  `test_running_controller_blocks_refresh_and_leaves_evidence_untouched`
  (confirmed failing against the base guard).
- `production/test_admit.py`: profile user, group, umask and directory; held
  journal runs nothing (exit 75); shell, PHP `exec` and `proc_open`
  background jobs keep the lease after the command exits; SIGTERM forwarding;
  timeout.
- `production/test_fences.py`: the deploy marker waits for a deploy inside
  either lock and then the exact Deploy-App lines exit 95; the SQLite seal
  refuses owner writes and restores modes, including companions created
  during the seal; cron rewriting keeps schedule, output and markers and
  refuses unknown application lines.
- `production/test_adapter.py`: hold, long-job refusal, freeze with worker
  and scheduler drain, a writer that never stops (hold kept, watchdog rolls
  back), abort and ordered reopen, reboot during hold, reboot in `planned`
  then `refresh` and `reopen`, CLI flows, unit files and example profiles.
  Added in review: reboot after a reopened rollback (tick, `refresh`,
  `reopen`), `refresh` on an open host keeps the proof, the CLI refuses marker
  paths that nginx and Deploy-App do not read, Supervisor restarts a worker
  that exits 0, and every installed user Supervisor program has a profile.

Writer starvation under the polling exclusive lock, measured in
`test_drain_under_refused_admission_load` on 2 CPUs: with 1, 4 and 8
processes looping refused admission checks (about 4,500 to 10,500 refused
checks per round), the drain got the writer lock in 0 to 1 ms in every run.

**Host fixture runs (H5): not yet executed.** The code is committed and
checked (`py_compile`, `php -l`, Pint), but `run-local.ps1` refused to start
twice by its own device guard: Windows had 0.5 to 1.9 GB free while other
lanes were running, and the guard requires 2.3 GB. In the Codex round
Windows had 1.5 GB free, below the 3 GB set for this run, so it was not
attempted. The recovery scenario's replay defect (Codex #6) is fixed and
unit-tested without the fixture. Run both scenarios when
the machine has headroom, from this branch, with fresh evidence folders:

```powershell
$params = @{
    LockedVendorArchive = '<lock-matched dev vendor .tar.gz>'
    PinnedPyMySqlWheel = 'C:/Users/shubh/.codex/mysql-rehearsal-runtime-20261122/pymysql-1.1.2-py3-none-any.whl'
    ResourcePrefix = 'writer-hold-dress'
}
./scripts/cutover/host/run-local.ps1 @params -Scenario prewrite-fastcgi -EvidenceDirectory <fresh-1>
./scripts/cutover/host/run-local.ps1 @params -Scenario prewrite-fastcgi-recovery -EvidenceDirectory <fresh-2>
```

Expected report fields: `hold_to_drained_ms`, `admitted_writer_in_flight_at_hold`,
`slow_write_effects_in_snapshot: 1`, the per-host and per-layer 503 matrix
including `cubicasa_direct` and `iguide_direct`, and for the recovery run
`runner_killed_mid_drain`, `restarts_while_held`, `watchdog_rollback:
sqlite_authoritative` and `hold_to_watchdog_rollback_ms`.

## Owner decisions

The code implements the safe default and makes each a config value.

| Decision | Default | Config |
| --- | --- | --- |
| O1: one approval for hold and freeze, or separate approvals | One approval ("start freeze") covers both | `separate_freeze_approval` |
| O1: an admission closed outside the journal | Refused loudly; never adopted | `adopt_external_hold` |
| O1: who signs approvals | Root-owned 0600 approval file written by the operator after the owner's typed approval; no cryptographic signature | none yet |
| O2: long workers versus the outage clock | Plan default: long-job queues paused and drained before the hold (9:20 pm) | `require_long_job_predrain`, `max_drain_seconds` (600) |
| Reopen after a reboot | Manual `refresh` then `reopen`; nothing reopens at boot | none (manual) |
| Smoke-test bypass of the nginx hold | None; the map default stays 1 | `repro-cutover-gate.conf` |

## What is left

- W1 (needs approval per action): install guard, controller, wrappers,
  watchdog units, the `tmpfiles.d` entry, nginx include, sudoers, Supervisor
  and cron changes on the host; then observe 24 to 48 hours in `planned`
  (C1), with deploys held by the marker.
- W2 (needs a window): drill with real services, an in-flight request and
  job, restarts and a reboot.
- The production identity and MySQL probes and an independent live
  configuration probe are host commands still to be written; `refresh` and
  the authority proof copy the SQLite live-configuration pin from the policy.
- The activation receipt sets `application_verified` without an application
  check; add a smoke command before W2.
- The Supervisor template must be reconciled with the live
  `laravel-worker.conf` and the user `supervisord.conf` before W1.
- Install order for W1: the nginx include holds the site until the open
  marker exists, and `cli.py init` needs a live SQLite route. Create the
  marker by hand, run `init`, remove the marker, then `refresh` and `reopen`.
- If the optional `repro-lab-tour` program is installed, add its queue as
  `iguide-lab-tour:iguide-lab-tour` (connection `iguide-lab-tour`, queue
  `iguide-lab-tour`) to the host config before `init` and to the 9:20 pm
  pre-hold pause (`queue:pause iguide-lab-tour:iguide-lab-tour`, as
  `www-data` like the other pauses; the bare name would pause
  `database:iguide-lab-tour`). Its jobs can run 600 s, the whole default
  drain limit. The host-config check at `4e4a262d` (`[a-z]+:[a-z0-9-]+`)
  refuses that name; the code-fix lane (`71f7db04`) accepts it.
- `queue_pause` and `queue_resume` in the host config must run as
  `www-data` through `/usr/sbin/runuser -u www-data --` (runbook, Release
  and target preparation step 10, and MySQL adapter lane item 17).

## Risks

- Linux flock has no writer preference. The drain polls every 10 ms.
  Measured in the fixture with 1, 4 and 8 processes looping refused checks
  (about 5,000 to 10,000 refused checks per round, 2 CPUs): the drain got the
  lock in 0 to 1 ms every time. During a real hold, nginx answers 503 before
  PHP, so refused checks come only from restarting wrappers and cron.
- Installing the wrappers changes how live workers start even in `planned`.
  A guard fault, or a reboot without `refresh`, holds every writer and the
  site (fail closed). It needs on-call cover.
- Root ignores file modes, so the SQLite seal does not stop root-run tools.
  The seal refuses while any other process holds the database files open
  for writing, and it needs root's `/proc` access to every process
  (`CAP_SYS_PTRACE`, present for a root systemd service). Without it, or
  with a stray idle `sqlite3` or tinker session, the freeze is refused and
  the hold stays until `abort` or the watchdog rollback.
- From `cli.py init`, the deploy marker holds every deploy for the whole
  planned observation (C1) and the cutover. Only `reopen` after a completed
  rollback lifts it. This matches the guard's planned-phase policy, because
  the policy pins the release.
- `direct-webhook` evidence: behind a stale open marker, the PHP gate holds
  the write but the file has already answered 200, so the event is lost.
  Only the nginx server-level hold protects those two files.
- After an aborted and reopened cutover, the policy still pins the old
  release. A later deploy changes the observed release, so the next reboot's
  `refresh` refuses and every writer stays held. Retire the installed fences
  (or start a new run) before the next deploy.
- A watchdog tick can now run up to 25 minutes (it was stopped after 55 s,
  which could stop a rollback from ever finishing). During a long tick, run
  operator commands again once it ends; `systemctl status
  repro-cutover-watchdog.service` shows it.

## Independent review (25 November)

A second session reviewed the branch diff against H1 to H7 and fixed these
defects test-first, as new commits on this branch.

| Defect | Effect before the fix | Fix and test |
| --- | --- | --- |
| After abort and `reopen`, a reboot closes ingress again (empty `/run`) while the deploy fence stays lifted. | The watchdog tick saw neither a sealed nor a live SQLite route and moved the journal to `recovery_required` with `writes_possible` set: every writer held until manual recovery. `refresh` also refused. | The runner accepts a held SQLite-only route for a completed SQLite rollback; production `refresh` publishes the deploy fence again before it measures. `test_reboot_after_reopened_rollback_keeps_sqlite_authority_for_the_watchdog`, `test_reboot_after_reopened_rollback_ticks_refreshes_and_reopens`. |
| `cli.py refresh` on a live host. | The guard revoked the live proof first, then refused: the scheduler and every restarted worker were held. | `refresh` refuses while the open marker exists. `test_refresh_on_an_open_host_keeps_the_current_proof`. |
| The CLI did not pin the nginx open marker and the deploy marker. | A host config naming other files made the hold remove or publish files nobody reads while the journal recorded admissions closed: fail open. | The CLI refuses any other path. `test_cli_refuses_marker_paths_that_nginx_and_deploys_do_not_read`, `test_cli_marker_paths_are_the_ones_nginx_and_deploys_read`. |
| Supervisor template had `exitcodes=0,75` with `autorestart=unexpected`. | `queue:restart` (every deploy) and `--max-time` end `queue:work` with 0, so wrapped workers would have stayed down. | `exitcodes=75`. `test_supervisor_restarts_a_worker_that_exits_normally`. |
| `install-studio-runtime.sh` can add `repro-lab-tour`, a queue writer with no profile. | It would have run unfenced. | `lab-tour` profile and sudoers entry. `test_every_installed_user_supervisor_program_has_a_wrapper_profile`. |

Open notes, not changed:

- The same reboot escalation exists for a completed MySQL promotion
  (`mysql_authoritative` with ingress closed). This lane's adapter refuses
  promotion, so it is not reachable here; the MySQL adapter lane must handle
  it.
- Resolved in the Codex round: the timer now uses `OnCalendar=minutely`,
  and `crontab.py` refuses lines that chain a command after the match.
- H5 (host fixture runs) is still not executed; see above.

## Codex review round (25 Nov)

Codex reviewed `6aebf499`. Each finding was rechecked at `490f9c77`; fixes
are test-first commits on this branch. Every red test was run and seen to
fail for the stated reason before its fix.

- writer-hold#1 (P1, watchdog killed after 55 s): fixed in `011a67ea`. `TimeoutStartSec=25min`, at least the 50 s lock wait plus the 15-minute rollback budget plus one 300 s command; `test_watchdog_service_outlasts_the_controller_wait_and_a_full_rollback` (red: 55 < 1250).
- writer-hold#2 (P1, reboot after a reopened rollback): already fixed in `4c38253e` (`runner.py:622-626`, `adapter.py:350-354`). The current `test_reboot_after_reopened_rollback_keeps_sqlite_authority_for_the_watchdog` fails against the `6aebf499` runner ("SQLite authority could not be verified") and passes now, as does `test_reboot_after_reopened_rollback_ticks_refreshes_and_reopens`.
- writer-hold#3 (P1, Supervisor exit 0): already fixed in `425e9e2d` (`exitcodes=75` with `autorestart=unexpected`); `test_supervisor_restarts_a_worker_that_exits_normally`.
- writer-hold#4 (P2, reopen retry blocked by started workers): fixed in `10a262fb`. A retried reopen stops running worker groups, then drains; `test_reopen_retry_stops_workers_that_a_failed_reopen_started` (red: "Admitted writers did not drain").
- writer-hold#5 (P2, seal record rename not durable): fixed in `9abbd875`. Directory fsync after the rename and before the chmod, and unseal syncs restored modes before removing the record; `test_seal_record_is_durable_before_write_bits_are_removed`, `test_unseal_restored_modes_are_durable_before_the_record_is_removed`.
- writer-hold#6 (P2, recovery replay compared with None): fixed in `521fcaae`. `mysql_baseline()` is used by `observe()` and `replay_routes()`; `host/test_prewrite_fastcgi.py` (red: "reached the sealed MySQL target").
- reviewer#1 (H5 fixture runs): not run. 1.5 GB free, below the 3 GB set for this run. Still open for a machine with headroom.
- reviewer#2 (`mysql_authoritative` reboot escalation): left for the MySQL adapter lane. This adapter refuses promotion, so the state cannot be reached here.
- reviewer#3 (`OnUnitActiveSec` after a skipped condition): fixed in `011a67ea` with `OnCalendar=minutely`. `systemd-analyze verify` passes under systemd 252; a real timer still needs W2.
- reviewer#4 (55 s timeout nit): fixed with writer-hold#1 in `011a67ea`.
- reviewer#5 (old release pinned after an aborted cutover): owner decision. Before the next deploy, either retire the installed fences or start a new run with a new policy. See Risks.
- reviewer#6 (`crontab.py` dropped chained commands): fixed in `e2513923`. Such lines are now refused; `test_commands_chained_after_a_matched_entry_are_refused_not_dropped`.
- reviewer#7 (no real systemd, Supervisor, crontab or visudo): needs W1 and W2, each with owner approval. None can be tested offline here.

### Round 2

Codex reviewed `68f90752` (whole branch against `a6e799bb`). Every fix below
has a test that was run and seen failing for the stated reason first.

- r2#1 (P1, reopen opened before checking the live host): fixed in `d0eb91c2`. Live check before any writer is let in, and ingress, queues and workers closed again if the check after opening fails; `test_reopen_refuses_when_mysql_became_writable_after_the_proof`, `test_reopen_closes_ingress_again_when_the_live_check_fails_after_opening` (red: ingress left open).
- r2#2 (P1, idle SQLite handle survives the seal): fixed in `adabedee`. The seal refuses while another process holds the files open for writing, before and after the chmod, and refuses if `/proc` cannot be read; `test_seal_refuses_while_an_idle_connection_keeps_the_database_open` (red: the uid-33 session wrote after the seal), `test_seal_finds_writable_descriptors_and_ignores_read_only_ones`.
- r2#3 (P1, `/run` directories gone after a reboot): fixed in `cca8c823`. `tmpfiles.d/repro-cutover-fence.conf`, and the controller recreates a missing runtime directory; `test_refresh_and_reopen_recreate_the_runtime_directories_after_a_reboot`, `test_watchdog_rolls_back_after_a_reboot_removed_the_runtime_directories` (red: refresh refused, rollback crashed), `test_every_boot_recreates_the_runtime_directories_of_the_guard_and_nginx`.
- r2#4 (P1, planned deploys reported held but not fenced): fixed in `02e17ad8`. `init` and `refresh` publish the marker Deploy-App checks; the probe counts only the marker; `test_planned_journal_holds_deploys_with_the_marker_deploy_app_checks` (red: init left deploys open).
- r2#5 (P2, refresh raced a reopen): fixed in `c065ab14`. The guard's refresh takes a precondition run inside both locks before any revocation; `test_refresh_racing_a_reopen_neither_fences_deploys_nor_revokes_the_proof` (red: fence republished on the reopened host), `test_reopen_racing_inside_the_refresh_locks_is_refused`.
- writer-hold#2 recheck (already fixed): holds. After the reboot the tick returns `sqlite_authoritative` without writing, so the refreshed proof still matches the journal; `test_reboot_after_reopened_rollback_ticks_refreshes_and_reopens` passes with the round-2 changes.
- writer-hold#3 recheck (already fixed): holds. `queue:work` exits 0 (restart, `--max-time`), 12 (memory) or 1 (timeout), never 75. A held wrapper that exits 75 within `startsecs=5` goes BACKOFF then FATAL and stays down, and `reopen` starts it again.
- reviewer#2 recheck (documented): holds. `prepare`, `evidence` and `seal_mysql` still refuse, so this adapter cannot reach `mysql_authoritative`.

### Integration check (25 Nov)

Running CI's Python checks on the merged release candidate with 2 CPUs (the
size of a GitHub runner for a private repository),
`test_drain_under_refused_admission_load` failed in two of three runs: the
drain waited up to 3.2 s behind 4 or 8 refused checkers, against its 1 s mark.
A refused check took the shared writer lock before reading the records, and
`flock` gives a waiting exclusive lock no preference.

Fixed test-first: `guard.admitted` now reads the records once before the lock
and refuses at once when they already refuse. Refusing is always safe; only
the read under the shared lock can admit, so admission is unchanged. A read
error before the lock is settled under the lock as before.
`test_held_admission_is_refused_without_waiting_for_the_writer_lock` (red:
"admission lock held" instead of the hold) and the starvation test (five runs,
0 ms drain) pass. The two publication-crash tests now expect the
unfinished-action refusal and check directly that the publishing controller
still holds the writer lock. `run-linux-tests.sh` passes in full.

### Round 3

Codex reviewed `68f90752..273aa827`; the finding was rechecked at `66b8b88f`.

- r3#1 (P2, initial deploy fence not recoverable): real. `init` committed the
  `planned` journal and released the controller lock before publishing the
  fence. A deploy holding its lock past `command_seconds` then left an unfenced
  planned run: a retried `init` refused ("A run already exists"), a planned
  tick does nothing, and `refresh` refuses while ingress is open. Fixed in the
  commit that adds this entry: `Runner.initialize` publishes the fence under
  the controller lock before the journal commit, so a failed publication leaves
  no run and `init` can be retried. A committed run is still never
  initialized again. If the journal write itself fails after the fence is
  published, deploys stay held with no run until `init` is retried (fail
  closed). r2#4 and r2#5 are unchanged.
  `test_init_refused_by_a_busy_deploy_lock_is_retried_until_deploys_are_fenced`
  (red: "Cutover controller refused: A run already exists in this directory").
  `run-linux-tests.sh` passes in full (adapter and CLI 31); CI discovery of
  `scripts/cutover`, `fence` and `readiness` passes.
