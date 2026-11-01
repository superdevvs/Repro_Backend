# Native action contract fixtures

These synthetic requests were intercepted by the Swift model tests for the
native UX/workflow batch on 2026-11-01. They contain no bearer tokens or customer
records. The initial four requests came from `native-nine-action-fixtures-r1.zip`
(SHA256 `aa5752e7b070caabf92123c71689bb7b715d77b96090887e69fb578cfae96e27`).
The indexed-filter request came from `native-nine-partial-review-r2.zip`
(SHA256 `a48a7afd3d4683a6e2c5a66cdad8fac5d9c32e11f541382da328f66760a1ea91`).

`NativeShootActionContractTest` preserves their request shapes while binding IDs,
unit keys, and content versions to isolated factory records. Scheduling and
qualification checks remain enabled. Queue, messaging, invoice, storage, and
calendar side effects are isolated; unexpected external HTTP fails the tests.
