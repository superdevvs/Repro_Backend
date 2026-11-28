"""Read the four smoke transactions privately over the checked MySQL socket for the short rollback audit."""
from __future__ import annotations

import hashlib
import json
import os
import re
import subprocess
from pathlib import Path

import binlog_scan
import mysql_probe as probe
from adapter import SECRET_VALUE, guard
from runner import UnsafeCutover

# Oracle mysqlbinlog 8.4.13 Linux x86_64, verified by the isolated native PITR rehearsal.
DECODER_SHA256 = "1087ee9d26ea42f396a1a7f302eb2cc549b2e05c32c62f8125f9d1b40bc08048"
OPENSSL_SHA256 = {"libssl.so.3": "78ae74e4cc70d4e19478065ee00c5b1864cf8528c725eb6230ce0e6ec0d9feb5",
                  "libcrypto.so.3": "a087e2cafe703f8417ded60f57949dfde4b0ec99a112a9b421a3ca44c7cea514"}
MAX_BINLOG_BYTES = 128 * 1024
MAX_DECODED_BYTES = 2 * 1024 * 1024
TABLES = ("aryeo_changes", "jobs", "shoots", "users")
# Each release layout binds all four tables together, including the real ordinal assignment,
# complete COLUMN_TYPE and nullability. A mixture of two known layouts remains unreviewed.
CATALOG_SHA256 = {
    "224b87b85c4bf310c25eb41e2cb36b825c0b94c665e729671c9600f32e4f4990",  # clean MySQL schema
    "8f5c86b31b71a3cf66b5945730b29e179f888fc96f6ae713035120831810b589",  # reviewed SQLite import
}


class Unavailable(ValueError):
    """Private attribution could not be completed. Keep the full reverse-export path."""


def require(condition):
    if not condition:
        raise Unavailable("The native smoke row scope could not be verified")


def _size(gtid_set):
    total = 0
    for intervals in gtid_set.intervals.values():
        previous = 0
        for first, last in sorted(intervals):
            require(first > previous)
            total += last - first + 1
            previous = last
    return total


def coordinates(config, window, listed):
    uuid = config["mysql"]["target_uuid"]
    # Earlier reviewed foreign activity and later business rows retain their own
    # classifications. A later DDL/unknown statement may have changed ordinal layout.
    candidates = []
    for item in listed:
        if item["class"] == "smoke":
            candidates.append(item)
        elif candidates:
            require(not item["statements"])
    require(len(candidates) == 4)
    before, after = window[:2]
    gtids = tuple(item["gtid"] for item in candidates)
    pattern = re.compile(re.escape(uuid) + r":([1-9][0-9]{0,18})")
    numbers = []
    for gtid in gtids:
        match = pattern.fullmatch(gtid)
        require(match and gtid not in before and gtid in after)
        numbers.append(int(match[1]))
    require(numbers == list(range(numbers[0], numbers[0] + 4))
            and before.issubset(after) and _size(after) - _size(before) == 4)
    name = candidates[0]["file"]
    positions = [item["position"] for item in candidates]
    require(re.fullmatch(binlog_scan.LOG_NAME, name) and all(item["file"] == name for item in candidates)
            and all(type(pos) is int and pos >= 4 for pos in positions)
            and positions == sorted(set(positions)))
    # SQL's transaction records carry the start of each GTID. Stop at the fourth commit,
    # before any later activity or current mutable file tail.
    raw = probe._show(config, f"SHOW BINLOG EVENTS IN '{name}' FROM {positions[-1]} LIMIT 32")
    events = binlog_scan.parse_events(raw, name)
    require(events and events[0].position == positions[-1] and events[0].type == "Gtid")
    previous = positions[-1]
    for event in events:
        require(event.position == previous and event.end_position > previous)
        previous = event.end_position
        if event.type == "Xid":
            require(0 < event.end_position - positions[0] <= MAX_BINLOG_BYTES)
            return gtids, name, positions[0], event.end_position
        require(event is events[0] or event.type in {"Query", "Table_map", "Delete_rows"})
    raise Unavailable("The smoke transaction has no verified end")


