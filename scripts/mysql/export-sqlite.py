"""Read-only reverse export into a NEW SQLite file. Never changes a live connection.

Drain/stop writers externally first. Supply the reviewed current-release SQLite
schema template, its checksum, the exact approved exceptions and source UUID.
Every copied field is verified; precision loss or unknown schema stops recovery.
"""
from __future__ import annotations
import argparse,datetime,decimal,hashlib,json,os,pathlib,re,sqlite3,time,traceback
from contextlib import closing
import conversion as core
import migrate

IGUIDE_PROJECT_TRIGGER = 'iguide_lab_project_delete_dependencies'
IGUIDE_PROJECT_TABLES = {
    'iguide_lab_projects', 'iguide_lab_compositions', 'iguide_lab_pipelines', 'iguide_lab_masks',
    'iguide_lab_sections', 'iguide_lab_workflow_items', 'iguide_lab_tour_run_targets',
    'iguide_lab_tour_captures', 'iguide_lab_tour_surveys',
}
# The normalized body from 2026_11_22_120200_preserve_iguide_lab_project_deletion.php.
IGUIDE_PROJECT_TRIGGER_SHA256 = '4d40869921f1abab41729467e05bcea46a0ecaa7a09983160641800a0e5984ce'
CASCADE_TRIGGER_MANIFEST = pathlib.Path(__file__).resolve().parents[2] / 'database/schema/mysql-cascade-triggers.json'


class ReverseInputError(RuntimeError):
    pass


def journal_mode_from_header(path):
    # The immutable reader deliberately ignores WAL sidecars and reports DELETE even
    # for a checkpointed WAL file. Bytes 18/19 are SQLite's persistent read/write versions.
    with path.open('rb') as stream:
        header = stream.read(20)
    if not header.startswith(b'SQLite format 3\0') or header[18:20] not in (b'\x01\x01', b'\x02\x02'):
        raise ReverseInputError('Invalid or unsupported SQLite journal format in the database header.')
    return 'wal' if header[18:20] == b'\x02\x02' else 'delete'

def portable(value):
    if isinstance(value,(datetime.date,datetime.time,datetime.datetime,decimal.Decimal)):return str(value)
    if isinstance(value,datetime.timedelta):
        seconds=value.total_seconds();sign='-' if seconds<0 else '';seconds=abs(seconds)
        return f'{sign}{int(seconds//3600):02}:{int(seconds%3600//60):02}:{seconds%60:09.6f}'
    return value

def connect(options):
    if core.pymysql is None:
        raise RuntimeError('Install the pinned PyMySQL dependency before exporting.')
    config=migrate.protected_json(options.credentials)
    if set(config)!={'unix_socket','user','password'} or not str(config['unix_socket']).startswith('/'):
        raise RuntimeError('An explicit local socket and protected account are required.')
    if not re.fullmatch(r'repro_[A-Za-z0-9_]{1,50}',options.database) or not re.fullmatch('[a-f0-9-]{36}',options.expected_server_uuid):
        raise RuntimeError('Explicit RePro source identity is required.')
    db=core.pymysql.connect(**config,database=options.database,charset='utf8mb4',autocommit=True,connect_timeout=5,read_timeout=180)
    with db.cursor() as cursor:
        cursor.execute('SELECT DATABASE(),VERSION(),@@server_uuid,@@innodb_flush_log_at_trx_commit,@@sync_binlog')
        name,version,uuid,commit,binlog=cursor.fetchone()
        if (name,version.split('-')[0],uuid,commit,binlog)!=(options.database,'8.4.13',options.expected_server_uuid,1,1):
            raise RuntimeError('Source identity, validated patch version or durability changed.')
        cursor.execute("SET SESSION time_zone='+00:00'")
        for catalog,column in [('VIEWS','TABLE_SCHEMA'),('ROUTINES','ROUTINE_SCHEMA'),('EVENTS','EVENT_SCHEMA')]:
            cursor.execute('SELECT COUNT(*) FROM information_schema.'+catalog+' WHERE '+column+'=DATABASE()')
            if cursor.fetchone()[0]:raise RuntimeError('MySQL-only objects require an explicit reverse mapping.')
        cursor.execute("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND ENGINE<>'InnoDB'")
        if cursor.fetchone()[0]:raise RuntimeError('A consistent reverse export requires InnoDB.')
    return db

