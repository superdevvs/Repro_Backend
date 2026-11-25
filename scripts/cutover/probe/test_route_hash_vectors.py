"""Shared route-hash vectors: the contract between the PHP probes and Python.

`scripts/cutover/probe/fpm-engine.php` and `php artisan cutover:smoke --engine-only
--json` print the raw route values of the live config. Python (the controller's
live config module) builds the same values from bootstrap/cache/config.php, which
it parses and never executes, and hashes them. PHP never computes the route hash.

This file checks the vectors against an independent stdlib reference of the
allowlist, the password rule and the canonical form. The PHP side checks the same
vectors in tests/Feature/CutoverSmokeTest.php. Changing either side means
changing route-hash-vectors.json, which both read.

Run from the app root: python3 -B -m unittest discover -s scripts/cutover/probe
"""

from __future__ import annotations

import hashlib
import json
import unittest
from pathlib import Path

VECTORS = Path(__file__).with_name("route-hash-vectors.json")

# Laravel's ConfigurationUrlParser driver aliases (same table as alert_probe.URL_DRIVERS).
URL_DRIVERS = {"mysql2": "mysql", "postgres": "pgsql", "postgresql": "pgsql", "sqlite3": "sqlite", "mssql": "sqlsrv"}
MYSQL_EXTRAS = ("mysql_cache_locks", "mysql_queue_worker", "mysql_scheduling_cache")
QUEUE_KEYS = ("driver", "connection", "table")
TOP_KEYS = {
    "app.key_sha256", "cache.default", "cache.stores.default", "cache.stores.scheduling",
    "database.connections", "database.default", "database.repro_leftover_transactions",
    "queue.connections", "queue.default",
}


def objects(value):
    """PHP arrays are maps with string keys: a JSON list is the map of its indexes."""
    if isinstance(value, list):
        return {str(index): objects(item) for index, item in enumerate(value)}
    if isinstance(value, dict):
        return {str(key): objects(item) for key, item in value.items()}
    return value


def without_passwords(value):
    """Every key named exactly `password`, at any depth, becomes `password_set`."""
    if not isinstance(value, dict):
        return value
    if "password" in value and "password_set" in value:
        raise ValueError("password and password_set side by side")
    result = {}
    for key, item in value.items():
        if key == "password":
            result["password_set"] = not (item is None or item == "")
        else:
            result[key] = without_passwords(item)
    return result


def engine(config: dict) -> str:
    database = config["database"]
    connection = database["connections"][database["default"]]
    url = connection.get("url")
    if isinstance(url, str) and url != "":
        scheme = url.split(":", 1)[0].lower()
        return URL_DRIVERS.get(scheme, scheme)
    return connection["driver"]


def route_values(config: dict) -> dict:
    config = objects(config)
    database, cache, queue = config["database"], config["cache"], config["queue"]
    default = database["default"]
    connections = database["connections"]
    chosen = {default: connections.get(default)}
    if engine(config) == "mysql":
        for name in MYSQL_EXTRAS:
            chosen[name] = connections.get(name)
    key = config.get("app", {}).get("key")
    values = {
        "app.key_sha256": None if key is None else hashlib.sha256(str(key).encode("utf-8")).hexdigest(),
        "cache.default": cache.get("default"),
        "cache.stores.default": cache.get("stores", {}).get(cache.get("default")),
        "cache.stores.scheduling": cache.get("stores", {}).get("scheduling"),
        "database.connections": chosen,
        "database.default": default,
        "database.repro_leftover_transactions": database.get("repro_leftover_transactions"),
        "queue.connections": {name: {k: v for k, v in settings.items() if k in QUEUE_KEYS}
                              for name, settings in queue.get("connections", {}).items()},
        "queue.default": queue.get("default"),
    }
    return without_passwords(values)


def canonical(value) -> bytes:
    return json.dumps(objects(value), sort_keys=True, separators=(",", ":"), ensure_ascii=True,
                      allow_nan=False).encode("ascii")


