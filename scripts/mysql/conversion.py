"""SQLite conversion primitives verified on the isolated full-size rehearsal.

Called only through migrate.py. No credentials, fixed lab paths or reset commands.
Canonical hashes preserve stored temporal text and compare approved numeric values exactly.
"""
from __future__ import annotations
import collections, decimal, hashlib, json, pathlib, re, time
try:
    import pymysql
except ModuleNotFoundError as error:
    if error.name != "pymysql":
        raise
    pymysql = None

COLLATION = "utf8mb4_0900_bin"
TYPE_POLICY_PATH = pathlib.Path(__file__).with_name("type-policy.json")
TYPE_POLICY = json.loads(TYPE_POLICY_PATH.read_text(encoding="utf-8"))
TEMPORAL_TYPES = {"DATE", "DATETIME", "TIMESTAMP", "TIME"}
REDUNDANT_SHOOT_INDEX_COLUMNS = ("client_id", "editor_id", "photographer_id", "scheduled_date", "status")
INSERT_BATCH_ROWS = 200
INSERT_TRANSACTION_ROWS = 2_000
INSERT_TRANSACTION_BYTES = 8 * 1024**2
REPORTS = None


class MySqlImportWarnings(RuntimeError):
    def __init__(self, count, conditions):
        super().__init__(f"MySQL returned {count} warnings or notes during conversion. Discard the incomplete target before retrying.")
        self.count = count
        self.conditions = conditions


def require_warning_free(count, connection):
    if not count:
        return
    try:
        rows = connection.show_warnings() or ()
    except pymysql.MySQLError:
        rows = ()
    # Condition messages may contain stored values. Record only codes and severity.
    conditions = sorted({(level, code) for level, code, _ in rows})
    raise MySqlImportWarnings(count, conditions)


if pymysql is not None:
    class WarningCheckedConnection(pymysql.connections.Connection):
        def query(self, sql, unbuffered=False):
            affected = super().query(sql, unbuffered=unbuffered)
            if not unbuffered:
                # executemany can issue multiple queries before exposing its final result.
                require_warning_free(self._result.warning_count, self)
            return affected


def emit(event, **details):
    print(json.dumps({"event": event, **details}), flush=True)

def save(name, value):
    path = REPORTS / name
    with path.open("w", encoding="utf-8") as stream:
        json.dump(value, stream, indent=2, default=str)
    path.chmod(0o600)

def q(name):
    if not re.fullmatch(r"[A-Za-z_][A-Za-z0-9_]*", name):
        raise ValueError("Unsupported identifier")
    return "`" + name + "`"


def bounded_name(prefix, text):
    return (prefix + text)[:49] + "_" + hashlib.sha256(text.encode()).hexdigest()[:12]


def split_sql(text):
    """Split comma-delimited SQL while preserving literals and nested expressions."""
    result, start, depth, quote = [], 0, 0, None
    i = 0
    while i < len(text):
        char = text[i]
        if quote:
            if char == quote:
                if i + 1 < len(text) and text[i + 1] == quote:
                    i += 2
                    continue
                quote = None
        elif char in "'\"`":
            quote = char
        elif char == "(":
            depth += 1
        elif char == ")":
            depth -= 1
        elif char == "," and depth == 0:
            result.append(text[start:i].strip())
            start = i + 1
        i += 1
    result.append(text[start:].strip())
    return result


def checks(sql):
    result = []
    for match in re.finditer(r"\bCHECK\s*\(", sql, re.I):
        start, depth, quote, i = match.end(), 1, None, match.end()
        while i < len(sql) and depth:
            char = sql[i]
            if quote:
                if char == quote:
                    if i + 1 < len(sql) and sql[i + 1] == quote:
                        i += 2
                        continue
                    quote = None
            elif char in "'\"`":
                quote = char
            elif char == "(":
                depth += 1
            elif char == ")":
                depth -= 1
            i += 1
        if depth:
            raise ValueError("Unbalanced source CHECK")
        expression = sql[start:i - 1]
        expression = re.sub(r'"([A-Za-z_][A-Za-z0-9_]*)"', r'`\1`', expression)
        expression = re.sub(r"\blength\s*\(", "CHAR_LENGTH(", expression, flags=re.I)
        result.append(expression)
    return result


