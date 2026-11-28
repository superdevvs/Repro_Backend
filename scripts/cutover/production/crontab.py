"""Route application cron entries through the admission wrapper, and back.

Each entry whose command matches a rule is rewritten to run the fixed wrapper
with that rule's profile. The rule names the exact command it replaces; the
wrapper runs only the profile's command, so a line whose command differs in
any way (something chained, piped or substituted after it, any other prefix
before it, another option) is returned instead of being installed, never
rewritten with the difference dropped. The schedule, the whitespace after it,
the output redirection and any trailing marker comment are kept, so installer
idempotency checks still work. A redirection that writes into the application
is refused: that output would be a writer of its own, which neither the wrapper
nor an allow rule fences. Each target is first placed lexically, '//' and '.'
collapsed; one with a '..' component, or a relative one that is not behind a
leading `cd <absolute path> &&` (the only cd in the command), cannot be placed
and is refused. Under an app root (an absolute application marker) a target in
a tree the source fingerprint hashes (app, bootstrap, config, resources,
routes, scripts, public, database) or one of its root files (artisan,
composer.json, ...) is refused; a marker without a slash names the live
database, refused wherever a file of that name (or its -wal, -shm or -journal)
is. Any other target, the app's storage/logs included, is allowed (review
W2V-3). The check is lexical: it cannot see a symbolic link along the path.

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

Words are split as /bin/sh and cron split them, at spaces and tabs only. Python
counts more characters as whitespace (\\v, \\f, \\r, \\x1c-\\x1f, U+0085, U+00A0 and
other Unicode spaces), and after one of them a '#' starts no comment for sh, so
a line holding one, or any other control character but tab, is refused outright
in both directions, whatever it runs (review W2F-3). Comment lines (a '#' after
nothing but spaces and tabs) are never run by cron and are kept as they are.
"""

from __future__ import annotations

import argparse
import json
import posixpath
import re
import sys
from pathlib import Path

# The blanks of /bin/sh and cron: space and tab, never Python's wider \s (review W2F-3).
BLANKS = " \t"
SCHEDULE = re.compile(r"^([ \t]*(?:@[A-Za-z0-9_]+|[^ \t]+[ \t]+[^ \t]+[ \t]+[^ \t]+[ \t]+[^ \t]+[ \t]+[^ \t]+))"
                      r"([ \t]+)([^ \t].*)$")
# What may follow a rule's command: output redirections to plain paths, 2>&1 and a marker comment. Nothing in
# it can run or feed another command (no ;, &, |, $, quotes or backquotes in a target, no cron % anywhere, no
# character sh would read as part of a word), and no target writes into the application (_writes_into_the_application).
TRAILER = re.compile(r"((?:[ \t]+(?:>>?|2>>?)[ \t]*[A-Za-z0-9_./-]+)*(?:[ \t]+2>&1)?)([ \t]+#[^'\"%]*)?$")
REDIRECTION_TARGET = re.compile(r"(?:>>?|2>>?)[ \t]*([A-Za-z0-9_./-]+)")
# Under an app root (an absolute application marker): the trees and root files the source fingerprint hashes
# (scripts/deploy/backend-source-fingerprint.py; all of database/, which also holds the live database), where a
# write changes artifact_sha256 and stalls every runner action. A marker without a slash names the live database.
FINGERPRINTED_TREES = ("app", "bootstrap", "config", "resources", "routes", "scripts", "public", "database")
FINGERPRINTED_FILES = ("artisan", "composer.json", "composer.lock", "package.json", "package-lock.json")
# The identity probe's other inputs under the app root (identity_probe.py: config_sha256, release_sha): a line
# written into either makes the probe refuse, which stalls every runner action, the rollback included (D10R-8).
IDENTITY_FILES = (".env", "storage/app/deploy-meta.json")
JOURNAL_SUFFIXES = ("", "-wal", "-shm", "-journal")
# A leading `cd <absolute path> && ` places the redirected command (and relative targets); any later cd moves it.
LEADING_CD = re.compile(r"cd[ \t]+(/[A-Za-z0-9_./-]*)[ \t]+&&[ \t]")
ANOTHER_CD = re.compile(r"(?:^|[^A-Za-z0-9_./-])cd(?:[ \t]|$)")
ASSIGNMENT = re.compile(r"^[ \t]*[A-Za-z_][A-Za-z0-9_]*[ \t]*=")
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


