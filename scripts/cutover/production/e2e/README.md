# Local end-to-end drill (build plan D18)

Built and run locally only, in Docker. Never on a production host: the outer
half refuses to run without `/.dockerenv`, and it changes the disposable
container it runs in (below). W2a, the same drill in the sandbox on the host,
is a production action that needs the owner's approval and its own recipe.

| File | Purpose |
| --- | --- |
| `run_drill.py` | The drill: the step list, the outer half (container, sandbox roots, config, launches), the inside half (the steps), and the sandbox services the host commands reach (private Supervisor, FPM control, `timer`, watchdog loop) |
| `run-drill.sh` | Starts the MySQL 8.4.13 fixture and the privileged QA container, runs `run_drill.py run`, removes every container and volume it made |

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
   synthetic SQLite database built by the app's own migrations as the deploy
   user, the smoke fixtures (`cutover:smoke-fixtures --create`), the live
   SQLite config cache, the MySQL defaults files from the fixture, the
   capture's files, nginx with the production gate and hold includes
   verbatim, the app's PHP-FPM with the status snippet verbatim, and the
   sandbox host commands in `bin/`;
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
profiles and the app's own artisan commands. Nothing imports a controller
module. `repro-cutover-run` (D14) is not usable inside the sandbox
(`systemd-run` is the refusing shim), so the drill calls `cli.py` directly
(sandbox/README.md). Every outbound path is blocked twice: `--network none`
and the sandbox's loopback-only network (the boot checks that a connection
out fails).

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
| `r1.services` | | | Boot; nginx holds (503 `admission_held`, `Retry-After`); an unsigned Telnyx post reaches the capture and is refused |
| `r1.marker` | | | W1: the open marker; `/up` answers 200 through nginx |
| `r1.init` | | | `cli.py init`: planned, deploy marker |
| `r1.init-live-config` | | | `live_config.py`: source cache, hash = policy SQLite pin (review C2-4) |
| `r1.pin-config` | D14 | | `cli.py pin-config` reproduces both pins |
| `r1.rehold` | D13, D14 | 120 | `cli.py rehold` |
| `r1.refresh` | | 60 | `cli.py refresh` |
| `r1.reopen-init` | | 120 | `cli.py reopen`: wrapped workers admitted, marker |
| `r1.scheduler-release` | D13 | | The release exists, made last; `admit.py auth-prune` admitted |
| `r1.watchdog-idle` | | | Two watchdog ticks leave the planned journal unchanged |
| `r1.copy-load` | D19, PyMySQL | | The afternoon copy load and its coordinate |
| `r1.predrain` | | | The 9:20 pm long-job pre-drain (media-archives stopped) |
| `r1.approval-hold` | | 60 | `cli.py approval-template --phase hold` |
| `r1.hold` | | 60 | A 30 s request started, then `cli.py hold`: every route held, nine queues paused, the request still in flight |
| `r1.capture` | | | A signed test text captured (200, frontend block), a signed test call saved (503 with `Retry-After`, legacy block) |
| `r1.freeze` | | 180 | `cli.py freeze`: the drain waited for the request in flight (`fpm_drain` `drained`), the request finished 200, SQLite sealed, `sealed_hash` = the file |
| `r1.snapshot` | | 180 | Frozen snapshot `S_frozen`: integrity, SHA-256 record, = `sealed_hash` |
| `r1.check-evidence` | D11, D14, D19 | 60 | `cli.py check-evidence --write` |
| `r1.prepare` | D10, D11, D14 | 120 | `cli.py prepare`: ready on the MySQL pin |
| `r1.prepare-checks` | D11, D14 | | `cli.py status --evidence`: the step 11 checks |
| `r1.approval-promote` | D14 | 60 | Issued promote approval, the 5-minute timer armed |
| `r1.timer-abort` | D12, D14 | 180 | The timer's conditional abort rolls back (time-scaled) |
| `r1.probes-sqlite` | D10, D12 | | Every probe reports SQLite |
| `r1.unseal-proof` | D12 | | `unseal_proof` = `sealed_hash` |
| `r1.reopen` | D13, D14 | 120 | `cli.py reopen --hold-scheduler` |
| `r1.replay` | D13 | | The captured text replayed into SQLite once |
| `r1.release-scheduler` | D13, D14 | | `cli.py release-scheduler` |
| `r1.deploy-fence` | D13 | | The deploy marker stays after the reopen |
| `r2.root` to `r2.reopen-init` | as run 1 | | Second sandbox root and run, the same init sequence (no `pin-config`) |
| `r2.copy-load` to `r2.snapshot` | as run 1 | | Copy load, pre-drain, Approval 1, hold, freeze, snapshot |
| `r2.check-evidence` | D11, D14, D19 | 60 | As run 1 |
| `r2.prepare-fails` | D10, D14 | 120 | `cli.py prepare` fails at `fpm_start` (injected), after the `.env` switch and the MySQL build |
| `r2.watchdog-rollback` | D12 | 180 | A watchdog tick at 0:45 rolls back; `.env` and the config back on the SQLite pin |
| `r2.probes-sqlite`, `r2.unseal-proof` | as run 1 | | As run 1 |
| `r2.reopen` | D13, D14 | 120 | `reopen --hold-scheduler`, then `release-scheduler` |

