"""Read-only fingerprint and final concurrency check for backend releases."""
import argparse
import hashlib
import os
from pathlib import Path
import sys

TREES = ('app', 'bootstrap', 'config', 'resources', 'routes', 'scripts', 'public',
         'database/migrations', 'database/factories', 'database/seeders')
ROOT_FILES = ('artisan', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json')
PROTECTED = ('bootstrap/cache', 'public/storage')


def fingerprint(root):
    root = Path(root)
    paths = {root / name for name in ROOT_FILES if (root / name).exists()}
    for tree in TREES:
        for directory, dirs, files in os.walk(root / tree, followlinks=False):
            parent = Path(directory)
            dirs[:] = sorted(d for d in dirs if (parent / d).relative_to(root).as_posix() not in PROTECTED)
            paths.update(parent / name for name in files)
            paths.update(parent / name for name in dirs if (parent / name).is_symlink())
    digest = hashlib.sha256()
    for path in sorted(paths):
        relative = path.relative_to(root).as_posix()
        digest.update(relative.encode() + b'\0')
        if path.is_symlink():
            digest.update(b'link:' + os.readlink(path).encode())
        else:
            with path.open('rb') as stream:
                for block in iter(lambda: stream.read(1024 * 1024), b''):
                    digest.update(block)
        digest.update(b'\0')
    return digest.hexdigest()


def assert_no_deployment_process(root):
    """Detect common non-cooperating deployment processes without printing argv."""
    root = os.path.realpath(root)
    for entry in Path('/proc').iterdir():
        if not entry.name.isdigit() or int(entry.name) == os.getpid():
            continue
        try:
            argv = (entry / 'cmdline').read_bytes().split(b'\0')
            argv = [part.decode(errors='replace') for part in argv if part]
            cwd = os.path.realpath(entry / 'cwd')
        except (OSError, PermissionError):
            continue
        if not argv:
            continue
        command = Path(argv[0]).name
        rsync = command == 'rsync' and any(root in arg for arg in argv)
        artisan = cwd == root and any(Path(arg).name == 'artisan' for arg in argv) and any(
            arg in ('down', 'migrate', 'optimize:clear', 'config:cache', 'route:cache', 'view:cache', 'queue:restart') for arg in argv)
        composer = cwd == root and any(Path(arg).name in ('composer', 'composer.phar') for arg in argv) and 'install' in argv
        if rsync or artisan or composer:
            raise RuntimeError(f'Another backend deployment process is active (PID {entry.name}); retry later.')


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('root')
    parser.add_argument('--expected')
    parser.add_argument('--check-processes', action='store_true')
    args = parser.parse_args()
    try:
        if not Path(args.root).is_dir():
            raise RuntimeError('Backend source directory is missing.')
        if args.check_processes:
            assert_no_deployment_process(args.root)
        actual = fingerprint(args.root)
        if args.expected and actual != args.expected:
            raise RuntimeError('Production source changed after preflight; reconcile the concurrent changes before deploying.')
        print(actual)
    except (OSError, RuntimeError) as error:
        print(str(error), file=sys.stderr)
        sys.exit(1)
