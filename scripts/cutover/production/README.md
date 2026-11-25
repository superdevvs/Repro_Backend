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
| `identity.example.json` | `/etc/repro-cutover-fence/identity.json` (root 0644, with the run's policy) | The identity probe's inputs and the `.env` denylist |
| `systemd/repro-cutover-watchdog.{service,timer}` | `/etc/systemd/system/` | Watchdog tick every minute from boot |
| `tmpfiles.d/repro-cutover-fence.conf` | `/etc/tmpfiles.d/` | Recreates `/run/repro-cutover-fence` and `/run/repro-cutover-ingress` (root, 0755) at every boot |
| `systemd/repro-media-archives.service.d/cutover-fence.conf` | `/etc/systemd/system/repro-media-archives.service.d/` | Media worker through the wrapper |
| `supervisor/laravel-worker.conf.example` | compare with `/etc/supervisor/conf.d/laravel-worker.conf` | System worker group through the wrapper |
| `sudoers/repro-cutover-admit` | `/etc/sudoers.d/repro-cutover-admit` | Deploy user's Supervisor and cron reach the wrapper only |
| `sbin/repro-queue-flag` | `/usr/local/sbin/repro-queue-flag` (root 0755) | Deploy user's queue pause and resume as `www-data` (imports `adapter.py` from `/opt/repro-cutover-fence/production/`) |
| `sudoers/repro-queue-flag` | `/etc/sudoers.d/repro-queue-flag` (root 0440) | One line: the deploy user runs the queue flag helper |
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
`options`. `test_adapter.py` builds a complete example. Each queue is one
`connection:queue` argument for `queue:pause` and `queue:resume`: the nine are
`database:` mail, integrations, default, watermarks, voice-realtime,
voice-notifications, voice-transcripts and media-archives, plus
`studio:studio`. If the optional lab-tour worker is installed, add
`iguide-lab-tour:iguide-lab-tour` (connection and queue share the name) before
`cli.py init`, because `hold` refuses a host config that changed after `init`.
Lower case only; the connection starts with a letter. The CLI refuses a
config whose guard paths differ from the installed guard's, or whose
`ingress_open` and `deploy_marker` are not `/run/repro-cutover-ingress/open`
(read by the nginx include) and `/etc/repro-database-cutover/active` (read by
Deploy-App); another file would close nothing and the hold would fail open. The identity probe
must observe the deployed release, artifact, source and configuration digests
independently of the pinned policy. The MySQL probe must report
`writable` and `business_activity` for the target.

### Config contract for the MySQL lane (design 3.1)

`adapter.load_config(raw, read=...)` checks `host.json` and the
`identity.json` it names, and returns the host config unchanged. It fills
nothing in: every key below is required, no other key is accepted (at the top
level and in `mysql`, `fpm` and `smoke`), and anything else is refused with
`guard.Refused`. `read(path)` returns a file's bytes; `cli.py` passes
`guard.read_protected`, so `identity.json` gets the same root-only chain
check as `host.json`. `test_adapter.py` (`example_config`,
`example_identity`) holds the complete production example.

| Key | Rule |
| --- | --- |
| `app_root` | Absolute path; equal to `identity.json`'s `app_root` |
| `app_owner`, `app_group`, `web_user` | Account names, never `root` (`maverick`, `www-data`, `www-data`) |
| `sqlite` | Absolute; equal to `identity.json`'s `sqlite_path` |
| `mysql.mode` | `drill` or `cutover` |
| `mysql.database`, `mysql.control_database` | Two different schema names (`repro_dashboard`, `repro_mysql_control`) |
| `mysql.target_uuid` | The independently observed `@@server_uuid` |
| `mysql.datadir`, `client`, `socket`, `probe_defaults`, `gate_defaults`, `token_check_defaults` | Absolute paths; `datadir` equal to `identity.json`'s `mysql_datadir` |
| `mysql.gated_accounts`, `lockable_accounts` | Distinct `user@localhost` names, no account in both; at least one gated |
| `mysql.activity_excluded_tables`, `smoke_tables` | Distinct table names; at least one smoke table |
| `mysql.switch` | Exactly `DB_CONNECTION` (`mysql`), `DB_DATABASE` (= `mysql.database`), `DB_SOCKET` (= `mysql.socket`), `DB_USERNAME` (a gated account's user), `DB_COLLATION` and `REPRO_MYSQL_EXPECTED_SERVER_UUID` (= `mysql.target_uuid`), plus optionally `DB_EXPECTED_ENGINE` (`mysql`) and `SCHEMA_CACHE_RELEASE` once those branches are merged. Values are written unquoted into `.env`, so only `A-Z a-z 0-9 _ . , / : @ + -` |
| `mysql.switch_secrets` | Exactly `{"DB_PASSWORD": "<root-only file>"}`; the password never appears in `host.json` |
| `mysql.night_flags` | Distinct `.env` key names, none of them a switch key |
| `mysql.offsite_waiver` | `true` or `false`; `true` only in `drill` mode |
| `fpm` | `unit` (`*.service`), `socket`, `status_socket` (absolute), `drain_seconds` (1 to 300) |
| `smoke` | `client_id`, `shoot_id`: the marked test records, positive integers |
| `studio_pause_files` | Distinct absolute paths (may be empty) |
| `options.unattended_reopen` | `true` or `false`, like every other option |

**Switch keys and the one denylist.** The switch keys are the keys of
`mysql.switch` plus `mysql.switch_secrets` (so `DB_PASSWORD` is one);
`adapter.switch_keys(config)` lists them. `prepare` writes exactly these
keys into `.env` and `seal_sqlite` restores exactly these. `identity.json`'s
`env_denylist` must equal the night flags plus the switch keys
(`adapter.env_denylist(config)`; order does not matter, duplicates are
refused), so neither the night nor the switch nor a later password rotation
changes `config_sha256` (critic RC-H1), and no other key can be hidden from
it.

**`identity.json`** (`identity.example.json`): `version` 1, `run_id`,
`app_root`, `sqlite_path`, `mysql_datadir` and `env_denylist`, nothing else;
`adapter.load_identity_config(raw)` checks this shape. `commands.identity`
must end with `--config` and `paths.identity_config`, so the denylist is
checked in the very file the identity probe reads.

**Queue commands.** The pause flags live in `www-data`'s 0755 folders under
`storage/framework/cache/data`, and the watchdog runs with `UMask=0077`,
which `runuser` keeps; a 0600 flag reads as "not paused" to the deploy user's
workers. So `queue_pause` and `queue_resume` must be exactly
`["/usr/sbin/runuser", "-u", "www-data", "--", "/bin/sh", "-c", SCRIPT, NAME]`
where `SCRIPT` is ` && `-separated steps: plain `cd /absolute/path` steps,
exactly one `umask 0022`, and last `exec ... queue:pause "$1"` (or
`queue:resume "$1"` for `queue_resume`) with no other umask. The adapter
appends the `connection:queue` argument, which becomes `$1`:

```json
"queue_pause": ["/usr/sbin/runuser", "-u", "www-data", "--", "/bin/sh", "-c",
  "cd /var/www/backend && umask 0022 && exec /usr/bin/php artisan queue:pause \"$1\"", "repro-queue"]
```

**Queue flags for the deploy user** (design 5.1). The pre-hold pauses and
the undo list's resumes run as the deploy user through one sudoers line:

```text
sudo -n /usr/local/sbin/repro-queue-flag pause database:media-archives
sudo -n /usr/local/sbin/repro-queue-flag resume database:media-archives
```

Laravel keeps a pause flag in the file cache: the file is
`storage/framework/cache/data/<h[0:2]>/<h[2:4]>/<h>`, where `h` is the SHA-1
of `illuminate:queue:paused:<connection>:<queue>`, and holds
`9999999999b:1;` (forever, `true`). The folders belong to `www-data` with
mode 0755, so the deploy user can neither write nor remove a flag, and a
flag its workers cannot read counts as "not paused". The helper
(`/usr/bin/python3 -IS`, standard library only) takes exactly
`pause|resume <connection:queue>` (exit 2 otherwise), runs only as root
(exit 1), loads `/etc/repro-cutover-fence/host.json` with `load_config`,
reading `host.json` and `identity.json` through `guard.read_protected`, so
it refuses whatever the controller refuses (exit 1). It refuses a queue not
listed in `queues`, exact match only (exit 1). Then it runs
`commands.queue_pause` or `queue_resume` with the queue appended, with only
`PATH` and `LANG` in the environment, in its own process group, for at most
`command_seconds`. A failure or timeout exits 3, the whole group is killed
and the flag may or may not have changed. The flag is `www-data:www-data`
0644 in 0755 folders whatever umask sudo or the caller had. The helper takes
no lock: a resume while the controller holds the queues paused un-pauses
that queue, so only the undo list uses `resume`. `database:cutover-smoke`
can be flagged only if `host.json` lists it.

**New `paths`** (absolute): `identity_config`, `switch_record`,
`sealed_hash`, `receipt`, `activity`, `activated`, `smoke_window`,
`evidence_inputs`, `evidence_report`, `unseal_proof`, `issued_approvals`,
`scheduler_release`, `wrappers`, `wrappers_sqlite`, `wrappers_mysql`,
`retired`, `archive`, `keep`. `load_config` checks only that they are
absolute; the slices that use them pin any path another program reads at a
fixed location (as `cli.py` does for the markers).

**New `commands`**, each a fixed absolute argument list: `config_build`,
`fpm_start`, `fpm_reload`, `fpm_stop`, `smoke_write`, `telnyx_replay`,
`timer` (one list each; the adapter may append arguments) and
`smoke_readonly` (a non-empty list of lists, like `stop_workers`,
`start_workers` and `drain_fpm`). Their shapes beyond that belong to the
slices that run them.

## Owner options (the safe value for each)

Every option must be written in `host.json`; none is filled in.

| Option | Safe value | Meaning |
| --- | --- | --- |
| `separate_freeze_approval` | `false` | One approval ("start freeze") covers the hold and the freeze |
| `adopt_external_hold` | `false` | Admissions closed outside the journal are refused, not adopted |
| `require_long_job_predrain` | `true` | Long-job workers must be stopped before the hold (the 9:20 pm step) |
| `max_drain_seconds` | `600` | Longest wait for admitted writers after the hold |
| `unattended_reopen` | `false` | After an unattended rollback, reopen SQLite in the same unit (design 4.4; owner decision 7) |

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
run in a disposable container through `../run-linux-tests.sh`. The host
config contract tests in `test_adapter.py` (`HostConfigContractTests`,
`HostConfigQueueTests`) need only a POSIX system and also run as an ordinary
user. The cron and unit-file tests also run on Windows. `test_queue_flag.py`
needs Linux root for the helper tests; its Laravel tests also need `php` and
the vendor tree at the repository root (mount the locked vendor volume at
`/src/vendor`; a read-only checkout needs an empty `vendor/` folder as the
mount point). They run the real `php artisan queue:pause` and `queue:resume`
through the helper and a real `queue:work` as a member of `www-data`, and are
skipped without vendor. Its sudoers test checks the text only: `visudo -cf`
is not in the QA image and runs at install. Real systemd, Supervisor, nginx reloads
and a host reboot are not exercised here; they need the staging or production
drill (W2).