Budgets are design 6.1 and 6.2 (hold 1 min, freeze 3, snapshot 3, prepare 60
to 120 s, template 1, rollback 2 to 3, SQLite reopen 1 to 2) and 4.5 (rehold,
refresh and reopen together 3 to 5 minutes). The report marks a passed step
over its budget with `!`, adds rehold+refresh+reopen against 300 s and the
outage from the hold to the run's last step against design 6.2's 20 minutes.
The synthetic database is small, so the times are far below production's.

## Run it

```sh
scripts/cutover/production/e2e/run-drill.sh --through r1.init-live-config
scripts/cutover/production/e2e/run-drill.sh
```

Options of `run_drill.py run` (pass them to `run-drill.sh`): `--through STEP`
runs a prefix; `--runs 1` stops after run 1; `--time-scale N` (default 60)
divides every timer's delay and the watchdog period (at least 5 s);
`--stand-ins` is for developing the harness only (below). `REPRO_DRILL_PREFIX`
names the containers and volumes (default `repro-drill-<pid>`). One run takes
about 80 s to the first pending step, most of it the app copy and the
migrations; a whole run with every slice landed adds about 30 s per freeze
(the slow request) and the time-scaled 5-minute timer.

Exit status: 0 green (every selected step ran for real and passed), 1 a step
failed (the report is followed by the tails of the sandbox's own service
logs), 3 stopped at a pending step, 4 a stand-in run, 2 usage or a fixture
that did not come up.

Expected output today (base `3b0c734f`), `--through r1.init-live-config`,
exit 0 (the pins and run IDs differ every run):

```text
run-drill: MySQL fixture ready: repro-drill-12345-mysql
D18 drill report (time scale 60: timers and the watchdog period divided by it)
step                               status     seconds  budget  detail
r1.root                            passed        66.0       -   app /srv/repro-drill-1/app/backend, smoke client 1, shoot 1
r1.config                          passed         0.4       -   wrote host.json, identity.json, wrappers.json, wrappers.mysql.json, wrappers.sqlite.json
r1.measure                         passed         1.0       -   run drill-r1-20261126124021, release 3b0c734f0512, SQLite pin 1e6a03e920ba, MySQL pin b1228db441a2
r1.policy                          passed         0.3       -   policy.json pins run drill-r1-20261126124021
r1.sandbox                         passed         0.6       -   canaries checked; the inside half started
r1.services                        passed         0.8       -   MySQL, capture FPM, app FPM, nginx, Supervisor and watchdog up; held 503, Telnyx to the capture
r1.marker                          passed         0.2       -   open marker created; /up answers 200 through nginx
r1.init                            passed         0.7       -   planned, generation 1, deploy marker published
r1.init-live-config                passed         0.1       -   source cache, engine sqlite, hash = policy sqlite_live_config_sha256
Verdict: GREEN: every step through r1.init-live-config ran for real and passed
run-drill: run_drill.py exited 0
```

The full run, exit 3:

