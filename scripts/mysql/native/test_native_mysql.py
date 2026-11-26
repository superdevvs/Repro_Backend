"""No Docker, MySQL process, sudo or host modification; temporary fixtures only."""
import importlib.util
from contextlib import contextmanager
import json
import os
from pathlib import Path
import shutil
import stat
import sys
import tempfile
import types
import unittest
from unittest.mock import MagicMock, mock_open, patch

SOURCE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location("native_mysql", SOURCE / "native_mysql.py")
native = importlib.util.module_from_spec(spec)
spec.loader.exec_module(native)


class NativePreparationTests(unittest.TestCase):
    @contextmanager
    def posix_modes(self, modes):
        """Model POSIX ownership/modes on Windows; bytes and traversal remain real."""
        original = Path.lstat
        changes = []

        def lstat(path, *args, **kwargs):
            values = list(original(path, *args, **kwargs))
            values[0] = stat.S_IFMT(values[0]) | modes.get(path, stat.S_IMODE(values[0]))
            values[4] = 0
            return os.stat_result(values)

        def chmod(path, mode):
            changes.append((Path(path), mode))
            modes[Path(path)] = mode

        with patch.object(Path, "lstat", lstat), patch.object(native.os, "chmod", side_effect=chmod):
            yield changes

    def bundle_modes(self, bundle):
        modes = {path: 0o755 if path.is_dir() else 0o644 for path in (bundle, *bundle.rglob("*"))}
        for name in native.CLIENTS:
            modes[bundle / "bin" / name] = 0o755
        return modes

    def bundle(self, root):
        path = Path(root) / "bundle"
        (path / "bin").mkdir(parents=True)
        for name in native.CLIENTS:
            (path / "bin" / name).write_bytes(b"fixture binary " + name.encode())
        for resource in native.SERVER_RESOURCES:
            target = path / resource
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(b"fixture Noble runtime resource")
        return path

    def test_manifest_covers_clients_and_changes_on_tamper(self):
        with tempfile.TemporaryDirectory() as root:
            bundle = self.bundle(root)
            before = native.inventory(bundle)
            (bundle / "bin/mysql").write_bytes(b"changed")
            self.assertNotEqual(before, native.inventory(bundle))

    def test_missing_matching_client_is_refused(self):
        with tempfile.TemporaryDirectory() as root:
            bundle = self.bundle(root)
            (bundle / "bin/mysqlbinlog").unlink()
            with self.assertRaisesRegex(native.Refusal, "missing matching"):
                native.inventory(bundle)

    def test_missing_relocated_noble_server_resource_is_refused(self):
        with tempfile.TemporaryDirectory() as root:
            bundle = self.bundle(root)
            (bundle / native.SERVER_PRIVATE_LIBRARY).unlink()
            with self.assertRaisesRegex(native.Refusal, "Noble server resource"):
                native.inventory(bundle)

    def test_copied_nested_directories_are_traversable_without_changing_staging_or_manifest(self):
        with tempfile.TemporaryDirectory() as root:
            staged = self.bundle(root)
            copied = Path(root) / "installed"
            shutil.copytree(staged, copied)
            modes = {**self.bundle_modes(staged), **self.bundle_modes(copied)}
            for bundle in (staged, copied):
                modes[bundle / "lib/mysql"] = 0o700
                modes[bundle / "lib/mysql/private"] = 0o700
            with self.posix_modes(modes) as changes:
                manifest = native.inventory(staged)
                original_manifest = json.dumps(manifest, sort_keys=True)
                native.normalize_copied_bundle(copied, manifest)
                native.verify_installed_bundle_access(copied)
                self.assertEqual(native.inventory(copied), manifest)
                self.assertEqual(native.inventory(staged), manifest)
                self.assertEqual(json.dumps(manifest, sort_keys=True), original_manifest)
                self.assertEqual(modes[staged / "lib/mysql"], 0o700)
                self.assertEqual(modes[staged / "lib/mysql/private"], 0o700)
                self.assertTrue(changes)
                for path, mode in changes:
                    self.assertTrue(path == copied or path.is_relative_to(copied))
                    self.assertTrue(path.is_dir())
                    self.assertEqual(mode, 0o755)
                self.assertEqual(modes[copied / native.SERVER_PRIVATE_LIBRARY], 0o644)
                self.assertEqual(modes[copied / "bin/mysqld"], 0o755)

    def test_unreadable_vendor_file_or_nonexecutable_client_refuses_before_execution(self):
        for relative, mode, reason in ((native.SERVER_PRIVATE_LIBRARY, 0o600, "service-readable"),
                                       (Path("bin/mysql"), 0o644, "service-executable")):
            with self.subTest(path=relative), tempfile.TemporaryDirectory() as root:
                bundle = self.bundle(root)
                modes = self.bundle_modes(bundle)
                modes[bundle / relative] = mode
                with self.posix_modes(modes) as changes:
                    manifest = Path(root) / "manifest.json"
                    manifest.write_text(json.dumps(native.inventory(bundle)))
                    with patch.object(native, "protected"), patch.object(native, "command") as execute:
                        with self.assertRaisesRegex(native.Refusal, reason):
                            native.verify_bundle(bundle, manifest, native.digest(manifest))
                        execute.assert_not_called()
                    self.assertEqual(changes, [])

    def test_installed_guard_rejects_root_only_nested_directory_and_library(self):
        for relative, mode in ((Path("lib/mysql"), 0o700), (native.SERVER_PRIVATE_LIBRARY, 0o600)):
            with self.subTest(path=relative), tempfile.TemporaryDirectory() as root:
                bundle = self.bundle(root)
                modes = self.bundle_modes(bundle)
                modes[bundle / relative] = mode
                with self.posix_modes(modes), self.assertRaisesRegex(native.Refusal, "service-readable"):
                    native.verify_installed_bundle_access(bundle)

    def test_normalization_refuses_tampering_before_chmod(self):
        with tempfile.TemporaryDirectory() as root:
            bundle = self.bundle(root)
            with self.posix_modes(self.bundle_modes(bundle)) as changes:
                manifest = native.inventory(bundle)
                (bundle / "bin/mysql").write_bytes(b"changed")
                with self.assertRaisesRegex(native.Refusal, "copied bundle verification failed"):
                    native.normalize_copied_bundle(bundle, manifest)
                self.assertEqual(changes, [])

    def test_normalization_never_chmods_contained_symlink(self):
        with tempfile.TemporaryDirectory() as root:
            bundle = self.bundle(root)
            link = bundle / "lib/mysql/private/libprotobuf-link.so"
            try:
                link.symlink_to(native.SERVER_PRIVATE_LIBRARY.name)
            except (OSError, NotImplementedError):
                self.skipTest("host does not permit fixture symlinks")
            with self.posix_modes(self.bundle_modes(bundle)) as changes:
                manifest = native.inventory(bundle)
                native.normalize_copied_bundle(bundle, manifest)
                self.assertNotIn(link, [path for path, _ in changes])
                self.assertEqual(os.readlink(link), native.SERVER_PRIVATE_LIBRARY.name)
                self.assertEqual(native.inventory(bundle), manifest)

    def test_noble_server_requires_t64_and_its_own_protobuf(self):
        with tempfile.TemporaryDirectory() as root:
            bundle = self.bundle(root)
            good = ("libaio.so.1t64 => /lib/x86_64-linux-gnu/libaio.so.1t64 (0x1)\n"
                    f"libprotobuf-lite.so.24.4.0 => {bundle / native.SERVER_PRIVATE_LIBRARY} (0x2)\n")
            native.verify_noble_server_libraries(bundle, good)
            with self.assertRaisesRegex(native.Refusal, "libaio.so.1t64"):
                native.verify_noble_server_libraries(bundle, good.replace(".1t64", ".1"))
            with self.assertRaisesRegex(native.Refusal, "bundled private protobuf"):
                native.verify_noble_server_libraries(bundle, good.replace(str(bundle), "/usr"))

    def test_manifest_rejects_path_escape_and_wrong_version(self):
        for path in ("../outside", "/etc/mysql/my.cnf", "bin/../../outside", "..\\outside"):
            with self.subTest(path=path), self.assertRaises(native.Refusal):
                native.validate_manifest({"version": native.VERSION, "files": {path: {}}})
        with self.assertRaises(native.Refusal):
            native.validate_manifest({"version": "8.0.46", "files": {}})

    def test_staged_bundle_mismatch_is_detected_before_execution(self):
        with tempfile.TemporaryDirectory() as root:
            bundle = self.bundle(root)
            manifest = Path(root) / "manifest.json"
            manifest.write_text(json.dumps(native.inventory(bundle)))
            with patch.object(native, "protected"), patch.object(native, "command") as execute:
                with self.assertRaisesRegex(native.Refusal, "manifest hash mismatch"):
                    native.verify_bundle(bundle, manifest, "0" * 64)
                execute.assert_not_called()

    def test_existing_install_is_never_adopted(self):
        with patch.object(native.os.path, "lexists", return_value=True):
            with self.assertRaisesRegex(native.Refusal, "no overwrite/adoption"):
                native.absent_targets()

    def test_uid_collision_and_wrong_web_group_are_refused(self):
        def missing(_):
            raise KeyError
        fake_pwd = types.SimpleNamespace(getpwnam=missing, getpwuid=lambda _: object())
        fake_grp = types.SimpleNamespace(getgrnam=missing, getgrgid=missing)
        with patch.dict(sys.modules, {"pwd": fake_pwd, "grp": fake_grp}):
            with self.assertRaisesRegex(native.Refusal, "already allocated: 64084"):
                native.unused_account()
        fake_pwd.getpwuid = missing
        fake_grp.getgrnam = lambda name: types.SimpleNamespace(gr_gid=999) if name == "www-data" else missing(name)
        with patch.dict(sys.modules, {"pwd": fake_pwd, "grp": fake_grp}):
            with self.assertRaisesRegex(native.Refusal, "GID is not 33"):
                native.unused_account()

    def test_non_linux_preflight_refuses_before_host_checks(self):
        with patch.object(native.platform, "system", return_value="Windows"):
            with self.assertRaisesRegex(native.Refusal, "Ubuntu amd64"):
                native.host_identity("host", "0" * 64)

    def test_child_commands_have_clean_environment_and_redacted_failures(self):
        result = type("Result", (), {"returncode": 1, "stdout": "", "stderr": "password=SECRET"})()
        with patch.object(native.subprocess, "run", return_value=result) as run:
            with self.assertRaises(native.Refusal) as caught:
                native.command(["/test/bin"])
            self.assertNotIn("SECRET", str(caught.exception))
            self.assertEqual(run.call_args.kwargs["env"], native.SAFE_ENV)
            self.assertNotIn("LD_PRELOAD", run.call_args.kwargs["env"])

    def test_live_check_rejects_wrong_instance_and_unsafe_settings(self):
        identity = {"server_uuid": "00000000-0000-4000-8000-000000000001",
                    "datadir": str(native.DATA) + "/"}
        values = [identity["server_uuid"], native.VERSION, identity["datadir"], str(native.SOCKET),
                  "1", "1", "1", "0", "OFF", "ON", "ON", "0", "1", "ON", "0"]
        native.validate_live_row("\t".join(values), identity)
        for index in range(len(values)):
            changed = values.copy()
            changed[index] = "wrong"
            with self.subTest(column=index), self.assertRaises(native.Refusal):
                native.validate_live_row("\t".join(changed), identity)

    def test_failed_authentication_never_flushes_logs(self):
        with patch.object(native, "root_only"), patch.object(native, "verify_start", return_value={}), \
             patch.object(native, "verify_runtime_access"), \
             patch.object(native, "sql", side_effect=native.Refusal("offline")) as sql:
            with self.assertRaises(native.Refusal):
                native.check(flush=True)
            self.assertEqual(sql.call_count, 1)
            self.assertTrue(sql.call_args.args[0].startswith("SELECT "))

    def acl_tool(self, name):
        tool = MagicMock(spec=Path)
        tool.name = name
        tool.__str__.return_value = "/usr/bin/" + name
        tool.is_file.return_value = True
        tool.resolve.return_value = tool
        tool.stat.return_value = types.SimpleNamespace(st_mode=stat.S_IFREG | 0o755)
        return tool

    def test_acl_prerequisites_only_execute_protected_version_checks(self):
        tools = (self.acl_tool("setfacl"), self.acl_tool("getfacl"))
        with patch.object(native, "ACL_TOOLS", tools), patch.object(native, "protected") as protect, \
             patch.object(native, "command", side_effect=["setfacl 2.3.2", "getfacl 2.3.2"]) as execute:
            native.verify_acl_tools()
            self.assertEqual(protect.call_count, 2)
            self.assertEqual([call.args[0] for call in execute.call_args_list],
                             [["/usr/bin/setfacl", "--version"], ["/usr/bin/getfacl", "--version"]])

    def test_missing_unsafe_or_nonexecutable_acl_tool_refuses_without_execution(self):
        for failure in ("missing", "unsafe", "nonexecutable"):
            with self.subTest(failure=failure):
                tool = self.acl_tool("setfacl")
                if failure == "missing":
                    tool.is_file.return_value = False
                if failure == "nonexecutable":
                    tool.stat.return_value = types.SimpleNamespace(st_mode=stat.S_IFREG | 0o644)
                with patch.object(native, "ACL_TOOLS", (tool,)), \
                     patch.object(native, "protected", side_effect=native.Refusal("unsafe") if failure == "unsafe" else None), \
                     patch.object(native, "command") as execute:
                    with self.assertRaises(native.Refusal):
                        native.verify_acl_tools()
                    execute.assert_not_called()

    @contextmanager
    def runtime_access_fixture(self, acl, *, directory=None, socket=None):
        directory = directory or types.SimpleNamespace(st_mode=stat.S_IFDIR | 0o750, st_uid=64084, st_gid=64084)
        socket = socket or types.SimpleNamespace(st_mode=stat.S_IFSOCK | 0o777, st_uid=64084, st_gid=64084)
        with patch.object(Path, "lstat", lambda path: directory if path == native.RUN else socket), \
             patch.object(native, "verify_acl_tools"), patch.object(native, "command", return_value=acl):
            yield

    def test_runtime_acceptance_requires_exact_traversal_acl(self):
        expected = "user::rwx\ngroup::r-x\ngroup:33:--x\nmask::r-x\nother::---"
        with self.runtime_access_fixture(expected):
            native.verify_runtime_access()
        for bad in (expected.replace("group:33:--x\n", ""),
                    expected.replace("group:33:--x", "group:33:r-x"),
                    expected + "\nuser:1000:rwx", expected + "\ngroup:44:--x",
                    expected + "\ndefault:user::rwx", expected + "\ngroup:33:--x"):
            with self.subTest(acl=bad), self.runtime_access_fixture(bad), \
                 self.assertRaisesRegex(native.Refusal, "exact www-data traversal ACL"):
                native.verify_runtime_access()

    def test_runtime_acceptance_refuses_owner_mode_type_and_socket_drift(self):
        for kind, info in (("directory", types.SimpleNamespace(st_mode=stat.S_IFDIR | 0o750, st_uid=64084, st_gid=33)),
                           ("directory", types.SimpleNamespace(st_mode=stat.S_IFDIR | 0o755, st_uid=64084, st_gid=64084)),
                           ("directory", types.SimpleNamespace(st_mode=stat.S_IFLNK | 0o750, st_uid=64084, st_gid=64084)),
                           ("socket", types.SimpleNamespace(st_mode=stat.S_IFSOCK | 0o777, st_uid=0, st_gid=64084)),
                           ("socket", types.SimpleNamespace(st_mode=stat.S_IFSOCK | 0o770, st_uid=64084, st_gid=64084))):
            with self.subTest(kind=kind, info=info), self.runtime_access_fixture("", **{kind: info}), \
                 self.assertRaisesRegex(native.Refusal, "ownership or mode"):
                native.verify_runtime_access()

    def test_acl_refusal_prevents_sql_and_log_flushing(self):
        with patch.object(native, "root_only"), patch.object(native, "verify_start", return_value={}), \
             patch.object(native, "verify_runtime_access", side_effect=native.Refusal("ACL drift")), \
             patch.object(native, "sql") as sql:
            with self.assertRaisesRegex(native.Refusal, "ACL drift"):
                native.check(flush=True)
            sql.assert_not_called()

    def test_unit_dropin_and_wrong_unit_source_are_refused(self):
        for fragment, overrides in ((str(native.SYSTEMD / native.UNIT), "/etc/systemd/system/service.d/custom.conf"),
                                    ("/usr/lib/systemd/system/other.service", "")):
            with patch.object(native, "command", side_effect=[fragment, overrides]):
                with self.assertRaisesRegex(native.Refusal, "unexpected unit"):
                    native.verify_unit(native.UNIT)

    def test_nonempty_data_prevents_initialization(self):
        with tempfile.TemporaryDirectory() as root:
            path = Path(root)
            (path / "ibdata1").write_text("do not overwrite")
            with patch.object(native, "root_only"), patch.object(native, "installed_receipt"), \
                 patch.object(native, "DATA", path), patch.object(native, "command") as command:
                with self.assertRaisesRegex(native.Refusal, "not empty"):
                    native.initialize()
                command.assert_not_called()

    def test_no_unconditional_destructive_undo_or_service_start(self):
        source = (SOURCE / "native_mysql.py").read_text()
        self.assertNotIn("rmtree(", source)
        self.assertNotIn('"enable"', source)
        self.assertNotIn('"restart"', source)
        self.assertNotIn('"start"', source)

    def test_service_is_socket_only_and_capped(self):
        unit = (SOURCE / native.UNIT).read_text()
        for required in ("PrivateNetwork=yes", "RestrictAddressFamilies=AF_UNIX", "MemoryMax=8G",
                         "CPUQuota=400%", "NoNewPrivileges=yes", "verify-start", "wait-ready"):
            self.assertIn(required, unit)
        self.assertNotIn("mysql.service", unit)
        self.assertNotIn("docker", unit.lower())
        self.assertIn("Group=repro-mysql84-native", unit)
        self.assertNotIn("Group=www-data", unit)
        self.assertNotIn("chgrp", unit)
        execution = [line for line in unit.splitlines() if line.startswith("Exec")]
        self.assertEqual(execution[-1],
                         "ExecStartPost=+/usr/bin/setfacl --modify=group:33:--x,mask::r-x /run/repro-mysql84-native")
        self.assertIn("wait-ready", execution[-2])
        self.assertIn("RuntimeDirectoryMode=0750", unit)
        config = (SOURCE / "mysql.cnf").read_text()
        for required in ("skip-networking", "mysqlx=OFF", "innodb-flush-log-at-trx-commit=1",
                         "sync-binlog=1", "binlog-expire-logs-auto-purge=OFF",
                         "persisted-globals-load=OFF", "innodb-dedicated-server=OFF",
                         f"plugin-dir={native.BINARY / native.SERVER_PLUGIN_DIR}".replace("\\", "/"),
                         f"lc-messages-dir={native.BINARY / native.SERVER_MESSAGE_DIR}".replace("\\", "/")):
            self.assertIn(required, config)

    def test_offline_check_precedes_logrotate_invocation(self):
        unit = (SOURCE / f"{native.ACCOUNT}-logrotate.service").read_text()
        self.assertLess(unit.index("ExecStartPre="), unit.index("ExecStart="))
        self.assertIn("native_mysql.py check", unit)
        rotation = (SOURCE / "logrotate.conf").read_text()
        self.assertIn("native_mysql.py flush-logs", rotation)
        self.assertNotIn("copytruncate", rotation)
        self.assertIn("rotate 7", rotation)


