# Production cutover controller, writer wrappers and fences

Built and tested locally only. Nothing here is installed on the production
host. Installing any of it is a production change that needs the owner's
approval for each action (handoff item W1).

## Cutover development

In `mysql.mode=cutover`, the issued promotion approval moves a verified, read-only target to sealed MySQL authority. The operator can inspect `status` and the gate receipt while ingress is held. The active writer profiles remain pinned to SQLite until the MySQL reopen runs. A gate or publication failure after promotion intent places the journal in `recovery_required`. Drill mode always refuses promotion before recording intent.

The combined promotion checks its installed MySQL reopen helpers and both wrapper files before recording write intent. After promotion, the same operator service drains earlier workers, verifies the route and FPM status, checks the app account’s writer token, and runs the marked write smoke. The controller removes the Studio pause files, replays captured messages, resumes queues, verifies worker start times and probes FPM before opening ingress. A second replay and live checks precede activation. The scheduler releases last unless the operator selected `--hold-scheduler`. The deploy fence stays published.

A per-run `*.mysql-reopen-pending.json` file binds the attempt to its committed journal. If the operator process dies, the next watchdog tick reholds the writers after confirming the controller lock is free. It also withdraws ingress if the process died between opening it and finishing the second replay. MySQL stays authoritative.

After a failed reopen, inspect the private smoke receipt and worker status. Use `refresh` on the held host, followed by `reopen`. Refresh verifies exclusive MySQL authority and publishes proof for this boot. Reopen can reuse a verified write smoke on the same boot while the exact authority proof still matches. A new boot or a changed proof requires a fresh write smoke. The receipt keeps every attempt. An interrupted smoke gives no rollback exemption for uncertain writes. Multiple completed smoke windows require the full recovery path because the short rollback audit recognizes a single verified window.

The W2c host rehearsal must demonstrate the approved service groups and expected worker counts, the PHP-FPM pool, the marked Laravel records, complete replay, and the time between controller failure and the watchdog closing ingress. Reconcile any work admitted during that interval.

## Layout on the host

| Repository file | Installed as | Purpose |
| --- | --- | --- |
| `../fence/guard.py` | `/opt/repro-cutover-fence/guard.py` | Admission check (fixed path) |
| `../runner.py` | `/opt/repro-cutover-fence/runner.py` | Journal and state machine |
| `cli.py`, `adapter.py`, `admit.py`, `deploy_marker.py`, `sqlite_seal.py`, `mysql_probe.py`, `envfile.py` | `/opt/repro-cutover-fence/production/` | Controller, host adapter, writer wrapper, fences, MySQL probe, the `.env` switch |
| `mysql/accounts.sql`, `mysql/fixture.sh` | run once by the administrator (session A1): `fixture.sh render` fills the passwords | The eight MySQL accounts; the probe account's defaults file goes to `/etc/repro-cutover-fence/mysql-probe.cnf` (root 0600) |
| `testdata/route-hash-vectors.json` | not installed (test data) | The route-hash contract and vectors shared by the PHP probes (`../probe/`), the live config module (D03) and its tests (D08) |
| Exact copy of the selected rendered engine profile file | `/etc/repro-cutover-fence/wrappers.json` | Active writer profiles, initially SQLite |
| host config (see below) | `/etc/repro-cutover-fence/host.json` | Paths, queues, host commands, owner options |
| `identity.example.json` | `/etc/repro-cutover-fence/identity.json` (root 0644, with the run's policy) | The identity probe's inputs and the `.env` denylist |
| `systemd/repro-cutover-watchdog.{service,timer}` | `/etc/systemd/system/` | Watchdog tick every minute from boot |
| `tmpfiles.d/repro-cutover-fence.conf` | `/etc/tmpfiles.d/` | Recreates `/run/repro-cutover-fence`, `/run/repro-cutover-ingress` and `/run/repro-telnyx-capture` (root, 0755) at every boot |
| `systemd/repro-media-archives.service.d/cutover-fence.conf` | `/etc/systemd/system/repro-media-archives.service.d/` | Media worker through the wrapper |
| `supervisor/laravel-worker.conf.example` | compare with `/etc/supervisor/conf.d/laravel-worker.conf` | System worker group through the wrapper |
| `sudoers/repro-cutover-admit` | `/etc/sudoers.d/repro-cutover-admit` | Deploy user's Supervisor and cron reach the wrapper only |
| `sbin/repro-queue-flag` | `/usr/local/sbin/repro-queue-flag` (root 0755) | Deploy user's queue pause and resume as `www-data` (imports `adapter.py` from `/opt/repro-cutover-fence/production/`) |
| `sudoers/repro-queue-flag` | `/etc/sudoers.d/repro-queue-flag` (root 0440) | One line: the deploy user runs the queue flag helper |
| `nginx/repro-cutover-gate.conf` | `/etc/nginx/conf.d/` | Two http-level maps: the hold (default 1, no exemption) and the Telnyx paths |
| `nginx/repro-cutover-hold.conf` | included at the top of `frontend.conf` and `laravel.conf` server blocks | Server-level 503 unless `/run/repro-cutover-ingress/open` exists; while held, Telnyx goes to the capture |
| `capture/capture.php` | `/opt/repro-telnyx-capture/capture.php` (root 0644) | Hold-time Telnyx capture into the app's spool (below) |
| `capture/php-fpm.conf` | `/etc/repro-telnyx-capture/php-fpm.conf` (root 0644) | The capture's own PHP-FPM master and pool |
| `capture/capture.example.json` | `/etc/repro-telnyx-capture/capture.json` (root 0644) | Release path and open marker |
| `systemd/repro-telnyx-capture.service` | `/etc/systemd/system/` (enabled) | Runs the capture's PHP-FPM at all times; makes `/run/repro-telnyx-capture` itself (`ExecStartPre`) if tmpfiles has not yet |
| `wrappers.sqlite.example.json` | `/etc/repro-cutover-fence/wrappers.sqlite.json` | SQLite writer profiles, including `telnyx-replay` and `cutover-smoke` |
| `wrappers.mysql.example.json` | `/etc/repro-cutover-fence/wrappers.mysql.json` | The same commands pinned to MySQL |
| `sandbox/` (`run-sandbox.sh`, `sandbox_check.py`, `make_config.py`, `shims/refuse`) | `/opt/repro-cutover-sandbox/` for a sandbox run only (W2a, W2c; owner approval each time), removed after it; never part of W1 | Namespace sandbox for on-host rehearsals and its host config (`sandbox/README.md`) |
| `crontab.py`, `cron-rules.example.json` | run once by the operator | Rewrites application cron lines to the wrapper, keeping the storage guard; `--reverse` restores them (below) |

All files and every parent directory must be owned by root and not writable
by group or others. Python runs as `/usr/bin/python3 -I -S`: the installed
copies are outside the app's fingerprinted trees, so they need no `-B`
(only Python run from the app tree must write no bytecode, with `-B` by hand
and `PYTHONDONTWRITEBYTECODE=1` in the lab services; see "Bytecode caches"
below).

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
| Other application cron lines | `crontab.py` rewrites lines that match a rule exactly, keeping the storage guard in front of the wrapper, and keeps an allowed line only when it is exactly its rule's command; any other application line is refused (below) |
| Deployments | `/etc/repro-database-cutover/active`, published under both deploy locks from `init` (Deploy-App checks only this file, never the guard) until reopening a completed rollback lifts it |
| Manual `sqlite3` or tinker as the deploy or web user | SQLite seal: no write bits on the database files during the freeze. Write bits stop new opens only, so the seal first refuses while any other process holds the database, `-wal` or `-shm` open for writing (an idle session opened earlier), and checks again after the modes change. It needs root's `/proc` access to every process (`CAP_SYS_PTRACE`); an unreadable process also refuses |

Root bypasses file modes. Root-run tools are fenced by the runbook only.

### Cron lines (`crontab.py`, D20)

A rule in `cron-rules.json` names the exact command text of its lines
(`command`) and either leaves them as they are (`match`, `allow` with the
reason, `command`) or rewrites them (`match`, `profile`, `command`, the
command the profile replaces); any other shape is refused (exit 2). A
rule's `command` never includes the storage guard, which is carried over
separately: a `command` that starts with `/usr/local/sbin/repro-storage-guard`
is refused (exit 2), and so is a `match` that is not a valid regular
expression. A line that a rule matches is kept or wrapped only when its
command is exactly the rule's `command`, alone or behind the storage guard
`/usr/local/sbin/repro-storage-guard --live && ` (that form, byte for
byte), followed only by output redirections to plain paths (letters,
digits, `_`, `.`, `/`, `-`) that write nothing into the application,
`2>&1` and a marker comment without `%`. A redirection there would be a
writer of its own (review W2V-3). Each target is placed lexically first
(`//` and `.` collapsed). It is refused when it holds `..`, or is relative
and not behind a leading `cd <absolute path> &&` that is the command's
only `cd`. It is also refused under an absolute `application_markers`
entry (the app root) when it lies in a fingerprinted tree (`app`,
`bootstrap`, `config`, `resources`, `routes`, `scripts`, `public`,
`database`) or is one of the fingerprinted root files (`artisan`,
`composer.json`, `composer.lock`, `package.json`, `package-lock.json`)
or one of the identity probe's other inputs (`.env`,
`storage/app/deploy-meta.json`: a line appended there makes the probe
refuse every runner action, the rollback included; review D10R-8),
and when its file name is a marker without a slash (`database.sqlite`,
the live database, with or without `-wal`, `-shm` or `-journal`).
`>> /var/www/backend/storage/logs/cron.log`, or
`>> storage/logs/cron.log` after `cd /var/www/backend &&`, stays allowed.
The check is lexical and cannot see a symbolic link along the path.
That holds for `allow` rules too: an allowed script with an artisan command
chained before or after it, or the retention line with `sqlite3` after it,
is refused like any other line. Words are split at spaces and tabs only, as
`/bin/sh` and cron split them: any line other than a comment that holds
another control character or a character Python takes for whitespace (`\v`,
`\f`, `\r`, `\x1c`-`\x1f`, U+0085, U+00A0, ...) is refused outright, in both
directions, because after one of them a `#` starts no comment for `sh` and
whatever follows would run (review W2F-3). Refused lines are printed with
such characters escaped. The guard is carried over in front of the
wrapper, so it still runs first:

```text
* * * * * /usr/local/sbin/repro-storage-guard --live && cd /var/www/backend && php artisan schedule:run >> /dev/null 2>&1
* * * * * /usr/local/sbin/repro-storage-guard --live && /usr/bin/sudo -n /usr/bin/python3 -I -S /opt/repro-cutover-fence/production/admit.py scheduler >> /dev/null 2>&1
```

Any other prefix (another guard form, `flock`, `nice`, a second `cd`),
anything chained, piped or substituted after the command, or another option
is refused, never rewritten with the difference dropped: `crontab.py` exits
1, names each such line on stderr and prints nothing on stdout. So is a line
the reverse could not restore exactly (two rules for one profile). The
schedule, the whitespace after it, the redirections and the marker comment
are kept. `--reverse` turns each wrapped line back into its rule's command,
guard and trailer kept, so a rewrite and its reverse give the original
crontab byte for byte (input and output are bytes, with no newline or
encoding translation). Lines that are already wrapped are left alone.

The example's commands are the repository installers' forms. At W1, check
each rule's `command` against the live crontab, the `allow` rules included:
production's `ensure-supervisor.sh` and backup retention lines must be
compared with their rules like the wrapped ones (only schedules and command
names were read on 25 November). A live line that differs is refused, and
the rule is corrected, not the line.

The `cubicasa-poll` line keeps the cron guard in front of the wrapper, and
its profile in both engine-specific wrapper files runs
`/usr/local/sbin/repro-storage-guard --live` again before the command. The
guard is installed by hand on production and its source is not in this
repository, so this checkout cannot show that a second run is harmless (a
read-only check). Both stay for now; at W1, read the installed guard
(read-only) and drop the profile's copy if the guard writes anything.

Write to a file and install only on exit 0:

```sh
wrapper='/usr/bin/sudo -n /usr/bin/python3 -I -S /opt/repro-cutover-fence/production/admit.py'
crontab -u maverick -l > before.txt
python3 -I -S -B crontab.py --rules cron-rules.json --wrapper "$wrapper" < before.txt > after.txt \
  && python3 -I -S -B crontab.py --rules cron-rules.json --wrapper "$wrapper" --reverse < after.txt | cmp - before.txt \
  && crontab -u maverick after.txt
```

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

**Host commands** run in their own session and process group. `runuser`
does not pass `SIGKILL` on to the command it started, so on a timeout
(`command_seconds`) or an interrupt the controller kills the whole group
before it reports "Host command timed out": no queue command keeps running
to change a flag after the report.