```text
...
r1.init-live-config                passed         0.1       -   source cache, engine sqlite, hash = policy sqlite_live_config_sha256
r1.pin-config                      PENDING          -       -   pending: needs D14 (CLI actions, timers, issued approvals, run wrapper)
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
lead's abort, design 6.2); `reopen` by the candidate's `cli.py reopen`;
`replay` by `admit.py telnyx-replay` by hand; `r2.watchdog-rollback` by the
same 0:45 watchdog rollback from `frozen` with no prepare. On `3b0c734f`
both runs pass every step that runs with `--stand-ins` (run 1: 4 stood in, 11
skipped; both runs: 7 stood in, 16 skipped): the freeze drained a 30 s
request in flight (`fpm_drain` waited 27 s, the request answered 200), the
captured text was replayed into SQLite as one `INBOUND` row, and the
watchdog rolled back about 8 s after its clock reached 0:45.

## How the sandbox's host commands behave

`make_config.py` points host commands at `ROOT/bin`. Each clears the
controller's environment (its `REPRO_CUTOVER_CHILD` tag) before running the
harness, so nothing it starts counts as a host command still running
(`require_quiescent`).

| Command | Stands in for | Behaviour |
| --- | --- | --- |
| `stop-workers` | `supervisorctl stop` of every group | Private Supervisor: SIGTERM to each program's process group, SIGKILL after 30 s; exits 0 when all are stopped |
| `start-workers` | `systemctl start php8.3-fpm.service`, then every group | FPM first (design 5.4), then each program; exits 0 only when every program is running after 1.5 s (a held admission, exit 75, fails it, as Supervisor's `startsecs`) |
| `fpm-start`, `fpm-stop`, `fpm-reload` | `systemctl start`, `stop`, `try-reload-or-restart php8.3-fpm.service` | Start waits for the socket; stop is SIGQUIT (graceful); reload is SIGUSR2 for a running master and nothing for a stopped one. `fpm-start` exits 1 while `ROOT/e2e/faults/fpm-start` exists (run 2's injected prepare failure) |
| `timer` | `systemd-run` transient timer (design 3.10) | `timer --unit=NAME --on-active=SECONDS [--collect] [--property=UMask=0077] [--] COMMAND...` runs COMMAND detached after SECONDS / time scale, with that umask and a clean environment; a second live unit of the same name is refused, as `systemd-run` does; `timer --stop --unit=NAME` cancels it. Record: `ROOT/e2e/timers/NAME.json` |

The private Supervisor is the harness's own (`python3 -m supervisor` is not in
the QA image): `autorestart=unexpected`, `exitcodes=75`, `stopasgroup`,
`killasgroup`, as `supervisor/laravel-worker.conf.example`. Its programs are
`laravel-worker`, `media-archives` and `voice-notifications`, each
`admit.py <profile>` as root.

The watchdog loop runs `cli.py tick` every `max(5, 60 / time scale)` seconds,
exactly as `repro-cutover-watchdog.service` does. For run 2's 0:45 it runs
the same `cli.py` through `python3 -I -S -c` with the wall clock moved
forward (`runpy`; the runner reads `time.time()`), from
`ROOT/e2e/watchdog-offset`; cli.py itself is unchanged. The harness's
controller commands, the timers' commands and the watchdog's ticks take one
lock (`ROOT/e2e/step.lock`) so none is refused with "Another host operator
holds the cutover lock"; the watchdog skips a tick while the lock is busy.

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

## Adding it to the Linux suite (D14 or the lead)

Not added yet: it is red until every pending step has landed. When the last
of D10 to D14 lands (and D19 and the PyMySQL image for the copy load), D14
or the lead makes it part of the lane's merge check. The simplest is to run
`e2e/run-drill.sh` beside `run-probe-fixture-tests.sh --linux-suite`. To put
it into `../run-linux-tests.sh` instead, that container must also be
`--privileged` with the vendor volume at `/vendor-src` and a volume at
`/srv` (`run-probe-fixture-tests.sh --linux-suite` mounts neither today), and
the last line would be

```sh
[ -z "${REPRO_DRILL_FIXTURE:-}" ] || /usr/bin/python3 -I -S -B "$cutover/production/e2e/run_drill.py" run \
    --fixture "$REPRO_DRILL_FIXTURE"
