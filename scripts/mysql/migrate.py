"""Explicit, empty-target-only import. Never switches an application or edits a source.

Use a frozen SQLite backup, an approved exact exception manifest, a socket-only
maintenance account, and the independently observed new server UUID.
"""
from __future__ import annotations
import argparse,collections,hashlib,json,os,pathlib,re,sqlite3,sys,traceback
import conversion as core

def file_hash(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream,'sha256').hexdigest()

def protected_json(path):
    if path.is_symlink() or not path.is_file() or (os.name!='nt' and path.stat().st_mode&0o077):
        raise RuntimeError('Private inputs require a protected regular file.')
    return json.loads(path.read_text(encoding='utf-8'))

def audit_exceptions(source,schema):
    details=[]
    for table in schema:
        groups=collections.defaultdict(list)
        for key in table['foreign_keys']:groups[key['id']].append(key)
        for number,keys in sorted(groups.items()):
            keys.sort(key=lambda key:key['seq']);parent=keys[0]['table']
            if not table['primary']:raise RuntimeError('Exception audit requires stable row identities.')
            columns=list(dict.fromkeys(table['primary']+[key['from'] for key in keys]))
            query='SELECT '+','.join('child.'+core.q(column) for column in columns)+' FROM '+core.q(table['name'])+' child WHERE '
            query+=' AND '.join('child.'+core.q(key['from'])+' IS NOT NULL' for key in keys)
            query+=' AND NOT EXISTS (SELECT 1 FROM '+core.q(parent)+' parent WHERE '
            query+=' AND '.join('parent.'+core.q(key['to'])+'=child.'+core.q(key['from']) for key in keys)+')'
            query+=' ORDER BY '+','.join('child.'+core.q(column) for column in table['primary'])
            rows=[dict(row) for row in source.execute(query)]
            if rows:details.append({'table':table['name'],'foreign_key':number,'columns':[key['from'] for key in keys],
                'parent_table':parent,'nullable':{key['from']:not next(c for c in table['columns'] if c['name']==key['from'])['notnull'] for key in keys},
                'orphan_count':len(rows),'rows':rows})
    return details

def canonical_exceptions(details):
    normalized=[]
    for item in details:
        item=dict(item)
        item['rows']=sorted(item['rows'],key=lambda row:json.dumps(row,sort_keys=True,separators=(',',':')))
        normalized.append(item)
    return sorted(normalized,key=lambda item:(item['table'],item['foreign_key']))

def destination(credentials,database,uuid):
    config=protected_json(credentials)
    if set(config)!={'unix_socket','user','password'} or not str(config['unix_socket']).startswith('/'):
        raise RuntimeError('Only an explicit local Unix socket and scoped maintenance account are accepted.')
    if not re.fullmatch(r'repro_[A-Za-z0-9_]{1,50}',database) or not re.fullmatch(r'[a-f0-9-]{36}',uuid):
        raise RuntimeError('An explicit RePro database and independently observed server UUID are required.')
    db=core.pymysql.connect(**config,database=database,charset='utf8mb4',autocommit=True,connect_timeout=5,read_timeout=180,write_timeout=180)
    with db.cursor() as cursor:
        cursor.execute('SELECT DATABASE(),VERSION(),@@server_uuid,@@innodb_flush_log_at_trx_commit,@@sync_binlog,@@FOREIGN_KEY_CHECKS,@@sql_mode')
        name,version,actual_uuid,durable,binlog,fk,mode=cursor.fetchone()
        if name!=database or version.split('-')[0]!='8.4.13' or actual_uuid!=uuid or (durable,binlog,fk)!=(1,1,1):
            raise RuntimeError('Target identity, tested patch version or durability differs from the approved rehearsal.')
        if not {'STRICT_TRANS_TABLES','ONLY_FULL_GROUP_BY'}<=set(mode.split(',')):
            raise RuntimeError('Strict SQL and grouped-query enforcement are required.')
        cursor.execute('SET SESSION time_zone=\'+00:00\'')
        cursor.execute('SELECT GET_LOCK(%s,0)',['repro-sqlite-import:'+database])
        if cursor.fetchone()[0]!=1:raise RuntimeError('Another import holds the target lock.')
        for catalog,column in [('TABLES','TABLE_SCHEMA'),('TRIGGERS','TRIGGER_SCHEMA'),('ROUTINES','ROUTINE_SCHEMA'),('EVENTS','EVENT_SCHEMA')]:
            cursor.execute('SELECT COUNT(*) FROM information_schema.'+catalog+' WHERE '+column+'=%s',[database])
            if cursor.fetchone()[0]:raise RuntimeError('Destination must be completely empty; existing objects are never replaced.')
    return db

