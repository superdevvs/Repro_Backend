"""Route application cron entries through the admission wrapper, and back.

Each entry whose command matches a rule is rewritten to run the fixed wrapper
with that rule's profile. The rule names the exact command it replaces; the
wrapper runs only the profile's command, so a line whose command differs in
any way (something chained, piped or substituted after it, any other prefix
before it, another option) is returned instead of being installed, never
rewritten with the difference dropped. The schedule, the whitespace after it,
the output redirection and any trailing marker comment are kept, so installer
idempotency checks still work.

On production the deploy user's application lines run behind the storage
guard, `/usr/local/sbin/repro-storage-guard --live && <command>`. That exact
prefix, and no other, is carried over in front of the wrapper, so the guard
still runs before every wrapped command.

Entries that touch the application but match no rule are returned too, so an
unknown writer is never left unfenced by accident. A rule that leaves its
lines as they are (allow) names its exact command as well: a line is kept
only when it holds that command alone, behind the same guard and before the
same kind of trailer, so nothing rides along unfenced. `--reverse` turns wrapped
lines back into the rule's command byte for byte; the forward rewrite refuses
any line it could not turn back exactly.
"""

from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path

SCHEDULE = re.compile(r"^(\s*(?:@\w+|\S+\s+\S+\s+\S+\s+\S+\s+\S+))(\s+)(\S.*)$")
# What may follow a rule's command: output redirections to plain paths, 2>&1 and a marker comment. Nothing in
# it can run or feed another command (no ;, &, |, $, quotes or backquotes in a target, no cron % anywhere).
TRAILER = re.compile(r"((?:\s+(?:>>?|2>>?)\s*[A-Za-z0-9_./-]+)*(?:\s+2>&1)?)(\s+#[^'\"%]*)?$")
ASSIGNMENT = re.compile(r"^\s*[A-Za-z_][A-Za-z0-9_]*\s*=")
# The only prefix carried over in front of the wrapper, byte for byte.
STORAGE_GUARD = "/usr/local/sbin/repro-storage-guard --live && "
STORAGE_GUARD_PATH = STORAGE_GUARD.split()[0]


def _checked(rules: dict) -> list[tuple[re.Pattern, dict]]:
    """The compiled rules; each names the exact command it either leaves as it is (allow) or replaces (profile)."""
    compiled = []
    for rule in rules["rules"]:
        keys = set(rule) if isinstance(rule, dict) else None
        if keys not in ({"match", "allow", "command"}, {"match", "profile", "command"}) \
                or not all(isinstance(value, str) and value for value in rule.values()):
            raise ValueError(f"Each cron rule needs match, command and either allow or profile: {rule!r}")
        if rule["command"].startswith(STORAGE_GUARD_PATH):
            raise ValueError(f"A cron rule's command starts with the storage guard, which is carried over "
                             f"separately; name the command after '{STORAGE_GUARD}': {rule!r}")
        compiled.append((re.compile(rule["match"]), rule))
    return compiled


def _parts(command: str) -> tuple[str, str, str]:
    """The exact storage guard in front (or nothing), the command it guards and the trailer after it."""
    guard = STORAGE_GUARD if command.startswith(STORAGE_GUARD) else ""
    trailer = TRAILER.search(command).start()
    return guard, command[len(guard):trailer], command[trailer:]


def _split(text: str) -> list[str]:
    """The lines without their newlines; unlike splitlines, no other character ends a line."""
    lines = text.split("\n")
    return lines[:-1] if text.endswith("\n") else lines


def _join(lines: list[str], text: str) -> str:
    return "\n".join(lines) + ("\n" if text.endswith("\n") else "")


def _unwrap_line(line: str, compiled: list, wrapper: str) -> str | None:
    """The original line for a wrapped one; None when its profile has no single rule (or it is not wrapped)."""
    parsed = SCHEDULE.match(line)
    if parsed is None:
        return None
    schedule, separator, command = parsed.groups()
    guard = STORAGE_GUARD if command.startswith(STORAGE_GUARD + wrapper + " ") else ""
    if not command.startswith(guard + wrapper + " "):
        return None
    trailer = TRAILER.search(command)
    profile = command[len(guard + wrapper + " "):trailer.start()]
    rules = [rule for _, rule in compiled if rule.get("profile") == profile]
    if len(rules) != 1:
        return None
    return f"{schedule}{separator}{guard}{rules[0]['command']}{command[trailer.start():]}"


