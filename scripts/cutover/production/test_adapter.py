"""Linux tests for the production host adapter and CLI with fake host commands and real processes."""

import io
import json
import os
import re
import shutil
import stat
import subprocess
import sys
import tempfile
import time
import unittest
import uuid
from contextlib import redirect_stdout
from pathlib import Path
from unittest.mock import patch

sys.path[:0] = [str(Path(__file__).resolve().parents[1]), str(Path(__file__).resolve().parent)]
import adapter as production
import cli
import deploy_marker
import sqlite_seal
from fence import guard
from runner import Identity, Journal, Runner, UnsafeCutover

IDENTITY = Identity("host-fixture", "a" * 40, "b" * 64, "c" * 64, "d" * 64, "ec219d68-61fd-4a9e-ba98-43ddb4008383")
# The lab-tour connection and queue are both `iguide-lab-tour` (config/queue.php).
QUEUES = ["database:mail", "database:default", "studio:studio", "iguide-lab-tour:iguide-lab-tour"]
# The nine queues the hold pauses on the production host, plus the optional lab-tour queue.
PRODUCTION_QUEUES = [
    "database:mail", "database:integrations", "database:default", "database:watermarks", "database:voice-realtime",
    "database:voice-notifications", "database:voice-transcripts", "studio:studio", "database:media-archives",
    "iguide-lab-tour:iguide-lab-tour",
]
TARGET_UUID = IDENTITY.target_uuid
# `.env` keys the night sets after `init` (design 3.1). Denylisted, so they never change `config_sha256`.
NIGHT_FLAGS = ["CRM_OUTBOUND_HOLD", "CRM_DEFER_REPLY_FORWARDING", "CRM_CALENDAR_SYNC_HOLD", "PRODUCER_INGRESS_HOLD",
               "SCHEDULER_HOLD_ENABLED"]
# Every `.env` key `prepare` writes and `seal_sqlite` restores: the switch plus its secret.
SWITCH_KEYS = ["DB_COLLATION", "DB_CONNECTION", "DB_DATABASE", "DB_PASSWORD", "DB_SOCKET", "DB_USERNAME",
               "REPRO_MYSQL_EXPECTED_SERVER_UUID"]
FENCE = "/opt/repro-cutover-fence/production"
PYTHON = ["/usr/bin/python3", "-I", "-S"]


def queue_command(verb, user="www-data", script=None):
    """The queue pause or resume prefix of design 5.1; the adapter appends `connection:queue` as `$1`."""
    script = script or f'cd /var/www/backend && umask 0022 && exec /usr/bin/php artisan queue:{verb} "$1"'
    return ["/usr/sbin/runuser", "-u", user, "--", "/bin/sh", "-c", script, "repro-queue"]


def example_config():
    """The complete host.json for the production host (design 3.1). IDs and the UUID are placeholders."""
    state, run, etc = "/var/lib/repro-cutover-fence", "/run/repro-cutover-fence", "/etc/repro-cutover-fence"
    return {
        "version": 1,
        "paths": {
            "state": state, "policy": f"{etc}/policy.json", "evidence": f"{run}/verified-engine.json",
            "activation": f"{run}/verified-activation.json", "machine_id": "/etc/machine-id",
            "boot_id": "/proc/sys/kernel/random/boot_id", "ingress_open": "/run/repro-cutover-ingress/open",
            "deploy_marker": "/etc/repro-database-cutover/active", "scheduler_hold": f"{run}/scheduler-hold.json",
            "sqlite_seal_record": f"{state}/sqlite-seal.json", "identity_config": f"{etc}/identity.json",
            "switch_record": f"{state}/switch-record.json", "sealed_hash": f"{state}/sealed-hash.json",
            "receipt": f"{state}/mysql-receipt.json", "activity": f"{state}/mysql-activity.json",
            "activated": f"{state}/mysql-activated.json", "smoke_window": f"{state}/smoke-window.json",
            "evidence_inputs": f"{state}/evidence-inputs.json", "evidence_report": f"{state}/evidence-report.json",
            "unseal_proof": f"{state}/unseal-proof.json", "issued_approvals": f"{state}/issued-approvals",
            "scheduler_release": f"{run}/scheduler-released", "wrappers": f"{etc}/wrappers.json",
            "wrappers_sqlite": f"{etc}/wrappers.sqlite.json", "wrappers_mysql": f"{etc}/wrappers.mysql.json",
            "retired": f"{etc}/retired.json", "archive": "/var/lib/repro-cutover-archive",
            "keep": "/var/lib/repro-cutover-keep",
        },
        "app_root": "/var/www/backend", "app_owner": "maverick", "app_group": "www-data", "web_user": "www-data",
        "sqlite": "/var/www/backend/database/database.sqlite",
        "deploy_locks": ["/home/maverick/.repro-backend-deploy.lock", "/home/maverick/.repro-frontend-deploy.lock"],
        "deploy_owner": "maverick",
        "queues": list(PRODUCTION_QUEUES),
        "mysql": {
            "mode": "drill", "database": "repro_dashboard", "control_database": "repro_mysql_control",
            "target_uuid": TARGET_UUID, "datadir": "/var/lib/repro-mysql84-native/data",
            "client": "/opt/repro-mysql84-native/mysql-8.4.13/bin/mysql",
            "socket": "/run/repro-mysql84-native/mysql.sock",
            "probe_defaults": f"{etc}/mysql-probe.cnf", "gate_defaults": f"{etc}/mysql-gate.cnf",
            "token_check_defaults": f"{etc}/mysql-token-check.cnf",
            "gated_accounts": ["repro_app@localhost"],
            "lockable_accounts": ["repro_maint@localhost", "repro_migrator@localhost"],
            "activity_excluded_tables": ["repro_recovery_heartbeat"],
            "smoke_tables": ["clients", "shoots", "aryeo_changes", "jobs", "cache", "cache_locks"],
            "switch": {"DB_CONNECTION": "mysql", "DB_DATABASE": "repro_dashboard",
                       "DB_SOCKET": "/run/repro-mysql84-native/mysql.sock", "DB_USERNAME": "repro_app",
                       "DB_COLLATION": "utf8mb4_0900_bin", "REPRO_MYSQL_EXPECTED_SERVER_UUID": TARGET_UUID},
            "switch_secrets": {"DB_PASSWORD": f"{etc}/mysql-app.env"},
            "night_flags": list(NIGHT_FLAGS),
            "offsite_waiver": False,
        },
        "fpm": {"unit": "php8.3-fpm.service", "socket": "/run/php/php8.3-fpm.sock",
                "status_socket": "/run/php/php8.3-fpm-status.sock", "drain_seconds": 90},
        "smoke": {"client_id": 4101, "shoot_id": 9201},
        "studio_pause_files": ["/home/maverick/.local/share/repro-studio/paused",
                               "/home/maverick/.local/share/repro-studio/paused-lab-tour"],
        "commands": {
            "identity": PYTHON + [f"{FENCE}/identity_probe.py", "--config", f"{etc}/identity.json"],
            "mysql": PYTHON + [f"{FENCE}/mysql_probe.py", "--config", f"{etc}/host.json"],
            "queue_pause": queue_command("pause"), "queue_resume": queue_command("resume"),
            "stop_workers": [["/usr/bin/supervisorctl", "stop", "laravel-worker:*"],
                             ["/usr/bin/systemctl", "stop", "repro-media-archives.service"]],
            "start_workers": [["/usr/bin/systemctl", "start", "php8.3-fpm.service"],
                              ["/usr/bin/supervisorctl", "start", "laravel-worker:*"],
                              ["/usr/bin/systemctl", "start", "repro-media-archives.service"]],
            "drain_fpm": [PYTHON + [f"{FENCE}/fpm_drain.py", "--config", f"{etc}/host.json"],
                          ["/usr/bin/systemctl", "stop", "php8.3-fpm.service"]],
            "config_build": ["/usr/sbin/runuser", "-u", "maverick", "-g", "www-data", "--", "/bin/sh", "-c",
                             'umask 0022 && cd /var/www/backend && exec env APP_CONFIG_CACHE="$1" /usr/bin/php '
                             'artisan config:cache', "repro-config"],
            "fpm_start": ["/usr/bin/systemctl", "restart", "php8.3-fpm.service"],
            "fpm_reload": ["/usr/bin/systemctl", "try-reload-or-restart", "php8.3-fpm.service"],
            "fpm_stop": ["/usr/bin/systemctl", "stop", "php8.3-fpm.service"],
            "smoke_readonly": [
                ["/usr/sbin/runuser", "-u", "www-data", "--", "/bin/sh", "-c",
                 "cd /var/www/backend && umask 0022 && exec /usr/bin/php artisan cutover:smoke --read-only --json",
                 "repro-smoke"],
                ["/usr/sbin/runuser", "-u", "maverick", "-g", "www-data", "--", "/bin/sh", "-c",
                 "cd /var/www/backend && umask 0002 && exec /usr/bin/php artisan cutover:smoke --read-only --json",
                 "repro-smoke"]],
            "smoke_write": PYTHON + [f"{FENCE}/admit.py", "cutover-smoke"],
            "telnyx_replay": PYTHON + [f"{FENCE}/admit.py", "telnyx-replay"],
            "timer": ["/usr/bin/systemd-run", "--collect", "--property=UMask=0077"],
        },
        "writer_processes": ["artisan queue:work", "repro-studio/worker.sh", "repro-studio/lab-tour-worker.sh"],
        "long_job_processes": ["repro-studio/worker.sh", "--queue=media-archives"],
        "scheduler_processes": ["artisan schedule:run", "artisan schedule:finish"],
        "command_seconds": 120,
        "options": {"max_drain_seconds": 600, "separate_freeze_approval": False, "adopt_external_hold": False,
                    "require_long_job_predrain": True, "unattended_reopen": False},
    }


