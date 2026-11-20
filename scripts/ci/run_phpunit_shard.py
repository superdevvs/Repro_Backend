#!/usr/bin/env python3
"""Run one shard of the PHPUnit suite with several PHPUnit processes.

The deploy gate used to run ~9,400 tests one after another in a single process
(about 37 minutes). This splits the exact file list PHPUnit itself discovers
(`phpunit --list-test-files`) across CI jobs ("shards") and, inside each job,
across concurrent PHPUnit processes ("workers"), balanced by the recorded
per-file durations in scripts/ci/phpunit-timings.json.

Nothing is weakened: every worker runs with the same fail-on flags as
`composer test`, every discovered test file is assigned to exactly one worker
of one shard (checked before anything runs), and any failing worker fails the
job. Each worker gets its own TEST_TOKEN, which Laravel uses to keep fake
storage disks apart; the database is per-process already (sqlite :memory:).

Usage:
  run_phpunit_shard.py --shard-index 1 --shard-total 3 --workers 4
  run_phpunit_shard.py --shard-total 3 --workers 4 --plan      # print the split
  run_phpunit_shard.py --update-timings "junit/*.xml"   # refresh from CI JUnit artifacts
"""

from __future__ import annotations

import argparse
import glob
import json
import os
import statistics
import subprocess
import sys
import time
import xml.etree.ElementTree as ET
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TIMINGS = ROOT / 'scripts' / 'ci' / 'phpunit-timings.json'
OUTPUT = ROOT / 'build' / 'phpunit'
PHPUNIT = ['php', '-d', 'memory_limit=2G', 'vendor/bin/phpunit']
STRICT_FLAGS = [
    '--fail-on-warning',
    '--fail-on-risky',
    '--fail-on-skipped',
    '--fail-on-phpunit-deprecation',
]


def discover_test_files() -> list[str]:
    """The files PHPUnit would run for the configured test suites."""
    result = subprocess.run(
        PHPUNIT + ['--list-test-files'],
        cwd=ROOT, capture_output=True, text=True, check=False,
    )
    if result.returncode != 0:
        sys.stderr.write(result.stdout + result.stderr)
        raise SystemExit('phpunit --list-test-files failed')
    files = []
    for line in result.stdout.splitlines():
        line = line.strip()
        if not line.startswith('- '):
            continue
        path = Path(line[2:].strip())
        files.append(path.resolve().relative_to(ROOT).as_posix())
    if not files:
        raise SystemExit('phpunit --list-test-files returned no test files')
    return sorted(set(files))


def load_timings() -> dict[str, float]:
    if not TIMINGS.is_file():
        return {}
    return {path: float(secs) for path, secs in json.loads(TIMINGS.read_text()).items()}


def plan(files: list[str], shard_total: int, workers: int) -> list[list[list[str]]]:
    """Longest-processing-time-first packing into shard_total x workers bins."""
    timings = load_timings()
    known = [timings[f] for f in files if f in timings]
    default = statistics.median(known) if known else 1.0
    weighted = sorted(((timings.get(f, default), f) for f in files), key=lambda item: (-item[0], item[1]))

    bins = [[0.0, index, []] for index in range(shard_total * workers)]
    for weight, path in weighted:
        target = min(bins, key=lambda b: (b[0], b[1]))
        target[0] += weight
        target[2].append(path)

    shards = [[sorted(bins[s * workers + w][2]) for w in range(workers)] for s in range(shard_total)]

    assigned = [path for shard in shards for worker in shard for path in worker]
    if sorted(assigned) != files or len(assigned) != len(set(assigned)):
        raise SystemExit('internal error: test files were not assigned exactly once')
    return shards


def estimate(paths: list[str], timings: dict[str, float], default: float) -> float:
    return sum(timings.get(p, default) for p in paths)


def print_plan(shards: list[list[list[str]]]) -> None:
    timings = load_timings()
    default = statistics.median(timings.values()) if timings else 1.0
    for s, shard in enumerate(shards, start=1):
        for w, paths in enumerate(shard, start=1):
            print(f'shard {s} worker {w}: {len(paths):4d} files, ~{estimate(paths, timings, default):6.0f}s recorded')