def mark_redundant_shoot_indexes(indexes):
    """Keep the original Laravel index for each reviewed duplicate pair."""
    by_name = {index["name"]: index for index in indexes}
    for column in REDUNDANT_SHOOT_INDEX_COLUMNS:
        retained_name = f"shoots_{column}_index"
        retained = by_name.get(retained_name)
        duplicate = by_name.get(f"idx_shoots_{column}")
        if retained is None or duplicate is None:
            continue
        if any(index["unique"] or index["partial"] or index["origin"] != "c" for index in (retained, duplicate)):
            continue
        keys = retained["columns"]
        if keys != duplicate["columns"] or len(keys) != 1:
            continue
        key = keys[0]
        if key["name"] == column and key["cid"] >= 0 and not key["desc"] and key["coll"] == "BINARY":
            duplicate["target_redundant_with"] = retained_name


def table_schema(src, name, sql):
    columns = [dict(row) for row in src.execute("PRAGMA table_info(" + q(name) + ")")]
    indexes = []
    indexed = set()
    for row in src.execute("PRAGMA index_list(" + q(name) + ")").fetchall():
        index = dict(row)
        info = [dict(item) for item in src.execute("PRAGMA index_xinfo(" + q(index["name"]) + ")") if item["key"]]
        indexed.update(item["name"] for item in info if item["name"])
        raw = src.execute("SELECT sql FROM sqlite_master WHERE type='index' AND name=?", [index["name"]]).fetchone()
        index.update(columns=info, sql=raw[0] if raw else None)
        indexes.append(index)
    if name == "shoots":
        mark_redundant_shoot_indexes(indexes)
    foreign_keys = [dict(row) for row in src.execute("PRAGMA foreign_key_list(" + q(name) + ")")]
    indexed.update(row["from"] for row in foreign_keys)
    indexed.update(column["name"] for column in columns if column["pk"])
    text_columns = [column["name"] for column in columns if re.search("CHAR|TEXT|CLOB", column["type"], re.I)]
    maxima = {}
    if text_columns:
        statement = "SELECT " + ",".join("MAX(LENGTH(" + q(col) + "))" for col in text_columns) + " FROM " + q(name)
        maxima = dict(zip(text_columns, src.execute(statement).fetchone()))
    primary = [c["name"] for c in sorted(columns, key=lambda x: x["pk"]) if c["pk"]]
    definitions = []
    for column in columns:
        declared = column["type"].upper()
        maximum = maxima.get(column["name"]) or 0
        is_integer_pk = len(primary) == 1 and column["pk"] and declared == "INTEGER"
        if "INT" in declared:
            target = "BIGINT"
            kind = "integer"
        elif any(t in declared for t in ["CHAR", "TEXT", "CLOB"]):
            if column["name"] in indexed:
                declared_width = re.fullmatch(r"(?:VAR)?CHAR\s*\((\d+)\)", declared)
                width = int(declared_width[1]) if declared_width else TYPE_POLICY["indexed_text_default_characters"]
                if not 1 <= width <= 768:
                    raise ValueError("Indexed text needs a reviewed width: " + name + "." + column["name"])
                target = f"VARCHAR({width})"
            else:
                target = "LONGTEXT"
            kind = "text"
        elif "BLOB" in declared or not declared:
            target, kind = "LONGBLOB", "blob"
        elif declared in TEMPORAL_TYPES:
            # SQLite date/time affinity does not normalize stored text. Keep
            # date-only values, offsets and fractional precision byte for byte.
            target, kind = f'VARCHAR({TYPE_POLICY["temporal_text_characters"]})', "temporal_text"
        elif any(t in declared for t in ["REAL", "FLOAT", "DOUBLE"]):
            target, kind = "DOUBLE", "float"
        elif any(t in declared for t in ["NUMERIC", "DECIMAL", "BOOLEAN"]):
            target, kind = "DECIMAL(65,30)", "decimal"
        else:
            raise ValueError("Unknown SQLite type: " + declared)
        override = TYPE_POLICY["overrides"].get(f'{name}.{column["name"]}')
        if override:
            target, kind = override["type"], override["kind"]
        varchar = re.fullmatch(r"VARCHAR\((\d+)\)", target)
        if varchar and maximum > int(varchar[1]):
            raise ValueError("Stored text exceeds the reviewed width: " + name + "." + column["name"])
        column.update(target_type=target, kind=kind, maximum_characters=maximum, auto_increment=bool(is_integer_pk))
        part = q(column["name"]) + " " + target
        part += " NOT NULL" if column["notnull"] or column["pk"] else " NULL"
        if is_integer_pk:
            part += " AUTO_INCREMENT"
        default = column["dflt_value"]
        if default is not None and not is_integer_pk:
            default = default.strip()
            while default.startswith("(") and default.endswith(")"):
                default = default[1:-1].strip()
            if default.upper() == "CURRENT_TIMESTAMP" and kind == "temporal_text":
                part += " DEFAULT (DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%d %H:%i:%s'))"
            elif default.upper() == "CURRENT_TIMESTAMP":
                raise ValueError("Timestamp default needs an explicit mapping: " + name + "." + column["name"])
            elif target in ["LONGTEXT", "LONGBLOB"]:
                part += " DEFAULT (" + default + ")"
            else:
                part += " DEFAULT " + default
        definitions.append(part)
    if primary:
        definitions.append("PRIMARY KEY (" + ",".join(map(q, primary)) + ")")
    create = "CREATE TABLE " + q(name) + " (" + ",".join(definitions) + ") ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARACTER SET utf8mb4 COLLATE " + COLLATION
    return {"name": name, "source_sql": sql, "columns": columns, "indexes": indexes,
            "foreign_keys": foreign_keys, "checks": checks(sql), "primary": primary, "create": create}