def example_identity(config, run_id=IDENTITY.run_id):
    """identity.json for a host config: the identity probe's inputs, with the one denylist load_config accepts."""
    return {"version": 1, "run_id": run_id, "app_root": config["app_root"], "sqlite_path": config["sqlite"],
            "mysql_datadir": config["mysql"]["datadir"], "env_denylist": sorted(NIGHT_FLAGS + SWITCH_KEYS)}


def load(config, identity=None):
    """load_config with identity.json served from exactly the path the host config names."""
    identity_raw = json.dumps(example_identity(config) if identity is None else identity).encode()

    def read(path):
        if path != Path(config["paths"]["identity_config"]):
            raise FileNotFoundError(path)
        return identity_raw

    return production.load_config(json.dumps(config).encode(), read=read)


@unittest.skipUnless(os.name == "posix", "Host config paths are POSIX absolute paths")
class HostConfigContractTests(unittest.TestCase):
    """host.json and identity.json (design 3.1): every key explicit, one denylist, safe queue commands."""

    def test_load_config_accepts_complete_v2_example(self):
        config = example_config()
        self.assertEqual(config, load(config))
        # The cutover host config differs only in the mode.
        config["mysql"]["mode"] = "cutover"
        self.assertEqual("cutover", load(config)["mysql"]["mode"])

    # Keys added by the MySQL lane; the SQLite-era keys keep their own checks.
    NEW_KEYS = (
        *[(name,) for name in ("app_root", "app_owner", "app_group", "web_user", "mysql", "fpm", "smoke",
                               "studio_pause_files")],
        *[("mysql", name) for name in example_config()["mysql"]],
        *[("fpm", name) for name in example_config()["fpm"]],
        *[("smoke", name) for name in example_config()["smoke"]],
        *[("paths", name) for name in ("identity_config", "switch_record", "sealed_hash", "receipt", "activity",
                                       "activated", "smoke_window", "evidence_inputs", "evidence_report",
                                       "unseal_proof", "issued_approvals", "scheduler_release", "wrappers",
                                       "wrappers_sqlite", "wrappers_mysql", "retired", "archive", "keep")],
        *[("commands", name) for name in ("config_build", "fpm_start", "fpm_reload", "fpm_stop", "smoke_readonly",
                                          "smoke_write", "telnyx_replay", "timer")],
        ("options", "unattended_reopen"),
    )

    @staticmethod
    def changed(document, keys, value=None, delete=False):
        changed = json.loads(json.dumps(document))
        parent = changed
        for key in keys[:-1]:
            parent = parent[key]
        if delete:
            del parent[keys[-1]]
        else:
            parent[keys[-1]] = value
        return changed

    def test_every_new_key_is_required(self):
        # No silent defaults: a host config without the key is refused, never filled in.
        identity = example_identity(example_config())
        for keys in self.NEW_KEYS:
            with self.subTest(missing=".".join(keys)):
                with self.assertRaises(guard.Refused):
                    load(self.changed(example_config(), keys, delete=True), identity)

    def test_unknown_keys_are_refused(self):
        for keys in (("unexpected",), ("mysql", "unexpected"), ("fpm", "unexpected"), ("smoke", "unexpected")):
            with self.subTest(extra=".".join(keys)):
                with self.assertRaises(guard.Refused):
                    load(self.changed(example_config(), keys, True))

    def test_malformed_new_values_are_refused(self):
        argv = ["/usr/bin/true"]
        cases = [
            (("app_root",), "var/www/backend"), (("app_owner",), ""), (("app_owner",), "root"),
            (("app_group",), 33), (("web_user",), "root"), (("web_user",), "www data"),
            (("mysql", "mode"), "live"), (("mysql", "mode"), "Drill"), (("mysql", "database"), "repro-dashboard"), (("mysql", "control_database"), "repro_dashboard"),
            (("mysql", "control_database"), ""), (("mysql", "target_uuid"), "not-a-uuid"),
            (("mysql", "datadir"), "var/lib/mysql"), (("mysql", "client"), "mysql"),
            (("mysql", "socket"), "mysql.sock"), (("mysql", "probe_defaults"), "mysql-probe.cnf"),
            (("mysql", "gate_defaults"), None), (("mysql", "token_check_defaults"), ["/etc/x.cnf"]),
            (("mysql", "gated_accounts"), []), (("mysql", "gated_accounts"), ["repro_app"]),
            (("mysql", "gated_accounts"), ["repro_app@localhost", "repro_app@localhost"]),
            (("mysql", "gated_accounts"), ["repro_app@%"]),
            (("mysql", "lockable_accounts"), ["repro_app@localhost"]),
            (("mysql", "lockable_accounts"), "repro_maint@localhost"),
            (("mysql", "activity_excluded_tables"), ["repro recovery"]), (("mysql", "smoke_tables"), []),
            (("mysql", "smoke_tables"), ["clients", "clients"]), (("mysql", "smoke_tables"), ["Clients"]),
            (("mysql", "switch"), []), (("mysql", "switch", "DB_COLLATION"), 5),
            (("mysql", "switch", "DB_COLLATION"), "utf8mb4_0900_bin\nAPP_KEY=base64:x"),
            (("mysql", "switch", "DB_COLLATION"), ""),
            (("mysql", "switch_secrets", "DB_PASSWORD"), "mysql-app.env"),
            (("mysql", "night_flags"), "CRM_OUTBOUND_HOLD"), (("mysql", "night_flags"), ["crm_outbound_hold"]),
            (("mysql", "night_flags"), NIGHT_FLAGS + ["CRM_OUTBOUND_HOLD"]),
            (("mysql", "offsite_waiver"), "false"), (("mysql", "offsite_waiver"), 0),
            (("fpm", "unit"), "php8.3-fpm"), (("fpm", "socket"), "php8.3-fpm.sock"),
            (("fpm", "status_socket"), ""), (("fpm", "drain_seconds"), 0), (("fpm", "drain_seconds"), True),
            (("fpm", "drain_seconds"), production.MAX_COMMAND_SECONDS + 1), (("fpm", "drain_seconds"), 90.0),
            (("smoke", "client_id"), 0), (("smoke", "shoot_id"), "9201"), (("smoke", "shoot_id"), True),
            (("studio_pause_files",), "/home/maverick/.local/share/repro-studio/paused"),
            (("studio_pause_files",), ["paused"]),
            (("paths", "switch_record"), "switch-record.json"), (("commands", "timer"), ["systemd-run"]),
            (("commands", "smoke_readonly"), argv), (("commands", "smoke_readonly"), []),
            (("commands", "fpm_reload"), [argv]), (("options", "unattended_reopen"), "true"),
            (("options", "unattended_reopen"), 1),
        ]
        for keys, value in cases:
            with self.subTest(key=".".join(keys), value=value):
                with self.assertRaises(guard.Refused):
                    load(self.changed(example_config(), keys, value))

    def test_identity_config_shape_is_checked(self):
        config = example_config()
        identity = example_identity(config)
        cases = [(("run_id",), "run id"), (("run_id",), ""), (("app_root",), "var/www/backend"),
                 (("sqlite_path",), 7), (("mysql_datadir",), None), (("env_denylist",), "DB_PASSWORD"),
                 (("env_denylist",), [*identity["env_denylist"][:-1], "db_socket"]), (("version",), 2),
                 (("unexpected",), True)]
        for keys, value in cases:
            with self.subTest(key=".".join(keys), value=value):
                with self.assertRaises(guard.Refused):
                    load(config, self.changed(identity, keys, value))
        for key in identity:
            with self.subTest(missing=key):
                with self.assertRaises(guard.Refused):
                    load(config, self.changed(identity, (key,), delete=True))
        # The file is JSON with unique keys, like every guard record.
        with self.assertRaises(guard.Refused):
            production.load_config(json.dumps(config).encode(),
                                   read=lambda path: b'{"version": 1, "run_id": "a", "run_id": "b"}')

    @staticmethod
    def identity_for(config):
        """identity.json whose denylist matches this config's night flags and switch keys."""
        mysql = config["mysql"]
        return {**example_identity(config),
                "env_denylist": sorted({*mysql["night_flags"], *mysql["switch"], *mysql["switch_secrets"]})}

    def test_denylist_must_equal_night_flags_plus_switch_keys(self):
        config = example_config()
        denylist = sorted(NIGHT_FLAGS + SWITCH_KEYS)
        # Order does not matter, the set does.
        self.assertEqual(config, load(config, {**example_identity(config), "env_denylist": denylist[::-1]}))
        for name, changed in (
            ("a night flag missing", [key for key in denylist if key != "PRODUCER_INGRESS_HOLD"]),
            ("a switch key missing", [key for key in denylist if key != "DB_SOCKET"]),
            ("another key hidden from config_sha256", denylist + ["APP_KEY"]),
            ("a key twice", denylist + ["DB_SOCKET"]),
            ("night flags only", list(NIGHT_FLAGS)),
        ):
            with self.subTest(name):
                with self.assertRaisesRegex(guard.Refused, "env_denylist"):
                    load(config, {**example_identity(config), "env_denylist": changed})
        # A night flag added to host.json must be denylisted too.
        config["mysql"]["night_flags"].append("CRM_SMS_HOLD")
        with self.assertRaisesRegex(guard.Refused, "env_denylist"):
            load(config, example_identity(config))
        self.assertEqual(config, load(config, self.identity_for(config)))

    def test_switch_keys_include_secret_keys(self):
        config = example_config()
        self.assertEqual(SWITCH_KEYS, production.switch_keys(config))
        self.assertEqual(sorted(NIGHT_FLAGS + SWITCH_KEYS), production.env_denylist(config))
        # The v1 denylist (night flags plus the switch only) left DB_PASSWORD in config_sha256 (critic RC-H1).
        v1 = sorted(NIGHT_FLAGS + list(config["mysql"]["switch"]))
        with self.assertRaisesRegex(guard.Refused, "env_denylist"):
            load(config, {**example_identity(config), "env_denylist": v1})
        # The engine-check and schema-cache keys are switch keys once configured, and must be denylisted.
        config["mysql"]["switch"].update({"DB_EXPECTED_ENGINE": "mysql", "SCHEMA_CACHE_RELEASE": "mysql-20261212"})
        expected = sorted(SWITCH_KEYS + ["DB_EXPECTED_ENGINE", "SCHEMA_CACHE_RELEASE"])
        self.assertEqual(expected, production.switch_keys(config))
        with self.assertRaisesRegex(guard.Refused, "env_denylist"):
            load(config, example_identity(config))
        self.assertEqual(config, load(config, {**example_identity(config),
                                               "env_denylist": sorted(NIGHT_FLAGS + expected)}))

    def test_switch_holds_exactly_the_route_keys_and_the_app_password(self):
        # Every switch key is denylisted, so a key outside the route would hide its edits from config_sha256.
        def switch(change):
            config = example_config()
            change(config["mysql"])
            return config

        cases = {
            "APP_KEY switched": lambda mysql: mysql["switch"].update(APP_KEY="base64:abc"),
            "DB_SOCKET not switched": lambda mysql: mysql["switch"].pop("DB_SOCKET"),
            "another secret": lambda mysql: mysql["switch_secrets"].update(APP_KEY="/etc/repro-cutover-fence/key"),
            "no app password": lambda mysql: mysql["switch_secrets"].clear(),
            "the password in host.json": lambda mysql: (mysql["switch_secrets"].clear(),
                                                        mysql["switch"].update(DB_PASSWORD="placeholder")),
            "a night flag that is switched": lambda mysql: mysql["night_flags"].append("DB_SOCKET"),
            "an engine check that is not mysql": lambda mysql: mysql["switch"].update(DB_EXPECTED_ENGINE="sqlite"),
        }
        for name, change in cases.items():
            with self.subTest(name):
                config = switch(change)
                with self.assertRaisesRegex(guard.Refused, "switch"):
                    load(config, self.identity_for(config))

    def test_switch_values_must_match_the_mysql_target(self):
        for key, value in (("DB_CONNECTION", "sqlite"), ("DB_DATABASE", "repro_mysql_control"),
                           ("DB_SOCKET", "/run/mysqld/mysqld.sock"), ("DB_USERNAME", "repro_maint"),
                           ("REPRO_MYSQL_EXPECTED_SERVER_UUID", "0b6d3c52-7f43-4a3e-8f3f-3a6c1d2e4f50")):
            with self.subTest(key=key):
                config = example_config()
                config["mysql"]["switch"][key] = value
                with self.assertRaisesRegex(guard.Refused, "switch values must match the mysql target"):
                    load(config)

    def test_offsite_waiver_refused_outside_drill_mode(self):
        config = example_config()
        config["mysql"]["offsite_waiver"] = True
        self.assertTrue(load(config)["mysql"]["offsite_waiver"], "the drill may waive the off-site evidence")
        config["mysql"]["mode"] = "cutover"
        with self.assertRaisesRegex(guard.Refused, "off-site waiver is allowed only in drill mode"):
            load(config)
        config["mysql"]["offsite_waiver"] = False
        self.assertEqual("cutover", load(config)["mysql"]["mode"])

    def test_queue_commands_must_run_as_www_data_with_umask_0022(self):
        # The pause flags live in www-data's 0755 cache folders; the watchdog runs with UMask=0077, and a
        # 0600 flag the deploy user's workers cannot read counts as "not paused" (design 5.1).
        for verb in ("pause", "resume"):
            php = f'exec /usr/bin/php artisan queue:{verb} "$1"'
            other = "resume" if verb == "pause" else "pause"
            accepted = [queue_command(verb), queue_command(verb, script=f"umask 0022 && cd /var/www/backend && {php}")]
            refused = {
                "as the deploy user": queue_command(verb, user="maverick"),
                "as root": ["/bin/sh", "-c", f"cd /var/www/backend && umask 0022 && {php}", "repro-queue"],
                "through sudo": ["/usr/bin/sudo", "-u", "www-data", "--", "/bin/sh", "-c",
                                 f"umask 0022 && {php}", "repro-queue"],
                "another runuser path": ["/sbin/runuser", *queue_command(verb)[1:]],
                "without a shell": ["/usr/sbin/runuser", "-u", "www-data", "--", "/usr/bin/php",
                                    "/var/www/backend/artisan", f"queue:{verb}"],
                "no umask": queue_command(verb, script=f"cd /var/www/backend && {php}"),
                "the watchdog's umask": queue_command(verb, script=f"umask 0077 && {php}"),
                "umask 0002": queue_command(verb, script=f"umask 0002 && {php}"),
                "umask after the command": queue_command(verb, script=f"{php} && umask 0022"),
                "umask overridden": queue_command(verb, script=f"umask 0022 && umask 0077 && {php}"),
                "umask overridden in one step": queue_command(verb, script=f"umask 0022; umask 0077 && {php}"),
                "umask in a subshell": queue_command(verb, script=f"(umask 0022) && {php}"),
                # A run before the umask would leave a 0600 flag that later runs do not re-mode.
                "a command before the umask": queue_command(
                    verb, script=f'/usr/bin/php /var/www/backend/artisan queue:{verb} "$1" && umask 0022 && {php}'),
                "a command inside cd": queue_command(verb, script=f"cd $(/usr/bin/mktemp -d) && umask 0022 && {php}"),
                "a relative cd": queue_command(verb, script=f"cd backend && umask 0022 && {php}"),
                "not exec'd": queue_command(verb, script=f'umask 0022 && /usr/bin/php artisan queue:{verb} "$1"'),
                "a umask inside the command": queue_command(
                    verb, script=f"umask 0022 && exec /usr/bin/systemd-run --property=UMask=0077 --wait --pipe "
                                 f'/usr/bin/php /var/www/backend/artisan queue:{verb} "$1"'),
                "the other queue verb": queue_command(verb, script=f'umask 0022 && exec /usr/bin/php artisan '
                                                                   f'queue:{other} "$1"'),
                "the queue not passed as $1": queue_command(verb) + ["extra"],
                "the queue not used": queue_command(verb, script=f"umask 0022 && exec /usr/bin/php artisan "
                                                                 f"queue:{verb} database:default"),
            }
            for argv in accepted:
                config = example_config()
                config["commands"][f"queue_{verb}"] = argv
                self.assertEqual(argv, load(config)["commands"][f"queue_{verb}"])
            for name, argv in refused.items():
                with self.subTest(verb=verb, case=name):
                    config = example_config()
                    config["commands"][f"queue_{verb}"] = argv
                    with self.assertRaisesRegex(guard.Refused, "must run as www-data through runuser with umask 0022"):
                        load(config)

    def test_identity_config_must_describe_this_host(self):
        config = example_config()
        for key, value in (("app_root", "/var/www/backend-next"),
                           ("sqlite_path", "/var/www/backend/database/copy.sqlite"),
                           ("mysql_datadir", "/var/lib/mysql")):
            with self.subTest(key=key):
                with self.assertRaisesRegex(guard.Refused, "identity.json must describe this host"):
                    load(config, {**example_identity(config), key: value})
        # The denylist is checked in the file the identity probe reads, not in another copy.
        for name, argv in (("another file", PYTHON + [f"{FENCE}/identity_probe.py", "--config", "/etc/other.json"]),
                           ("no config argument", PYTHON + [f"{FENCE}/identity_probe.py"])):
            with self.subTest(name):
                changed = example_config()
                changed["commands"]["identity"] = argv
                with self.assertRaisesRegex(guard.Refused, "identity probe must read paths.identity_config"):
                    load(changed)

    def test_identity_example_file_agrees_with_the_example_host_config(self):
        raw = (Path(__file__).resolve().parent / "identity.example.json").read_bytes()
        identity = production.load_identity_config(raw)
        config = example_config()
        self.assertEqual(config, load(config, identity))
        self.assertEqual(sorted(NIGHT_FLAGS + SWITCH_KEYS), sorted(identity["env_denylist"]))


