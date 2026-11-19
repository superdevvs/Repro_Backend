#!/usr/bin/env python3
"""Sender TCP settings for long-distance downloads. Preview by default; --apply and --restore require root.

The download host sends multi-GB archives over paths with a 200+ ms round trip.
CUBIC halves its window on every lost packet, which holds such a connection to
1-2 MB/s regardless of link capacity. BBR paces from measured bandwidth instead,
and a larger send buffer lets one connection hold enough data in flight.
Only new connections are affected. Nginx and PHP are not reloaded.
"""
import argparse
import os
from pathlib import Path
import subprocess

SYSCTL = Path('/etc/sysctl.d/60-repro-download-tcp.conf')
MODULE = Path('/etc/modules-load.d/repro-download-tcp.conf')
BACKUP = Path('/var/backups/repro-download-tcp/previous.sysctl')
MARKER = '# Managed by install-download-tcp.py\n'
SETTINGS = {
    'net.ipv4.tcp_congestion_control': 'bbr',
    # min and default are the kernel's; only the autotuning ceiling is raised (4 MiB -> 32 MiB).
    'net.ipv4.tcp_wmem': '4096 16384 33554432',
}
# Ubuntu 24.04's values on this host before the change (2026-11-18). Used whenever a
# setting already equals the target, e.g. after a manual `sysctl -w`, because that
# value is not a state worth restoring.
KERNEL_DEFAULTS = {
    'net.ipv4.tcp_congestion_control': 'cubic',
    'net.ipv4.tcp_wmem': '4096 16384 4194304',
}


def render(settings):
    return MARKER + ''.join(f'{key} = {value}\n' for key, value in settings.items())


def parse(text):
    """Read `key = value` lines; whitespace inside a value is normalised."""
    values = {}
    for line in text.splitlines():
        line = line.strip()
        if line and not line.startswith('#') and '=' in line:
            key, value = line.split('=', 1)
            values[key.strip()] = ' '.join(value.split())
    return values


def current(key):
    return ' '.join(Path('/proc/sys', *key.split('.')).read_text().split())


def restorable(values):
    """Previous values to return to: a value equal to the target means "not recorded"."""
    return {key: KERNEL_DEFAULTS[key] if values.get(key, SETTINGS[key]) == SETTINGS[key] else values[key]
            for key in SETTINGS}


def run(*args):
    subprocess.run(args, check=True)


def set_live(settings):
    for key, value in settings.items():
        run('sysctl', '-q', '-w', f'{key}={value}')
        if current(key) != value:
            raise RuntimeError(f'{key} did not take the value {value}')


def require_root():
    if os.geteuid() != 0:
        raise SystemExit('Run with sudo. Nothing was changed.')


def preview():
    print('Current values:')
    for key in SETTINGS:
        print(f'  {key} = {current(key)}')
    print(f'\n--apply loads tcp_bbr, then writes {MODULE} (tcp_bbr) and {SYSCTL}:')
    print(render(SETTINGS), end='')
    print(f'Previous values are saved to {BACKUP}; --restore puts them back.')


def apply():
    require_root()
    for target in (SYSCTL, MODULE):
        if target.exists() and not target.read_text().startswith(MARKER):
            raise SystemExit(f'Refusing to replace unmanaged {target}')
    previous = {key: current(key) for key in SETTINGS}
    run('modprobe', 'tcp_bbr')
    if 'bbr' not in current('net.ipv4.tcp_available_congestion_control').split():
        raise SystemExit('This kernel does not provide BBR. Nothing was changed.')
    # Keep the first saved state: a second --apply must not record BBR as "previous".
    if not BACKUP.exists():
        BACKUP.parent.mkdir(mode=0o755, parents=True, exist_ok=True)
        BACKUP.write_text(render(restorable(previous)))
    try:
        set_live(SETTINGS)
        MODULE.write_text(MARKER + 'tcp_bbr\n')
        SYSCTL.write_text(render(SETTINGS))
    except BaseException:
        set_live(previous)
        for target in (SYSCTL, MODULE):
            target.unlink(missing_ok=True)
        raise
    print('Applied to new connections:')
    for key in SETTINGS:
        print(f'  {key}: {previous[key]} -> {current(key)}')


def restore():
    require_root()
    if not BACKUP.exists():
        raise SystemExit(f'No saved state at {BACKUP}. Nothing was changed.')
    # A backup saved by an earlier release may hold the target itself.
    previous = restorable(parse(BACKUP.read_text()))
    set_live(previous)
    for target in (SYSCTL, MODULE):
        if target.exists() and target.read_text().startswith(MARKER):
            target.unlink()
    BACKUP.unlink()
    print('Restored:')
    for key in previous:
        print(f'  {key} = {current(key)}')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    group = parser.add_mutually_exclusive_group()
    group.add_argument('--apply', action='store_true')
    group.add_argument('--restore', action='store_true')
    arguments = parser.parse_args()
    apply() if arguments.apply else restore() if arguments.restore else preview()
