# ReProDashboard cutover runner rehearsal

The runner tests promotion and rollback decisions with a local fixture. `rehearse.py` has no host adapter, SSH access, service controls, database connection or production mode. Its SQLite and MySQL observations describe simulated state. The production controller, host adapter, writer wrappers and watchdog units are in `production/` (see its README); they are built and tested locally only.

```powershell
$fixture = Join-Path ([System.IO.Path]::GetTempPath()) ('repro-cutover-fixture-' + [Guid]::NewGuid().ToString('N'))
python scripts/cutover/rehearse.py --state-dir $fixture init
python scripts/cutover/rehearse.py --state-dir $fixture approval-template --phase hold |
  Set-Content -LiteralPath (Join-Path $fixture hold.json) -Encoding utf8
python scripts/cutover/rehearse.py --state-dir $fixture hold --approval (Join-Path $fixture hold.json)
python scripts/cutover/rehearse.py --state-dir $fixture freeze
python scripts/cutover/rehearse.py --state-dir $fixture prepare
python scripts/cutover/rehearse.py --state-dir $fixture status
python scripts/cutover/rehearse.py --state-dir $fixture abort
python scripts/cutover/rehearse.py --state-dir $fixture reopen
```

For the fixture promotion path use `approval-template --phase promote` after `prepare` and pass that record to `promote --approval`. Then run `reopen` to exercise admission after the operator lock is released. The approval templates are unsigned fixtures. The real approval source must authenticate the operator.

The journal binds the full release commit, release archive digest, source identity, expected configuration digest, target UUID and run ID. A second approval also binds the verified snapshot, readiness evidence, and the scheduler hold verified for this run and boot. The readiness evidence includes the backup proofs and starting binlog position. Each approval expires and has a challenge tied to the phase.

Use `python3 -I -B scripts/cutover/final_backup_check.py --help` for the
frozen-backup review inputs. Supply independently obtained receipt SHA-256
pins for both published bases, their original manifests and producer receipts,
the trusted freeze time and release SHA. Pin the exact closed SQLite artifact
used for the import and the reviewed MySQL server UUID, database and starting
binlog coordinate. A matching report verifies the document bindings. The
operator and host adapter still need storage, restore and readiness evidence.

## Hold first, then drain, then freeze

The approved `hold` is the first journaled step. It writes phase `holding`, the outage clock (`started_at`) and a pending hold with its hold ID and boot ID before any host effect. The adapter then closes admissions: both vhosts answer 503 at server level, every queue is paused, the scheduler is held and the deploy marker is set. The guard refuses every admission in `holding`. Writers admitted before the hold keep running and keep their shared lease.

`freeze` starts from `holding`. It stops workers through the adapter, then waits for every admitted writer to release its shared lease (at most `max_drain_seconds`, 10 minutes by default), then drains the scheduler and seals SQLite. Every deadline counts from the hold: `tick` rolls back to SQLite 45 minutes after the hold, and the 60-minute window also starts there. An interrupted hold can be retried with `hold`; an interrupted freeze needs `abort` or the watchdog.

Two locks keep this safe. Controllers (the runner, the watchdog tick and the authority refresh) take `controller.lock` exclusively, one at a time; a tick waits up to 50 seconds for it. Admitted writers share `operator.lock`. A controller takes `operator.lock` exclusively only when it drains writers, and keeps it until its lease ends. A writer that reads the journal after the hold is refused; one admitted earlier holds the shared lock the drain waits for.

After a crash or a reboot, `tick` in a held phase closes admissions again if they were reopened or the boot changed, and re-drains the scheduler under the new boot. Admissions closed outside the journal are refused by `hold` unless the operator chooses `adopt_external_hold`; long-job queues must already be paused and drained unless `require_long_job_predrain` is turned off. One approval covers the hold and the freeze unless `separate_freeze_approval` is set.

One controller at a time holds the controller lock. The adapter must check its generation before every effect. The journal records the intent and observed state before each effect, and the verified result afterward. Linux state replacements sync the file and parent directory. On restart, an interrupted pre-write effect requires a verified retry of `abort` after its processes are drained. `tick` starts the same rollback at minute 45 if writes have not been enabled. The irreversible marker is saved and synced before the first opportunity for a MySQL business write. Any uncertain outcome after that marker requires recovery on the MySQL side.

Promotion and pre-write abort first prepare a single writable database route while keeping all application writes, workers, scheduler and deploy admissions closed. Under the exclusive lease, the adapter attests those controls and publishes current-boot evidence matching the candidate authoritative journal bytes. The runner then durably commits those exact bytes and releases the lease. The guard requires both records. A failure before the commit leaves the pending journal in force, even if the proof was already published. A valid authoritative phase records permission for guarded reopening. The fixture `reopen` verifies that permission and records a separate live observation. Watchdog reads on a healthy planned or authoritative phase preserve the journal bytes and its current proof.

The production adapter must meet these conditions before acceptance.

- Enforce the same lease in every privileged entrypoint and hold all deploy and application writers before reporting the freeze. This journal cannot fence unmanaged writers. Put its directory on a protected local Linux filesystem.
- Make effects synchronous or run them in a supervised scope that can be proven stopped before takeover, with deadline enforcement and a separate watchdog invoking `tick`. The core receives clock and process observations from the host. The fixture does not model clock synchronization or process supervision.
- Close scheduler admission and verify its drain before taking the snapshot. Laravel's scheduler can launch background commands that outlive the scheduler process and close the inherited lease descriptor. Record each launch as a durable pending permit under the cutover lock before asking the service manager to start it. Bind it to the service invocation and its nondelegated cgroup. Retire it only after the manager confirms no pending start or restart and the verified cgroup is empty. Unknown manager state or a missing permit registry keeps the cutover closed.
- Supply `hold_and_drain_scheduler` under the current journal lease. It must close admission and verify zero unresolved permits before the fixture or host adapter can freeze the databases. `scheduler_drain` must observe the current protected hold under that same operator lock during prepare, promotion and authority publication. An interrupted freeze can attempt a fresh verified drain before pre-write rollback seals SQLite. A drain under the operator lease must let the operator retire permits itself, or persist the admission hold before releasing and reacquiring the lease.
- Verify independent source, target, release and configuration identities without exposing credentials. Journal actual before-state needed for safe undo, including service, config and scheduler state, and verify it after restoration.
- Use read-only target evidence before promotion and hold all production webhooks, worker admissions, and schedulers until the recorded write boundary. During rehearsal test restoration after killed and hung actions and an unavailable target, plus lost authorization, disk exhaustion, and off-site restore.
- Open admissions through the installed guard after the operator lease is released, in the approved order and within the cutover budget. Record observed opening and reconciliation in a separate protected activation receipt. On a crash after authority is committed, verify the host and current boot again and resume only the recorded engine. Hold deploys until the host has independently verified application activation. Rehearse interruptions before proof publication, between proof and journal commit, and between commit and service opening.

Run the fixture tests with `python -B -m unittest discover -s scripts/cutover -p test_runner.py -v`. The cross-process lock contract, the root-only refresh tests and the production wrapper, fence and adapter tests need Linux; run them in a disposable, network-disabled container with `scripts/cutover/run-linux-tests.sh` (usage in the script header).
