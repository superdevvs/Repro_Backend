"""Tests for the identity probe (design v2, section 1.1) and its root-owned fingerprint copy.

The probe runs as root on the host,

    /usr/bin/python3 -I -S /opt/repro-cutover-fence/production/identity_probe.py --config /etc/repro-cutover-fence/identity.json

and prints the six runner `Identity` fields. Every field is checked here against a computation of the
test's own: the commit written into the fixture's `deploy-meta.json`, the deploy script run exactly as
Deploy-App runs it, canonical JSON and SHA-256 built by hand, a hand-sorted canonical `.env`, and the
UUID written into the fixture's `auto.cnf` (the real 8.4.13 format: `[auto]` then `server-uuid=`).

- The config digest, fingerprint copy, input shape and non-regular path tests need only POSIX.
- Review A2-1: `BytecodeCacheTests` (POSIX) pin that a `__pycache__` under a fingerprinted tree changes
  artifact_sha256 until it is removed; `HostPythonRuleTests` (any system) read the repository's Markdown and
  fail on a python3 command that runs code in the app tree without -B.
- `IdentityProbeTests` need Linux root: they make block device nodes (mknod) in a fake
  `/dev/disk/by-uuid`, whose numbers are those of the fixture files' own filesystem, and they run the
  probe's main(), which reads identity.json and the machine ID through the protected-file check. They
  skip without root, or where mknod is not permitted.
- `RealFilesystemTests` mount a real ext4 image through two loop devices, so the block device number
  really changes under one filesystem UUID. They attach kernel loop devices, so they run only with
  REPRO_IDENTITY_LOOP_DEVICES=1, as root, in a disposable --privileged container (Git Bash:
  MSYS_NO_PATHCONV=1):

    docker run --rm --privileged --network none --cpus 2 --memory 2g \
      --mount type=bind,source=<checkout>,target=/src,readonly \
      --tmpfs /tmp:rw,exec,size=512m,mode=1777 -e REPRO_IDENTITY_LOOP_DEVICES=1 \
      --entrypoint /bin/sh repro-mysql-rehearsal-qa:20261122 \
      -c 'cd /src && python3 -B -m unittest discover -s scripts/cutover/production -p test_identity_probe.py -v'

Run from the app root: python3 -B -m unittest discover -s scripts/cutover/production -p test_identity_probe.py
"""

import hashlib
import importlib.util
import io
import json
import os
import re
import shutil
import socket
import sqlite3
import stat
import subprocess
import sys
import tempfile
import unittest
from contextlib import redirect_stderr, redirect_stdout
from dataclasses import asdict
from pathlib import Path
from unittest.mock import patch

HERE = Path(__file__).resolve().parent
APP = HERE.parents[2]
sys.path[:0] = [str(HERE.parent), str(HERE)]
import adapter  # noqa: E402
import identity_probe  # noqa: E402
from fence import guard  # noqa: E402
from runner import Identity  # noqa: E402
from test_adapter import example_config, example_identity, load  # noqa: E402

PROBE = HERE / "identity_probe.py"
COPY = HERE / "backend_source_fingerprint.py"
DEPLOY_SCRIPT = APP / "scripts" / "deploy" / "backend-source-fingerprint.py"
LINUX_ROOT = sys.platform == "linux" and hasattr(os, "geteuid") and os.geteuid() == 0
LOOP_DEVICES = os.environ.get("REPRO_IDENTITY_LOOP_DEVICES") == "1"
RUN_ID = "drill-20261205"
COMMIT = "4e4a262d0c5f1b9e8a7d6c5b4a39281706f5e4d3"
SERVER_UUID = "12648f68-e93a-11f1-89e1-6eb9557f7efa"
FS_UUID = "0b3e8a52-5c1f-4c39-9a40-6a3b8f1d2e77"
OTHER_FS_UUID = "7d1c2b3a-4e5f-4a6b-8c7d-9e0f1a2b3c4d"
# The one denylist load_config accepts for the production example: night flags plus switch keys.
DENYLIST = adapter.env_denylist(example_config())
# A production-shaped .env (key names as measured on 25 Nov; values are fixtures, not secrets).
SECRET_VALUES = (b"base64:Zml4dHVyZS1rZXktbm90LWEtcmVhbC1zZWNyZXQ=", b"fixture-telnyx-value-not-a-secret")
ENV = (b"APP_NAME=ReproDashboard\n"
       b"APP_ENV=production\n"
       b"APP_KEY=" + SECRET_VALUES[0] + b"\n"
       b"APP_DEBUG=false\n"
       b"\n"
       b"# Database\n"
       b"DB_CONNECTION=sqlite\n"
       b"DB_DATABASE=/var/www/backend/database/database.sqlite\n"
       b"DB_BUSY_TIMEOUT=5000\n"
       b"DB_JOURNAL_MODE=wal\n"
       b"DB_SYNCHRONOUS=normal\n"
       b"   # an indented comment\n"
       b"CRM_OUTBOUND_HOLD=false\n"
       b"LEGACY_LABEL=caf\xe9\n"
       b'MAIL_FROM_NAME="Repro Dashboard"\n'
       b"TELNYX_API_KEY=" + SECRET_VALUES[1] + b"\n")
# Written by hand: comments, blank lines and the denylisted DB_CONNECTION, DB_DATABASE and
# CRM_OUTBOUND_HOLD dropped, every other line byte for byte (latin-1 byte included), sorted, joined.
CANONICAL_ENV = (b"APP_DEBUG=false\n"
                 b"APP_ENV=production\n"
                 b"APP_KEY=" + SECRET_VALUES[0] + b"\n"
                 b"APP_NAME=ReproDashboard\n"
                 b"DB_BUSY_TIMEOUT=5000\n"
                 b"DB_JOURNAL_MODE=wal\n"
                 b"DB_SYNCHRONOUS=normal\n"
                 b"LEGACY_LABEL=caf\xe9\n"
                 b'MAIL_FROM_NAME="Repro Dashboard"\n'
                 b"TELNYX_API_KEY=" + SECRET_VALUES[1])
# What MySQL 8.4.13 writes at --initialize (checked against the pinned image: 56 bytes, mysql 0640).
AUTO_CNF = b"[auto]\nserver-uuid=" + SERVER_UUID.encode() + b"\n"


def canonical_sha256(value: dict) -> str:
    return hashlib.sha256(json.dumps(value, sort_keys=True, separators=(",", ":")).encode()).hexdigest()


