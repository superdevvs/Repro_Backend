# Review-only binary log rotation

Extract the active file and verify the connection, server identity, expected
routine metadata, and narrow invoker grants before sending the fixed procedure
call. MySQL requires global `RELOAD` for both binary log rotation and a reset
that can erase the log chain. Bind that privilege to the locked definer.
[FLUSH reference](https://dev.mysql.com/doc/refman/8.4/en/flush.html)
[Reset reference](https://dev.mysql.com/doc/refman/8.4/en/reset-binary-logs-and-gtids.html)
[MySQL account locking](https://dev.mysql.com/doc/refman/8.4/en/account-locking.html)

## Provisioning example for a disposable source

The example requires review of the selected disposable server, substitutions
for all placeholders, and separately authorized administrator execution. Verify
that the account names and schema are fresh. Keep the rotation account separate
from the app and collector users. MySQL allows a locked account to own a
definer procedure. Both the definer and the invoker need routine execution
permission. An administrator should review the exact `SHOW CREATE PROCEDURE`,
account lock, and all effective grants before recording the routine timestamps
in the protected plan. [Definer access control](https://dev.mysql.com/doc/refman/8.4/en/stored-objects-security.html)
[Procedure privileges](https://dev.mysql.com/doc/refman/8.4/en/create-procedure.html)

```sql
CREATE SCHEMA repro_archive_control;
CREATE USER 'repro_binlog_definer'@'localhost' ACCOUNT LOCK;
GRANT RELOAD ON *.* TO 'repro_binlog_definer'@'localhost';
CREATE USER 'repro_binlog_rotator'@'localhost' IDENTIFIED BY '<private fixture password>';
GRANT REPLICATION CLIENT ON *.* TO 'repro_binlog_rotator'@'localhost';

DELIMITER //
CREATE DEFINER = 'repro_binlog_definer'@'localhost'
PROCEDURE repro_archive_control.rotate_binary_log()
SQL SECURITY DEFINER
BEGIN
  IF @@server_uuid <> '<reviewed MySQL source UUID>'
     OR @@server_id <> <reviewed numeric server ID>
     OR VERSION() <> '<reviewed MySQL 8.4 version>'
     OR @@global.log_bin_basename <> '<reviewed absolute binary log basename>'
     OR @@global.skip_networking <> 1
     OR @@global.log_bin <> 1
     OR @@global.binlog_expire_logs_auto_purge <> 0
     OR @@global.mandatory_roles <> '' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Binary log rotation safety check failed';
  END IF;
  FLUSH BINARY LOGS;
END//
DELIMITER ;

GRANT EXECUTE ON PROCEDURE repro_archive_control.rotate_binary_log
  TO 'repro_binlog_definer'@'localhost', 'repro_binlog_rotator'@'localhost';
SELECT UNIX_TIMESTAMP(CREATED), UNIX_TIMESTAMP(LAST_ALTERED)
FROM information_schema.ROUTINES
WHERE ROUTINE_SCHEMA = 'repro_archive_control' AND ROUTINE_NAME = 'rotate_binary_log';
```

The routine and the CLI require automatic purge to be disabled by the reviewed
source configuration. Log flush can trigger automatic expiry when purge is
enabled. The CLI makes no changes to the server configuration.
[Binary logging settings](https://dev.mysql.com/doc/refman/8.4/en/replication-options-binary-log.html)

## Rotation plan

Record the independently reviewed SHA-256 of a protected plan containing
`version` (integer 1), `collector_plan`, `collector_plan_sha256`,
`defaults_file`, `runtime_dir`, `timeout_seconds` (3–60), and the reviewed
`routine_created_epoch` and `routine_altered_epoch` values. The dedicated
defaults file contains only the `[client]` section with `user` and `password`,
has mode 0600, and belongs to the rotation account's Linux service identity.
Use a separate private runtime directory with mode 0700.

```sh
python3 -I -B rotate_closed_binlogs.py --plan /approved/rotation.json --plan-sha256 REVIEWED_SHA256
```

On success the previously active filename and its exact successor are reported.
Have the collector independently check the closed file and publish it. When a
request was sent and the outcome is unverified, inspect the current log
read-only before retrying. Privileged changes to the routine or MySQL
configuration require a new review. Routine timestamps detect ordinary changes.
The administrator's definition and privilege review remains necessary.