@unittest.skipUnless(sys.platform == "linux", "Linux mount metadata and no-follow directory descriptors")
class NativeStorageTests(unittest.TestCase):
    def setUp(self):
        self.originals = "/mnt/originals"
        self.uuid = "01234567-89ab-cdef-0123-456789abcdef"
        self.identity = {"server_uuid": "00000000-0000-4000-8000-000000000001",
                         "version": native.VERSION, "datadir": str(native.DATA) + "/"}
        self.data_dev, self.originals_dev = os.makedev(8, 32), os.makedev(8, 16)
        self.mounts = [{"id": 1, "device": "8:1", "root": "/", "target": "/"},
                       {"id": 12, "device": "8:16", "root": "/", "target": self.originals},
                       {"id": 13, "device": "8:32", "root": "/", "target": str(native.DATA)}]
        self.block = types.SimpleNamespace(st_mode=stat.S_IFBLK | 0o600, st_rdev=self.originals_dev)

    def metadata(self, path):
        is_data = path == native.DATA
        info = os.stat_result((stat.S_IFDIR | 0o700, 10 if is_data else 20,
                               self.data_dev if is_data else self.originals_dev,
                               2, native.UID if is_data else 0, native.GID if is_data else 0,
                               4096, 0, 0, 0))
        return info, 13 if is_data else 12

    @contextmanager
    def fixture(self):
        with patch.object(native, "root_only"), \
             patch.object(native, "verify_start", return_value=self.identity) as identity, \
             patch.object(native, "storage_mounts", side_effect=lambda: ("stable", self.mounts)) as mounts, \
             patch.object(native, "directory_metadata", side_effect=self.metadata) as metadata, \
             patch.object(Path, "resolve", return_value=Path("/dev/fixture-originals")), \
             patch.object(Path, "stat", return_value=self.block), \
             patch.object(native, "command") as command, patch.object(native.os, "chmod") as chmod:
            yield identity, mounts, metadata, command, chmod

    def test_actual_data_mount_governs_not_parent_and_no_permissions_or_sql_change(self):
        with self.fixture() as (identity, _, metadata, command, chmod):
            report = native.storage_check(self.originals, self.uuid)
            self.assertTrue(report["different_filesystems"] and report["read_only"])
            self.assertEqual(report["datadir_device"], "8:32")
            self.assertEqual(report["originals_device"], "8:16")
            self.assertEqual(identity.call_count, 2)
            self.assertEqual([call.args[0] for call in metadata.call_args_list],
                             [native.DATA, Path(self.originals), native.DATA, Path(self.originals)])
            command.assert_not_called()
            chmod.assert_not_called()

    def test_same_filesystem_bind_alias_is_refused_despite_different_paths_and_mount_ids(self):
        self.data_dev = self.originals_dev
        self.mounts[2]["device"] = "8:16"
        with self.fixture(), self.assertRaisesRegex(native.Refusal, "different filesystems"):
            native.storage_check(self.originals, self.uuid)

    def test_unavailable_wrong_uuid_nonblock_subtree_and_ambiguous_mounts_refuse(self):
        cases = ("unmounted", "wrong-uuid", "not-block", "originals-bind", "data-bind", "alias", "unknown-fd")
        for case in cases:
            with self.subTest(case=case):
                self.setUp()
                if case == "unmounted":
                    self.mounts[1]["target"] = "/mnt/different"
                elif case == "wrong-uuid":
                    self.block.st_rdev = os.makedev(8, 48)
                elif case == "not-block":
                    self.block.st_mode = stat.S_IFREG | 0o600
                elif case == "originals-bind":
                    self.mounts[1]["root"] = "/some-subdirectory"
                elif case == "data-bind":
                    self.mounts[2]["root"] = "/some-subdirectory"
                elif case == "alias":
                    self.mounts.append({**self.mounts[1], "id": 14, "target": "/mnt/alias"})
                else:
                    self.mounts[2]["id"] = 19
                with self.fixture(), self.assertRaises(native.Refusal):
                    native.storage_check(self.originals, self.uuid)

    def test_fd_mount_identity_wins_over_hidden_longest_prefix(self):
        self.mounts.append({"id": 99, "device": "8:32", "root": "/hidden-subtree", "target": str(native.DATA)})
        with self.fixture():
            self.assertEqual(native.storage_check(self.originals, self.uuid)["datadir_device"], "8:32")

    def test_changed_mount_metadata_or_instance_during_check_refuses(self):
        for changed in ("mount-table", "instance", "directory"):
            with self.subTest(changed=changed), self.fixture() as (identity, mounts, metadata, _, _):
                if changed == "mount-table":
                    mounts.side_effect = [("before", self.mounts), ("after", self.mounts)]
                elif changed == "instance":
                    identity.side_effect = [self.identity, {**self.identity, "server_uuid": "changed"}]
                else:
                    data, originals = self.metadata(native.DATA), self.metadata(Path(self.originals))
                    metadata.side_effect = [data, originals, (data[0], 44)]
                with self.assertRaisesRegex(native.Refusal, "changed during"):
                    native.storage_check(self.originals, self.uuid)

    def test_changed_uuid_symlink_mapping_refuses_even_when_old_device_still_exists(self):
        with self.fixture(), patch.object(Path, "resolve", side_effect=[
                Path("/dev/fixture-originals"), Path("/dev/replacement")]), \
             self.assertRaisesRegex(native.Refusal, "changed during"):
            native.storage_check(self.originals, self.uuid)

    def test_root_or_pinned_identity_failure_occurs_before_storage_reads(self):
        with patch.object(native, "root_only", side_effect=native.Refusal("administrator required")), \
             patch.object(native, "verify_start") as identity, patch.object(native, "storage_mounts") as mounts:
            with self.assertRaises(native.Refusal):
                native.storage_check(self.originals, self.uuid)
            identity.assert_not_called()
            mounts.assert_not_called()
        with self.fixture() as (identity, mounts, *_):
            identity.side_effect = native.Refusal("pinned identity failed")
            with self.assertRaises(native.Refusal):
                native.storage_check(self.originals, self.uuid)
            mounts.assert_not_called()

    def test_noncanonical_or_unreviewed_argument_refused(self):
        for path in ("/", "relative", "/mnt/../originals", "/mnt/./originals", "/mnt/originals/"):
            with self.subTest(path=path), self.fixture(), self.assertRaises(native.Refusal):
                native.storage_check(path, self.uuid)
        with self.fixture(), self.assertRaises(native.Refusal):
            native.storage_check(self.originals, "../../device")

    def test_real_directory_fd_reports_mount_and_preserves_private_mode(self):
        with tempfile.TemporaryDirectory() as root:
            path = Path(root)
            before = path.stat()
            info, mount_id = native.directory_metadata(path)
            self.assertEqual((info.st_dev, info.st_ino), (before.st_dev, before.st_ino))
            self.assertGreater(mount_id, 0)
            self.assertEqual(stat.S_IMODE(path.stat().st_mode), stat.S_IMODE(before.st_mode))
            alias = path / "alias"
            alias.symlink_to(path, target_is_directory=True)
            with self.assertRaises(native.Refusal):
                native.directory_metadata(alias)
            with self.assertRaises(OSError):
                native.directory_metadata(path / "missing")

    def test_mount_parser_decodes_paths_and_refuses_missing_or_malformed_input(self):
        filesystem = "12 1 8:16 / /mnt/originals\\040drive rw,relatime - ext4 /dev/sdb1 rw\n"
        namespace = "22 1 0:4 net:[4026532707] /run/docker/netns/example rw shared:3 - nsfs nsfs rw\n"
        with patch.object(Path, "open", mock_open(read_data=filesystem + namespace)):
            raw, mounts = native.storage_mounts()
        self.assertEqual(raw, filesystem + namespace)
        self.assertEqual(mounts, [
            {"id": 12, "device": "8:16", "root": "/", "target": "/mnt/originals drive"},
            {"id": 22, "device": "0:4", "root": "net:[4026532707]", "target": "/run/docker/netns/example"},
        ])
        for text in ("", "invalid", "x" * (1024 * 1024 + 1),
                     namespace.replace("nsfs nsfs", "ext4 /dev/sdb1"),
                     namespace.replace("[4026532707]", "[invalid]"),
                     namespace.replace("/run/docker", "run/docker"),
                     namespace.replace("nsfs nsfs rw", "nsfs nsfs")):
            with patch.object(Path, "open", mock_open(read_data=text)), self.assertRaises(native.Refusal):
                native.storage_mounts()


if __name__ == "__main__":
    unittest.main()
