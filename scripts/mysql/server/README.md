# Proposed isolated MySQL 8.4 service

These files prepare a separate instance. They do not alter the existing MySQL
8.0 service, the application environment, production SQLite, FPM, or workers.
Nothing in this directory runs automatically during an application deployment.
The selected server must first have Docker Engine and Compose v2 installed and
enabled by its administrator. The current production host does not have Docker.

The pinned MySQL 8.4.13 image is the same artifact used in the rehearsal. It
accepts only a Unix socket, with durable commits/binlogs, strict SQL, a 5 GB
buffer pool, 8 GB memory cap, and 96 connections. The connection budget allows
20 FPM children, the 12 observed queue workers, scheduled commands, and headroom.
Capacity and storage latency still need acceptance on the selected host.

Administrator preparation, after explicit authorization:

1. Reserve UID 999 for the container data and confirm host group `www-data` has
   GID 33. Refuse existing `/etc/repro-mysql84`, `/var/lib/repro-mysql84`,
   `/run/repro-mysql84`, or service names; investigate any collision first.
2. Create `/etc/repro-mysql84` as root:root 0700 and its `secrets` directory as
   root:root 0700. Copy `compose.yaml` as root:root 0600 and `mysql.cnf` as
   root:www-data 0440 (the container's GID 33 must be able to read its mount).
3. Generate different random 64-character hex passwords into `root-password`
   and `app-password` in that secrets directory. Files must be owned by UID 999,
   GID 33, mode 0400; the parent directory stays root-only on the host. Do not
   pass credentials in command arguments, terminal output, or source control.
4. Create `root-client.cnf` in that directory, with the root password and
   `socket=/var/run/mysqld/mysqld.sock`, owner 999:33, mode 0400. Its `[client]`
   section must have `user=root` and the password read from `root-password`.
5. Create the empty data directory as 999:33 0700 and the runtime directory as
   999:www-data 0750. Install the tmpfiles rule under `/etc/tmpfiles.d/` and the
   unit under `/etc/systemd/system/`. Review the rendered Compose configuration
   without printing secrets. Enable/start **only** `repro-mysql84.service`.
6. Record the new server UUID, image digest, schema inventory (initially empty),
   healthy authenticated query, durability/SQL settings, host FPM socket access,
   restart/crash recovery, resource and disk alerts, and a restored backup.
   Install matching 8.4 `mysql`/`mysqldump` clients under
   `/opt/repro-mysql84/client/`; do not replace the existing 8.0 clients.

Later application connection values are `DB_CONNECTION=mysql`,
`DB_DATABASE=repro_dashboard`, `DB_USERNAME=repro_dashboard`,
`DB_SOCKET=/run/repro-mysql84/mysqld.sock`, and
`DB_COLLATION=utf8mb4_0900_bin`. They are a proposed fragment, not an instruction
to edit the active `.env` now. Keep the original APP_KEY and all other provider,
queue, storage, session and URL settings.

The official entrypoint grants the application privileges only on its own
database. Perform import/constraint/trigger DDL through an audited maintenance
account; do not grant global privileges to FPM. Turn
`log_bin_trust_function_creators` on only while installing audited triggers if
the maintenance account needs it, and restore it afterwards. The full suite
tests trigger behavior separately.

Before approving cutover, run the fresh snapshot conversion, exact historical
exception audit, original-data hashes, schema/ID/queue checks, representative
API/media/mobile-web checks, measured load, and backup/restore against this
actual instance. Docker absence, service installation, and host reboot behavior
remain administrator gates; local container success does not close them.

Backups belong on a separate protected volume or off-host storage with a defined
retention policy, disk-space alerts, checksum verification and periodic restore
drills. Binary logs alone are not an off-host backup. Do not start a scheduler or
consume copied customer queues in the rehearsal instance.
