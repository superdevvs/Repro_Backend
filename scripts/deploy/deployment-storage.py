#!/usr/bin/env python3
"""Bounded deployment-backup retention and fail-closed deployment space checks.

CLI paths are deliberately fixed. Tests call the filesystem functions with fixtures;
there is no environment variable or CLI switch that redirects production deletion.
"""
import argparse
from contextlib import ExitStack, contextmanager, redirect_stderr, redirect_stdout
from datetime import datetime, timedelta
import fcntl
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import time

HOME = Path('/home/maverick')
BASE = HOME / 'repro-deploy-backups'
APPS = {'backend': Path('/var/www/backend'), 'frontend': Path('/var/www/frontend')}
RESERVE = 20 * 1024 ** 3
RETENTION_DAYS = 14
MARKER = 'deployment-succeeded.json'
FILES = {'source.tar.gz', 'database.sqlite', MARKER}
STAMP = re.compile(r'^\d{8}-\d{6}$')
CRON_TAG = '# repro-deploy-backup-retention'
CRON_JOB = ('47 3 * * * /usr/bin/python3 '
            '/var/www/backend/scripts/deploy/deployment-storage.py scheduled-retention >/dev/null 2>&1 '
            '# repro-deploy-backup-retention')


def safe_directory(path):
    """Reject a redirected directory at any level, including the allowed root."""
    path = Path(path)
    if not path.is_absolute() or '..' in path.parts:
        raise RuntimeError(f'Unsafe absolute directory: {path}')
    for parent in reversed((path, *path.parents)):
        info = parent.lstat()
        if not stat.S_ISDIR(info.st_mode):
            raise RuntimeError(f'Directory is missing or redirected: {parent}')
    return path