def schema_fingerprint(schema):
    # Observed lengths are evidence, never part of the target schema contract.
    structure = [
        {**table, "columns": [
            {key: value for key, value in column.items() if key != "maximum_characters"}
            for column in table["columns"]
        ]}
        for table in schema
    ]
    return hashlib.sha256(json.dumps(structure, sort_keys=True, separators=(",", ":")).encode()).hexdigest()


def validate_source_values(src, schema):
    """Census fields subject to coercion before touching the destination."""
    for table in schema:
        checks = []
        for column in table["columns"]:
            field = q(column["name"])
            kind = column["kind"]
            valid = {
                "integer": f"typeof({field})='integer'",
                "decimal": f"typeof({field}) IN ('integer','real')",
                "float": f"(typeof({field})='real' OR (typeof({field})='integer' AND {field} BETWEEN -9007199254740992 AND 9007199254740992))",
                "text": f"typeof({field})='text'",
                "blob": f"typeof({field})='blob'",
                "temporal_text": f"(typeof({field})='text' AND length({field}) BETWEEN 1 AND {TYPE_POLICY['temporal_text_characters']})",
                "legacy_text": f"typeof({field}) IN ('integer','real','text')",
            }[kind]
            checks.append(f"COALESCE(SUM({field} IS NOT NULL AND NOT ({valid})),0)")
        counts = src.execute("SELECT " + ",".join(checks) + " FROM " + q(table["name"])).fetchone()
        for column, invalid in zip(table["columns"], counts):
            if invalid:
                raise ValueError(
                    f'Unsupported stored values in {table["name"]}.{column["name"]}: {invalid} rows'
                )


def index_statement(table, index):
    if index["origin"] == "pk":
        return None
    name = index["name"]
    if name.startswith("sqlite_"):
        name = bounded_name("unique_", table["name"] + "_" + "_".join(x["name"] for x in index["columns"]))
    elif len(name) > 64:
        name = bounded_name("idx_", name)
    if index["partial"]:
        if index["name"] != "voice_follow_up_manual_call":
            raise ValueError("Unknown partial index requires explicit implementation")
        keys = "((CASE WHEN `automation_run_id` IS NULL THEN `voice_call_id` ELSE NULL END))"
    elif any(x["cid"] == -2 for x in index["columns"]):
        match = re.search(r"\bON\s+[\w\"`]+\s*\((.*)\)\s*$", index["sql"], re.I | re.S)
        if not match:
            raise ValueError("Unsupported expression index")
        keys = "(" + ",".join("(" + part + ")" if "(" in part else q(part.strip('"` ')) for part in split_sql(match[1])) + ")"
    else:
        keys = "(" + ",".join(q(x["name"]) + (" DESC" if x["desc"] else "") for x in index["columns"]) + ")"
    return "CREATE " + ("UNIQUE " if index["unique"] else "") + "INDEX " + q(name) + " ON " + q(table["name"]) + " " + keys


