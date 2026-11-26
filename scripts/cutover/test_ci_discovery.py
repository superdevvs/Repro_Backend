"""CI runs every Python test folder of the cutover and MySQL tools.

The folders have no __init__.py, so `unittest discover -s scripts/cutover`
finds only the tests directly in scripts/cutover. Each folder with tests needs
its own discover line in the "Test database migration contracts" step of the
checks job in .github/workflows/deploy.yml, and neither that step nor the job
may be skipped (if:) or allowed to fail (continue-on-error:).
"""

from pathlib import Path
import re
import unittest


ROOT = Path(__file__).resolve().parents[2]
WORKFLOW = ROOT / ".github" / "workflows" / "deploy.yml"
DISCOVER = re.compile(r"^\s*python3 (?:-B )?-m unittest discover -s (\S+)\s*$", re.M)
STEP = "Test database migration contracts"


def checks_step(text: str, name: str) -> tuple[list[str], list[str]]:
    """The checks job's own lines (before its steps) and the lines of its step called name."""
    lines = text.splitlines()
    start = lines.index("  checks:")
    end = next((index for index in range(start + 1, len(lines)) if re.match(r"  \S", lines[index])), len(lines))
    job = lines[start + 1:end]
    starts = [index for index, line in enumerate(job) if line.startswith("      - ")] + [len(job)]
    for first, after in zip(starts, starts[1:]):
        if job[first] == f"      - name: {name}":
            return job[:job.index("    steps:")], job[first:after]
    raise AssertionError(f"The checks job has no step called {name!r}")


class CiDiscoveryTests(unittest.TestCase):
    def test_every_cutover_and_mysql_test_folder_has_a_discover_line(self):
        folders = {path.parent.relative_to(ROOT).as_posix()
                   for tree in ("scripts/cutover", "scripts/mysql") for path in (ROOT / tree).rglob("test_*.py")}
        self.assertIn("scripts/cutover/production", folders)
        job, step = checks_step(WORKFLOW.read_text(encoding="utf-8"), STEP)
        self.assertEqual([], [line.strip() for line in job + step if re.match(r"\s*(?:if|continue-on-error):", line)],
                         "A skipped or failure-tolerant step runs nothing that can fail CI.")
        discovered = set(DISCOVER.findall("\n".join(step)))
        self.assertEqual([], sorted(folders - discovered), "These test folders are never run by CI.")


if __name__ == "__main__":
    unittest.main()
