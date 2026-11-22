# Native MySQL 8.4.13 preparation

This is a **new, disabled, socket-only instance**, not an upgrade of the existing
MySQL 8.0 service and not a production database cutover. No host installation has
been performed by preparing these files. The installer never downloads packages,
changes firewall/sysctl settings, starts/enables a service, edits the app, or
removes a prior installation. Existing `mysql.service` must stay active with the
same PID and systemd invocation ID through installation.

## Fixed scope

| Item | Exact value |
| --- | --- |
| Supported initial host | Ubuntu 24.04, amd64, systemd, cgroup v2 |
| Vendor server | MySQL Community 8.4.13, Oracle Ubuntu 24.04 amd64 server-core package |
| Bundled clients | MySQL Community 8.4.13, Oracle Linux generic archive |
| Service user and primary group | `repro-mysql84-native`, UID/GID **64084** |
| Socket access group | Existing `www-data`, GID **33** |
| Executables | `/opt/repro-mysql84-native/mysql-8.4.13/bin/` |
| Installed operations | `/opt/repro-mysql84-native/ops/` |
| Configuration | `/etc/repro-mysql84-native/` |
| Data and temporary files | `/var/lib/repro-mysql84-native/{data,tmp}` |
| Logs | `/var/log/repro-mysql84-native/` |
| Socket | `/run/repro-mysql84-native/mysql.sock` |
| Service | `repro-mysql84-native.service` |
| Rotation | `repro-mysql84-native-logrotate.{service,timer}` |

