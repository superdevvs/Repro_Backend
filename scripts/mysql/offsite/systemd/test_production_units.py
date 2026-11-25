"""Offline contract tests for the production alert, MySQL recovery and recovery-kit units."""

from pathlib import Path
import datetime as dt
import json
import shlex
import unittest

ROOT = Path(__file__).resolve().parent / "system"
TOOLS = "/opt/repro-backup-tools/"
PYTHON = ["/usr/bin/python3", "-I", "-B"]
BRIDGE = PYTHON + [TOOLS + "alert_bridge.py"]
BRIDGE_CONFIG = ["--config", "/etc/repro-alert/bridge.json"]
SIGNALS = {
    "repro-alert-backup-ok.service": ("backup_failed", "ok"),
    "repro-alert-backup-failed.service": ("backup_failed", "fail"),
    "repro-alert-backup-fresh.service": ("backup_stale", "ok"),
    "repro-alert-backup-stale.service": ("backup_stale", "fail"),
    "repro-alert-heartbeat.service": ("server_silent", "ok"),
}
BRIDGE_UNITS = set(SIGNALS) | {"repro-alert-forward.service"}
NETWORK = BRIDGE_UNITS | {"repro-backup.service", "repro-binlog-archive.service", "repro-recovery-kit.service"}
TIMERS = {
    "repro-alert-heartbeat.timer": ("*-*-* *:*:00 UTC", False),
    "repro-alert-probe.timer": ("*-*-* *:*:15 UTC", False),
    "repro-alert-forward.timer": ("*-*-* *:*:40 UTC", False),
    "repro-mysql-heartbeat.timer": ("*-*-* *:*:05 UTC", False),
    "repro-binlog-rotate.timer": ("*-*-* *:0/2:00 UTC", False),
    "repro-binlog-archive.timer": ("*-*-* *:1/2:30 UTC", False),
    "repro-binlog-purge.timer": ("*-*-* *:17:00 UTC", False),
    "repro-recovery-kit.timer": ("*-*-* 04:15:00 UTC", True),
    "repro-backup.timer": ("*-*-* 03:30:00 UTC", True),
}
PINNED = {
    "repro-mysql-heartbeat.service": "REVIEWED_HEARTBEAT_PLAN_SHA256",
    "repro-binlog-rotate.service": "REVIEWED_ROTATION_PLAN_SHA256",
    "repro-binlog-archive.service": "REVIEWED_ARCHIVE_PLAN_SHA256",
    "repro-binlog-purge.service": "REVIEWED_PURGE_PLAN_SHA256",
}
NEW = BRIDGE_UNITS | set(PINNED) | set(TIMERS) | {
    "repro-alert-probe.service", "repro-alert-cutover-view.service", "repro-recovery-kit.service",
    "repro-recovery-kit.path"} - {"repro-backup.timer"}


def read(name: str) -> dict:
    """Parse a unit like systemd does: repeated keys are kept, joined by newlines."""
    sections, current = {}, None
    for line in (ROOT / name).read_text(encoding="utf-8").splitlines():
        if not line or line.startswith("#"):
            continue
        if line.startswith("[") and line.endswith("]"):
            current = sections.setdefault(line[1:-1], {})
            continue
        key, value = line.split("=", 1)
        current[key] = current[key] + "\n" + value if key in current else value
    return sections


def services():
    for path in sorted(ROOT.glob("*.service")):
        yield path.name, read(path.name)


