"""CI runs every Python test folder of the cutover and MySQL tools.

The folders have no __init__.py, so `unittest discover -s scripts/cutover`
finds only the tests directly in scripts/cutover. Each folder with tests needs
its own discover line in the checks job of .github/workflows/deploy.yml.
"""

from pathlib import Path
import re
import unittest


ROOT = Path(__file__).resolve().parents[2]
WORKFLOW = ROOT / ".github" / "workflows" / "deploy.yml"
DISCOVER = re.compile(r"^\s*python3 (?:-B )?-m unittest discover -s (\S+)\s*$", re.M)


class CiDiscoveryTests(unittest.TestCase):
    def test_every_cutover_and_mysql_test_folder_has_a_discover_line(self):
        folders = {path.parent.relative_to(ROOT).as_posix()
                   for tree in ("scripts/cutover", "scripts/mysql") for path in (ROOT / tree).rglob("test_*.py")}
        self.assertIn("scripts/cutover/production", folders)
        discovered = set(DISCOVER.findall(WORKFLOW.read_text(encoding="utf-8")))
        self.assertEqual([], sorted(folders - discovered), "These test folders are never run by CI.")


if __name__ == "__main__":
    unittest.main()
