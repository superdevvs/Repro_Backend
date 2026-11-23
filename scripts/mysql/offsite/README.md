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
