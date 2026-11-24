# Disposable MySQL binary log timer

These three system-manager units are review fixtures for a synthetic MySQL 8.4
source. All are static and have no enablement target. The timer has no on-boot
catch-up. It fires on even UTC minutes when explicitly started under a
separately authorized disposable manager. The placeholders make the unrendered
stock commands refuse their plans.

## Review and permissions

Render each plan SHA-256 as a literal lowercase digest in its service and review
the final unit bytes alongside the complete, pinned tools release. Give the
two operating-system accounts distinct unprivileged UIDs, private primary
groups, no login, and only the supplementary access described below.

| Path or identity | Required fixture contract |
| --- | --- |
| `/opt/repro-binlog-fixture-tools` | Root-owned exact tools, trusted executable dependencies and this directory's `rotation_attempt.py`. The wrapper invokes sibling `rotate_closed_binlogs.py` with fixed argv and the unit's literal plan pin. Install the full archive tool dependency set. Keep ancestors root-owned and non-writable by either account. |
| `/etc/repro-binlog-fixture/rotation.json`, `archive.json` | Root-owned `0640`, each readable only by its service's group. Their pinned collector plan is root-owned `0640` and readable by the dedicated shared `repro-binlog-plan` group. No secrets in these plans. The collector plan pins the base producer receipt, which the archive identity must be able to read with the private-file mode required by the stock tools. |
| `repro-binlog-rotate` | May reach the fixture MySQL Unix socket and read its own mode-`0600` MySQL option file. It has no provider credential, IP networking, or binlog data access. Use the dedicated MySQL invoker, reviewed locked definer and exact stored procedure from the rotation runbook. |
| `repro-binlog-archive` | May reach the same socket with its own metadata-only MySQL credential, read the exact fixture binlog files and read its own credentials-only option file and protected provider config. Match permissions on newly created logs. Limit the provider identity to the approved fixture target and apply reviewed network egress separately. |
| `/run/repro-mysql84-binlog-fixture/mysql.sock`, `/var/lib/repro-mysql84-binlog-fixture` | Exact disposable socket and binlog directory. The source owner and ancestor modes must pass stock collector checks. The archive service's reads occur on a read-only filesystem view. The rotator cannot traverse the binlog tree. |
| `/var/lib/repro-binlog-fixture-rotation`, `/var/lib/repro-binlog-fixture-archive` | New account-private `0700` state directories. Rotation's `runtime_dir` is its managed state directory. Under archive state, provision separate account-owned `0700` checkpoint, spool and publisher directories. Keep account-owned `0600` credentials in separate private directories under the root-owned configuration tree. |

Initialize the archive checkpoint from the verified base receipt before starting
the fixture. The standalone 240-second example in the backup runbook is for
operator-run batches. For this service set `run_timeout_seconds` to at most 100
and `transfer_timeout_seconds` to at least 60 and below the run deadline. The
manager's 110-second ceiling and 10-second stop grace bound the batch and its
subprocesses. Measure whether the reviewed batch size and actual rate of new
files fit this cadence.

## Dispatch and reconciliation

- Before sending a rotation request, the manager creates an exclusive
  `attempt.pending` directory in durable private state and syncs its parent.
  A verified exit 0 and an exact stock exit 1 with
  `rotation_requested: false` release it and sync its parent. The refusal
  remains visible as a failed unit and can be retried next tick. Ambiguous or
  interrupted requests keep the marker and prevent another scheduled call.
- Both stock outcomes and manager-level failures dispatch the same archive
  unit via `OnSuccess` or `OnFailure`. The archive runner independently
  verifies the selected source and begins after the last verified checkpoint.
  It can recover logs closed before a failed rotation report, and an active
  archive service coalesces a new dispatch. Unit failures remain in the local
  journal with each runner's outcome.
- If the guard remains held, stop the timer and verify the rotation cgroup has
  stopped. Review the exact unit result, independently check the fixture's
  active file, closed log inventory and checkpoint using read-only access,
  then catch up by running the archive service alone. Run one batch at a time.
  When `caught_up_to_observed_active_file` is false, run another archive batch.
  Wait for `true` or a verified idle result. An archive pass after an
  interrupted publication can produce a redundant verified batch under a new
  backup ID from the same checkpoint. The checkpoint advances only after
  read-back verifies the contiguous batch. An already advanced checkpoint
  starts at the next file.
- Release the exact empty marker with `rmdir` and resume the reviewed timer
  only after the operator establishes whether the interrupted request rotated
  the log and resolves any unexpected source or checkpoint state. All fixture
  rotations go through this single timer owner. Catch-up invokes only the
  archive service, so draining backlog issues no second rotation call.

## Offline verification

Stage the three public unit files at mode `0644` in a disposable Linux
environment with systemd 249 or newer and the listed standard binaries. Then
run `systemd-analyze verify repro-binlog-fixture-*.service repro-binlog-fixture-*.timer`
and `systemd-analyze calendar '*-*-* *:0/2:00 UTC'`. Run
`python3 -B -m unittest discover -s . -p test_rotation_attempt.py` from this
directory. These checks cover parser compatibility and guard classification.
Actual manager dispatch, service isolation, provider delivery and recovery
timing require separate synthetic evidence.