def deploy_fingerprint(root: Path) -> str:
    """The deploy side's fingerprint, run as Deploy-App runs it: the script's source on stdin, `python3 - <root>`."""
    result = subprocess.run([sys.executable, "-", str(root)], input=DEPLOY_SCRIPT.read_bytes(), capture_output=True,
                            check=True)
    return result.stdout.decode("ascii").strip().splitlines()[-1]


def load_copy():
    """The root-owned copy, loaded by path as the deploy script's own tests load it."""
    spec = importlib.util.spec_from_file_location("backend_source_fingerprint_copy", COPY)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def fixture_root(test: unittest.TestCase) -> Path:
    # The protected-file and chain checks refuse world-writable parents such as /tmp.
    directory = tempfile.TemporaryDirectory(prefix="repro-identity-", dir="/var/lib" if LINUX_ROOT else None)
    test.addCleanup(directory.cleanup)
    root = Path(directory.name)
    root.chmod(0o755)
    return root


def source_tree(app: Path) -> None:
    """A small app tree with root files, protected runtime folders and a file symlink."""
    for folder in ("app", "bootstrap/cache", "config", "public", "routes", "storage/app", "database/migrations"):
        (app / folder).mkdir(parents=True, exist_ok=True)
    (app / "artisan").write_text("<?php // fixture\n", encoding="ascii")
    (app / "composer.json").write_text('{"name": "fixture/app"}\n', encoding="ascii")
    (app / "app" / "Example.php").write_text("<?php class Example {}\n", encoding="ascii")
    (app / "config" / "app.php").write_text("<?php return [];\n", encoding="ascii")
    (app / "routes" / "web.php").write_text("<?php // routes\n", encoding="ascii")
    (app / "public" / "index.php").write_text("<?php // front controller\n", encoding="ascii")
    (app / "public" / "routes-link.php").symlink_to("../routes/web.php")
    (app / "database" / "migrations" / "0001_create.php").write_text("<?php // migration\n", encoding="ascii")
    # Runtime state the fingerprint excludes.
    (app / "bootstrap" / "cache" / "config.php").write_text("<?php return ['cached' => true];\n", encoding="ascii")


# Review A2-1: which documented commands run repository Python, so must run it with python3 -B.
RUNBOOK = APP / "docs" / "mysql-production-cutover.md"
PYTHON3 = re.compile(r"(?:/usr/bin/)?python3(?:\.[0-9]+)?")
# Code in the app tree: a path relative to the app root into a fingerprinted tree that holds Python (scripts/,
# resources/), a scripts.* module, or a path under the host's app root. A copy outside the app tree (under /opt,
# an isolated acceptance copy) is not fingerprinted.
REPOSITORY_CODE = re.compile(r"^(?:scripts|resources)/|^scripts\.|^/var/www/backend(?:/|$)")
SHELL_SEPARATORS = {"|", "||", "&&", ";", "&"}


def python3_commands(text: str):
    """Each python3 command in Markdown text, as (line number, its options, the words after them).

    Lines continued with a backslash are joined, and so is inline code that a line opens and does not close
    (Markdown joins the next line with a space; review W2I-1), unless a blank line comes first; a fence line
    ends it, and inside a fenced block no backtick opens a span. A command ends at a shell separator word or
    at the end of the inline code it is in (a closing backtick); `-W` and `-X` take a value.
    """
    lines = text.splitlines()
    logical, start, fenced = "", None, False
    for number, line in enumerate(lines, 1):
        start = start or number
        fence = line.lstrip().startswith("```")
        fenced = fenced != fence
        if line.endswith("\\"):
            logical += line[:-1] + " "
            continue
        joined = logical + line
        following = lines[number] if number < len(lines) else ""
        if not fenced and not fence and joined.count("`") % 2 == 1 and following.strip():
            logical = joined + " "
            continue
        words, first, logical, start = joined.split(), start, "", None
        for index, word in enumerate(words):
            if not PYTHON3.fullmatch(word.strip("`'\"($")):
                continue
            flags, arguments, value_follows, closed = [], [], False, word.endswith("`")
            for following in words[index + 1:]:
                if closed or following in SHELL_SEPARATORS:
                    break
                closed = following.endswith("`")
                following = following.strip("`'\"()")
                if value_follows:
                    value_follows = False
                elif not arguments and following.startswith("-") and following != "-":
                    flags.append(following)
                    value_follows = following in ("-W", "-X")
                else:
                    arguments.append(following)
            yield first, flags, arguments


def runs_repository_code(arguments: list[str]) -> bool:
    """Whether the command runs code in the app tree. A script named by an absolute path runs that file, so it is
    app-tree code only under the app root, and its other arguments (`--app-root /var/www/backend` given to the
    installed controller under /opt) are data (review W2I-2). Otherwise any word naming the app tree counts."""
    if arguments and arguments[0].startswith("/"):
        return bool(REPOSITORY_CODE.search(arguments[0]))
    return any(REPOSITORY_CODE.search(word) for word in arguments)


def lacks_dash_b(flags: list[str]) -> bool:
    return not any(re.fullmatch(r"-[A-Za-z]*B[A-Za-z]*", flag) for flag in flags)


def unflagged_repository_python(text: str) -> list[int]:
    """Line numbers of python3 commands that run repository code without -B."""
    return [number for number, flags, arguments in python3_commands(text)
            if runs_repository_code(arguments) and lacks_dash_b(flags)]


