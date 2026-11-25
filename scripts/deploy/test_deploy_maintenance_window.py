"""Run the backend deploy's remote bash against a throwaway app with stub tools.

Checks the maintenance window: the scheduler hold is set before `down`, the
Telnyx spool is replayed (www-data group, umask 002) before and after `up`,
the hold is released at the end, and the failure trap cleans up the same way.
No real php, composer, rsync, network or server is used.
"""
import json
import os
from pathlib import Path
import secrets
import shutil
import subprocess
import sys
import tempfile
import time
import unittest

DEPLOY = Path(__file__).with_name('Deploy-App.ps1')

STUB_LOG = r'''
log() { printf '%s | down=%s hold=%s umask=%s\n' "$*" \
  "$([ -e "$FAKE_APP/storage/framework/down" ] && echo 1 || echo 0)" \
  "$([ -e "$STUB_HOLD_DIR/scheduler-hold.deploy.json" ] && echo 1 || echo 0)" \
  "$(umask)" >> "$STUB_LOG"; }
'''

STUBS = {
    'id': 'if [ "$1" = "-gn" ]; then echo www-data; else exec /usr/bin/id "$@"; fi',
    'flock': 'exit 0',
    'curl': 'exit 0',
    'python3': 'log python3 "$@"; exit 0',
    'rsync': 'log rsync; exit 0',
    'composer': 'log composer "$@"; exit "${STUB_COMPOSER_EXIT:-0}"',
    'tar': r'''
case "$1" in
  -xOf) exit 0 ;;
  -xzf) mkdir -p "$4"; printf 'x' > "$4/artisan"; printf 'x' > "$4/composer.lock"; exit 0 ;;
  -czf) printf 'x' > "$2"; exit 0 ;;
esac
exit 0
''',
    'php': r'''
log php "$@"
if [ "$1" = artisan ]; then
  case "$2" in
    down) mkdir -p "$FAKE_APP/storage/framework"; : > "$FAKE_APP/storage/framework/down"
          cp "$STUB_HOLD_DIR/scheduler-hold.deploy.json" "$STUB_DIR/hold-at-down.json" 2>/dev/null || true ;;
    scheduler:hold) [ "$3" = path ] && [ -z "${STUB_HOLD_PATH_FAILS:-}" ] || exit 1
          echo "$STUB_HOLD_DIR" ;;
    up) rm -f "$FAKE_APP/storage/framework/down" ;;
    telnyx:replay-spool) exit "${STUB_REPLAY_EXIT:-0}" ;;
  esac
fi
exit 0
''',
}


def backend_script(app: Path) -> str:
    text = DEPLOY.read_text(encoding='utf-8').replace('\r\n', '\n')
    body = text[text.index('function Deploy-Backend'):]
    start = body.index("$remoteScript = @'\n") + len("$remoteScript = @'\n")
    return body[start:body.index("\n'@", start)].replace('/var/www/backend', str(app))