class ProductionUnitTests(unittest.TestCase):
    def test_every_new_unit_exists_with_lf_endings(self):
        for name in NEW:
            raw = (ROOT / name).read_bytes()
            self.assertNotIn(b"\r", raw, name)

    def test_services_are_bounded_oneshots_without_shell_or_success_overrides(self):
        for name, unit in services():
            service = unit["Service"]
            self.assertEqual(service["Type"], "oneshot", name)
            self.assertEqual(service["Restart"], "no", name)
            self.assertEqual(service["KillMode"], "control-group", name)
            self.assertEqual(service["NoNewPrivileges"], "yes", name)
            self.assertEqual(service["CapabilityBoundingSet"], "", name)
            self.assertEqual(service["ProtectSystem"], "strict", name)
            self.assertEqual(service["PrivateTmp"], "yes", name)
            self.assertNotIn("Install", unit, name)
            for key, value in service.items():
                self.assertNotIn(key, {"SuccessExitStatus", "RemainAfterExit", "ExecCondition"}, name)
                if key.startswith("Exec"):
                    for line in value.splitlines():
                        self.assertTrue(line.startswith("/usr/bin/"), (name, key))

    def test_only_bridge_publisher_archive_and_kit_units_have_network(self):
        for name, unit in services():
            service = unit["Service"]
            if name in NETWORK:
                self.assertNotIn("PrivateNetwork", service, name)
                self.assertEqual(service["RestrictAddressFamilies"], "AF_UNIX AF_INET AF_INET6", name)
            else:
                self.assertEqual(service["PrivateNetwork"], "yes", name)
                self.assertEqual(service["RestrictAddressFamilies"], "AF_UNIX", name)

    def test_bridge_units_run_as_the_alert_account_and_cannot_see_data(self):
        for name in BRIDGE_UNITS:
            service = read(name)["Service"]
            self.assertEqual((service["User"], service["Group"]), ("repro-alert", "repro-alert"), name)
            self.assertEqual(service["ProtectHome"], "yes", name)
            self.assertEqual(service["InaccessiblePaths"], "-/var/lib/repro-backup -/var/www -/etc/repro-backup", name)
            self.assertNotIn("ReadWritePaths", service, name)
        for name, (condition, state) in SIGNALS.items():
            self.assertEqual(shlex.split(read(name)["Service"]["ExecStart"]),
                             BRIDGE + ["signal"] + BRIDGE_CONFIG + ["--condition", condition, "--state", state])
        self.assertEqual(shlex.split(read("repro-alert-forward.service")["Service"]["ExecStart"]),
                         BRIDGE + ["forward"] + BRIDGE_CONFIG)

    def test_backup_results_reach_the_bridge_without_losing_the_journal_marker(self):
        backup = read("repro-backup.service")["Unit"]
        self.assertEqual(backup["OnSuccess"], "repro-alert-backup-ok.service")
        self.assertEqual(backup["OnFailure"], "repro-backup-alert.service repro-alert-backup-failed.service")
        self.assertEqual(backup["Wants"], "network-online.target")
        self.assertEqual(backup["After"], "network-online.target time-sync.target")
        watchdog = read("repro-backup-watchdog.service")["Unit"]
        self.assertEqual(watchdog["OnSuccess"], "repro-alert-backup-fresh.service")
        self.assertEqual(watchdog["OnFailure"], "repro-backup-alert.service repro-alert-backup-stale.service")
        self.assertEqual(read("repro-recovery-kit.service")["Unit"]["OnFailure"], "repro-alert-backup-failed.service")
        for name in BRIDGE_UNITS:
            self.assertFalse({"OnFailure", "OnSuccess"} & set(read(name)["Unit"]), name)

    def test_probe_writes_only_the_inbox_and_its_memory(self):
        service = read("repro-alert-probe.service")["Service"]
        self.assertEqual((service["User"], service["Group"]), ("repro-backup", "repro-backup"))
        self.assertEqual(service["SupplementaryGroups"], "adm")
        self.assertEqual(service["StateDirectory"], "repro-alert-probe")
        self.assertEqual(service["ReadWritePaths"], "/var/lib/repro-alert-inbox")
        self.assertEqual(shlex.split(service["ExecStart"]),
                         PYTHON + [TOOLS + "alert_probe.py", "run", "--plan", "/etc/repro-backup/alert-probe.json"])

    def test_probe_starts_the_cutover_view_first(self):
        unit = read("repro-alert-probe.service")["Unit"]
        self.assertEqual(unit["Wants"], "repro-alert-cutover-view.service")
        self.assertEqual(unit["After"], "repro-alert-cutover-view.service")

    def test_cutover_view_is_root_without_capabilities_and_sees_only_the_journal(self):
        # The journal is root-private (the guard refuses group or other access), so only its
        # owner may read it. Without capabilities root has the owner's rights and nothing more;
        # the empty /etc, /var, /run and /opt hide every other root-owned file.
        service = read("repro-alert-cutover-view.service")["Service"]
        self.assertEqual((service["User"], service["Group"]), ("root", "repro-backup"))
        self.assertEqual((service["CapabilityBoundingSet"], service["AmbientCapabilities"]), ("", ""))
        self.assertEqual(service["TemporaryFileSystem"], "/etc:ro /var:ro /run:ro /opt:ro")
        self.assertEqual(service["BindReadOnlyPaths"], "/opt/repro-backup-tools -/var/lib/repro-cutover-fence")
        self.assertEqual(service["BindPaths"], "/var/lib/repro-alert-cutover")
        self.assertEqual(service["InaccessiblePaths"], "-/mnt -/media -/srv")
        self.assertEqual(service["ProtectHome"], "yes")
        self.assertEqual(service["UMask"], "0027")
        self.assertNotIn("ReadWritePaths", service)
        self.assertEqual(shlex.split(service["ExecStart"]), PYTHON + [TOOLS + "cutover_view.py"])

    def test_timers_follow_the_reviewed_cadence_and_catch_up_policy(self):
        for name, (calendar, persistent) in TIMERS.items():
            timer = read(name)
            self.assertEqual(timer["Timer"]["OnCalendar"], calendar, name)
            self.assertEqual(timer["Timer"]["Persistent"], "true" if persistent else "false", name)
            self.assertTrue((ROOT / timer["Timer"]["Unit"]).is_file(), name)
            self.assertEqual(timer["Install"]["WantedBy"], "timers.target", name)

    def test_daily_backup_stays_clear_of_the_monthly_0200_reboot(self):
        hour, minute = (int(part) for part in TIMERS["repro-backup.timer"][0].split()[1].split(":")[:2])
        start = dt.datetime(2026, 12, 28, hour, minute, tzinfo=dt.timezone.utc)
        reboot = dt.datetime(2026, 12, 28, 2, 0, tzinfo=dt.timezone.utc)
        self.assertGreaterEqual((start - reboot).total_seconds(), 3600)
        # A 26 h freshness limit leaves room for the 5 min jitter and a 30 min run.
        self.assertLess(24 * 3600 + 5 * 60 + 30 * 60, 26 * 3600)

    def test_pinned_plans_keep_a_placeholder_until_the_installer_renders_them(self):
        for name, placeholder in PINNED.items():
            command = shlex.split(read(name)["Service"]["ExecStart"])
            self.assertEqual(command[command.index("--plan-sha256") + 1], placeholder, name)

    def test_rotation_guard_and_archive_dispatch(self):
        unit = read("repro-binlog-rotate.service")
        service = unit["Service"]
        guard = "/var/lib/repro-binlog-rotation/attempt.pending"
        self.assertEqual([shlex.split(line) for line in service["ExecStartPre"].splitlines()],
                         [["/usr/bin/mkdir", "--", guard], ["/usr/bin/sync", "/var/lib/repro-binlog-rotation"]])
        command = shlex.split(service["ExecStart"])
        self.assertEqual(command[command.index("--guard") + 1], guard)
        self.assertEqual(unit["Unit"]["OnSuccess"], "repro-binlog-archive.service")
        self.assertEqual(unit["Unit"]["OnFailure"], "repro-binlog-archive.service")
        self.assertEqual(service["User"], "repro-binlog")
        self.assertEqual(read("repro-mysql-heartbeat.service")["Service"]["User"], "repro-binlog")
        for name in ("repro-binlog-archive.service", "repro-binlog-purge.service"):
            self.assertEqual(read(name)["Service"]["User"], "repro-backup", name)
            self.assertEqual(read(name)["Service"]["ReadOnlyPaths"], "/var/lib/repro-mysql84-native/data", name)
        for name in PINNED:
            self.assertEqual(read(name)["Service"]["SupplementaryGroups"], "repro-binlog-plan", name)

    def test_kit_runs_daily_and_when_env_changes(self):
        path = read("repro-recovery-kit.path")
        self.assertEqual(path["Path"]["PathChanged"], "/var/www/backend/.env")
        self.assertEqual(path["Path"]["Unit"], "repro-recovery-kit.service")
        self.assertEqual(shlex.split(read("repro-recovery-kit.service")["Service"]["ExecStart"]),
                         PYTHON + [TOOLS + "produce_recovery_kit.py", "--plan", "/etc/repro-backup/recovery-kit.json"])


if __name__ == "__main__":
    unittest.main()


class ConditionNamesTests(unittest.TestCase):
    def test_every_condition_a_unit_signals_is_in_the_thresholds_file(self):
        record = json.loads((ROOT.parent.parent / "alert-conditions.json").read_text(encoding="utf-8"))
        known = set(record["conditions"]) | set(record["extra_conditions"])
        self.assertTrue({condition for condition, _ in SIGNALS.values()} <= known)
        self.assertEqual(set(record["extra_conditions"]) & set(record["conditions"]), set())