def add_constraints(mysql, schema, report_name="constraints.json"):
    result = {"indexes": 0, "foreign_keys": 0, "checks": 0, "failures": [], "redundant_indexes": []}
    with mysql.cursor() as cursor:
        for table in schema:
            for index in table["indexes"]:
                retained_name = index.get("target_redundant_with")
                if retained_name:
                    result["redundant_indexes"].append({"table": table["name"], "omitted": index["name"],
                                                        "retained": retained_name})
                    continue
                statement = index_statement(table, index)
                if not statement:
                    continue
                try:
                    cursor.execute(statement)
                    result["indexes"] += 1
                except pymysql.MySQLError as error:
                    result["failures"].append({"table": table["name"], "object": index["name"], "kind": "index", "error_code": error.args[0]})
            groups = collections.defaultdict(list)
            for fk in table["foreign_keys"]:
                groups[fk["id"]].append(fk)
            for number, keys in groups.items():
                keys.sort(key=lambda x: x["seq"])
                first = keys[0]
                target = first["table"]
                referenced = [key["to"] for key in keys]
                if any(key is None for key in referenced):
                    raise ValueError("Implicit foreign-key column requires explicit implementation")
                statement = "ALTER TABLE " + q(table["name"]) + " ADD CONSTRAINT " + q(bounded_name("fk_", table["name"] + "_" + str(number)))
                statement += " FOREIGN KEY (" + ",".join(q(key["from"]) for key in keys) + ") REFERENCES " + q(target)
                statement += " (" + ",".join(map(q, referenced)) + ") ON DELETE " + first["on_delete"] + " ON UPDATE " + first["on_update"]
                try:
                    cursor.execute(statement)
                    result["foreign_keys"] += 1
                except pymysql.MySQLError as error:
                    result["failures"].append({"table": table["name"], "object": number, "kind": "foreign_key", "error_code": error.args[0]})
            for number, expression in enumerate(table["checks"]):
                try:
                    cursor.execute("ALTER TABLE " + q(table["name"]) + " ADD CONSTRAINT " + q(bounded_name("ck_", table["name"] + "_" + str(number))) + " CHECK (" + expression + ")")
                    result["checks"] += 1
                except pymysql.MySQLError as error:
                    result["failures"].append({"table": table["name"], "object": number, "kind": "check", "error_code": error.args[0]})
            emit("constraints", table=table["name"])
    save(report_name, result)
    return result


def canonical(value, column):
    if value is None:
        return ["null"]
    kind = column["kind"]
    if kind in ["decimal", "integer"]:
        number = decimal.Decimal(str(value))
        return ["number", format(number.normalize(context=decimal.Context(prec=80)), "f")]
    if kind == "float":
        return ["float", repr(float(value))]
    if kind == "temporal_text" and not isinstance(value, str):
        raise ValueError("Temporal value was normalized by a driver or database type.")
    if isinstance(value, bytes):
        return ["bytes", value.hex()]
    return ["text", str(value)]


def row_digest(hasher, row, columns):
    value = [canonical(item, column) for item, column in zip(row, columns)]
    hasher.update(json.dumps(value, ensure_ascii=False, separators=(",", ":")).encode("utf-8") + b"\n")


def insert_batch_sql_bytes(statement, batch):
    # Allow a full statement prefix per row because PyMySQL can split a batch.
    size = (len(statement) + 16) * len(batch)
    for row in batch:
        for value in row:
            kind = type(value)
            if kind is str:
                # A utf8mb4 character takes up to four bytes, or two when escaped.
                size += 4 * len(value) + 2
            elif kind is bytes:
                size += 2 * len(value) + len("_binary''")
            elif kind is int or kind is float or value is None:
                size += 32
            else:
                return INSERT_TRANSACTION_BYTES + 1
    return size