def schema_state(db):
    with db.cursor(core.pymysql.cursors.DictCursor) as cursor:
        cursor.execute('SELECT TABLE_NAME,COLUMN_NAME,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION')
        metadata={}
        for row in cursor:metadata.setdefault(row['TABLE_NAME'],{})[row['COLUMN_NAME']]=row['IS_NULLABLE']=='YES'
        cursor.execute('SELECT TRIGGER_NAME,ACTION_TIMING,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME')
        triggers=cursor.fetchall()
        tables={}
        for name in metadata:
            cursor.execute('SHOW CREATE TABLE '+core.q(name));tables[name]=cursor.fetchone()['Create Table']
    return metadata,triggers,tables

def expected_source_triggers(tables, sqlite_triggers):
    expected = set(sqlite_triggers)
    if IGUIDE_PROJECT_TRIGGER in expected:
        raise RuntimeError('The MySQL-only iGUIDE trigger cannot be in the SQLite template.')
    if {'aryeo_changes', 'shoots', 'shoot_files', 'payments'} <= tables:
        try:
            names = [row['name'] for row in json.loads(CASCADE_TRIGGER_MANIFEST.read_text(encoding='utf-8'))]
        except (OSError, ValueError, KeyError, TypeError) as error:
            raise ReverseInputError('Required reverse-export cascade trigger manifest is missing or invalid.') from error
        if not names or not all(isinstance(name, str) and re.fullmatch(r'[A-Za-z_][A-Za-z0-9_]{0,63}', name)
                                for name in names) or len(names) != len(set(names)):
            raise ReverseInputError('Required reverse-export cascade trigger manifest is missing or invalid.')
        expected.update(names)
    if IGUIDE_PROJECT_TABLES <= tables:
        expected.add(IGUIDE_PROJECT_TRIGGER)
    return expected


def verify_source_triggers(tables, sqlite_triggers, mysql_triggers, expected=None):
    if expected is None:
        expected = expected_source_triggers(tables, sqlite_triggers)
    by_name = {row['TRIGGER_NAME']: row for row in mysql_triggers}
    if expected != by_name.keys():
        raise RuntimeError('Unknown/missing source trigger needs review before reverse export.')
    if IGUIDE_PROJECT_TRIGGER in by_name:
        trigger = by_name[IGUIDE_PROJECT_TRIGGER]
        body = ' '.join(trigger['ACTION_STATEMENT'].split())
        if (trigger['EVENT_OBJECT_TABLE'], trigger['ACTION_TIMING'], trigger['EVENT_MANIPULATION']) != (
            'iguide_lab_projects', 'BEFORE', 'DELETE',
        ) or hashlib.sha256(body.encode('utf-8')).hexdigest() != IGUIDE_PROJECT_TRIGGER_SHA256:
            raise RuntimeError('The MySQL-only iGUIDE trigger definition differs from the reviewed migration.')

def prepare_reverse_inputs(options):
    template_path = options.schema_template.resolve(strict=True)
    if (options.schema_template.is_symlink() or not template_path.is_file() or
            any(os.path.lexists(str(template_path) + suffix) for suffix in ('-wal', '-shm', '-journal')) or
            migrate.file_hash(template_path) != options.schema_template_sha256):
        raise ReverseInputError('Schema template must be standalone and match the reviewed checksum.')
    if migrate.file_hash(options.approved_exceptions) != options.exceptions_file_sha256:
        raise ReverseInputError('Approved exception file checksum differs.')
    approved = migrate.protected_json(options.approved_exceptions)
    if not isinstance(approved, list):
        raise ReverseInputError('Approved exceptions must be the reviewed relationship list.')
    approved = migrate.canonical_exceptions(approved)
    with closing(sqlite3.connect(template_path.as_uri() + '?mode=ro&immutable=1', uri=True)) as template:
        template.row_factory = sqlite3.Row
        template.execute('PRAGMA query_only=ON')
        if template.execute('PRAGMA quick_check').fetchone()[0] != 'ok':
            raise ReverseInputError('Schema-template integrity check failed.')
        definitions = [dict(row) for row in template.execute(
            "SELECT type,name,sql FROM sqlite_master WHERE sql IS NOT NULL "
            "AND type IN ('table','index','trigger') ORDER BY type,name")]
        schema = {name: core.table_schema(template, name, sql) for name, sql in template.execute(
            "SELECT name,sql FROM sqlite_master WHERE type='table' "
            "AND name NOT LIKE 'sqlite_%' ORDER BY name")}
        if not schema:
            raise ReverseInputError('Reviewed SQLite schema template has no application tables.')
        sqlite_triggers = migrate.sqlite_triggers(template)
    expected = expected_source_triggers(schema.keys(), sqlite_triggers)
    return template_path, approved, definitions, schema, sqlite_triggers, expected


