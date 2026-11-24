# Encrypted off-site publication gate

This package validates already completed backup artifacts, publishes them through
an explicitly supplied rclone crypt configuration, and streams every decrypted
remote object through SHA-256 before marking the batch complete. Application and
database backups use separate manifests and prefixes. It does not create a
database snapshot, install rclone, configure a bucket, read a live database, or
claim that a backup can be restored.

Fixture acceptance used signed rclone 1.76.0 with a private HTTPS S3-compatible
endpoint and synthetic credentials. The publisher verified encrypted names and
contents. A separate container recovered and checked a SQLite database with
no producer files mounted. The real private bucket, lock rules and independent
MySQL recovery remain acceptance gates.

## Input contract

For a completed SQLite backup from `scripts/deploy/backup-database.php`, use the
local staging command to build the publisher's manifest. Supply the independently
recorded SHA-256 of the producer's `database-backup.json` and the reviewed release
and SQLite lineage. Staging checks the data hash, table count and SQLite integrity,
refuses open backups and sidecars, and creates `input-manifest.json` exclusively
in the private backup directory. Pass its reported manifest digest to the publisher.

```sh
/usr/bin/python3 -I /opt/repro-backup-tools/prepare_sqlite_backup.py \
  --source /var/lib/repro-backups/staged/closed-sqlite \
  --producer-sha256 REVIEWED_PRODUCER_RECEIPT_SHA256 \
  --expected-release APPROVED_FULL_COMMIT \
  --expected-identity APPROVED_SQLITE_LINEAGE
```

For MySQL 8.4, run the reviewed database producer in `--pitr` mode with the
approved source UUID and backup account. Once it exits successfully and closes
the batch, independently record the SHA-256 of `database-backup.json`. Stage
that receipt and `database.sql.gz` with the approved release, source UUID and
database name:

```sh
/usr/bin/python3 -I /opt/repro-backup-tools/prepare_mysql_backup.py \
  --source /var/lib/repro-backups/staged/closed-mysql \
  --producer-sha256 REVIEWED_PRODUCER_RECEIPT_SHA256 \
  --expected-release APPROVED_FULL_COMMIT \
  --expected-identity APPROVED_MYSQL_SOURCE_UUID \
  --expected-database APPROVED_MYSQL_DATABASE
```

Staging verifies the compressed dump's size and hash, gzip integrity, full
expanded length, completion marker and the snapshot position recorded by
`mysqldump`. It creates the same `input-manifest.json` used by the publisher.
Pass the staging result's `manifest_sha256` to the publication gate. Keep
archiving the binlog sequence separately and verify continuity from the
snapshot position to the desired recovery point during an independent restore.

The upstream backup producer must close every artifact and stop writing to the
source directory before invoking this gate. Create a fresh UUID for each attempt;
abandon failed prefixes rather than resuming them. A backup's input manifest is a
UTF-8 JSON file with exactly these fields (hashes below are placeholders):

```json
{
  "schema": "repro.offsite.input.v1",
  "backup_id": "7ca011d3-b197-48c6-99d0-af07d99d3c08",
  "created_at": "2026-11-22T10:00:00Z",
  "scope": "database",
  "release": "FULL_LOWERCASE_GIT_COMMIT_40_OR_64_HEX_DIGITS",
  "identity": "EXPECTED_INSTANCE_ID",
  "artifacts": [
    {"path": "base.sql.zst", "bytes": 123, "sha256": "64_LOWERCASE_HEX_DIGITS"},
    {"path": "metadata/checkpoint.json", "bytes": 456, "sha256": "64_LOWERCASE_HEX_DIGITS"}
  ]
}
```

- `scope` is `database` or `application`; run them independently. Use the
  application scope for an approved recovery kit and application artifacts.
  Contents are not classified by this tool, so the producer owns that distinction.
- `identity` is a lowercase identifier of at most 128 characters, using letters,
  digits, dots, underscores and hyphens. Use the exact reviewed server UUID for a
  MySQL instance, or the approved snapshot lineage identifier for SQLite. It is
  compared with an independent CLI expectation, as is the release commit. This
  comparison does not inspect a dump and prove its database identity.
- Paths are relative, with simple ASCII names, no traversal, duplicate paths,
  file/directory collisions, symlinks, hard links or special files. Package
  nonconforming producer filenames inside a closed archive first. Nested
  artifacts retain their relative paths.
