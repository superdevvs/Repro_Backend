"""Readers of PHP `var_export` output (Laravel's bootstrap/cache/config.php) that never execute it.

The deploy user can write the cached config and root reads it, so nothing here runs PHP. Shared by the
cutover controller's live config module (live_config.py, design v2 1.3) and the alert probe
(scripts/mysql/offsite/alert_probe.py), which is installed with a copy of this file beside it
(/opt/repro-backup-tools/var_export.py). Standard library only.

- `parse(raw)` reads a whole cached config strictly: exactly `<?php return <array>;`, where values are
  arrays, single-quoted strings (valid UTF-8), integers, floats, `true`, `false` and `NULL`, written as
  PHP 8.3's var_export writes them. Anything else raises `Refused`: an object (`__set_state`, `(object)`),
  an enum, a NUL byte's concatenation, `INF` or `NAN`, PHP_INT_MIN's expression, an integer or float PHP
  would read differently, another spelling of a constant, a duplicate key, invalid UTF-8, nesting deeper
  than MAX_DEPTH, or anything before the tag or after the semicolon. Arrays become dicts with string
  keys in file order; a PHP list is the map of its indexes (as the route-hash contract writes it).
- `tokens(raw)` and `value(tokens, index)` are the alert probe's lenient reader, moved here unchanged:
  it keeps strings and NULL and returns OPAQUE for any other value (an object, a number, a boolean), so
  the probe can find the engine in a config that also holds values it does not need.
"""

from __future__ import annotations

import math
import re

# Nesting a Laravel config never reaches (its deepest arrays are about eight levels down).
MAX_DEPTH = 32
INTEGER = re.compile(rb"-?(?:0|[1-9][0-9]*)")
# var_export writes every float with a fraction, and an exponent as E+N or E-N: 0.1, 2.0, -0.0, 1.0E+25.
FLOAT = re.compile(rb"-?(?:0|[1-9][0-9]*)\.[0-9]+(?:E[+-][0-9]+)?")
# A larger literal is a float to PHP, and var_export writes PHP_INT_MIN as an expression.
PHP_INT_MAX = 2**63 - 1
CONSTANTS = {b"NULL": None, b"true": True, b"false": False}
OPAQUE = object()  # A value the lenient reader skips (object, enum, concatenation, number, boolean).


class Refused(ValueError):
    """The text is not a cached config this reader accepts. The message holds no value from the file."""


def tokens(raw: bytes) -> list[tuple[str, bytes]]:
    """Tokens of PHP var_export output: quoted strings (unescaped), the symbols => ( ) , ; and bare words."""
    found, index, size = [], 0, len(raw)
    while index < size:
        char = raw[index:index + 1]
        if char in b" \t\r\n":
            index += 1
        elif char == b"'":
            text, index = bytearray(), index + 1
            while True:
                if index >= size:
                    raise Refused("unterminated string")
                char = raw[index:index + 1]
                if char == b"\\" and raw[index + 1:index + 2] in (b"\\", b"'"):
                    text += raw[index + 1:index + 2]
                    index += 2
                elif char == b"'":
                    index += 1
                    break
                else:
                    text += char
                    index += 1
            found.append(("str", bytes(text)))
        elif raw.startswith(b"=>", index):
            found.append(("op", b"=>"))
            index += 2
        elif char in b"(),;":
            found.append(("op", char))
            index += 1
        else:
            start = index
            while index < size and raw[index:index + 1] not in b" \t\r\n'(),;" and not raw.startswith(b"=>", index):
                index += 1
            found.append(("word", raw[start:index]))
    return found


def value(items: list, index: int):
    """The lenient reader: parse one value; return (value, index of the token after it). Arrays become dicts."""
    if items[index] == ("word", b"array") and items[index + 1] == ("op", b"("):
        result, index = {}, index + 2
        while items[index] != ("op", b")"):
            kind, key = items[index]
            if not (kind in ("str", "word") and items[index + 1] == ("op", b"=>")):
                raise Refused("unexpected array entry")
            result[key.decode("utf-8", "replace")], index = value(items, index + 2)
            if items[index] == ("op", b","):
                index += 1
        return result, index + 1
    if items[index][0] == "str" and items[index + 1] in (("op", b","), ("op", b")"), ("op", b";")):
        return items[index][1].decode("utf-8", "replace"), index + 1
    if items[index] == ("word", b"NULL") and items[index + 1] in (("op", b","), ("op", b")")):
        return None, index + 1
    depth = 0  # Anything else (object, enum, concatenation, number) is skipped to its end.
    while True:
        kind, text = items[index]
        if kind == "op" and text == b"(":
            depth += 1
        elif kind == "op" and text in (b")", b",", b";") and depth == 0:
            return OPAQUE, index
        elif kind == "op" and text == b")":
            depth -= 1
        index += 1


def parse(raw: bytes) -> dict:
    """A whole cached config, strictly (see the module docstring)."""
    if not raw.startswith(b"<?php"):
        raise Refused("the file does not start with <?php")
    items = tokens(raw)
    if items[:2] != [("word", b"<?php"), ("word", b"return")]:
        raise Refused("not a cached config: no <?php return")
    config, index = _strict(items, 2, 0)
    if not isinstance(config, dict):
        raise Refused("the cached config is not an array")
    if items[index:] != [("op", b";")]:
        raise Refused("the cached config does not end with its array and one semicolon")
    return config


def _at(items: list, index: int) -> tuple[str, bytes]:
    if index >= len(items):
        raise Refused("the cached config ends early")
    return items[index]


def _text(raw: bytes) -> str:
    try:
        return raw.decode("utf-8")
    except UnicodeDecodeError:
        raise Refused("a string is not valid UTF-8") from None


def _integer(text: bytes) -> int | None:
    if INTEGER.fullmatch(text) is None:
        return None
    number = int(text)
    if abs(number) > PHP_INT_MAX:
        raise Refused("an integer PHP would read as a float")
    return number


def _key(items: list, index: int) -> tuple[str, int]:
    kind, text = _at(items, index)
    if kind == "str":
        return _text(text), index + 1
    number = _integer(text) if kind == "word" else None
    if number is None:
        raise Refused(f"an array key the reader does not accept at token {index}")
    return str(number), index + 1


def _strict(items: list, index: int, depth: int):
    kind, text = _at(items, index)
    if (kind, text) == ("word", b"array"):
        if depth >= MAX_DEPTH:
            raise Refused("the cached config nests too deep")
        if _at(items, index + 1) != ("op", b"("):
            raise Refused(f"array without ( at token {index}")
        result, index = {}, index + 2
        while _at(items, index) != ("op", b")"):
            key, index = _key(items, index)
            if _at(items, index) != ("op", b"=>"):
                raise Refused(f"array entry without => at token {index}")
            if key in result:
                raise Refused("a key appears twice in one array")
            result[key], index = _strict(items, index + 1, depth + 1)
            following = _at(items, index)
            if following == ("op", b","):
                index += 1
            elif following != ("op", b")"):
                raise Refused(f"something other than , or ) follows a value at token {index}")
        return result, index + 1
    if kind == "str":
        return _text(text), index + 1
    if kind == "word":
        if text in CONSTANTS:
            return CONSTANTS[text], index + 1
        number = _integer(text)
        if number is not None:
            return number, index + 1
        if FLOAT.fullmatch(text) is not None:
            real = float(text)
            if not math.isfinite(real):
                raise Refused("a float PHP would read as INF")
            return real, index + 1
    raise Refused(f"a value the reader does not accept at token {index}")