def _is_wrapped(command: str, wrapper: str) -> bool:
    return command.startswith(wrapper + " ") or command.startswith(STORAGE_GUARD + wrapper + " ")


def wrap(text: str, rules: dict, wrapper: str) -> tuple[str, list[str]]:
    compiled = _checked(rules)
    markers = rules["application_markers"]
    lines, unknown = [], []
    for line in _split(text):
        parsed = SCHEDULE.match(line)
        if not line.strip() or line.lstrip().startswith("#") or ASSIGNMENT.match(line) or parsed is None:
            lines.append(line)
            continue
        schedule, separator, command = parsed.groups()
        if _is_wrapped(command, wrapper):
            lines.append(line)
            continue
        rule = next((rule for pattern, rule in compiled if pattern.search(command)), None)
        if rule is None:
            if any(marker in command for marker in markers):
                unknown.append(line)
            lines.append(line)
            continue
        guard, exact, trailer = _parts(command)
        if "allow" in rule:
            # Left as it is only when its command is exactly the rule's, alone or behind the exact storage guard,
            # with only the trailer after it; anything else chained to an allowed command is refused (review C2-8).
            if exact != rule["command"]:
                unknown.append(line)
            lines.append(line)
            continue
        wrapped = f"{schedule}{separator}{guard}{wrapper} {rule['profile']}{trailer}"
        # The wrapper runs only the profile's command. The wrapped line is installed only when it turns back
        # into exactly this line: the rule's command, alone or behind the exact storage guard. Any other
        # prefix, anything chained, piped or substituted after it, or another option would be dropped.
        if _unwrap_line(wrapped, compiled, wrapper) != line:
            unknown.append(line)
            lines.append(line)
        else:
            lines.append(wrapped)
    return _join(lines, text), unknown


def unwrap(text: str, rules: dict, wrapper: str) -> tuple[str, list[str]]:
    """The reverse of wrap: each wrapped line becomes its rule's command again, guard and trailer kept."""
    compiled = _checked(rules)
    lines, unknown = [], []
    for line in _split(text):
        parsed = SCHEDULE.match(line)
        if parsed is None or line.lstrip().startswith("#") or ASSIGNMENT.match(line) \
                or not _is_wrapped(parsed.group(3), wrapper):
            lines.append(line)
            continue
        original = _unwrap_line(line, compiled, wrapper)
        if original is None:
            unknown.append(line)
            lines.append(line)
        else:
            lines.append(original)
    return _join(lines, text), unknown


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--rules", required=True, type=Path)
    parser.add_argument("--wrapper", required=True)
    parser.add_argument("--reverse", action="store_true", help="turn wrapped lines back into the rules' commands")
    args = parser.parse_args(argv)
    # Bytes in, bytes out: no newline translation, no encoding error on an odd byte.
    text = sys.stdin.buffer.read().decode("utf-8", "surrogateescape")
    try:
        rules = json.loads(args.rules.read_text(encoding="utf-8"))
        result, unknown = (unwrap if args.reverse else wrap)(text, rules, args.wrapper)
    except (OSError, ValueError, KeyError, TypeError, re.error) as error:
        # re.error (a match that is not a regular expression) is no ValueError before Python 3.13.
        print(f"Refusing: the cron rules cannot be used: {error}", file=sys.stderr)
        return 2
    if unknown:
        print("Refusing: these wrapped cron entries name a profile without exactly one rule:" if args.reverse
              else "Refusing: these application cron entries match no admission rule exactly:", file=sys.stderr)
        for line in unknown:
            print(f"  {line}", file=sys.stderr)
        return 1
    sys.stdout.buffer.write(result.encode("utf-8", "surrogateescape"))
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