def measured_catalog(config, *, deadline=None):
    """Return the actual ordinal names and an approved digest of all four complete typed layouts."""
    import gate

    mysql = config["mysql"]
    database = probe._literal(mysql["database"])
    tables = ", ".join(probe._literal(name) for name in TABLES)
    sql = ("SELECT JSON_ARRAY(CURRENT_USER(), @@server_uuid, VERSION(), @@GLOBAL.skip_networking,"
           " @@GLOBAL.binlog_row_image, @@GLOBAL.binlog_format); "
           "SELECT JSON_ARRAY(c.TABLE_NAME, c.ORDINAL_POSITION, c.COLUMN_NAME, c.COLUMN_TYPE, c.IS_NULLABLE) "
           "FROM information_schema.COLUMNS c JOIN information_schema.TABLES t USING (TABLE_SCHEMA,TABLE_NAME) "
           f"WHERE c.TABLE_SCHEMA = {database} AND c.TABLE_NAME IN ({tables}) "
           "AND t.ENGINE='InnoDB' AND t.TABLE_TYPE='BASE TABLE' AND c.GENERATION_EXPRESSION='' "
           "ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION")
    result = gate._client(config, "token_check_defaults", [sql], deadline=deadline)
    require(result.returncode == 0 and len(result.stdout) < 65536)
    lines = result.stdout.splitlines()
    require(lines and json.loads(lines[0]) == ["repro_token_check@localhost", mysql["target_uuid"],
                                               binlog_scan.SERVER_VERSION, 1, "FULL", "ROW"])
    names = {name: [] for name in TABLES}
    shapes = {name: [] for name in TABLES}
    for line in lines[1:]:
        table, ordinal, column, mysql_type, nullable = json.loads(line)
        require(table in names and type(ordinal) is int and ordinal == len(names[table]) + 1
                and isinstance(column, str) and re.fullmatch(r"[a-z0-9_]+", column)
                and isinstance(mysql_type, str) and nullable in ("NO", "YES"))
        names[table].append(column)
        shapes[table].append((column, mysql_type, nullable))
    encoded = json.dumps(shapes, sort_keys=True, separators=(",", ":")).encode("ascii")
    digest = hashlib.sha256(encoded).hexdigest()
    require(digest in CATALOG_SHA256)
    return names, digest


def layout(config, *, deadline=None):
    return measured_catalog(config, deadline=deadline)[0]


def safe_schema_at_smoke(config, deadline):
    """Measure after the starting GTID read, before the first smoke write. Return only an aggregate verdict."""
    import gate
    try:
        import smoke_rows

        smoke_rows.verified_layout(layout(config, deadline=deadline))
        mysql = config["mysql"]
        db = probe._literal(mysql["database"])
        # InnoDB executes cascades internally. FULL row images show only the originating
        # write. Its PROCESS-only dictionary exposes incoming FKs even from other schemas.
        # A known release FK independently witnesses that both dictionary views have rows.
        sql = f"""
            WITH incoming AS (
                SELECT f.ID, f.FOR_NAME, f.REF_NAME, f.TYPE, f.N_COLS,
                       COUNT(c.POS) AS positions, COUNT(DISTINCT c.POS) AS distinct_positions,
                       MIN(c.POS) AS first_pos, MAX(c.POS) AS last_pos,
                       COUNT(c.REF_COL_NAME) AS ref_names, COUNT(c.FOR_COL_NAME) AS child_names,
                       MAX(LOWER(c.REF_COL_NAME) IN ('attempts','reserved_at')) AS job_edit,
                       MAX(LOWER(c.REF_COL_NAME)='company_notes') AS note_edit,
                       MAX(c.FOR_COL_NAME='client_id' AND c.REF_COL_NAME='id') AS known_columns
                FROM information_schema.INNODB_FOREIGN f
                LEFT JOIN information_schema.INNODB_FOREIGN_COLS c ON BINARY c.ID=BINARY f.ID
                WHERE f.REF_NAME IN (CONCAT({db},'/users'),CONCAT({db},'/shoots'),CONCAT({db},'/jobs'))
                GROUP BY f.ID,f.FOR_NAME,f.REF_NAME,f.TYPE,f.N_COLS
            )
            SELECT JSON_ARRAY(CURRENT_USER(),@@server_uuid,VERSION(),@@GLOBAL.skip_networking,
                   @@GLOBAL.lower_case_table_names,
                   (SELECT COUNT(*) FROM information_schema.USER_PRIVILEGES
                    WHERE GRANTEE='''repro_cutover_probe''@''localhost''' AND PRIVILEGE_TYPE='PROCESS'),
                   COUNT(CASE WHEN FOR_NAME=CONCAT({db},'/shoots') AND REF_NAME=CONCAT({db},'/users')
                                  AND N_COLS=1 AND known_columns=1 THEN 1 END),
                   COUNT(CASE WHEN TYPE IS NULL OR TYPE NOT BETWEEN 0 AND 63
                                OR (TYPE & 19) NOT IN (0,1,2,16) OR (TYPE & 44) NOT IN (0,4,8,32)
                                OR N_COLS IS NULL OR N_COLS < 1 OR positions <> N_COLS
                                OR distinct_positions <> N_COLS OR ref_names <> N_COLS OR child_names <> N_COLS
                                OR first_pos NOT IN (0,1) OR last_pos-first_pos+1 <> N_COLS
                                OR (REF_NAME=CONCAT({db},'/jobs') AND ((TYPE & 3)<>0 OR ((TYPE & 12)<>0 AND job_edit)))
                                OR (REF_NAME IN (CONCAT({db},'/users'),CONCAT({db},'/shoots'))
                                    AND (TYPE & 12)<>0 AND note_edit)
                              THEN 1 END)) FROM incoming
        """
        result = gate._client(config, "probe_defaults", [sql], deadline=deadline)
        require(result.returncode == 0 and len(result.stdout) < 1024)
        actual = json.loads(result.stdout)
        expected = [probe.PROBE_ACCOUNT, mysql["target_uuid"], binlog_scan.SERVER_VERSION, 1, 0, 1, 1, 0]
        return actual == expected and all(type(value) is int for value in actual[3:])
    except (ImportError, OSError, ValueError, TypeError, KeyError, guard.Refused, UnsafeCutover,
            subprocess.SubprocessError):
        return False