def insert_rows(mysql, source_cursor, statement):
    count = pending_rows = pending_bytes = 0
    with mysql.cursor() as cursor:
        try:
            while rows := source_cursor.fetchmany(INSERT_BATCH_ROWS):
                batch = [tuple(row) for row in rows]
                batch_bytes = insert_batch_sql_bytes(statement, batch)
                if pending_rows and (pending_rows + len(batch) > INSERT_TRANSACTION_ROWS
                                     or pending_bytes + batch_bytes > INSERT_TRANSACTION_BYTES):
                    # A failed COMMIT can have succeeded on the server. Never replay it.
                    pending_rows = pending_bytes = 0
                    cursor.execute("COMMIT")

                # Keep the original autocommit path for large batches. This avoids
                # grouping PyMySQL's separate statements into a larger transaction.
                if batch_bytes <= INSERT_TRANSACTION_BYTES:
                    if not pending_rows:
                        cursor.execute("START TRANSACTION")
                    pending_rows += len(batch)
                    pending_bytes += batch_bytes
                cursor.executemany(statement, batch)
                count += len(batch)

            if pending_rows:
                pending_rows = 0
                cursor.execute("COMMIT")
        except BaseException:
            if pending_rows:
                try:
                    cursor.execute("ROLLBACK")
                except Exception:
                    # The caller closes the connection and the target stays incomplete.
                    pass
            raise
    return count


def apply(mysql, src, schema):
    with mysql.cursor() as cursor:
        cursor.execute("SHOW TABLES")
        existing = cursor.fetchall()
        if existing:
            raise RuntimeError("Destination must be empty; existing objects are never dropped.")
        for table in schema:
            cursor.execute(table["create"])
    progress = []
    sequences = {}
    if src.execute("SELECT 1 FROM sqlite_schema WHERE name='sqlite_sequence'").fetchone():
        sequences = {row[0]: row[1] for row in src.execute("SELECT name,seq FROM sqlite_sequence")}
    for table in schema:
        started = time.monotonic()
        columns = table["columns"]
        names = ",".join(q(column["name"]) for column in columns)
        source_cursor = src.execute("SELECT " + names + " FROM " + q(table["name"]))
        statement = "INSERT INTO " + q(table["name"]) + " (" + names + ") VALUES (" + ",".join(["%s"] * len(columns)) + ")"
        count = insert_rows(mysql, source_cursor, statement)
        if any(column["auto_increment"] for column in columns):
            next_id = int(sequences.get(table["name"], 0)) + 1
            with mysql.cursor() as cursor:
                cursor.execute("ALTER TABLE " + q(table["name"]) + " AUTO_INCREMENT = " + str(max(1, next_id)))
        details = {"table": table["name"], "rows": count, "seconds": round(time.monotonic() - started, 2)}
        progress.append(details)
        save("import-progress.json", progress)
        emit("imported", **details)
    return add_constraints(mysql, schema)


def verify(mysql, src, schema, report_name="data-verification.json"):
    result = []
    for table in schema:
        names = ",".join(q(column["name"]) for column in table["columns"])
        order = table["primary"] or [column["name"] for column in table["columns"]]
        statement = "SELECT " + names + " FROM " + q(table["name"]) + " ORDER BY " + ",".join(map(q, order))
        sqlite_hash, mysql_hash = hashlib.sha256(), hashlib.sha256()
        sqlite_count, mysql_count = 0, 0
        for row in src.execute(statement):
            row_digest(sqlite_hash, tuple(row), table["columns"])
            sqlite_count += 1
        with mysql.cursor(pymysql.cursors.SSCursor) as cursor:
            cursor.execute(statement)
            for row in cursor:
                row_digest(mysql_hash, row, table["columns"])
                mysql_count += 1
            require_warning_free(cursor.warning_count, mysql)
        item = {"table": table["name"], "source_rows": sqlite_count, "target_rows": mysql_count,
                "source_sha256": sqlite_hash.hexdigest(), "target_sha256": mysql_hash.hexdigest(),
                "match": sqlite_count == mysql_count and sqlite_hash.digest() == mysql_hash.digest()}
        result.append(item)
        save(report_name, result)
        emit("verified", table=table["name"], rows=mysql_count, match=item["match"])
    return result
