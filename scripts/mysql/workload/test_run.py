"""Portable refusal/containment checks; no PHP, database or credentials needed."""
import importlib.util
import json
import os
from pathlib import Path
import stat
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location("mysql_workload_run", Path(__file__).with_name("run.py"))
run = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(run)


def fixture():
    return {"version": 1, "server_uuid": "11111111-2222-3333-4444-555555555555",
            "socket": "/run/repro-mysql84-native/mysql.sock", "database": run.DATABASE,
            "accounts": {role: {"username": username, "password": "synthetic-secret-" + role}
                         for role, username in run.ACCOUNTS.items()}}


class CredentialBoundaryTest(unittest.TestCase):
    def test_only_exact_dedicated_contract_is_accepted(self):
        value = fixture()
        self.assertEqual(value, run.credentials(json.dumps(value).encode()))
        changes = (("database", "repro_dashboard"), ("socket", "/run/../mysql.sock"),
                   ("socket", "/run/mysql.sock;dbname=live"), ("server_uuid", "unknown"),
                   ("version", True), ("extra", "ignored"))
        for key, replacement in changes:
            with self.subTest(key=key, replacement=replacement):
                value = fixture()
                value[key] = replacement
                with self.assertRaises(run.Refusal):
                    run.credentials(json.dumps(value).encode())

    def test_accounts_cannot_be_shared_admin_or_have_missing_credentials(self):
        for role in run.ACCOUNTS:
            value = fixture()
            value["accounts"][role]["username"] = "root"
            with self.assertRaises(run.Refusal):
                run.credentials(json.dumps(value).encode())
            value = fixture()
            value["accounts"][role]["password"] = ""
            with self.assertRaises(run.Refusal):
                run.credentials(json.dumps(value).encode())

    def test_duplicate_keys_and_unbounded_input_are_refused(self):
        raw = json.dumps(fixture()).replace('"version": 1', '"version": 1, "version": 1').encode()
        for data in (raw, b"x" * (run.MAX_INPUT + 1), b"", b"[]", b"null"):
            with self.subTest(size=len(data)), self.assertRaises(run.Refusal):
                run.credentials(data)

    def test_refusal_messages_do_not_echo_a_credential(self):
        value = fixture()
        secret = "DO-NOT-PRINT-THIS-SECRET\n"
        value["accounts"]["admin"]["password"] = secret
        with self.assertRaises(run.Refusal) as caught:
            run.credentials(json.dumps(value).encode())
        self.assertNotIn(secret, str(caught.exception))


