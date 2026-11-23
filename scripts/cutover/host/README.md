# Local pre-write cutover rehearsal

Run the current cutover state machine against a miniature synthetic Laravel stack. The Windows launcher uses the existing pinned QA and MySQL 8.4.13 images. Both disposable containers have networking disabled. PHP serves the fixture route on its private loopback and connects to MySQL through a dedicated socket volume. The launcher verifies the Composer lock after extraction and removes its own containers and volume on exit.

Use a local lock-matched vendor archive, the pinned PyMySQL 1.1.2 wheel and a fresh directory outside the checkout.

~~~powershell
$params = @{
    LockedVendorArchive = 'C:/rehearsal/locked-dev-vendor.tar.gz'
    PinnedPyMySqlWheel = 'C:/rehearsal/pymysql-1.1.2-py3-none-any.whl'
    EvidenceDirectory = 'C:/rehearsal/prewrite-trial-01'
}
./scripts/cutover/host/run-local.ps1 @params
~~~

The trial exercises these operations in order.

1. Bootstrap Laravel from a private configuration cache. A fixture HTTP endpoint calls the real notification receipt service against SQLite. A Laravel database queue stores one synthetic job.
2. Run the actual freeze transition. The application refuses a second HTTP write and a queue worker invocation. The fixture verifies a SQLite write fails and takes an integrity-checked SQLite snapshot.
3. Transfer the four fixture tables into MySQL and independently compare the rows. Set MySQL super read-only, verify a direct write is refused, and read the matching application state through a restarted PHP server.
4. Verify the runner refuses readiness and promotion when off-site backup evidence is unavailable.
5. Run the pre-write abort. Restore the original Laravel cache and SQLite authority, test that wrong-engine and stale-boot evidence hold admission, then verify that HTTP reads work again and Laravel drains the queued job exactly once.

The report records the exact source commit and harness hashes, freeze-to-target-smoke time, abort-to-SQLite-HTTP time and the acknowledged effects. The cleanup receipt records the source archive digest and confirms removal of owned resources.

The scope covers a dedicated fixture HTTP route on PHP's CLI server and a one-shot database worker. The scheduler inventory is empty by construction. Actual application HTTP ingress, privileged and restart-safe writer admission, the systemd scheduler, provider replay, production-sized parity, comparable load and off-site restore require separate evidence. The fixture's local backup values remain false, and the adapter refuses to open MySQL for writes.