def _passive(line: str) -> bool:
    """A line cron never runs: empty, blanks only, or a comment ('#' after nothing but blanks)."""
    rest = line.lstrip(BLANKS)
    return not rest or rest.startswith("#")


def _odd(line: str) -> bool:
    """A control character other than tab (C0, DEL, C1), or a character Python takes for whitespace and sh does not."""
    return any((char < " " and char != "\t") or "\x7f" <= char <= "\x9f" or (char.isspace() and char not in BLANKS)
               for char in line)


def _working_directory(command: str) -> str | None:
    """Where the trailer's redirections open: the directory of a leading `cd <absolute path> &&` (behind the
    storage guard) when it is the command's only cd; None otherwise (cron starts a command in the user's home,
    which the rules do not name, and a second cd moves it again)."""
    _, exact, _ = _parts(command)
    leading = LEADING_CD.match(exact)
    if leading is None or ANOTHER_CD.search(exact[leading.end():]):
        return None
    return leading.group(1)


def _place(target: str, directory: str | None) -> str | None:
    """A redirection target as a lexical absolute path ('//' and '.' collapsed, os.path.normpath), or None when
    it cannot be placed: a '..' component (a link would decide where it lands) or a relative target with no
    known directory."""
    if not target.startswith("/"):
        if directory is None:
            return None
        target = f"{directory}/{target}"
    target = re.sub(r"/+", "/", target)
    if ".." in target.split("/"):
        return None
    return posixpath.normpath(target)


def _writes_into_the_application(command: str, markers: list[str]) -> bool:
    """Whether a redirection of the command's trailer may write into what the cutover fingerprints or seals.

    An absolute marker is the app root (or a file): a target that is the marker itself, or lies in one of
    its FINGERPRINTED_TREES, or is one of its FINGERPRINTED_FILES or IDENTITY_FILES (review D10R-8), is
    refused. A marker without a slash is a file name (the live database): a target with that name, or the name
    with a journal suffix, is refused wherever it is. So is a target that cannot be placed lexically. Anything
    else, the app's own storage/logs included, is allowed (review W2V-3)."""
    directory = _working_directory(command)
    roots = [posixpath.normpath(re.sub(r"/+", "/", marker)) for marker in markers if marker.startswith("/")]
    names = {marker + suffix for marker in markers if "/" not in marker for suffix in JOURNAL_SUFFIXES}
    for target in REDIRECTION_TARGET.findall(TRAILER.search(command).group(1)):
        path = _place(target, directory)
        if path is None or posixpath.basename(path) in names:
            return True
        for root in roots:
            if path in {root + suffix for suffix in JOURNAL_SUFFIXES}:
                return True
            if path.startswith(root.rstrip("/") + "/"):
                inside = path[len(root.rstrip("/")) + 1:]
                if (inside.split("/")[0] in FINGERPRINTED_TREES or inside in FINGERPRINTED_FILES
                        or inside in IDENTITY_FILES):
                    return True
    return False


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
        if not _passive(line) and _odd(line):
            unknown.append(line)
            lines.append(line)
            continue
        parsed = SCHEDULE.match(line)
        if _passive(line) or ASSIGNMENT.match(line) or parsed is None:
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
        if _writes_into_the_application(command, markers):
            # Its output would write into the live database or the app tree, unfenced (review W2F-3).
            unknown.append(line)
            lines.append(line)
            continue
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
        if not _passive(line) and _odd(line):
            unknown.append(line)
            lines.append(line)
            continue
        parsed = SCHEDULE.match(line)
        if parsed is None or _passive(line) or ASSIGNMENT.match(line) \
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
        odd = " or hold a character sh does not take for a blank"
        print(f"Refusing: these wrapped cron entries name a profile without exactly one rule{odd}:" if args.reverse
              else f"Refusing: these cron entries match no admission rule exactly, write into the application{odd}:",
              file=sys.stderr)
        for line in unknown:
            # A control or space character is shown escaped, never sent to the terminal as it is.
            shown = "".join(char if char.isprintable() or char == "\t" else ascii(char)[1:-1] for char in line)
            print(f"  {shown}", file=sys.stderr)
        return 1
    sys.stdout.buffer.write(result.encode("utf-8", "surrogateescape"))
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
