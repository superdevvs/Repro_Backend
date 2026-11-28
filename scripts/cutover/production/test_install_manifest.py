"""Check the operator's W1 file list against the release tree."""
import re
import unittest
from pathlib import Path, PurePosixPath


ROOT = Path(__file__).resolve().parent
MANIFEST = ROOT / "INSTALL-W1.md"
FILE_ROW = re.compile(r"^\| `([^`]+)` \| `([^`]+)` \| (\w+) \|$", re.MULTILINE)


class InstallManifestTest(unittest.TestCase):
    def test_install_manifest_lists_every_production_file_and_only_those(self):
        base = FILE_ROW.findall(MANIFEST.read_text(encoding="utf-8"))
        cutover = FILE_ROW.findall((ROOT / "INSTALL-cutover.md").read_text(encoding="utf-8"))
        self.assertTrue(base)
        self.assertTrue(cutover)
        self.assertTrue({"retire.py", "wayback_policy.py", "mysql_reopen.py"} <= {row[0] for row in cutover})
        entries = base + cutover
        listed = {source for source, _, _ in entries}
        expected = {p.name for p in ROOT.glob("*.py") if not p.name.startswith("test_")}
        # The historical writer inventory predates the smoke and replay profiles.
        expected.update(p.name for p in ROOT.glob("*.example.json") if p.name != "wrappers.example.json")
        expected.update({"../fence/guard.py", "../runner.py"})
        for folder in ("capture", "sbin", "sudoers", "systemd", "tmpfiles.d", "supervisor", "nginx", "php-fpm"):
            expected.update(p.relative_to(ROOT).as_posix() for p in (ROOT / folder).rglob("*") if p.is_file())
        self.assertEqual(expected, listed)
        self.assertEqual(len(entries), len(listed), "duplicate source")
        self.assertEqual(len(entries), len({dest for _, dest, _ in entries}), "duplicate destination")
        for engine in ("sqlite", "mysql"):
            self.assertIn((f"wrappers.{engine}.example.json", f"/etc/repro-cutover-fence/wrappers.{engine}.json",
                           "render"), entries)
        for source, dest, mode in entries:
            with self.subTest(source=source):
                self.assertTrue((ROOT / source).is_file())
                self.assertTrue(PurePosixPath(dest).is_absolute())
                self.assertNotIn("..", PurePosixPath(dest).parts)
                self.assertIn(mode, {"0644", "0755", "0440", "render", "review"})
                if source.startswith("sudoers/"):
                    self.assertEqual(mode, "0440")
                if source.startswith("sbin/"):
                    self.assertEqual(mode, "0755")

    def test_offline_fixture_driver_matches_nightly_pin(self):
        import json
        wheel = json.loads((ROOT.parent / "nightly/nightly-config.json").read_text())["pymysql_wheel"]
        builder = (ROOT / "e2e/prepare-drill-image.sh").read_text(encoding="utf-8")
        self.assertIn("digest=" + wheel["sha256"] + "\n", builder)
        self.assertIn("name=" + PurePosixPath(wheel["path"]).name + "\n", builder)


if __name__ == "__main__":
    unittest.main()