class ConfigDigestTests(unittest.TestCase):
    """config_sha256: .env canonicalised; denylisted keys removed; any other edit changes it (design 1.1)."""

    def digest(self, raw: bytes) -> str:
        return identity_probe.config_sha256(raw, DENYLIST)

    def test_digest_is_sha256_of_the_hand_built_canonical_env(self):
        self.assertEqual(hashlib.sha256(CANONICAL_ENV).hexdigest(), self.digest(ENV))

    def test_denylisted_edit_keeps_config_digest(self):
        base = self.digest(ENV)
        self.assertEqual(sorted(example_identity(example_config())["env_denylist"]), DENYLIST)
        lines = ENV.split(b"\n")
        for key in DENYLIST:
            name = key.encode()
            edits = {
                "added": ENV + name + b"=night-value\n",
                "added first": name + b"=1\n" + ENV,
                "changed": ENV.replace(b"DB_CONNECTION=sqlite", b"DB_CONNECTION=mysql") + name + b"=x\n",
                "emptied": ENV + name + b"=\n",
                "set twice": ENV + name + b"=a\n" + name + b"=b\n",
                "removed": b"\n".join(line for line in lines if not line.startswith(name + b"=")),
            }
            for edit, raw in edits.items():
                with self.subTest(key=key, edit=edit):
                    self.assertEqual(base, self.digest(raw))

    def test_switch_and_password_rotation_keep_config_digest(self):
        # prepare step 6: every switch key set (DB_PASSWORD from its secret file), every other line kept.
        switch = {**example_config()["mysql"]["switch"], "DB_PASSWORD": "fixture-app-password"}
        switched = b"".join(line + b"\n" for line in ENV.split(b"\n")
                            if line and line.split(b"=", 1)[0].decode("ascii", "replace") not in switch)
        switched += b"".join(f"{key}={value}\n".encode() for key, value in switch.items())
        self.assertEqual(self.digest(ENV), self.digest(switched))
        rotated = switched.replace(b"DB_PASSWORD=fixture-app-password", b"DB_PASSWORD=rotated-password")
        self.assertEqual(self.digest(ENV), self.digest(rotated))

    def test_comments_blank_lines_and_order_keep_config_digest(self):
        base = self.digest(ENV)
        lines = [line for line in ENV.split(b"\n") if line]
        for edit, raw in {
            "reversed": b"\n".join(reversed(lines)) + b"\n",
            "no final newline": ENV.rstrip(b"\n"),
            "comment added": ENV + b"# changed by hand on 26 Nov\n",
            "indented comment": b"\t# note\n" + ENV,
            "blank lines": b"\n\n" + ENV.replace(b"\n", b"\n\n"),
            "whitespace-only line": ENV + b"  \t \n",
        }.items():
            with self.subTest(edit=edit):
                self.assertEqual(base, self.digest(raw))

    def test_other_edit_changes_config_digest(self):
        base = self.digest(ENV)
        edits = {
            "value changed": ENV.replace(b"APP_DEBUG=false", b"APP_DEBUG=true"),
            "secret changed": ENV.replace(SECRET_VALUES[0], b"base64:b3RoZXI="),
            "byte value changed": ENV.replace(b"caf\xe9", b"cafe"),
            "key added": ENV + b"QUEUE_CONNECTION=database\n",
            "flag not on the denylist": ENV + b"CRM_NEW_HOLD=true\n",
            "key removed": ENV.replace(b"DB_BUSY_TIMEOUT=5000\n", b""),
            "value emptied": ENV.replace(b"DB_JOURNAL_MODE=wal", b"DB_JOURNAL_MODE="),
            "quoting changed": ENV.replace(b'"Repro Dashboard"', b"'Repro Dashboard'"),
            "inline comment": ENV.replace(b"APP_ENV=production", b"APP_ENV=production # live"),
            "trailing space": ENV.replace(b"APP_ENV=production", b"APP_ENV=production "),
            "carriage return": ENV.replace(b"APP_ENV=production\n", b"APP_ENV=production\r\n"),
            "key case": ENV.replace(b"APP_DEBUG=false", b"app_debug=false"),
            # phpdotenv names are case-sensitive: db_password is another variable, not the denylisted DB_PASSWORD.
            "lower-case denylisted name": ENV + b"db_password=x\n",
        }
        digests = {}
        for edit, raw in edits.items():
            with self.subTest(edit=edit):
                digest = self.digest(raw)
                self.assertNotEqual(base, digest)
                digests[edit] = digest
        self.assertEqual(len(digests), len(set(digests.values())), "two different edits gave one digest")

    def test_denylist_is_exact_names_only(self):
        # A key that only starts like a denylisted one is not denylisted.
        self.assertNotEqual(self.digest(ENV), self.digest(ENV + b"DB_PASSWORD_OLD=x\n"))
        self.assertNotEqual(self.digest(ENV), self.digest(ENV + b"XDB_PASSWORD=x\n"))

    def test_malformed_env_refuses_without_printing_values(self):
        for edit, line in {
            "export prefix": b"export TELNYX_API_KEY=" + SECRET_VALUES[1],
            "leading space": b" TELNYX_API_KEY=" + SECRET_VALUES[1],
            "space before equals": b"TELNYX_API_KEY =" + SECRET_VALUES[1],
            "no equals": SECRET_VALUES[1],
            "bad name": b"1TELNYX=" + SECRET_VALUES[1],
            "repeated key": b"APP_KEY=" + SECRET_VALUES[1],
            "multi-line value": b'PEM="-----BEGIN KEY-----\n' + SECRET_VALUES[1] + b'\n-----END KEY-----"',
        }.items():
            with self.subTest(edit=edit):
                with self.assertRaises(guard.Refused) as refused:
                    self.digest(ENV + line + b"\n")
                message = str(refused.exception)
                self.assertRegex(message, r"^\.env line [0-9]+ ")
                for value in SECRET_VALUES:
                    self.assertNotIn(value.decode(), message)


class FingerprintCopyTests(unittest.TestCase):
    """artifact_sha256 comes from a root-owned copy of the deploy script, never from the app tree."""

    def test_fingerprint_copy_matches_deploy_script(self):
        # Byte for byte, so the copy cannot drift from what Deploy-App's preflight measures.
        self.assertEqual(DEPLOY_SCRIPT.read_bytes(), COPY.read_bytes())
        copy = load_copy()
        root = fixture_root(self)
        source_tree(root)
        # And the same digest as the deploy script run the deploy's way, on a tree and after an edit.
        self.assertEqual(deploy_fingerprint(root), copy.fingerprint(root))
        (root / "app" / "Example.php").write_text("<?php class Changed {}\n", encoding="ascii")
        self.assertEqual(deploy_fingerprint(root), copy.fingerprint(root))

    @unittest.skipUnless(os.name == "posix", "POSIX paths")
    def test_probe_measures_with_the_copy_beside_itself(self):
        module = identity_probe.backend_source_fingerprint
        self.assertEqual(COPY.resolve(), Path(module.__file__).resolve())
        root = fixture_root(self)
        source_tree(root)
        with patch.object(module, "fingerprint", return_value="f" * 64) as fingerprint:
            self.assertEqual("f" * 64, identity_probe.artifact_sha256(root))
        fingerprint.assert_called_once_with(root)

    @unittest.skipUnless(os.name == "posix", "POSIX paths")
    def test_artifact_is_measured_by_the_copy_not_a_script_in_the_app_tree(self):
        root = fixture_root(self)
        source_tree(root)
        # A fingerprint helper planted in the app tree is hashed like any other file and never run.
        planted = "raise SystemExit('the app tree copy must never be imported')\n"
        (root / "backend_source_fingerprint.py").write_text(planted, encoding="ascii")
        (root / "scripts" / "deploy").mkdir(parents=True)
        (root / "scripts" / "deploy" / "backend-source-fingerprint.py").write_text(planted, encoding="ascii")
        self.assertEqual(deploy_fingerprint(root), identity_probe.artifact_sha256(root))

    @unittest.skipUnless(os.name == "posix", "POSIX paths")
    def test_missing_app_root_refuses(self):
        root = fixture_root(self)
        for path in (root / "missing", root / "file"):
            with self.subTest(path=path.name):
                if path.name == "file":
                    path.write_text("x", encoding="ascii")
                with self.assertRaises(guard.Refused):
                    identity_probe.artifact_sha256(path)


