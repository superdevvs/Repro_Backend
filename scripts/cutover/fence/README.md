# Cutover admission guard

This read-only check consumes the actual host cutover journal. It returns exit
zero for the verified engine in the current boot and exit one when admissions
must remain held. The repository supplies the check and tests. Host enforcement
and the trusted evidence publisher still need implementation and acceptance.

| Journal phase | Allowed on valid authority evidence |
| --- | --- |
| `planned` | SQLite web, queue and scheduler writers |
| `sqlite_authoritative` | SQLite writer admissions and the guarded deploy entrypoint |
| `mysql_authoritative` | MySQL writer admissions and the guarded deploy entrypoint |
| Other phase, unresolved action or missing evidence | All admissions held |

## Installation contract

Use a root-owned copy of the reviewed guard at
`/opt/repro-cutover-fence/guard.py`. All directories in its privilege chain and
the input chains must be real directories owned by root and writable by root
only. Run the fixed entrypoint with the isolated system interpreter.

```text
/usr/bin/python3 -I -S /opt/repro-cutover-fence/guard.py queue-worker sqlite
```

The action is one of `queue-worker`, `scheduler`, `web-writer`, or `deploy`.
The engine argument is the caller's expected database, pinned by the trusted
service wrapper. A systemd condition for that worker could use
`ExecCondition=+/usr/bin/python3 -I -S /opt/repro-cutover-fence/guard.py queue-worker sqlite`.
The `+` prefix gives this fixed check access to the root-private journal while
the worker itself still runs as its service account.

1. **Persistent policy.** `/etc/repro-cutover-fence/policy.json` contains
   `version: 1`, `machine_id_sha256`, the complete cutover `identity`, and
   `sqlite_live_config_sha256` and `mysql_live_config_sha256` pins. The
   configuration pins identify each engine's expected effective runtime
   configuration. Derive them without placing secrets in the record.
2. **Persistent journal.** Use `/var/lib/repro-cutover-fence` as the runner's
   root-private state directory. The guard consumes the same `state.json` and
   `operator.lock` as the runner. Its shared lock fails while a cutover action
   holds the exclusive lock. Run the controller with root access to that same
   journal directory and keep the lock inode in place.
3. **Boot authority evidence.** A trusted verifier checks the backend and
   worker configuration with read-only connection probes while admissions
   are held, and confirms the deployment controls and running processes.
   It publishes a protected
   `/run/repro-cutover-fence/verified-engine.json` with `version: 1`, the live
   kernel `boot_id`, `policy_sha256` and `journal_sha256` over the exact raw
   records, observed `engine`, `database_identity` (SQLite source identity
   digest or live MySQL UUID), `live_config_sha256`, `release_sha`,
   `artifact_sha256`, and `competing_writer_detected`. Establish exclusive
   write authority using real process and connection probes before publishing
   a false competing-writer flag. During promotion or pre-write abort, publish
   the proof atomically for the candidate authoritative bytes while admissions
   are sealed and the runner holds the exclusive lease. The runner commits
   those bytes and releases the lease, so the guard can then permit the
   selected engine. A pending journal and stale or copied proof deny admission.
   Refresh proof after reboot, using an independently checked stable SQLite
   source identity or MySQL UUID. Keep hashes of SQLite snapshot contents in
   the migration evidence, since live SQLite contents change after reopening.

## Integration and acceptance

The CLI and systemd condition check only at admission time. A reviewed,
root-installed deploy or short scheduler wrapper can call
`with admitted(action, expected_engine) as lease_fd` to verify under the
shared lease and hold it through its full bounded operation. If a synchronous
child performs the operation, pass this specific descriptor with
`subprocess.run(..., pass_fds=(lease_fd,))` and supervise the full process tree.
The child's inherited descriptor keeps the lease alive if the wrapper exits.
Use fixed command paths and arguments, preserve the existing deploy mutex, and
never use a process that outlives its lease or the wrapper's deadline.
Keep the deployment hold until the controller has verified live activation.
Record activation separately from the authorization-bearing journal. Restrict
filesystem and entrypoint permissions so stale scripts cannot mutate release files, run
migrations, or reload services around the wrapper. Cutover preparation must
close admission and drain existing processes before a writer can change
engine. The actual web write gate, long-lived worker draining, and webhook
capture need their own controls. Running a condition once at service startup
cannot revoke a process that already started.

Install and test the guard, evidence publisher, real write admission controls,
and wrappers together on an isolated Linux fixture. Show that a reboot during
freeze, a missing or corrupt journal, stale engine proof, and an interrupted
post-write transition hold the real services. Test that an unauthorized script
cannot bypass the wrapper and that the authoritative phase permits only the
recorded engine after the exclusive lease ends. Verify ordinary watchdog ticks
leave a healthy authority proof usable. Review the successful controls and exact
privilege chain before connecting production services.

```text
python -B -m unittest discover -s scripts/cutover/fence -p test_guard.py -v
```