def check_digest(path, expected):
    probe._protected(path)
    digest = hashlib.sha256()
    with path.open("rb") as executable:
        for block in iter(lambda: executable.read(1024 * 1024), b""):
            digest.update(block)
    require(digest.hexdigest() == expected)


def decoder_credentials(path):
    probe._protected(path, secret=True)
    lines = [line.strip() for line in guard.read_protected(path).decode("ascii").splitlines()]
    lines = [line for line in lines if line and not line.startswith(("#", ";"))]
    require(len(lines) == 3 and lines[0] == "[client]")
    values = {}
    for line in lines[1:]:
        key, sep, value = line.partition("=")
        require(sep == "=" and key.strip() in {"user", "password"} and key.strip() not in values)
        values[key.strip()] = value.strip()
    require(values.get("user") == "repro_cutover_probe" and guard.matches(SECRET_VALUE, values.get("password")))
    return ("[client]\nuser=repro_cutover_probe\npassword=" + values["password"] + "\n").encode("ascii")


def decode(config, name, start, stop):
    import resource

    # This probe keeps private before/after images in Python and decoder process memory.
    _, hard = resource.getrlimit(resource.RLIMIT_CORE)
    resource.setrlimit(resource.RLIMIT_CORE, (0, hard))
    mysql = config["mysql"]
    path = Path(mysql["client"]).with_name("mysqlbinlog")
    check_digest(path, DECODER_SHA256)
    private = path.parent.parent / "lib/private"
    guard.check_chain(private, directory=True)
    # Exclude replacement OS libraries and glibc hardware-capability subdirectories
    # that the loader would otherwise prefer over the two reviewed portable objects.
    require({entry.name for entry in private.iterdir()} == OPENSSL_SHA256.keys())
    for lib, digest in OPENSSL_SHA256.items():
        check_digest(private / lib, digest)
    credentials = decoder_credentials(Path(mysql["probe_defaults"]))

    def limit_output():
        resource.setrlimit(resource.RLIMIT_CORE, (0, 0))
        resource.setrlimit(resource.RLIMIT_FSIZE, (MAX_DECODED_BYTES, MAX_DECODED_BYTES))

    # Row images can contain private columns. Use anonymous Linux memory and never print
    # or persist the decoder output or stderr. Enforce the limit in the child as it writes.
    with (os.fdopen(os.memfd_create("cutover-smoke-rows", os.MFD_CLOEXEC), "w+b") as output,
          os.fdopen(os.memfd_create("cutover-probe-login", os.MFD_CLOEXEC), "w+b") as login):
        os.fchmod(login.fileno(), 0o600)
        login.write(credentials)
        login.flush()
        login.seek(0)
        # Give the decoder only the account and password. A shared defaults file can contain
        # decoder-only output or plugin options that would bypass our private stdout.
        argv = [str(path), f"--defaults-file=/proc/self/fd/{login.fileno()}", "--no-login-paths",
                "--read-from-remote-server", "--protocol=SOCKET", f"--socket={mysql['socket']}",
                "--verify-binlog-checksum", "--base64-output=DECODE-ROWS", "--verbose", "--verbose",
                f"--start-position={start}", f"--stop-position={stop}", name]
        result = subprocess.run(argv, stdin=subprocess.DEVNULL, stdout=output, stderr=subprocess.DEVNULL,
                                env=probe.CLIENT_ENVIRONMENT, timeout=probe.CLIENT_SECONDS, preexec_fn=limit_output,
                                pass_fds=(login.fileno(),))
        require(result.returncode == 0 and 0 < output.tell() < MAX_DECODED_BYTES)
        output.seek(0)
        return output.read(MAX_DECODED_BYTES)


def verified(config, window, listed):
    """Return one aggregate boolean. Missing tools, unsupported layouts and row ambiguity have no exemption."""
    try:
        import smoke_rows

        require(window[2].get("schema_verified_before_write") is True)
        gtids, name, start, stop = coordinates(config, window, listed)
        names = layout(config)
        smoke_rows.verified_layout(names)
        raw = decode(config, name, start, stop)
        checked = window[2]["report"]["checks"]
        fixture = {"client_id": checked["client_id"], "shoot_id": checked["shoot_id"], "marker": checked["value"]}
        return smoke_rows.verify_rows(raw, database=config["mysql"]["database"],
                                      server_uuid=config["mysql"]["target_uuid"], expected_gtids=gtids,
                                      fixture=fixture, layout=names) is True
    except (ImportError, OSError, ValueError, TypeError, KeyError, guard.Refused, UnsafeCutover,
            subprocess.SubprocessError):
        return False
