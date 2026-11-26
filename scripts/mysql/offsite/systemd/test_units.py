"""Offline contract tests for fixed scheduled-backup unit files."""

from pathlib import Path
import configparser
import shlex
import unittest


ROOT = Path(__file__).resolve().parent
PROFILES = ("system", "user-fixture")
COMMAND = ["/usr/bin/python3", "-I", "-B", "/opt/repro-backup-tools/scheduled_backup.py"]
PLAN_ARGS = ["--plan", "/etc/repro-backup/plan.json"]
NAMES = {
    "repro-backup.service", "repro-backup.timer",
    "repro-backup-watchdog.service", "repro-backup-watchdog.timer",
    "repro-backup-alert.service",
}


def read_unit(profile: str, name: str) -> configparser.ConfigParser:
    parser = configparser.ConfigParser(interpolation=None, strict=True)
    parser.optionxform = str
    parser.read_string((ROOT / profile / name).read_text(encoding="utf-8"))
    return parser


class UnitContractTest(unittest.TestCase):
    def services(self):
        for profile in PROFILES:
            for name in sorted(NAMES):
                if name.endswith(".service"):
                    yield profile, name, read_unit(profile, name)

    def test_complete_profiles_and_exact_commands(self):
        # The system profile also holds the production alert, MySQL and kit units
        # (test_production_units.py); the user fixture keeps exactly the base five.
        self.assertTrue(NAMES <= {p.name for p in (ROOT / "system").iterdir()})
        self.assertEqual({p.name for p in (ROOT / "user-fixture").iterdir()}, NAMES)
        for profile, name, unit in self.services():
            action = "run" if name == "repro-backup.service" else "check"
            self.assertEqual(shlex.split(unit["Service"]["ExecStart"]), COMMAND + [action] + PLAN_ARGS)
            self.assertEqual(unit["Service"]["Type"], "oneshot")
            self.assertNotIn("Install", unit, (profile, name))

    def test_no_shell_skip_success_override_or_repeated_writer(self):
        for profile, name, unit in self.services():
            service = unit["Service"]
            for key in service:
                self.assertNotIn(key, {"ExecCondition", "SuccessExitStatus", "RemainAfterExit",
                                       "RestartForceExitStatus", "RuntimeMaxSec"}, (profile, name, key))
                if key.startswith("Exec"):
                    self.assertTrue(service[key].startswith("/usr/bin/"))
                    self.assertNotIn("sh", Path(shlex.split(service[key])[0]).name)
            self.assertFalse(any(k.startswith(("Condition", "Assert")) for k in unit["Unit"]))
            self.assertEqual(service["Restart"], "no")

    def test_child_group_is_killed_with_bounded_oneshot(self):
        for _, name, unit in self.services():
            service = unit["Service"]
            self.assertEqual(service["KillMode"], "control-group")
            self.assertEqual(service["TimeoutStopSec"], "30s")
            self.assertEqual(service["SendSIGKILL"], "yes")
            self.assertEqual(service["TimeoutStartSec"], "7200s" if name == "repro-backup.service" else "60s")
            self.assertEqual(service["NoNewPrivileges"], "yes")
            self.assertEqual(service["CapabilityBoundingSet"], "")
            self.assertEqual(service["AmbientCapabilities"], "")

    def test_failure_graph_terminates_in_alert(self):
        for profile in PROFILES:
            for name in ("repro-backup.service", "repro-backup-watchdog.service"):
                unit = read_unit(profile, name)
                # The journal marker stays first; the system profile adds the external bridge.
                self.assertEqual(unit["Unit"]["OnFailure"].split()[0], "repro-backup-alert.service")
                self.assertEqual(len(unit["Unit"]["OnFailure"].split()), 2 if profile == "system" else 1)
                self.assertEqual(unit["Unit"]["OnFailureJobMode"], "replace")
            alert = read_unit(profile, "repro-backup-alert.service")
            self.assertNotIn("OnFailure", alert["Unit"])
            self.assertEqual(shlex.split(alert["Service"]["ExecStartPre"]), ["/usr/bin/echo",
                "REPRO_BACKUP_ALERT:", "scheduled", "backup", "or", "freshness", "check", "failed"])
            self.assertEqual(alert["Service"]["SyslogLevel"], "err")

    def test_state_and_read_access_are_explicit(self):
        for _, name, unit in self.services():
            self.assertEqual(unit["Service"]["UMask"], "0077")
        for name in sorted(NAMES):
            if not name.endswith(".service"):
                continue
            service = read_unit("system", name)["Service"]
            self.assertEqual(service["User"], "repro-backup")
            self.assertEqual(service["Group"], "repro-backup")
            self.assertEqual(service["ProtectSystem"], "strict")
            self.assertEqual(service["ProtectHome"], "read-only")
            if name == "repro-backup.service":
                self.assertEqual(service["StateDirectory"], "repro-backup")
                self.assertEqual(service["StateDirectoryMode"], "0700")
                self.assertEqual(service["ReadWritePaths"], "/var/lib/repro-backup")
            else:
                self.assertNotIn("StateDirectory", service)
                self.assertNotIn("ReadWritePaths", service)
                self.assertEqual(service["WorkingDirectory"], "/")

    def test_check_cannot_use_ip_sockets(self):
        for profile, name, unit in self.services():
            service = unit["Service"]
            if name == "repro-backup.service":
                self.assertEqual(service["RestrictAddressFamilies"], "AF_UNIX AF_INET AF_INET6")
            else:
                self.assertEqual(service["RestrictAddressFamilies"], "AF_UNIX")
                if profile == "system":
                    self.assertEqual(service["PrivateNetwork"], "yes")

    def test_fixture_profile_does_not_claim_privileged_features(self):
        prohibited = {"User", "Group", "StateDirectory", "PrivateNetwork", "PrivateTmp",
                      "PrivateDevices", "ProtectSystem", "ProtectHome", "ProtectControlGroups",
                      "ProtectKernelTunables", "ProtectKernelModules", "ReadWritePaths"}
        for name in sorted(NAMES):
            text = (ROOT / "user-fixture" / name).read_text(encoding="utf-8")
            self.assertTrue(text.startswith("# Fixture only:"))
            if name.endswith(".service"):
                self.assertFalse(prohibited.intersection(read_unit("user-fixture", name)["Service"]))

    def test_schedules_are_independent_and_target_only_fixed_services(self):
        for profile in PROFILES:
            daily = read_unit(profile, "repro-backup.timer")
            watch = read_unit(profile, "repro-backup-watchdog.timer")
            self.assertEqual(daily["Timer"]["Unit"], "repro-backup.service")
            # 03:30 UTC keeps 90 minutes clear of the monthly 02:00 reboot on the 28th.
            self.assertEqual(daily["Timer"]["OnCalendar"], "*-*-* 03:30:00 UTC")
            self.assertEqual(daily["Timer"]["Persistent"], "true")
            self.assertEqual(daily["Timer"]["RandomizedDelaySec"], "5m")
            self.assertEqual(watch["Timer"]["Unit"], "repro-backup-watchdog.service")
            self.assertEqual(watch["Timer"]["OnStartupSec"], "5m")
            self.assertEqual(watch["Timer"]["OnUnitInactiveSec"], "5m")
            for timer in (daily, watch):
                self.assertEqual(timer["Install"]["WantedBy"], "timers.target")


if __name__ == "__main__":
    unittest.main()