64084 was only a previous candidate: **any account/name/UID/GID collision refuses
installation**, even if it looks like an old RePro account. Never reuse UID 999
(previously the host's dnsmasq account). No user-namespace remapping is required.
No existing `/etc/mysql`, `/var/lib/mysql`, `mysql.service`, system client path,
or worker membership is changed. A pre-existing target path also refuses the
operation. Review the actual application worker groups before using the socket.

The account has no login shell or created home. Data/log directories are 0700;
only the runtime directory is traversable by `www-data` (0750). A fixed privileged
unit precommand changes that new directory's group. The database process retains
only its dedicated group, so it cannot inherit access to unrelated `www-data`
files. It cannot open IP sockets and has a private network
namespace. The account is not granted sudo.

## Prerequisites and administrator handoff

1. Review the [official 8.4.13 release](https://dev.mysql.com/doc/relnotes/mysql/8.4/en/news-8-4-13.html)
   and obtain the matching amd64 generic archive from
   [MySQL downloads](https://dev.mysql.com/downloads/mysql/8.4.html).
   Verify the archive signature and the Noble `InRelease` signature using the
   independently obtained Oracle key with fingerprint
   `BCA43417C3B485DD128EC6D4B7B3B788A8D3785C`. Check `Packages.gz` against the
   signed `InRelease` and check the server-core package against that index.
   Record the download URLs and verified checksums in the change evidence.

   | Verified input | SHA-256 |
   | --- | --- |
   | Linux generic client archive | `5dfbb764f731df40ef9e4f5d69aed32bdac8ba1af20bd96b7d40a942d83d4ed2` |
   | [Noble amd64 server-core 8.4.13](https://repo.mysql.com/apt/ubuntu/pool/mysql-8.4-lts/m/mysql-community/mysql-community-server-core_8.4.13-1ubuntu24.04_amd64.deb) | `66269aaf941bee02390df98c7b69ee01eb918298a18f10882202d113aa0f1e62` |
   | [Noble 8.4 LTS amd64 Packages.gz](https://repo.mysql.com/apt/ubuntu/dists/noble/mysql-8.4-lts/binary-amd64/Packages.gz) | `40f0dba6f80a7f587750b09a601b15b06b48ee7104eb1d3a04e75e294009b7d6` |

   Assemble the bundle in a fresh staging area. Start from the verified generic
   tree and extract server-core to a separate staging directory with
   `dpkg-deb --extract`. Map only these Noble payload paths into the bundle:

   | Extracted server-core path | Bundle path |
   | --- | --- |
   | `usr/sbin/mysqld` | `bin/mysqld` |
   | `usr/lib/mysql/private/` | `lib/mysql/private/` |
   | `usr/lib/mysql/plugin/` | `lib/mysql/plugin/` |
   | `usr/share/mysql-8.4/` | `share/mysql-8.4/` |

   Retain the generic clients, their libraries, and `share/charsets/` where
   supplied. The Noble server uses Ubuntu's `libaio.so.1t64`. Its relative
   loader path resolves private protobuf from this bundle. Runtime and
   initialization name the Noble plugin and message paths explicitly. Extracting
   archive contents into staging avoids package installation scripts, service
   hooks, and changes to the existing MySQL package or configuration.

   MySQL Shell is not installed or assumed by this package; its backup tooling
   needs a separately pinned compatible release and its own acceptance.
2. Before elevated execution, stage the reviewed **whole native directory** at
   `/opt/repro-mysql84-stage/ops`, and the unpacked vendor tree at
   `/opt/repro-mysql84-stage/bundle`. All components must be root-owned and not
   group/other writable. Root must review the staged script's checksum against
   the approved checkout before executing it. Do not run a privileged script or
   Python import from a deploy-user-writable checkout or `/tmp`. Staging is an
   administrator operation; no shell password belongs in chat.
3. Verify the actual binary dependencies. The script checks each of `mysqld`,
   `mysql`, `mysqladmin`, `mysqlbinlog`, and `mysqldump` for exact version and `ldd`
   resolution. It additionally requires the Noble server's `libaio.so.1t64` and
   verifies that its private protobuf resolves within the staged bundle.
   Missing dependencies are a no-go. Do not add global compatibility symlinks,
   replace shared system libraries, remove MySQL 8.0 or disable AppArmor to pass.
   The generic server's `libaio.so.1` dependency is why this bundle uses the
   Noble server-core binary. [Oracle generic binary guidance](https://dev.mysql.com/doc/refman/8.4/en/binary-installation.html)
4. `python3`, `chgrp`, `systemd-analyze`, `systemctl`, `logrotate`, `useradd` and `groupadd`
   must already exist. If a prerequisite is missing, review a package simulation
   and service restart policy separately; this installer never runs APT.
   Confirm `/opt`, `/var/lib`, `/var/log`, `/etc` resolve to the intended local
   filesystems. The 40 GiB free-space floor is an installation check, not capacity
   acceptance for the workload.
5. Generate the bundle manifest and record the exact intended host identity in the
   administrator's terminal. The manifest includes all files and contained relative
   symlinks; critical executables must be regular files. These commands do not start
   MySQL. Shell redirects below write only to the root-owned staging directory.

   ```sh
   /usr/bin/python3 -I /opt/repro-mysql84-stage/ops/native_mysql.py manifest \
     /opt/repro-mysql84-stage/bundle > /opt/repro-mysql84-stage/bundle-manifest.json
   chmod 0644 /opt/repro-mysql84-stage/bundle-manifest.json
   hostname
   sha256sum /etc/machine-id /opt/repro-mysql84-stage/bundle-manifest.json
   ```

6. Substitute the **reviewed literal values** for the three placeholders. Run the
   read-only preflight first. It is usable without root once staging is readable;
   it executes only the signature/manifest-verified vendor `--version` commands.

   ```sh
   /usr/bin/python3 -I /opt/repro-mysql84-stage/ops/native_mysql.py preflight \
     --bundle /opt/repro-mysql84-stage/bundle \
     --manifest /opt/repro-mysql84-stage/bundle-manifest.json \
     --manifest-sha256 APPROVED_MANIFEST_SHA256 \
     --expect-host APPROVED_HOSTNAME \
     --machine-id-sha256 APPROVED_MACHINE_ID_SHA256
   ```

   After the operator reviews a pass, use the identical arguments with `install`
   in an actual administrator terminal. It repeats every preflight, creates the
   dedicated account and new directories, copies and verifies the bundle, installs
   the three units and calls `daemon-reload`. It does **not** start or enable them.
   The install makes directories inside the copied vendor tree traversable by
   the service account and local clients. Regular-file modes and contents stay
   pinned to the bundle manifest. The staging tree retains its private permissions.
   A failure may leave a partial disabled installation; stop and inspect the exact
   new paths. There is intentionally no recursive/destructive automatic undo.
7. Initialize **only** the new empty data directory with the installed operation:

   ```sh
   /usr/bin/python3 -I /opt/repro-mysql84-native/ops/native_mysql.py initialize
   ```

   This runs vendor `--initialize` with explicit directory, user, log, plugin,
   and message options, and generates a temporary root password. It checks
   the resulting `auto.cnf` UUID, and pins it in `identity.json`. The temporary root
   password remains in the private error log. Inspect it only in the administrator
   terminal; do not paste the log or put passwords in command arguments. A failed
   initialization is **not retried over existing data**.
8. Review AppArmor enforcement for the new absolute binary/data paths; do not
   modify or unload the existing MySQL profile. Then the administrator may explicitly
   start **only** `repro-mysql84-native.service`. Its startup guard verifies the host,
   installed files, configuration, account and data UUID before invoking mysqld.
   `wait-ready` checks that the server responds; it is not authenticated acceptance.

   Use only the pinned client and socket to set the expired root password and
   create a dedicated `repro_logrotate`@`localhost` account. It needs only `RELOAD`
   for flushing logs, not application privileges. Use interactive password entry
   (`--password`, with no value) and disable client history before administrative
   SQL; store chosen credentials outside chat. Create root-owned **0600**
   `/etc/repro-mysql84-native/maintenance.cnf` using a secure editor:

   ```ini
   [client]
   user=repro_logrotate
   password=ENTER_PRIVATELY
   ```

   The pinned interactive client invocation is:

   ```sh
   MYSQL_HISTFILE=/dev/null /opt/repro-mysql84-native/mysql-8.4.13/bin/mysql \
     --no-defaults --no-login-paths --protocol=SOCKET \
     --socket=/run/repro-mysql84-native/mysql.sock --user=root \
     --connect-expired-password --password
   ```

   Authentication uses `caching_sha2_password`; do not enable legacy native password
   authentication. The helper always overrides protocol/socket and ignores login
   paths. The new app and migrator accounts are a separate privilege review.
9. Run authenticated acceptance, then explicitly enable the rotation timer only
   after its runtime test passes. Enabling database boot startup is another explicit
   operator action after restart/boot acceptance; installation itself never enables it.

   ```sh
   /usr/bin/python3 -I /opt/repro-mysql84-native/ops/native_mysql.py check
   systemctl start repro-mysql84-native-logrotate.service
   ```

   `check` compares UUID, exact version, data/socket paths, networking, durability,
   GTID, event scheduler and persisted-settings flags. A check is read-only. Only
   `flush-logs` sends the two FLUSH statements. Do not use an unqualified `mysql`,
   `mysqlbinlog` or `mysqldump`: the host PATH may still point to 8.0.

The installed bundle (including libraries/plugins), configuration and actual unit
files are checked against the receipt. Unexpected systemd drop-ins are refused;
do not override resource or security controls behind that receipt. A reviewed
configuration/version change needs a matching updated package and acceptance,
not a manual receipt edit to silence the guard.

## Resource, log and recovery boundaries

Initial settings: 4 GiB buffer pool, 6 GiB memory high threshold, 8 GiB hard cap,
no swap, four CPU equivalents, 96 connections. These are conservative test
budgets, not a capacity claim. Account for PHP-FPM and all actual queue workers,
backup overhead and media processing before adjusting. No shared-host automatic
InnoDB sizing, global OOM changes, disk repartitioning or service reordering.

Slow/error logs rotate daily or above 100 MiB at the hourly check, retain seven
archives and reopen through an authenticated, identity-checked native client.
Do not install this config under the global `/etc/logrotate.d` as well. The
systemd precheck is required: an offline in-config prerotate alone can still let
logrotate rename old archives. A failure mid-rotation can partially shift archives;
this is not an atomic rotation guarantee. There is no `copytruncate`. Log growth
between hourly checks must be monitored; journal messages have per-unit rate limits
without changing the host's journal policy.

Binary logs are **not** application logs. Automatic age purging is disabled so an
unuploaded gap cannot be silently removed. The 256 MiB setting is per file, not a
total bound. An archive-aware purge, free-space/oldest-unarchived alerts, verified
off-host base plus contiguous logs and timed clean-host restore are outstanding
production requirements. This package supplies none of those services and must
not be described as production-ready without them. Preserve `sync_binlog=1`,
`innodb_flush_log_at_trx_commit=1` and doublewrite during performance testing.

This package grants no passwordless sudo. Any future delegation must protect the
entire executable/configuration chain and constrain exact service, UUID and paths.
It contains no app cutover/boot fence and must not be wired to production writers
until the separate cutover state machine and boot-persistent writer fence are proven.

## Verification and limits

Portable, host-free tests:

```sh
python -B -m unittest discover -s scripts/mysql/native -p 'test_*.py' -v
```

They test manifest tampering, traversal, missing clients, collision refusal,
wrong-platform refusal, nonempty initialization, error redaction, unsafe live
identity/settings, offline rotation refusal, and static isolation/durability rules.
They do not establish Linux ABI compatibility or systemd/MySQL runtime behavior.

Before any real-host install, run the whole handoff on an isolated Ubuntu 24.04
VM with a disposable existing MySQL 8.0 fixture. Validate systemd units, init,
authenticated acceptance, real app-user socket permissions, no TCP listeners,
stops/restarts, log inode changes/ownership/compression/retention and offline timer
refusal. Crash-test only that disposable database. Compare the original 8.0 PID,
invocation ID, configuration/data hashes and network/firewall inventory before/after.
Use synthetic data and provider-free accounts. Do not run this fixed-path installer
directly on the developer's workstation or substitute production for the fixture.

Actual-host acceptance additionally needs peak-load headroom, storage health,
backup/restore timing, operator availability and the application's full compatibility
checks. Recheck the current maintenance release and security notices before a later
installation; a version change requires a new manifest, tests and review. See
[independent instances](https://dev.mysql.com/doc/refman/8.4/en/multiple-servers.html)
for why every path/configuration must be isolated.
