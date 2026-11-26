# Production cutover controller, writer wrappers and fences

Built and tested locally only. Nothing here is installed on the production
host. Installing any of it is a production change that needs the owner's
approval for each action (handoff item W1).

## Layout on the host

| Repository file | Installed as | Purpose |
| --- | --- | --- |
| `../fence/guard.py` | `/opt/repro-cutover-fence/guard.py` | Admission check (fixed path) |
| `../runner.py` | `/opt/repro-cutover-fence/runner.py` | Journal and state machine |
| `cli.py`, `adapter.py`, `admit.py`, `deploy_marker.py`, `sqlite_seal.py`, `mysql_probe.py` | `/opt/repro-cutover-fence/production/` | Controller, host adapter, writer wrapper, fences, MySQL probe |
| `mysql/accounts.sql`, `mysql/fixture.sh` | run once by the administrator (session A1): `fixture.sh render` fills the passwords | The eight MySQL accounts; the probe account's defaults file goes to `/etc/repro-cutover-fence/mysql-probe.cnf` (root 0600) |
| `testdata/route-hash-vectors.json` | not installed (test data) | The route-hash contract and vectors shared by the PHP probes (`../probe/`), the live config module (D03) and its tests (D08) |
| `wrappers.example.json` | `/etc/repro-cutover-fence/wrappers.json` | Writer profiles: user, command, action, engine |
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
| `wrappers.sqlite.example.json` | `/etc/repro-cutover-fence/wrappers.sqlite.json` | `wrappers.example.json` plus `telnyx-replay` and `cutover-smoke` (design 3.8) |
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
(sorting would hide a swap of two definitions), and the lines are sorted and
joined with `\n`. The night flags, the switch and a `DB_PASSWORD` rotation
never change the digest; any other edit does, spacing, quoting, an inline
comment and a carriage return included. Refusals name line numbers, never a
value.

`deploy-meta.json`, `.env` and `auto.cnf` belong to other accounts: each is
opened without following a final symlink and must be a regular file of at
most 1 MiB. The probe uses `identity.json`'s denylist as given; `load_config`,
which `cli.py`, `mysql_probe.py` and `repro-queue-flag` run first, refuses one
that is not the night flags plus the switch keys.

`test_identity_probe.py`: the digest, copy and input-shape tests run on any
POSIX system; the probe tests need Linux root (block device nodes made with
`mknod` in a fake `by-uuid`) and run in `../run-linux-tests.sh`;
`RealFilesystemTests` mount a real ext4 image through two loop devices and run
only with `REPRO_IDENTITY_LOOP_DEVICES=1` in a `--privileged` container
(command in the file's docstring). Host proof P2 compares the output with
`findmnt -no UUID -T`, `stat`, the deploy script's own fingerprint and
`@@server_uuid`. The deploy runs the fingerprint as the deploy user, the probe
as root: `os.walk` skips a folder its user cannot read, so such a folder under
the fingerprinted trees would make the two differ.

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
`mysql.target_uuid`, version not `8.4.x`, `skip_networking` off); 4 when the
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

**Not yet built here (slice D05).** The receipt, the binary log scan and the
activity latches. Until they exist the probe exits 1 whenever
`paths.receipt`, `paths.activity` or `paths.activated` exists, so it never
reports `business_activity: false` after `prepare`.

**Accounts.** `mysql/accounts.sql` is design 2.2 verbatim with `:name`
placeholders instead of passwords; it needs `repro_mysql_control.writer_token`
to exist first (runbook Release step 4, without its generic grant). Render it
with `mysql/fixture.sh render accounts.sql <password-dir>`: one file per
placeholder (`app`, `maint`, `migrator`, `reader`, `check`, `probe`, `gate`,
`monitor`), owned by the caller, mode 0600, 16 to 256 characters of
`A-Z a-z 0-9 . _ ~ + / = -`; nothing is printed unless every placeholder is
filled. The probe account reads only the columns it needs (never
`authentication_string`).

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
`../run-linux-tests.sh` prints a `WARNING` line naming the skipped probe tests,
first and last, and still exits 0; with `REPRO_REQUIRE_PROBE_FIXTURE=1` it
stops before any suite. The probe file's docstring has the same recipe by
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
web user can read (the command is in the file's docstring). Real systemd, Supervisor, nginx reloads
and a host reboot are not exercised here; they need the staging or production
drill (W2).