def route_sha256(values) -> str:
    return hashlib.sha256(canonical(values)).hexdigest()


def keys_named(value, name):
    if isinstance(value, dict):
        for key, item in value.items():
            if key == name:
                yield key
            yield from keys_named(item, name)
    elif isinstance(value, list):
        for item in value:
            yield from keys_named(item, name)


class RouteHashVectorsTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.document = json.loads(VECTORS.read_text(encoding="utf-8"))
        cls.vectors = cls.document["vectors"]

    def test_document_shape(self):
        self.assertEqual(self.document["version"], 1)
        self.assertGreaterEqual(len(self.vectors), 4)
        names = [vector["name"] for vector in self.vectors]
        self.assertEqual(len(names), len(set(names)))
        for vector in self.vectors:
            self.assertEqual(set(vector), {"name", "config", "engine", "route_values", "route_sha256"}, vector["name"])
            self.assertEqual(set(vector["route_values"]), TOP_KEYS, vector["name"])

    def test_route_values_follow_the_allowlist(self):
        for vector in self.vectors:
            with self.subTest(vector["name"]):
                self.assertEqual(route_values(vector["config"]), vector["route_values"])

    def test_engine_follows_driver_and_url_scheme(self):
        engines = {vector["name"]: vector["engine"] for vector in self.vectors}
        for vector in self.vectors:
            with self.subTest(vector["name"]):
                self.assertEqual(engine(objects(vector["config"])), vector["engine"])
        self.assertIn("sqlite", engines.values())
        self.assertIn("mysql", engines.values())

    def test_route_values_are_written_in_canonical_form(self):
        # Every array is an object with string keys, as the strict var_export reader returns it.
        for vector in self.vectors:
            with self.subTest(vector["name"]):
                self.assertEqual(objects(vector["route_values"]), vector["route_values"])

    def test_hash_is_sha256_of_canonical_json(self):
        for vector in self.vectors:
            with self.subTest(vector["name"]):
                self.assertEqual(route_sha256(vector["route_values"]), vector["route_sha256"])

    def test_no_route_value_carries_a_password(self):
        for vector in self.vectors:
            with self.subTest(vector["name"]):
                self.assertEqual(list(keys_named(vector["route_values"], "password")), [])
        set_flags = [vector["route_values"] for vector in self.vectors if '"password_set":true' in canonical(vector["route_values"]).decode()]
        unset_flags = [vector["route_values"] for vector in self.vectors if '"password_set":false' in canonical(vector["route_values"]).decode()]
        self.assertTrue(set_flags and unset_flags)

    def test_a_php_list_hashes_like_the_object_of_its_indexes(self):
        listed = {"tables": ["jobs", "cache"], "empty": []}
        mapped = {"tables": {"0": "jobs", "1": "cache"}, "empty": {}}
        self.assertEqual(canonical(listed), canonical(mapped))

    def test_night_flags_and_other_keys_do_not_move_the_hash(self):
        vector = next(v for v in self.vectors if v["engine"] == "mysql")
        changed = json.loads(json.dumps(vector["config"]))
        changed.setdefault("crm", {})["outbound_hold"] = True
        changed["cache"]["prefix"] = "another-prefix"
        changed["database"]["connections"][vector["config"]["database"]["default"]]["password"] = "rotated"
        self.assertEqual(route_sha256(route_values(changed)), vector["route_sha256"])

    def test_socket_moves_the_hash(self):
        vector = next(v for v in self.vectors if v["engine"] == "mysql")
        changed = json.loads(json.dumps(vector["config"]))
        changed["database"]["connections"][vector["config"]["database"]["default"]]["unix_socket"] = "/run/other.sock"
        self.assertNotEqual(route_sha256(route_values(changed)), vector["route_sha256"])


if __name__ == "__main__":
    unittest.main()