- Include producer metadata as an artifact: engine/version, release, source
  identity, consistency result, full/differential kind, and an exact checked
  binlog/GTID recovery position when applicable. The producer/restore gate must
  validate its meaning; this publisher validates its bytes only.
- Supply the input manifest's SHA-256 from the producer's verified receipt.
  Rehashing an unknown manifest and calling it approved is not an identity gate.

## Operator prerequisites

Run as the dedicated backup account on Linux. The source root and persistent
workdir must be owned by that account with mode `0700`; manifest, config and all
artifact files must be owned by it with mode `0600`. Ancestors must belong to root
or that account, have no group/other write permissions, and contain no symlinks.
Therefore `/tmp` and a broadly writable mount root are intentionally refused;
an administrator must prepare an appropriate private location without changing
shared media or application permissions.

Install the reviewed script in a root-owned path outside the application checkout,
and use `/usr/bin/python3 -I`. The explicit rclone executable and its ancestors
must be root-owned and not group/other writable. No other programs or packages are
installed here. Record the installed rclone version and validate its supported
flags in the isolated acceptance environment before scheduling this gate.

Provide an existing, private `0600` rclone configuration. The first delivery
supports exactly two named sections: one `crypt` remote wrapping one `s3` remote
(including Cloudflare R2), with a fixed bucket and dedicated prefix. It rejects
local/alias/chained backends, ambient credentials, unrelated sections and
unreviewed backend options. The plaintext configuration contains sensitive
credentials and lightly obscured crypt passwords; `0600` is essential. A
password-encrypted rclone config is not supported by this noninteractive gate.

Required crypt options are `type=crypt`, the exact approved `remote`, nonempty
`password` and `password2`, standard filename encryption and directory encryption
(the last two may use their safe defaults). Data encryption must remain enabled.
The S3 section requires `type=s3`, explicit `access_key_id`/`secret_access_key`,
`env_auth=false`, and `no_check_bucket=true`. An explicit endpoint must use HTTPS.
The optional `session_token` supports short-lived credentials. Other optional
S3 settings are provider, region, endpoint, location_constraint and acl. Use the
reviewed configuration; this package does not generate,
print or prompt for keys. Ambient rclone, AWS and proxy environment overrides are
removed from child processes. A validated config snapshot is kept only inside a
private temporary directory during the run.

### Cloudflare R2 setup for acceptance