@unittest.skipUnless(os.name == "posix", "POSIX paths")
class BytecodeCacheTests(unittest.TestCase):
    """Review A2-1, kept as documented behaviour: the fingerprint hashes bytecode caches like any other file.

    Excluding `__pycache__` or `*.pyc` would hide a tampered `.pyc`, and the copy stays byte-identical to the
    deploy script. So a repository tool run from the app tree without -B changes artifact_sha256, and the
    runner refuses every action, the watchdog rollback included, until the cache folder is removed. Every
    repository Python on the host therefore runs with python3 -B (HostPythonRuleTests checks the docs).
    """

    def run_tool(self, root: Path, tool: str, *flags: str) -> None:
        # As a shell on the host runs it: from the app root, a relative path, no -I (the tool imports its
        # sibling from its own folder) and no PYTHON* variable (the test runner's own settings stay out).
        environment = {name: value for name, value in os.environ.items() if not name.startswith("PYTHON")}
        subprocess.run([sys.executable, *flags, tool], cwd=root, env=environment, capture_output=True, check=True,
                       timeout=60)

    def test_bytecode_cache_changes_artifact_until_removed(self):
        # migrate.py imports conversion.py the same way; the app's iGUIDE lab workers under resources/ import
        # their siblings too.
        for folder, module in (("scripts/mysql", "conversion"), ("resources/iguide-lab", "lab_protocol")):
            with self.subTest(folder=folder):
                root = fixture_root(self)
                source_tree(root)
                tools = root / folder
                tools.mkdir(parents=True)
                (tools / "tool.py").write_text(f"import {module}\nprint({module}.NAME)\n", encoding="ascii")
                (tools / f"{module}.py").write_text("NAME = 'fixture'\n", encoding="ascii")
                cache = tools / "__pycache__"
                before = identity_probe.artifact_sha256(root)
                self.run_tool(root, f"{folder}/tool.py", "-B")
                self.assertFalse(cache.exists())
                self.assertEqual(before, identity_probe.artifact_sha256(root))
                self.run_tool(root, f"{folder}/tool.py")
                self.assertEqual(1, len(list(cache.glob(f"{module}.*.pyc"))))
                after = identity_probe.artifact_sha256(root)
                self.assertNotEqual(before, after)
                # The deploy script measures the cache too: both sides keep one definition of the tree.
                self.assertEqual(deploy_fingerprint(root), after)
                shutil.rmtree(cache)
                self.assertEqual(before, identity_probe.artifact_sha256(root))


class HostPythonRuleTests(unittest.TestCase):
    """Review A2-1: every documented python3 command that runs repository code carries -B."""

    def test_rule_checker_examples(self):
        missing_dash_b = {
            "plain": "python3 scripts/mysql/migrate.py plan --snapshot x",
            "continued": "sudo -u maverick python3 \\\n  scripts/mysql/schema-drift.py --release x",
            "inline code": "`python3 scripts/deploy/install-download-tcp.py` previews the remedy",
            "app root path": "/usr/bin/python3 -I -S /var/www/backend/scripts/deploy/deployment-storage.py x",
            "module": "python3 -m scripts.cutover.website_ledger --export x",
            "test discovery": "python3 -W ignore::ResourceWarning -m unittest discover -s scripts/cutover/production",
            "lab worker": "cd /var/www/backend && python3 -I resources/iguide-lab/bridge.py --request x",
            "after a pipe": "printf x | python3 scripts/x.py",
            # Review W2I-1: inline code wrapped onto the next Markdown line is one command (README's old P8 line).
            "wrapped inline code": "For P8: `/usr/bin/python3 -I -S fastcgi.py --socket\n"
                                   "/run/php/php8.3-fpm.sock --app-root /var/www/backend` prints",
            "wrapped over three lines": "Run `python3\n-I\nscripts/x.py --apply` first.",
        }
        fine = {
            "dash B": "python3 -B scripts/mysql/migrate.py plan",
            "with -I": "sudo python3 -I -B $B --job alerts --settings settings.json --apply",
            "combined": "python3 -IB scripts/x.py",
            "after -W": "python3 -W ignore::ResourceWarning -B -m unittest discover -s scripts/cutover/production",
            "module": "`python3 -B -m scripts.cutover.nightly.runner check-config --config scripts/x.json`.",
            "controller under /opt": "/usr/bin/python3 -I -S /opt/repro-cutover-fence/production/cli.py tick",
            "bundle copy under /opt": "sudo python3 -I /opt/repro-install-bundle/scripts/mysql/offsite/install.py",
            "isolated copy": "python3 -I /isolated/backend/scripts/mysql/workload/run.py --app /isolated/backend",
            "inline script": "python3 - \"$run_dir/frozen.sqlite\" <<'PY'",
            "interpreter named in prose": "`python` `/usr/bin/python3`, `rclone` `/usr/bin/rclone`, scripts/x",
            "prose after inline code": "Run `python3 -m json.tool` on scripts/cutover/x.json first.",
            # Review W2I-2: the installed controller runs from /opt; the app root it is given is data, not code.
            "controller under /opt given the app root":
                "/usr/bin/python3 -I -S /opt/repro-cutover-fence/production/live_config.py --app-root /var/www/backend",
            "wrapped controller command":
                "For P8: `/usr/bin/python3 -I -S /opt/repro-cutover-fence/production/fastcgi.py\n"
                "--socket /run/php/php8.3-fpm.sock --app-root /var/www/backend` prints",
            # Only inline code continues on the next line: a fence, a closed span or a blank line ends it.
            "fenced block": "```sh\npython3 -B scripts/x.py\n```\nscripts/y.py is prose",
            "lines in a fence stay apart": "```sh\npython3 -m json.tool\nscripts/x.json\n```",
            "a closing fence joins nothing": "```\nx\n```\npython3 -m json.tool\nscripts/x.json",
            "a backtick inside a fence opens no span": "```sh\necho 'a`b'\npython3 -m json.tool\nscripts/x.json\n```",
            "an open span never runs into a fence": "Run `python3 -m json.tool\n````sh\nscripts/x.json\n````",
            "closed span": "`python3 -m json.tool`\nscripts/x.json is prose",
            "paragraph ends": "Then `python3 -m json.tool\n\nscripts/x.json` is prose",
        }
        for case, text in missing_dash_b.items():
            with self.subTest(case=case):
                self.assertEqual([1], unflagged_repository_python(text))
        for case, text in fine.items():
            with self.subTest(case=case):
                self.assertEqual([], unflagged_repository_python(text))

    def test_docs_run_repository_python_with_dash_b(self):
        # The scan is not empty: the runbook runs repository tools (migrate.py, schema-drift.py, export-sqlite.py).
        runbook = [arguments for _, _, arguments in python3_commands(RUNBOOK.read_text(encoding="utf-8"))
                   if runs_repository_code(arguments)]
        self.assertGreaterEqual(len(runbook), 5)
        documents = sorted({*APP.glob("*.md"), *APP.glob("docs/**/*.md"), *APP.glob("scripts/**/*.md")})
        unflagged = [f"{path.relative_to(APP).as_posix()}:{number}" for path in documents
                     for number in unflagged_repository_python(path.read_text(encoding="utf-8"))]
        self.assertEqual([], unflagged, "python3 runs repository code without -B: a __pycache__ under the app tree "
                                        "changes the identity the controller checks before every action")