def install_legacy_constraints(db,schema,exceptions,failures):
    expected={(entry['table'],entry['foreign_key']) for entry in exceptions}
    actual={(entry['table'],entry['object']) for entry in failures if entry['kind']=='foreign_key' and entry['error_code']==1452}
    if len(failures)!=len(expected) or expected!=actual:
        raise RuntimeError('Constraint failures differ from the exact approved historical exceptions.')
    by_name={table['name']:table for table in schema}
    with db.cursor() as cursor:
        for table_name,number in sorted(expected):
            keys=sorted([key for key in by_name[table_name]['foreign_keys'] if key['id']==number],key=lambda key:key['seq'])
            first=keys[0]
            statement='ALTER TABLE '+core.q(table_name)+' ADD CONSTRAINT '+core.q(core.bounded_name('fk_',table_name+'_'+str(number)))
            statement+=' FOREIGN KEY ('+','.join(core.q(key['from']) for key in keys)+') REFERENCES '+core.q(first['table'])
            statement+=' ('+','.join(core.q(key['to']) for key in keys)+') ON DELETE '+first['on_delete']+' ON UPDATE '+first['on_update']
            try:
                cursor.execute('SET SESSION FOREIGN_KEY_CHECKS=0');cursor.execute(statement)
            finally:cursor.execute('SET SESSION FOREIGN_KEY_CHECKS=1')
        cursor.execute('SELECT @@FOREIGN_KEY_CHECKS')
        if cursor.fetchone()[0]!=1:raise RuntimeError('Foreign-key enforcement was not restored.')

def sqlite_triggers(source):
    definitions=dict(source.execute("SELECT name,sql FROM sqlite_master WHERE type='trigger' ORDER BY name"))
    tables={row[0] for row in source.execute("SELECT name FROM sqlite_master WHERE type='table'")}
    required={'shoot_files','payments','shoots','shoot_service','payment_service_allocations','aryeo_changes'}
    if required<=tables:
        for table in ['shoot_files','payments']:
            for event,row in [('INSERT','NEW'),('UPDATE','NEW'),('DELETE','OLD')]:
                name='aryeo_'+table+'_'+event
                condition=(' WHEN '+row+'.shoot_id IS NOT NULL') if table=='payments' else ''
                definitions.setdefault(name,'CREATE TRIGGER '+name+' AFTER '+event+' ON '+table+condition+' BEGIN INSERT INTO aryeo_changes (shoot_id,client_id,kind) VALUES ('+row+'.shoot_id,(SELECT client_id FROM shoots WHERE id='+row+".shoot_id),'"+table+'.'+event+"'); END")
            name='aryeo_'+table+'_moved'
            definitions.setdefault(name,'CREATE TRIGGER '+name+' AFTER UPDATE OF shoot_id ON '+table+' WHEN OLD.shoot_id IS NOT NEW.shoot_id AND OLD.shoot_id IS NOT NULL BEGIN INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT id,client_id,\''+table+'.moved\' FROM shoots WHERE id=OLD.shoot_id; END')
        definitions.setdefault('aryeo_payment_status',"CREATE TRIGGER aryeo_payment_status AFTER UPDATE ON payments BEGIN INSERT INTO aryeo_changes (shoot_id,client_id,kind) SELECT DISTINCT s.id,s.client_id,'payment.status' FROM shoots s JOIN shoot_service ss ON ss.shoot_id=s.id JOIN payment_service_allocations a ON a.shoot_service_id=ss.id WHERE a.payment_id=NEW.id; END")
    return definitions