def run_shard(shards: list[list[list[str]]], shard_index: int) -> int:
    OUTPUT.mkdir(parents=True, exist_ok=True)
    workers = shards[shard_index - 1]
    processes = []
    started = time.monotonic()
    for w, paths in enumerate(workers, start=1):
        if not paths:
            continue
        token = str((shard_index - 1) * len(workers) + w)
        label = f'shard-{shard_index}-worker-{w}'
        log_path = OUTPUT / f'{label}.log'
        junit_path = OUTPUT / f'{label}.xml'
        list_path = OUTPUT / f'{label}.files'
        list_path.write_text('\n'.join(paths) + '\n')
        env = dict(os.environ, TEST_TOKEN=token)
        log = open(log_path, 'w', encoding='utf-8')
        process = subprocess.Popen(
            PHPUNIT + STRICT_FLAGS + ['--log-junit', str(junit_path)] + paths,
            cwd=ROOT, env=env, stdout=log, stderr=subprocess.STDOUT,
        )
        processes.append((label, process, log, log_path, junit_path, len(paths)))

    finished_after: dict[str, float] = {}
    while len(finished_after) < len(processes):
        for label, process, *_ in processes:
            if label not in finished_after and process.poll() is not None:
                finished_after[label] = time.monotonic() - started
        time.sleep(1)

    failed = []
    summary = []
    for label, process, log, log_path, junit_path, file_count in processes:
        code = process.wait()
        log.close()
        elapsed = finished_after[label]
        tests = junit_count(junit_path)
        summary.append(f'{label}: exit {code}, {file_count} files, {tests} tests, finished after {elapsed:.0f}s')
        print(f'::group::{label} (exit {code})', flush=True)
        sys.stdout.write(log_path.read_text(encoding='utf-8', errors='replace'))
        print('::endgroup::', flush=True)
        if code != 0:
            failed.append(label)
            # Repeat the failing output outside the collapsed group.
            print(f'--- {label} failed; tail of its output ---')
            print('\n'.join(log_path.read_text(encoding='utf-8', errors='replace').splitlines()[-80:]))

    print('\n'.join(summary))
    if failed:
        print(f'Failing workers: {", ".join(failed)}', file=sys.stderr)
        return 1
    return 0


def junit_count(path: Path) -> str:
    try:
        root = ET.parse(path).getroot()
    except (OSError, ET.ParseError):
        return '?'
    suite = root if root.tag == 'testsuite' else root.find('testsuite')
    return suite.get('tests', '?') if suite is not None else '?'


def update_timings(patterns: list[str]) -> int:
    totals: dict[str, float] = {}
    for pattern in patterns:
        for file in glob.glob(pattern):
            for case in ET.parse(file).getroot().iter('testcase'):
                source = case.get('file')
                if not source:
                    continue
                try:
                    rel = Path(source).resolve().relative_to(ROOT).as_posix()
                except ValueError:
                    rel = source.split('/tests/', 1)[-1]
                    rel = f'tests/{rel}'
                totals[rel] = totals.get(rel, 0.0) + float(case.get('time', 0) or 0)
    if not totals:
        raise SystemExit('no test timings found in the given JUnit files')
    # Merge, so refreshing from one shard's files keeps the others' timings.
    merged = load_timings() | {k: round(v, 1) for k, v in totals.items()}
    TIMINGS.write_text(json.dumps(dict(sorted(merged.items())), indent=2) + '\n')
    print(f'updated {len(totals)} of {len(merged)} file timings in {TIMINGS.relative_to(ROOT)}')
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--shard-index', type=int, default=1)
    parser.add_argument('--shard-total', type=int, default=1)
    parser.add_argument('--workers', type=int, default=os.cpu_count() or 1)
    parser.add_argument('--plan', action='store_true', help='print the split and exit')
    parser.add_argument('--update-timings', nargs='+', metavar='JUNIT', help='rewrite timings from JUnit files')
    args = parser.parse_args()

    if args.update_timings:
        return update_timings(args.update_timings)
    if args.shard_total < 1 or args.workers < 1 or not 1 <= args.shard_index <= args.shard_total:
        parser.error('need 1 <= shard-index <= shard-total and workers >= 1')

    shards = plan(discover_test_files(), args.shard_total, args.workers)
    print_plan(shards)
    if args.plan:
        return 0
    return run_shard(shards, args.shard_index)


if __name__ == '__main__':
    sys.exit(main())
