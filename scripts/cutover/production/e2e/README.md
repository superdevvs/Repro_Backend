# Local end-to-end drill (build plan D18)

Built and run locally only, in Docker. Never on a production host: the outer
half refuses to run without `/.dockerenv`, and it changes the disposable
container it runs in (below). Every other role of `run_drill.py` (the inside
half and the sandbox services the host commands reach) refuses with exit 2
unless `/.dockerenv` exists and PID 1 is `run-sandbox.sh`'s init for the drill
root it is given, so none of them can act on a host's `/run/php` or ingress
marker. W2a, the same drill in the sandbox on the host, is a production
action that needs the owner's approval and its own recipe.

| File | Purpose |
| --- | --- |
| `run_drill.py` | The drill: the step list, the outer half (container, sandbox roots, config, launches), the inside half (the steps, the harness-local copy load), and the sandbox services the host commands reach (private Supervisor, FPM control, `timer`, `timer-stop`, watchdog loop) |
| `run-drill.sh` | Starts the MySQL 8.4.13 fixture and the privileged QA container, runs `run_drill.py run`, removes every container and volume it made |

## Prepare the offline QA image

Use Git Bash on Windows. Set `REPRO_DRILL_ARCHIVE_REF` to the reviewed commit for acceptance.
The runner streams its tracked files once into a disposable Docker volume, preserving LF line endings
and avoiding per-file Windows bind-mount overhead. The fixture and QA containers mount that volume read-only.
The local QA base image and MySQL image must already be present on this machine.
Supply the approved PyMySQL 1.1.2 wheel. The script checks the same SHA-256 used by
the nightly runner and builds a local derivative with the wheel available read-only
to the import process. Neither drill container has an external network.

```sh
bash scripts/cutover/production/e2e/prepare-drill-image.sh /path/to/pymysql-1.1.2-py3-none-any.whl
REPRO_DRILL_ARCHIVE_REF=HEAD bash scripts/cutover/production/e2e/run-drill.sh --a3-only
```

The second command exercises only the real and reference copy loads and compares
their receipts. Use the default full run for drill acceptance.

Save the console report from the pinned archive with the evidence. The launcher
prints and runs both immutable image IDs. The harness compares every installed
Composer package version and source/distribution reference to the archive's lock,
then records both the lock and installed-manifest SHA-256 values and package count.
The verdict checks receipt count, ordering and status against the selected steps.
Both the full and A3-only runs require the last A3 comparison receipt.

## What it does

The run is the test (design 6.2 for the drill, 7.1 drill mode, 7.3 the
sandbox). `run-drill.sh` starts `mysql/fixture.sh serve` (socket-only, the
accounts from this checkout's `accounts.sql`) in one `--network none`
container and the QA image `--privileged --network none` beside it, with the
checkout read-only at `/src`, the locked vendor volume read-only at
`/vendor-src`, the fixture's volume at `/fixture` and a fresh volume at `/srv`.
There `run_drill.py run`, as root:

1. lays out a sandbox root `/srv/repro-drill-<run>`: the controller copy at
   `binds/opt/repro-cutover-fence` (installed layout), an app copy with
   vendor, a `.env` with a fresh `APP_KEY` and blank provider keys, a
   synthetic SQLite database, the smoke fixtures
   (`cutover:smoke-fixtures --create`), the live SQLite config cache, the
   MySQL defaults files from the fixture, the capture's files, nginx with
   the production gate and hold includes verbatim, the app's PHP-FPM with the
   status snippet verbatim, and the sandbox host commands in `bin/`. The
   database is a new, empty file filled only by the app's own migrations as
   the deploy user: no `*.sqlite*` file of the checkout is copied (git
   ignores them, so a working tree can hold a real one), and the run refuses
   if `database.sqlite` or a `-wal`, `-shm` or `-journal` beside it exists
   before the file is created;
2. writes `host.json`, `identity.json` and the wrapper files with
   `sandbox/make_config.py`, measures the pins with the reviewed controller
   tree's own probes (P2: `identity_probe.py`; P3: `live_config.py` on the
   live cache and on a staged `config:cache` with the switch values), and
   writes `policy.json` with `make_config.py --measured`;
3. launches `sandbox/run-sandbox.sh` with this file's inside half as the
   command. Inside, the boot starts what a host starts: the fixture's socket
   directory bound at `/run/repro-mysql84-native`, the capture's own PHP-FPM
   (`/usr/sbin/php-fpm8.3 --nodaemonize --fpm-config
   /etc/repro-telnyx-capture/php-fpm.conf`), the app's PHP-FPM, nginx
   (`nginx -t` first), a private Supervisor whose stand-in workers are real
   `queue:work` processes on the synthetic database, each through
   `admit.py <profile>`, and the watchdog loop. Then the steps run in order.

