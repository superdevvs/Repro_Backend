# Production cutover controller, writer wrappers and fences

Built and tested locally only. Nothing here is installed on the production
host. Installing any of it is a production change that needs the owner's
approval for each action (handoff item W1).

## Layout on the host

| Repository file | Installed as | Purpose |
| --- | --- | --- |
| `../fence/guard.py` | `/opt/repro-cutover-fence/guard.py` | Admission check (fixed path) |
| `../runner.py` | `/opt/repro-cutover-fence/runner.py` | Journal and state machine |
| `cli.py`, `adapter.py`, `admit.py`, `deploy_marker.py`, `sqlite_seal.py` | `/opt/repro-cutover-fence/production/` | Controller, host adapter, writer wrapper, fences |
| `wrappers.example.json` | `/etc/repro-cutover-fence/wrappers.json` | Writer profiles: user, command, action, engine |
| host config (see below) | `/etc/repro-cutover-fence/host.json` | Paths, queues, host commands, owner options |
| `systemd/repro-cutover-watchdog.{service,timer}` | `/etc/systemd/system/` | Watchdog tick every minute from boot |
| `tmpfiles.d/repro-cutover-fence.conf` | `/etc/tmpfiles.d/` | Recreates `/run/repro-cutover-fence` and `/run/repro-cutover-ingress` (root, 0755) at every boot |
| `systemd/repro-media-archives.service.d/cutover-fence.conf` | `/etc/systemd/system/repro-media-archives.service.d/` | Media worker through the wrapper |
| `supervisor/laravel-worker.conf.example` | compare with `/etc/supervisor/conf.d/laravel-worker.conf` | System worker group through the wrapper |
| `sudoers/repro-cutover-admit` | `/etc/sudoers.d/repro-cutover-admit` | Deploy user's Supervisor and cron reach the wrapper only |
| `nginx/repro-cutover-gate.conf` | `/etc/nginx/conf.d/` | http-level map |
| `nginx/repro-cutover-hold.conf` | included at the top of `frontend.conf` and `laravel.conf` server blocks | Server-level 503 unless `/run/repro-cutover-ingress/open` exists |
| `crontab.py`, `cron-rules.example.json` | run once by the operator | Rewrites application cron lines to the wrapper |

All files and every parent directory must be owned by root and not writable
by group or others. Python runs as `/usr/bin/python3 -I -S`.

## Writers

Every writer starts through `admit.py <profile>`. The wrapper verifies the
cutover journal under the shared writer lock and runs the profile's fixed
command as its user. The command and all its children, including detached
background jobs from `runInBackground`, inherit the lock, so the cutover drain
waits for them. A held admission exits 75 and runs nothing. Supervisor treats
75 as the only expected exit, so a held worker is not restarted in a loop; the
cutover `reopen` starts workers again. Exit 0 stays unexpected: `queue:restart`
on every deploy and `--max-time` end `queue:work` with 0, and the worker must
start again through the wrapper.

| Writer | Fence |
| --- | --- |
| Both vhosts (same-origin `/api`, legacy API host, direct `.php` webhooks) | nginx server-level include; 503 with `Retry-After` unless the open marker exists |
| System Supervisor `laravel-worker` (3) | `admit.py laravel-worker`, Supervisor as root |
| User Supervisor: studio, lab-tour (when installed), voice-realtime, voice-notifications, voice-transcripts | `sudo -n ... admit.py <profile>` |
| `repro-media-archives.service` | systemd drop-in `ExecStart=+... admit.py media-archives` |
| Laravel scheduler (cron `schedule:run`) | `admit.py scheduler`; background commands keep the lease |
| Other application cron lines | `crontab.py` rewrites known lines; unknown ones are refused |
| Deployments | `/etc/repro-database-cutover/active`, published under both deploy locks from `init` (Deploy-App checks only this file, never the guard) until reopening a completed rollback lifts it |
| Manual `sqlite3` or tinker as the deploy or web user | SQLite seal: no write bits on the database files during the freeze. Write bits stop new opens only, so the seal first refuses while any other process holds the database, `-wal` or `-shm` open for writing (an idle session opened earlier), and checks again after the modes change. It needs root's `/proc` access to every process (`CAP_SYS_PTRACE`); an unreadable process also refuses |