@unittest.skipUnless(os.name == "posix", "POSIX files")
class InputShapeTests(unittest.TestCase):
    """release_sha and target_uuid: exact shapes or a refusal; the SQLite path must be a regular file."""

    def setUp(self):
        self.root = fixture_root(self)

    def write(self, relative: str, content: bytes) -> Path:
        path = self.root / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        path.unlink(missing_ok=True)
        path.write_bytes(content)
        return path

    def test_release_sha_is_the_deploy_meta_commit(self):
        self.write("storage/app/deploy-meta.json",
                   f'{{"commit":"{COMMIT}","deployed_at":"2026-11-24T02:10:00Z"}}\n'.encode())
        self.assertEqual(COMMIT, identity_probe.release_sha(self.root))

    def test_bad_deploy_meta_refuses(self):
        meta = "storage/app/deploy-meta.json"
        for case, content in {
            "short commit": f'{{"commit":"{COMMIT[:39]}"}}',
            "upper case": f'{{"commit":"{COMMIT.upper()}"}}',
            "no commit": '{"deployed_at":"2026-11-24T02:10:00Z"}',
            "number": '{"commit": 4}',
            "array": f'["{COMMIT}"]',
            "duplicate commit": f'{{"commit":"{COMMIT}","commit":"{"0" * 40}"}}',
            "not json": "commit=" + COMMIT,
            "empty": "",
        }.items():
            with self.subTest(case=case):
                self.write(meta, content.encode())
                with self.assertRaises((guard.Refused, ValueError)):
                    identity_probe.release_sha(self.root)
        (self.root / meta).unlink()
        with self.assertRaises(OSError):
            identity_probe.release_sha(self.root)

    def test_target_uuid_is_the_auto_cnf_server_uuid(self):
        self.write("mysql/auto.cnf", AUTO_CNF)
        self.assertEqual(SERVER_UUID, identity_probe.target_uuid(self.root / "mysql"))

    def test_bad_auto_cnf_refuses(self):
        for case, content in {
            "upper case": AUTO_CNF.upper(),
            "no section": AUTO_CNF.replace(b"[auto]\n", b""),
            "short uuid": AUTO_CNF.replace(SERVER_UUID.encode(), SERVER_UUID[:-1].encode()),
            "two uuids": AUTO_CNF + b"server-uuid=" + OTHER_FS_UUID.encode() + b"\n",
            "other key": AUTO_CNF + b"server-id=2\n",
            "empty": b"",
        }.items():
            with self.subTest(case=case):
                self.write("mysql/auto.cnf", content)
                with self.assertRaises((guard.Refused, ValueError)):
                    identity_probe.target_uuid(self.root / "mysql")
        (self.root / "mysql" / "auto.cnf").unlink()
        with self.assertRaises(OSError):
            identity_probe.target_uuid(self.root / "mysql")

    def test_inputs_must_be_regular_files_not_symlinks_or_fifos(self):
        real = self.write("elsewhere/deploy-meta.json", f'{{"commit":"{COMMIT}"}}'.encode())
        meta = self.root / "storage" / "app" / "deploy-meta.json"
        meta.parent.mkdir(parents=True)
        meta.symlink_to(real)
        with self.assertRaises(OSError):
            identity_probe.release_sha(self.root)
        (self.root / "mysql").mkdir()
        os.mkfifo(self.root / "mysql" / "auto.cnf")
        with self.assertRaisesRegex(guard.Refused, "is not a regular file"):
            identity_probe.target_uuid(self.root / "mysql")

    def test_oversized_input_refuses(self):
        meta = f'{{"commit":"{COMMIT}","padding":"'.encode()
        self.write("storage/app/deploy-meta.json", meta + b"x" * (identity_probe.MAX_INPUT_BYTES - len(meta) - 2) + b'"}')
        self.assertEqual(COMMIT, identity_probe.release_sha(self.root))
        self.write("storage/app/deploy-meta.json", meta + b"x" * (identity_probe.MAX_INPUT_BYTES - len(meta) - 1) + b'"}')
        with self.assertRaisesRegex(guard.Refused, "is larger than"):
            identity_probe.release_sha(self.root)

    def test_non_regular_sqlite_path_refuses(self):
        database = self.root / "database"
        database.mkdir()
        real = self.write("elsewhere/database.sqlite", b"SQLite format 3\x00")
        listener = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        self.addCleanup(listener.close)
        cases = {
            "missing": lambda path: None,
            "directory": lambda path: path.mkdir(),
            "symlink to a regular file": lambda path: path.symlink_to(real),
            "dangling symlink": lambda path: path.symlink_to(self.root / "gone"),
            "fifo": lambda path: os.mkfifo(path),
            "socket": lambda path: listener.bind(str(path)),
        }
        for case, make in cases.items():
            with self.subTest(case=case):
                path = database / f"{case.replace(' ', '-')}.sqlite"
                make(path)
                with self.assertRaisesRegex(guard.Refused, "is not a regular file"):
                    identity_probe.source_sha256(path, by_uuid=self.root / "no-by-uuid", machine_id_sha256="0" * 64)


