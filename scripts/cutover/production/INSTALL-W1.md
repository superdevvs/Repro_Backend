# Drill installation and copy-load runbook

Use this manifest with the exact reviewed drill release. The cutover controller has additional imports and services. An administrator staging a cutover build must use both manifests from that same cutover commit. [Cutover package and schedule](INSTALL-cutover.md)

## W1 file manifest

Source paths are relative to this directory. Root owns installed code, configs and their parent directories. Render the example profiles for the identified host, compare existing service configuration and preserve unrelated settings. Store secrets in separate root-only files. D14 adds the timer module, run wrapper and operator sudoers entry. Confirm those components have landed together and recheck their exact commands before activating any fences.

| Source | Destination | Mode or action |
| --- | --- | --- |
| `../fence/guard.py` | `/opt/repro-cutover-fence/guard.py` | 0644 |
| `../runner.py` | `/opt/repro-cutover-fence/runner.py` | 0644 |
| `adapter.py` | `/opt/repro-cutover-fence/production/adapter.py` | 0644 |
| `admit.py` | `/opt/repro-cutover-fence/production/admit.py` | 0644 |
| `backend_source_fingerprint.py` | `/opt/repro-cutover-fence/production/backend_source_fingerprint.py` | 0644 |
| `binlog_scan.py` | `/opt/repro-cutover-fence/production/binlog_scan.py` | 0644 |
| `cli.py` | `/opt/repro-cutover-fence/production/cli.py` | 0644 |
| `config_build.py` | `/opt/repro-cutover-fence/production/config_build.py` | 0644 |
| `crontab.py` | `/opt/repro-cutover-fence/production/crontab.py` | 0644 |
| `deploy_marker.py` | `/opt/repro-cutover-fence/production/deploy_marker.py` | 0644 |
| `envfile.py` | `/opt/repro-cutover-fence/production/envfile.py` | 0644 |
| `evidence.py` | `/opt/repro-cutover-fence/production/evidence.py` | 0644 |
| `fastcgi.py` | `/opt/repro-cutover-fence/production/fastcgi.py` | 0644 |
| `fpm_drain.py` | `/opt/repro-cutover-fence/production/fpm_drain.py` | 0644 |
| `gate.py` | `/opt/repro-cutover-fence/production/gate.py` | 0644 |
| `identity_probe.py` | `/opt/repro-cutover-fence/production/identity_probe.py` | 0644 |
| `live_config.py` | `/opt/repro-cutover-fence/production/live_config.py` | 0644 |
| `mysql_probe.py` | `/opt/repro-cutover-fence/production/mysql_probe.py` | 0644 |
| `sqlite_seal.py` | `/opt/repro-cutover-fence/production/sqlite_seal.py` | 0644 |
| `timers.py` | `/opt/repro-cutover-fence/production/timers.py` | 0644 |
| `var_export.py` | `/opt/repro-cutover-fence/production/var_export.py` | 0644 |
| `capture/capture.php` | `/opt/repro-telnyx-capture/capture.php` | 0644 |
| `capture/php-fpm.conf` | `/etc/repro-telnyx-capture/php-fpm.conf` | 0644 |
| `capture/capture.example.json` | `/etc/repro-telnyx-capture/capture.json` | render |
| `identity.example.json` | `/etc/repro-cutover-fence/identity.json` | render |
| `wrappers.mysql.example.json` | `/etc/repro-cutover-fence/wrappers.mysql.json` | render |
| `wrappers.sqlite.example.json` | `/etc/repro-cutover-fence/wrappers.sqlite.json` | render |
| `cron-rules.example.json` | `/etc/repro-cutover-fence/cron-rules.json` | review |
| `nginx/repro-cutover-gate.conf` | `/etc/nginx/conf.d/repro-cutover-gate.conf` | 0644 |
| `nginx/repro-cutover-hold.conf` | `/etc/nginx/snippets/repro-cutover-hold.conf` | 0644 |
| `php-fpm/repro-status.conf` | `/etc/php/8.3/fpm/pool.d/zz-repro-status.conf` | 0644 |
| `supervisor/laravel-worker.conf.example` | `/etc/supervisor/conf.d/laravel-worker.conf` | review |
| `systemd/repro-cutover-watchdog.service` | `/etc/systemd/system/repro-cutover-watchdog.service` | 0644 |
| `systemd/repro-cutover-watchdog.timer` | `/etc/systemd/system/repro-cutover-watchdog.timer` | 0644 |
| `systemd/repro-media-archives.service.d/cutover-fence.conf` | `/etc/systemd/system/repro-media-archives.service.d/cutover-fence.conf` | 0644 |
| `systemd/repro-telnyx-capture.service` | `/etc/systemd/system/repro-telnyx-capture.service` | 0644 |
| `tmpfiles.d/repro-cutover-fence.conf` | `/etc/tmpfiles.d/repro-cutover-fence.conf` | 0644 |
| `sudoers/repro-cutover-admit` | `/etc/sudoers.d/repro-cutover-admit` | 0440 |
| `sudoers/repro-cutover-operator` | `/etc/sudoers.d/repro-cutover-operator` | 0440 |
| `sudoers/repro-queue-flag` | `/etc/sudoers.d/repro-queue-flag` | 0440 |
| `sbin/repro-queue-flag` | `/usr/local/sbin/repro-queue-flag` | 0755 |
| `sbin/repro-cutover-run` | `/usr/local/sbin/repro-cutover-run` | 0755 |

