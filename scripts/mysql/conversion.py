"""SQLite conversion primitives verified on the isolated full-size rehearsal.

Called only through migrate.py. No credentials, fixed lab paths or reset commands.
Canonical hashes preserve strings and compare numeric/time values by meaning.
"""
from __future__ import annotations
import collections, datetime as dt, decimal, hashlib, json, pathlib, re, time
import pymysql

COLLATION = "utf8mb4_0900_bin"
TYPE_OVERRIDES = {("services", "delivery_time"): ("LONGTEXT", "text"),
                  ("voice_calls", "duration_seconds"): ("DECIMAL(65,30)", "decimal")}
REPORTS = None

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
                if maximum > 768:
                    raise ValueError("Indexed text exceeds InnoDB key limit: " + name + "." + column["name"])
                target = "VARCHAR(" + str(max(255, maximum)) + ")"
            else:
                target = "LONGTEXT"
            kind = "text"
        elif "BLOB" in declared or not declared:
            target, kind = "LONGBLOB", "blob"
        elif declared == "DATE":
            target, kind = "DATE", "date"
        elif declared in ["DATETIME", "TIMESTAMP"]:
            target, kind = "DATETIME(6)", "datetime"
        elif declared == "TIME":
            target, kind = "TIME(6)", "time"
        elif any(t in declared for t in ["REAL", "FLOAT", "DOUBLE"]):
            target, kind = "DOUBLE", "float"
        elif any(t in declared for t in ["NUMERIC", "DECIMAL", "BOOLEAN"]):
            target, kind = "DECIMAL(65,30)", "decimal"
        else:
            raise ValueError("Unknown SQLite type: " + declared)
        if (name, column["name"]) in TYPE_OVERRIDES:
            target, kind = TYPE_OVERRIDES[(name, column["name"])]
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
            if default.upper() == "CURRENT_TIMESTAMP":
                part += " DEFAULT CURRENT_TIMESTAMP(6)"
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
    result = {"indexes": 0, "foreign_keys": 0, "checks": 0, "failures": []}
    with mysql.cursor() as cursor:
        for table in schema:
            for index in table["indexes"]:
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
    if kind in ["date", "datetime", "time"]:
        text = str(value)
        if kind == "date":
            return [kind, dt.date.fromisoformat(text[:10]).isoformat()]
        if kind == "time":
            if isinstance(value, dt.timedelta):
                return [kind, str(decimal.Decimal(str(value.total_seconds())).normalize())]
            match = re.fullmatch(r"(-?)(\d+):(\d{2})(?::(\d{2})(?:\.(\d+))?)?", text)
            if not match:
                raise ValueError("Unsupported time representation")
            sign, hour, minute, second, fraction = match.groups()
            seconds = decimal.Decimal(hour) * 3600 + decimal.Decimal(minute) * 60 + decimal.Decimal(second or 0)
            if fraction:
                seconds += decimal.Decimal('0.' + fraction)
            return [kind, str((-seconds if sign else seconds).normalize())]
        if kind == "datetime":
            fraction = re.search(r"\.(\d+)", text)
            if fraction and len(fraction[1]) > 6 and any(c != '0' for c in fraction[1][6:]):
                raise ValueError("Sub-microsecond precision requires explicit preservation")
            parsed = value if isinstance(value, dt.datetime) else dt.datetime.fromisoformat(text)
            if parsed.tzinfo:
                parsed = parsed.astimezone(dt.timezone.utc).replace(tzinfo=None)
            return [kind, parsed.isoformat(timespec='microseconds')]
    if isinstance(value, bytes):
        return ["bytes", value.hex()]
    return ["text", str(value)]


def row_digest(hasher, row, columns):
    value = [canonical(item, column) for item, column in zip(row, columns)]
    hasher.update(json.dumps(value, ensure_ascii=False, separators=(",", ":")).encode("utf-8") + b"\n")


def apply(mysql, src, schema):
    with mysql.cursor() as cursor:
        cursor.execute("SHOW TABLES")
        existing = cursor.fetchall()
        if existing:
            raise RuntimeError("Destination must be empty; existing objects are never dropped.")
        for table in schema:
            cursor.execute(table["create"])
    progress = []
    sequences = {row[0]: row[1] for row in src.execute("SELECT name,seq FROM sqlite_sequence")}
    for table in schema:
        started = time.monotonic()
        columns = table["columns"]
        names = ",".join(q(column["name"]) for column in columns)
        source_cursor = src.execute("SELECT " + names + " FROM " + q(table["name"]))
        statement = "INSERT INTO " + q(table["name"]) + " (" + names + ") VALUES (" + ",".join(["%s"] * len(columns)) + ")"
        count = 0
        while True:
            batch = source_cursor.fetchmany(200)
            if not batch:
                break
            with mysql.cursor() as cursor:
                cursor.executemany(statement, [tuple(row) for row in batch])
            count += len(batch)
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
        item = {"table": table["name"], "source_rows": sqlite_count, "target_rows": mysql_count,
                "source_sha256": sqlite_hash.hexdigest(), "target_sha256": mysql_hash.hexdigest(),
                "match": sqlite_count == mysql_count and sqlite_hash.digest() == mysql_hash.digest()}
        result.append(item)
        save(report_name, result)
        emit("verified", table=table["name"], rows=mysql_count, match=item["match"])
    return result
