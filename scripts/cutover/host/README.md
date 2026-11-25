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

The pre-write fixture requires the same durable journal and boot evidence on each HTTP write and one-shot queue invocation. Both PHP entry points hold a shared lease through execution and refuse admission while the runner has the exclusive lease. A stale permissive launch record cannot override a frozen journal after a PHP server restart. Separate restarts also verify missing, invalid and stale-boot proof. The smoke read checks only the dedicated fixture route during freeze.

The report records the exact source commit and harness hashes, freeze-to-target-smoke time, abort-to-SQLite-HTTP time and the acknowledged effects. The cleanup receipt records the source archive digest and confirms removal of owned resources.

The scope covers a dedicated fixture HTTP route on PHP's CLI server and a one-shot database worker under the same synthetic user. The scheduler inventory is empty by construction. Actual application HTTP ingress, the root-owned installation and authority publisher, long-lived workers, the systemd scheduler, provider replay, production-sized parity, comparable load and off-site restore require separate evidence. The fixture's local backup values remain false, and the adapter refuses to open MySQL for writes.

## Private nginx and PHP-FPM restart rehearsal

Set `$params.Scenario = 'prewrite-fastcgi'` and a fresh evidence directory to run the same pre-write rehearsal through the pinned nginx and PHP-FPM binaries. nginx listens on a private Unix HTTP socket and forwards to a private FastCGI socket. Closing the fixture ingress denies a write with HTTP 503 before it reaches PHP-FPM, including after replacing both processes. An intentionally stale, open ingress marker exercises the PHP-FPM journal guard independently. Before each replacement the launcher sends `SIGQUIT`, waits for the master and observes the identified worker exit through its Linux pidfd. The dedicated smoke read and the one-shot queue use the same synthetic data and release cache.

Two private vhosts represent the same-origin dashboard and legacy API ingress. Both test the CRM quote-view GET and Resend webhook POST route shapes with synthetic effects. Closed nginx returns HTTP 503 and `Retry-After` before FastCGI. The journal also refuses those routes when the edge marker is stale and open. Restored SQLite accepts the same fixture requests through both hosts and stores one effect per replayed identity. The fixtures use no provider and do not invoke the production quote or webhook controllers.

For host acceptance, install the root-owned guard and evidence publisher in an isolated staging host with the deployed nginx vhosts, PHP-FPM service, scheduler supervisor, and worker launchers. Close ingress across every writing route before Laravel, including GET routes with side effects, and verify retry behavior through nginx. Drain the old FPM cohort and verify cgroup and service-manager state under the scheduler hold and exclusive journal lease. Restart nginx, FPM and each worker launcher while the durable journal holds admission, then verify only the journal's engine can open after the publisher verifies the current boot. The local run covers three synthetic routes and one FPM worker under the fixture account. Actual vhost mappings and systemd wiring remain staging acceptance work.

## Post-write failback fixture

Request a separate disposable run with fresh evidence.

~~~powershell
$params.Scenario = 'postwrite'
$params.EvidenceDirectory = 'C:/rehearsal/postwrite-trial-01'
./scripts/cutover/host/run-local.ps1 @params
~~~

The real runner still refuses preparation and promotion without off-site backup evidence. After that check, a separate fixture admission record allows the disposable MySQL stack to accept an HTTP receipt and a Laravel database queue job. It cannot publish cutover authority.

The fixture closes HTTP and queue writes, disconnects the one identified MySQL session holding an uncommitted write, and verifies that the native MySQL read-only seal rejects subsequent writes. The runner records that separate recovery is required and declines the pre-write abort. The existing native reverse exporter uses a SELECT-only account to create a new SQLite file. The harness independently compares the fields to sealed MySQL and checks SQLite integrity and foreign keys.

Laravel then opens the fresh SQLite file with the same test application key, reads the previously acknowledged MySQL receipt, drains the migrated job exactly once, and accepts a new fixture HTTP write. The trial verifies the old SQLite file stayed byte-identical, sealed MySQL did not change and the cutover journal still requires separate recovery. The report measures from closure of fixture admissions through the first verified SQLite HTTP read. It records synthetic IDs and evidence digests. Production ingress, real daemon/scheduler drains, post-write operator approval and full-size data remain outside this local fixture.
