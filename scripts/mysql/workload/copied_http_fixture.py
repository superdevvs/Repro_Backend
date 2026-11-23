"""Run the two-route Laravel/FPM pilot inside the dedicated Linux copy supervisor."""

import argparse
import base64
import hashlib
import json
import os
from pathlib import Path
import re
import sqlite3
import stat
import subprocess
import sys
import time
from datetime import datetime, timezone

import laravel_http_fixture as fixture


def workload(root, calibration_sha256, media=False):
    routes = [{'label': label, 'method': 'GET', 'path_template': route, 'role': label, 'fixture': label,
               'weight': weight, 'expected_status': 200} for label, (_, route, weight) in fixture.fixture_actors(media).items()]
    if calibration_sha256 is None:
        scope = 'native-copied-upload-summary-fpm-smoke' if media else 'native-copied-two-route-fpm-smoke'
        return scope, routes, [
            {'name': name, 'duration_ms': 4000, 'arrival_rate_milli_rps': rate}
            for name, rate in (('warmup', 2000), ('peak', 4000), ('surge', 8000))
        ], 2
    fixture.need(not media, 'copy-pilot-calibration-cannot-add-upload-route')

    path = root / 'inputs/calibration.json'
    fixture.need(path.is_file() and path.resolve() == path, 'copy-pilot-calibration-path')
    with os.fdopen(os.open(path, os.O_RDONLY | os.O_NOFOLLOW), 'rb') as stream:
        raw = stream.read(65537)
    fixture.need(len(raw) <= 65536 and hashlib.sha256(raw).hexdigest() == calibration_sha256, 'copy-pilot-calibration-pin')
    data = json.loads(raw, object_pairs_hook=fixture.contract.pairs, parse_constant=fixture.contract.reject_constant)
    fixture.need(data['version'] == 1 and data['scope'] == 'isolated-host-two-route-pilot-calibration',
                 'copy-pilot-calibration-scope')
    proposed = data['proposed_routes']
    fixture.need(isinstance(proposed, list) and len(proposed) == len(routes), 'copy-pilot-calibration-routes')
    for actual, expected in zip(proposed, routes):
        fixture.need({**actual, 'weight': expected['weight']} == expected, 'copy-pilot-calibration-route-changed')
        fixture.contract.integer(actual['weight'], 1, 10000, 'copy-pilot-calibration-weight')
    phases = data['proposed_phases']
    fixture.need(isinstance(phases, list) and len(phases) == 3, 'copy-pilot-calibration-phases')
    for phase, name in zip(phases, ('warmup', 'peak', 'surge')):
        fixture.contract.keys(phase, {'name', 'duration_ms', 'arrival_rate_milli_rps'}, 'copy-pilot-calibration-fields')
        fixture.need(phase['name'] == name, 'copy-pilot-calibration-phase-order')
        fixture.contract.integer(phase['duration_ms'], 1000, 180000, 'copy-pilot-calibration-duration')
        fixture.contract.integer(phase['arrival_rate_milli_rps'], 1, 6000, 'copy-pilot-calibration-rate')
    fixture.need(sum(phase['duration_ms'] for phase in phases) <= 480000
                 and phases[2]['arrival_rate_milli_rps'] >= phases[1]['arrival_rate_milli_rps'],
                 'copy-pilot-calibration-runtime')
    # The planner needs each route to appear in each measured phase. Refuse a
    # profile that would fail that check before opening either database.
    available = [phase['duration_ms'] * phase['arrival_rate_milli_rps'] // 1_000_000 for phase in phases[1:]]
    total_weight = sum(route['weight'] for route in proposed)
    fixture.need(min(available) * min(route['weight'] for route in proposed) >= total_weight,
                 'copy-pilot-calibration-insufficient-route-samples')
    concurrency = data['proposed_max_in_flight']
    fixture.contract.integer(concurrency, 1, 20, 'copy-pilot-calibration-concurrency')
    return 'native-copied-two-route-fpm-load', proposed, phases, concurrency


def owned(path, *, directory=False):
    info = path.lstat()
    kind = stat.S_ISDIR if directory else stat.S_ISREG
    fixture.need(path.resolve() == path and kind(info.st_mode) and info.st_uid == os.geteuid()
                 and stat.S_IMODE(info.st_mode) == (0o700 if directory else 0o600), 'copy-pilot-private-path')


def verify_inputs(root, setup):
    names = {'version', 'source_sha256', 'snapshot_sha256', 'mysql_uuid', 'mysql_datadir',
             'owner_token', 'database', 'monitor', 'seeder'}
    fixture.need(set(setup) == names and setup['version'] == 1, 'copy-pilot-input-fields')
    for name in ('source_sha256', 'snapshot_sha256', 'owner_token'):
        fixture.need(re.fullmatch('[a-f0-9]{64}', setup[name]) is not None, 'copy-pilot-digest')
    fixture.need(re.fullmatch('[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}', setup['mysql_uuid']) is not None,
                 'copy-pilot-uuid')
    fixture.need(setup['mysql_datadir'] == str(root / 'mysql-data') + '/', 'copy-pilot-mysql-datadir')
    for role, user in (('database', 'repro_http_app'), ('monitor', 'repro_http_monitor'), ('seeder', 'repro_http_seed')):
        credential = setup[role]
        fixture.need(set(credential) == {'username', 'password'} and credential['username'] == user
                     and re.fullmatch('[a-f0-9]{64}', credential['password']) is not None, 'copy-pilot-fresh-account')

    workspace = root / 'workspace'
    fixture.transport.isolation.validate_source(workspace)
    fixture.need(not os.access(workspace, os.W_OK) and not os.access(fixture.TOOLS, os.W_OK), 'copy-pilot-readonly-code')
    for path in ('/var/www', '/var/lib/mysql', '/run/mysqld', '/run/php', '/run/redis', '/run/systemd/private'):
        fixture.need(not os.access(path, os.R_OK), 'copy-pilot-unrelated-path-accessible')
    fixture.need(fixture.transport.isolation.source_digest(workspace) == setup['source_sha256'], 'copy-pilot-source-changed')
    for name in ('runs', 'evidence', 'database'):
        owned(root / name, directory=True)
    database = root / 'database/database.sqlite'
    owned(database)
    fixture.need(not any(os.path.lexists(str(database) + suffix) for suffix in ('-wal', '-shm', '-journal'))
                 and fixture.sha(database) == setup['snapshot_sha256'], 'copy-pilot-sqlite-baseline-changed')
    sock = root / 'db/mysql.sock'
    socket_info = sock.lstat()
    fixture.need(sock.resolve() == sock and stat.S_ISSOCK(socket_info.st_mode)
                 and socket_info.st_uid == os.geteuid(), 'copy-pilot-socket-identity')


def prepare_sqlite(path, token):
    with sqlite3.connect(path.as_uri() + '?mode=rw', uri=True, timeout=5) as db:
        count = db.execute("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name!='sqlite_sequence'").fetchone()[0]
        fixture.need(count == 291, 'copy-pilot-sqlite-table-count')
        fixture.need(db.execute('PRAGMA journal_mode=WAL').fetchone()[0] == 'wal', 'copy-pilot-sqlite-wal')
        db.execute('BEGIN IMMEDIATE')
        db.execute('CREATE TABLE _repro_http_fixture_owner(token VARCHAR(64) NOT NULL)')
        db.execute('INSERT INTO _repro_http_fixture_owner(token) VALUES(?)', [token])


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, required=True)
    parser.add_argument('--release', required=True)
    parser.add_argument('--baseline-net', required=True)
    parser.add_argument('--calibration-sha256')
    parser.add_argument('--media-fixture', action='store_true')
    args = parser.parse_args()
    os.umask(0o077)
    root = args.root
    fixture.need(re.fullmatch('/run/repro-mysql-http-[a-f0-9]{12}', str(root)) is not None and root.resolve() == root
                 and re.fullmatch('[a-f0-9]{40}', args.release) is not None, 'copy-pilot-run-identity')
    fixture.transport.isolation.namespace_check(args.baseline_net)
    fixture.transport.isolation.supervised_status_check()
    raw = sys.stdin.buffer.read(65537)
    fixture.need(len(raw) <= 65536, 'copy-pilot-input-budget')
    setup = json.loads(raw)
    verify_inputs(root, setup)
    run_scope, profile_routes, phases, max_in_flight = workload(root, args.calibration_sha256, args.media_fixture)
    env = fixture.environment(args.baseline_net, root)
    actors = fixture.fixture_actors(args.media_fixture)
    actor_numbers = [number for number, _, _ in actors.values()]
    if args.media_fixture:
        actor_numbers.append(5)
    tokens = {str(fixture.COPY_ACTOR_BASE + number): 'repro-fixture-' + os.urandom(24).hex()
              for number in actor_numbers}
    preexisting = 'repro-fixture-' + os.urandom(24).hex()
    app_key = 'base64:' + base64.b64encode(os.urandom(32)).decode()
    password = subprocess.check_output(['/usr/bin/php8.3', '-r', "echo password_hash('unused-synthetic-password', PASSWORD_BCRYPT);"],
                                       env=env, timeout=10).decode()
    offer_time = int(time.time()) - 900
    clock = datetime.fromtimestamp(offer_time, timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
    runs = root / 'runs'
    evidence = root / 'evidence'

    def application(path, engine):
        return {'scope': 'copied-laravel-http-v1', 'runtime': str(path), 'sqlite': str(root / 'database/database.sqlite'),
                'engine': engine, 'database': setup['database'], 'monitor': setup['monitor'],
                'uuid': setup['mysql_uuid'], 'mysql_datadir': setup['mysql_datadir'], 'owner_token': setup['owner_token'],
                'tokens': tokens, 'preexisting_token': preexisting, 'password_hash': password, 'app_key': app_key,
                'sqlite_source_sha256': setup['snapshot_sha256'], 'offer_time': offer_time,
                'media_fixture': args.media_fixture}

    mysql_stage = runs / 'mysql-seed'
    fixture.runtime(mysql_stage)
    mysql_app = application(mysql_stage, 'mysql')
    fixture.php('warning-probe', mysql_app, env)
    prepare_sqlite(root / 'database/database.sqlite', setup['owner_token'])
    digests = {}
    for engine in ('sqlite', 'mysql'):
        scope = application(mysql_stage, engine)
        if engine == 'mysql':
            scope['database'] = setup['seeder']
        digests[engine] = fixture.php('seed', scope, env)['voice_graph_sha256']
    print(json.dumps({'phase': 'copied-data-actors-seeded', 'result': 'passed'}), flush=True)
    manifest = {'version': 1, 'seed_receipt_sha256': fixture.contract.digest(
        {'snapshot': setup['snapshot_sha256'], 'voice_graph': digests, 'logical_time_utc': clock,
         'actor_base': fixture.COPY_ACTOR_BASE}), 'routes': {
        label: {'role': label, 'fixture': label, 'method': 'GET',
                'path': route.replace('{shoot}', str(fixture.COPY_ACTOR_BASE + 1)), 'query': {}}
        for label, (_, route, _) in actors.items()}}
    response = {'version': 1, 'routes': {label: {'kind': 'exact-json-numeric-lexemes-v1'} for label in actors}}
    profile = {'version': 1, 'scope': 'isolated-http-read-workload', 'release_sha': args.release,
               'app_source_sha256': setup['source_sha256'], 'source_snapshot_sha256': setup['snapshot_sha256'],
               'host_profile_sha256': fixture.contract.digest({'scope': run_scope, 'fpm_children': 2,
                   'fpm_memory_mib': 192, 'opcache': False, 'sandbox': 'approved-systemd-namespace'}),
               'fixture_manifest_sha256': fixture.contract.digest(manifest), 'response_contract_sha256': fixture.contract.digest(response),
               'mysql_server_uuid': setup['mysql_uuid'], 'logical_time_utc': clock, 'max_in_flight': max_in_flight,
               'request_timeout_ms': 20000, 'routes': profile_routes, 'phases': phases}
    schedule = fixture.contract.plan(profile)
    for name, data in (('profile.json', profile), ('schedule.json', schedule)):
        fixture.private_json(runs / name, data)
        fixture.private_json(evidence / name, data)
    print(json.dumps({'phase': 'copied-data-schedule', 'result': 'ready',
                      'requests_per_round': len(schedule['operations']),
                      'scheduled_seconds_per_round': sum(item['duration_ms'] for item in phases) // 1000}), flush=True)
    fragments = {'sqlite': [], 'mysql': []}
    checks = []
    for run, order in enumerate(fixture.contract.ORDERS, 1):
        for engine in order:
            path = runs / f'round-{run}-{engine}'
            fixture.runtime(path)
            print(json.dumps({'phase': 'copied-data-http', 'engine': engine, 'round': run,
                              'result': 'started'}), flush=True)
            client = {'version': 1, 'profile_sha256': schedule['profile_sha256'], 'schedule_sha256': schedule['schedule_sha256'],
                      'engine': engine, 'round': run, 'database_identity': setup['snapshot_sha256'] if engine == 'sqlite' else setup['mysql_uuid'],
                      'server_kind': 'reviewed-http-instrumentation', 'run_token': os.urandom(32).hex(), 'socket': {},
                      'fixture_manifest': manifest, 'response_contract': response,
                      'synthetic_authorization': {label: 'Bearer ' + tokens[str(fixture.COPY_ACTOR_BASE + number)]
                                                  for label, (number, _, _) in actors.items()}}
            fragment, verified = fixture.run_round(profile, schedule, client, application(path, engine), env, path, digests[engine])
            fragments[engine].append(fragment)
            fixture.private_json(evidence / f'{engine}-round-{run}.json', fragment)
            checks.append({'engine': engine, 'round': run, **verified})
            print(json.dumps({'phase': 'copied-data-http', 'engine': engine, 'round': run,
                              'requests': len(schedule['operations']), 'result': 'passed'}), flush=True)
    receipts = {engine: fixture.transport.assemble(profile, *runs) for engine, runs in fragments.items()}
    for engine, result in receipts.items():
        fixture.private_json(evidence / (engine + '-receipt.json'), result)
    comparison = fixture.contract.compare(profile, receipts['sqlite'], receipts['mysql'])
    result = {'result': comparison['result'], 'scope': run_scope, 'release_sha': args.release,
              'calibration_sha256': args.calibration_sha256, 'comparison': comparison, 'checks': checks, 'copied_customer_data_tested': True,
              'production_capacity_tested': False, 'production_readiness': False}
    fixture.private_json(evidence / 'result.json', result)
    fixture.need(comparison['result'] == 'comparable', 'copied-data-response-parity')
    print(json.dumps({'phase': 'copied-data-http', 'result': 'passed', 'report_sha256': fixture.contract.digest(result),
                      'production_readiness': False}), flush=True)


if __name__ == '__main__':
    try:
        main()
    except (Exception, KeyboardInterrupt) as error:
        print(json.dumps({'result': 'refused', 'scope': 'native-copied-two-route-fpm',
                          'reason': str(error) if isinstance(error, fixture.contract.Refusal) else type(error).__name__}), flush=True)
        sys.exit(1)
