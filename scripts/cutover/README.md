# ReProDashboard cutover runner rehearsal

The runner tests promotion and rollback decisions with a local fixture. It has no host adapter, SSH access, service controls, database connection or production mode. Its SQLite and MySQL observations describe simulated state.

```powershell
$fixture = Join-Path ([System.IO.Path]::GetTempPath()) ('repro-cutover-fixture-' + [Guid]::NewGuid().ToString('N'))
python scripts/cutover/rehearse.py --state-dir $fixture init
python scripts/cutover/rehearse.py --state-dir $fixture approval-template --phase freeze |
  Set-Content -LiteralPath (Join-Path $fixture freeze.json) -Encoding utf8
python scripts/cutover/rehearse.py --state-dir $fixture freeze --approval (Join-Path $fixture freeze.json)
python scripts/cutover/rehearse.py --state-dir $fixture prepare
python scripts/cutover/rehearse.py --state-dir $fixture status
python scripts/cutover/rehearse.py --state-dir $fixture abort
```

For the fixture promotion path use `approval-template --phase promote` after `prepare` and pass that record to `promote --approval`. The approval templates are unsigned fixtures. The real approval source must authenticate the operator.

The journal binds the full release commit, release archive digest, source identity, expected configuration digest, target UUID and run ID. A second approval also binds the verified snapshot and readiness evidence, including the backup proofs and starting binlog position. Each approval expires and has a challenge tied to the phase.

One operator at a time holds the OS lock. The adapter must check its generation before every effect. The journal records the intent and observed state before each effect, and the verified result afterward. Linux state replacements sync the file and parent directory. On restart, an interrupted pre-write effect requires a verified retry of `abort` after its processes are drained. `tick` starts the same rollback at minute 45 if writes have not been enabled. The irreversible marker is saved and synced before the first opportunity for a MySQL business write. Any uncertain outcome after that marker requires recovery on the MySQL side.

Before a production adapter can be considered for acceptance, it must:

- Enforce the same lease in every privileged entrypoint and hold all deploy and application writers before reporting the freeze. This journal cannot fence unmanaged writers. Put its directory on a protected local Linux filesystem.
- Make effects synchronous or run them in a supervised scope that can be proven stopped before takeover, with deadline enforcement and a separate watchdog invoking `tick`. The core receives clock and process observations from the host. The fixture does not model clock synchronization or process supervision.
- Verify independent source, target, release and configuration identities without exposing credentials. Journal actual before-state needed for safe undo, including service, config and scheduler state, and verify it after restoration.
- Use read-only target evidence before promotion and hold all production webhooks, worker admissions, and schedulers until the recorded write boundary. During rehearsal test restoration after killed and hung actions and an unavailable target, plus lost authorization, disk exhaustion, and off-site restore.

Run the fixture tests with `python -B -m unittest discover -s scripts/cutover -p test_runner.py -v`.
