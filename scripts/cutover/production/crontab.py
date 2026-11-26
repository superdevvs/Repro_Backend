"""Route application cron entries through the admission wrapper.

Each entry whose command matches a rule's profile is rewritten to run the
fixed wrapper with that profile. The schedule, the output redirection and any
trailing marker comment are kept, so installer idempotency checks still work.
Entries that touch the application but match no rule are returned instead of
being installed, so an unknown writer is never left unfenced by accident.
Entries that chain, pipe or substitute another command after the matched one
are returned too: the wrapper runs only the profile's command, so the rest
would be dropped silently.
"""

from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path

SCHEDULE = re.compile(r"^(\s*(?:@\w+|\S+\s+\S+\s+\S+\s+\S+\s+\S+))\s+(\S.*)$")
TRAILER = re.compile(r"((?:\s+(?:>>?|2>>?)\s*[^\s'\"]+)*(?:\s+2>&1)?)(\s+#[^'\"]*)?$")
ASSIGNMENT = re.compile(r"^\s*[A-Za-z_][A-Za-z0-9_]*\s*=")
# Shell control after the matched command: a chained, piped or substituted command.
CHAINED = re.compile(r"[;&|`]|\$\(")


def wrap(text: str, rules: dict, wrapper: str) -> tuple[str, list[str]]:
    compiled = [(re.compile(rule["match"]), rule) for rule in rules["rules"]]
    markers = rules["application_markers"]
    lines, unknown = [], []
    for line in text.splitlines():
        parsed = SCHEDULE.match(line)
        if not line.strip() or line.lstrip().startswith("#") or ASSIGNMENT.match(line) or parsed is None:
            lines.append(line)
            continue
        schedule, command = parsed.groups()
        if command.startswith(wrapper + " "):
            lines.append(line)
            continue
        found = next(((match, rule) for pattern, rule in compiled if (match := pattern.search(command))), None)
        rule = None if found is None else found[1]
        trailer = TRAILER.search(command)
        if rule is not None and "allow" not in rule \
                and CHAINED.search(command[found[0].end():trailer.start()]):
            # The wrapper runs only the profile's command; a chained command would be dropped.
            unknown.append(line)
            lines.append(line)
        elif rule is None:
            if any(marker in command for marker in markers):
                unknown.append(line)
            lines.append(line)
        elif "allow" in rule:
            lines.append(line)
        else:
            lines.append(f"{schedule} {wrapper} {rule['profile']}{trailer.group(1)}{trailer.group(2) or ''}")
    return "\n".join(lines) + ("\n" if text.endswith("\n") else ""), unknown


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--rules", required=True, type=Path)
    parser.add_argument("--wrapper", required=True)
    args = parser.parse_args(argv)
    wrapped, unknown = wrap(sys.stdin.read(), json.loads(args.rules.read_text(encoding="utf-8")), args.wrapper)
    if unknown:
        print("Refusing: these application cron entries match no admission rule:", file=sys.stderr)
        for line in unknown:
            print(f"  {line}", file=sys.stderr)
        return 1
    sys.stdout.write(wrapped)
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