@contextmanager
def deployment_lock(component, home=HOME, timeout=300):
    safe_directory(home)
    path = home / f'.repro-{component}-deploy.lock'
    descriptor = os.open(path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    try:
        info = os.fstat(descriptor)
        if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_uid != os.getuid():
            raise RuntimeError(f'Unsafe deployment lock: {path}')
        deadline = time.monotonic() + timeout
        while True:
            try:
                fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
                break
            except BlockingIOError:
                if time.monotonic() >= deadline:
                    raise RuntimeError(f'Another {component} deployment holds the lock; retry later.')
                time.sleep(0.25)
        yield
    finally:
        os.close(descriptor)


def inspect_backup(path, component):
    """Only complete, flat, owned backup bundles are eligible for removal."""
    if not STAMP.fullmatch(path.name):
        return None
    try:
        stamp = datetime.strptime(path.name, '%Y%m%d-%H%M%S').timestamp()
        info = path.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid != os.getuid():
            return None
        entries = list(path.iterdir())
        required = {'source.tar.gz', 'database.sqlite'} if component == 'backend' else {'source.tar.gz'}
        allowed = FILES if component == 'backend' else {'source.tar.gz', MARKER}
        if not required.issubset({p.name for p in entries}) or any(p.name not in allowed for p in entries):
            return None
        snapshot = {}
        latest = info.st_mtime
        size = 0
        for child in entries:
            data = child.lstat()
            if (not stat.S_ISREG(data.st_mode) or data.st_nlink != 1
                    or data.st_uid != os.getuid() or data.st_dev != info.st_dev or data.st_size == 0):
                return None
            snapshot[child.name] = (data.st_dev, data.st_ino, data.st_size, data.st_mtime_ns, data.st_ctime_ns)
            latest = max(latest, data.st_mtime)
            size += data.st_blocks * 512
        success = False
        if MARKER in snapshot:
            # A malformed/unknown marker preserves the entire directory.
            if (path / MARKER).stat().st_size > 4096:
                return None
            marker = json.loads((path / MARKER).read_text())
            if (not isinstance(marker, dict) or marker.get('component') != component or marker.get('backup') != path.name
                    or marker.get('status') != 'verified' or not re.fullmatch(r'[a-f0-9]{40}', marker.get('commit', ''))):
                return None
            success = True
        return {'path': path, 'stamp': stamp, 'mtime': latest, 'snapshot': snapshot,
                'directory': (info.st_dev, info.st_ino, info.st_mtime_ns, info.st_ctime_ns),
                'bytes': size, 'success': success}
    except (OSError, ValueError, TypeError):
        return None


def retention(base=BASE, apply=False, now=None):
    safe_directory(base)
    now = time.time() if now is None else now
    cutoff = now - timedelta(days=RETENTION_DAYS).total_seconds()
    removed = 0
    for component in ('backend', 'frontend'):
        directory = base / component
        if not directory.exists() and not directory.is_symlink():
            continue
        safe_directory(directory)
        bundles = []
        for path in sorted(directory.iterdir()):
            bundle = inspect_backup(path, component)
            if bundle is None:
                print(f'preserve {component}/{path.name}: unrecognized or incomplete backup')
            else:
                bundles.append(bundle)
        # Legacy backups have no success marker. Keep the newest complete one
        # as well as the newest explicitly verified rollback, even after 14 days.
        keep = set()
        if bundles:
            keep.add(max(bundles, key=lambda item: item['stamp'])['path'])
        verified = [item for item in bundles if item['success']]
        if verified:
            keep.add(max(verified, key=lambda item: item['stamp'])['path'])
        for bundle in bundles:
            path = bundle['path']
            if path in keep or bundle['stamp'] >= cutoff or bundle['mtime'] >= cutoff:
                continue
            # Revalidate the whole bundle immediately before unlinking. No
            # recursion, glob deletion, symlink following or arbitrary filenames.
            safe_directory(directory)
            if inspect_backup(path, component) != bundle:
                raise RuntimeError(f'Backup changed during retention; preserved: {path}')
            print(f'{"remove" if apply else "would-remove"} {component}/{path.name} bytes={bundle["bytes"]}')
            if apply:
                fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
                try:
                    opened = os.fstat(fd)
                    if (opened.st_dev, opened.st_ino) != bundle['directory'][:2]:
                        raise RuntimeError(f'Backup directory changed: {path}')
                    for name in bundle['snapshot']:
                        os.unlink(name, dir_fd=fd)
                finally:
                    os.close(fd)
                path.rmdir()
                removed += bundle['bytes']
    print(f'retention={"applied" if apply else "dry-run"} removed_bytes={removed} keep_days={RETENTION_DAYS}')


def tree_bytes(path, exclude=()):
    """Conservative apparent bytes, never traverse links or mounted children."""
    safe_directory(path)
    device = path.stat().st_dev
    total = 0
    for root, directories, files in os.walk(path, followlinks=False):
        directories[:] = [name for name in directories if name not in exclude
                           and not name.startswith('quarantine')
                           and not (Path(root) / name).is_symlink()]
        for name in directories:
            if (Path(root) / name).stat().st_dev != device:
                raise RuntimeError(f'Unexpected mounted source directory: {Path(root) / name}')
        for name in files:
            child = Path(root) / name
            if name in exclude or name.startswith('.env') or name.startswith('database.sqlite'):
                continue
            data = child.lstat()
            if stat.S_ISREG(data.st_mode):
                total += data.st_size
            elif not stat.S_ISLNK(data.st_mode):
                raise RuntimeError(f'Unexpected source file type: {child}')
    return total


def assert_capacity(requirements, reserve=RESERVE):
    devices = {}
    for path, size in requirements:
        safe_directory(path)
        info = path.stat()
        group = devices.setdefault(info.st_dev, {'path': path, 'bytes': 0})
        group['bytes'] += size
    errors = []
    for group in devices.values():
        fs = os.statvfs(group['path'])
        free = fs.f_bavail * fs.f_frsize
        needed = group['bytes'] + reserve
        print(f'deploy-space path={group["path"]} free_bytes={free} needed_bytes={needed} reserve_bytes={reserve}')
        if free < needed:
            errors.append(f'{group["path"]}: need {needed / 1024**3:.2f} GiB, free {free / 1024**3:.2f} GiB '
                          f'(includes {reserve / 1024**3:.0f} GiB reserve)')
    if errors:
        raise RuntimeError('Insufficient deployment space; no transfer/maintenance should start. ' + '; '.join(errors))


def preflight(component, archive_bytes, release_bytes):
    app = APPS[component]
    source = tree_bytes(app, {'.git', 'vendor', 'node_modules', 'storage', 'output', 'releases', 'dist'})
    # Tar headers/compression can exceed tiny source inputs; reserve 5% + 1MiB.
    source_backup = int(source * 1.05) + 1024 ** 2
    requirements = [(Path('/tmp'), archive_bytes + release_bytes + source), (app, release_bytes)]
    if component == 'backend':
        database = app / 'database' / 'database.sqlite'
        data = database.lstat()
        if not stat.S_ISREG(data.st_mode) or data.st_nlink != 1:
            raise RuntimeError('SQLite source must be a regular, non-hardlinked file.')
        db_bytes = data.st_size
        wal = database.with_name(database.name + '-wal')
        if wal.exists():
            wal_info = wal.lstat()
            if not stat.S_ISREG(wal_info.st_mode) or wal_info.st_nlink != 1:
                raise RuntimeError('SQLite WAL is redirected or hardlinked.')
            db_bytes += wal_info.st_size
        dependencies = tree_bytes(app / 'vendor')
        requirements += [(BASE / component, source_backup + db_bytes), (app / 'database', db_bytes), (app / 'vendor', 2 * dependencies)]
    else:
        installed_guard = APPS['backend'] / 'scripts/deploy/deployment-storage.py'
        safe_directory(installed_guard.parent)
        installed = installed_guard.lstat()
        if not stat.S_ISREG(installed.st_mode) or installed.st_nlink != 1 or installed.st_uid != os.getuid():
            raise RuntimeError('Deploy the backend storage guard before a frontend release.')
        dependencies = tree_bytes(app / 'node_modules') if (app / 'node_modules').exists() else 0
        # Existing release assets may be hardlinked. Apparent-size counting
        # deliberately overestimates space for the next build and asset copies.
        releases = tree_bytes(app / 'releases') if (app / 'releases').exists() else 0
        release_destination = app / 'releases' if (app / 'releases').exists() else app
        requirements += [(Path('/tmp'), release_bytes + max(2 * dependencies, 2 * 1024 ** 3) + releases), (release_destination, releases)]
    assert_capacity(requirements)


def updated_crontab(current):
    lines = current.splitlines()
    matches = [line for line in lines if CRON_TAG in line]
    if any(line != CRON_JOB for line in matches) or len(matches) > 1:
        raise RuntimeError('Unexpected existing retention cron entry; preserved for manual review.')
    if matches:
        return current
    return current.rstrip('\n') + '\n\n' + CRON_JOB + '\n'


def retention_state():
    state = HOME / '.repro-deploy-retention'
    if not state.exists() and not state.is_symlink():
        state.mkdir(mode=0o700)
    safe_directory(state)
    info = state.stat()
    if info.st_uid != os.getuid() or stat.S_IMODE(info.st_mode) != 0o700:
        raise RuntimeError('Retention state must be owned by maverick with mode 0700.')
    return state


def open_last_run_log(state, truncate=False):
    fd = os.open(state / 'last-run.log', os.O_WRONLY | os.O_CREAT | os.O_NOFOLLOW | os.O_NONBLOCK, 0o600)
    info = os.fstat(fd)
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_uid != os.getuid():
        os.close(fd)
        raise RuntimeError('Unsafe retention last-run log.')
    if truncate:
        os.ftruncate(fd, 0)
    return os.fdopen(fd, 'w')


def apply_retention():
    with ExitStack() as locks:
        for component in ('backend', 'frontend'):
            locks.enter_context(deployment_lock(component))
        retention(apply=True)


def scheduled_retention():
    # One overwritten log per run, no cron mail and no ever-growing output file.
    state = retention_state()
    with open_last_run_log(state, truncate=True) as stream, redirect_stdout(stream), redirect_stderr(stream):
        print(f'started_at={datetime.now().astimezone().isoformat()}', flush=True)
        try:
            apply_retention()
        except Exception as error:
            print(f'retention_failed: {error}', flush=True)
            raise
        print(f'finished_at={datetime.now().astimezone().isoformat()}', flush=True)


def install_cron():
    helper = APPS['backend'] / 'scripts/deploy/deployment-storage.py'
    safe_directory(helper.parent)
    data = helper.lstat()
    if not stat.S_ISREG(data.st_mode) or data.st_nlink != 1 or data.st_uid != os.getuid():
        raise RuntimeError('Installed retention helper must be owned by the deployment user.')
    with deployment_lock('cron-retention'):
        result = subprocess.run(['crontab', '-l'], capture_output=True, text=True, env={**os.environ, 'LC_ALL': 'C'})
        if result.returncode and not (result.returncode == 1 and result.stderr.strip() == 'no crontab for maverick'):
            raise RuntimeError('Unable to read deployment-user crontab; no changes made.')
        current = result.stdout
        updated = updated_crontab(current)
        state = retention_state()
        with open_last_run_log(state):
            pass
        if updated != current:
            # Save a bounded exact rollback once. Refuse unsafe or unknown state.
            backup = state / 'crontab-before-retention.txt'
            if backup.exists() or backup.is_symlink():
                prior = backup.lstat()
                if not stat.S_ISREG(prior.st_mode) or prior.st_nlink != 1 or prior.st_uid != os.getuid():
                    raise RuntimeError('Unsafe crontab rollback file; no changes made.')
            else:
                fd = os.open(backup, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
                with os.fdopen(fd, 'w') as stream:
                    stream.write(current)
            # Detect another cooperating/unrelated editor before replacing it.
            again = subprocess.run(['crontab', '-l'], capture_output=True, text=True, env={**os.environ, 'LC_ALL': 'C'})
            if (again.returncode, again.stdout, again.stderr) != (result.returncode, result.stdout, result.stderr):
                raise RuntimeError('Crontab changed during installation; no changes made.')
            subprocess.run(['crontab', '-'], input=updated, text=True, check=True)
            confirmed = subprocess.run(['crontab', '-l'], capture_output=True, text=True, check=True)
            if confirmed.stdout != updated:
                raise RuntimeError('Unable to confirm installed retention schedule.')
        print('deployment_backup_retention_cron=installed daily=03:47-server-local keep_days=14')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest='command', required=True)
    cleanup = sub.add_parser('retention')
    cleanup.add_argument('--apply', action='store_true', help='Default is a read-only inventory.')
    space = sub.add_parser('preflight')
    space.add_argument('component', choices=APPS)
    space.add_argument('archive_bytes', type=int)
    space.add_argument('release_bytes', type=int)
    sub.add_parser('install-cron')
    sub.add_parser('scheduled-retention')
    args = parser.parse_args()
    if Path.home() != HOME or os.getuid() != HOME.stat().st_uid:
        raise RuntimeError('Run deployment storage safeguards as maverick.')
    if args.command == 'retention':
        if args.apply:
            apply_retention()
        else:
            retention()
    elif args.command == 'preflight':
        if args.archive_bytes <= 0 or args.release_bytes <= 0:
            raise RuntimeError('Release sizes must be positive.')
        preflight(args.component, args.archive_bytes, args.release_bytes)
    elif args.command == 'install-cron':
        install_cron()
    else:
        scheduled_retention()


if __name__ == '__main__':
    try:
        main()
    except (OSError, RuntimeError, subprocess.SubprocessError) as error:
        print(f'deployment-storage: {error}', file=sys.stderr)
        sys.exit(1)
