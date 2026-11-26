"""Contracts between slices of the MySQL adapter lane that no single slice's tests can see.

- Smoke tables (review B1): the write smoke changes exactly CutoverSmokeFixture::SMOKE_TABLES (D09, app
  code). The production example host config (test_adapter.example_config, D01) and the sandbox host.json
  (sandbox/make_config.py, D17) must name exactly those tables: the 4.3 audit accepts business events
  only inside the smoke window and only on smoke_tables, so a list without `users` sends the night down
  the hour-long way back, and a table the smoke never writes widens what the audit lets through.
- The Telnyx capture's command lines (review B9): adapter.CAPTURE_COMMAND_LINES (D01) are copied from the
  capture's systemd unit and PHP-FPM config (D15). load_config refuses process patterns that match them;
  if the unit's ExecStart or the pool changed without the constants, that refusal would silently stop
  covering the capture.

Pure Python; the make_config test needs a POSIX system (no root). Run from the app root:
    python3 -B -m unittest discover -s scripts/cutover/production -p test_cross_slice.py
"""

import configparser
import json
import os
import re
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parent
APP = HERE.parents[2]
sys.path[:0] = [str(HERE.parent), str(HERE)]
import adapter  # noqa: E402
from test_adapter import example_config  # noqa: E402

SMOKE_FIXTURE = APP / "app" / "Support" / "Cutover" / "CutoverSmokeFixture.php"
MAKE_CONFIG = HERE / "sandbox" / "make_config.py"
CAPTURE_UNIT = HERE / "systemd" / "repro-telnyx-capture.service"
CAPTURE_FPM = HERE / "capture" / "php-fpm.conf"


def app_smoke_tables() -> list[str]:
    """CutoverSmokeFixture::SMOKE_TABLES, read from the PHP source (a missing file or constant fails)."""
    source = SMOKE_FIXTURE.read_text(encoding="utf-8")
    found = re.findall(r"public const SMOKE_TABLES = \[([^\]]*)\];", source)
    if len(found) != 1:
        raise AssertionError(f"{SMOKE_FIXTURE} must define SMOKE_TABLES exactly once")
    items = [item.strip() for item in found[0].split(",") if item.strip()]
    tables = [re.fullmatch(r"'([a-z_]+)'", item) for item in items]
    if not tables or not all(tables):
        raise AssertionError(f"SMOKE_TABLES is not a plain list of table names: {found[0]!r}")
    return [table.group(1) for table in tables]


class SmokeTablesTests(unittest.TestCase):
    """Review B1: host config smoke_tables equal the app smoke's own list."""

    def test_app_smoke_fixture_lists_the_tables(self):
        self.assertEqual(["users", "shoots", "aryeo_changes", "jobs"], app_smoke_tables())

    def test_example_host_config_names_exactly_the_app_smoke_tables(self):
        tables = example_config()["mysql"]["smoke_tables"]
        self.assertEqual(len(tables), len(set(tables)), "duplicate smoke table")
        self.assertCountEqual(app_smoke_tables(), tables)

    @unittest.skipUnless(os.name == "posix", "make_config.py writes POSIX paths")
    def test_sandbox_host_config_names_exactly_the_app_smoke_tables(self):
        with tempfile.TemporaryDirectory(prefix="repro-cross-slice-") as temporary:
            root = Path(os.path.realpath(temporary)) / "root"
            app = root / "app" / "backend"
            (app / "database").mkdir(parents=True)
            datadir = root / "mysql" / "data"
            datadir.mkdir(parents=True)
            (datadir / "auto.cnf").write_text("[auto]\nserver-uuid=5f0e9a8c-2b51-11f1-9d3a-0242ac110002\n",
                                              encoding="ascii")
            result = subprocess.run(
                [sys.executable, "-I", "-S", str(MAKE_CONFIG), "--root", str(root), "--controller", str(HERE.parent),
                 "--app-root", str(app), "--run-id", "cross-slice-1", "--mysql-datadir", str(datadir),
                 "--deploy-owner", "www-data", "--smoke-client-id", "41", "--smoke-shoot-id", "42"],
                capture_output=True, text=True, timeout=120)
            self.assertEqual(0, result.returncode, result.stderr)
            written = root / "binds" / "etc" / "repro-cutover-fence" / "host.json"
            tables = json.loads(written.read_text(encoding="utf-8"))["mysql"]["smoke_tables"]
        self.assertEqual(len(tables), len(set(tables)), "duplicate smoke table")
        self.assertCountEqual(app_smoke_tables(), tables)


class CaptureCommandLineTests(unittest.TestCase):
    """Review B9: the capture command lines load_config keeps clear are the ones D15 installs."""

    def test_capture_command_lines_follow_the_unit_and_its_pool(self):
        unit = CAPTURE_UNIT.read_text(encoding="ascii")
        starts = re.findall(r"^ExecStart=(.+)$", unit, re.MULTILINE)
        self.assertEqual(1, len(starts), "the capture unit has one ExecStart")
        self.assertEqual(starts[0], adapter.CAPTURE_COMMAND_LINES[0])
        fpm_config = re.fullmatch(r"\S+ --nodaemonize --fpm-config (\S+)", starts[0])
        self.assertIsNotNone(fpm_config, starts[0])
        self.assertIn(f"php-fpm: master process ({fpm_config.group(1)})", adapter.CAPTURE_COMMAND_LINES)
        parser = configparser.ConfigParser(comment_prefixes=(";", "#"), interpolation=None)
        parser.read_string(CAPTURE_FPM.read_text(encoding="ascii"))
        pools = [section for section in parser.sections() if section != "global"]
        self.assertEqual([adapter.CAPTURE_NAME], pools)
        self.assertEqual(Path(CAPTURE_UNIT.name).stem, adapter.CAPTURE_NAME)
        for pool in pools:
            self.assertIn(f"php-fpm: pool {pool}", adapter.CAPTURE_COMMAND_LINES)


if __name__ == "__main__":
    unittest.main()