def install_triggers(db,source):
    definitions=sqlite_triggers(source)
    with db.cursor() as cursor:
        for name,sql in definitions.items():
            match=re.fullmatch(r'CREATE TRIGGER (\w+) (BEFORE|AFTER) (INSERT|UPDATE|DELETE)(?: OF (\w+))? ON (\w+)(?: WHEN (.*?))? BEGIN (.*) END',sql,re.I|re.S)
            if not match:raise RuntimeError('Unsupported trigger requires explicit review: '+name)
            trigger,timing,event,column,table,condition,body=match.groups()
            if column and not condition:raise RuntimeError('Column trigger needs an explicit comparison: '+name)
            if condition:
                condition=re.sub(r'(OLD\.\w+) IS NOT (NEW\.\w+)',r'NOT (\1 <=> \2)',condition,flags=re.I)
                body='IF '+condition+' THEN '+body+' END IF;'
            cursor.execute('CREATE TRIGGER '+core.q(trigger)+' '+timing+' '+event+' ON '+core.q(table)+' FOR EACH ROW BEGIN '+body+' END')
    return len(definitions)

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('mode',choices=['plan','import-empty'])
    parser.add_argument('--snapshot',type=pathlib.Path,required=True)
    parser.add_argument('--snapshot-sha256',required=True)
    parser.add_argument('--output',type=pathlib.Path,required=True)
    parser.add_argument('--credentials',type=pathlib.Path)
    parser.add_argument('--database')
    parser.add_argument('--expected-server-uuid')
    parser.add_argument('--approved-exceptions',type=pathlib.Path)
    parser.add_argument('--exceptions-file-sha256')
    options=parser.parse_args()
    if options.output.exists():raise RuntimeError('Output must be a new private directory.')
    options.output.mkdir(mode=0o700,parents=False);core.REPORTS=options.output
    snapshot=options.snapshot.resolve(strict=True)
    if options.snapshot.is_symlink() or not re.fullmatch('[a-f0-9]{64}',options.snapshot_sha256) or file_hash(snapshot)!=options.snapshot_sha256:
        raise RuntimeError('Frozen snapshot checksum does not match.')
    source=sqlite3.connect(snapshot.as_uri()+'?mode=ro&immutable=1',uri=True);source.row_factory=sqlite3.Row
    source.execute('PRAGMA query_only=ON')
    if source.execute('PRAGMA quick_check').fetchone()[0]!='ok':raise RuntimeError('SQLite snapshot integrity check failed.')
    schema=[core.table_schema(source,name,sql) for name,sql in source.execute("SELECT name,sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")]
    if not schema:raise RuntimeError('Snapshot has no application tables.')
    core.save('schema-plan.json',schema)
    exceptions=audit_exceptions(source,schema);core.save('exceptions-private.json',exceptions)
    result={'mode':options.mode,'source_sha256':options.snapshot_sha256,'tables':len(schema),'legacy_exception_relationships':len(exceptions),
            'source_changed':False,'application_configuration_changed':False,'cutover_ready':False}
    if options.mode=='import-empty':
        if not all([options.credentials,options.database,options.expected_server_uuid,options.approved_exceptions,options.exceptions_file_sha256]):
            raise RuntimeError('Import requires private credentials, target identity and an approved exception manifest checksum.')
        if file_hash(options.approved_exceptions)!=options.exceptions_file_sha256:raise RuntimeError('Approved exception file checksum differs.')
        approved=protected_json(options.approved_exceptions)
        if canonical_exceptions(approved)!=canonical_exceptions(exceptions):raise RuntimeError('Historical exceptions changed; review the fresh audit before importing.')
        db=destination(options.credentials,options.database,options.expected_server_uuid)
        try:
            constraints=core.apply(db,source,schema)
            verified=core.verify(db,source,schema)
            if not all(row['match'] for row in verified):raise RuntimeError('Original row/field hashes differ after import.')
            install_legacy_constraints(db,schema,exceptions,constraints['failures'])
            triggers=install_triggers(db,source)
            result.update(import_verified=True,original_rows=sum(row['source_rows'] for row in verified),triggers=triggers,
                          target_database=options.database,target_server_uuid=options.expected_server_uuid,
                          next_step='Apply the reviewed application migrations in isolated maintenance configuration, then repeat acceptance gates before any traffic switch.')
        finally:db.close()
    source.close()
    if file_hash(snapshot)!=options.snapshot_sha256:raise RuntimeError('Snapshot changed during conversion.')
    core.save('result.json',result);print(json.dumps(result),flush=True)

if __name__=='__main__':
    os.umask(0o077)
    try:main()
    except Exception as error:
        if core.REPORTS is not None:core.save('failure-private.json',{'type':type(error).__name__,'traceback':traceback.format_exc()})
        print(json.dumps({'stopped':True,'error_type':type(error).__name__,'error_code':error.args[0] if isinstance(error,core.pymysql.MySQLError) else None,
                          'details':'See the protected report. No application configuration or source database was changed.'}),flush=True)
        raise SystemExit(1)