class ProcessIsolationTest(unittest.TestCase):
    def test_environment_does_not_inherit_production_or_proxy_settings(self):
        with patch.dict(os.environ, {"APP_KEY": "production-secret", "DB_PASSWORD": "production-secret",
                                    "HTTP_PROXY": "http://proxy.invalid", "PHPRC": "/unsafe/php.ini",
                                    "AWS_SECRET_ACCESS_KEY": "production-secret", "APP_CONFIG_CACHE": "/var/www/cache.php"}):
            env = run.clean_environment(Path("/private/runtime"))
        for name in ("APP_KEY", "DB_PASSWORD", "HTTP_PROXY", "PHPRC", "AWS_SECRET_ACCESS_KEY"):
            self.assertNotIn(name, env)
        self.assertEqual("testing", env["APP_ENV"])
        self.assertEqual("false", env["SERVER_MONITOR_ENABLED"])
        for name in run.CACHE_NAMES:
            self.assertEqual(Path("/private/runtime/cache"), Path(env[name]).parent)

    def proc_fixture(self, root, *, interface="lo", route=False):
        (root / "net").mkdir()
        (root / "net/dev").write_text("header\nheader\n " + interface + ": 0 0 0\n")
        (root / "net/route").write_text("header\n" + ("eth0 default\n" if route else ""))

    def test_namespace_and_no_egress_route_must_both_be_proven(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self.proc_fixture(root)
            with patch.object(run.os, "readlink", side_effect=["net:[2]", "user:[4]"]), \
                 patch.object(run.socket, "socket") as sock:
                sock.return_value.__enter__.return_value.connect.side_effect = OSError("unreachable")
                result = run.namespace_check("net:[1]", "user:[3]", root)
            self.assertFalse(result["outbound_route_available"])

    def test_unchanged_namespace_host_interface_or_route_refuses(self):
        for label in ("namespace", "interface", "route", "udp"):
            with self.subTest(label=label), tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                self.proc_fixture(root, interface="eth0" if label == "interface" else "lo", route=label == "route")
                namespace = "net:[1]" if label == "namespace" else "net:[2]"
                with patch.object(run.os, "readlink", side_effect=[namespace, "user:[4]"]), \
                     patch.object(run.socket, "socket") as sock:
                    if label != "udp":
                        sock.return_value.__enter__.return_value.connect.side_effect = OSError("unreachable")
                    with self.assertRaises(run.Refusal):
                        run.namespace_check("net:[1]", "user:[3]", root)

    def test_missing_or_malformed_supervisor_namespace_is_not_proof(self):
        for value in (None, "", "net:[different]", "user:[1]", "net:[1]\nextra"):
            with self.subTest(value=value), self.assertRaises(run.Refusal):
                run.namespace_check(value)

    def test_supervisor_requires_dropped_capabilities_and_no_new_privileges(self):
        good = {"Uid": "1000\t1000\t1000\t1000", "NoNewPrivs": "1",
                **{name: "0000000000000000" for name in ("CapEff", "CapPrm", "CapInh", "CapAmb", "CapBnd")}}
        changes = [(None, None), ("NoNewPrivs", "0"), ("Uid", "0\t1000\t0\t1000")]
        changes += [(field, "0000000000000001") for field in ("CapEff", "CapPrm", "CapInh", "CapAmb", "CapBnd")]
        for field, value in changes:
            with self.subTest(field=field), tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                (root / "self").mkdir()
                status = good.copy()
                if field:
                    status[field] = value
                (root / "self/status").write_text("\n".join(key + ":\t" + value for key, value in status.items()))
                with patch.object(run.os, "geteuid", return_value=1000, create=True):
                    if field:
                        with self.assertRaises(run.Refusal):
                            run.supervised_status_check(root)
                    else:
                        self.assertTrue(run.supervised_status_check(root)["capability_sets_empty"])
        with patch.object(run.os, "geteuid", return_value=0, create=True), self.assertRaises(run.Refusal):
            run.supervised_status_check(Path("/unused"))

    def test_isolation_probe_contains_no_database_or_laravel_boot(self):
        args = unittest.mock.Mock(mode="isolation", php=Path("/usr/bin/php"))
        command = run.php_command(args)
        self.assertIn("-r", command)
        self.assertIn("'database_contacted'=>false", command[-1])
        self.assertNotIn("smoke.php", " ".join(command))
        self.assertNotIn("new PDO", " ".join(command))

    def test_child_receives_secret_only_on_stdin_and_refusal_is_redacted(self):
        secret = json.dumps(fixture()).encode()
        child = unittest.mock.Mock()
        child.pid = 101
        child.returncode = 1
        output = json.dumps({"scope": "synthetic-laravel-smoke", "result": "refused",
            "phase": "fixture", "reason": "sensitive SQL contains a secret"}).encode()
        with patch.object(run.subprocess, "Popen", return_value=child) as popen, \
             patch.object(run, "collect_child_output", return_value=output) as collect, \
             patch.object(run.os, "killpg", create=True), \
             patch.object(run.signal, "SIGKILL", 9, create=True):
            with self.assertRaises(run.Refusal) as caught:
                run.bounded_process(["php", "smoke.php"], secret, {"APP_ENV": "testing"}, "/private")
        self.assertNotIn("secret", str(caught.exception))
        self.assertNotIn("private stderr", str(caught.exception))
        self.assertNotIn(secret.decode(), repr(popen.call_args))
        collect.assert_called_once_with(child, secret, run.TIMEOUT)

    def test_timeout_kills_owned_session_and_does_not_fall_back(self):
        child = unittest.mock.Mock()
        child.pid = 202
        with patch.object(run.subprocess, "Popen", return_value=child) as popen, \
             patch.object(run, "collect_child_output", side_effect=subprocess.TimeoutExpired("fixture", 1)), \
             patch.object(run.os, "killpg", create=True) as kill, \
             patch.object(run.signal, "SIGKILL", 9, create=True):
            with self.assertRaises(subprocess.TimeoutExpired):
                run.bounded_process(["unshare", "--net"], b"{}", {}, "/private")
        self.assertEqual(1, popen.call_count)
        kill.assert_any_call(202, 9)

    @unittest.skipIf(os.name == "nt", "POSIX pipe selection is exercised on Linux")
    def test_real_child_stderr_is_capped_during_collection(self):
        command = [sys.executable, "-c", "import sys; sys.stderr.write('X'*262144); sys.stderr.flush()"]
        with self.assertRaisesRegex(run.Refusal, "child-output-too-large"):
            run.bounded_process(command, b"", {}, "/")

    @unittest.skipIf(os.name == "nt", "POSIX pipe selection is exercised on Linux")
    def test_real_child_receives_stdin_and_returns_only_sanitized_result(self):
        command = [sys.executable, "-c", "import json,sys; data=json.load(sys.stdin); "
                   "print(json.dumps({'scope':'synthetic-laravel-smoke','result':'passed','received':data['value']}))"]
        result = run.bounded_process(command, b'{"value":7}', {}, "/")
        self.assertEqual(7, result["received"])


class FilesystemBoundaryTest(unittest.TestCase):
    def app_fixture(self, root):
        for name in ("bootstrap/app.php", "vendor/autoload.php", "composer.lock"):
            path = root / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text("fixture")

    def test_existing_environment_and_config_cache_are_rejected(self):
        for name in (".env", ".env.production", "bootstrap/cache/config.php"):
            with self.subTest(name=name), tempfile.TemporaryDirectory() as directory:
                root = Path(directory).resolve()
                self.app_fixture(root)
                run.validate_source(root)
                path = root / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text("never read this secret")
                with self.assertRaises(run.Refusal):
                    run.validate_source(root)

    @unittest.skipIf(os.name == "nt", "POSIX ownership is exercised on Linux")
    def test_runtime_requires_private_parent_and_exclusive_new_directory(self):
        with tempfile.TemporaryDirectory() as directory:
            parent = Path(directory).resolve()
            parent.chmod(0o755)
            with self.assertRaises(run.Refusal):
                run.prepare_runtime(parent / "run", parent / "source")
            parent.chmod(0o700)
            run.prepare_runtime(parent / "run", parent / "source")
            self.assertEqual(0o700, stat.S_IMODE((parent / "run").stat().st_mode))
            with self.assertRaises(run.Refusal):
                run.prepare_runtime(parent / "run", parent / "source")


if __name__ == "__main__":
    unittest.main()