@unittest.skipUnless(LINUX_ROOT, "Requires Linux root: block device nodes and the protected-file check")
class IdentityProbeTests(unittest.TestCase):
    """The probe's main() on a fixture host: app root, SQLite file, MySQL datadir, machine ID, fake by-uuid."""

    def setUp(self):
        self.root = root = fixture_root(self)
        self.app = root / "app"
        self.datadir = root / "mysql"
        self.dev = root / "dev"
        self.by_uuid = self.dev / "disk" / "by-uuid"
        for folder in (self.app, self.datadir, root / "etc", self.by_uuid):
            folder.mkdir(parents=True)
        for folder in (self.dev, self.dev / "disk", self.by_uuid, self.datadir):
            folder.chmod(0o755)
        source_tree(self.app)
        (self.app / "storage" / "app" / "deploy-meta.json").write_text(
            f'{{"commit":"{COMMIT}","deployed_at":"2026-11-24T02:10:00Z"}}\n', encoding="ascii")
        (self.app / ".env").write_bytes(ENV)
        (self.app / ".env").chmod(0o640)
        self.sqlite = self.app / "database" / "database.sqlite"
        with sqlite3.connect(self.sqlite) as connection:
            connection.execute("CREATE TABLE shoots (id INTEGER PRIMARY KEY, note TEXT)")
        connection.close()
        (self.datadir / "auto.cnf").write_bytes(AUTO_CNF)
        (self.datadir / "auto.cnf").chmod(0o640)
        self.machine_id = root / "etc" / "machine-id"
        self.machine_id.write_bytes(b"0123456789abcdef0123456789abcdef\n")
        self.identity_path = root / "etc" / "identity.json"
        self.identity = {"version": 1, "run_id": RUN_ID, "app_root": str(self.app), "sqlite_path": str(self.sqlite),
                         "mysql_datadir": str(self.datadir), "env_denylist": DENYLIST}
        self.write_identity(self.identity)
        # udev's layout: by-uuid/<uuid> -> ../../<device node>. The SQLite file's device, plus noise: another
        # filesystem's device and a link whose device is gone.
        self.device = os.lstat(self.sqlite).st_dev
        self.add_uuid(FS_UUID, self.device)
        other = os.makedev(259, 99)
        self.assertNotEqual(other, self.device)
        self.add_uuid(OTHER_FS_UUID, other)
        (self.by_uuid / "1234-ABCD").symlink_to("../../removed-device")

    def write_identity(self, identity: dict) -> None:
        self.identity_path.write_text(json.dumps(identity), encoding="utf-8")
        self.identity_path.chmod(0o644)

    def node(self, device: int, kind: int = stat.S_IFBLK) -> Path:
        node = self.dev / f"{'blk' if kind == stat.S_IFBLK else 'chr'}-{os.major(device)}-{os.minor(device)}"
        if not node.exists():
            try:
                os.mknod(node, kind | 0o600, device)
            except PermissionError:
                self.skipTest("mknod is not permitted here")
        return node

    def add_uuid(self, name: str, device: int, kind: int = stat.S_IFBLK) -> None:
        link = self.by_uuid / name
        link.unlink(missing_ok=True)
        link.symlink_to(os.path.relpath(self.node(device, kind), self.by_uuid))

    def run_probe(self, config: Path | None = None) -> tuple[int, str, str]:
        stdout, stderr = io.StringIO(), io.StringIO()
        with patch.object(identity_probe, "BY_UUID", self.by_uuid), \
                patch.object(identity_probe, "MACHINE_ID", self.machine_id), redirect_stdout(stdout), \
                redirect_stderr(stderr):
            code = identity_probe.main(["--config", str(config or self.identity_path)])
        return code, stdout.getvalue(), stderr.getvalue()

    def probe(self) -> dict:
        code, output, errors = self.run_probe()
        self.assertEqual((0, ""), (code, errors))
        return json.loads(output)

    def refused(self, pattern: str, config: Path | None = None) -> str:
        code, output, errors = self.run_probe(config)
        self.assertEqual((1, ""), (code, output), errors)
        self.assertRegex(errors, pattern)
        for value in SECRET_VALUES:
            self.assertNotIn(value.decode(), errors)
        return errors

    def expected_source(self, fs_uuid: str = FS_UUID) -> str:
        return canonical_sha256({"kind": "sqlite-file", "path": str(self.sqlite), "fs_uuid": fs_uuid,
                                 "st_ino": os.lstat(self.sqlite).st_ino,
                                 "machine_id_sha256": hashlib.sha256(self.machine_id.read_bytes()).hexdigest()})

    def test_fields_match_independent_computation(self):
        identity = self.probe()
        self.assertEqual(guard.IDENTITY_FIELDS, set(identity))
        self.assertEqual(identity, asdict(Identity(**identity)))
        self.assertEqual(RUN_ID, identity["run_id"])
        self.assertEqual(COMMIT, identity["release_sha"])
        self.assertEqual(deploy_fingerprint(self.app), identity["artifact_sha256"])
        self.assertEqual(self.expected_source(), identity["source_sha256"])
        self.assertEqual(hashlib.sha256(CANONICAL_ENV).hexdigest(), identity["config_sha256"])
        self.assertEqual(SERVER_UUID, identity["target_uuid"])
        # The probe measures; reading never changes what it measures.
        self.assertEqual(identity, self.probe())

    def test_output_never_holds_env_values(self):
        code, output, errors = self.run_probe()
        self.assertEqual(0, code)
        for value in SECRET_VALUES:
            self.assertNotIn(value.decode(), output + errors)

    def test_renamed_sqlite_file_changes_source_identity(self):
        before = self.probe()["source_sha256"]
        # A byte-identical copy renamed into place is another file (a restore, or a copy swapped in).
        replacement = self.sqlite.with_name("database.sqlite.restored")
        shutil.copyfile(self.sqlite, replacement)
        os.rename(replacement, self.sqlite)
        after = self.probe()["source_sha256"]
        self.assertNotEqual(before, after)
        self.assertEqual(self.expected_source(), after)

    def test_in_place_write_keeps_source_identity(self):
        before = self.probe()["source_sha256"]
        inode, size = os.lstat(self.sqlite).st_ino, os.lstat(self.sqlite).st_size
        with sqlite3.connect(self.sqlite) as connection:
            connection.executemany("INSERT INTO shoots (note) VALUES (?)", [("x" * 500,)] * 200)
        connection.execute("VACUUM")
        connection.close()
        with open(self.sqlite, "ab") as stream:
            stream.write(b"\0" * 4096)
        self.assertEqual(inode, os.lstat(self.sqlite).st_ino)
        self.assertNotEqual(size, os.lstat(self.sqlite).st_size)
        self.assertEqual(before, self.probe()["source_sha256"])

    def test_device_number_change_with_same_filesystem_uuid_keeps_source_identity(self):
        before = self.probe()["source_sha256"]
        # After a reboot the partition has another minor; udev points the same UUID at the new node.
        renumbered = os.makedev(259, 7) if os.makedev(259, 7) != self.device else os.makedev(259, 8)
        self.add_uuid(FS_UUID, renumbered)

        def lstat(path):
            info = os.lstat(path)
            return os.stat_result((info.st_mode, info.st_ino, renumbered, info.st_nlink, info.st_uid, info.st_gid,
                                   info.st_size, int(info.st_atime), int(info.st_mtime), int(info.st_ctime)))

        machine = hashlib.sha256(self.machine_id.read_bytes()).hexdigest()
        after = identity_probe.source_sha256(self.sqlite, by_uuid=self.by_uuid, machine_id_sha256=machine, lstat=lstat)
        self.assertEqual(before, after)
        # The lookup really follows the file's device: at the old number nothing names it any more.
        self.refused(r"No filesystem UUID")

    def test_other_filesystem_uuid_changes_source_identity(self):
        before = self.probe()["source_sha256"]
        (self.by_uuid / FS_UUID).unlink()
        self.add_uuid("5f0e1d2c-3b4a-4596-8877-665544332211", self.device)
        after = self.probe()["source_sha256"]
        self.assertNotEqual(before, after)
        self.assertEqual(self.expected_source("5f0e1d2c-3b4a-4596-8877-665544332211"), after)

    def test_machine_id_is_part_of_source_identity(self):
        before = self.probe()["source_sha256"]
        self.machine_id.write_bytes(b"fedcba9876543210fedcba9876543210\n")
        after = self.probe()["source_sha256"]
        self.assertNotEqual(before, after)
        self.assertEqual(self.expected_source(), after)

    def test_missing_filesystem_uuid_refuses(self):
        link = self.by_uuid / FS_UUID
        link.unlink()
        self.refused(r"No filesystem UUID in .* names the device of ")
        # The same number on a character device is not the filesystem's block device.
        self.add_uuid(FS_UUID, self.device, stat.S_IFCHR)
        self.refused(r"No filesystem UUID")
        link.unlink()
        # Two UUIDs for one device: ambiguous, refused.
        self.add_uuid(FS_UUID, self.device)
        self.add_uuid("5f0e1d2c-3b4a-4596-8877-665544332211", self.device)
        self.refused(r"Several filesystem UUIDs .*5f0e1d2c")
        (self.by_uuid / "5f0e1d2c-3b4a-4596-8877-665544332211").unlink()
        # udev escapes odd characters; a name it would never write is refused, not hashed.
        link.unlink()
        self.add_uuid("not a uuid", self.device)
        self.refused(r"Unexpected filesystem UUID name")
        # No by-uuid folder at all (as in a container).
        shutil.rmtree(self.dev / "disk")
        self.refused(r"No filesystem UUID")

    def test_writable_by_uuid_folder_refuses(self):
        self.by_uuid.chmod(0o777)
        self.refused(r"Unsafe file type, owner or permissions")

    def test_non_regular_sqlite_path_refuses(self):
        self.sqlite.rename(self.sqlite.with_name("moved.sqlite"))
        self.refused(r"is not a regular file")
        self.sqlite.symlink_to("moved.sqlite")
        self.refused(r"is not a regular file")
        self.sqlite.unlink()
        self.sqlite.mkdir()
        self.refused(r"is not a regular file")

    def test_unreadable_or_malformed_inputs_refuse(self):
        def env_symlink():
            env = self.app / ".env"
            env.rename(env.with_name("env.real"))
            env.symlink_to("env.real")

        cases = {
            "env missing": (lambda: (self.app / ".env").unlink(), r"\.env"),
            "env symlink": (env_symlink, r"\.env"),
            "env fifo": (lambda: ((self.app / ".env").unlink(), os.mkfifo(self.app / ".env")), r"\.env"),
            "env malformed": (lambda: (self.app / ".env").write_bytes(
                ENV + b"export TELNYX_API_KEY=" + SECRET_VALUES[1] + b"\n"), r"\.env line 17 is not KEY=VALUE"),
            "deploy meta missing": (lambda: (self.app / "storage/app/deploy-meta.json").unlink(), r"deploy-meta"),
            "auto.cnf missing": (lambda: (self.datadir / "auto.cnf").unlink(), r"auto\.cnf"),
            "machine id missing": (lambda: self.machine_id.unlink(), r"machine-id"),
            "app root missing": (lambda: self.app.rename(self.root / "app.moved"), r"."),
        }
        for case, (change, pattern) in cases.items():
            with self.subTest(case=case):
                self.setUp()  # A fresh fixture host for each case.
                change()
                self.refused(pattern)

    def test_identity_config_is_read_through_the_protected_check(self):
        self.identity_path.chmod(0o664)
        self.refused(r"Unsafe file type, owner or permissions")
        self.identity_path.chmod(0o644)
        os.chown(self.identity_path, 33, 33)
        self.refused(r"Unsafe file type, owner or permissions")
        os.chown(self.identity_path, 0, 0)
        self.write_identity({**self.identity, "machine_id": str(self.machine_id)})
        self.refused(r"identity\.json must hold")
        self.write_identity({**self.identity, "env_denylist": "DB_PASSWORD"})
        self.refused(r"identity\.json must hold")
        self.write_identity(self.identity)
        # The machine ID is read as the guard reads it, through the same check.
        self.machine_id.chmod(0o666)
        self.refused(r"Unsafe file type, owner or permissions")

    def test_run_id_comes_from_identity_config(self):
        self.write_identity({**self.identity, "run_id": "cutover-20261212-b"})
        self.assertEqual("cutover-20261212-b", self.probe()["run_id"])

    def test_runs_as_installed_under_isolated_python(self):
        # The installed command line: /usr/bin/python3 -I -S <probe> --config <identity.json>, standard library
        # only. The fixture's by-uuid folder and machine ID stand in for the fixed host paths.
        bootstrap = ("import sys; from pathlib import Path; sys.path.insert(0, sys.argv[1]); import identity_probe; "
                     "identity_probe.BY_UUID = Path(sys.argv[2]); identity_probe.MACHINE_ID = Path(sys.argv[3]); "
                     "sys.exit(identity_probe.main(sys.argv[4:]))")
        result = subprocess.run([sys.executable, "-I", "-S", "-c", bootstrap, str(HERE), str(self.by_uuid),
                                 str(self.machine_id), "--config", str(self.identity_path)],
                                capture_output=True, text=True, env={"PATH": "/usr/bin:/bin"}, timeout=60)
        self.assertEqual((0, ""), (result.returncode, result.stderr))
        self.assertEqual(self.probe(), json.loads(result.stdout))
        # The script itself, as commands.identity runs it: it refuses anyone but root, and as root it
        # reads identity.json through the protected check before anything else.
        script = [sys.executable, "-I", "-S", str(PROBE), "--config", str(self.identity_path)]
        nobody = subprocess.run(["/usr/bin/setpriv", "--reuid=65534", "--regid=65534", "--clear-groups", *script],
                                capture_output=True, text=True, timeout=60)
        self.assertEqual((1, ""), (nobody.returncode, nobody.stdout))
        self.assertIn("runs as root", nobody.stderr)
        self.identity_path.chmod(0o666)
        unsafe = subprocess.run(script, capture_output=True, text=True, timeout=60)
        self.assertEqual((1, ""), (unsafe.returncode, unsafe.stdout))
        self.assertIn("Unsafe file type, owner or permissions", unsafe.stderr)


