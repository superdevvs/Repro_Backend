#!/usr/bin/env python3
"""Administrator-only reversible install; never changes application feature flags."""
import argparse
import datetime
import fcntl
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys

SOURCE = Path(__file__).resolve().parent
BACKUPS = Path('/var/backups/repro-media-transfers')
FRONTEND = Path('/etc/nginx/sites-available/frontend.conf')
BACKEND = Path('/etc/nginx/sites-available/laravel.conf')
HTTP = Path('/etc/nginx/conf.d/repro-media-transfers.conf')
LOCATIONS = Path('/etc/nginx/snippets/repro-media-transfers.conf')
ROTATE = Path('/etc/logrotate.d/repro-media-transfers')
SERVICE = Path('/etc/systemd/system/repro-media-archives.service')
ALLOWED = {FRONTEND, BACKEND, HTTP, LOCATIONS, ROTATE, SERVICE}
MEDIA_ROOTS = {'private': '/var/www/backend/storage/app/private',
               'originals': '/mnt/16tb/repro/media-originals',
               'legacy': '/var/www/backend/storage/app/public'}
INCLUDE = '    include /etc/nginx/snippets/repro-media-transfers.conf;'


def run(*args):
    result = subprocess.run(args, text=True, capture_output=True)
    if result.returncode:
        raise RuntimeError(f'{args[0]} failed: {result.stderr.strip() or result.stdout.strip()}')
    return result.stdout


def worker_state():
    return {key: subprocess.run(['systemctl', verb, '--quiet', SERVICE.name], check=False, capture_output=True).returncode == 0
            for key, verb in [('enabled', 'is-enabled'), ('active', 'is-active')]}


def restore_worker_state(state):
    run('systemctl', 'daemon-reload')
    if SERVICE.exists():
        run('systemctl', 'enable' if state['enabled'] else 'disable', SERVICE.name)
        run('systemctl', 'restart' if state['active'] else 'stop', SERVICE.name)


def application_state():
    # Read only explicitly selected nonsensitive values from the same cached config used by FPM.
    code = """chdir('/var/www/backend'); require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
    echo json_encode(['private'=>config('filesystems.disks.'.config('media.local_disk').'.root'),
    'originals'=>config('filesystems.disks.'.config('media.originals_disk').'.root'),
    'legacy'=>config('filesystems.disks.'.config('media.legacy_public_disk').'.root'),
    'offload'=>(bool)config('media.download_offload'), 'archives'=>(bool)config('media.archive_dedicated_queue'),
    'canaries'=>config('media.performance_shoot_ids', []),
    'compression'=>(bool)config('media.archive_store_compressed'), 'prewarm'=>(bool)config('media.archive_prewarm'),
    'queue_driver'=>config('queue.connections.database.driver'),
    'retry_after'=>config('queue.connections.database.retry_after'),
    'archive_jobs'=>Illuminate\\Support\\Facades\\DB::table('jobs')->where('queue','media-archives')->count()]);"""
    return json.loads(run('runuser', '-u', 'www-data', '--', '/usr/bin/php', '-r', code))


def insert_include(source, anchor):
    if INCLUDE.strip() in source:
        if source.count(INCLUDE.strip()) != 1:
            raise RuntimeError('Multiple transfer includes; inspect the site manually.')
        return source
    if source.count(anchor) != 1:
        raise RuntimeError(f'Expected one site root anchor: {anchor}')
    return source.replace(anchor, anchor + '\n' + INCLUDE)


def backup(paths, phase):
    BACKUPS.mkdir(mode=0o700, parents=True, exist_ok=True)
    destination = BACKUPS / datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    destination.mkdir(mode=0o700)
    entries = []
    for index, path in enumerate(paths):
        if path.is_symlink():
            raise RuntimeError(f'Refusing to replace symlink: {path}')
        entry = {'path': str(path), 'copy': str(index), 'existed': path.exists()}
        if path.exists():
            shutil.copy2(path, destination / str(index))
        entries.append(entry)
    manifest = {'phase': phase, 'files': entries}
    if phase == 'archives':
        manifest['worker_state'] = worker_state()
    (destination / 'manifest.json').write_text(json.dumps(manifest, indent=2))
    return destination, manifest


def restore_files(directory, manifest):
    for entry in manifest['files']:
        path = Path(entry['path'])
        if path not in ALLOWED or path.is_symlink():
            raise RuntimeError('Invalid restoration target.')
        saved = directory / entry['copy']
        if saved.parent != directory:
            raise RuntimeError('Invalid backup entry.')
        if entry['existed']:
            shutil.copy2(saved, path)
        else:
            path.unlink(missing_ok=True)