def export(options):
    started=time.monotonic()
    final_mode = getattr(options, 'sqlite_journal_mode', 'delete')
    if final_mode not in ('delete', 'wal'):
        raise ReverseInputError('Reviewed SQLite journal mode must be delete or wal.')
    if not options.writers_stopped:raise RuntimeError('Externally drain/stop all writers and explicitly attest the freeze.')
    if options.output.exists():raise RuntimeError('Output must be a new private directory; existing files are never replaced.')
    template_path, approved, definitions, schema, sqlite_triggers, expected = prepare_reverse_inputs(options)
    options.output.mkdir(mode=0o700,parents=False);core.REPORTS=options.output
    db=connect(options)
    try:
        before=schema_state(db);metadata,triggers,ddl=before
        if set(metadata)!=set(schema):raise RuntimeError('Current-release SQLite template and source tables differ.')
        verify_source_triggers(metadata.keys(), sqlite_triggers, triggers, expected)
        destination=options.output/'database.sqlite'
        with destination.open('xb'):pass
        destination.chmod(0o600)
        out=sqlite3.connect(destination)
    except BaseException:
        db.close()
        raise
    out.execute('PRAGMA journal_mode=DELETE');out.execute('PRAGMA synchronous=FULL');out.execute('PRAGMA foreign_keys=OFF')
    try:
        for entry in definitions:
            if entry['type']!='table' or entry['name'].startswith('sqlite_'):continue
            name=entry['name'];sql=entry['sql'];start=sql.index('(');end=sql.rindex(')');parts=[]
            for part in core.split_sql(sql[start+1:end]):
                match=re.match(r'^\s*[`"\[]?([A-Za-z_][A-Za-z0-9_]*)[`"\]]?\s+',part)
                if match and metadata[name].get(match[1]) is True:part=re.sub(r'\s+NOT\s+NULL\b','',part,flags=re.I)
                parts.append(part)
            out.execute(sql[:start+1]+','.join(parts)+sql[end:])
            if {row[1] for row in out.execute('PRAGMA table_info('+core.q(name)+')')}!=set(metadata[name]):
                raise RuntimeError('Current-release SQLite template is missing source columns: '+name)
        with db.cursor() as cursor:
            cursor.execute('SET TRANSACTION READ ONLY');cursor.execute('START TRANSACTION WITH CONSISTENT SNAPSHOT')
        verified=[]
        for name,table in schema.items():
            columns=table['columns'];names=','.join(core.q(column['name']) for column in columns)
            order=table['primary'] or [column['name'] for column in columns]
            query='SELECT '+names+' FROM '+core.q(name)+' ORDER BY '+','.join(map(core.q,order))
            source_hash=hashlib.sha256();count=0
            with db.cursor(core.pymysql.cursors.SSCursor) as cursor:
                cursor.execute(query)
                while batch:=cursor.fetchmany(500):
                    for row in batch:core.row_digest(source_hash,row,columns)
                    out.executemany('INSERT INTO '+core.q(name)+' ('+names+') VALUES ('+','.join('?' for column in columns)+')',
                        [tuple(portable(value) for value in row) for row in batch]);count+=len(batch)
            out.commit();target_hash=hashlib.sha256();target_count=0
            for row in out.execute(query):core.row_digest(target_hash,row,columns);target_count+=1
            match=count==target_count and source_hash.digest()==target_hash.digest()
            verified.append({'table':name,'rows':count,'match':match,'mysql_sha256':source_hash.hexdigest(),'sqlite_sha256':target_hash.hexdigest()})
            core.save('data-verification.json',verified);core.emit('reverse_verified',table=name,rows=count,match=match)
            if not match:raise RuntimeError('Reverse field/precision verification failed: '+name)
        with db.cursor() as cursor:
            cursor.execute('SELECT TABLE_NAME,AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND AUTO_INCREMENT IS NOT NULL')
            sequences=cursor.fetchall()
        db.rollback()
        if schema_state(db)!=before:raise RuntimeError('Source DDL or next IDs changed during reverse export; freeze was incomplete.')
        for entry in definitions:
            if entry['type']=='index' and entry['sql']:out.execute(entry['sql'])
        for sql in sqlite_triggers.values():out.execute(sql)
        for name,next_id in sequences:
            if out.execute('SELECT COUNT(*) FROM sqlite_sequence WHERE name=?',[name]).fetchone()[0]:out.execute('UPDATE sqlite_sequence SET seq=? WHERE name=?',[next_id-1,name])
            else:out.execute('INSERT INTO sqlite_sequence(name,seq) VALUES (?,?)',[name,next_id-1])
        out.commit();out.row_factory=sqlite3.Row
        if out.execute('PRAGMA quick_check').fetchone()[0]!='ok':raise RuntimeError('Reverse SQLite integrity check failed.')
        exceptions=migrate.audit_exceptions(out,list(schema.values()));core.save('exceptions-private.json',exceptions)
        if migrate.canonical_exceptions(exceptions)!=approved:raise RuntimeError('Reverse historical exceptions differ; investigate before any switch.')
        out.execute('PRAGMA foreign_keys=ON')
        if out.execute('PRAGMA foreign_keys').fetchone()[0]!=1:raise RuntimeError('SQLite foreign-key enforcement was not restored.')
    finally:
        out.close();db.close()
    if migrate.file_hash(template_path)!=options.schema_template_sha256:raise RuntimeError('Schema template changed during export.')
    # Prepare the reviewed format before hashing. Use a fresh connection after all
    # export cursors have closed so no statement from field verification holds a lock.
    if final_mode == 'wal':
        with closing(sqlite3.connect(destination, isolation_level=None)) as finish:
            if finish.execute('PRAGMA journal_mode=WAL').fetchall() != [('wal',)]:
                raise ReverseInputError('The new SQLite file could not enter the reviewed WAL journal mode.')
            if finish.execute('PRAGMA wal_checkpoint(TRUNCATE)').fetchall() != [(0, 0, 0)]:
                raise ReverseInputError('The new SQLite WAL could not be fully checkpointed.')
    if (journal_mode_from_header(destination) != final_mode or
            any(os.path.lexists(str(destination) + suffix) for suffix in ('-wal', '-shm', '-journal'))):
        raise ReverseInputError('The reverse SQLite file must be standalone in the reviewed journal format.')
    result={'verified':True,'tables':len(verified),'rows':sum(row['rows'] for row in verified),'field_mismatches':0,
        'sqlite_triggers':len(sqlite_triggers),'exception_relationships':len(exceptions),'foreign_keys_enabled':True,
        'database_sha256':migrate.file_hash(destination),'sqlite_journal_mode':final_mode,'source_server_uuid':options.expected_server_uuid,
        'schema_template_sha256':options.schema_template_sha256,'seconds':round(time.monotonic()-started,1),
        'application_configuration_changed':False,'cutover_ready':False,
        'next_step':'Independently validate the new SQLite artifact and external-side-effect reconciliation before the approved connection switch.'}
    core.save('result.json',result);print(json.dumps(result),flush=True)

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--credentials',type=pathlib.Path);parser.add_argument('--database')
    parser.add_argument('--expected-server-uuid');parser.add_argument('--schema-template',type=pathlib.Path,required=True)
    parser.add_argument('--schema-template-sha256',required=True);parser.add_argument('--approved-exceptions',type=pathlib.Path,required=True)
    parser.add_argument('--exceptions-file-sha256',required=True);parser.add_argument('--output',type=pathlib.Path)
    parser.add_argument('--writers-stopped',action='store_true')
    parser.add_argument('--sqlite-journal-mode', choices=('delete', 'wal'), default='delete',
                        help='persistent format of the verified export; select the reviewed SQLite runtime mode')
    parser.add_argument('--check-inputs-only', action='store_true')
    options = parser.parse_args()
    if options.check_inputs_only:
        _, approved, _, schema, sqlite_triggers, expected = prepare_reverse_inputs(options)
        print(json.dumps({'result':'reverse-inputs-verified','tables':len(schema),
                          'historical_exception_relationships':len(approved),'sqlite_triggers':len(sqlite_triggers),
                          'expected_mysql_triggers':len(expected),'mysql_connected':False,'output_created':False}),
              flush=True)
        return
    if any(not getattr(options, argument) for argument in ('credentials', 'database', 'expected_server_uuid', 'output')):
        parser.error('export requires --credentials, --database, --expected-server-uuid and --output')
    export(options)

if __name__=='__main__':
    os.umask(0o077)
    try:main()
    except Exception as error:
        if core.REPORTS is not None:core.save('failure-private.json',{'type':type(error).__name__,'traceback':traceback.format_exc()})
        details = str(error) if isinstance(error, ReverseInputError) else (
            'See the protected report.' if core.REPORTS is not None else 'Reverse export refused before starting.')
        print(json.dumps({'stopped':True,'error_type':type(error).__name__,'details':details}),flush=True)
        raise SystemExit(1)
