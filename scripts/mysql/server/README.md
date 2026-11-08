# Proposed isolated MySQL 8.4 service

These are local preparation templates, not an installed service. Provision only
the new instance and its log-rotation timer. Keep the existing MySQL 8.0 service,
application environment, production SQLite, FPM, workers, and client binaries
unchanged. Nothing here runs during an application deployment. Do not reboot the
shared host or start copied customer queues to test this instance.

Docker Engine, Compose v2, and logrotate must be available before installation.
The November 7 inventory found no Docker. An administrator must review package
dependencies, service-start hooks, and host firewall/forwarding effects before
installing it; the socket-only Compose setting does not prevent daemon-level
networking changes. Do not change global Docker networking or user namespaces
as part of these templates.

The pinned MySQL 8.4.13 image is the artifact used in the earlier rehearsal. It
accepts only a Unix socket, with durable commits/binlogs, strict SQL, a 5 GB buffer
pool, an 8 GB memory cap, and 96 connections. The budget allows the previously
observed 20 FPM children and 12 workers plus headroom; recheck current counts.
The dedicated identity and log rotation passed the local synthetic checks in
the [November 8 readiness update](../../../docs/mysql-production-readiness.md).
Actual-host permissions, Compose/systemd execution and capacity remain untested.

## Reserve a dedicated identity and refuse collisions

UID **999 belongs to dnsmasq** on the inspected host. The templates instead use
fixed UID **64084**, GID **33**, with host account `repro-mysql84` and group
`www-data`. UID 64084 was unused during inspection; that is not a reservation.
In an administrator's root shell, recheck immediately before account creation:

```sh
test "$(id -u)" -eq 0 || exit 1
if getent passwd 64084 >/dev/null || getent passwd repro-mysql84 >/dev/null; then
    printf '%s\n' 'Refusing an existing UID or service account; investigate first.' >&2
    exit 1
fi
test "$(getent group www-data | cut -d: -f3)" = 33 || exit 1
for path in /etc/repro-mysql84 /var/lib/repro-mysql84 /run/repro-mysql84 \
    /opt/repro-mysql84 /var/lib/repro-mysql84-logrotate \
    /etc/systemd/system/repro-mysql84.service \
    /etc/systemd/system/repro-mysql84-logrotate.service \
    /etc/systemd/system/repro-mysql84-logrotate.timer \
    /etc/tmpfiles.d/repro-mysql84.conf; do
    if test -e "$path" || test -L "$path"; then
        printf 'Refusing existing path: %s\n' "$path" >&2
        exit 1
    fi
done
useradd --system --uid 64084 --gid www-data --no-user-group --no-create-home \
    --home-dir /nonexistent --shell /usr/sbin/nologin repro-mysql84
passwd --lock repro-mysql84
```

Also inspect `systemctl list-unit-files 'repro-mysql84*'` and existing Compose
project/container names for collisions. Never repurpose an existing account,
chown an existing database, or overwrite a unit to make these checks pass.
If a different identity is necessary, change all ownership references together
and repeat the isolated runtime tests before installation.

## Administrator installation paths

Create `/etc/repro-mysql84` and its `secrets` directory as root:root 0700. Create
an **empty** `/var/lib/repro-mysql84` as 64084:33 0700 and `/run/repro-mysql84` as
64084:33 0750. Install only the reviewed files at these destinations:

| Source | Destination | Owner / mode |
| --- | --- | --- |
| `compose.yaml` | `/etc/repro-mysql84/compose.yaml` | root:root 0600 |
| `mysql.cnf` | `/etc/repro-mysql84/mysql.cnf` | root:www-data 0440 |
| `repro-mysql84.tmpfiles.conf` | `/etc/tmpfiles.d/repro-mysql84.conf` | root:root 0644 |
| `repro-mysql84.service` | `/etc/systemd/system/repro-mysql84.service` | root:root 0644 |
| `repro-mysql84.logrotate.conf` | `/etc/repro-mysql84/logrotate.conf` | root:root 0600 |
| `repro-mysql84-logrotate.service` | `/etc/systemd/system/repro-mysql84-logrotate.service` | root:root 0644 |
| `repro-mysql84-logrotate.timer` | `/etc/systemd/system/repro-mysql84-logrotate.timer` | root:root 0644 |

Generate different random 64-character hex passwords into `root-password` and
`app-password` under the protected secrets directory. Own both as 64084:33 0400.
Create `root-client.cnf` there as 64084:33 0400 with a `[client]` section containing
`user=root`, the root password read from its file, and
`socket=/var/run/mysqld/mysqld.sock`. Do not print credentials or put them in
command arguments, source control, reports, or chat. The container can read these
file mounts while the parent host directory remains root-only.

