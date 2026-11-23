import datetime as dt
from dataclasses import replace
import unittest

import publish_backup as backup
import verify_retention as retention


class RetentionEvidenceTests(unittest.TestCase):
    def setUp(self):
        prefix = "rehearsals/retention/"
        self.expected = retention.Expected("a" * 32, "synthetic-retention", prefix, prefix + "test-object",
                                           "b" * 64, 3_600, 7_200)
        self.now = dt.datetime(2026, 11, 23, 15, 5, tzinfo=dt.timezone.utc)
        self.writer, self.probe = "c" * 64, "d" * 64
        self.lock_rule = {"id": "rehearsal-lock", "enabled": True, "prefix": prefix,
                          "condition": {"type": "Age", "maxAgeSeconds": 3_600}}
        self.expiry_rule = {"id": "rehearsal-expiry", "enabled": True, "conditions": {"prefix": prefix},
                            "deleteObjectsTransition": {"condition": {"type": "Age", "maxAge": 7_200}}}
        self.abort_rule = {"id": "abort-incomplete", "enabled": True, "conditions": {"prefix": ""},
                           "abortMultipartUploadsTransition": {"condition": {"type": "Age", "maxAge": 604_800}}}
        self.operations = {
            "create": self.observation(self.writer, "PutObject", 200),
            "read_before": self.observation(self.writer, "GetObject", 200, digest=self.expected.content_sha256),
            "overwrite": self.observation(self.writer, "PutObject", 409, retention.LOCKED),
            "publisher_delete": self.observation(self.writer, "DeleteObject", 403, "AccessDenied"),
            "lock_probe_delete": self.observation(self.probe, "DeleteObject", 409, retention.LOCKED),
            "read_after": self.observation(self.writer, "GetObject", 200, digest=self.expected.content_sha256),
        }
        self.evidence = {
            "schema": retention.SCHEMA, "account": self.expected.account, "bucket": self.expected.bucket,
            "prefix": prefix, "observed_utc": "2026-11-23T15:04:30Z",
            "locks": self.export(self.lock_rule),
            "lifecycle": self.export(self.abort_rule, self.expiry_rule),
            "sentinel": {"key": self.expected.sentinel, "created_utc": "2026-11-23T15:04:00Z",
                         "publisher_fingerprint": self.writer, "lock_probe_fingerprint": self.probe,
                         "operations": self.operations},
        }

    @staticmethod
    def export(*rules):
        return {"success": True, "errors": [], "messages": [], "result": {"rules": list(rules)}}

    def observation(self, credential, method, status, code=None, digest=None):
        return {"key": self.expected.sentinel, "credential_fingerprint": credential, "operation": method,
                "http_status": status, "s3_code": code, "content_sha256": digest}

    def verify(self, **options):
        raw = backup.encode(self.evidence)
        arguments = {"evidence_sha256": backup.sha256(raw), "expected": self.expected, "now": self.now}
        return retention.verify(raw, **(arguments | options))

    def test_matches_reviewed_rule_ages_and_distinguishes_two_delete_denials(self):
        self.evidence["locks"]["result"]["rules"].append(self.lock_rule | {"prefix": "unrelated/"})
        result = self.verify()
        self.assertEqual(result["result"], "r2-retention-evidence-matched")
        self.assertEqual((result["lock_seconds"], result["expiry_seconds"]), (3_600, 7_200))
        self.assertIs(result["remote_accessed"], False)
        self.assertIs(result["live_probe_run"], False)

    def test_default_multipart_abort_rule_is_not_expiry_or_lock(self):
        self.evidence["lifecycle"] = self.export(self.abort_rule)
        with self.assertRaisesRegex(backup.Refusal, "exact enabled rule"):
            self.verify()
        self.evidence["lifecycle"] = self.export(self.abort_rule, self.expiry_rule)
        self.evidence["locks"] = self.export()
        with self.assertRaisesRegex(backup.Refusal, "exact enabled rule"):
            self.verify()

    def test_rejects_broader_narrower_duplicate_disabled_or_short_lock(self):
        for altered in (self.lock_rule | {"prefix": ""},
                        self.lock_rule | {"prefix": "rehearsals/"},
                        self.lock_rule | {"prefix": self.expected.prefix + "unreviewed/"},
                        self.lock_rule | {"enabled": False},
                        self.lock_rule | {"condition": {"type": "Age", "maxAgeSeconds": 3_599}},
                        self.lock_rule | {"condition": {"type": "Indefinite"}}):
            with self.subTest(altered=altered), self.assertRaises(backup.Refusal):
                self.evidence["locks"] = self.export(altered)
                self.verify()
        self.evidence["locks"] = self.export(self.lock_rule, self.lock_rule | {"id": "duplicate"})
        with self.assertRaises(backup.Refusal):
            self.verify()

    def test_rejects_earlier_expiry_and_successful_status_with_provider_errors(self):
        early = self.expiry_rule | {"deleteObjectsTransition": {"condition": {"type": "Age", "maxAge": 3_599}}}
        for changed in (self.export(self.abort_rule, early),
                        self.export(self.abort_rule, self.expiry_rule) | {"errors": [{"code": 1000}]}):
            with self.subTest(changed=changed), self.assertRaises(backup.Refusal):
                self.evidence["lifecycle"] = changed
                self.verify()

    def test_rejects_unrelated_errors_bulk_delete_wrong_key_and_changed_bytes(self):
        for name, mutation in (
            ("overwrite", {"http_status": 412, "s3_code": "PreconditionFailed"}),
            ("publisher_delete", {"s3_code": "ExpiredRequest"}),
            ("lock_probe_delete", {"operation": "DeleteObjects", "http_status": 200}),
            ("lock_probe_delete", {"credential_fingerprint": self.writer}),
            ("read_after", {"key": self.expected.prefix + "another-object"}),
            ("read_after", {"content_sha256": "e" * 64}),
            ("create", {"http_status": 200.0}),
        ):
            with self.subTest(name=name, mutation=mutation):
                old = self.operations[name]
                self.operations[name] = old | mutation
                with self.assertRaises(backup.Refusal):
                    self.verify()
                self.operations[name] = old

    def test_refuses_wrong_pin_scope_and_observation_clock(self):
        for overrides in ({"evidence_sha256": "e" * 64}, {"now": self.now - dt.timedelta(minutes=1)},
                          {"expected": replace(self.expected, bucket="wrong-bucket")},
                          {"expected": replace(self.expected, sentinel="another-prefix/test-object")}):
            with self.subTest(overrides=overrides), self.assertRaises(backup.Refusal):
                self.verify(**overrides)
        self.evidence["sentinel"]["created_utc"] = "2026-11-23T14:04:30Z"
        with self.assertRaisesRegex(backup.Refusal, "outside the approved lock window"):
            self.verify()


if __name__ == "__main__":
    unittest.main()