@unittest.skipUnless(os.name == "posix", "Host config paths are POSIX absolute paths")
class DenylistContractTests(unittest.TestCase):
    """The probe uses identity.json's denylist as given; load_config is what ties it to host.json."""

    def test_denylist_differing_from_host_config_is_refused_by_load_config(self):
        config = example_config()
        self.assertEqual(config, load(config))
        identity = example_identity(config)
        for case, denylist in {
            "password missing (the v1 bug)": [key for key in DENYLIST if key != "DB_PASSWORD"],
            "night flag missing": [key for key in DENYLIST if key != "SCHEDULER_HOLD_ENABLED"],
            "extra key hidden": DENYLIST + ["APP_KEY"],
        }.items():
            with self.subTest(case=case):
                # The shape alone is fine; only the host config check refuses it.
                adapter.load_identity_config(json.dumps({**identity, "env_denylist": denylist}).encode())
                with self.assertRaisesRegex(guard.Refused, "env_denylist must equal"):
                    load(config, {**identity, "env_denylist": denylist})

    def test_identity_command_reads_the_identity_config_with_this_probe(self):
        config = example_config()
        command = config["commands"]["identity"]
        self.assertEqual(["/usr/bin/python3", "-I", "-S", "/opt/repro-cutover-fence/production/identity_probe.py",
                          "--config", config["paths"]["identity_config"]], command)
        self.assertEqual(PROBE.name, Path(command[3]).name)