```

with `REPRO_DRILL_FIXTURE` naming a fixture the drill has to itself (the
probe and gate tests change their fixture's accounts and restore them;
`run-drill.sh` starts its own). Without `--release-sha` the deploy record
names forty zeros, which the identity probe accepts.

## What each pending step needs

So the slices can be checked against it. Paths are the sandbox's fixed
paths (production's values); `ROOT` is `/srv/repro-drill-<run>`.

- `r1.pin-config` (D14): `cli.py pin-config`, read-only, exits 0 and prints
  JSON holding `sqlite_live_config_sha256` and `mysql_live_config_sha256`
  equal to `policy.json`'s (the harness looks for both values in the
  output).
- `r1.rehold`, `r2.rehold` (D13 `rehold`, D14 the action): `cli.py rehold` in
  `planned` exits 0 and leaves no `/run/repro-cutover-ingress/open`, no
  `/run/repro-cutover-fence/scheduler-released`, every `host.json` queue
  paused (Laravel's file-cache flag), no `writer_processes` process, nginx
  answering the held 503.
- `r1.refresh` (exists): after D13's change it still publishes
  `/run/repro-cutover-fence/verified-engine.json` for the planned journal
  after `rehold`.
- `r1.reopen-init` (exists; D13 changes it): `cli.py reopen` in `planned`
  exits 0, creates the marker and leaves every supervised program running
  (`start_workers`); with D13 also replay pass 1 on an empty spool, the
  FastCGI probe before the marker and the release last.
- `r1.scheduler-release` (D13): after that reopen
  `/run/repro-cutover-fence/scheduler-released` exists with an mtime not
  before the marker's, and `admit.py auth-prune` exits 0 (D13's own tests
  prove 75 without the release).
- `r1.copy-load`, `r2.copy-load` (D19, PyMySQL): not written yet. It must
  load the synthetic copy into the fixture as `repro_maint` with
  `scripts/mysql/migrate.py import-empty` (needs PyMySQL, which the QA image
  lacks), run `verify-fields.php`, `migrate:status --pending` and
  `complete-schema.php` against it, record the binary log coordinate after
  `complete-schema` and `cutover:smoke-fixtures --create` (the waiver), and
  write the six inputs and D10's manifest into `ROOT/e2e/night/`
  (`manifest.json`).
- `r1.check-evidence`, `r2.check-evidence` (D11, D14): the harness runs
  `cli.py check-evidence --write --manifest ROOT/e2e/night/manifest.json`
  (a proposal: D11 and D14 choose the flag, and this step follows) with D10's
  manifest document; exit 0 and `/var/lib/repro-cutover-fence/evidence-inputs.json`
  written.
- `r1.prepare` (D10 steps 1 to 10, D11 steps 11 and 12 and `evidence()`,
  D14 the action): `cli.py prepare` exits 0 with phase `ready`; `.env` holds
  `DB_CONNECTION=mysql`; `live_config.py` measures the policy's
  `mysql_live_config_sha256`; `/run/php/php8.3-fpm.sock` listens (the
  `fpm_start` command, `ROOT/bin/fpm-start`); `switch-record.json` and
  `receipt.json` exist under `/var/lib/repro-cutover-fence`.
- `r1.prepare-checks` (D11, D14): `cli.py status --evidence` exits 0 and its
  output names the profile probes, the FastCGI probe, the read-only smokes
  and the token check (the words `profile`, `fastcgi`, `readonly`, `token`);
  `evidence-report.json` is root 0600.
- `r1.approval-promote` (D14): `cli.py approval-template --phase promote`
  exits 0, writes `issued-approvals/promote.json` and arms exactly one timer
  through `host.json` `commands.timer` in the form above, with SECONDS in
  (0, 300] and a command containing `abort`, `--if-challenge` and
  `--if-phase` (design 3.10: `/usr/bin/python3 -I -S
  /opt/repro-cutover-fence/production/cli.py abort --if-challenge C
  --if-phase ready`). Re-issuing must `timer --stop --unit=NAME` the old one.
- `r1.timer-abort` (D12, D14): the timer's conditional abort exits 0 and the
  journal reads `sqlite_authoritative` with `abort` as the last action.
- `r1.probes-sqlite`, `r2.probes-sqlite` (D10 starts FPM on MySQL, D12
  reloads it after the undo): `cutover:smoke --engine-only --json` in each
  PHP wrapper profile's user, group, cwd, umask and environment reports
  `sqlite`; `fastcgi.py --socket /run/php/php8.3-fpm.sock --app-root APP`
  reports `sqlite` with `route_sha256` = the SQLite pin; the MySQL probe
  reads `closed` with no business activity.
- `r1.unseal-proof`, `r2.unseal-proof` (D12):
  `/var/lib/repro-cutover-fence/unseal-proof.json` has `sha256` equal to
  `sealed-hash.json`'s.
- `r1.reopen` (D13, D14): `cli.py reopen --hold-scheduler` exits 0; the
  marker exists, the release does not, `/up` answers 200.
- `r1.replay` (D13): that reopen's replay left one `messages` row
  `provider_message_id = 'msg-drill-1'`, `INBOUND`, `drill test text`, and
  no entry in `storage/app/telnyx-spool/texts`.
- `r1.release-scheduler` (D13, D14): `cli.py release-scheduler` exits 0, the
  release exists, `admit.py auth-prune` exits 0.
- `r1.deploy-fence` (D13): `/etc/repro-database-cutover/active` still exists.
- `r2.prepare-fails` (D10, D14): with `ROOT/e2e/faults/fpm-start` present,
  `cli.py prepare` exits non-zero at step 9; the journal stays `frozen` with
  `pending.action` `prepare`; `.env` already holds `DB_CONNECTION=mysql`.
- `r2.watchdog-rollback` (D12): a watchdog tick at 0:45 rolls the pending
  prepare back: `sqlite_authoritative`, `.env` `DB_CONNECTION=sqlite`,
  `live_config.py` the SQLite pin from the cache.
- `r2.reopen` (D13, D14): `cli.py reopen --hold-scheduler`, then
  `cli.py release-scheduler`, both exit 0.