Root bypasses file modes. Root-run tools are fenced by the runbook only.

## Host config

`host.json` holds `paths` (journal, policy, evidence, activation, machine and
boot IDs, ingress open marker, deploy marker, scheduler hold, seal record),
`sqlite`, `deploy_locks` and `deploy_owner`, the nine `queues`, host
`commands` (identity probe, MySQL probe, queue pause and resume prefixes, stop
and start worker commands, FPM drain), process patterns (`writer_processes`,
`long_job_processes`, `scheduler_processes`), `command_seconds` and the owner
`options`. `test_adapter.py` builds a complete example. The CLI refuses a
config whose guard paths differ from the installed guard's, or whose
`ingress_open` and `deploy_marker` are not `/run/repro-cutover-ingress/open`
(read by the nginx include) and `/etc/repro-database-cutover/active` (read by
Deploy-App); another file would close nothing and the hold would fail open. The identity probe
must observe the deployed release, artifact, source and configuration digests
independently of the pinned policy. The MySQL probe must report
`writable` and `business_activity` for the target.

## Owner options (defaults are the safe choice)

| Option | Default | Meaning |
| --- | --- | --- |
| `separate_freeze_approval` | `false` | One approval ("start freeze") covers the hold and the freeze |
| `adopt_external_hold` | `false` | Admissions closed outside the journal are refused, not adopted |
| `require_long_job_predrain` | `true` | Long-job workers must be stopped before the hold (the 9:20 pm step) |
| `max_drain_seconds` | `600` | Longest wait for admitted writers after the hold |

## Commands

```text
cli.py init                                              # also publishes the deploy fence
cli.py approval-template --phase hold > approval.json   # root-owned, mode 0600
cli.py hold --approval approval.json                     # outage clock starts here
cli.py freeze
cli.py status | tick | abort
cli.py refresh                                           # after a reboot (planned or sqlite_authoritative)
cli.py reopen                                            # after abort, or after refresh
```

This adapter covers the SQLite hold, freeze and pre-write rollback only.
`prepare` and `promote` refuse, so it cannot open MySQL for writes.

**After every reboot.** `/run` is a fresh tmpfs, so every writer is held: there is
no authority proof for the new boot and no open marker. The runtime
directories themselves are gone too; `tmpfiles.d` recreates them at boot,
and the controller recreates them before it writes there. The operator runs
`cli.py refresh`, which measures the host and publishes current-boot evidence
for a committed `planned` or `sqlite_authoritative` journal through the
guard, then `cli.py reopen`. There is no automatic reopen at boot (owner
decision). `refresh` refuses while the open marker exists, because the guard
revokes the current proof before it measures the host. `refresh` publishes
the deploy fence before it measures and counts deploys as fenced only when
that marker exists. In `planned`, deploys stay held (the policy pins the
release) and reopen writes no activation receipt. After a reopened
rollback, `reopen` lifts the fence once activation is verified. `refresh` copies the SQLite live-configuration pin from the policy;
an independent configuration probe is still to be added before W1.

**Live check on `reopen`.** The authority proof was measured earlier, and
the guard does not look at the host again. `reopen` therefore observes the
host before it lets any writer in and refuses unless SQLite is the only
writable engine. If the check after opening fails, it removes the open
marker, pauses the queues and stops the workers again; the deploy fence
and the missing activation receipt keep deploys held.

**Retrying `reopen`.** If a reopen fails after it started a worker group,
those wrappers keep their shared writer lease. Run `cli.py reopen` again: it
stops any running worker group first (`stop_workers`), waits for the writer
lock, then resumes the queues and starts the workers in order.

**Watchdog run time.** A deadline tick runs the whole rollback, so the
service allows 25 minutes (`TimeoutStartSec`). While a tick runs, an
operator command is refused with "Another host operator holds the cutover
lock"; check `systemctl status repro-cutover-watchdog.service` and retry.

## Tests

`test_admit.py`, `test_fences.py` and `test_adapter.py` need Linux root and
run in a disposable container through `../run-linux-tests.sh`. The cron and
unit-file tests also run on Windows. Real systemd, Supervisor, nginx reloads
and a host reboot are not exercised here; they need the staging or production
drill (W2).