@unittest.skipUnless(LINUX_ROOT and LOOP_DEVICES, "Needs Linux root, --privileged and REPRO_IDENTITY_LOOP_DEVICES=1")
class RealFilesystemTests(unittest.TestCase):
    """A real ext4 filesystem whose block device number changes between two mounts (design 1.1, HO-M6)."""

    def setUp(self):
        for tool in ("mkfs.ext4", "losetup", "mount", "umount", "blkid"):
            if shutil.which(tool) is None:
                self.skipTest(f"{tool} is not installed")
        self.root = fixture_root(self)
        self.image = Path(tempfile.mkdtemp(prefix="repro-identity-image-")) / "fs.img"
        self.addCleanup(shutil.rmtree, self.image.parent, True)
        self.mount = self.root / "mnt"
        self.by_uuid = self.root / "by-uuid"
        for folder in (self.mount, self.by_uuid):
            folder.mkdir(mode=0o755)
        with open(self.image, "wb") as stream:
            stream.truncate(32 * 1024 * 1024)
        subprocess.run(["mkfs.ext4", "-q", "-F", "-U", FS_UUID, str(self.image)], check=True)
        self.machine = hashlib.sha256(b"loop fixture machine\n").hexdigest()

    def attach(self) -> str:
        device = subprocess.run(["losetup", "--find", "--show", str(self.image)], capture_output=True, text=True,
                                check=True).stdout.strip()
        self.addCleanup(subprocess.run, ["losetup", "--detach", device], capture_output=True)
        return device

    def mounted(self, device: str) -> None:
        subprocess.run(["mount", device, str(self.mount)], check=True)
        self.addCleanup(subprocess.run, ["umount", str(self.mount)], capture_output=True)
        # udev's link for this filesystem: by-uuid/<uuid> -> the block device that holds it now.
        link = self.by_uuid / FS_UUID
        link.unlink(missing_ok=True)
        link.symlink_to(device)

    def source(self, path: Path) -> str:
        return identity_probe.source_sha256(path, by_uuid=self.by_uuid, machine_id_sha256=self.machine)

    def test_remount_through_another_loop_device_keeps_source_identity(self):
        first = self.attach()
        self.mounted(first)
        self.assertEqual(FS_UUID, subprocess.run(["blkid", "-o", "value", "-s", "UUID", first], capture_output=True,
                                                 text=True, check=True).stdout.strip())
        database = self.mount / "database.sqlite"
        with sqlite3.connect(database) as connection:
            connection.execute("CREATE TABLE shoots (id INTEGER PRIMARY KEY)")
        connection.close()
        before, info = self.source(database), os.lstat(database)
        self.assertEqual(os.stat(first).st_rdev, info.st_dev)
        self.assertEqual(canonical_sha256({"kind": "sqlite-file", "path": str(database), "fs_uuid": FS_UUID,
                                           "st_ino": info.st_ino, "machine_id_sha256": self.machine}), before)
        subprocess.run(["umount", str(self.mount)], check=True)
        # Keep the first device attached, so the second mount gets another device number.
        second = self.attach()
        self.assertNotEqual(first, second)
        self.mounted(second)
        moved = os.lstat(database)
        self.assertNotEqual(info.st_dev, moved.st_dev)
        self.assertEqual(info.st_ino, moved.st_ino)
        self.assertEqual(before, self.source(database))
        # A stale link to the first device names nothing that holds the file now.
        (self.by_uuid / FS_UUID).unlink()
        (self.by_uuid / FS_UUID).symlink_to(first)
        with self.assertRaisesRegex(guard.Refused, "No filesystem UUID"):
            self.source(database)
        (self.by_uuid / FS_UUID).unlink()
        (self.by_uuid / FS_UUID).symlink_to(second)
        # A file renamed into place on the same filesystem is another file.
        shutil.copyfile(database, self.mount / "restored.sqlite")
        os.rename(self.mount / "restored.sqlite", database)
        self.assertNotEqual(before, self.source(database))


if __name__ == "__main__":
    unittest.main()