Inside, the controller is driven only through the commands the runbook uses:
`cli.py` actions, the installed `live_config.py` and `fastcgi.py`, `admit.py`
profiles and the app's own artisan commands and tools. Nothing imports a
controller module (the copy load's `copy-sql` role imports the release's
`scripts/mysql` importer modules, in a process of its own).
`repro-cutover-run` (D14) is not usable inside the sandbox (`systemd-run` is
the refusing shim), so the drill calls `cli.py` directly
(sandbox/README.md). Every outbound path is blocked twice:
`--network none` and the sandbox's own network namespace. The boot checks
that loopback is the only interface and that a TCP connection to an IPv4
and an IPv6 documentation address fails at once with `ENETUNREACH` (a
timeout, which a live network would also give, fails the step).

## Steps

`run_drill.py list` prints the table below from `STEPS`, the one place that
names each step and the slices it needs. `LANDED` lists the merged slices; a
slice adds itself there when it lands, and its steps then run for real.

| Step | Needs | Budget s | What it proves |
| --- | --- | --- | --- |
| `r1.root` | | | Sandbox root 1 (above) |
| `r1.config` | | | `make_config.py`: host, identity and wrapper files |
| `r1.measure` | | | The pins, measured (P2, P3) |
| `r1.policy` | | | `make_config.py --measured`: `policy.json` |
| `r1.sandbox` | | | The launch and its canary checks |
| `r1.services` | | | Boot; loopback only, no route out; every non-Telnyx route of both server blocks holds (503 `admission_held`, `Retry-After`); an unsigned Telnyx post reaches the capture and is refused |
| `r1.marker` | | | W1: the open marker; `/up` answers 200 through nginx |
| `r1.init` | | | `cli.py init`: planned, deploy marker |
| `r1.init-live-config` | | | `live_config.py`: source cache, hash = policy SQLite pin (review C2-4) |
| `r1.pin-config` | D14 | | `cli.py pin-config` reproduces both pins |
| `r1.rehold` | D13, D14 | 120 | `cli.py rehold` |
| `r1.refresh` | | 60 | `cli.py refresh` (with D13: deploy fence, release removed, queues paused) |
| `r1.reopen-init` | | 120 | `cli.py reopen`: wrapped workers admitted, marker |
| `r1.scheduler-release` | D13 | | The release exists, made last; `admit.py auth-prune` admitted |
| `r1.watchdog-idle` | | | Two watchdog ticks leave the planned journal unchanged |
| `r1.copy-load` | | | The afternoon copy load, harness-local (below): `S_copy` in MySQL, verified, and the night's inputs |
| `r1.predrain` | | | The 9:20 pm long-job pre-drain (media-archives stopped) |
| `r1.approval-hold` | | 60 | `cli.py approval-template --phase hold` |
| `r1.hold` | | 60 | A 30 s request started, then `cli.py hold`: every route of both blocks held, nine queues paused, the request still in flight |
| `r1.capture` | | | A signed test text captured (200, frontend block), a signed test call saved (503 with `Retry-After`, legacy block) |
| `r1.freeze` | | 180 | `cli.py freeze`: the drain waited for the request in flight (`fpm_drain` `drained`), the request finished 200, SQLite sealed, `sealed_hash` = the file |
| `r1.snapshot` | | 180 | Frozen snapshot `S_frozen`: integrity, `frozen.sha256` (the night's snapshot input), = `sealed_hash` |
| `r1.check-evidence` | D11, D14 | 60 | `cli.py check-evidence --write` builds the manifest from the night's inputs; the harness checks what it wrote |
| `r1.prepare` | D10, D11, D14 | 120 | `cli.py prepare`: ready on the MySQL pin, the receipt's `backup` and `armed_from` |
| `r1.prepare-checks` | D11, D14 | | The evidence report: the design 3.2 step 11 checks, value by value (D11 contract below) |
| `r1.approval-promote` | D14 | 60 | Issued promote approval, the 5-minute timer armed in `systemd-run`'s form |
| `r1.timer-abort` | D12, D14 | 180 | The timer's conditional abort rolls back (time-scaled) |
| `r1.probes-sqlite` | D10, D12 | | Every profile context on SQLite with the SQLite pin; PHP-FPM stopped; gate closed and quiet |
| `r1.unseal-proof` | D12 | | `unseal_proof` = `sealed_hash` |
| `r1.reopen` | D13, D14 | 120 | `cli.py reopen --hold-scheduler`; FPM runs again and FastCGI reports SQLite with the SQLite pin |
| `r1.replay` | D13 | | The captured text replayed into SQLite once |
| `r1.release-scheduler` | D13, D14 | | `cli.py release-scheduler` |
| `r1.deploy-fence` | D13 | | The deploy marker stays after the reopen |
| `r2.root` to `r2.reopen-init` | as run 1 | | Second sandbox root and run, the same init sequence (no `pin-config`) |
| `r2.copy-load` to `r2.snapshot` | as run 1 | | Copy load (run 1's copy dropped first), pre-drain, Approval 1, hold, freeze, snapshot |
| `r2.check-evidence` | D11, D14 | 60 | As run 1 |
| `r2.prepare-fails` | D10, D14 | 120 | `cli.py prepare` exits non-zero at `fpm_start` (injected), after the `.env` switch and the MySQL build |
| `r2.watchdog-rollback` | D12 | 180 | A watchdog tick at 0:45 rolls back; `.env` and the config back on the SQLite pin |
| `r2.probes-sqlite`, `r2.unseal-proof` | as run 1 | | As run 1 |
| `r2.reopen` | D13, D14 | 120 | `reopen --hold-scheduler` (FastCGI on SQLite), then `release-scheduler` |
| `r2.a3-copy-load` | D19, PyMySQL | | The real A3 copy-load recipe, last so no night step waits on it (below) |

Budgets are design 6.1 and 6.2 (hold 1 min, freeze 3, snapshot 3, prepare 60
to 120 s, template 1, rollback 2 to 3, SQLite reopen 1 to 2) and 4.5 (rehold,
refresh and reopen together 3 to 5 minutes). The report marks a passed step
over its budget with `!`. It adds rehold+refresh+reopen against 300 s and the
outage from the start of the hold to the run's last passed step against
design 6.2's 20 minutes; a sum over its budget gets `!` too, and a sum is
not given as a measurement when one of its steps was stood in or skipped.
The synthetic database is small, so the times are far below production's.

## Run it

```sh
scripts/cutover/production/e2e/run-drill.sh --through r1.init-live-config
scripts/cutover/production/e2e/run-drill.sh
```

Options of `run_drill.py run` (pass them to `run-drill.sh`): `--through STEP`
runs a prefix; `--runs 1` stops after run 1 (refused together with a
`--through` step of run 2); `--time-scale N` (above 0, at most 3600; default
60) divides every timer's delay and the watchdog period (at least 5 s);
`--stand-ins` is for developing the harness only (below).
`REPRO_DRILL_PREFIX` names the containers and volumes (default
`repro-drill-<pid>`). One run takes about 80 s to the first pending step,
most of it the app copy and the migrations; the copy load adds about
20 to 40 s per run, and a whole run with every slice landed adds
about 30 s per freeze (the slow request) and the time-scaled 5-minute timer.
With D10 the Docker VM's disk needs more than 6 GiB free (`prepare` checks
`mysql.free_disk_floor_bytes` on `/`).

Exit status: 0 green (every selected step ran for real and passed), 1 a step
failed (the report is followed by the tails of the sandbox's own service
logs), 3 stopped at a pending step, 4 a stand-in run, 2 usage or a fixture
that did not come up.

Once D10 to D14 have landed, `--through r2.reopen` is the drill and must be
green. The full run adds the independent A3 recipe on the pinned PyMySQL image.

Expected output today (lane base `3b0c734f`), `--through r1.init-live-config`,
exit 0 (the pins, run IDs and release differ every run):

```text
run-drill: MySQL fixture ready: repro-drill-12345-mysql
D18 drill report (time scale 60: timers and the watchdog period divided by it)
step                                   status     seconds  budget  detail
r1.root                                passed        50.7       -   app /srv/repro-drill-1/app/backend, smoke client 1, shoot 1
r1.config                              passed         0.3       -   wrote host.json, identity.json, wrappers.json, wrappers.mysql.json, wrappers.sqlite.json
r1.measure                             passed         0.9       -   run drill-r1-20261126142310, release fe08fefe0338, SQLite pin 840982d06c0a, MySQL pin 522c030c6c28
r1.policy                              passed         0.3       -   policy.json pins run drill-r1-20261126142310
r1.sandbox                             passed         0.4       -   canaries checked; the inside half started
r1.services                            passed         0.8       -   MySQL, capture FPM, app FPM, nginx, Supervisor and watchdog up; loopback only, no route out; held 503 on both server blocks, Telnyx to the capture
r1.marker                              passed         0.2       -   open marker created; /up answers 200 through nginx
r1.init                                passed         0.6       -   planned, generation 1, deploy marker published
r1.init-live-config                    passed         0.1       -   source cache, engine sqlite, hash = policy sqlite_live_config_sha256
Verdict: GREEN: every step through r1.init-live-config ran for real and passed
run-drill: run_drill.py exited 0
```

The full run, exit 3:

```text
...
r1.init-live-config                    passed         0.1       -   source cache, engine sqlite, hash = policy sqlite_live_config_sha256
r1.pin-config                          PENDING          -       -   pending: needs D14 (CLI actions, timers, issued approvals, run wrapper)
Verdict: RED: stopped at r1.pin-config (pending: needs D14 (CLI actions, timers, issued approvals, run wrapper))
run-drill: run_drill.py exited 3
```

## Stand-ins (developing the harness only)

`--stand-ins` lets the steps after a pending one run before their slices
land, so the harness's own code for them is exercised. A pending step with a
stand-in from code that exists runs that instead; one without is skipped. The
verdict is always red (exit 4, "this is not the drill"). Stand-ins:
`rehold` by a simulated reboot (`run-sandbox.sh --reboot`: `/run` emptied,
every process stopped; the boot starts the services again, as the
candidate's "after every reboot" path); `timer-abort` by `cli.py abort` (the
lead's abort, design 6.2); `probes-sqlite` by the same checks after that
rollback, which ran with no prepare; `reopen` by the candidate's
`cli.py reopen`, then the same FastCGI check; `replay` by
`admit.py telnyx-replay` by hand; `r2.watchdog-rollback` by the same 0:45
watchdog rollback from `frozen` with no prepare.
On this harness both runs pass every step that runs with `--stand-ins`
(9 stood in, 13 skipped, about 5 minutes): the copy load put 321 tables,
600 rows and 25 triggers into MySQL and verify-fields checked 5072
fields; the freeze drained a 30 s request in flight (`fpm_drain` waited
27 s, the request answered 200); after each rollback 4 profile contexts
(12 profiles) reported SQLite with the pin, PHP-FPM was stopped and the
gate closed and quiet; after each reopen FastCGI reported SQLite with the
pin; the captured text was replayed into SQLite as one `INBOUND` row;
the watchdog rolled back about 6 s after its clock reached 0:45.

## The copy load (harness-local)

Design 6.2's afternoon step, done by the harness so the night does not wait
on D19 (review D18R-3). It needs nothing outside the QA image and this
checkout. `r{n}.copy-load`, as root inside the sandbox, after the init
sequence (the site open on SQLite, its workers running):

1. checks the target as `repro_maint` (`/fixture/repro_maint.cnf`): MySQL
   8.4.13, the fixture's server UUID, `innodb_flush_log_at_trx_commit`,
   `sync_binlog` and `FOREIGN_KEY_CHECKS` 1; `repro_dashboard` must be empty
   (tables, triggers, routines, events). Run 2 finds run 1's copy and drops
   and creates the database again as `repro_maint` first;
2. takes `S_copy`: SQLite's online backup of the live synthetic database as
   the deploy user, made standalone (rollback journal, no sidecar), root
   0600 at `ROOT/e2e/night/copy.sqlite`, its digest in `copy.sha256`;
3. runs the release's importer in plan mode,
   `python3 -B scripts/mysql/migrate.py plan --snapshot copy.sqlite
   --snapshot-sha256 <S_copy> --output night/plan` (no PyMySQL needed): the
   schema plan and the exception audit, which must be empty;
4. generates the load with the release's own conversion code
   (`run_drill.py copy-sql`): `conversion.apply` and
   `migrate.install_triggers` run against a recorder in place of PyMySQL's
   connection, so the statements are exactly the ones `import-empty` would
   send (CREATE TABLE from the plan, the rows as multi-row INSERTs in the
   same transactions, AUTO_INCREMENT, indexes, foreign keys, checks,
   triggers), with each value rendered as PyMySQL renders it (text and bytes
   as hex literals). The mysql client runs them as `repro_maint`. Creating
   a trigger while the binary log is on needs SUPER unless
   `log_bin_trust_function_creators` is on, so the fixture's root sets it on
   for the load and complete-schema and back to its old value after
   (`scripts/mysql/server/README.md`: "restore any temporary
   `log_bin_trust_function_creators` setting after reviewed DDL");
5. runs `scripts/mysql/verify-fields.php` (every field of `S_copy` against
   the copy) as the read-only `repro_parity_reader`, then
   `scripts/mysql/complete-schema.php` and `artisan migrate:status
   --pending` as the deploy user with the switch values and the
   `repro_migrator` account in the environment and no config cache;
6. records what A3 records: `SHOW BINARY LOG STATUS` (the probe account),
   `{"file", "position", "gtid_executed"}`, after the load and
   complete-schema;
7. writes the night's inputs (next section).

What it proves: the copy in MySQL is `S_copy`, field for field
(verify-fields' `all-fields-verified`), in the layout the release's importer
generates; complete-schema verified it with the migration ledger unchanged
and no migration is pending; the coordinate is a real one after real
app-schema events, so `prepare`'s history scan (D10 steps 4 and 10) and the
receipt run on real data; D10 and D11 read real tool outputs bound to
`S_copy`, the fixture's server and `repro_dashboard`.

What it does not prove: `migrate.py import-empty` itself (its PyMySQL
path; `WarningCheckedConnection`, which stops on any MySQL warning or note,
while the mysql client reports none; `destination()`'s checks, which the
harness repeats only in part; `GET_LOCK`; the exception audit against an
approved list and the legacy constraints, since the synthetic copy has no
orphan rows; the importer's own `conversion.verify`; the result file it
writes: `import-result.json` is written by the harness from the plan's
result and the load's counts, `import_verified` only after verify-fields
passed, `mysql_warning_count` null and a `loader` key naming this load);
A3 and D19's recipe (the account and session the operator uses, how the
host allows trigger creation, the production copy, its size and the nightly
snapshot method, `ANALYZE`, when the coordinate is taken on the host); the
off-site upload (the waiver); real timing.

The separate `r2.a3-copy-load` step reuses that exact snapshot and reviewed synthetic plan,
confirms the maintenance identity and closed write gate and resets only the disposable
fixture target. The real `migrate.py import-empty` uses the pinned wheel and socket-only
`repro_maint` account. It must report zero warnings and the same receipt identities,
table, row and trigger totals. The verification and completion path runs independently
again, saves its real binary-log coordinate, and compares each table's row and field
counts with the earlier load. Its outputs live in the root-private `e2e/a3` directory;
the temporary maintenance and verification credential files are removed after use.

`verify-fields.php` requires its credentials' socket to equal `@@socket`,
which is the fixture container's path, so it connects at
`/fixture/run/mysql.sock`; everything else uses the production socket path
the sandbox binds.

## The night's inputs (D11 and D14 contract)

`check-evidence --write` builds the evidence inputs manifest itself (design
3.2, D10's `adapter.check_inputs`); the harness only provides the inputs and
then checks the manifest. Proposed form, for D11 and D14 to confirm or
change (this step and `NIGHT_FILES` follow):

```sh
/usr/bin/python3 -I -S /opt/repro-cutover-fence/production/cli.py check-evidence --write --inputs <night directory>
```

The night directory holds one file per manifest input (`adapter.EVIDENCE_INPUTS`)
and the coordinate:

| Manifest name | File | Written by |
| --- | --- | --- |
| `snapshot` | `frozen.sha256` (`sha256sum` line of `frozen.sqlite`) | `r{n}.snapshot` |
| `import` | `import-result.json` | copy load (step 7 above) |
| `verify_fields` | `verify-fields.jsonl` (the tool's stdout) | copy load |
| `migrate_status` | `migrate-status.txt` (the command's stdout) | copy load |
| `complete_schema` | `complete-schema.json` (the tool's stdout) | copy load |
| `backup_check` | `final-backup-check.json`; absent with `mysql.offsite_waiver` | (the drill has the waiver) |
| the manifest's `backup` | `backup-coordinate.json`, `{"file", "position", "gtid_executed"}` | copy load |

`r{n}.check-evidence` then requires `paths.evidence_inputs` root 0600 with
exactly D10's keys: `version` 1, `run_id` the policy's run, `mode` drill,
`backup` equal to `backup-coordinate.json`, and `inputs` naming each file
above by its absolute path with its SHA-256, `backup_check` null.

## How the sandbox's host commands behave

`make_config.py` points host commands at `ROOT/bin`. Each clears the
controller's environment (its `REPRO_CUTOVER_CHILD` tag) before running the
harness, so nothing it starts counts as a host command still running
(`require_quiescent`).

| Command | Stands in for | Behaviour |
| --- | --- | --- |
| `stop-workers` | `supervisorctl stop` of every group | Private Supervisor: SIGTERM to each program's process group, SIGKILL after 30 s; exits 0 when all are stopped |
| `start-workers` | `systemctl start php8.3-fpm.service`, then every group | FPM first (design 5.4: starts a stopped FPM, leaves a running one alone), then each program; exits 0 only when every program is running after 1.5 s (a held admission, exit 75, fails it, as Supervisor's `startsecs`) |
| `fpm-start` | `systemctl restart php8.3-fpm.service` (design 3.1 `fpm_start`) | Stops a running master as `fpm-stop` does, starts it, waits for the socket. Exits 1 while `ROOT/e2e/faults/fpm-start` exists (run 2's injected prepare failure) |
| `fpm-stop` | `systemctl stop php8.3-fpm.service` | SIGTERM to the master (Debian's unit sets no `KillSignal`: requests in flight end at once, design 5.4), SIGKILL to what is left after 30 s |
| `fpm-reload` | `systemctl try-reload-or-restart php8.3-fpm.service` | SIGUSR2 to a running master; nothing for a stopped one |
| `timer` | `systemd-run` transient timer (design 3.10) | The controller supplies `--unit=NAME`, an absolute `--on-calendar=YYYY-MM-DD HH:MM:SS UTC` argument, `--timer-property=AccuracySec=1us`, `--collect` and `--property=UMask=0077`. The shim records the absolute expiry and runs the command after the remaining time divided by the drill scale. Standalone fixtures also accept `--on-active=SPAN`. An occupied name exits 1. Unsupported arguments exit 2. A command that fails to execute records exit 203. The receipt is `ROOT/e2e/timers/NAME.json`. |
| `timer-stop` | `systemctl stop NAME.timer` (proposed `commands.timer_stop`, below) | Only `stop NAME.timer`: cancels an armed timer (its command never runs) or unloads an elapsed one; a command already running goes on. Exit 5 when NAME.timer is not loaded, as `systemctl`; any other form exits 2 |

The private Supervisor is the harness's own (`python3 -m supervisor` is not in
the QA image): `autorestart=unexpected`, `exitcodes=75`, `stopasgroup`,
`killasgroup`, as `supervisor/laravel-worker.conf.example`. Its programs are
`laravel-worker`, `media-archives` and `voice-notifications`, each
`admit.py <profile>` as root.

The watchdog loop runs `cli.py tick` every `max(5, 60 / time scale)`
seconds, exactly as `repro-cutover-watchdog.service` does. For run 2's 0:45
it runs the same `cli.py` through `python3 -I -S -c` with the wall clock
moved forward (`runpy`; the runner reads `time.time()`), from
`ROOT/e2e/watchdog-offset`; cli.py itself is unchanged. The offset is
removed once the rollback is seen, so later ticks run on the real clock.
The harness's controller commands, the timers' commands and the watchdog's
ticks take one lock (`ROOT/e2e/step.lock`) so none is refused with "Another
host operator holds the cutover lock"; the watchdog skips a tick while the
lock is busy (see D18R-11 below).

## Local stand-ins for the host (deviations from design 7.3)

- The MySQL server runs in its own container (the QA image has no
  `mysqld`); its socket directory is bound into the sandbox's private `/run`
  at the production path. The sandbox datadir holds only a copy of the
  server's `auto.cnf`, which the identity probe and `make_config.py` read.
- The container gets what production has and the QA image lacks:
  `/usr/bin/php` and `/usr/sbin/php-fpm8.3` (links to the image's PHP), the
  `maverick` account in group `www-data`, and one block-device node in
  `/dev/disk/by-uuid` for the device of the `/srv` volume (the identity
  probe only `stat`s it).
- The slow request goes through a drill-only nginx location to a script in
  `/opt/repro-drill`, outside the app tree, so the artifact is unchanged.
- The W1 open marker is written by the harness, as the runbook's step does,
  with the adapter's own content.
- The pins are measured by the harness until D14 lands; `r1.pin-config` then
  checks that `cli.py pin-config` reproduces them.
- The night uses the harness-local copy; the last step separately runs the real importer against that snapshot.
- The route hash of a profile probe is computed by the harness from the raw
  route values PHP prints, by the shared contract
  (`testdata/route-hash-vectors.json`); the harness checks its function
  against every vector first.

## Adding it to the Linux suite (D14 or the lead)

Not added yet: it is red until every pending step has landed. When the last
of D10 to D14 lands, D14 or the lead makes `--through r2.reopen` part of the
lane's merge check (and the full run using the offline PyMySQL image).
The simplest is to run `e2e/run-drill.sh` beside
`run-probe-fixture-tests.sh --linux-suite`. To put it into
`../run-linux-tests.sh` instead, that container must also be `--privileged`
with the vendor volume at `/vendor-src` and a volume at `/srv`
(`run-probe-fixture-tests.sh --linux-suite` mounts neither today), and the
last line would be

```sh
[ -z "${REPRO_DRILL_FIXTURE:-}" ] || /usr/bin/python3 -I -S -B "$cutover/production/e2e/run_drill.py" run \
    --fixture "$REPRO_DRILL_FIXTURE" --through r2.reopen
```

with `REPRO_DRILL_FIXTURE` naming a fixture the drill has to itself (the
probe and gate tests change their fixture's accounts and restore them;
`run-drill.sh` starts its own; the copy load drops and loads
`repro_dashboard`). Without `--release-sha` the deploy record names forty
zeros, which the identity probe accepts.

## What each pending step needs

So the slices can be checked against it. Paths are the sandbox's fixed
paths (production's values); `ROOT` is `/srv/repro-drill-<run>`; "night" is
`ROOT/e2e/night`.

- `r1.pin-config` (D14): `cli.py pin-config`, read-only, exits 0 and prints
  JSON holding `sqlite_live_config_sha256` and `mysql_live_config_sha256`
  equal to `policy.json`'s (the harness looks for both values in the
  output). See also D18R-15 below.
- `r1.rehold`, `r2.rehold` (D13 `rehold`, D14 the action): `cli.py rehold` in
  `planned` exits 0, leaves the journal's phase and nothing pending, and
  leaves no `/run/repro-cutover-ingress/open`, no
  `/run/repro-cutover-fence/scheduler-released`, no activation receipt
  (`paths.activation`), every `host.json` queue paused (Laravel's
  file-cache flag), no supervised program with a process, no
  `writer_processes` process, and every route of both server blocks
  answering the held 503.
- `r1.refresh` (exists): it publishes
  `/run/repro-cutover-fence/verified-engine.json` for the planned journal
  after `rehold`; with D13 (design 3.9) also `/etc/repro-database-cutover/active`
  present, no scheduler release and every queue paused.
- `r1.reopen-init` (exists; D13 changes it): `cli.py reopen` in `planned`
  exits 0, creates the marker and leaves every supervised program running
  (`start_workers`); with D13 also replay pass 1 on an empty spool, the
  FastCGI probe before the marker and the release last.
- `r1.scheduler-release` (D13): after that reopen
  `/run/repro-cutover-fence/scheduler-released` exists with an mtime not
  before the marker's, and `admit.py auth-prune` exits 0 (D13's own tests
  prove 75 without the release).
- `r1.check-evidence`, `r2.check-evidence` (D11, D14): the night's inputs
  section above.
- `r1.prepare` (D10 steps 1 to 10, D11 steps 11 and 12 and `evidence()`,
  D14 the action): `cli.py prepare` exits 0 with phase `ready`; `.env` holds
  every `mysql.switch` value and a `DB_PASSWORD`; `live_config.py` measures
  engine `mysql`, source `cache`, the policy's `mysql_live_config_sha256`;
  `/run/php/php8.3-fpm.sock` answers (`fpm_start`, `ROOT/bin/fpm-start`);
  `switch-record.json` (this run, `switch_at` an integer) and `receipt.json`
  are root 0600 under `/var/lib/repro-cutover-fence`; the receipt has
  exactly D10's keys, this run, the target's server UUID and database,
  `backup` equal to `backup-coordinate.json` and `armed_from` a
  `{file, position}` at or after it.
- `r1.prepare-checks` (D11, D14): the D11 report contract below.
- `r1.approval-promote` (D14): `cli.py approval-template --phase promote`
  exits 0, writes `issued-approvals/promote.json` and arms exactly one timer
  through `host.json` `commands.timer` (`ROOT/bin/timer`) with `--collect`,
  `--property=UMask=0077`, `--timer-property=AccuracySec=1us` and the exact
  UTC expiry on the issued approval, at most 300 s away. The command is
  bound to the full challenge and ready phase (design 3.10:
  `/usr/bin/python3 -I -S /opt/repro-cutover-fence/production/cli.py abort
  --if-challenge C --if-phase ready`). Re-issuing a template first cancels
  its timer with `commands.timer_stop` (D18R-2 below).
- `r1.timer-abort` (D12, D14): the timer's conditional abort exits 0 and the
  journal reads `sqlite_authoritative` with `abort` as the last action.
- `r1.probes-sqlite`, `r2.probes-sqlite` (D10 starts FPM on MySQL, D12 the
  undo): after the rollback, `cutover:smoke --engine-only --json` in every
  wrapper profile's user, group, cwd, umask and environment (deduplicated;
  the shell-script profiles studio, lab-tour and cubicasa-poll are probed in
  theirs with the app's artisan) exits 0, reports `sqlite` and route values
  whose hash is the SQLite pin, for every profile of the production example.
  PHP-FPM is stopped: no master and `/run/php/php8.3-fpm.sock` does not
  answer. A rollback always stops FPM (`drain_fpm` with purpose rollback,
  build-plan addendum after wave 2) and D12's `fpm_reload`
  (`try-reload-or-restart`) does nothing to a stopped FPM (design 3.5 step
  5); the reopen starts it, so the FastCGI SQLite probe belongs to
  `r{n}.reopen` (design 3.7 step 4). The MySQL probe reads `closed`, no
  business and no foreign activity.
- `r1.unseal-proof`, `r2.unseal-proof` (D12):
  `/var/lib/repro-cutover-fence/unseal-proof.json` has `sha256` equal to
  `sealed-hash.json`'s.
- `r1.reopen` (D13, D14): `cli.py reopen --hold-scheduler` exits 0; the
  marker exists, the release does not, `/up` answers 200, and
  `fastcgi.py --socket /run/php/php8.3-fpm.sock --app-root APP` reports
  `sqlite` with `route_sha256` = the SQLite pin.
- `r1.replay` (D13): that reopen's replay left one `messages` row
  `provider_message_id = 'msg-drill-1'`, `INBOUND`, `drill test text`, and
  no entry in `storage/app/telnyx-spool/texts`.
- `r1.release-scheduler` (D13, D14): `cli.py release-scheduler` exits 0, the
  release exists, `admit.py auth-prune` exits 0.
- `r1.deploy-fence` (D13): `/etc/repro-database-cutover/active` still exists.
- `r2.prepare-fails` (D10, D14): with `ROOT/e2e/faults/fpm-start` present,
  `cli.py prepare` exits non-zero at step 9; the journal stays `frozen` with
  `pending.action` `prepare`; `.env` holds `DB_CONNECTION=mysql`; the live
  config is the MySQL pin from the cache and the switch record has
  `switch_at` (step 8 ran); no receipt; FPM stopped.
- `r2.watchdog-rollback` (D12): a watchdog tick at 0:45 rolls the pending
  prepare back: `sqlite_authoritative`, `.env` `DB_CONNECTION=sqlite`,
  `live_config.py` the SQLite pin from the cache.
- `r2.reopen` (D13, D14): as `r1.reopen`, then `cli.py release-scheduler`,
  both exit 0.
- `r2.a3-copy-load` (D19, PyMySQL): the copy load section above.

### D11 report contract (`r1.prepare-checks`)

`/var/lib/repro-cutover-fence/evidence-report.json`, root 0600, and
`cli.py status --evidence` prints the same JSON object. The key names are
D11's to fix; until then this is the shape the step reads, and a missing key
fails it with the key's dotted path. The top level reuses D10's
`check_inputs` names:

```json
{
  "version": 1, "run_id": "<the pinned run>", "mode": "drill", "offsite_waiver": true,
  "snapshot_sha256": "<S_copy: forward parity, drill>", "frozen_snapshot_sha256": "<S_frozen>",
  "backup": {"file": "binlog.000003", "position": 1157, "gtid_executed": "<uuid>:1-40"},
  "inputs": {"<the manifest's inputs>": {"path": "...", "sha256": "..."}},
  "checks": {
    "profile_probes": [
      {"profiles": ["laravel-worker", "media-archives"], "user": "www-data", "group": "www-data",
       "cwd": "/var/www/backend", "umask": "0007", "engine": "mysql", "database": "repro_dashboard",
       "server_uuid": "<target_uuid>", "route_sha256": "<MySQL pin>", "ok": true}
    ],
    "fastcgi": {"engine": "mysql", "database": "repro_dashboard", "server_uuid": "<target_uuid>",
                "route_sha256": "<MySQL pin>", "opcache": {"...": "..."}, "ok": true},
    "readonly_smoke": [{"user": "www-data", "ok": true}, {"user": "maverick", "ok": true}],
    "token_check": {"problems": [], "token_acquired": true},
    "mysql": {"gate": "closed", "writable": false, "business_activity": false, "foreign_activity": false}
  }
}
```

The step requires: `version` 1, `run_id`, `mode` and `offsite_waiver` as
configured; `snapshot_sha256` = `S_copy` (`night/copy.sha256`),
`frozen_snapshot_sha256` = `sealed-hash.json`'s; `backup` =
`backup-coordinate.json`; `inputs` = the manifest's; every profile probe on
`mysql`, `repro_dashboard`, the target's server UUID, the MySQL pin, `ok`
true, each wrapper profile in exactly one probe whose `user`, `group` and
`cwd` are that profile's; the FastCGI probe the same plus an `opcache`
value; both read-only smokes (`web_user`, `app_owner`) `ok` true; the token
check reports empty `problems` and `token_acquired` true. The MySQL probe
reports a closed gate, `writable` false and no business or foreign activity.

### D14 contract items from the harness

- **D18R-2, cancelling a timer.** `systemd-run` has no option that stops a
  timer; a transient timer is cancelled with `systemctl stop <unit>.timer`.
  Neither design 3.1/3.10 nor D14's build-plan entry names a host.json
  command for it, so this README proposes one: `commands.timer_stop`, in
  production `["/usr/bin/systemctl"]`, in the sandbox
  (`make_config.py`) `["ROOT/bin/timer-stop"]`. D14 appends `stop <unit>.timer`
  and treats exit 5 as already gone.
  Reissuing a template stops the previous approval timer before scheduling
  its replacement. The operational deadline has its own unit. Reissue the promote template twice;
  the stand-in must show one armed timer, the first stopped and never
  fired.
- **D18R-11, a conditional abort while the controller lock is busy.**
  Design 3.10: `abort --if-challenge C --if-phase P` does nothing while the
  controller lock is busy. So a promote timer that fires during a watchdog
  tick skips the unattended abort, and the site waits for the 0:45
  watchdog. The harness serialises timer commands with the watchdog and its
  own commands through `ROOT/e2e/step.lock`, so the drill cannot show this.
  D14 decides (for example: the conditional abort waits for the lock,
  bounded like `tick`'s 50 s, and then re-checks phase and challenge) and
  tests it: hold the controller lock as a tick does, fire the conditional
  abort, release the lock; the journal must end `sqlite_authoritative` by
  the abort (or the documented outcome must hold), and a phase or challenge
  that changed meanwhile must still make it a no-op.
- **D18R-15, `pin-config` before `init`.** Design 1.3 and P3 run
  `cli.py pin-config` before `init`, and `policy.json` takes the pins it
  prints (A6). The harness measures the pins itself (`r1.measure`) and
  `r1.pin-config` runs after `init` and only looks for the policy's hashes in
  the output, so a `pin-config` that echoed `policy.json` would pass. D14
  tests that `pin-config` runs with no journal, rebuilds the staged SQLite
  cache with the controller's exact command and refuses unless its hash
  equals the live one (1.3 step 2,
  `test_pin_config_refuses_unreproducible_sqlite_hash`), and builds the
  staged MySQL cache from `mysql.switch` (1.3 step 3); its output must not
  depend on `policy.json` (run it with `policy.json` absent or holding
  other pins). When D14 lands, the harness moves the pins to `pin-config`'s
  output before `init` and keeps its own measurement as the cross-check.

### For the lead

Design 6.2's row "abort + 5 min: per-profile probes and the FastCGI probe
report SQLite" contradicts 3.5 step 5 and 3.7 step 4: after a rollback FPM
is stopped, and the FastCGI probe runs in the reopen. The harness follows
3.5 and 3.7 (review D18R-1).