Render the two engine-specific examples with the reviewed host paths and accounts. Confirm that the profile sets include `cutover-smoke` and `telnyx-replay` and that only `engine` differs between corresponding profiles. Initially publish the exact rendered SQLite bytes as `wrappers.json`. The older `wrappers.example.json` is a repository reference for the pre-rehearsal writer inventory.

Generate the drill-mode `host.json`, `policy.json` and active SQLite `wrappers.json` under `/etc/repro-cutover-fence/`. Validate the database UUID, source fingerprint and both cached configuration hashes with the installed probes. Keep the probe defaults file root-owned, mode 0600. Session A1 supplies the eight restricted accounts from `mysql/accounts.sql`. W2a separately stages the launcher and shims documented in [the sandbox guide](sandbox/README.md).

### Before installing

1. Confirm the approved window, release identity, quiet jobs, healthy recovery copy and available disk. Verify that the application fingerprint is stable with real worker activity, the app tree contains no Python bytecode and the identity probe accepts the live file types, ownership, unique environment keys, machine and storage identity. Measure the configurations and identity twice; record timings and hashes only.
2. Compare every scheduler, worker, manual writer, producer and allowlisted cron command to the recorded inventory. The cron rewrite must retain the storage guard. Confirm the absolute PHP binary, the FPM socket and the process-group behavior of the installed `runuser`.
3. Confirm database privileges, server UUID, free disk including binary logs, connection budget, recovery credentials and backup throughput. At W2a inspect the mount tree before launch, namespace canaries, lack of egress, and host memory, CPU, process and disk limits.
4. Verify the privileged wrapper argument list and run `visudo -cf` on every staged sudoers file. Validate systemd units with `systemd-analyze verify`, FPM configuration with its test mode and final nginx configuration with `nginx -t`. Record baseline configs and the undo procedure before applying changes.

### Activation proofs P6–P10

Create the required runtime paths and open marker before activating ingress rules. P6 checks the server-level include on every path to PHP, removes the superseded Telnyx exception and exercises the held route, missing/unreadable capture script, direct internal request and duplicate replay. Confirm the capture service survives a freeze. P7 exercises pause and resume only for the listed test queue as the web user through the deploy-user helper. P8 checks FPM idle drain, the status socket and a fresh FastCGI request reporting SQLite with the pinned hash. P9 loads every profile from both wrapper files in dry-run mode. P10 times drill initialization, rehold, refresh and reopen, checks the scheduler release last, and observes watchdog ticks. Use the actual host timer and privileged wrapper for host evidence.

## A3 copy loading while the primary remains SQLite

A3 has its own administrator window. A planned drill may load a verified SQLite copy into an **empty, isolated MySQL target with the application write gate closed**. The cutover itself takes a separate final frozen snapshot. Use the authorized target database and independently measured server UUID. A nonempty target requires investigation; recreating the synthetic fixture is part of the local test only.

1. Use the approved online backup and verify its integrity and original digest. Record its release, migration ledger, reviewed schema fingerprint and historical exception manifest. Keep the protected snapshot and inputs private. Set umask 077 in the maintenance session.
2. Run the release's [empty-target import](../../../docs/mysql-production-cutover.md#import-into-the-empty-target) using the pinned PyMySQL wheel, the private socket-only `repro_maint` credential file, the reviewed schema fingerprint and exception checksum. Keep binary logging durable. Record the tool's actual `result.json`, including source SHA, target UUID, counts, trigger count and zero warnings. Treat a partial load as a failed target.
3. Run `verify-fields.php` with separate read-only credentials. Confirm matching per-table counts, every field and the final all-fields-verified record. Run `complete-schema.php` with the migrator profile and verify the migration ledger, then `artisan migrate:status --pending` with the same isolated connection. Confirm the inert smoke fixtures are present. Create any approved missing fixtures before capturing the final coordinate.
4. The probe account records `SHOW BINARY LOG STATUS` with file, position and GTID after the final maintenance writes. Restore any temporary trigger-creation setting. Before arming, the administrator locks the maintenance account or revokes its elevated session privilege as approved. Use one reviewed account statement per change. Verify the gate is closed, the app has no business writes, and retain each receipt required by `check-evidence` in its private night directory.
5. Under the approved drill scope, exercise hold, freeze, prepare, refusal of promotion, unattended undo and verified SQLite reopening. Reconcile captured callbacks before releasing outbound workers and release the scheduler last. Follow the [leftover smoke job procedure](../probe/README.md) if needed. A cleanup after MySQL write-open is itself a business write.

The isolated A3 fixture test compares the real importer's result to the earlier harness-local loader on the same synthetic snapshot. Its independent verifier checks each table's row and field counts. Supply the wheel and run the commands in [the local drill guide](e2e/README.md).