@unittest.skipUnless(sys.platform == "linux" and os.geteuid() == 0, "Requires Linux root, /proc and flock")
class ProductionAdapterTests(unittest.TestCase):
    def setUp(self):
        # Protected-file checks reject world-writable parents such as /tmp.
        temporary = tempfile.TemporaryDirectory(prefix="repro-cutover-host-", dir="/var/lib")
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.root.chmod(0o755)
        self.tag = uuid.uuid4().hex[:12]
        for folder in ("state", "run", "ingress", "etc", "home", "database", "log"):
            (self.root / folder).mkdir(mode=0o755)
        (self.root / "state").chmod(0o700)
        (self.root / "run").chmod(0o700)
        os.chown(self.root / "database", 33, 33)
        os.chown(self.root / "home", 33, 33)
        self.database = self.root / "database" / "database.sqlite"
        subprocess.run(["/usr/bin/setpriv", "--reuid=33", "--regid=33", "--clear-groups", sys.executable, "-c",
                        "import sqlite3, sys; c = sqlite3.connect(sys.argv[1]); c.execute('PRAGMA journal_mode=WAL');"
                        "c.execute('CREATE TABLE t (v)'); c.commit()", str(self.database)], check=True)
        (self.root / "machine-id").write_bytes(b"host fixture machine\n")
        self.boot = self.root / "boot-id"
        self.boot.write_text("2fb9e4be-f041-45a0-9055-8d3ed47f9857\n", encoding="ascii")
        self.log = self.root / "log" / "commands.jsonl"
        # Queue commands run as www-data (runuser); every recorder appends to the same log.
        self.log.touch(mode=0o644)
        os.chown(self.log, 33, 33)
        self.queue_recorder = self.root / "etc" / "record-queue.py"
        self.queue_recorder.write_text(
            "import json, sys\nlog, name, verb, queue = sys.argv[1:]\n"
            "with open(log, 'a') as stream:\n    stream.write(json.dumps([name, queue]) + '\\n')\n", encoding="ascii")
        self.config_path = self.root / "etc" / "host.json"
        self.config_path.write_text(json.dumps(self.config()), encoding="utf-8")
        (self.root / "etc" / "identity.json").write_text(json.dumps(example_identity(self.config())), encoding="utf-8")
        policy = {
            "version": 1, "machine_id_sha256": guard.sha256((self.root / "machine-id").read_bytes()),
            "identity": {**IDENTITY.__dict__}, "sqlite_live_config_sha256": "1" * 64, "mysql_live_config_sha256": "2" * 64,
        }
        (self.root / "etc" / "policy.json").write_text(json.dumps(policy, sort_keys=True), encoding="utf-8")
        (self.root / "ingress" / "open").write_text("{}", encoding="ascii")
        self.workers = []

    def record(self, name):
        return [sys.executable, "-c",
                "import json, sys; open(sys.argv[1], 'a').write(json.dumps(sys.argv[2:]) + '\\n')", str(self.log), name]

    def record_queue(self, verb, name):
        """The configured queue prefix form: www-data through runuser, umask 0022, the queue as `$1`."""
        return queue_command(verb, script=f'umask 0022 && exec {sys.executable} -I -S {self.queue_recorder} '
                                          f'{self.log} {name} queue:{verb} "$1"')

    def config(self, **overrides):
        root = self.root
        config = example_config()
        # The host's own paths under this fixture; the MySQL-lane paths are not used by these tests.
        config["paths"] = {
            **{name: str(root / "state" / Path(value).name) for name, value in config["paths"].items()},
            "state": str(root / "state"), "policy": str(root / "etc" / "policy.json"),
            "evidence": str(root / "run" / "verified-engine.json"),
            "activation": str(root / "run" / "verified-activation.json"),
            "machine_id": str(root / "machine-id"), "boot_id": str(self.boot),
            "ingress_open": str(root / "ingress" / "open"), "deploy_marker": str(root / "etc" / "active"),
            "scheduler_hold": str(root / "run" / "scheduler-hold.json"),
            "sqlite_seal_record": str(root / "state" / "sqlite-seal.json"),
            "identity_config": str(root / "etc" / "identity.json"),
        }
        identity_probe = [sys.executable, "-c", f"print({json.dumps(json.dumps(IDENTITY.__dict__))})"]
        config.update({
            "sqlite": str(self.database),
            "deploy_locks": [str(root / "home" / ".repro-backend-deploy.lock"),
                             str(root / "home" / ".repro-frontend-deploy.lock")],
            "deploy_owner": "www-data",
            "queues": QUEUES,
            "writer_processes": [f"fake-queue-worker-{self.tag}", f"fake-studio-worker-{self.tag}"],
            "long_job_processes": [f"fake-studio-worker-{self.tag}"],
            "scheduler_processes": [f"fake-schedule-run-{self.tag}"],
            "command_seconds": 20,
            "options": {"max_drain_seconds": 5, "separate_freeze_approval": False, "adopt_external_hold": False,
                        "require_long_job_predrain": True, "unattended_reopen": False},
        })
        config["commands"].update({
            # The probe reads the identity config the host config names (python -c ignores the extra arguments).
            "identity": identity_probe + ["--config", config["paths"]["identity_config"]],
            "mysql": [sys.executable, "-c", "print('{\"writable\": false, \"business_activity\": false}')"],
            "queue_pause": self.record_queue("pause", "pause"), "queue_resume": self.record_queue("resume", "resume"),
            # '[f]ake' never matches this shell's own command line.
            "stop_workers": [["/bin/sh", "-c", f"/usr/bin/pkill -f '[f]ake-queue-worker-{self.tag}' || true"],
                             self.record("stop")],
            "start_workers": [self.record("start")],
            "drain_fpm": [self.record("drain-fpm")],
        })
        return {**config, **overrides}

    def spawn(self, name, seconds=30):
        process = subprocess.Popen([f"{name}-{self.tag}", str(seconds)], executable="/bin/sleep")
        self.workers.append(process)
        self.addCleanup(lambda: process.poll() is None and process.kill())
        time.sleep(0.1)
        return process

    def commands(self):
        if not self.log.exists():
            return []
        return [json.loads(line) for line in self.log.read_text().splitlines()]

    def host(self):
        loaded = production.load_config(guard.read_protected(self.config_path), read=guard.read_protected)
        journal = Journal(Path(loaded["paths"]["state"]))
        adapter = production.ProductionAdapter(loaded, journal)
        return journal, adapter, production.runner_for(loaded, journal, adapter)

    def started(self):
        journal, adapter, runner = self.host()
        runner.initialize()
        return journal, adapter, runner

    def test_hold_closes_ingress_publishes_the_deploy_fence_and_pauses_every_queue(self):
        journal, adapter, runner = self.started()
        worker = self.spawn("fake-queue-worker")
        state = runner.perform("hold", runner.approval_request("hold"))
        self.assertEqual("holding", state["phase"])
        self.assertFalse((self.root / "ingress" / "open").exists())
        self.assertTrue(deploy_marker.published(self.root / "etc" / "active", IDENTITY.run_id))
        self.assertEqual([["pause", queue] for queue in QUEUES], self.commands())
        observed = adapter.observe()
        self.assertTrue(observed.held())
        self.assertFalse(observed.workers_stopped)
        self.assertIsNone(worker.poll(), "The hold must not stop workers; the drain does")
        with self.assertRaisesRegex(guard.Refused, "holds all admissions"):
            guard.authority(json.loads((self.root / "etc" / "policy.json").read_text()), state, "scheduler")

    def test_queue_flags_are_www_data_0644_when_the_controller_runs_with_umask_0077(self):
        # The watchdog unit runs with UMask=0077; runuser keeps it, so only the prefix's own umask gives the
        # deploy user's workers a readable flag (an unreadable flag reads as "not paused").
        flags = self.root / "flags"
        flags.mkdir(mode=0o755)
        os.chown(flags, 33, 33)
        touch = self.root / "etc" / "touch-flag.py"
        touch.write_text("import os, sys\nflags, verb, queue = sys.argv[1:]\n"
                         "open(os.path.join(flags, verb + '-' + queue), 'w').close()\n", encoding="ascii")
        config = self.config()
        for verb in ("pause", "resume"):
            config["commands"][f"queue_{verb}"] = queue_command(
                verb, script=f'umask 0022 && exec {sys.executable} -I -S {touch} {flags} queue:{verb} "$1"')
        self.config_path.write_text(json.dumps(config), encoding="utf-8")
        previous = os.umask(0o077)
        try:
            journal, adapter, runner = self.started()
            runner.perform("hold", runner.approval_request("hold"))
        finally:
            os.umask(previous)
        for queue in QUEUES:
            info = (flags / f"queue:pause-{queue}").stat()
            self.assertEqual((33, 33, 0o644), (info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode)), queue)

    def test_long_job_worker_still_running_refuses_the_hold(self):
        journal, adapter, runner = self.started()
        self.spawn("fake-studio-worker")
        original = journal.path.read_bytes()
        with self.assertRaisesRegex(UnsafeCutover, "Long-job queues"):
            runner.perform("hold", runner.approval_request("hold"))
        self.assertEqual(original, journal.path.read_bytes())
        self.assertTrue((self.root / "ingress" / "open").exists())

    def test_freeze_stops_workers_waits_for_the_scheduler_and_seals_sqlite(self):
        journal, adapter, runner = self.started()
        worker = self.spawn("fake-queue-worker")
        scheduler = self.spawn("fake-schedule-run", seconds=1.5)
        runner.perform("hold", runner.approval_request("hold"))
        state = runner.perform("freeze")
        self.assertEqual("frozen", state["phase"])
        self.assertIsNotNone(worker.poll())
        self.assertIsNotNone(scheduler.poll(), "The scheduler drain returned while a scheduled command ran")
        self.assertEqual(0, state["scheduler_drain"]["unresolved_permits"])
        self.assertFalse(sqlite_seal.writable(self.database))
        self.assertTrue(adapter.observe().frozen())
        self.assertIn(["drain-fpm"], self.commands())

    def test_writer_that_never_stops_keeps_the_hold_and_the_watchdog_rolls_back(self):
        config = self.config()
        config["commands"]["stop_workers"] = [self.record("stop")]  # The stop does not reach this worker.
        self.config_path.write_text(json.dumps(config), encoding="utf-8")
        journal, adapter, runner = self.started()
        worker = self.spawn("fake-queue-worker")
        held = runner.perform("hold", runner.approval_request("hold"))
        with self.assertRaisesRegex(UnsafeCutover, "still running"):
            runner.perform("freeze")
        self.assertEqual(("holding", "freeze"), (journal.read()["phase"], journal.read()["pending"]["action"]))
        self.assertTrue(sqlite_seal.writable(self.database))
        worker.kill()
        worker.wait()
        late = Runner(journal, IDENTITY, adapter, lambda: held["started_at"] + Runner.DECISION_SECONDS)
        self.assertEqual("sqlite_authoritative", late.perform("tick")["phase"])

    def test_abort_publishes_sqlite_authority_and_reopen_restores_admissions_in_order(self):
        journal, adapter, runner = self.started()
        runner.perform("hold", runner.approval_request("hold"))
        runner.perform("freeze")
        state = runner.perform("abort")
        self.assertEqual("sqlite_authoritative", state["phase"])
        self.assertTrue(sqlite_seal.writable(self.database))
        self.assertFalse((self.root / "ingress" / "open").exists())
        policy = (self.root / "etc" / "policy.json").read_bytes()
        evidence = (self.root / "run" / "verified-engine.json").read_bytes()
        host = {"machine_sha": guard.sha256((self.root / "machine-id").read_bytes()),
                "boot_id": self.boot.read_text().strip()}
        guard.verify(policy, journal.path.read_bytes(), evidence, **host, action="queue-worker", expected_engine="sqlite")
        with self.assertRaisesRegex(guard.Refused, "not authoritative"):
            guard.verify(policy, journal.path.read_bytes(), evidence, **host, action="queue-worker",
                         expected_engine="mysql")

        before = len(self.commands())
        adapter.reopen()
        self.assertTrue((self.root / "ingress" / "open").exists())
        self.assertEqual([["resume", queue] for queue in QUEUES] + [["start"]], self.commands()[before:])
        self.assertFalse((self.root / "etc" / "active").exists())
        activation = (self.root / "run" / "verified-activation.json").read_bytes()
        guard.verify(policy, journal.path.read_bytes(), evidence, **host, action="deploy", expected_engine="sqlite",
                     activation_raw=activation)
        self.assertTrue(adapter.observe().sqlite_live())

    def admitted_worker(self, name):
        """A started worker whose wrapper keeps its shared writer lease for its whole life."""
        code = ("import fcntl, sys, time\nlease = open(sys.argv[1], 'rb')\nfcntl.flock(lease, fcntl.LOCK_SH)\n"
                "print('admitted', flush=True)\ntime.sleep(60)\n")
        process = subprocess.Popen([sys.executable, "-c", code, str(self.root / "state" / "operator.lock"),
                                    f"{name}-{self.tag}"], stdout=subprocess.PIPE, text=True)
        self.addCleanup(lambda: process.poll() is None and process.kill())
        self.addCleanup(process.stdout.close)
        self.assertEqual("admitted\n", process.stdout.readline())
        return process

    def test_reopen_retry_stops_workers_that_a_failed_reopen_started(self):
        blocker = self.root / "etc" / "second-group-fails"
        blocker.write_text("", encoding="ascii")
        config = self.config(command_seconds=3)
        config["commands"]["start_workers"] = [self.record("start"), ["/bin/sh", "-c", f"! test -e {blocker}"]]
        self.config_path.write_text(json.dumps(config), encoding="utf-8")
        journal, adapter, runner = self.started()
        runner.perform("hold", runner.approval_request("hold"))
        runner.perform("freeze")
        runner.perform("abort")
        with self.assertRaisesRegex(UnsafeCutover, "Host command failed"):
            adapter.reopen()
        # The first group started before the second failed; its wrapper keeps the shared lease.
        worker = self.admitted_worker("fake-queue-worker")
        self.assertFalse((self.root / "ingress" / "open").exists())
        blocker.unlink()

        before = len(self.commands())
        adapter.reopen()
        self.assertIsNotNone(worker.poll(), "The retry did not stop the worker the failed reopen started")
        self.assertEqual([["stop"]] + [["resume", queue] for queue in QUEUES] + [["start"]], self.commands()[before:])
        self.assertTrue((self.root / "ingress" / "open").exists())
        self.assertTrue((self.root / "run" / "verified-activation.json").exists())
        self.assertFalse((self.root / "etc" / "active").exists())
        self.assertTrue(adapter.observe().sqlite_live())

    def mysql_probe_from_file(self, config):
        """The MySQL probe reads its answer from a file, so a test can change the live target."""
        probe = self.root / "etc" / "mysql-probe.json"
        probe.write_text('{"writable": false, "business_activity": false}', encoding="ascii")
        config["commands"]["mysql"] = [sys.executable, "-c", "import sys; print(open(sys.argv[1]).read())",
                                       str(probe)]
        return probe

    def test_reopen_refuses_when_mysql_became_writable_after_the_proof(self):
        config = self.config()
        probe = self.mysql_probe_from_file(config)
        self.config_path.write_text(json.dumps(config), encoding="utf-8")
        journal, adapter, runner = self.started()
        runner.perform("hold", runner.approval_request("hold"))
        runner.perform("freeze")
        runner.perform("abort")
        # The proof was measured with MySQL read-only; the target changes afterwards.
        probe.write_text('{"writable": true, "business_activity": false}', encoding="ascii")
        before = len(self.commands())
        with self.assertRaisesRegex(UnsafeCutover, "only writable engine"):
            adapter.reopen()
        self.assertFalse((self.root / "ingress" / "open").exists(), "reopen opened ingress with MySQL writable")
        self.assertEqual([], self.commands()[before:], "reopen resumed queues or started workers")
        self.assertTrue(deploy_marker.published(self.root / "etc" / "active", IDENTITY.run_id))
        self.assertFalse((self.root / "run" / "verified-activation.json").exists())

    def test_reopen_closes_ingress_again_when_the_live_check_fails_after_opening(self):
        config = self.config()
        probe = self.mysql_probe_from_file(config)
        # MySQL turns writable while the workers start, after the pre-check.
        config["commands"]["start_workers"] = [self.record("start"), [
            sys.executable, "-c", "import sys; open(sys.argv[1], 'w').write(sys.argv[2])", str(probe),
            '{"writable": true, "business_activity": false}']]
        self.config_path.write_text(json.dumps(config), encoding="utf-8")
        journal, adapter, runner = self.started()
        runner.perform("hold", runner.approval_request("hold"))
        runner.perform("freeze")
        runner.perform("abort")
        before = len(self.commands())
        with self.assertRaisesRegex(UnsafeCutover, "could not be verified after reopening"):
            adapter.reopen()
        self.assertFalse((self.root / "ingress" / "open").exists(), "a failed reopen left ingress open")
        self.assertEqual([["resume", queue] for queue in QUEUES] + [["start"]]
                         + [["pause", queue] for queue in QUEUES] + [["stop"]], self.commands()[before:])
        self.assertTrue(deploy_marker.published(self.root / "etc" / "active", IDENTITY.run_id))
        self.assertFalse((self.root / "run" / "verified-activation.json").exists())

    def test_reboot_during_hold_reasserts_the_closed_route_under_the_new_boot(self):
        journal, adapter, runner = self.started()
        runner.perform("hold", runner.approval_request("hold"))
        # The deploy marker lives in /etc and survives; /run starts empty after a reboot.
        (self.root / "etc" / "active").unlink()
        self.boot.write_text("6c0b8a7e-3a43-4c55-9b7e-0d6f1d2b9c11\n", encoding="ascii")
        before = len(self.commands())
        journal, adapter, runner = self.host()
        state = runner.perform("tick")
        self.assertEqual("6c0b8a7e-3a43-4c55-9b7e-0d6f1d2b9c11", state["hold"]["boot_id"])
        self.assertEqual("reassert_hold", state["history"][-1]["action"])
        self.assertTrue(deploy_marker.published(self.root / "etc" / "active", IDENTITY.run_id))
        self.assertEqual([["pause", queue] for queue in QUEUES], self.commands()[before:])

    def test_target_steps_are_refused_by_this_adapter(self):
        journal, adapter, runner = self.started()
        runner.perform("hold", runner.approval_request("hold"))
        runner.perform("freeze")
        with self.assertRaisesRegex(UnsafeCutover, "SQLite hold, freeze and rollback only"):
            runner.perform("prepare")
        self.assertEqual("sqlite_authoritative", runner.perform("abort")["phase"])

    def guard_paths(self):
        # The installed guard's fixed paths, pointed at this fixture's root-owned tree.
        paths = self.config()["paths"]
        return patch.multiple(
            guard, STATE=Path(paths["state"]), POLICY=Path(paths["policy"]), EVIDENCE=Path(paths["evidence"]),
            ACTIVATION=Path(paths["activation"]), MACHINE_ID=Path(paths["machine_id"]), BOOT_ID=self.boot,
            _require_root_installation=lambda: None,
        )

    def marker_paths(self):
        # The nginx hold and Deploy-App read fixed marker paths; point them at this fixture.
        paths = self.config()["paths"]
        return patch.multiple(cli, INGRESS_OPEN=Path(paths["ingress_open"]), DEPLOY_MARKER=Path(paths["deploy_marker"]))

    def cli(self, *argv):
        output = io.StringIO()
        with self.guard_paths(), self.marker_paths(), redirect_stdout(output):
            code = cli.main(["--config", str(self.config_path), *argv])
        return code, json.loads(output.getvalue()) if output.getvalue().strip() else None

    def test_cli_refuses_marker_paths_that_nginx_and_deploys_do_not_read(self):
        journal, _, runner = self.started()
        approval = self.root / "state" / "hold-approval.json"
        approval.write_text(json.dumps(runner.approval_request("hold").__dict__), encoding="utf-8")
        approval.chmod(0o600)
        # A host config that names another file would close nothing nginx or Deploy-App reads.
        for constant in ("INGRESS_OPEN", "DEPLOY_MARKER"):
            with self.subTest(constant=constant):
                errors = io.StringIO()
                with self.guard_paths(), self.marker_paths(), patch.object(cli, constant, self.root / "elsewhere"), \
                        redirect_stdout(io.StringIO()), patch("sys.stderr", errors):
                    code = cli.main(["--config", str(self.config_path), "hold", "--approval", str(approval)])
                self.assertEqual("planned", journal.read()["phase"])
                self.assertEqual(1, code)
                self.assertIn("marker paths", errors.getvalue())
        self.assertTrue((self.root / "ingress" / "open").exists())

    def test_reboot_in_planned_holds_writers_until_refresh_and_reopen(self):
        journal, adapter, runner = self.started()
        # A reboot empties /run: ingress is closed and no authority proof exists for the new boot.
        (self.root / "ingress" / "open").unlink()
        self.boot.write_text("6c0b8a7e-3a43-4c55-9b7e-0d6f1d2b9c11\n", encoding="ascii")
        journal, adapter, runner = self.host()
        with self.assertRaises((UnsafeCutover, FileNotFoundError)):
            adapter.reopen()
        self.assertFalse((self.root / "ingress" / "open").exists())

        code, refreshed = self.cli("refresh")
        self.assertEqual(0, code)
        self.assertEqual("planned", refreshed["state"]["phase"])
        evidence = json.loads((self.root / "run" / "verified-engine.json").read_text())
        self.assertEqual(("6c0b8a7e-3a43-4c55-9b7e-0d6f1d2b9c11", guard.sha256(journal.path.read_bytes())),
                         (evidence["boot_id"], evidence["journal_sha256"]))
        before = len(self.commands())
        self.assertEqual(0, self.cli("reopen")[0])
        self.assertTrue((self.root / "ingress" / "open").exists())
        self.assertEqual([["resume", queue] for queue in QUEUES] + [["start"]], self.commands()[before:])
        self.assertFalse((self.root / "run" / "verified-activation.json").exists())
        policy = (self.root / "etc" / "policy.json").read_bytes()
        host = {"machine_sha": guard.sha256((self.root / "machine-id").read_bytes()),
                "boot_id": "6c0b8a7e-3a43-4c55-9b7e-0d6f1d2b9c11"}
        guard.verify(policy, journal.path.read_bytes(), json.dumps(evidence).encode(), **host,
                     action="scheduler", expected_engine="sqlite")
        with self.assertRaisesRegex(guard.Refused, "Deploys are held"):
            guard.verify(policy, journal.path.read_bytes(), json.dumps(evidence).encode(), **host,
                         action="deploy", expected_engine="sqlite")

    def test_reboot_after_reopened_rollback_ticks_refreshes_and_reopens(self):
        journal, adapter, runner = self.started()
        runner.perform("hold", runner.approval_request("hold"))
        runner.perform("freeze")
        runner.perform("abort")
        adapter.reopen()
        self.assertFalse((self.root / "etc" / "active").exists())
        # A reboot empties /run: no open marker, no authority proof, no activation receipt.
        (self.root / "ingress" / "open").unlink()
        for record in (self.root / "run").iterdir():
            record.unlink()
        rebooted = "6c0b8a7e-3a43-4c55-9b7e-0d6f1d2b9c11"
        self.boot.write_text(rebooted + "\n", encoding="ascii")

        code, ticked = self.cli("tick")
        self.assertEqual(0, code)
        self.assertEqual(("sqlite_authoritative", False), (ticked["state"]["phase"], ticked["state"]["writes_possible"]))
        code, refreshed = self.cli("refresh")
        self.assertEqual(0, code)
        self.assertEqual("sqlite_authoritative", refreshed["state"]["phase"])
        self.assertTrue(deploy_marker.published(self.root / "etc" / "active", IDENTITY.run_id))
        self.assertEqual(0, self.cli("reopen")[0])
        self.assertTrue((self.root / "ingress" / "open").exists())
        self.assertFalse((self.root / "etc" / "active").exists())
        policy = (self.root / "etc" / "policy.json").read_bytes()
        evidence = (self.root / "run" / "verified-engine.json").read_bytes()
        activation = (self.root / "run" / "verified-activation.json").read_bytes()
        guard.verify(policy, journal.path.read_bytes(), evidence,
                     machine_sha=guard.sha256((self.root / "machine-id").read_bytes()), boot_id=rebooted,
                     action="deploy", expected_engine="sqlite", activation_raw=activation)
        self.assertEqual("sqlite_authoritative", journal.read()["phase"])

    def test_planned_journal_holds_deploys_with_the_marker_deploy_app_checks(self):
        # Deploy-App checks only /etc/repro-database-cutover/active, never the guard. The guard
        # holds deploys for a planned cutover, so the marker must really be there.
        marker = self.root / "etc" / "active"
        self.assertEqual(0, self.cli("init")[0])
        self.assertTrue(deploy_marker.published(marker, IDENTITY.run_id), "init left deployments open")
        # Lost marker (for example removed by hand), then a reboot.
        marker.unlink()
        (self.root / "ingress" / "open").unlink()
        self.boot.write_text("6c0b8a7e-3a43-4c55-9b7e-0d6f1d2b9c11\n", encoding="ascii")
        journal, adapter, _ = self.host()
        self.assertFalse(adapter.host_authority("sqlite").deployment_fenced,
                         "The host probe reported deploys fenced from the journal, not from the marker")
        self.assertEqual(0, self.cli("refresh")[0])
        self.assertTrue(deploy_marker.published(marker, IDENTITY.run_id), "refresh measured an unfenced host")
        self.assertEqual(0, self.cli("reopen")[0])
        self.assertTrue((self.root / "ingress" / "open").exists())
        self.assertTrue(deploy_marker.published(marker, IDENTITY.run_id), "reopen in planned lifted the deploy fence")

    def test_init_refused_by_a_busy_deploy_lock_is_retried_until_deploys_are_fenced(self):
        # A deploy that keeps its lock past command_seconds makes the fence publication fail.
        # Ingress is open (refresh refuses) and a planned tick changes nothing, so init itself
        # must stay retryable and fence deploys once the deploy ends.
        import fcntl

        self.config_path.write_text(json.dumps(self.config(command_seconds=1)), encoding="utf-8")
        marker = self.root / "etc" / "active"
        errors = io.StringIO()
        with open(self.root / "home" / ".repro-backend-deploy.lock", "a+b") as deploying, patch("sys.stderr", errors):
            os.fchown(deploying.fileno(), 33, 33)
            fcntl.flock(deploying, fcntl.LOCK_EX)
            self.assertEqual(1, self.cli("init")[0])
        self.assertIn("deployment holds its lock", errors.getvalue())
        self.assertFalse(deploy_marker.published(marker, IDENTITY.run_id))

        errors = io.StringIO()
        with patch("sys.stderr", errors):
            code, initialized = self.cli("init")
        self.assertEqual(0, code, errors.getvalue())
        self.assertEqual("planned", initialized["state"]["phase"])
        self.assertTrue(deploy_marker.published(marker, IDENTITY.run_id), "init left deployments open")
        # A committed run is never initialized again.
        journal = Journal(self.root / "state")
        committed = journal.path.read_bytes()
        with patch("sys.stderr", io.StringIO()):
            self.assertEqual(1, self.cli("init")[0])
        self.assertEqual(committed, journal.path.read_bytes())

    def reboot_without_runtime_directories(self):
        """A real reboot mounts a fresh /run: the runtime directories are gone, not only their files."""
        shutil.rmtree(self.root / "run")
        shutil.rmtree(self.root / "ingress")
        self.boot.write_text("6c0b8a7e-3a43-4c55-9b7e-0d6f1d2b9c11\n", encoding="ascii")

    def assert_runtime_directories(self):
        for folder in ("run", "ingress"):
            info = (self.root / folder).lstat()
            self.assertTrue(stat.S_ISDIR(info.st_mode))
            # Root-owned and not writable by others (guard chain); nginx workers must traverse the ingress one.
            self.assertEqual((0, 0o755), (info.st_uid, stat.S_IMODE(info.st_mode)), folder)

    def test_refresh_and_reopen_recreate_the_runtime_directories_after_a_reboot(self):
        self.started()
        self.reboot_without_runtime_directories()
        errors = io.StringIO()
        with patch("sys.stderr", errors):
            code, refreshed = self.cli("refresh")
        self.assertEqual(0, code, errors.getvalue())
        self.assertEqual("planned", refreshed["state"]["phase"])
        self.assertEqual(0, self.cli("reopen")[0])
        self.assertTrue((self.root / "ingress" / "open").exists())
        self.assert_runtime_directories()

    def test_watchdog_rolls_back_after_a_reboot_removed_the_runtime_directories(self):
        journal, adapter, runner = self.started()
        held = runner.perform("hold", runner.approval_request("hold"))
        self.reboot_without_runtime_directories()
        journal, adapter, _ = self.host()
        late = Runner(journal, IDENTITY, adapter, lambda: held["started_at"] + Runner.DECISION_SECONDS)
        self.assertEqual("sqlite_authoritative", late.perform("tick")["phase"])
        self.assertTrue((self.root / "run" / "verified-engine.json").exists())
        self.assertFalse((self.root / "ingress" / "open").exists())

    def test_refresh_racing_a_reopen_neither_fences_deploys_nor_revokes_the_proof(self):
        # The reopen completes right after refresh's first ingress check.
        self.race_refresh_with_reopen(after_check=1)

    def test_reopen_racing_inside_the_refresh_locks_is_refused(self):
        # The reopen starts while refresh holds the guard's locks, after its second ingress check.
        self.assertFalse(self.race_refresh_with_reopen(after_check=2), "The reopen ran inside the refresh locks")

    def race_refresh_with_reopen(self, after_check):
        journal, adapter, runner = self.started()
        runner.perform("hold", runner.approval_request("hold"))
        runner.perform("freeze")
        runner.perform("abort")
        _, racer, _ = self.host()
        raced, checks = [], []

        class ReopenAfterCheck(type(Path())):
            def exists(self, *args, **kwargs):
                observed = super().exists(*args, **kwargs)
                checks.append(observed)
                if len(checks) == after_check:
                    # A second operator's reopen runs to completion right after this check.
                    try:
                        racer.reopen()
                        raced.append("reopened")
                    except UnsafeCutover as error:
                        raced.append(str(error))
                return observed

        adapter.paths["ingress_open"] = ReopenAfterCheck(adapter.paths["ingress_open"])
        with self.guard_paths():
            try:
                adapter.refresh()
            except (UnsafeCutover, guard.Refused):
                pass
        self.assertEqual(1, len(raced))
        marker = self.root / "etc" / "active"
        if (self.root / "ingress" / "open").exists():
            # The reopen won: the host is live, so refresh must leave it exactly as reopened.
            self.assertFalse(deploy_marker.published(marker, IDENTITY.run_id),
                             "refresh fenced deploys again on the reopened host")
            policy = (self.root / "etc" / "policy.json").read_bytes()
            evidence = (self.root / "run" / "verified-engine.json").read_bytes()
            activation = (self.root / "run" / "verified-activation.json").read_bytes()
            guard.verify(policy, journal.path.read_bytes(), evidence,
                         machine_sha=guard.sha256((self.root / "machine-id").read_bytes()),
                         boot_id=self.boot.read_text().strip(), action="deploy", expected_engine="sqlite",
                         activation_raw=activation)
        else:
            # The refresh won: the reopen was refused and the host stays measured and held.
            self.assertIn("Another host operator", raced[0])
            self.assertTrue(deploy_marker.published(marker, IDENTITY.run_id))
        return (self.root / "ingress" / "open").exists()

    def test_refresh_on_an_open_host_keeps_the_current_proof(self):
        self.started()
        (self.root / "ingress" / "open").unlink()
        self.assertEqual(0, self.cli("refresh")[0])
        self.assertEqual(0, self.cli("reopen")[0])
        evidence = (self.root / "run" / "verified-engine.json").read_bytes()
        # Live host: refresh must not revoke the proof that admits the scheduler and restarted workers.
        errors = io.StringIO()
        with patch("sys.stderr", errors):
            self.assertEqual(1, self.cli("refresh")[0])
        self.assertTrue((self.root / "run" / "verified-engine.json").exists(), "Refresh revoked the live proof")
        self.assertEqual(evidence, (self.root / "run" / "verified-engine.json").read_bytes())
        self.assertIn("Ingress is open", errors.getvalue())
        self.assertTrue((self.root / "ingress" / "open").exists())

    def test_cli_refuses_a_config_that_differs_from_the_installed_guard_paths(self):
        self.started()
        self.assertEqual(0, self.cli("status")[0])
        errors = io.StringIO()
        with redirect_stdout(io.StringIO()), patch("sys.stderr", errors):
            self.assertEqual(1, cli.main(["--config", str(self.config_path), "status"]))
        self.assertIn("installed guard paths", errors.getvalue())

    def test_cli_runs_status_hold_freeze_tick_and_abort_from_the_root_config(self):
        call = self.cli

        self.assertEqual("planned", call("init")[1]["state"]["phase"])
        code, template = call("approval-template", "--phase", "hold")
        approval = self.root / "state" / "hold-approval.json"
        approval.write_text(json.dumps(template), encoding="utf-8")
        approval.chmod(0o600)
        self.assertEqual("holding", call("hold", "--approval", str(approval))[1]["state"]["phase"])
        self.assertEqual("frozen", call("freeze")[1]["state"]["phase"])
        self.assertEqual("frozen", call("tick")[1]["state"]["phase"])
        self.assertEqual("sqlite_authoritative", call("abort")[1]["state"]["phase"])
        self.assertEqual(1, call("hold", "--approval", str(approval))[0])

    def test_cli_requires_a_protected_approval_file(self):
        journal, adapter, runner = self.started()
        approval = self.root / "state" / "hold-approval.json"
        approval.write_text(json.dumps(runner.approval_request("hold").__dict__), encoding="utf-8")
        approval.chmod(0o666)
        self.assertEqual(1, self.cli("hold", "--approval", str(approval))[0])
        self.assertEqual("planned", journal.read()["phase"])