@unittest.skipUnless(sys.platform.startswith('linux') and shutil.which('bash'), 'needs Linux bash')
class DeployMaintenanceWindowTest(unittest.TestCase):
    def setUp(self):
        self.root = Path(tempfile.mkdtemp(prefix='deploy-window-'))
        self.app = self.root / 'app'
        self.stubs = self.root / 'bin'
        self.commit = 'harness' + secrets.token_hex(6)
        self.archive = Path(f'/tmp/repro-backend-{self.commit}.tar.gz')
        for folder in ['storage/framework', 'storage/app', 'public', 'scripts/deploy', 'bootstrap/cache']:
            (self.app / folder).mkdir(parents=True, exist_ok=True)
        (self.app / '.env').write_text('APP_ENV=synthetic\n')
        for script in ['provision-public-storage.sh']:
            (self.app / 'scripts/deploy' / script).write_text('exit 0\n')
        self.stubs.mkdir()
        for name, body in STUBS.items():
            path = self.stubs / name
            path.write_text('#!/bin/bash\n' + STUB_LOG + body + '\n')
            path.chmod(0o755)
        self.archive.write_bytes(b'synthetic archive')

    def tearDown(self):
        shutil.rmtree(self.root, ignore_errors=True)
        shutil.rmtree(f'/tmp/repro-backend-{self.commit}', ignore_errors=True)
        self.archive.unlink(missing_ok=True)

    def deploy(self, **env):
        environment = {
            'PATH': f'{self.stubs}:/usr/bin:/bin', 'HOME': str(self.root),
            'FAKE_APP': str(self.app), 'STUB_DIR': str(self.root), 'STUB_LOG': str(self.root / 'calls.log'),
            # Where the stub app says the scheduler reads holds.
            'STUB_HOLD_DIR': str(self.app / 'storage/framework'),
            **env,
        }
        result = subprocess.run(
            ['bash', '-s', '--', str(self.archive), self.commit, '1', '0', 'fingerprint', '17', '17'],
            input=backend_script(self.app), capture_output=True, text=True, env=environment, timeout=120,
        )
        log = (self.root / 'calls.log').read_text().splitlines() if (self.root / 'calls.log').exists() else []
        return result, log

    @staticmethod
    def index(log, prefix, start=0):
        for number, line in enumerate(log[start:], start):
            if line.startswith(prefix):
                return number
        raise AssertionError(f'{prefix!r} not called after line {start}:\n' + '\n'.join(log))

    def test_hold_then_down_then_replay_then_up_then_replay_then_release(self):
        result, log = self.deploy()

        self.assertEqual(result.returncode, 0, result.stderr)
        down = self.index(log, 'php artisan down')
        self.assertEqual(log[down], 'php artisan down --retry=60 | down=0 hold=1 umask=0022')
        hold = json.loads((self.root / 'hold-at-down.json').read_text())
        self.assertEqual(hold['hold'], ['*'])
        self.assertIn(self.commit, hold['reason'])
        self.assertTrue(time.time() + 1700 < hold['expires_at'] < time.time() + 1900)

        before_up = self.index(log, 'php artisan telnyx:replay-spool', down)
        up = self.index(log, 'php artisan up', before_up)
        after_up = self.index(log, 'php artisan telnyx:replay-spool', up)
        self.assertIn('composer install', ' '.join(log[down:before_up]))
        self.assertTrue(log[before_up].endswith('| down=1 hold=1 umask=0002'), log[before_up])
        self.assertTrue(log[after_up].endswith('| down=0 hold=1 umask=0002'), log[after_up])
        self.assertFalse((self.app / 'storage/framework/scheduler-hold.deploy.json').exists())
        self.assertNotIn('--render', ''.join(line for line in log if line.startswith('php artisan down')))

    def test_failed_deploy_replays_reopens_and_releases_the_hold(self):
        result, log = self.deploy(STUB_COMPOSER_EXIT='1')

        self.assertNotEqual(result.returncode, 0)
        self.assertIn('backend_source_rollback_complete', result.stderr)
        replay = self.index(log, 'php artisan telnyx:replay-spool', self.index(log, 'php artisan down'))
        self.assertTrue(log[replay].endswith('| down=1 hold=1 umask=0002'), log[replay])
        self.index(log, 'php artisan up', replay)
        self.assertFalse((self.app / 'storage/framework/down').exists())
        self.assertFalse((self.app / 'storage/framework/scheduler-hold.deploy.json').exists())

    def test_holds_set_by_others_are_left_alone(self):
        cutover = self.app / 'storage/framework/scheduler-hold.cutover.json'
        cutover.write_text('{"hold":["*"],"reason":"operator","expires_at":4102444800}\n')

        for environment in ({}, {'STUB_COMPOSER_EXIT': '1'}):
            self.deploy(**environment)
            self.assertTrue(cutover.exists())

    def test_the_hold_goes_to_the_folder_the_scheduler_reads(self):
        # SCHEDULER_HOLD_DIRECTORY moved the app's hold folder.
        holds = self.root / 'configured-holds'
        holds.mkdir()

        result, log = self.deploy(STUB_HOLD_DIR=str(holds))

        self.assertEqual(result.returncode, 0, result.stderr)
        down = self.index(log, 'php artisan down')
        self.assertLess(self.index(log, 'php artisan scheduler:hold path'), down)
        self.assertEqual(log[down], 'php artisan down --retry=60 | down=0 hold=1 umask=0022')
        self.assertEqual(json.loads((self.root / 'hold-at-down.json').read_text())['hold'], ['*'])
        self.assertEqual(list(holds.iterdir()), [])
        self.assertFalse((self.app / 'storage/framework/scheduler-hold.deploy.json').exists())

    def test_code_without_the_hold_folder_command_uses_the_default_folder(self):
        result, log = self.deploy(STUB_HOLD_PATH_FAILS='1')

        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(log[self.index(log, 'php artisan down')], 'php artisan down --retry=60 | down=0 hold=1 umask=0022')
        self.assertNotIn('scheduler_hold=unavailable', result.stderr)
        self.assertFalse((self.app / 'storage/framework/scheduler-hold.deploy.json').exists())

    def test_hold_or_replay_problems_never_stop_a_deploy(self):
        (self.app / 'storage/framework/scheduler-hold.deploy.json.tmp').mkdir()

        result, log = self.deploy(STUB_REPLAY_EXIT='1')

        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('scheduler_hold=unavailable', result.stderr)
        self.assertEqual(result.stderr.count('telnyx_spool_replay=failed'), 2)
        self.assertIn('backend_deploy_complete:' + self.commit, result.stdout)


class DeployMaintenancePageTest(unittest.TestCase):
    def test_deploy_never_uses_a_prerendered_maintenance_page(self):
        downs = [line for line in DEPLOY.read_text(encoding='utf-8').splitlines()
                 if 'artisan down' in line and not line.lstrip().startswith('#')]
        self.assertTrue(downs)
        self.assertEqual([line for line in downs if '--render' in line], [])


if __name__ == '__main__':
    unittest.main()