Review `docker compose --project-name repro-mysql84 --file
/etc/repro-mysql84/compose.yaml config --quiet`, verify the pinned image is pulled,
and run `systemd-analyze verify` on the three new units. Validate logrotate with
`logrotate --debug --state /dev/null /etc/repro-mysql84/logrotate.conf`.
Use `systemd-tmpfiles --create /etc/tmpfiles.d/repro-mysql84.conf` only for this
new runtime directory. After `systemctl daemon-reload`, enable/start only
`repro-mysql84.service`; once authenticated health passes, enable/start only
`repro-mysql84-logrotate.timer`. Do not restart Docker or existing services as a
shortcut. State for this timer is created automatically as root:root 0700 under
`/var/lib/repro-mysql84-logrotate`.

Install matching 8.4 `mysql`/`mysqldump` clients under
`/opt/repro-mysql84/client/`, retaining existing 8.0 clients. Record the new server
UUID, image digest, initial schema inventory, authenticated health, SQL/durability
settings, socket access as www-data and maverick, and account isolation.

## Log rotation and operator checks

Compose limits this container's stdout/stderr through Docker's `json-file` driver
to three 10 MB files. This is a per-container setting; do not edit daemon defaults
or manipulate Docker's log files with logrotate. See
[Docker's logging options](https://docs.docker.com/engine/logging/drivers/json-file/).

The dedicated hourly timer checks `slow.log`: rotate daily or above 100 MB, retain
seven rotated files, and compress with one rotation's delay. It uses its own
state and config, outside `/etc/logrotate.d`, so the global logrotate schedule
does not rotate it twice. Rotation renames the log, creates a 0600 replacement
owned by the dedicated account, then runs authenticated `FLUSH SLOW LOGS` through
the exact Compose project. The rotation service's authenticated `ExecStartPre`
check is required: if MySQL is already unreachable, logrotate is not invoked and
no log files are shifted. The in-config `prerotate` query is a second check before
renaming the active log. Logrotate can shift/compress older archives **before**
that hook, so it is not a replacement for the service preflight. A failure after
the preflight can still leave partially shifted archives; rotation is not atomic.
There is no `copytruncate`, server restart, credential output, or ignored flush
error. This follows
[MySQL's log reopen procedure](https://dev.mysql.com/doc/refman/8.4/en/log-file-maintenance.html).

An hourly size check is **not a hard disk quota**: the active file may exceed
100 MB between checks. Monitor free space and log growth. Check timer execution
and the rotation service's journal/failed state; an active oneshot database unit
alone is not a health monitor. If reopening fails, preserve `slow.log.1` and
resolve the failure before forcing another rotation; MySQL may still be writing
to the renamed file. Binlog expiry is separate from slow-log rotation and is
not an off-host backup.

## Isolated runtime validation before host installation

Use a disposable local Linux fixture with no production mounts or credentials,
a separate Compose project, fresh data/socket directories, and fixture secrets.
Keep the pinned image, 64084:33, readonly mounts, hardening, and runtime limits;
override only host paths and the project name. Provision the same test account
inside the fixture running logrotate, and point a **copy** of the rotation config
at the fixture paths/project and its own state file. Do not run a forced rotation
against these literal server paths during local testing.

Verify fresh initialization, effective UID/GID, authenticated health, socket
access/denial by relevant users, no TCP listeners, durable settings, and committed
synthetic rows surviving a container crash/restart. Generate identifiable slow
queries, force two fixture rotations, and prove queries after each reopen reach
the new `slow.log` while the prior inode stops growing. Check ownership, 0600
files, delayed compression, and the seven-file retention limit. With the fixture
database stopped, verify the service's authenticated preflight fails before
logrotate is invoked and leaves every active/archived log unchanged. Separately
verify that an in-config pre-hook failure preserves the active log, while
recording any earlier archive shifts rather than claiming an atomic failure.
Validate the timer/calendar and logrotate syntax in Linux. Static review or
Compose parsing alone cannot establish these runtime results.

## Separate cutover and operational acceptance

Future application values are `DB_CONNECTION=mysql`, `DB_DATABASE=repro_dashboard`,
`DB_USERNAME=repro_dashboard`, `DB_SOCKET=/run/repro-mysql84/mysqld.sock`, and
`DB_COLLATION=utf8mb4_0900_bin`. They are a proposed fragment, not an instruction
to edit the active `.env`. Preserve APP_KEY and unrelated settings.

The application account is scoped to its own database. Use an audited maintenance
account for import/constraints/triggers, and restore any temporary
`log_bin_trust_function_creators` setting after reviewed DDL. Before cutover,
repeat fresh-snapshot conversion, exact historical exceptions, data/schema/ID
parity, representative APIs/media/mobile-web checks, measured load and independent
backup restore on the actual instance. Monitor shared-host I/O and live latency;
CPU/RAM caps do not isolate disk contention. Actual boot recovery remains unproven
without a separately authorized host reboot.

Define protected off-host backup storage, retention, checksum checks, disk alerts,
and restore drills. Do not start schedulers or consume copied customer jobs in
the rehearsal instance. Local results do not replace actual-host acceptance or
authorize the application database switch.
