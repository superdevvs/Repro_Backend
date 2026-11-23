# Scheduled backup unit fixtures

These are reviewable, disabled-by-default unit files for the standalone backup
runner. Nothing here installs a unit, creates an account, enables a timer, or
changes the live application, database, network, or cloud configuration.

Use the five files in `system/` together for a reviewed system-manager setup.
`user-fixture/` is a separate compatibility fixture for an already externally
isolated user manager. Never install both profiles into one manager. The user
profile deliberately omits filesystem, device and network namespace protections
that may be unavailable to an unprivileged manager; it is **not** a production
fallback when the system profile cannot start.

## Runner and installation contract

The only backup command is:

```text
/usr/bin/python3 -I -B /opt/repro-backup-tools/scheduled_backup.py run --plan /etc/repro-backup/plan.json
```

The watchdog and alert use the identical command with `check` in place of `run`.
No shell, environment-provided command, templated instance name, or producer
argument is accepted by a unit. The installed runner and its reviewed sibling
tools must be a complete, pinned release. The runner validates producer commands,
paths, identity, private credentials, publication receipts and the overlap lock;
units do not replace those checks. Exit zero means verified fresh evidence. Every
other exit, signal, timeout or startup failure must remain a failure.

| Path or identity | Required installation property |
| --- | --- |
| `repro-backup` | Existing dedicated unprivileged account/group, no interactive login or broad supplementary access. Provisioning is separate. |
| `/opt/repro-backup-tools/` | Root-owned, traversable, non-writable to the backup account; install reviewed runner/dependencies and PHP producers here. Python files readable, executables executable, exact bytes pinned. |
| `/etc/repro-backup/plan.json` | Root:`repro-backup` mode `0640`, no secrets in JSON, safe root-owned ancestors. Root:`root` `0640` would be unreadable to this service. Root-owned `0600` is only for separately controlled root-run fixtures, not this service. |
| `/var/lib/repro-backup/` | New dedicated state directory, account-owned `0700`; plan `state_dir` must equal this exact path. Never reuse an unrelated directory. The system profile manages ownership using `StateDirectory`. |
| rclone/MySQL credential files | Exact reviewed paths, service-account-owned `0600` with safe private ancestors, as required by the runner. Separate from the root-owned plan. No secrets in unit files or environment. |
| approved application snapshot/source | Account needs only the reads/traversal required for app/vendor/config/`.env` and SQLite snapshot access or the dedicated MySQL socket. Existing OS permissions still apply; these units grant no group or ACL access. |
| reviewed `.service`/`.timer` files | Root-owned `0644`, exact reviewed bytes, installed under `/etc/systemd/system/` only in a separately authorized installation. No fixture Python/JSON files are installed. |

The system service permits durable writes only under its private state directory.
`PrivateTmp` supplies separate ephemeral temporary directories; the root filesystem
and home directories are otherwise read-only. This preserves read access to
approved rehearsal app/vendor/`.env` paths under `/home` without writable access.
Do not weaken the sandbox to accommodate an unexpected source write. Verify the
actual producer and SQLite WAL/read permissions with the intended source fixture.

The publisher needs Unix sockets and IP networking. `RestrictAddressFamilies`
allows `AF_UNIX AF_INET AF_INET6`; this **does not** restrict destinations to one
bucket or endpoint. Approved endpoint/config validation and any required egress
policy remain separate gates. `check` makes no provider call. System watchdog and
alert units additionally use a private network namespace and permit only Unix
sockets, with no writable state exception.

`TimeoutStartSec=7200s` bounds the oneshot backup; the plan's complete run deadline
must be below that limit (the current runner caps it at 3600 seconds). Checks have
a 60-second limit. `KillMode=control-group`, a 30-second stop grace and final
SIGKILL cover subprocesses as well as Python. There is no automatic retry. The
system backup cap is one CPU, 1 GiB RAM and 128 tasks; those are limits to test,
not proof that a full backup will fit. No host-wide settings are changed.

## Schedules and observable failures