**The Telnyx capture** (`repro-telnyx-capture.service`, its own `php-fpm`
master and pool, design 5.2) keeps answering through the freeze. The process
patterns are substrings of `/proc/<pid>/cmdline`, so `load_config` refuses a
`writer_processes`, `long_job_processes` or `scheduler_processes` pattern that
names `php-fpm` or `repro-telnyx-capture`, or that is part of the capture's
command lines (`php-fpm: master process (/etc/repro-telnyx-capture/php-fpm.conf)`,
`php-fpm: pool repro-telnyx-capture`, its `ExecStart`): the freeze would wait
for the capture until it failed. It also refuses a `stop_workers`, `drain_fpm`
or `fpm_stop` argument that names `php-fpm` or `repro-telnyx-capture`: a stop
by process name cannot tell the app's pool from the capture's. The app's FPM
is drained through its status socket and stopped through its own unit
(`systemctl stop php8.3-fpm.service`).

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
| `mysql.activity_excluded_tables`, `smoke_tables` | Distinct table names; at least one smoke table. `smoke_tables` is exactly the tables the write smoke changes, triggers included: `["users", "shoots", "aryeo_changes", "jobs"]` (the app smoke's `CutoverSmokeFixture::SMOKE_TABLES`; the app has no `clients` table, the test client is a `users` row). The controller names no app table itself |
| `mysql.switch` | Exactly `DB_CONNECTION` (`mysql`), `DB_DATABASE` (= `mysql.database`), `DB_SOCKET` (= `mysql.socket`), `DB_USERNAME` (a gated account's user), `DB_COLLATION` and `REPRO_MYSQL_EXPECTED_SERVER_UUID` (= `mysql.target_uuid`), plus optionally `DB_EXPECTED_ENGINE` (`mysql`) and `SCHEMA_CACHE_RELEASE` once those branches are merged. Values are written unquoted into `.env`, so only `A-Z a-z 0-9 _ . , / : @ + -` |
| `mysql.switch_secrets` | Exactly `{"DB_PASSWORD": "<root-only file>"}`; the password never appears in `host.json` |
| `mysql.night_flags` | Distinct `.env` key names, none of them a switch key |
| `mysql.offsite_waiver` | `true` or `false`; `true` only in `drill` mode |
| `mysql.free_disk_floor_bytes` | A positive integer (at most 2^50): `prepare` refuses unless `/` has more bytes free for the service accounts (`f_bavail`; design 3.2 step 2, critic HO-M12). The example's 20 GiB is a placeholder: P5 sets the host's value from the night's need (snapshot, import, base, binary logs). The sandbox's `make_config.py` writes 6 GiB |
| `fpm` | `unit` (`*.service`), `socket`, `status_socket` (absolute), `drain_seconds` (1 to 300). `status_socket` is not `socket` (compared normalised), and `drain_seconds` plus 12 s (`adapter.FPM_DRAIN_MARGIN_SECONDS`, `fpm_drain.py`'s last status request and margin) fits inside `command_seconds`: what `fpm_drain.py` refuses at run time, where its exit 1 would also keep a rollback's FPM stop from running |
| `smoke` | `client_id`, `shoot_id`: the marked test records, positive integers |
| `studio_pause_files` | Distinct absolute paths (may be empty) |
| `options.unattended_reopen` | `true` or `false`, like every other option |
| `commands.config_build` | Exactly design 1.3's command for this host config (`config_build.check_command`: `runuser -u <app_owner> -g <app_group>`, `umask 0022`, `cd <app_root>`, `exec env APP_CONFIG_CACHE="$1" /usr/bin/php artisan config:cache`); `build` and `stage` check it again before anything runs |

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
workers. Run as the deploy user, `queue:pause` cannot write there; run as
root, it leaves root-owned files and folders that `www-data` cannot write
later. So `queue_pause` and `queue_resume` must be exactly
`["/usr/sbin/runuser", "-u", "www-data", "--", "/bin/sh", "-c", SCRIPT, NAME]`.
`SCRIPT` is matched by structure, not searched as text: split on ` && `
only, it is exactly three steps, `cd <app_root>` and `umask 0022` (in either
order), then exactly `exec /usr/bin/php artisan queue:pause "$1"` (or
`queue:resume "$1"` for `queue_resume`). The cd goes to `app_root` (which
`identity.json` must name too) and to nothing else, `app_root` must be one
plain shell word (`A-Z a-z 0-9 _ . / -`), and nothing else may appear: no
`;`, `#`, `|`, `&`, backquote, `$(`, `<`, `>`, newline, other quoting or
spacing, no other program or wrapper and no other `php`. The adapter appends
the `connection:queue` argument, which becomes `$1`:

```json
"queue_pause": ["/usr/sbin/runuser", "-u", "www-data", "--", "/bin/sh", "-c",
  "cd /var/www/backend && umask 0022 && exec /usr/bin/php artisan queue:pause \"$1\"", "repro-queue"]
```

**Queue flags for the deploy user** (design 5.1). The pre-hold pauses (the
runbook's 9:20 pm step) and the undo list's resumes (when the night is
called off) run as the deploy user through one sudoers line. The runbook
(`docs/mysql-production-cutover.md`) has the full `host.json` entry; W1
installs the helper and its sudoers file and checks the file with
`visudo -cf /etc/sudoers.d/repro-queue-flag`:

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

## Identity probe (design 1.1)

`commands.identity` runs
`/usr/bin/python3 -I -S /opt/repro-cutover-fence/production/identity_probe.py --config /etc/repro-cutover-fence/identity.json`
as root and prints exactly the six `Identity` fields; any other exit makes
`adapter.identity()` raise, so the runner refuses before any journal change.
`identity_probe.py` and `backend_source_fingerprint.py` are installed beside
`adapter.py` (root 0644).

| Field | Measured from |
| --- | --- |
| `run_id` | `identity.json` (read through `guard.read_protected`, shape by `adapter.load_identity_config`) |
| `release_sha` | `commit` in `<app_root>/storage/app/deploy-meta.json`, 40 lower-case hex |
| `artifact_sha256` | `backend_source_fingerprint.fingerprint(app_root)`, a byte-for-byte copy of `scripts/deploy/backend-source-fingerprint.py` (the deploy preflight's fingerprint); `test_fingerprint_copy_matches_deploy_script` fails when they differ, so a deploy-script change needs a new copy |
| `source_sha256` | SHA-256 of canonical JSON `{"kind": "sqlite-file", "path", "fs_uuid", "st_ino", "machine_id_sha256"}`; `fs_uuid` is the one `/dev/disk/by-uuid` name whose block device's `st_rdev` is the SQLite file's `st_dev` |
| `config_sha256` | `<app_root>/.env` canonicalised, `env_denylist` removed (below) |
| `target_uuid` | `server-uuid` in `<mysql_datadir>/auto.cnf` (`[auto]` then `server-uuid=<uuid>`, as 8.4.13 writes it) |

**Source identity** follows the file, not its bytes or device number: a file
renamed into place (a restore, a copy swapped in) changes it, a write in place
keeps it, and a new device number under the same filesystem UUID (NVMe minors
after a reboot) keeps it. Refused: a SQLite path that is missing or not a
regular file (symlink, directory, FIFO, socket); no `by-uuid` name for its
device, or several; a `by-uuid` chain not root-owned or open to group or other
writes. A filesystem whose files' `st_dev` is no block device (btrfs, overlay,
ZFS) has no name and refuses; P2 shows which the host has.

**Config digest.** Blank lines and lines starting with `#` (after spaces) are
dropped. Every other line must be `NAME=VALUE` with the name first on the line
(`[A-Za-z_][A-Za-z0-9_]*`: no `export`, no space before `=`, no multi-line
value). A denylisted name is dropped whatever its value and however often it
appears; every other line is kept byte for byte, a repeated name is refused
(Laravel's immutable repository keeps the last of two definitions, and sorting
would hide a swap of the two), and the lines are sorted and joined with `\n`.
Names compare exactly, letter case included, as in phpdotenv: `db_password`
is not the denylisted `DB_PASSWORD`. The night flags, the switch and a
`DB_PASSWORD` rotation never change the digest; any other edit does, spacing,
quoting, an inline comment and a carriage return included. Refusals name line
numbers, never a value.

**Bytecode caches (review A2-1).** The fingerprint hashes every file in its
trees, `__pycache__` and `*.pyc` included: excluding them would hide a
tampered `.pyc`, and the copy stays byte-identical to the deploy script. So
repository Python run from the app tree without `-B` changes
`artifact_sha256` (`scripts/mysql/migrate.py` writes
`scripts/mysql/__pycache__/` for the `conversion` module it imports), and the
runner then refuses every action, the watchdog rollback included, until the
folder is removed (`BytecodeCacheTests`). Rule: every repository Python run
from the app tree on the host writes no bytecode. Run by hand (a script or
module under `<app_root>/scripts`), it runs with `python3 -B`. The app's
iGUIDE lab services start `resources/iguide-lab/*.py` with
`PYTHONDONTWRITEBYTECODE=1` in each process environment instead (W2F-1, on
`claude/python-no-bytecode-20261126`, guarded by
`tests/Unit/PythonProcessesWriteNoBytecodeTest.php`); it must reach
production with release D, before W1. The controller's installed copies
under `/opt/repro-cutover-fence` are outside the fingerprinted trees, so they
need no `-B`: they run exactly as `host.json`, the sudoers files and the
units name them, `/usr/bin/python3 -I -S` (sudo matches those command lines
exactly; adding `-B` to one form and not the others would make `sudo -n`
refuse every cron admission), and so do the manual commands below that run
an installed copy. `HostPythonRuleTests` fails if a Markdown file at the
root or under `docs/` or `scripts/` runs code in the app tree with `python3`
and no `-B`; inline code wrapped onto the next line is read as one command,
and a script named by an absolute path outside the app tree (an installed
copy given `--app-root /var/www/backend`) is not app-tree code.
Pre-init check (P2, then W1, read-only): no `__pycache__` under the
fingerprinted trees, that is
`cd <app_root> && find app bootstrap config resources routes scripts public database -name __pycache__`
prints nothing. Run it over every tree, not only `scripts/` and
`resources/iguide-lab/`: a cache anywhere in them changes the artifact. If
the controller refuses on the artifact after no deploy, run it first (the
refusal names this command).

`deploy-meta.json`, `.env` and `auto.cnf` belong to other accounts: each is
opened without following a final symlink and must be a regular file of at
most 1 MiB. The probe uses `identity.json`'s denylist as given; `load_config`,
which `cli.py`, `mysql_probe.py` and `repro-queue-flag` run first, refuses one
that is not the night flags plus the switch keys.

`test_identity_probe.py`: the digest, copy, input-shape, bytecode-cache and
docs-rule tests run on any POSIX system; the probe tests need Linux root
(block device nodes made with `mknod` in a fake `by-uuid`) and run in
`../run-linux-tests.sh`;
`RealFilesystemTests` mount a real ext4 image through two loop devices and run
only with `REPRO_IDENTITY_LOOP_DEVICES=1` in a `--privileged` container
(command in the file's docstring). Host proof P2 compares the output with
`findmnt -no UUID -T`, `stat`, the deploy script's own fingerprint and
`@@server_uuid`. The deploy runs the fingerprint as the deploy user, the probe
as root: `os.walk` skips a folder its user cannot read, so such a folder under
the fingerprinted trees would make the two differ.

Open items for this probe:
- Done in D10 (review A2-11): prepare's `.env` writer (`envfile.py`, below)
  is tested against `identity_probe.config_sha256` itself
  (`test_envfile.py`): the switched file gives the same digest and is never
  refused, and a restore gives back the original bytes. Each refusal the
  probe adds is also a state that blocks the rollback, because the runner
  checks the identity before every action.
- P2 (read-only, before init): run the probe on production's `.env`,
  `/dev/disk/by-uuid` and `/etc/machine-id` and confirm it exits 0 (review
  A2-11), as well as the comparisons above.
- Fixed in the app by W2F-1, not yet in production: the iGUIDE lab
  services start `resources/iguide-lab/*.py`, most of which import a sibling
  module, and without the variable a lab job would write
  `resources/iguide-lab/__pycache__/` and change the identity after init.
  On `claude/python-no-bytecode-20261126` (bound for main) all 34 lab
  Process environments set `PYTHONDONTWRITEBYTECODE=1`, and
  `tests/Unit/PythonProcessesWriteNoBytecodeTest.php` fails on a missing
  or overridden variable, on `-E` or `-I` without `-B`, and when it sees
  fewer than 34 sites. Release D must carry it to production before W1,
  deployed with no lab or studio job in flight. P2 still runs the full
  `find` above and checks who can write `resources/iguide-lab/`. It also
  checks, read-only, that the four interpreter paths `.env` names
  (`IGUIDE_LAB_*_PYTHON`) are real interpreters or venv links, not
  wrappers that add `-E` or `-I` (review W2V-1).

## MySQL probe: the write gate (design 1.2, 2.2)

`commands.mysql` runs
`/usr/bin/python3 -I -S /opt/repro-cutover-fence/production/mysql_probe.py --config /etc/repro-cutover-fence/host.json`.
As root it loads `host.json` with `load_config`, checks that `mysql.client`
and `mysql.probe_defaults` are root-owned in a root-owned chain (the defaults
file also readable by root only), and runs the client once:
`--defaults-file=<probe_defaults> --no-login-paths --protocol=SOCKET
--socket=<mysql.socket> --batch --raw --skip-column-names --connect-timeout=10`
with two `SELECT JSON_OBJECT(...)` statements (server identity, then every
gate input as one JSON document, so the server escapes the values).

The gate is read for each `mysql.gated_accounts` entry, as
`repro_cutover_probe@localhost`, from the nine grant tables (`mysql.user`,
`global_grants`, `role_edges` and `default_roles` and `proxies_priv` in
either direction, `procs_priv`, `db`, `tables_priv`, `columns_priv`),
`@@GLOBAL.mandatory_roles` and `CURRENT_USER()`:

| State | Rule | Exit |
| --- | --- | --- |
| closed | Every global privilege `N`, not locked; one `db` row `repro\_dashboard` with `SELECT` only; one `tables_priv` row `writer_token` with `Select` and no column privilege; no other rows | 0 |
| open | As closed, plus `INSERT`, `UPDATE`, `DELETE` on the schema and `UPDATE (id)` on the token (`tables_priv.Column_priv = 'Update'` and its `columns_priv` row) | 0 in measured mode, 4 in strict mode |
| partial | Any other subset of those four, everything else exactly closed. `open_privileges` names them (`INSERT`, `UPDATE`, `DELETE`, `UPDATE(id)`) for `gate.py` | 4 |
| unrecognised | Anything else: a role or default role (either direction), a mandatory role, a routine or proxy grant, a global static or dynamic privilege, a lock, another schema or table row, the token's `tables_priv.Column_priv` without its `columns_priv` row or that row without it, an unescaped schema grant, a missing account, `CURRENT_USER()` other than the probe (another account in the defaults file, or `--skip-grant-tables`), or a probe without `PROCESS` (it could not see app sessions) | 4 |

Other exits: 3 when nothing listens at the socket (the client's error 2002
with `ENOENT` or `ECONNREFUSED`, or, while the client or the defaults file is
not installed yet, a socket connect that finds nothing), stdout
`{"version": 2, "reachable": false}`; 6 for a wrong server (UUID other than
`mysql.target_uuid`, version not exactly `8.4.13`, `skip_networking` off); 4 when the
state is not readable (login refused: a wrong password, error 1045, or a
locked probe account, error 3118; a missing grant-table read, a server
that accepts but does not answer within the timeouts, or a listening server
with no client or defaults file); 1 for a probe failure (config, unsafe
files, a malformed or unknown-phase journal). A
refused reading prints `gate`, `mode`, `problems` and, when classified,
`open_privileges`, and never `writable` or `business_activity`.

**Strict and measured.** The probe reads the journal
(`<paths.state>/state.json`, through the protected-file check). With no
journal, `planned` or `sqlite_authoritative` it is strict: only `closed`
exits 0. In `holding`, `frozen`, `ready`, `writes_possible`,
`mysql_authoritative` and `recovery_required` it is measured. Exit 0 prints:

```json
{"version": 2, "reachable": true, "server_uuid": "...", "database": "repro_dashboard",
 "gate": "closed", "writable": false, "business_activity": false, "foreign_activity": false,
 "boundary": null, "receipt": null, "gated_sessions": 0, "scanned_events": 0, "mode": "strict"}
```

Business activity, the receipt and `--audit` are in the next section.

**Accounts.** `mysql/accounts.sql` is design 2.2 verbatim with `:name`
placeholders instead of passwords; it needs `repro_mysql_control.writer_token`
to exist first (runbook Release step 4, without its generic grant). Render it
with `mysql/fixture.sh render accounts.sql <password-dir>`: one file per
placeholder (`app`, `maint`, `migrator`, `reader`, `check`, `probe`, `gate`,
`monitor`), owned by the caller, mode 0600, 16 to 256 characters of
`A-Z a-z 0-9 . _ ~ + / = -`; nothing is printed unless every placeholder is
filled. The probe account reads only the columns it needs (never
`authentication_string`).

## MySQL probe: business activity from the receipt (design 1.2, 4.3)

`binlog_scan.py` installs next to `mysql_probe.py` in
`/opt/repro-cutover-fence/production/` (the probe imports it). There is no
`mysqlbinlog` on the host: the probe reads `SHOW BINARY LOG STATUS; SHOW BINARY
LOGS` and `SHOW BINLOG EVENTS IN '<file>' [FROM <position>] LIMIT <offset>, 500`
as the probe account (it has `REPLICATION CLIENT` and `REPLICATION SLAVE`),
without `--raw`: the client's batch mode escapes backslash, tab, newline and
NUL, so a multi-line statement stays one row. The text is MySQL 8.4.13's, so
any other server version is exit 6 (review A13); retest the scan before an
upgrade.

**Records** (all under the journal directory, root-owned, read through the
protected-file check; each is bound to the journal's `identity.run_id`, and
another run's record is ignored):

| Path | Written by | Keys (exactly) |
| --- | --- | --- |
| `paths.receipt` | `prepare` step 10 (D10) | `version` 1, `run_id`, `server_uuid`, `database`, `backup` (`file`, `position`, `gtid_executed`), `armed_from` (`file`, `position`) |
| `paths.activity` | this probe, at the first business hit (0600) | `version`, `run_id`, `receipt_sha256`, `gtid`, `file`, `position`, `tables`, `statements`, `boundary`, `foreign_activity` |
| `paths.activated` | the MySQL reopen, step 16 (C03) | `version` 1, `run_id`, `server_uuid`, `database`, `receipt_sha256` |
| `paths.smoke_window` | the MySQL reopen, step 7 (C03) | `version` 2, `binding`, `attempts`, `reuses`. Each attempt records GTIDs, timestamps and its validated application report when available. |

A receipt or activation record of this run that names another server or
database is exit 4; a malformed or unsafe record is exit 1. `prepare` must
remove the latch before it writes a new receipt (a latch bound to another
receipt is ignored anyway).

**Reading.** No journal, or none of the three records: no activity, no
scan. Else, in order: this run's activation record gives
`business_activity: true` without a scan (a purge can no longer fail it);
a latch bound to this run's receipt gives the same; without a receipt there
is no activity; otherwise the scan runs from `armed_from` to the end of the
last listed file, every later file from its start, paged by offset (the
events inside a compressed transaction all show the payload's end, and
`FROM` that position starts after them). Exit 5 when the `armed_from` file is
no longer listed, or when `gtid_executed` no longer holds the receipt's
`backup.gtid_executed` (a reset reuses the file names). Exit 6 when
`SHOW BINARY LOG STATUS` reports a non-empty `Binlog_Do_DB` or
`Binlog_Ignore_DB` (review B2-7): with `binlog-do-db` or `binlog-ignore-db`
the server leaves app writes out of the log (measured on 8.4.13: the `INSERT`
left no event), so no scan could see them; the message names the column
only. Production's `scripts/mysql/native/mysql.cnf` sets no filter. Every mode
that scans refuses this way (`--audit` and `--since` too); a reading without
this run's receipt reads no binary log. Output: `receipt` is
the receipt's SHA-256, `boundary` is `{file, position, gtid}` of the first
account statement, `scanned_events` counts the events read. In strict mode
business activity is exit 4 (never `true`); foreign activity is reported and
never blocks.

**Command-line contract** (D07's `adapter._mysql` relies on it): exit 0
prints the 13 keys above, with boolean `writable` and `business_activity`
and the measured `server_uuid` (MySQL authority); exit 3 prints exactly
`{"version": 2, "reachable": false}`. The server is read before any record,
so an unreachable server is exit 3 in every phase and mode even while this
run's receipt, latch, activation record and smoke window are present, and
nothing is written.

**Classification** (one transaction per Gtid event): a boundary is an account
statement (`GRANT`, `REVOKE`, `CREATE`/`ALTER`/`DROP`/`RENAME USER`,
`CREATE`/`DROP ROLE`, `SET DEFAULT ROLE`, `SET PASSWORD`, after any leading
comments) except an exempt lock (below), and also, beyond the design's
list, a row event on one of the nine grant tables or a `FLUSH PRIVILEGES`.
An app-schema event is a `Table_map` on the app schema outside
`activity_excluded_tables`, a `Query` with the app schema as default database
or named in its text (also as `repro\_dashboard`; not `BEGIN`, `COMMIT`,
`ROLLBACK` or `XA` control), an `Incident`, a rows event whose table its own
transaction did not map, any Info the scan cannot read, and any event type it
does not know. A statement hidden behind `/*!` or an unclosed comment is both.
App-schema transactions before the first boundary are foreign; the boundary's
own transaction and every later one are business. `sql_log_bin=0` sessions
(`repro_maint` may set it) leave nothing to scan, so a foreign write made that
way is not seen; the app account cannot set it.

**Exempt locks** (review B2-6). Exactly these two forms, typed as one
statement each, lock or unlock only `lockable_accounts` and are not a
boundary:

    ALTER USER '<a>'@'localhost'[, '<b>'@'localhost' ...] ACCOUNT LOCK|UNLOCK
    ALTER USER IF EXISTS '<a>'@'localhost'[, '<b>'@'localhost' ...] ACCOUNT LOCK|UNLOCK

The server rewrites `ALTER USER` before logging it (measured on 8.4.13: quotes
added to unquoted or backquoted names, keywords upper case, comments dropped,
the list joined with `,` and no space), so the scan matches the logged text
exactly: `ALTER USER [IF EXISTS ]'<a>'@'localhost'[,'<b>'@'localhost'...]
ACCOUNT LOCK|UNLOCK`, then only the server's `/* xid=N */`. Every listed
account must be in `lockable_accounts`. Anything else is a boundary: another
clause (`PASSWORD EXPIRE`, `IDENTIFIED BY`, a second `ACCOUNT` option), an
account outside the list (the app account, another host, an `IF EXISTS` name
that does not exist), a comment the server kept, a second statement, or any
other spelling. One account per statement in the first form is the safest
runbook text.

**Modes.** `--audit` (way back, 4.3) needs this run's receipt (else exit 4),
scans from `armed_from` even after activation, and lists every app-schema
transaction with its classification, counts, boundary, smoke window and event count.
A version 2 smoke record binds the run and receipt to the target, pinned route, fixture IDs and installed wrapper.
Each attempt records its boot ID, verified authority-proof SHA-256 and starting GTID set before the application
runs. Interrupted and failed attempts stay in the record. A successful attempt records its ending GTID set and
the application's validated report after rechecking the same authority. A retry may reuse the latest successful
attempt on the same boot and authority only if the target still has its GTIDs. A new boot, changed proof or latest
failed attempt requires a fresh marked edit and job. The short rollback audit currently supports one verified
window. Multiple verified windows or an older version 2 record without boot binding require the full reverse
export and independent verification.

The audit credits the verified attempt only when its GTID difference contains the expected application writes
in order. The fixture update touches `users`, `shoots` and `aryeo_changes` in one transaction. The database
queue then inserts, reserves and deletes its no-op job in three separate `jobs` transactions. Additional
application transactions, a different table sequence or DDL leave the writes classified as business. Legacy
version 1 windows and incomplete attempts also leave their business transactions in the audit. Verify the complete
sequence on the pinned application and MySQL configuration during W2c before relying on the short rollback.

`--since <file>:<position>`
(for `prepare`, 3.2 steps 4 and 10) needs no receipt: it reads the coordinate
first (`until`, with `gtid_executed`), scans from the given one to the end and
lists every app-schema transaction; `prepare` writes `armed_from = until` only
when the list is empty. `--since-gtid <set>` (only with `--since`) adds the
receipt's history check: exit 5 before any event is read unless
`gtid_executed` still holds the set. Both only read and print the gate as well.

**The `--since` contract for D10** (review B2-9):

- Without `--since-gtid`, `--since` checks no history: after `RESET BINARY
  LOGS AND GTIDS` a reused file name and position would scan as if it were
  the backup coordinate. D10 passes `--since-gtid <backup.gtid_executed>`, or
  itself requires the receipt's `backup.gtid_executed` to be a subset of the
  output's `until.gtid_executed`. Neither detects a reset followed by enough
  new transactions to cover the backup's set again (a GTID set cannot); the
  file and position must also still name an event (else exit 4).
- Every app-schema event after the coordinate counts, whoever made it: DDL,
  `complete-schema`, the smoke fixtures' creation, even `ANALYZE TABLE
  repro_dashboard.<t>` (logged as its own transaction naming the schema).
  So the drill's recorded coordinate (`offsite_waiver`) must be read after
  `complete-schema` and `cutover:smoke-fixtures --create`, or `prepare`
  refuses (D19/C12 runbook).

**For gate.py** (D06 open item). `mysql_probe.read_gate(config, mode)` is
`read()` that also returns the rows it classified: a `Reading(server, state,
gate)`, where `state` is the probe's state document (`STATE_KEYS`: each grant
table's rows for the gated accounts, `None` for none, `sessions`,
`probe_privileges`, `mandatory_roles`). A `Refusal` raised after the
classification (unrecognised, partial, not closed in strict mode) carries the
same `Reading` as `refusal.reading`; earlier refusals (exit 3, 6, an
unreadable answer) carry `None`. The rows are never printed. A consumer that
builds SQL from them must still require `server["current_user"] ==
PROBE_ACCOUNT`.

**Tests.** `test_binlog_scan.py` runs anywhere: the E6 and E1 event text
recorded on 8.4.13 through the client's batch format, the lock forms as 8.4.13
logs them, and `SHOW BINARY LOG STATUS` recorded with each binary log filter.
`test_mysql_probe.py`'s `FakeServerTests` need Linux root only (a root-owned
stand-in client serves the recorded text: version pin, filters, paging calls,
exit 5, records and their protected-file refusals in each reading that reads
them, latch, modes, `--since-gtid`, exit 3 with this run's records,
`read_gate`); `ActivityTests` need the socket fixture and replay E6 on it (the
recorded text must equal what the server prints), plus compressed
transactions, more than one page, several files, a purge, the activation
record, the typed lock forms, rejection of the legacy synthetic smoke window, `--since`,
`--since-gtid` and a multi-line DDL. The filter refusal has no fixture test:
`mysql/fixture.sh serve` cannot start a server with `binlog-ignore-db`
without a change to that script.

**Open items.**

- Owner/runbook choice (review B2-10), to record in the A1/D19 manifest:
  writes the binary log never records cannot be seen. `repro_maint` holds
  `SESSION_VARIABLES_ADMIN` (accounts.sql) and can `SET sql_log_bin=0` and
  write the app schema invisibly; `SET GLOBAL` or `SET PERSIST` of
  `mandatory_roles` is not logged either (the gate read catches that at each
  read, not between reads). Either revoke `SESSION_VARIABLES_ADMIN` from
  `repro_maint` after the import, or lock `repro_maint` with an exempt lock
  (above) before `prepare`'s `armed_from`.
- Done in D10: `prepare` runs `--since` with `--since-gtid` and also
  compares the sets itself (below). Still open for the runbook (D19/C12):
  record the drill coordinate after `complete-schema` and
  `cutover:smoke-fixtures --create` (the contract above).
- Done at the lane merge: `gate.py` builds its root SQL from
  `refusal.reading.state` (the rows `read_gate` classified), with its own
  `CURRENT_USER()` and server UUID check, and no second read.

## Write gate procedures: `gate.py` (design 2.3, 2.4)

Installed with the probe in `/opt/repro-cutover-fence/production/`; the gate
account's defaults file is `mysql.gate_defaults`
(`/etc/repro-cutover-fence/mysql-gate.cnf`, root 0600, checked like the
probe's). A library for the controller, under its lock: `open_gate(config)`
for `seal_mysql`; `close_gate(config)` for `seal_sqlite`, `way-back close`
and `gate-normalise`. The caller decides when each is allowed (phases,
approvals); `gate.py` decides how.

- **Reading.** Always `mysql_probe.read()` as the probe account, in measured
  mode whatever the journal says (an open gate found in `planned` is still
  closed, not left open). The gate account runs only `GRANT`, `REVOKE` and
  `KILL`; it cannot read the grant tables.
- **Close.** Closed with no app session: done, no gate-account login. Open or
  partial: one `REVOKE` per level naming exactly the privileges present (a
  full-set revoke of a partial gate fails with 1141 and revokes nothing). Then
  the app sessions the probe lists after the revoke are `KILL`ed (1094 is
  fine) and only those IDs are waited for, 10 s at most, so a reconnecting
  reader cannot hold the close up; the probe must read closed. A closed gate
  with app sessions also gets the kill (a close that crashed between its
  revoke and its kill leaves sessions that still write). Strict: a failed
  `KILL`, or a killed session still listed after the wait, refuses the close
  (the rollback retries it).
- **KILLs.** Sent on the client's stdin, one per line, with `--force`, so
  every `KILL` runs and each error names its line. With `-e` the 8.4 client
  stops at the first error even with `--force`, so a session that ended by
  itself (1094) left every later one unkilled.
- **Open.** Refused before any MySQL login unless `mysql.mode` is `cutover`
  and the journal reads `writes_possible` (the runner commits it just before
  `seal_mysql`; in drill mode, or any other phase, the gate never opens).
  From closed: the two `GRANT`s, then every app session listed afterwards is
  killed (it holds the closed privileges); from open: no `GRANT`, the kill
  only; the probe must read open. Partial is refused. A stale session whose
  `KILL` fails, or that is still listed after the wait, does not fail the
  open (it cannot write, and the open runs after `writes_possible`): it is
  recorded in `state/mysql-gate-open.json` with the run ID, target UUID, receipt digest and timestamp.
- **Results.** `close_gate` returns `{"before", "revoked", "killed"}`,
  `open_gate` `{"before", "granted", "killed", "kill_failed", "stragglers"}`
  (disjoint session ID lists), for the caller's records.
- **Refusals.** `GateUnreachable` (probe exit 3, nothing changed: the caller
  decides whether a skip is allowed and records it) is a `GateRefused`, which
  is an `UnsafeCutover`. An unrecognised state is refused with no change and
  `root_sql`: statements built from the rows the probe classified
  (`refusal.reading.state` from `mysql_probe.read_gate`, never a second read;
  roles and proxies
  either way, default roles, the lock, `REVOKE ALL PRIVILEGES, GRANT OPTION`,
  a column row left by direct DML, `mandatory_roles`, the probe's `PROCESS`)
  ending with the two closed grants of `accounts.sql`. It is also in the
  exception's message. A missing account gets comments only (recreate it as
  `accounts.sql` does, with its password); a reading not made as the probe
  account gets no SQL. Names that would need quoting appear only as JSON in a
  comment.
- **Lint.** `gate.py --config <host.json> check` (root, read-only) prints the
  reading as JSON, with `root_sql` and `gate_account`, and exits 0 for closed
  or open, 3 unreachable, 4 for any other gate state, 5 when the gate reads
  closed or open but the gate account's self-check fails, 1 on a failure.
- **Gate account self-check** (`check()`, review B2-1). A login as the gate
  account through `mysql.client` and `mysql.gate_defaults`, exactly as the
  procedures log in, running `SELECT CURRENT_USER(); SHOW GRANTS` (for the
  current account: no privilege needed, no change, nothing in the binary
  log). `CURRENT_USER()` must be `repro_cutover_gate@localhost` and the
  grants exactly those `accounts.sql` gives it, compared as (privilege, level,
  grant option); `test_gate.py` parses `accounts.sql` itself, so a change
  there fails it. A wrong password, a lock, an expired password, another
  account, an unsafe or missing defaults file, a missing `CONNECTION_ADMIN`,
  a missing `WITH GRANT OPTION` or any extra grant is named in
  `gate_account.problems` (never a password). The 8.4.13 client prints its
  expired-password text on stdout with nothing on stderr, so a failed login
  quotes the first stdout line when stderr is empty; SHOW GRANTS joins
  dynamic privileges with a bare comma (`CONNECTION_ADMIN,SYSTEM_VARIABLES_ADMIN`),
  and each is read and named (review W2F-4). Every one of these fails closed
  (exit 5). It is not run when nothing listens (exit 3), and the close of a
  closed gate still logs in as nobody but the probe. D11 and D14 make this
  self-check part of `check-evidence` and of the promote preconditions,
  before Approval 2, so a gate account that could not open the gate refuses
  the promote instead of failing at `seal_mysql`'s `GRANT` after
  `writes_possible` (then `recovery_required`).

`test_gate.py` judges each procedure from the server side (the probe as the
controller runs it, `SHOW GRANTS`, the binary log's account statements,
`performance_schema.accounts` logins and `processlist`) on the fixture that
`mysql/fixture.sh serve` builds from `accounts.sql`, with
`REPRO_MYSQL_PROBE_FIXTURE` as for the probe. Without it those tests skip
and a `WARNING` line names them, at the start of `../run-linux-tests.sh`
(`test_gate.py --fixture-check`, beside the probe's) and at the end of the
file's run; with `REPRO_REQUIRE_PROBE_FIXTURE=1` (`run-probe-fixture-tests.sh
--linux-suite`) the Linux suite stops before any suite instead, naming both
files' tests. Tests that change the gate account restore it from
`accounts.sql`'s own `GRANT` lines and check that they did. The statement,
quoting and expected-grant tests run anywhere.

## Live config and the config cache build (design 1.3)

`var_export.py`, `live_config.py` and `config_build.py` are installed with the
controller in `/opt/repro-cutover-fence/production/` (W1). `var_export.py`
is also the alert probe's reader: the alerts job of
`scripts/mysql/offsite/install_backup_host.py` installs a copy beside
`alert_probe.py` in `/opt/repro-backup-tools/`, which loads it by path. Only
in a checkout (the probe in `scripts/mysql/offsite`) does the probe also look
in that checkout's `scripts/cutover/production`; installed, it reads only the
copy beside it, and a missing copy is an error.

- **Reader** (`var_export.parse`): `<?php return <array>;` exactly as PHP 8.3's
  `var_export` writes it: arrays, single-quoted UTF-8 strings, integers,
  floats, `true`, `false`, `NULL`. An object, enum, NUL concatenation, `INF`
  or `NAN`, `PHP_INT_MIN`'s expression, a literal PHP would read differently,
  a duplicate key, nesting over 32 levels or any text around it is refused.
  It never executes the file. The alert probe's lenient reader
  (`tokens`, `value`) moved here unchanged.
- **`live_config.measure(app_root)`** returns `LiveConfig(engine, source,
  live_config_sha256, values, problem)` from `bootstrap/cache/config.php`
  (a regular file, never through a link, at most 8 MiB). The engine is the
  default connection's driver; a non-empty `url` wins with its scheme. The
  route values and hash follow `testdata/route-hash-vectors.json`.
  Passwords become `password_set`, and a `url` carrying a password is refused.
  A missing or refused cache gives source `fallback`, no hash, the engine from
  `.env` `DB_CONNECTION` (read as phpdotenv reads it, through a link) and the
  reason in `problem`. Refused means a link, not a regular file, over 8 MiB,
  empty, truncated, not var_export, an object, enum or expression inside, or a
  shape the contract cannot hash (no default connection or driver, a
  `password` beside `password_set`, a `url` carrying a password; the module
  docstring has the full list). PHP still loads several of these (a link, an
  enum, a large file), so **the fallback engine is advisory**: it may let a
  rollback proceed, but it never proves which engine is live. Both
  publications (and `host_authority`) and `prepare` (D10) refuse it today;
  the SQLite reopen (D13, which still accepts it) and the MySQL reopen (C03)
  are to refuse it too (see "Not yet here" below). Anything other than a plain
  `sqlite` or `mysql`, or a `DB_URL`, raises `Unmeasurable`. CLI (the
  installed copy, run as `/usr/bin/python3 -I -S` like every controller copy
  under `/opt`: it reads the app root and imports nothing from it):
  `/usr/bin/python3 -I -S /opt/repro-cutover-fence/production/live_config.py --app-root /var/www/backend`
  prints the same as JSON (exit 1 when unmeasurable).
- **`config_build.build(config, expected_engine=..., expected_sha256=None,
  deadline=None)`** first checks that `commands.config_build` is exactly
  design 1.3's command (`config_build.check_command`); another command (as
  root, umask 0002, another php) is refused before anything runs or changes.
  It runs it for `bootstrap/cache/.config.php.repro-<hex>`, for at most
  `command_seconds` or until the caller's deadline, whichever is first (at
  least 1 s). It then checks the file: regular, one link, owned by
  `app_owner`, mode 0644, strictly readable, the expected engine and, if
  given, the pin. **Owner and mode:** the new `config.php` is always
  `app_owner`'s, keeps the live file's group, and gets the live file's mode
  with the group and other write bits and every execute bit cleared
  (`app_group` and 0644 when there is no regular live file). Deploy-App runs
  `config:cache` as maverick in group www-data with umask 0022, which writes
  maverick:www-data 0644; a live 0664 or 0666 file becomes 0644, a root-owned
  one becomes maverick's, and the result is never wider than the live mode,
  so www-data is never handed a config it may rewrite. The build fsyncs the
  file, renames it over `config.php` and fsyncs the folder. A failure or
  timeout (the whole process group is killed) removes the temporary file and
  leaves the live file untouched. An interrupted build's temporary files are
  removed by the next one.
  **`config_build.stage(config, parent=..., expected_engine=...,
  environment=...)`** is the same build into a fresh 0700 folder owned by the
  deploy user inside `parent`, with the given variables added (the pins: the
  switch values for MySQL). It renames nothing and keeps nothing. `parent` is
  required (never the system temporary folder, where the deploy user could
  rename its own staged folder): an absolute path to a real folder, not a
  link, owned by root, writable by no group or other account. **The
  controller passes a root-owned runtime folder under
  `/run/repro-cutover-fence`** (itself root 0755 from tmpfiles.d), for
  example `/run/repro-cutover-fence/config-stage` created root 0711: a tmpfs,
  so a staged MySQL config left by a SIGKILL is gone at the next boot. A root
  0700 folder does not work: the deploy user's `config:cache` must pass
  through the parent to reach its staged folder, so `stage` refuses a parent
  that neither others nor `app_group` can search.

`test_live_config.py` runs anywhere. `test_config_build.py` needs Linux root,
`runuser`, `nobody` and `www-data` for its fake-php tests (its command-check
tests run anywhere). Its
`RealConfigCacheTests` also need `php`, `php-fpm` and `vendor/` (the QA
image; its docstring has the command). They show that a staged SQLite build
equals the measured live cache, and that both equal what `fpm-engine.php`
prints inside a PHP-FPM pool, for SQLite and for MySQL. The two fsyncs (the
temporary file before the rename, the folder after it) are not verified by
a crash test; no test here can tell a missing fsync apart.

`adapter.load_config` also calls `config_build.check_command`, so a wrong
command is refused when the host config loads; the sandbox's `make_config.py`
writes design 1.3's form for the sandbox app copy. `adapter.py` therefore
imports `config_build.py` (and through it and `live_config.py`,
`var_export.py`): every program that imports the adapter (`cli.py`,
`identity_probe.py`, `mysql_probe.py`, `gate.py`, `fpm_drain.py`,
`repro-queue-flag`) needs all three installed beside it. `config_build` looks
the accounts up (`pwd`, `grp`) only when a build runs, so the import works
where those modules do not exist.

Not yet here (later slices):
- D13: the SQLite reopen refuses a `fallback` live config (review A2-9); it
  accepts one today (`reopen` checks only the observed engine and
  writability). The MySQL reopen (C03) must refuse it too; both
  publications and `prepare` (D10) already do.
- D12, D14: `build(config, expected_engine=..., expected_sha256=<pin>,
  deadline=...)` then `live_config.measure` again (the deploy user can
  replace `config.php` at any time), as `prepare` step 8 does (D10);
  `pin-config` creates the root 0711 staging folder under
  `/run/repro-cutover-fence` and passes it to `stage`.

## Drill operator entry points

Run `cli.py pin-config` as root before creating policy.json. It reproduces the live SQLite cache in
a private staging directory, stages the MySQL cache, and prints the two policy hashes. Use
`gate-check` to inspect the configured server. A separately authorized `gate-normalise` closes
a partial gate only before writes are possible and with no pending transition.

The installed `repro-cutover-run` wrapper runs each operator action in a transient systemd service.
It prints the unit name before the action starts. Follow that unit with `journalctl -fu UNIT.service`
after reconnecting. Install the dedicated operator sudoers file only for the approved host window.
The service names and accepted arguments are explicit in the wrapper and sudoers file.

Issue a phase approval with `approval-template --phase hold`, then run `hold --issued-approval`.
The hold validates the target identity and closed gate under the controller lock.
Run `check-evidence --inputs DIR --write` while frozen and use `status --evidence` after prepare.
Issuing a promote approval rechecks the prepared evidence and arms a challenge-bound expiry timer.
A separate `arm-deadline --minutes N --phase frozen` uses the earlier of N minutes and the run's
decision deadline. The two timers use separate unit names. Each schedules the exact UTC deadline
with `--on-calendar` and `--timer-property=AccuracySec=1us`. Reissuing an approval replaces its
own timer under the controller lock. A stale timer leaves the new phase alone. This drill build refuses promotion.
Queue resumes take the same controller lock and require a stable pre-hold or authority phase.

## Retiring a completed run

The owner invokes `cli.py retire` directly on the approved host. Retirement requires a planned SQLite run, a completed SQLite rollback, or a completed MySQL promotion with its durable activation receipt. An ordinary retirement checks the reopened application and its pinned route. It archives the journal in a private directory named `<run>-<UTC>`, compares the files and keeps both lock inodes. On MySQL it validates the four rollback records, retains private copies and moves the sealed SQLite file and its companions into `database/frozen-<run>/`. A root-private directory occupies the original SQLite path.

The protected `retire-pending.json` records the archive and chosen outcome before any journal records are removed. After interruption, rerun the same retirement. The controller refuses competing transitions. The retired authority becomes usable only after the journal and pending record are both absent. Workers hold a shared lease on the existing operator lock and must match the retired engine. At boot `repro-cutover-retired-ingress.service` verifies the cached route before opening the ingress marker.

To install a reviewed next run, supply both `--next-policy PATH` and `--next-identity PATH`. The pair must describe the same new run and host. The deployment fence transfers to the new run atomically. A recovery handoff additionally requires the completed way-back close coordinate, stopped writers, a quiet closed MySQL gate and the restored SQLite route. Its root-private `state/next-run.json` identifies the exact archive, previous run, new run and installed policy checksum. Reinitializing from ordinary retired SQLite commits the new journal before removing the retired marker. The operator then runs the normal rehold, refresh and reopen sequence.

## Observation and authority (design 1.2, 1.3, 3.2, 3.4)

`observe()` runs the MySQL probe and the live config module on every call.

- **Engine**: `live_config.measure(app_root)`, the cached config's default
  driver. A missing or refused cache gives `.env` `DB_CONNECTION` with source
  `fallback`, which `observe()` accepts so that a rollback can run. A config
  whose engine only PHP could tell (`Unmeasurable`) raises `UnsafeCutover`.
- **MySQL probe exits**: 0 is the reading as printed (`writable` and
  `business_activity` must be booleans). 3 with exactly `{"version": 2,
  "reachable": false}` (nothing listens at the socket) reads as not writable
  and no business activity, but only while there is no journal or its
  `writes_possible` is exactly `false`. The grants persist in the grant
  tables, so a stopped server cannot be written, and `init`, the hold, the
  freeze, the rollback and `refresh` keep working while MySQL is down or not
  installed yet. With `writes_possible` true or missing, exit 3 raises. Every
  other exit (1, 4, 5, 6) raises, also when its output looks like a closed,
  quiet reading. For exits 4 to 6 (design 4.7: the site stays held until a
  person acts) the refusal quotes the probe's own `problems` list, which never
  holds a secret, joined with `; ` and cut to 500 characters; output that is
  not a JSON object with a non-empty list of texts adds nothing. Exit 1 is the
  probe's own refusal and is not quoted.
- **SQLite writable**: `sqlite_seal.writable()` counts only a regular file at
  the path (`lstat`) with a write bit. A link, a directory (what `retire`
  leaves at a MySQL run's path), a FIFO or nothing there is not writable.
- **Freeze**: right after the seal, `freeze` writes `paths.sealed_hash` (root
  0600), `{"version": 1, "run_id", "hold_id", "started_at", "database",
  "sha256"}`, the SHA-256 of the sealed file read without following a link
  (`sqlite_seal.file_sha256`). `hold_id` and `started_at` are the journal's
  `hold.hold_id` and `started_at` (either may be `null`, not both: a journal
  with no hold refuses before the seal). `prepare`, the rollback's unseal
  proof and the short way back compare the file with it. A repeated freeze
  within the same run and hold refuses if the file changed since; another
  run's or another hold's record (a journal reset by hand and held again under
  the same run ID, as a sandbox rerun does) is replaced.
- **Drain** (`drain(lease, deadline, purpose=...)`, design 5.4): `purpose` is
  `freeze` or `rollback`, keyword-only with no default; anything else refuses
  before any command. It runs `stop_workers`, then `drain_fpm`
  (`fpm_drain.py`, then the FPM stop), then waits for the writer processes.
  The first `drain_fpm` command's stdout must be one JSON object with a text
  `outcome` (no repeated key, no NaN); anything else refuses, for both
  purposes. Its record is `{purpose, outcome}` plus `active_processes` (a
  count or `null`), `waited_seconds` (seconds or `null`), `polls` (the number
  of status readings, a count or `null`) and `problem` (text or `null`, cut
  to 500 characters) when the report has them; other report keys are dropped
  and a malformed one refuses. A freeze goes on after `drained`, `timed_out`
  with at least one status reading (the stop ends what still runs) or
  `stopped`. On any other outcome, `unmonitored` included, and on a
  `timed_out` with `polls` 0, `null` or missing (only transient errors for
  the whole limit, such as a malformed FastCGI answer: the drain never saw
  the requests the stop would end; review W2F-6), it refuses naming the
  problem before any further `drain_fpm` command, so the FPM stop does not
  run and the hold stays. A rollback records any outcome, `polls` included,
  and always runs the stop: nginx holds every request by then (review
  A2-2). A freeze with no
  `drain_fpm` command refuses; a rollback with none returns no record.
  A rollback that has less than `fpm.drain_seconds` plus 12 s left before
  its deadline when the FPM drain would start skips `fpm_drain.py` and runs
  the FPM stop at once, recording outcome `skipped` (D12; "`seal_sqlite`,
  the full undo" below). A freeze is unchanged.
  `drain` returns the record and the runner journals it: it goes into the
  pending `freeze` or `abort` action, so that action's **history entry**
  carries it as `fpm_drain` (the journal's `evidence` key is the target
  evidence `prepare` writes, and the adapter never writes the journal
  itself). It becomes durable with the runner's next journal write, before
  the scheduler drain. A refused freeze journals nothing; its message names
  the problem, and the rollback's own record follows.
- **Identity refusal** (`runner._verify_identity`): names the identity fields
  that differ (`run`, `release`, `artifact`, `source`, `configuration`,
  `target`), never a value. When `artifact` is among them it adds one sentence
  pointing at the runbook's bytecode rule: a `__pycache__` folder under any
  of the app's fingerprinted trees changes the artifact, and the rule's
  `find` over all of them (which the sentence repeats) shows it (review
  A2-1, W2V-4).
- **Publication** (`publish_authority`, both engines) and **`host_authority`**
  (what `refresh` publishes) measure every value. The route must be sealed for
  the engine asked for, and the live config must be a parsed cache: a
  `fallback` refuses and nothing is published. `live_config_sha256` is the
  measured route hash; `database_identity` is the identity probe's
  `source_sha256` (SQLite) or the probe's `server_uuid`, which must equal
  `mysql.target_uuid` (MySQL); release and artifact come from the identity
  probe. The guard compares them with the pins, so a cache other than the
  pinned one publishes a proof that admits nobody (the reopen refuses) until
  the pinned cache is back and `refresh` measures again. Only `seal_mysql`,
  which this adapter still refuses, leads the runner to a MySQL publication.

Not yet here:
- Review C2-4: measured publication makes correct pins a precondition of the
  W1 drill init sequence (marker, `init`, `rehold`, `refresh`, reopen). D14's
  W1 sequence must refuse before `rehold` unless `live_config.py --app-root`
  measures the source cache with the hash equal to policy
  `sqlite_live_config_sha256` (read-only), and D18 must exercise that order.
  `pin-config` is D14.
- Review C2-7: (1) `config_build.check_command` in `load_config`: done;
  (2) `reopen()` (SQLite) still accepts a fallback live config (D13); the
  unseal proof against `paths.sealed_hash`, and `seal_sqlite` rebuilding a
  fallback config and never unsealing on one, are done (D12, "`seal_sqlite`,
  the full undo" below); (3) with MySQL down, `observe()` reads false/false in
  `frozen` and `ready` while `writes_possible` is false, so `before.frozen()`
  holds in `_promote`: done in D11, `evidence()` requires a reachable, closed
  target reading, so `promote` refuses before `writes_possible` is written,
  and the evidence report carries the freeze's `fpm_drain` record from the
  journal history (next sections but one).

Tests: `test_adapter.py` (root) with fake probes and a fake `fpm_drain.py`
that prints the report in a file, plus the real `mysql_probe.py` twice: with
nothing installed (exit 3), and against D04's fixture
(`test_real_probe_reading_gives_mysql_authority_the_server_uuid`). That one
skips without `REPRO_MYSQL_PROBE_FIXTURE` and fails when
`REPRO_REQUIRE_PROBE_FIXTURE=1`, as under `run-probe-fixture-tests.sh
--linux-suite`. `test_adapter.py --fixture-check`, which
`../run-linux-tests.sh` runs before any suite beside the probe's and the
gate's, names it in a WARNING line when it would skip (no fixture, or not
Linux root) and stops the run when `REPRO_REQUIRE_PROBE_FIXTURE=1` (review
W2F-5). The
runner's part of the drain contract and the identity refusal are in
`../test_runner.py` (fake adapters, any platform). Since the lane merge the
contracts between slices are also tested end to end: the real `fpm_drain.py`
as `drain_fpm`'s first command against a pool socket that listens without its
status socket (the freeze refuses before the FPM stop; the rollback records
`unmonitored` and stops FPM), against a status socket that closes every
request unanswered (`timed_out` with `polls` 0: the same refusal and
rollback) and against a scripted status socket (a drained pool's counts in
the journal); and the real `mysql_probe.py` against D05's
fake 8.4.13 client with this run's receipt in place (exit 3 still reads
false/false while `writes_possible` is false; exits 4 to 6 are refused with
the probe's own problems).

## `prepare`, the switch half (design 3.2 steps 1 to 10, D10)

`adapter.prepare(lease, deadline)` runs inside `runner.perform("prepare")`
(`Runner._prepare` is unchanged): the runner has drained the writers and
journalled the pending `prepare`; afterwards it requires a frozen
observation on MySQL and then calls `evidence()` (D11, next section), whose
record it journals as the run goes `ready`. `cli.py prepare` is D14's. The
steps are `adapter.PREPARE_STEPS`, one method `_prepare_<name>` each; before
each, the lease must still be current and the deadline not passed. Steps 11
and 12 (`checks`, `report`) are in the next section.

| Step | Name | What |
| --- | --- | --- |
| 1 | `writers_drained` | `lease.require_writers_drained()`; the journal reads `frozen` with `writes_possible` false |
| 2 | `inputs` | The evidence inputs manifest (below), every input re-read and bound by `adapter.check_inputs`; the policy pins `mysql_live_config_sha256`; free bytes on `/` above `mysql.free_disk_floor_bytes` |
| 3 | `sqlite_frozen` | `sqlite_seal.writable()` false, the WAL empty, `paths.sealed_hash` of this run, the journal's hold (`hold_id`, `started_at`) and this `database`, and `sqlite_seal.file_sha256` equal to its `sha256` |
| 4 | `mysql_history` | One probe reading: the target's server UUID and database, `gate` closed, `writable` and `business_activity` false. App sessions (`gated_sessions` above 0) are ended by `gate.close_gate`, which on a closed gate only sends the KILLs (no grant change); a gate it found not closed is refused after the close. Then the history scan (below) |
| 5 | `switch_record` | First `envfile.require_switchable` (the `.env` checks of `assign` and `replace`, steps 6 and 7); then the switch record (below), written, or verified on a retry |
| 6 | `mysql_env` | The MySQL `.env` in memory (`envfile.assign`): `mysql.switch` plus the secret from `mysql-app.env`; refused unless `identity_probe.config_sha256` of the current and the switched file, with the denylist read again from `paths.identity_config`, are equal (the v1 bug, critic RC-H1) |
| 7 | `env_replaced` | `envfile.replace` (owner, group, mode and every extended attribute kept, the ACL included); skipped when the file already holds the switch; read back |
| 8 | `config` | `config_build.build(config, expected_engine="mysql", expected_sha256=<MySQL pin>, deadline=...)`, then `live_config.measure` again: engine `mysql`, source `cache`, the pin (the deploy user can replace `config.php` at any time). Then `switch_at` in the switch record |
| 9 | `fpm` | `commands.fpm_start` (fresh FPM workers on the MySQL config; the freeze's drain stopped FPM) |
| 10 | `receipt` | The history scan once more; `paths.activity` removed; the receipt written (below) |
| 11 | `checks` | Every process context, FPM, the read-only smoke, the token check and the MySQL reading on MySQL (next section) |
| 12 | `report` | `paths.evidence_report` (next section) |

Steps 1 to 4 change nothing (step 4 may end app sessions, which hold no
grant), and the run stays `frozen` with its pending `prepare` until `abort`.
Step 5 refuses a `.env` that steps 6 and 7 would refuse before it writes
the record. From the record on a failure leaves the switch partly done, for
`seal_sqlite` to undo ("`seal_sqlite`, the full undo" below).

**The history scan** (steps 4 and 10, the `--since` contract above):
`commands.mysql` with `--since <backup file>:<position> --since-gtid
<backup gtid_executed>` appended. Nothing listening (exit 3) is retried, 3
attempts 1 s apart within the deadline, then refused; exits 4 to 6 are
refused quoting the probe's `problems`. The output must name the target's
server and database, read the gate closed, start at the backup coordinate
and list no app-schema transaction: every one counts, whoever made it ("the
copy changed after the verified backup", naming the count, the first GTID
and its tables or statement kinds, never a value). The backup's
`gtid_executed` must be a subset of the output's `until.gtid_executed`,
checked here as well as by `--since-gtid` (review B2-9). Step 10's `until`
(the coordinate the probe read before it scanned) becomes `armed_from`.

**The evidence inputs manifest** (`paths.evidence_inputs`, root 0600, read
through the protected-file check). `cli.py check-evidence --write` writes it
(`ProductionAdapter.check_evidence`, next section): it builds the document
from the night's input directory and runs `adapter.check_inputs(config,
pinned, policy, started_at, manifest)` on it first, so a bad input is caught
before the one-shot action; `prepare` runs the same function again:

```json
{"version": 1, "run_id": "<run>", "mode": "drill",
 "backup": {"file": "binlog.000012", "position": 1157, "gtid_executed": "<server uuid>:1-40"},
 "inputs": {"snapshot": {"path": "/protected/night/frozen.sha256", "sha256": "<SHA-256 of that file>"},
            "import": {"path": "...", "sha256": "..."}, "verify_fields": {"path": "...", "sha256": "..."},
            "migrate_status": {"path": "...", "sha256": "..."}, "complete_schema": {"path": "...", "sha256": "..."},
            "backup_check": {"path": "...", "sha256": "..."}}}
```

Exactly these keys, absolute paths. `backup` is the verified MySQL base's
coordinate with its `gtid_executed` (the receipt's `backup`); with the
waiver it is the coordinate the operator recorded after the copy load. Each
input is read again (a regular file, never through a final link, at most
1 MiB) and must still have the recorded digest. Then, each refused with its
own message:

| Input | Must hold |
| --- | --- |
| `snapshot` | `sha256sum` of the frozen snapshot: one line, the digest, a space and a space or `*`, the path. Its digest is the frozen snapshot (drill: `S_frozen`) |
| `import` (`migrate.py import-empty`'s `result.json`) | `mode` `import-empty`, `import_verified` true, `source_changed` false, `target_server_uuid` = `mysql.target_uuid`, `target_database` = `mysql.database`. Its `source_sha256` is the forward-parity snapshot (cutover: the frozen one; drill: the copy's `S_copy`) |
| `verify_fields` (`verify-fields.php`'s stdout, JSON lines) | The last line: `result` `all-fields-verified`, `field_mismatches` 0, `source_sha256` = the import's, the target's server and database |
| `migrate_status` (`migrate:status --pending`'s stdout) | Laravel 12's `INFO No pending migrations.`, whitespace aside |
| `complete_schema` (`complete-schema.php`'s stdout) | `verified` and `migration_ledger_unchanged` true, `server_uuid` = the target |
| `backup_check` (`final_backup_check.py`'s result) | `result` `frozen-base-publications-verified`, `release` = the pinned `release_sha`, `frozen_at` not before the journal's hold, `sqlite.snapshot_sha256` = the snapshot record's digest, `mysql.identity` = the target, `mysql.database`, `mysql.pitr` (`binlog_file`, `binlog_position`) = `backup`. `null` exactly when `mysql.offsite_waiver` (drill only) |

The manifest's `run_id` is the pinned run's and its `mode` is
`mysql.mode`; cutover mode needs one snapshot (the record, the import and
verify-fields; with the backup check, the SQLite publication too). Drill
mode (design 7.1) binds forward parity to `S_copy` and the publication to
`S_frozen`. `check_inputs` returns `mode`, `offsite_waiver`,
`snapshot_sha256` (forward parity: what `evidence()` returns),
`frozen_snapshot_sha256`, `backup` and the input digests, for D11's report.

**The switch record** (`paths.switch_record`, root 0600): `{"version": 1,
"run_id", "values", "env_sha256", "switch_at"}`. `values` holds each switch
key's SQLite value, the raw text after the first `=` of its line
(`envfile.values`), or null when the SQLite `.env` has no line for it: only
the switch keys, never a copy of `.env` (no `APP_KEY`; critic RC-L4,
HO-L10). `env_sha256` is `envfile.digest(<SQLite .env>, night_flags)`, the
SHA-256 of the file with the night-flag lines left out, so a flag the night
turns does not make the record unverifiable. A new record is taken only from
a `.env` that reads `DB_CONNECTION=sqlite` (else "switched by hand"). An
existing record is used only when it is this run's and restoring its values
into the current `.env` (`envfile.assign`) gives `env_sha256`, whether the
file is still the SQLite one or already switched; another run's record is
never used or replaced. `switch_at` is null until step 8, then the Unix time
of the MySQL config build (the MySQL reopen compares worker start times with
it, design 3.6 step 11); a rerun moves it to its own build. The record stays
until `retire`.

**The `.env` switch** (`envfile.py`, beside `adapter.py`). Lines are read
exactly as the identity probe reads them (its `ENV_LINE`) and every switch
key is denylisted, so the switched file has the same `config_sha256` and is
never refused by the probe (review A2-11), and restoring the recorded values
gives back the original bytes. A line the probe refuses, a switch key on two
lines, a value that is not UTF-8 or holds NUL, a file that does not end with
a newline when a line must be appended, a `.env` with a second name (a hard
link: the rename would leave the SQLite bytes under the other name) and an
app root reached through a symbolic link (`replace` opens the folder without
following one, while the identity probe, `live_config` and the build follow
it) are refused at step 5 (`envfile.require_switchable`, and `envfile.values`
for a new record), before the switch record is written (review D10R-6). The secret file and the denylist checks
(step 6) come after the record and before `.env` changes; the record then
still verifies against the unchanged `.env`, so a rerun after the fix
proceeds. P2 confirms read-only that `/var/www/backend` is a directory and
its `.env` has one link and ends with a newline.
`replace` writes a temporary `.env.repro-<32 hex>` in the app root, gives it
the old owner, group, extended attributes (the backup account's
`system.posix_acl_access`) and mode, fsyncs it, renames it over `.env` only
while the name still holds the very file that was read with the expected
bytes, fsyncs the folder and reads the result back (E5). The secret file
`mysql.switch_secrets.DB_PASSWORD` must be root's in a root chain, mode
0600, and hold exactly `DB_PASSWORD=<16 to 256 characters of A-Z a-z 0-9 .
_ ~ + / = ->`; the password is never printed.

**The receipt** (step 10): exactly the keys of the records table above,
root 0600, written after `paths.activity` is removed: `server_uuid` =
`mysql.target_uuid` (the scan read it), `backup` = the manifest's,
`armed_from` = the scan's `until` (`file`, `position`). A rerun never erases
a latch of the current receipt: step 4's reading then reports business
activity and refuses before step 10 runs.

**What a crash leaves** (the markers `seal_sqlite` reads, D12;
`assert_markers` in `test_adapter_mysql.py` checks each):

| Stopped after step | On disk |
| --- | --- |
| 1 to 4 | Nothing changed (app sessions may have been ended) |
| 5, 6 | This run's switch record, `switch_at` null; `.env` still SQLite |
| 7 | `.env` switched (each switch key reads its switch value); `config.php` still SQLite |
| 8 | `config.php` measures the MySQL pin; `switch_at` set |
| 9 | FPM restarted on the MySQL config (no file: `try-reload-or-restart` covers a running and a stopped FPM) |
| 10 | This run's receipt; the latch removed |
| 11 | As after 10 (the checks are kept in memory; the token check's folder is removed, see below) |
| 12 | This run's evidence report (`paths.evidence_report`); the runner then journals `evidence()` and goes `ready` |

A controller killed inside a step (`PrepareHost.kill_inside`) can also leave:

| Killed | On disk |
| --- | --- |
| In step 7, before the rename | As after step 6, plus `.env.repro-<32 hex>` in the app root: the whole switched `.env`, the app password included, with `.env`'s owner, group, mode and ACL. The next `envfile.replace` removes it, and so does `seal_sqlite` (below) |
| In step 8, after the rename | As after step 7, but `config.php` measures the MySQL pin from the cache; `switch_at` still null |
| In step 8, during the build | As after step 7, plus `.config.php.repro-<32 hex>` (removed by the next build) |
| In step 11, during the token check | As after step 10, plus `/run/repro-token-check-<random>/config.php`: the check account's private config cache, its password included, owned by the web user in a 0700 folder. The next token check removes every such folder first; a reboot empties `/run` |

The runner records an interrupted `prepare` as pending and refuses a retry
under that hold. Use the verified pre-write `abort` and reopen SQLite, or
let the watchdog run that recovery. A later attempt starts with a fresh hold.
Direct adapter tests repeat individual prepare steps to verify idempotence
and the states that the rollback must recover.

Not yet here:
- D14: `cli.py prepare`.

## `prepare` checks, the evidence report and `evidence()` (design 3.2 steps 11 and 12, 3.3, 5.3, 7.1, D11)

**Step 11, `checks`.** Every command is checked before the first one runs,
so a profile, a smoke command or a check account that would be refused runs
nothing. Application PHP never runs as root: `cutover:smoke` refuses root
only after artisan has bootstrapped, which may already have written
root-owned files where `www-data` writes (wave 1). Then, in this order, the
first failure refuses (the run stays `frozen` with its pending `prepare`, no
report is written):

1. **Per-profile engine probes.** `paths.wrappers` (the live
   `wrappers.json`) is read through the protected-file check and each
   profile through `admit.load_profile`, so a root user or group is refused
   as `admit.py` refuses it. Profiles are grouped by the context `admit.py`
   gives their writer: user, group and its supplementary groups
   (`os.getgrouplist`), cwd, umask, environment (`admit.environment`), and
   the php and artisan they run. Each distinct context runs `<php>
   <app_root>/artisan cutover:smoke --engine-only --json` once, started as
   `admit.py` starts the writer (`Popen` with `user`, `group`,
   `extra_groups`, `umask`, `cwd`, that environment plus the controller's
   child tag), in the order of each context's first profile name. A profile
   whose argv is not `<php> <app_root>/artisan ...` is a shell script; it is
   probed with `/usr/bin/php` and the app's artisan, for its user and cwd,
   and shares the context of any artisan profile with the same values. The
   report must be mode `engine-only`, `ok`, no problems, exit 0, a process
   that ran as the context's user, group, umask and cwd (never euid 0), and
   engine `mysql`, `mysql.database`, `mysql.target_uuid` and
   `live_config.route_sha256(route_values)` equal to the policy's
   `mysql_live_config_sha256`. A refusal names the context's profiles.
2. **FastCGI probe.** Waits up to `adapter.FPM_SOCKET_SECONDS` (15 s, within
   the deadline) for `fpm.socket` to listen (step 9 does not wait for it),
   then `fastcgi.engine_probe(fpm.socket, app_root)`: `ok`, no problems, the
   same engine, database, server and route hash. OPcache's state, the pool's
   SAPI and PID are recorded, not checked.
3. **Read-only smoke** (`commands.smoke_readonly`). Each command must begin
   `<runuser> -u <user>` with `web_user` or `app_owner` (`config_build.RUNUSER`,
   `/usr/sbin/runuser`). After an optional `-g <group>` and the required
   `--`, the direct form runs `/usr/bin/php <app_root>/artisan cutover:smoke --read-only --json`.
   The shell form runs `/bin/sh -c 'cd <app_root> && umask 0022 && exec /usr/bin/php artisan cutover:smoke --read-only --json' repro-smoke`.
   The `cd` and four-digit octal `umask` steps can appear in either order.
   Each of the two distinct non-root user IDs runs once. Each report must be mode `read-only`,
   `ok`, exit 0, a process of that user (and of the `-g` group when given),
   the same route values, `client_id` and `shoot_id` equal to `host.json`
   `smoke`, `client_read`, `shoot_read` and `encrypted_value_decrypts` true,
   `smoke_queue` equal to `database:cutover-smoke` and integer `smoke_queue_pending` 0.
4. **Token check** (design 3.3). The check account comes from
   `mysql.token_check_defaults` (root-only, mode 0600, a `[client]` section
   with `user=repro_token_check` and `password=` of 16 to 256 characters of
   `A-Z a-z 0-9 . _ ~ + / = -`; anything else is refused before step 11 runs
   anything, the password never quoted). Folders a crash left are removed
   (here, and by a rollback's `seal_sqlite` step 9);
   then a fresh 0700 folder `adapter.TOKEN_CHECK_PARENT`/`repro-token-check-<random>`
   (`/run`) is handed to `web_user`, and two commands run as `web_user`:
   `runuser -u www-data -- /bin/sh -c 'umask 0077 && cd <app_root> && exec env APP_CONFIG_CACHE="$1" /usr/bin/php artisan config:cache' repro-token-check <folder>/config.php`
   with `DB_USERNAME` and `DB_PASSWORD` of the check account in its
   environment (Laravel keeps the process environment over `.env`; never on
   a command line), then the same with `db:writer-policy-check` and no
   account in the environment (the command prints JSON and has no `--json`
   option). The built cache must name the check account; the check must
   print `problems: []`, engine `mysql`, `writer_policy` true and
   `token.acquired` true, exit 0. The folder is removed afterwards, also
   after a failure.
5. **MySQL reading and `gate.check`.** One probe reading (exit 3 retried as
   in step 4): the target's server and database, gate `closed`, `writable`,
   `business_activity` and `foreign_activity` false, and `receipt` equal to
   the SHA-256 of this run's receipt (a probe that did not arm on it reads
   no activity whatever happened). Then `gate.check` (review B2-1): exit 0,
   the gate `closed`, and the gate account's read-only self-check logged in
   as `repro_cutover_gate@localhost` with exactly its `accounts.sql` grants.

**Step 12, `report`.** `evidence.build` then `paths.evidence_report`, root
0600, written atomically as canonical JSON (`evidence.encode`: keys sorted,
no spaces, ASCII, one final newline). Besides the checks it holds what
step 2 bound (mode, waiver, both snapshots, the backup coordinate, every
input's path and digest), the receipt's SHA-256 and `armed_from`,
`switch_at`, and the freeze's FPM drain record from the journal's last
`freeze` entry (`fpm_drain`, D07/D08), which must be one the freeze goes on
after; a freeze journalled without one refuses.

**The evidence report.** The contract the drill harness (D18) and
`cli.py status --evidence` (D14) read: exactly these keys, at the top level,
in `checks` and in each check record (`evidence.REPORT_KEYS`,
`CHECK_KEYS`, `CHECK_RECORD_KEYS`, `RESULT_KEYS`; `test_evidence.py` checks
this block against them). `profile_probes` and `readonly_smoke` are lists, one
record per context or user; the others are one record. Every record has
`ok` true (a failed check writes no report). `env` lists the profile's own
environment keys, never their values. Values below are examples.

<!-- evidence-report-contract -->
```json
{"version": 1, "run_id": "<run>", "mode": "drill", "offsite_waiver": false,
 "snapshot_sha256": "<forward parity: S_copy in a drill, the frozen snapshot in a cutover>",
 "frozen_snapshot_sha256": "<S_frozen>",
 "backup": {"file": "binlog.000012", "position": 1157, "gtid_executed": "<server uuid>:1-40"},
 "receipt_sha256": "<SHA-256 of the receipt file>", "armed_from": {"file": "binlog.000012", "position": 5253},
 "inputs": {"snapshot": {"path": "/root/night/frozen.sha256", "sha256": "<SHA-256>"},
            "import": {"path": "...", "sha256": "..."}, "verify_fields": {"path": "...", "sha256": "..."},
            "migrate_status": {"path": "...", "sha256": "..."}, "complete_schema": {"path": "...", "sha256": "..."},
            "backup_check": {"path": "...", "sha256": "..."}},
 "switch_at": 1764970000,
 "fpm_drain": {"purpose": "freeze", "outcome": "drained", "active_processes": 0, "waited_seconds": 5.2, "polls": 21,
               "problem": null},
 "checks": {
  "profile_probes": [{"profiles": ["cubicasa-poll", "scheduler", "studio", "voice-realtime"], "scripts": ["cubicasa-poll", "studio"],
                "user": "maverick", "group": "www-data", "cwd": "/var/www/backend", "umask": "0002", "env": [],
                "engine": "mysql", "database": "repro_dashboard", "server_uuid": "<target>", "route_sha256": "<MySQL pin>",
                "ok": true}],
  "fastcgi": {"socket": "/run/php/php8.3-fpm.sock", "engine": "mysql", "database": "repro_dashboard",
              "server_uuid": "<target>", "route_sha256": "<MySQL pin>", "sapi": "fpm-fcgi", "pid": 4242,
              "opcache": {"enabled": true, "start_time": 1764970003}, "ok": true},
  "readonly_smoke": [{"user": "www-data", "group": "www-data", "engine": "mysql", "database": "repro_dashboard",
                      "server_uuid": "<target>", "route_sha256": "<MySQL pin>", "client_id": 4101, "shoot_id": 9201,
                      "client_read": true, "shoot_read": true, "encrypted_value_decrypts": true,
                      "smoke_queue_pending": 0, "smoke_queue_paused": false, "ok": true},
                     {"user": "maverick", "group": "www-data", "engine": "mysql", "database": "repro_dashboard",
                      "server_uuid": "<target>", "route_sha256": "<MySQL pin>", "client_id": 4101, "shoot_id": 9201,
                      "client_read": true, "shoot_read": true, "encrypted_value_decrypts": true,
                      "smoke_queue_pending": 0, "smoke_queue_paused": false, "ok": true}],
  "token_check": {"account": "repro_token_check@localhost", "user": "www-data", "engine": "mysql",
                  "writer_policy": true, "token_acquired": true, "problems": [], "ok": true},
  "mysql": {"server_uuid": "<target>", "database": "repro_dashboard", "gate": "closed", "writable": false,
            "business_activity": false, "foreign_activity": false, "receipt": "<receipt_sha256>", "mode": "measured",
            "gate_account": {"current_user": "repro_cutover_gate@localhost", "problems": []}, "ok": true}},
 "results": {"forward_parity": true, "final_schema": true, "readonly_smoke": true, "sqlite_backup_offsite": true,
             "mysql_backup_offsite": true},
 "written_at": 1764970060}
```

Rules `evidence.parse` enforces besides the keys: canonical bytes (another
layout of the same document refuses, so `report_sha256` is reproducible);
`mode` `drill` or `cutover`; `offsite_waiver` only in `drill`, and then
`inputs.backup_check` null, else present; in `cutover` `snapshot_sha256`
equals `frozen_snapshot_sha256`; every route check (profiles, FastCGI, smokes) reads one and the
same engine `mysql`, database, server and route hash, the MySQL reading's
server and database; `checks.mysql.receipt` equals `receipt_sha256`;
`results` follow from the rest (`evidence.results`): `forward_parity` (the
import and verify-fields inputs bound), `final_schema` (migrate:status and
complete-schema bound), `readonly_smoke` (every check `ok`), and both
off-site results (the final backup check bound, or the waiver in drill
mode: in a waived drill both are true by the waiver, design 7.1).

**`evidence()`** (called by the runner at the end of `prepare` and again in
`promote`, which requires the two records equal). The record is a pure
function of the report's bytes (`evidence.record`): `snapshot_sha256` (forward
parity; drill: `S_copy`; cutover: the frozen snapshot),
`report_sha256` = SHA-256 of the whole file (so Approval 2 binds it),
`binlog_file` and `binlog_position` = `backup` (the receipt's), and the five
results. Before it returns, what the report binds is read again, changing
nothing: this run's ID, `host.json`'s mode and waiver, the FastCGI record's
target and the MySQL pin; the night's inputs bound once more by
`check_inputs` (each re-read and re-hashed; the free-disk floor too); the
receipt unchanged (its SHA-256, `run_id`, `backup`, `armed_from`); the
journal's freeze drain record equal to the report's. Then the live
preconditions, which raise and never change the record (design 3.3, review
C2-7): a MySQL reading as in step 11 point 5 (reachable after the retries,
closed, nothing writable, no business or foreign activity, armed on this
receipt), `gate.check` with the gate account's self-check (review B2-1), and
the live config engine `mysql` from the cache with the MySQL pin. With
MySQL down, `observe()` still reads not writable and no business activity
while `writes_possible` is false (the run stays `frozen`/`ready` for the
watchdog), but `evidence()` refuses, so `promote` refuses before it writes
`writes_possible`.

**`check_evidence(night, *, write=False)`** (`cli.py check-evidence
[--write]`, design 3.2 and 3.10; the CLI is D14's). Only in a `frozen` run
whose `writes_possible` is false. `night` is the night's input directory, an
absolute path (no NUL, no `..`), holding one file per input
(`adapter.NIGHT_FILES`) and the coordinate (`adapter.BACKUP_COORDINATE`):

| Input | File in the night's directory |
| --- | --- |
| `snapshot` | `frozen.sha256` (`sha256sum` of the frozen snapshot) |
| `import` | `import-result.json` (`migrate.py import-empty`'s `result.json`) |
| `verify_fields` | `verify-fields.jsonl` (`verify-fields.php`'s stdout) |
| `migrate_status` | `migrate-status.txt` (`migrate:status --pending`'s stdout) |
| `complete_schema` | `complete-schema.json` (`complete-schema.php`'s stdout) |
| `backup_check` | `final-backup-check.json` (`final_backup_check.py`'s result); absent exactly with the waiver |
| the backup coordinate | `backup-coordinate.json`: exactly `{"file", "position", "gtid_executed"}`, the verified MySQL base's binary log coordinate and `gtid_executed` (the base's own receipt has no GTID set; with the waiver, the coordinate recorded after the copy load) |

It builds D10's manifest from them (each file read as `check_inputs` reads
it and hashed; another missing input refuses), binds it with `check_inputs`
exactly as step 2 will, and runs `gate.check` (exit 0, closed, the gate
account's self-check clean). With `write` it writes `paths.evidence_inputs`
(root 0600) and nothing else; a refusal writes nothing and leaves an older
manifest as it was. It returns what the CLI prints: `{"version": 1,
"manifest", "bound", "gate", "written"}` (`bound` is `check_inputs`' return,
`gate` is `gate.check`'s output; no secret in either). `check_inputs`
refuses with `UnsafeCutover`, never another exception, a JSON input nested
too deep for the parser and a path with NUL (review D10V-3).

What D14 calls:

- `check-evidence --inputs DIR [--write]` (the flag D18's harness proposes):
  under the controller lock (`journal.acquire()`, read-only on the journal),
  `adapter.check_evidence(DIR, write=...)`; print the returned object as JSON; an `UnsafeCutover` is
  exit 1 with its message.
- `prepare`: `runner.perform("prepare")` (steps 1 to 12 and `evidence()`).
- `status --evidence`: the bytes of `paths.evidence_report` (root 0600, read
  through `guard.read_protected`), checked with `evidence.parse` and bound to the
  current run ID and the SHA-256 in its prepared journal evidence.
- `approval-template --phase promote`: before issuing, `adapter.evidence()`
  (the live preconditions and the B2-1 self-check) and
  `dataclasses.asdict(record) == state["evidence"]`, else refuse.

Tests: `test_evidence.py` (any POSIX system, any user) holds the report's
contract, `evidence.record`, the waiver rule, step 11's parsers and the
D10V-3 refusals of `check_inputs`. `test_adapter_mysql.py`'s `EvidenceTests`
(Linux root, the scripted probe and a scripted `gate.check`) run step 11 and
12, `evidence()` and `check_evidence` on `PrepareHost`, whose fake `php`
answers `cutover:smoke` and `db:writer-policy-check` from the config the
process would load and records its context, and whose `FpmStandIn` answers
the FastCGI probe from the live `config.php`. `EvidenceFixtureTests` run
`evidence()` and `check_evidence` against D04's fixture with the real probe
and the real `gate.check` (a foreign write, an open gate, a locked gate
account).

## `seal_sqlite`, the full undo (design 3.5, D12)

`adapter.seal_sqlite(lease, deadline)` runs inside every rollback
(`runner._rollback`: `abort`, the watchdog's tick at 0:45, an expiry timer's
conditional abort), after the runner closed admissions, ran the drain with
purpose `rollback` (FPM stopped) and drained the writers, and before it
publishes SQLite authority. It undoes whatever part of `prepare` ran,
whether `prepare` never ran, stopped between or inside any step (the two
tables above), or finished. The steps are `adapter.SEAL_SQLITE_STEPS`, one
method `_seal_sqlite_<name>` each; before each, the lease must still be
current and the deadline not passed.

| Step | Name | What |
| --- | --- | --- |
| 1 | `writers_drained` | `lease.require_writers_drained()` |
| 2 | `gate` | `gate.close_gate` (design 2.3): a closed gate with no app session needs no login; app sessions are ended; an open or partial gate is closed. Nothing listening (`GateUnreachable`): skipped, and `gate_close_skipped` recorded, only while the journal's `writes_possible` is exactly false (else refused). Any other refusal (an unrecognised state) stops the rollback here |
| 3 | `env` | The `.env` (below) |
| 4 | `config` | `config_build.build(config, expected_engine="sqlite", expected_sha256=<SQLite pin>, deadline=...)` when step 3 restored `.env`, the live config is the `.env` fallback (a missing or refused `config.php`, design 4.7), or this run's switch left a cache off the SQLite pin. A failed build leaves `config.php` as it was |
| 5 | `fpm` | `commands.fpm_reload` (`systemctl try-reload-or-restart`, never a plain `reload`), wherever this run's switch record exists or steps 3 and 4 changed something: a running FPM reloads onto the SQLite config, a stopped one stays stopped |
| 6 | `verify` | The live config: engine `sqlite`, source `cache`, and the SQLite pin wherever this run switched or step 4 built; the `.env`: this run's record's values (every switch key), or no MySQL (below); the identity probe's `config_sha256` equal to the pin's |
| 7 | `unseal_proof` | `paths.unseal_proof` (below) |
| 8 | `unseal` | `sqlite_seal.unseal` |
| 9 | `markers` | The ingress open marker and `paths.scheduler_release` removed, and any `repro-token-check-*` folder a killed step 11 left under `adapter.TOKEN_CHECK_PARENT` (it may hold the check account's password, and a rolled-back run has no next step 11 to remove it; a symbolic link of that name is left alone) |

Every refusal comes before step 8, so SQLite is never unsealed while `.env`
or the live config routes to MySQL (review D10R-1): the run stays in its
held phase with its pending `abort`, SQLite sealed, no authority published,
and the next tick retries. The receipt is not touched: it stays armed until
the SQLite reopen retires it (design 3.7), so the runner's publication
after this step still reads real business activity (critic RC-L10). The
switch record and `sealed_hash` stay until `retire`.

**The `.env`** (step 3). With any run's switch record, first the
temporary files an interrupted `envfile.replace` left are removed
(`envfile.remove_leftovers`, review D10R-5), and the `.env` must exist and
be a regular file whose switch keys `envfile.values` can read (one line each);
otherwise the rollback stops (review D10V-1).
- This run's record: `envfile.assign(<.env>, record values)` sets each
  switch key back to its SQLite value (removing the keys that had none) and
  keeps every other line, the night flags included; the result's
  `envfile.digest` without the night flags must equal the record's
  `env_sha256` (else "does not match the .env": the record or the file was
  edited); if it differs from the current file, `envfile.replace` writes it
  (atomic, owner, group, mode and ACL kept). With no night flag turned
  since `prepare`, the `.env` is then the SQLite file byte for byte.
- Another run's record is never used or replaced, and neither does it stop
  a rollback by itself: this run's `prepare` refuses at step 5 with another
  run's record, so nothing of this run was switched.
- No record of this run: the `.env` (when there is one) must not name
  another `DB_CONNECTION` than `sqlite`, and the live config's engine must
  be `sqlite`; otherwise the `.env` or `config.php` was switched by hand,
  nothing records the SQLite values, and the rollback stops ("switched by
  hand"). Step 6 checks the same again.

**A cache off the pin without a switch.** Without a switch record of this
run, a parsed SQLite `config.php` whose route hash is not the SQLite pin is
left as it is (design 3.5 step 4 would rebuild it): the publication
measures it, the guard admits nobody on it, and `refresh` measures again
once the pinned cache is back (`test_adapter.py`
`test_publication_records_measured_hash_and_identity`). Only a missing or
refused cache is rebuilt there.

**The rollback's record** (`paths.unseal_proof`, root 0600; the "seal
record" of design 3.5 step 2 and 3.7), written on every pass of step 7:

```json
{"version": 1, "run_id": "<run>", "hold_id": "<journal hold>", "started_at": 1764000000,
 "database": "/var/www/backend/database/database.sqlite",
 "sha256": "<the file now>", "sealed_sha256": "<paths.sealed_hash sha256>", "unchanged": true, "problem": null,
 "gate": {"before": "closed", "revoked": {}, "killed": []}, "gate_close_skipped": false, "at": 1764000300}
```

`sha256` is `sqlite_seal.file_sha256` of the sealed file before it is
unsealed. `sealed_sha256` is `paths.sealed_hash`'s, only when that record is
this run's, this hold's (`hold_id`, `started_at`) and this `database`'s;
otherwise both it and `unchanged` are `null` and `problem` says why (a
rollback from `holding` has no freeze). A file that changed after the freeze
(`unchanged` false) is recorded, printed as one `WARNING` line on stderr
(the watchdog's journal), and does not stop the rollback: SQLite is still
the only copy with every confirmed write. `gate` is `gate.close_gate`'s
result (account and privilege names, session IDs; no secret), `null` with
`gate_close_skipped` true when nothing listened. The SQLite reopen (D13)
must then require a reachable, closed reading with no business activity
(design 3.7 step 1). The drill's pass mark is `sha256` equal to
`sealed-hash.json`'s.

**PHP-FPM after a rollback** is stopped: the rollback's drain stops it
(`drain_fpm`'s last command), and step 5's `try-reload-or-restart` leaves a
stopped FPM stopped. The SQLite reopen starts it (`start_workers`, design
5.4) and probes it through FastCGI before the marker (design 3.7). There is
no FastCGI reading of FPM between the rollback and the reopen.

**A rollback under a short deadline.** The runner gives every rollback at
least 10 minutes (`ROLLBACK_SECONDS`, even for a late watchdog), so a short
deadline only comes from host commands before the drain that used most of
it (`close_admissions`, `stop_workers`, each bounded by `command_seconds`).
`drain` checks the time left when the FPM drain would start (after
`stop_workers`): with less than
`fpm.drain_seconds` plus 12 s (what `fpm_drain.py` needs inside
`command_seconds`) a rollback does not start `fpm_drain.py`, whose kill at
the deadline would leave the FPM stop unrun and the rollback failing until
the next tick; it runs the stop at once and records `{"purpose":
"rollback", "outcome": "skipped", "waited_seconds": 0, "polls": 0,
"problem": "<n> s were left ..."}` in the `abort` history entry; it still
waits, as every drain does, until no writer process remains. nginx
holds every request by then (review A2-2), and a request the stop ends
mid-write is rolled back by SQLite (design 5.4). A freeze is unchanged: its
deadline is the 0:45 point or `max_drain_seconds`, and `fpm_drain.py` killed
at the deadline refuses the freeze before the FPM stop (the hold stays and
the rollback follows).

**Host commands that start their own session.** `_execute` and
`config_build` run every host command in its own session and process group
and kill the whole group on a timeout or an interrupt. A command that calls
`setsid` itself (or daemonizes into a new session) would leave that group
and outlive the kill. None does: the queue commands, the config build,
`fpm_drain.py`, `systemctl` and the identity and MySQL probes stay in the
group they were started in (`runuser` keeps its child in its own group and
session; D19 checks that read-only on production's util-linux). A new host
command must keep it that way.

**What a crash inside the undo leaves.** Each step is safe to run twice: a
closed gate needs no login, a restored `.env` is not replaced again, a
cache on the SQLite pin is not built again, `try-reload-or-restart` and the
unseal are idempotent, and the proof is rewritten. A rollback killed
anywhere is finished by the next `abort` or tick from what is on disk: a
kill inside step 3 before the rename leaves `.env.repro-<32 hex>` (the
restored SQLite `.env`), which the next pass removes; after the rename,
`.env` is SQLite and the cache still MySQL, which the next pass builds
again (this run's cache off the SQLite pin); a kill inside step 4 leaves
`.config.php.repro-<32 hex>` (removed by the next build) or the SQLite
cache. Before step 8 SQLite stays sealed; after it, `.env` and the cache
already read SQLite on the pin.

Tests: `SealSqliteTests` in `test_adapter_mysql.py` (the scripted probe)
and `SealSqliteFixtureTests` (the real gate close and the real probe's
armed receipt on D04's fixture; fixture tests as `PrepareFixtureTests`
are: they skip without it, `--fixture-check` names them, and they fail
when `REPRO_REQUIRE_PROBE_FIXTURE=1`). `UndoHost` extends `PrepareHost`
(its `ready()` runs D11's steps 11 and 12 and `evidence()` unpatched; its
MySQL probe names the receipt it armed on, as `PrepareHost`'s does): a
`systemctl` stand-in that keeps `php8.3-fpm.service`'s state
(`try-reload-or-restart` reloads only a running FPM and records the live
config it read), `drain_fpm` as `fpm_drain.py`'s stand-in then the FPM
stop, a scripted `gate.close_gate` (exit 3: not listening), a probe that
reads business activity only while the receipt is armed, a runner at a
chosen clock (the watchdog at 0:45, a late tick after 1:00), and a
rollback stopped between (`crash_rollback_after(n)`) or killed inside
(`kill_rollback_inside(point)`) seal_sqlite's steps.

## Telnyx while held (design 5.2)

While the hold is in force, nginx answers 503 before Laravel and the freeze
drains the app's PHP-FPM, so the app's own maintenance capture never sees a
text. `repro-cutover-hold.conf` therefore sends exactly the Telnyx webhook
paths (`$repro_cutover_telnyx` in the gate file: messaging, status, voice and
shared MMS with a UUID) to the hold-time capture, in both server blocks,
until the open marker exists. The rewrite lives in the one hold include, so
a server block cannot have the hold without the capture, and a gate file
without the Telnyx map fails `nginx -t`. The v1 `repro-cutover-telnyx.conf`
must not be installed.

`repro-telnyx-capture.service` runs `capture.php` in its own PHP-FPM master
(pool `repro-telnyx-capture`, `www-data`, 4 static children, socket in
`/run/repro-telnyx-capture`). The cutover never drains or stops it, so it
must not appear in `drain_fpm`, `stop_workers` or `writer_processes`.
`capture.php` boots no kernel and opens no database: it loads the release's
autoloader and cached config, checks the request as
`CaptureTelnyxDuringMaintenance` does (fresh timestamp, Ed25519 signature,
event ID) and saves it with the receiver's `TelnyxMaintenanceSpool` into the
spool `telnyx:replay-spool` reads. The spool path, signature key, tolerance
and body limit all come from the app's cached config; `capture.json` names
only the release and the open marker. Under the spool's capture lock it
checks the marker again and refuses once it exists.

| Request while held | Answer |
| --- | --- |
| Signed text, status update or shared MMS, saved and flushed to disk | 200 `{"status":"captured"}` |
| Signed voice event, saved for the missed-call list | 503 with `Retry-After` (provider retry and failover unchanged) |
| Unsigned, stale, without an event ID, not saved, or the marker appeared | 503 `{"captured":false}` with `Retry-After` |
| Capture down, failed (500), `capture.php` missing or unreadable (PHP-FPM 404 or 403), or a body over 1 MiB | 503 `{"admission_held":true}` with `Retry-After` from nginx |
| Anything else, or `/__repro_telnyx_capture` called directly | 503 `{"admission_held":true}` with `Retry-After` |

With the marker, Telnyx reaches the app in both server blocks. A direct
request for `/__repro_telnyx_capture` never reaches the capture, marker or
not: nginx refuses it as internal (404) and the capture location maps that
to the same 503 (design 5.2 said 404; the 404 and 403 mapping is what keeps
a missing `capture.php` from reaching Telnyx as a bare 404). The reopens
(design 3.6 and 3.7, built in later
slices) replay the spool through `admit.py telnyx-replay` (`www-data`, umask
0002) before the queues resume and again after the marker; the replay waits
for any capture still saving.

## FastCGI client and FPM drain (design 5.3, 5.4)

`fastcgi.py` and `fpm_drain.py` are installed with the controller in
`/opt/repro-cutover-fence/production/` (W1). `php-fpm/repro-status.conf`
goes to `/etc/php/8.3/fpm/pool.d/zz-repro-status.conf` (root 0644); then
`php-fpm8.3 -t`, then one `systemctl reload php8.3-fpm.service` at a quiet
hour. It reopens the `[www]` pool (php-fpm merges a repeated section) and adds
`pm.status_path = /repro-fpm-status` on its own socket,
`pm.status_listen = /run/php/php8.3-fpm-status.sock` (host.json
`fpm.status_socket`). A USR2 reload keeps the master and adds the socket; a
USR2 reload whose config fails the test stops FPM, so the test comes first.

- **`fastcgi.request(socket, params, stdin=b"", timeout=10, max_output=1 MiB)`**
  sends one request to a PHP-FPM Unix socket and returns `Response(status,
  headers, body, stdout, stderr, app_status, protocol_status)`. Its record
  handling is `../probe/test_fpm_engine.py`'s client (experiment E8's), made
  strict: records of another version or request, of another type, a short or
  incomplete end record, data after it, a stream that closes first, output
  over the limit and the deadline (one bound for the whole exchange) refuse.
  A missing socket, or one nothing listens on, raises `NotListening`.
- **`fastcgi.engine_probe(socket, app_root)`** sends
  `<app_root>/scripts/cutover/probe/fpm-engine.php` with a fixed request line
  and nothing else (Laravel reads `$_SERVER` as environment, so a parameter
  could stand in for the pool's own) and returns its report. The caller
  compares `engine` and `live_config.route_sha256(report["route_values"])`
  with the pin. For P8, the installed copy (never a copy in the app tree,
  whose imports of `live_config` and `var_export` would write `__pycache__`
  there and change `artifact_sha256`):
  `/usr/bin/python3 -I -S /opt/repro-cutover-fence/production/fastcgi.py --socket /run/php/php8.3-fpm.sock --app-root /var/www/backend`
  prints `{"version", "report", "route_sha256"}` (exit 0 ok, 2 not ok, 1 no
  report).
- **`fpm_drain.py --config host.json`**, the first command of `drain_fpm`,
  polls the status page every 0.25 s for at most `fpm.drain_seconds` (90 s).
  Idle is two readings in a row with no active process, an empty listen queue
  and the same accepted count: PHP-FPM never counts the listen queue of a Unix
  socket, so a request taken from the backlog shows only as a moved count.
  Each status request is bounded by what is left of the limit. It prints one
  JSON report and exits 0 for every outcome, so its exit status never keeps
  the stop from running: `drained`; `timed_out` (the stop ends what is still
  running);
  `stopped` (nothing listens at the status socket nor at `fpm.socket`); and
  `unmonitored`, when `fpm.socket` listens (a full backlog counts) and nothing
  answers at the status socket on the first reading (the snippet is missing),
  or when the status page answers anything but its JSON document. An
  unmonitored report's `problem` is one of two fixed texts, with no path and
  nothing the socket answered, and one summary line goes to stderr. It exits
  1, with nothing on stdout, when it cannot run as configured: not root, a
  protected file or host config that `load_config` refuses,
  `fpm.status_socket` equal to `fpm.socket` (every status request there is an
  active process), `fpm.drain_seconds` plus 12 s not fitting in
  `command_seconds` (the adapter would kill the drain first), and an
  `fpm.socket` whose connect fails for another reason than no socket, nothing
  listening or a full backlog (a path through a file, say). `load_config`
  refuses the two `fpm` conditions first; `fpm_drain.drain_settings` keeps
  its own copy of each as defence in depth, and `DrainSettingsTests` call it
  directly, since the command line no longer reaches them (review W2I-4).
- **The report** always has the keys `version`, `outcome`, `limit_seconds`,
  `waited_seconds`, `polls`, `pool`, `accepted_conn`, `active_processes`,
  `idle_processes`, `total_processes`, `listen_queue` and `problem`. `pool`
  and the counters are the last status reading's, `null` when there was none;
  `problem` is `null`, the last transient error of a `timed_out` drain, or the
  fixed text of an `unmonitored` one; `polls` counts the status readings, 0
  when there was none. `adapter.drain(..., purpose=...)` parses the first
  `drain_fpm` command's report and records it, `polls` included (review
  A2-3). The controller refuses the freeze on `unmonitored` and on a
  `timed_out` with `polls` 0, because in both the drain could not see the
  requests the stop would end (review W2F-6). A rollback records it and stops
  FPM anyway, because nginx already holds every request then (review A2-2). The
  drain does not prove the snippet: host proof P8 must still show the status
  socket answering and an idle `fpm_drain.py` poll printing `drained`.

`test_fastcgi.py` and `test_fpm_drain.py` test the protocol and the drain's
decisions against scripted servers on any POSIX system: the scripted
`DrainLogicTests` prove the two-reading rule, and `StatusSocketTests` prove
that the last status request ends at the limit and that a pool socket with a
full backlog is not `stopped`. With Linux root, `php-fpm` and `www-data` they
also run private PHP-FPM masters laid out as Debian's, with the snippet in
`pool.d`: a request in flight and one queued behind it are waited for, the
limit is kept and reported, a stopped pool is `stopped`, and a pool without
the snippet or with another `pm.status_path` is `unmonitored`. The
queued-request test proves that a queued request is waited for, not the
accepted-count rule: PHP-FPM takes a queued request as soon as the last one
ends, so no reading lands in between, and the test passes without the rule
(review A2-4). `test_drain_waits_while_short_requests_keep_being_accepted`
sends short requests one after another, so most readings find the pool idle
and only the moving accepted count shows requests are still being taken; it
fails without the rule. `test_route_hash_vectors` also needs `php`, `runuser`, `nobody`
and a `vendor/` that `www-data` can read (the docstring has the command): it
sends `fpm-engine.php` into the pool for every vector of
`testdata/route-hash-vectors.json`, with every path moved under a private
folder, and checks the printed values, their hash against the vector's pin
and `live_config.measure` of the same live file.

## Owner options (the safe value for each)

Every option must be written in `host.json`; none is filled in.

| Option | Safe value | Meaning |
| --- | --- | --- |
| `separate_freeze_approval` | `false` | One approval ("start freeze") covers the hold and the freeze |
| `adopt_external_hold` | `false` | Admissions closed outside the journal are refused, not adopted |
| `require_long_job_predrain` | `true` | Long-job workers must be stopped before the hold (the 9:20 pm step) |
| `max_drain_seconds` | `600` | Longest wait for admitted writers after the hold |
| `unattended_reopen` | `false` | When enabled before hold approval, a watchdog or matching expiry timer that completes prewrite rollback immediately runs the verified SQLite reopen under the same controller lock. The scheduler stays held for reconciliation and explicit release. |

A manual abort leaves admissions held, including when the watchdog finishes an interrupted manual abort.
A stale timer or later tick cannot open a completed rollback. If an automatic reopen check fails,
the controller withdraws admission proof and keeps queues and ingress held. After correcting
the failed check, use the guarded refresh and SQLite reopen procedures below.

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

This adapter covers the SQLite hold, freeze, pre-write rollback with
`seal_sqlite`'s full undo of a `prepare`, `prepare` (steps 1 to 12,
above), `evidence()` and `check_evidence`. `seal_mysql` refuses (C02), so
a `promote` that passes `evidence()` ends in `recovery_required` with the
gate still closed: no CLI action runs `promote` (D14 refuses it in drill
mode), and MySQL is never opened for writes.

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
that marker exists. The deployment fence stays in place until retirement. The guard compares the measured
configuration route hash with the policy pin.

**SQLite reopening.** The controller checks the pinned cache and wrapper profiles,
performs the first replay, starts the workers and checks the FastCGI route. It then
opens ingress, performs the second replay and records activation. The scheduler
release comes last. With `--hold-scheduler`, the operator issues `release-scheduler`
after reconciliation.

**Interrupted or failed reopening.** Use `rehold` to revoke writer authority,
hold ingress and the scheduler, pause queues, stop workers, and drain the writer
lease. Verify the intended SQLite route, then run `refresh` and `reopen`. A caught
failure during replay or activation already starts the hold. The same sequence
also recovers a process killed after it opened ingress. A completed `refresh` is
required before wrapped writers can start again.

**Watchdog run time.** A deadline tick runs the rollback and any approved
automatic SQLite reopen. The service allows 30 minutes (`TimeoutStartSec`). While a tick runs, an
operator command is refused with "Another host operator holds the cutover
lock"; check `systemctl status repro-cutover-watchdog.service` and retry.

## Tests

`test_admit.py`, `test_fences.py` and `test_adapter.py` need Linux root and
run in a disposable container through `../run-linux-tests.sh`. The host
config contract tests in `test_adapter.py` (`HostConfigContractTests`,
`HostConfigQueueTests`) need only a POSIX system and also run as an ordinary
user. The root adapter tests run the queue commands in design 5.1's exact
form through `runuser` as `www-data`, in a fixture app root, with a fixture
program standing in for `/usr/bin/php` (`adapter.QUEUE_PHP`, patched like the
guard's fixed paths); the QA image has no `/usr/bin/php`. The cron and
unit-file tests also run on Windows.
`test_mysql_probe.py` needs Linux root and a disposable MySQL 8.4.13 built by
`mysql/fixture.sh serve` from `accounts.sql` itself (plus a networking and a
`--skip-grant-tables` variant). `run-probe-fixture-tests.sh` builds all three
in disposable `--network none` containers, runs the file as root with
`REPRO_REQUIRE_PROBE_FIXTURE=1` (a fixture test that would skip fails the run)
and removes the containers and volumes again; `--linux-suite` runs
`../run-linux-tests.sh` that way instead. Without the fixtures
`../run-linux-tests.sh` prints one `WARNING` line per file whose fixture
tests would skip, naming them, first and last, and still exits 0: the probe
tests (`test_mysql_probe.py`), the gate tests (`test_gate.py`), the
real-probe test (`test_adapter.py`) and the prepare, evidence and undo tests
(`test_adapter_mysql.py`), each from that file's `--fixture-check`; with
`REPRO_REQUIRE_PROBE_FIXTURE=1` it stops before any suite when any of them
would skip (review D10V-4). The probe file's docstring has the same recipe by
hand. Its accounts and renderer tests run anywhere with bash. `test_queue_flag.py`
needs Linux root for the helper tests; its Laravel tests also need `php` and
the vendor tree at the repository root (mount the locked vendor volume at
`/src/vendor`; a read-only checkout needs an empty `vendor/` folder as the
mount point). They run the real `php artisan queue:pause` and `queue:resume`
through the helper and a real `queue:work` as a member of `www-data`, and are
skipped without vendor. Its sudoers test checks the text only: `visudo -cf`
is not in the QA image and runs at install.
`test_nginx_hold.py` needs nginx (the QA
image has it); its four capture tests also start the capture's PHP-FPM
master from `capture/php-fpm.conf` and need root, as on the host, to hand
the socket to `www-data`. Its harness server blocks allow 100 MiB bodies, so
the capture location's own 1 MiB limit is what the tests prove.
`test_capture.py`'s file and class-setup tests run on any Linux (one runs the
unit's `ExecStartPre` and needs root);
its `CaptureTests` run the real `capture.php` behind nginx and replay a
captured text through `telnyx:replay-spool` into the app's handler, so they
need root, nginx, PHP-FPM and an application checkout whose `vendor/` the
web user can read (the command is in the file's docstring).
`test_cross_slice.py` checks contracts between slices that no single
slice's tests can see: the smoke tables in the example host config and the
sandbox `host.json` against the app's `CutoverSmokeFixture::SMOKE_TABLES`,
and `adapter.CAPTURE_COMMAND_LINES` against the capture's unit and pool.
It is pure Python, needs a POSIX system and also runs as an ordinary user.
`../run-linux-tests.sh` runs it in its production loop, and by default also
runs the sandbox's `MakeConfigTests` (`sandbox/test_sandbox.py -k
MakeConfigTests`, no privileges needed), which fail when the `host.json`
contract drifts from `sandbox/make_config.py`. The sandbox launcher tests
need a disposable `--privileged` container and run only when
`production/sandbox/test_sandbox.py` is passed as an extra argument
(`sandbox/README.md`). Real systemd, Supervisor, nginx reloads
and a host reboot are not exercised here; they need the staging or production
drill (W2).

`test_envfile.py` (the `.env` switch) runs anywhere for its byte tests,
which check the switched and the restored file against
`identity_probe.config_sha256` itself (review A2-11); its `ReplaceTests` need
Linux root (another account's owner and a POSIX ACL written as its kernel
blob). `test_adapter_mysql.py` holds `prepare` (D10) and the harness D11 and
D12 add their tests to: `PrepareHost` builds a whole root-owned host under
`/var/lib` (journal, records, `host.json`, a deploy-user-owned app root with
`.env` carrying an ACL, `config.php`, a WAL SQLite file), runs `init`, `hold`
and `freeze` through the real runner, writes the night's inputs and their
manifest, and records every host effect in one log: recorders for
`systemctl` and `runuser` (the runuser one then runs the real runuser, so the
fake `php`'s `config:cache` runs as the deploy user and writes the config
from `.env`), a stand-in identity probe that measures `config_sha256` from
the live `.env`, and either a scripted MySQL probe (`PrepareTests`, any root
container) or the real `mysql_probe.py` and `gate.py` against D04's 8.4.13
fixture on its shared socket volume (`PrepareFixtureTests`). A prepare can
be stopped between two steps (`crash_after(n)`) or killed with SIGKILL
inside step 7 or 8 (`kill_inside(point)`, a forked controller), for D12's
abort tests. The file's docstring has the parts table and how a later slice
extends it. D12's `SealSqliteTests` and `SealSqliteFixtureTests` run on
`UndoHost` (section "`seal_sqlite`, the full undo").
`../run-linux-tests.sh` runs both files after its production loop, and
`test_evidence.py` (D11, pure) on its own line after them; without
the fixture the prepare, evidence and undo fixture tests skip and `test_adapter_mysql.py
--fixture-check` names them in its own WARNING line before any suite (or
stops the run with `REPRO_REQUIRE_PROBE_FIXTURE=1`, as
`run-probe-fixture-tests.sh --linux-suite` sets it).