Create a dedicated private bucket for backups. Keep its development URL and
custom domains disabled. Give the publisher and the separate recovery machine
different credentials scoped to that bucket. Keep the configuration-management
credentials separate from both machines. [Create a bucket](https://developers.cloudflare.com/r2/buckets/create-buckets/)
and [configure access](https://developers.cloudflare.com/r2/api/tokens/).

Use a fixed outer prefix for each retention class, such as `daily/`,
`monthly/` and `final-sqlite/`. Put that prefix in the crypt remote's
`remote=storage:BUCKET/PREFIX` setting so it remains visible to the bucket
rules. The logical artifact names beneath it are encrypted. For the plan's
retention, lock daily backups and binlogs for 30 days and the monthly backups
and final SQLite copy for 12 months. Apply matching expiry rules at or after
the corresponding lock periods. [Bucket locks](https://developers.cloudflare.com/r2/buckets/bucket-locks/)
and [object lifecycles](https://developers.cloudflare.com/r2/buckets/object-lifecycles/).

For the publisher, supply short-lived credentials with the list, read, write
and multipart operations needed by the tested rclone release. Exclude object
deletion and bucket management. The recovery config only needs independent
read access and the separately held crypt material. Validate the actual
permissions and expiry with synthetic artifacts before configuring recurring
publication. Use a disposable synthetic key to prove an overwrite is refused
by the lock and a delete is refused by the uploader's permissions. A separate
recovery machine must read and decrypt the synthetic backup after the
publication machine's copy is unavailable. [R2 temporary credentials](https://developers.cloudflare.com/r2/api/s3/temporary-credentials/)
and [rclone session tokens](https://rclone.org/s3/#s3-session-token).

## Exact invocation

These are operator commands for a future approved fixture or backup job, not
commands executed during this delivery. Substitute reviewed paths and values.
First validate local inputs, without invoking rclone or contacting storage:

```sh
/usr/bin/python3 -I /opt/repro-backup-tools/publish_backup.py \
  --manifest /var/lib/repro-backups/staged/manifest.json \
  --manifest-sha256 APPROVED_MANIFEST_SHA256 \
  --source /var/lib/repro-backups/staged/artifacts \
  --scope database \
  --expected-release APPROVED_FULL_COMMIT \
  --expected-identity APPROVED_INSTANCE_ID \
  --config /var/lib/repro-backups/credentials/rclone.conf \
  --rclone /usr/bin/rclone \
  --crypt-remote repro_encrypted \
  --expected-destination storage:APPROVED_BUCKET/APPROVED_PREFIX \
  --workdir /var/lib/repro-backups/publish-state \
  --check-local-only
```

After off-host access is authorized/configured, invoke the same command without
`--check-local-only`. That operation makes remote reads and writes. Output is a
credential-free JSON receipt. The persistent workdir receives a private attempt
record before network access and a receipt only on success. Preserve this workdir
across runs; its lock prevents concurrent publishers sharing that workdir. Use one
producer per scope/instance; it is not a distributed lock across separate hosts.

For an application recovery-kit batch, use a different source manifest, fresh
UUID, and `--scope application`. Separate approved crypt configurations/keys may
also be used if the account design requires them.

## Publication and failure behavior

The encrypted logical destination is
`CRYPT_REMOTE:SCOPE/IDENTITY/BACKUP_UUID/`. The configured crypt root supplies the
fixed bucket/prefix. The gate refuses a nonempty destination, verifies all local
artifacts, and uses only `rclone copy --immutable --no-update-modtime` for writes.
It uploads `artifacts/...`, reads each back through `rclone cat` on the crypt
remote, checks full decrypted length and SHA-256, and rechecks the local source.
It then uploads and reads back `input-manifest.json`. The final remote write is
`completion.json`; that record is also read back and hashed before success.

All provider stderr is suppressed to avoid leaking credentials, paths or payloads.
Plaintext remote bytes go directly to the hash loop, never to terminal output or
a local downloaded file. Transfers use a 16 MiB/s bandwidth cap, one transfer,
and bounded retries. The default deadline is one hour per subprocess; an explicit
`--timeout-seconds` between 60 and 21600 adjusts it. Source/destination bytes are
checked even when rclone skips an apparently unchanged file.
Before network access, the tool rejects any artifact exceeding the configured
bandwidth/deadline budget, reserving 30 seconds and 20% headroom. That allows about
44 GiB per artifact with the default or 269 GiB with the maximum deadline. Split
larger closed archives in the producer and list every part. Slower networks can
still time out; these limits are not a throughput guarantee.

Any failure returns a nonzero exit code. There is no delete, sync, purge,
configuration rewrite, overwrite retry, or cleanup of partially uploaded objects.
Abandon the UUID and retain its attempt record. A failure during final-marker
readback can leave a marker remotely; without the verified receipt it is not an
accepted run. Recovery must independently verify the marker, input manifest and
every artifact.

## Independent retrieval

Install the two reviewed Python scripts together in the same root-owned tools
directory. Give the restore operator an independently verified publication
receipt and a separate private rclone configuration scoped to reads. Use the
receipt's exact backup ID, manifest and completion sizes and digests. The
retrieval process needs no files from the publication machine.

```sh
/usr/bin/python3 -I -B /opt/repro-backup-tools/restore_backup.py \
  --backup-id APPROVED_BACKUP_ID --scope database \
  --expected-release APPROVED_FULL_COMMIT --expected-identity APPROVED_INSTANCE_ID \
  --manifest-bytes APPROVED_MANIFEST_SIZE --manifest-sha256 APPROVED_MANIFEST_SHA256 \
  --completion-bytes APPROVED_COMPLETION_SIZE --completion-sha256 APPROVED_COMPLETION_SHA256 \
  --config /var/lib/repro-recovery/credentials/rclone.conf --rclone /usr/bin/rclone \
  --crypt-remote APPROVED_CRYPT_NAME --expected-destination APPROVED_S3_BUCKET_PREFIX \
  --output /var/lib/repro-recovery/private/new-recovery
```

The restore output has a new `0700` directory name under an operator-owned
`0700` parent. The tool verifies the completion record against the input
manifest and writes only reviewed objects, each to a private file checked by
size and SHA-256. It publishes the result tree and `recovery-receipt.json`
after all checks pass. A failure before publication leaves the approved
destination available for a clean attempt. An interruption during the final
reservation can leave an empty directory.

An artifact recovery receipt proves the downloaded bytes. Separately load the
base backup into the isolated recovery instance, verify the recovery point
and database results, and test decryption with the separately held key.

### Check whether the latest published snapshot is fresh

Run the local checker with the latest accepted publication receipt and its
original input manifest. Supply the receipt hash from the verified publication
job or an independently reviewed copy. An attempted upload has no success
receipt. Keep the evidence files in protected Linux storage.

```sh
/usr/bin/python3 -I -B /opt/repro-backup-tools/check_backup_freshness.py \
  --receipt /var/lib/repro-backups/evidence/latest-publication.json \
  --receipt-sha256 REVIEWED_PUBLICATION_RECEIPT_SHA256 \
  --manifest /var/lib/repro-backups/evidence/latest-input-manifest.json \
  --scope database --expected-release APPROVED_FULL_COMMIT \
  --expected-identity APPROVED_INSTANCE_ID --crypt-remote APPROVED_CRYPT_NAME
```

Exit zero means the accepted snapshot is younger than 26 hours. Exit one
reports stale, missing or invalid evidence. The clock is local UTC and the
age starts at the snapshot time recorded in the manifest. Reuploading an
old snapshot leaves its age intact. An alternate positive limit can be set
with `--max-age-seconds`. This checks evidence from completed publication.
It makes no request to cloud storage. Use separate monitoring for binlog
lag, retention and restore success. Configure the timer and alert destination
only after reviewing those controls.

### Schedule verified base backups

Install `scheduled_backup.py`, both preparation scripts, the publisher and
freshness checker alongside the reviewed `backup-database.php` and
`backup-sqlite.php` deployment tools in the root-owned tools directory.
Run the coordinator as a dedicated account with its own `0700` state directory.
The plan is a regular root-owned `0640` file readable by that account. It lists
identity and paths. Credentials remain in separate private files.

```json
{
  "version": 1,
  "driver": "sqlite",
  "release": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
  "identity": "isolated-sqlite-copy",
  "app_root": "/srv/repro-isolated/app",
  "state_dir": "/var/lib/repro-backup",
  "rclone_config": "/var/lib/repro-backup/credentials/rclone.conf",
  "php": "/usr/bin/php8.3",
  "rclone": "/usr/bin/rclone",
  "crypt_remote": "repro_encrypted",
  "destination": "storage:APPROVED_BUCKET/APPROVED_PREFIX",
  "max_age_seconds": 93600,
  "run_timeout_seconds": 1800,
  "transfer_timeout_seconds": 900,
  "mysql": null
}
```

For MySQL, set `driver` to `mysql`, set `identity` to the expected server
UUID, and supply `mysql.database`, `mysql.defaults_file`, `mysql.client` and
`mysql.dump`. The defaults file is a private credentials-only option file.
Use resolved executable paths. The stock MySQL producer checks the exact server
and client versions and records the base snapshot's starting binlog coordinate.

```sh
/usr/bin/python3 -I -B /opt/repro-backup-tools/scheduled_backup.py run --plan /etc/repro-backup/plan.json
/usr/bin/python3 -I -B /opt/repro-backup-tools/scheduled_backup.py check --plan /etc/repro-backup/plan.json
```

Each run acquires an exclusive lock, makes a fresh private snapshot, stages
and publishes it, and verifies the receipt. It atomically records success
after staging cleanup. A failed attempt preserves the previous verified receipt
and returns nonzero immediately. The local health check reads that state and
returns nonzero for missing, failed or stale evidence. Changes to the reviewed
plan require a fresh successful backup. Interrupted processes and partially
uploaded UUIDs are treated as failed attempts. Inspect and remove abandoned
private staging files only after confirming the service has stopped.

This schedule covers base snapshots. Schedule closed-binlog archival and check
its lag independently. Verify the protected destination, independent restores
and alert delivery before relying on this schedule during cutover. See the
[systemd unit and rehearsal instructions](systemd/README.md).

### Stage closed MySQL logs for archival

Supply protected copies of the already closed log files in a dedicated
directory. Include a pinned `closed-binlogs.json` with
`schema=repro.mysql.closed-binlogs.v1`, the original `source_uuid` and
`server_id`, a UTC `sealed_at` timestamp, the rotated-to `next_file`, and
ordered `segments` with `file`, `bytes` and `sha256`. Export and pin the
inventory through the reviewed collector before preparing publication.

```sh
python3 -I -B /opt/repro-backup-tools/prepare_closed_binlogs.py \
  --source /var/lib/repro-backup/closed-batch \
  --inventory-sha256 REVIEWED_INVENTORY_SHA256 --expected-release REVIEWED_RELEASE \
  --expected-identity REVIEWED_SERVER_UUID --expected-server-id REVIEWED_SERVER_ID \
  --expected-first-file DURABLE_NEXT_FILE
```

Use the generated manifest with the stock publisher under database scope
and identity `REVIEWED_SERVER_UUID.closed-binlogs`. Use
`advance_binlog_checkpoint.py initialize` with the independently pinned
base receipt to set the first file, and `check` to read the current anchor.
Use `advance` after successful publication with the original inventory,
the separately pinned staging manifest and the independently obtained
publication receipt pin. It rejects discontinuities and accepts an exact
retry of the latest successful advancement. See the command's `--help`
for input paths and identity flags.

The stager checks checksums and terminal rotations, including in the last file.

### Collect and publish closed logs on a schedule

`archive_binlogs.py` runs one bounded batch. Install it with
`collect_closed_binlogs.py`, the staging and checkpoint tools, and their
reviewed dependencies in the protected tools directory. Initialize the
private checkpoint from the verified base snapshot's producer receipt before
the first run. Keep that base snapshot, its receipt and its hash independently
recoverable. The backup account needs a socket-only MySQL credential with
`REPLICATION CLIENT` and OS read access to the exact binary-log directory and
files. Verify these permissions against newly rotated files. Keep database
table and filesystem write permissions out of this account.

Save this example as a root-owned `0640` collector plan. Replace example
identity, release, server paths, version and receipt hash with reviewed values.

```json
{
  "version": 1,
  "release": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
  "identity": "11111111-2222-4333-8444-555555555555",
  "database": "repro_dashboard",
  "server_id": 5384,
  "server_version": "8.4.13",
  "crypt_remote": "repro_encrypted",
  "checkpoint_dir": "/var/lib/repro-backup/closed-log-checkpoint",
  "base_receipt": "/var/lib/repro-backup/evidence/base/database-backup.json",
  "base_receipt_sha256": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
  "spool_dir": "/var/lib/repro-backup/closed-log-spool",
  "mysql": "/usr/bin/mysql",
  "defaults_file": "/var/lib/repro-backup/credentials/archive-mysql.cnf",
  "socket": "/run/repro-mysql84/mysql.sock",
  "binlog_base": "/var/lib/repro-mysql84/binlog",
  "mysql_owner_uid": 999,
  "max_files": 4,
  "max_batch_bytes": 1073741824,
  "query_timeout_seconds": 15
}
```

Create separate account-owned `0700` directories for the checkpoint, staging
spool and publisher state. The orchestration plan is also root-owned `0640`.
Review the destination and exactly pin both plan files before invocation.

```json
{
  "version": 1,
  "collector_plan": "/etc/repro-backup/closed-log-collector.json",
  "collector_plan_sha256": "cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc",
  "publish_state_dir": "/var/lib/repro-backup/closed-log-publication",
  "rclone_config": "/var/lib/repro-backup/credentials/archive-rclone.conf",
  "rclone": "/usr/bin/rclone",
  "destination": "storage:APPROVED_BUCKET/APPROVED_BINLOG_PREFIX",
  "run_timeout_seconds": 240,
  "transfer_timeout_seconds": 180
}
```

```sh
/usr/bin/python3 -I -B /opt/repro-backup-tools/archive_binlogs.py \
  --plan /etc/repro-backup/closed-log-archive.json \
  --plan-sha256 REVIEWED_ARCHIVE_PLAN_SHA256
```

The collector compares the expected socket, server and catalog, then copies
only the closed files that start at the checkpoint. The publisher verifies
remote bytes before the checkpoint can move. On failure the previous anchor
stays available for retry. A retry collects a new batch with a fresh UUID. If
the process stopped after read-back but before advancing, this can safely
republish the same closed files under a fresh UUID. Keep the protected journal
of publication receipts. Inspect abandoned scratch only after confirming the
job has stopped. The coordinator never rotates or purges MySQL files.

`observed_active_file` identifies the active file at collection time. If
`caught_up_to_observed_active_file` is false, closed files remain to archive.
An idle run means the checkpoint was at the observed active file. Measure
the time from a synthetic committed marker through approved rotation, archive,
independent restore and verification. That interval, along with tests of
failures and missed runs, is needed to establish the recovery point and alert
thresholds. Size the local binlog retention window to survive a destination
outage and backlog. Use a single scheduler owner and arrange catch-up runs for
backlog, with an independent timely-rotation policy and external monitoring.

### Check rotated MySQL logs before replay

Put the retrieved base producer receipt, the required closed log files, and
the reviewed `binlog-chain.json` in a private recovery directory. The inventory
has schema `repro.mysql.binlog-chain.v1` with `source_uuid`, the originating
numeric `server_id`, `start` and `stop` objects containing `file` and integer
`position`, and the ordered `segments` list. Each segment contains `file`,
`bytes` and `sha256` from verified archive receipts. The start is the
snapshot coordinate from the base producer. The stop is the end position of
the selected committed transaction. Keep the inventory and base receipt hashes
independently from the recovered files.

```sh
/usr/bin/python3 -I /opt/repro-backup-tools/verify_mysql_binlog_chain.py \
  --source /var/lib/repro-recovery/private/replay-inputs \
  --inventory-sha256 REVIEWED_CHAIN_INVENTORY_SHA256 \
  --producer-sha256 REVIEWED_BASE_PRODUCER_SHA256 \
  --expected-identity APPROVED_MYSQL_SOURCE_UUID \
  --expected-database APPROVED_MYSQL_DATABASE
```

The verifier checks file and event checksums, the source server ID, rotation to
the next filename, and complete event boundaries. It accepts CRC32-checked
MySQL logs closed by rotation and an end position immediately after an InnoDB
commit. Keep the verified files immutable until replay. Run mysqlbinlog once
with `--verify-binlog-checksum`, the ordered files, and the verified start and
stop positions. Feed its output to one connection on the isolated recovery
server. Count and compare restored data and confirm exclusion of commits after
the selected point. The chain check does no SQL replay. [MySQL recovery with binary logs](https://dev.mysql.com/doc/refman/8.4/en/point-in-time-recovery-binlog.html)
and [MySQL event positions](https://dev.mysql.com/doc/refman/8.4/en/point-in-time-recovery-positions.html).

### Verify bucket retention

`--immutable` is a client guard, not a cloud retention lock or conditional
create-only storage primitive. The upload key must lack deletion/retention-policy
management authority, and independently tested bucket rules must prevent
overwrite/deletion for the agreed retention period. The bucket's rules operate
on encrypted object names: confirm the actual encrypted prefix mapping, rather
than applying rules only to the plaintext names above. Keep management keys and
recoverable crypt secrets outside the production host. This gate neither sets nor
tests those policies and cannot prevent a second authorized writer racing it.

## Acceptance still required

1. Exercise the actual bucket with the installed rclone version, a private
   account/config and synthetic artifacts. Test authentication denial,
   interrupted uploads, corrupt/missing objects, a preexisting prefix and failed
   final-marker readback. Check cloud-side objects are encrypted and only the
   exact approved prefix is writable.
2. Test overwrite/delete denial and lock/lifecycle coverage using the actual
   production upload identity. Do not infer this from the copy flags.
3. Restore independently on another host from the bucket and separately held
   secrets only. Prove database consistency, exact source identity, application-key
   recovery, binlog continuity and the desired recovery point/time. A successful
   publication receipt is not that proof.
4. Connect nonzero exit/stale receipts to the approved monitoring channel. For an
   unlogged migration import, require a complete verified off-host MySQL base and
   checked recovery position before any production writes. This publisher is one
   component of that gate, not the entire approval or cutover controller.

Portable tests (no rclone/network):

```sh
python -B -m unittest discover -s scripts/mysql/offsite -p 'test_*.py' -v
```

References: [rclone crypt](https://rclone.org/crypt/),
[copy behavior](https://rclone.org/commands/rclone_copy/),
[decrypted stream through cat](https://rclone.org/commands/rclone_cat/),
[immutable, environment and exit-code semantics](https://rclone.org/docs/).
