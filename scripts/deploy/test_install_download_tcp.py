"""Apply, repeat-apply, failure rollback and restore against a fake /proc/sys and /etc."""
import importlib.util
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location('install_download_tcp', Path(__file__).with_name('install-download-tcp.py'))
tcp = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(tcp)

CUBIC = {'net.ipv4.tcp_congestion_control': 'cubic', 'net.ipv4.tcp_wmem': '4096 16384 4194304'}


class InstallDownloadTcpTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        root = Path(self.directory.name)
        self.live = dict(CUBIC, **{'net.ipv4.tcp_available_congestion_control': 'reno cubic'})
        self.fail_on = None
        patches = [
            patch.object(tcp, 'SYSCTL', root / 'sysctl.conf'), patch.object(tcp, 'MODULE', root / 'module.conf'),
            patch.object(tcp, 'BACKUP', root / 'backup' / 'previous.sysctl'),
            patch.object(tcp, 'require_root', lambda: None), patch.object(tcp, 'current', lambda key: self.live[key]),
            patch.object(tcp, 'run', self.run_command),
        ]
        for item in patches:
            item.start()
            self.addCleanup(item.stop)
        self.addCleanup(self.directory.cleanup)

    def run_command(self, *args):
        if args[0] == 'modprobe':
            self.live['net.ipv4.tcp_available_congestion_control'] = 'reno cubic bbr'
        elif args[0] == 'sysctl':
            key, value = args[-1].split('=', 1)
            if key == self.fail_on and value != CUBIC[key]:
                raise RuntimeError('sysctl refused the value')
            self.live[key] = value

    def tunables(self):
        return {key: self.live[key] for key in tcp.SETTINGS}

    def test_apply_then_restore_returns_the_exact_previous_values(self):
        tcp.apply()
        self.assertEqual(tcp.SETTINGS, self.tunables())
        self.assertEqual(tcp.SETTINGS, tcp.parse(tcp.SYSCTL.read_text()))
        self.assertIn('tcp_bbr', tcp.MODULE.read_text())
        tcp.apply()  # A repeat must not record BBR as the state to restore.
        self.assertEqual(CUBIC, tcp.parse(tcp.BACKUP.read_text()))
        tcp.restore()
        self.assertEqual(CUBIC, self.tunables())
        self.assertFalse(tcp.SYSCTL.exists() or tcp.MODULE.exists() or tcp.BACKUP.exists())

    def test_a_failed_apply_leaves_the_previous_values_and_no_persistent_files(self):
        self.fail_on = 'net.ipv4.tcp_wmem'
        with self.assertRaises(RuntimeError):
            tcp.apply()
        self.assertEqual(CUBIC, self.tunables())
        self.assertFalse(tcp.SYSCTL.exists() or tcp.MODULE.exists())

    def test_unmanaged_files_and_missing_state_are_refused(self):
        tcp.SYSCTL.write_text('net.ipv4.tcp_congestion_control = reno\n')
        with self.assertRaises(SystemExit):
            tcp.apply()
        with self.assertRaises(SystemExit):
            tcp.restore()
        self.assertEqual(CUBIC, self.tunables())


if __name__ == '__main__':
    unittest.main()