@unittest.skipUnless(os.name == "posix", "Host config paths are POSIX absolute paths")
class HostConfigQueueTests(unittest.TestCase):
    """Each queue is one `connection:queue` argument appended to the queue pause and resume commands."""

    @staticmethod
    def config(queues):
        return {**example_config(), "queues": queues}

    def test_every_production_queue_and_the_lab_tour_queue_are_accepted(self):
        self.assertEqual(PRODUCTION_QUEUES, load(self.config(PRODUCTION_QUEUES))["queues"])

    def test_names_that_are_not_one_connection_and_one_queue_are_refused(self):
        # A leading hyphen would reach `queue:pause` as an option; a comma or a space would name
        # several queues or arguments; Laravel splits only the first colon.
        for queue in ("database", ":mail", "database:", "-database:mail", "--queue=x:mail", "1database:mail",
                      "Database:mail", "dataBase:mail", "database:Mail", "lab_tour:lab-tour", "database:mail,default",
                      "database:mail default", "database:mail:extra", "database:mail\n", "", 7):
            with self.subTest(queue=queue):
                with self.assertRaisesRegex(guard.Refused, "connection:queue names"):
                    load(self.config(["database:mail", queue]))


class UnitFileTests(unittest.TestCase):
    HERE = Path(__file__).resolve().parent

    @staticmethod
    def entries(path):
        values = {}
        for line in path.read_text(encoding="utf-8").splitlines():
            if line and not line.startswith(("#", ";", "[")) and "=" in line:
                key, value = line.split("=", 1)
                values.setdefault(key.strip(), []).append(value.strip())
        return values

    @staticmethod
    def seconds(span):
        units = {"": 1, "s": 1, "sec": 1, "m": 60, "min": 60, "h": 3600}
        parts = re.findall(r"(\d+)\s*([a-z]*)", span)
        if not parts or "".join(number + unit for number, unit in parts) != span.replace(" ", ""):
            raise ValueError(f"Unsupported systemd time span: {span}")
        return sum(int(number) * units[unit] for number, unit in parts)

    def test_watchdog_timer_ticks_every_minute_from_boot_as_root(self):
        service = self.entries(self.HERE / "systemd" / "repro-cutover-watchdog.service")
        timer = self.entries(self.HERE / "systemd" / "repro-cutover-watchdog.timer")
        self.assertEqual(["oneshot"], service["Type"])
        self.assertEqual(["/usr/bin/python3 -I -S /opt/repro-cutover-fence/production/cli.py tick"], service["ExecStart"])
        self.assertNotIn("User", service)
        self.assertEqual(["/var/lib/repro-cutover-fence/state.json"], service["ConditionPathExists"])
        # A wall-clock schedule keeps ticking even when the service's condition skipped an
        # earlier tick (no journal yet); OnUnitActiveSec counts from the unit's last activation.
        self.assertEqual(["minutely"], timer["OnCalendar"])
        self.assertNotIn("OnUnitActiveSec", timer)
        self.assertEqual(["30s"], timer["OnBootSec"])
        self.assertEqual(["timers.target"], timer["WantedBy"])

    def test_watchdog_service_outlasts_the_controller_wait_and_a_full_rollback(self):
        # A deadline tick runs the whole rollback (close, drain, unseal, publish). systemd must not
        # kill it inside the runner's own bounds: the controller-lock wait, the longest rollback
        # budget the runner grants, and one more maximal host command after that budget.
        service = self.entries(self.HERE / "systemd" / "repro-cutover-watchdog.service")
        budget = max(Runner.WINDOW_SECONDS - Runner.DECISION_SECONDS, Runner.ROLLBACK_SECONDS)
        required = Runner.CONTROLLER_WAIT_SECONDS + budget + production.MAX_COMMAND_SECONDS
        self.assertEqual(1, len(service["TimeoutStartSec"]))
        self.assertGreaterEqual(self.seconds(service["TimeoutStartSec"][0]), required)

    def test_every_boot_recreates_the_runtime_directories_of_the_guard_and_nginx(self):
        # /run is a tmpfs. The guard checks the evidence directory's chain, the controller writes
        # its records there, and nginx looks for the open marker in the ingress directory.
        config = self.HERE / "tmpfiles.d" / "repro-cutover-fence.conf"
        lines = [line.split() for line in config.read_text(encoding="ascii").splitlines()
                 if line.strip() and not line.startswith("#")]
        self.assertEqual({guard.EVIDENCE.parent.as_posix(), guard.ACTIVATION.parent.as_posix(),
                          cli.INGRESS_OPEN.parent.as_posix()}, {line[1] for line in lines})
        for line in lines:
            with self.subTest(path=line[1]):
                self.assertEqual(["d", line[1], "0755", "root", "root", "-"], line)

    def test_media_archive_drop_in_starts_the_worker_through_the_wrapper(self):
        drop_in = self.entries(self.HERE / "systemd" / "repro-media-archives.service.d" / "cutover-fence.conf")
        self.assertEqual(["", "+/usr/bin/python3 -I -S /opt/repro-cutover-fence/production/admit.py media-archives"],
                         drop_in["ExecStart"])
        self.assertEqual(["75"], drop_in["RestartPreventExitStatus"])
        profiles = json.loads((self.HERE / "wrappers.example.json").read_text(encoding="utf-8"))["profiles"]
        unit = self.entries(self.HERE.parents[1] / "deploy" / "repro-media-archives.service")
        media = profiles["media-archives"]
        self.assertEqual(unit["ExecStart"][0].split(), media["argv"])
        self.assertEqual((unit["User"][0], unit["Group"][0], unit["UMask"][0]), (media["user"], media["group"], media["umask"]))

    def test_cli_marker_paths_are_the_ones_nginx_and_deploys_read(self):
        hold = (self.HERE / "nginx" / "repro-cutover-hold.conf").read_text(encoding="ascii")
        self.assertIn(f"if (-f {cli.INGRESS_OPEN.as_posix()})", hold)
        deploy = (self.HERE.parents[1] / "deploy" / "Deploy-App.ps1").read_text(encoding="utf-8")
        self.assertEqual(2, deploy.count(f"[ -e {cli.DEPLOY_MARKER.as_posix()} ]"))

    def test_supervisor_restarts_a_worker_that_exits_normally(self):
        # Every deploy runs queue:restart, and --max-time ends a worker each hour: queue:work then
        # exits 0 and must start again through the wrapper. Only a held admission (75) stays down.
        program = self.entries(self.HERE / "supervisor" / "laravel-worker.conf.example")
        self.assertEqual(["unexpected"], program["autorestart"])
        self.assertEqual(["75"], program["exitcodes"])

    def test_every_installed_user_supervisor_program_has_a_wrapper_profile(self):
        installer = (self.HERE.parents[1] / "install-studio-runtime.sh").read_text(encoding="utf-8")
        scripts = set(re.findall(r"^command=\$runtime/(\S+)$", installer, re.MULTILINE))
        self.assertEqual({"worker.sh", "lab-tour-worker.sh"}, scripts)
        profiles = json.loads((self.HERE / "wrappers.example.json").read_text(encoding="utf-8"))["profiles"]
        by_script = {Path(profile["argv"][0]).name: name for name, profile in profiles.items()
                     if profile["argv"][0].startswith("/home/maverick/.local/share/repro-studio/")}
        sudoers = (self.HERE / "sudoers" / "repro-cutover-admit").read_text(encoding="utf-8")
        allowed = set(re.findall(r"admit\.py ([a-z0-9-]+)", sudoers))
        for script in sorted(scripts):
            with self.subTest(script=script):
                self.assertIn(script, by_script)
                self.assertEqual("queue-worker", profiles[by_script[script]]["action"])
                self.assertIn(by_script[script], allowed)

    @unittest.skipUnless(os.name == "posix", "The wrapper reads POSIX accounts")
    def test_every_example_profile_is_valid_and_every_cron_rule_has_a_profile(self):
        import admit

        raw = (self.HERE / "wrappers.example.json").read_bytes()
        names = json.loads(raw)["profiles"]
        with patch.object(admit.pwd, "getpwnam", return_value=type("Account", (), {"pw_uid": 1000})()), \
                patch.object(admit.grp, "getgrnam", return_value=type("Group", (), {"gr_gid": 33})()):
            for name in names:
                admit.load_profile(name, raw)
        rules = json.loads((self.HERE / "cron-rules.example.json").read_text(encoding="utf-8"))["rules"]
        self.assertTrue({rule["profile"] for rule in rules if "profile" in rule} <= set(names))
        supervisor = (self.HERE / "supervisor" / "laravel-worker.conf.example").read_text(encoding="utf-8")
        self.assertIn("admit.py laravel-worker", supervisor)


if __name__ == "__main__":
    unittest.main()
