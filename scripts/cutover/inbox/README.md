# Isolated Telnyx inbox primitive — not activated

`App\Support\TelnyxCutoverInbox` is an unbound local intake component. No live route,
controller, service binding, provider configuration, scheduler or replay worker uses it.
It sends no messages and does not acknowledge HTTP requests itself.

Its constructor requires an existing private Linux directory owned by the intake user,
an explicitly supplied Laravel `Encrypter`, and the Telnyx Ed25519 public key. Use a
dedicated, securely backed-up encryption key for a future intake deployment. Do not
place the spool under a served directory or reuse a public storage disk. The directory
must be canonical (no symlink path), private mode 0700, and on a local filesystem with
verified file and directory fsync semantics. Files are mode 0600. One bounded local
file lock serializes receipt publication; it is not a multi-host coordination system.

`store(destination, exactRawBody, signatureHeader, timestampHeader)` verifies the
signature and receipt-time freshness before writing. It preserves all raw bytes and
the verified headers inside authenticated ciphertext, flushes and fsyncs the file,
atomically publishes it, then fsyncs the directory. Only a successful return is a local
durable receipt. Any exception, including partial write, full disk, lock timeout,
corruption or failed directory sync, must produce a non-2xx response in a future
adapter. Never catch these failures and return an acknowledgement. A publish whose
directory sync fails may already exist: retry verifies it and syncs the directory
before returning success. Crashed unpublished `.pending-*` files are not receipts.

Identical signed bodies on the same logical destination deduplicate. Distinct signed
bodies with the same provider event ID remain separate receipts, preserving retry
metadata and possible conflicts. Capture does not silently overwrite either version.
`open(receiptId)` authenticates and returns a receipt for an explicit trusted local
consumer; it does not dispatch, mark processed, delete, or print payloads. Keep original
verification public keys available across key rotation. Missing event IDs are rejected.
Supported destination labels are `messaging`, `status`, `voice`, and
`shared-mms:<canonical-lowercase-uuid>`; these labels are operator-selected, not body data.

## Source-verified replay constraints

- The current SMS controller validates `timestamp|raw-body` and a 300-second freshness
  window, then deduplicates inbound SMS by `data.payload.id` (provider message ID).
  Its outbound status handling is a different operation. Reposting old signed headers
  to the public SMS endpoint after a long freeze will fail freshness validation.
- The voice path validates the Ed25519 signature and records provider event identity
  in `VoiceCallEvent` / `TelnyxWebhookEvent`; voice processing can trigger external call
  actions. Capture alone does not make those effects safe to replay.
- Future replay must use a separately reviewed, private consumer of authenticated
  receipts, retaining event/message deduplication, original destination, ordered
  per-message status handling, and durable processed/error state. It must classify
  stale interactive voice events instead of blindly re-running call actions. Do not
  weaken public signature guards or replay through a test-only authentication bypass.

## Remaining production gates

No cutover should rely on this primitive yet. Required work includes an independently
available authenticated intake route during app maintenance, complete provider-route
coverage, provider retry/failover confirmation, storage/quota monitoring and alerts,
failure-response tests through the actual HTTP proxy, an agreed off-host durability
policy before ACK, key recovery, a tested private replay adapter and duplicate-effect
checks, and a freeze/replay/failback rehearsal. Local fsync does not survive loss of the
host or disk; an off-host backup taken later is not synchronous event durability.

The isolated unit fixture covers signatures, exact-byte encryption/readback,
duplicates/conflicting envelopes, unsafe storage, partial writes, fsync uncertainty and
tamper failures. It does not prove a deployed endpoint, host filesystem, provider retry
policy, replication, replay behavior, or production readiness.
