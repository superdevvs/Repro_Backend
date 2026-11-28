# Review-only MySQL recovery heartbeat

A rotating binlog without a recent committed marker can show that transport is
working while leaving recovery point age uncertain. The heartbeat records one
UTC minute slot in the selected database so its table and rows are included in
that database's stock MySQL snapshot and binlog replay.

## Disposable fixture setup

Review the selected source and independently recovered database before making
fixture-only schema or account changes. Replace the database name and generate
separate credentials through protected input. Create the writer on the source
and create the reader on the isolated recovered database.

```sql
CREATE TABLE selected_fixture_db.repro_recovery_heartbeat (
  minute_utc DATETIME(0) NOT NULL PRIMARY KEY,
  recorded_utc DATETIME(0) NOT NULL
) ENGINE=InnoDB;

CREATE USER 'repro_recovery_heartbeat'@'localhost' IDENTIFIED BY '<private writer password>';
GRANT SELECT, INSERT ON selected_fixture_db.repro_recovery_heartbeat TO 'repro_recovery_heartbeat'@'localhost';

CREATE USER 'repro_heartbeat_reader'@'localhost' IDENTIFIED BY '<private reader password>';
GRANT SELECT ON selected_fixture_db.repro_recovery_heartbeat TO 'repro_heartbeat_reader'@'localhost';
```

Keep the table definition fixed, without triggers, and confirm there are no
database or table filters that exclude it from the recovery chain. Review the
grants separately from deploying the tool. No application migration or timer
is included here.

## Local invocation

Prepare separate root-protected plans for the source and recovered target.
Each contains `version` (integer 1), `collector_plan`,
`collector_plan_sha256`, `defaults_file`, `runtime_dir` and
`timeout_seconds` (3–60). Use the corresponding independently reviewed
collector-format identity for each server. The defaults files have mode 0600
and contain only `[client]`, `user` and `password`. The private runtime
directories have mode 0700 and are separate from their collector spools.

```sh
python3 -I -B heartbeat.py record --plan /approved/heartbeat-source.json --plan-sha256 SOURCE_PLAN_SHA256
python3 -I -B heartbeat.py verify --plan /approved/heartbeat-recovered.json --plan-sha256 TARGET_PLAN_SHA256 --target-utc 2026-11-24T08:04:30Z
```

The source uses server UTC on the same verified connection. An existing slot
is reported separately from a new committed insert. A lost response, duplicate
race, or boundary crossing produces an unverified result without an internal
retry. Inspect the exact slot on the reviewed server before retrying.

The recovery check uses the latest actual recorded timestamp at or before the
chosen target and accepts a gap of at most 60 seconds. Run after restoring and
replaying the selected transaction range. This check is one recovery observation,
independent of transport, retention, or the performance of the recovery run.