- The sample backup calendar is daily at **02:30 UTC**, with up to five minutes
  jitter and one minute timer accuracy. `Persistent=true` catches up a missed
  calendar event when the timer starts; that can cause an immediate backup. Review
  the time, permissions, target and deadline before any enabling/start operation.
- The independent watchdog starts five minutes after its manager starts and runs
  five minutes after the previous check finishes. Review the plan freshness limit
  against schedule jitter, allowed run duration and detection delay; a daily
  interval alone is not a recovery-point guarantee.
- Backup or watchdog failure invokes `repro-backup-alert.service`. It first emits
  the constant journal marker `REPRO_BACKUP_ALERT: scheduled backup or freshness
  check failed` at error priority, then runs the same check. The handler has no
  `OnFailure` of its own, so its failed check does not recurse. It does not swallow
  nonzero status. A subsequent successful check does not erase the earlier marker.
- The marker is a local journal event, **not an externally delivered alert**. An
  independently monitored notification path, host/timer outage detection, journal
  persistence and a demonstrated acknowledgement are still installation gates.
  This package sends no email, webhook or provider notification.
- A timer does not restart its service while that service is already active; the
  runner's own file lock also refuses overlapping invocations. No service uses
  `RemainAfterExit`, `SuccessExitStatus`, skipped-path conditions or ignored errors
  that could turn a failed attempt into apparent success. Provider/network failure
  during a boot catch-up is visible and requires the reviewed retry procedure.

These are database **base-backup** schedules. They do not archive live binlogs,
enforce object retention/locks, back up application media/config separately,
prove recovery from another machine, or authorize a production cutover. The runner's
freshness check is local evidence, not a fresh remote object availability check.

## Offline validation

From the backend checkout:

```powershell
python -I -B scripts/mysql/offsite/systemd/test_units.py
& scripts/mysql/offsite/systemd/run-fixture.ps1
```

The launcher requires the already-present exact reviewed image ID in its default
parameter. It never pulls, uses no network, mounts only this public unit/fixture
directory, drops capabilities, runs as UID 1000 with a read-only root filesystem,
and uses disposable tmpfs for fixture state. It removes only its unique labeled
container and verifies absence. No named volume or systemd manager is created.

The Linux fixture stages identical public unit bytes at mode `0644` in tmpfs
(Windows bind mounts otherwise expose synthetic permissive modes). It verifies
both profiles with `systemd-analyze`, refuses **any** parser diagnostic, parses the
UTC calendar, and executes each unit's exact fixed argv with an explicitly inert
runner. Both exit 0 and exit 1 paths and the constant pre-check alert marker are
checked. Inert fixture code never produces or uploads a backup.

Verified on 2026-11-24: eight offline contract tests; systemd
`252 (252.39-1~deb12u2)`, all ten units, twelve fixed-argv cases and four markers
passed as UID 1000 in 0.694 seconds. Fixture cleanup was verified. The receipt
prints hashes for the ten unit files. These results prove parser and CLI boundaries,
**not** manager-triggered timers, `OnFailure` dispatch, cgroup cleanup, applied
system sandbox restrictions, source/account access, or a real backup publication.

Before an authorized installation is considered accepted, verify the exact
installed bytes/ownership and actual systemd version, then exercise a private
synthetic plan under the real manager: success, explicit failure, missing/stale
evidence, overlap refusal, deadline/subprocess cleanup, both timer schedules, and
the local marker plus approved independent alert path. Confirm the source remains
unmodified and the existing application/database services retain their identities.
Leave all new units/timers disabled until those installation gates are satisfied.

The distinction between system/user sandbox support and managed directories comes
from [systemd.exec](https://www.freedesktop.org/software/systemd/man/latest/systemd.exec.html).
Failure dependencies are documented in
[systemd.unit](https://www.freedesktop.org/software/systemd/man/latest/systemd.unit.html);
calendar catch-up and active-service behavior are documented in
[systemd.timer](https://www.freedesktop.org/software/systemd/man/latest/systemd.timer.html).