def install(phase):
    state = application_state()
    if phase == 'downloads':
        for key, value in MEDIA_ROOTS.items():
            if state[key] != value or not Path(value).is_dir() or Path(value).is_symlink():
                raise RuntimeError(f'Configured {key} root differs from reviewed Nginx alias, or is unavailable.')
        if '--with-threads' not in subprocess.run(['nginx', '-V'], text=True, capture_output=True, check=True).stderr:
            raise RuntimeError('Nginx must support threaded file I/O.')
        replacements = {
            FRONTEND: insert_include(FRONTEND.read_text(), '    root /var/www/frontend/dist;'),
            BACKEND: insert_include(BACKEND.read_text(), '    root /var/www/backend/public;'),
            HTTP: (SOURCE / 'media-transfers-http.nginx.conf').read_text(),
            LOCATIONS: (SOURCE / 'media-transfers-locations.nginx.conf').read_text(),
            ROTATE: '/var/log/nginx/repro-transfers.jsonl {\n    daily\n    rotate 7\n    missingok\n    notifempty\n    compress\n    delaycompress\n    create 0640 www-data adm\n    sharedscripts\n    postrotate\n        /usr/sbin/nginx -s reopen\n    endscript\n}\n',
        }
    else:
        if state['queue_driver'] != 'database' or int(state['retry_after']) <= 600:
            raise RuntimeError('Database queue retry_after must exceed the 600-second worker timeout.')
        replacements = {SERVICE: (SOURCE / 'repro-media-archives.service').read_text()}
    destination, manifest = backup(list(replacements), phase)
    print(f'Restoration backup: {destination}', flush=True)
    try:
        for path, content in replacements.items():
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(content)
            path.chmod(0o644)
        if phase == 'downloads':
            run('nginx', '-t')
            run('systemctl', 'reload', 'nginx')
        else:
            run('systemctl', 'daemon-reload')
            run('systemctl', 'enable', '--now', SERVICE.name)
            run('systemctl', 'is-active', '--quiet', SERVICE.name)
    except Exception:
        if phase == 'archives':
            run('systemctl', 'disable', '--now', SERVICE.name)
        restore_files(destination, manifest)
        if phase == 'downloads':
            run('nginx', '-t')
            run('systemctl', 'reload', 'nginx')
        else:
            restore_worker_state(manifest['worker_state'])
        raise
    print(f'{phase} administrator configuration installed. Application feature flags were not changed.')


def restore(directory):
    directory = Path(directory).resolve()
    if directory.parent != BACKUPS or not directory.is_dir():
        raise RuntimeError('Restore requires a timestamped directory directly under the installer backup root.')
    manifest = json.loads((directory / 'manifest.json').read_text())
    state = application_state()
    if manifest['phase'] == 'downloads' and state['offload']:
        raise RuntimeError('Disable MEDIA_DOWNLOAD_OFFLOAD and rebuild cached config before removing internal locations.')
    if manifest['phase'] == 'archives':
        if state['archives'] or state['archive_jobs']:
            raise RuntimeError('Disable archive routing, rebuild cached config, and drain media-archives before restoring worker config.')
    # Back up the current state as well so restoration itself can be reversed.
    current, current_manifest = backup([Path(entry['path']) for entry in manifest['files']], manifest['phase'])
    try:
        if manifest['phase'] == 'archives':
            run('systemctl', 'disable', '--now', SERVICE.name)
        restore_files(directory, manifest)
        if manifest['phase'] == 'downloads':
            run('nginx', '-t')
            run('systemctl', 'reload', 'nginx')
        else:
            restore_worker_state(manifest['worker_state'])
    except Exception:
        restore_files(current, current_manifest)
        if manifest['phase'] == 'downloads':
            run('nginx', '-t')
            run('systemctl', 'reload', 'nginx')
        else:
            restore_worker_state(current_manifest['worker_state'])
        raise
    print(f'Restored {directory}. Pre-restoration backup: {current}')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument('--phase', choices=['downloads', 'archives'])
    group.add_argument('--restore', metavar='BACKUP_DIRECTORY')
    arguments = parser.parse_args()
    if os.geteuid() != 0:
        raise RuntimeError('An administrator must run this installer with sudo.')
    with open('/run/repro-media-transfers.lock', 'w') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        if arguments.restore:
            restore(arguments.restore)
        else:
            install(arguments.phase)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print(f'Install failed: {error}', file=sys.stderr)
        sys.exit(1)
