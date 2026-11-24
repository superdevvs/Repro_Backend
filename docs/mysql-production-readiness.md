# MySQL production readiness

## November 22 data rehearsal

The candidate is based on deployed backend
`9ef19f686d14b07de0995ee0b2cb157126d044a7`. The live application uses
SQLite. The rehearsal took an online backup with the source open read-only,
then imported the copy into a temporary, socket-only native MySQL 8.4.13
process under the deployment account. No application, scheduler or queue
worker used this database.

The corrected import verified all **291 tables and 1,841,678 original rows**
in **9 minutes 16 seconds**, including planning and initialization. It
installed all **477 foreign keys**, including eight relationships that retain
195 historical rows with 223 missing references, and installed 25 direct
triggers. A separate PHP reader used a SELECT-only account and a read-only
MySQL instance to compare all **29,909,713 fields** in 80 seconds with zero
mismatches. The first import attempt caught an oversized iGUIDE survey index.
The conversion now uses the original migration's declared 64- and
32-character widths for its two identity fields and retains the full unique key.

A separate same-host instance restored the compressed backup and verified all
291 tables, 29,909,713 fields, 477 foreign keys, 25 triggers and 166 next record
IDs. Exact column metadata matched. The DDL comparison accepted only a
redundant character-set clause where the collation was identical. Both
temporary instances stopped after verification. Off-site recovery remains
its own acceptance gate.

The fresh release build had 284 tables. The copy has seven additional
historical tables and other differences in constraints, columns, indexes and
triggers. The data rehearsal retained all seven tables. The protected drift
report must be reviewed against the final release and frozen snapshot before
signing a cutover layout.

On the retained rehearsal copy, a fresh metadata-only comparison verified the
copy's recorded SHA-256 and found 56 schema differences from the source release
and 62 from the candidate. Both fresh fixtures have 284 tables compared with
291 in the copy. Rebuilding the fixtures with locked dependencies reproduced
both schema fingerprints. The comparison queried schema metadata only.

The archived MySQL import catalog and the original empty SQLite template agree
on all **477 foreign-key relationships** across the 291 tables. The offline
comparison checks child and parent column order and both referential actions.
On the recovered copy, MySQL rejected an attempted invalid update for each of
the eight relationships with approved historical exceptions. Each transaction
was rolled back and its sampled historical row retained its original value.
Recheck the final snapshot and exception set during the dress rehearsals.
[Layout verifier](../scripts/mysql/verify-foreign-key-layout.py)

The draft disposition covers all 62 records. It proposes keeping 19,
using existing compatibility handling for 11, and collecting proof for 32.
The base SQLite recovery template was built from the pinned copy and contains
291 tables, 668 indexes and 16 original SQLite triggers. It contains no copied
application rows. A separate verified overlay adds the six optional messaging
columns and preserves the existing SQLite foreign-key ordinals. Reverse export
checks the reviewed MySQL-only iGUIDE trigger and recreates only the SQLite
trigger definitions in the restored database.

The resumed full-copy recovery restored the isolated MySQL backup and exported
all 291 tables and 1,841,678 rows to a new SQLite file in 125.3 seconds.
The export checked exact historical exceptions and enabled SQLite foreign-key
enforcement. An independent SELECT-only PHP reader compared 29,914,612 fields
with zero mismatches in a separate 75.84-second verification session. The
temporary MySQL process shut down cleanly. The restore and verification ran in
separate sessions around two compatibility corrections. Rehearse the complete
application failback and provider reconciliation under the outage clock before
signing off on recovery.

The November 23 data drill added two synthetic notification records to the
disposable MySQL copy and committed an update to one of them. The temporary
server was killed while a different write was uncommitted. It recovered the
committed records in a verified read-only restart. A new invalid foreign-key
write was rejected, and the explicitly rolled-back and in-flight writes were
absent. The first export stopped before copying rows because the packet omitted
the cascade trigger manifest. The corrected export resumed read-only, took
110.5 seconds, and produced 1,841,680 rows across 291 tables with exactly the
two intended additions. Independent PHP verification compared 29,914,626 fields
with zero mismatches. All other tables and the prior notification rows matched
the baseline. These were data-recovery steps over two sessions. The timed
application failback and provider checks remain open.

A later full-size private drill completed the failure recovery on one clock,
using a fresh isolated MySQL restore of the same retained copy. From the
controlled MySQL crash it took 14.091 seconds to reach verified read-only
mode, 128.898 seconds to export the new SQLite file, 207.601 seconds to finish
independent field verification, and 208.469 seconds to stop MySQL cleanly.
The recovery kept both acknowledged synthetic rows and excluded invalid,
rolled-back and unacknowledged writes. It matched all 29,914,626 fields
across 1,841,680 rows and 291 tables. The other 290 tables matched the
baseline. The rehearsal removed its new sensitive scratch data. The receipt
for release `fbfe24233423ebdb6fb1b505fce64d685e793e0e` has SHA-256
`d363c0d93a65e808520845f111f374545e3772dec853cb04ecfa9fc7445ab021`.
The clock ends with database verification and shutdown. Laravel reopening
and provider reconciliation still need their application rehearsal.

The separate managed MySQL 8.4.13 service on Maverick passed authenticated
checks on the empty instance before and after a controlled restart. Both
`www-data` and `maverick` initially received permission denied at the socket.
Granting the existing `www-data` group traverse-only access to the runtime
directory let both accounts reach the expected daemon. The managed v3 test
verified this access on start and restart without a manual ACL change. An
unrelated account was refused. The service and rotation timer were left
stopped and disabled. The live app still uses SQLite and the existing MySQL
service kept its process identity.

The isolated Laravel smoke now runs with the candidate's locked dependencies
and a disposable socket-only MySQL 8.4.13 instance. It verified that valid
credentials work and invalid credentials are refused, notification reads
persist with the correct user and watermark, rolled-back writes remain
invisible, and one native queue job produces one acknowledged effect. All 16
launcher tests passed on Linux. The disposable database was empty again after
the test. The smoke used only synthetic records.

Maverick refused unprivileged user namespace creation. The supervised smoke
verified a distinct private network namespace, a non-root UID, empty capability
sets, and no outbound route. The first supervisor stopped during filesystem
sandbox setup. The next refused its root-only service manager socket because
the check expected mode 0600 and Maverick uses 0700. The corrected host test
passed the isolation probe and all three database checks using the pinned
rehearsal release `0f644b36` and a synthetic schema. It verified that the production
paths and the service manager socket
were inaccessible. Each transient workload unit stopped and was collected.

The managed log-rotation service ran online and retained seven slow-log
archives through nine forced rotations. The offline test refused before
starting logrotate and left the logs and rotation state exact. Systemd kept the
one-shot unit marked failed after this intentional refusal. The coordinator's
final status check counted that marker as a cleanup failure. An independent
read-only check confirmed zero processes for the one-shot unit, no remaining
workload units, and both the isolated database service and timer stopped and
disabled. The candidate's loaded-copy workload and timed cutover remain to be
rehearsed.

The encrypted backup fixture published a SQLite backup and checkpoint to a
private S3-compatible HTTPS endpoint with rclone 1.76.0. A separate recovery
container had access only to the published objects and independently supplied
credentials. It verified both artifacts, SQLite integrity and the synthetic
records. The real bucket, retention rules and independent off-site recovery
still require acceptance.

The local MySQL fixture also published a consistent MySQL 8.4.13 dump, closed
binlog and checkpoint over encrypted HTTPS storage. A fresh recovery instance
used the recovered objects, restored the baseline, then replayed to the
reviewed transaction boundary. It recovered the first two synthetic rows and
excluded the later third row. Both database containers stopped and the test
network and volumes were removed.

A full-size recovery drill on Maverick used the isolated MySQL copy from the
upload rehearsal. Rclone encrypted the consistent dump and closed binary log,
then verified their decrypted sizes and hashes. The compressed dump was
766,321,760 bytes. A fresh MySQL 8.4.13 instance loaded the snapshot from
ciphertext, recovered the selected transaction and excluded the later one.
The comparison covered 293 tables, including two rehearsal tables, and matched
1,841,841 preexisting rows by table count and an ordered data-dump digest. It
also matched table definitions, indexes, foreign keys, triggers and next IDs.

Recovery took 191.7 seconds from fresh instance preparation through validation
and clean shutdown. The complete backup and recovery drill took 304.35 seconds.
Both private instances stopped. A separate check confirmed the live app still
used SQLite and the existing MySQL service had kept its original process
invocation. This rehearsal used encrypted storage on Maverick. Acceptance still
requires testing the designated off-host bucket, its retention controls and an
independent full-size recovery from that bucket.

The stock MySQL 8.4.13 PITR backup command also produced a 737,159,499-byte
compressed snapshot of the read-only copy. The backup took 44.04 seconds.
The MySQL staging command compared the dump's recovery position to its
producer receipt, verified the gzip stream and both artifact hashes, and
created the publisher manifest. The publisher's local checks passed 49.4
seconds after the start. The private database stopped and the temporary
plaintext batch was removed. No cloud bucket was configured for this run.

A separate synthetic recovery drill used three real MySQL 8.4.13 binlogs
closed by rotation. It refused a missing middle file before starting the
target, then replayed all three files on one connection through the selected
commit. The target recovered four committed rows and excluded the later fifth
row. A second drill using the stock backup producer and the repository checker
verified the same behavior. It checked actual event checksums, rotation
pointers, and the selected commit position before replay. Recovery, validation
and shutdown took 7.03 seconds in the second small fixture. Both drills used
fresh isolated MySQL instances and cleaned up their raw fixture data.

On November 23, an independent synthetic recovery passed against the actual
private Cloudflare R2 rehearsal bucket. Maverick published a stock MySQL
snapshot, three closed binary logs and their two metadata files. The publisher
verified all six artifacts after upload. A raw R2 listing found eight stored
objects, including the encrypted manifest and completion record, with encrypted
names. The Windows recovery environment received only the publication receipt
and used a separate bucket-scoped read-only key to download and verify all six
artifacts. A fresh network-isolated MySQL 8.4.13 instance restored the snapshot
and replayed three logs through the selected commit. It recovered rows 1–4 and
excluded the later row 5. Native replay and clean shutdown took 5.326 seconds
for this small fixture. The local recovery containers, private volume and
temporary Maverick credential files were removed.

The full-size follow-up published **753,141,689 bytes** of synthetic data
across seven artifacts to the private R2 rehearsal bucket. The encrypted
upload and publisher read-back took 140.94 seconds. A separate read-only
credential on Windows retrieved and verified the seven artifacts in 57.72
seconds. The decoder verified three closed logs and both the chosen and later
commit positions. A fresh network-isolated MySQL 8.4.13 process loaded the
snapshot, replayed through the chosen commit, and stopped cleanly in 51.18
seconds. All 79,168 generated payload rows and their indexes matched. The
four expected witness rows remained and the later fifth row was excluded.
The independent replay also regenerated each expected digest from the test
seed. Its report SHA-256 is
`24ebdbed04dec236290a54e44b92d817be8709a36fac99d9e2fe8874c62cbbbb`.

Only generated data went to R2. The two credential configurations stayed in
temporary memory-backed storage. The run removed the nine exact encrypted
test objects, both credential configurations, the temporary source files and
the independent recovery volumes. The rehearsal bucket's only lifecycle
rule aborts unfinished multipart uploads after seven days. It has no bucket
lock rules. Automatic backups and protected retention remain acceptance gates.

A separate off-site application rehearsal published **13,512,184 bytes**
from draft release `1683dfaa6e503a201f61158857b42c48d533b907`. The
application-scope kit contained 1,759 allowlisted Git source files, locked
production dependencies and a wrapped synthetic Laravel key. Historical seed
records, files with address-bearing examples or history, cached configuration
and the live application key were outside the archive. The synthetic wrapping
key stayed in separate local custody.

The encrypted upload and read-back took 21.33 seconds. The independent
read-only recovery took 8.23 seconds to fetch and verify the three artifacts.
A fresh offline container used only those recovered artifacts and the
separately held wrapping key. It booted Laravel 12.71.0, decrypted the
synthetic stored value and rejected the wrong wrapping key in 3.73 seconds.
The restore report SHA-256 is
`616eaf5fef544430d60f55c29cdf4917ab23a4327711a14220015cf83fe00b5b`.
All five encrypted test objects, temporary payloads, credential
configurations, the synthetic escrow and the recovery volumes were removed.
This verifies boot and key recovery for the allowlisted rehearsal package.
The full production recovery kit and its protected key custody still require
operational acceptance.

## November 24 backup and cutover integration

The scheduled base backup runs the stock producer, stages a closed snapshot,
and records a new restore point after encrypted publication and read-back.
The separate local health check reports stale or failed attempts. On a
private HTTPS storage fixture, both the SQLite and MySQL 8.4.13 workflows
published a baseline, reported an injected storage failure, preserved the
last verified receipt, and published a fresh UUID after storage recovered.
Independent consumers restored the expected synthetic records. The MySQL
consumer verified the snapshot's binlog coordinate and restored into a new
native MySQL instance after the source container stopped. All owned
containers, volumes and fixture networks were removed.

The backup and watchdog unit definitions passed Linux parser checks and
fixed-argument alert tests. A separate transient timer in Maverick's user
manager started its synthetic job. A failed watchdog fired the alert handler in
order. All test units and scratch were removed. Activating the real backup
schedule against the approved destination, delivering alerts to external
monitoring and proving live retention remain operational acceptance gates.
The retention verifier tested local evidence for exact prefix, duration,
provider refusals and unchanged content.

The private R2 rehearsal bucket also enforced a real 24-hour lock on a new
synthetic prefix. A two-day expiry was configured for the same prefix. R2
refused an overwrite and a delete with a delete-capable credential with
`ObjectLockedByBucketPolicy`. The writer lacked delete permission and got
`AccessDenied`. A second read returned the original 80 bytes. The trial
removed the object, both rules and the disposable bucket credential. The
seven-day multipart-abort rule and older rehearsal prefix remained in place.
The receipt's SHA-256 is
`8b5f14d8106630077ba689807974022a9f13d6d9e63899ad389957e7a2b6d3f4`.
Rule settings were observed in the Cloudflare dashboard. The trial removed
the rule before expiry was due. The approved production backup destination
still needs its own retention and recovery evidence.

An administrator-supervised Maverick rehearsal used the stock scheduled
runner from `0dfa65dd`, a private dynamic account and loopback-only TLS S3.
Three real systemd timer activations published a SQLite backup with verified
read-back, reported a forced storage failure, and published a fresh backup
after recovery. The failure preserved the last verified receipt and invoked
the systemd `OnFailure` alert. A separate restore process used its own fixture
credential. With the original SQLite file hidden, it independently read and
verified the expected row in the decrypted backup. All ten temporary units,
cgroups and private staging directories were removed. The report SHA-256 is
`1c428eca504dec29b03bfc9391f830f488f0b2d658da6d6907de51054f93f919`.

The closed-binlog stager accepted two CRC-checked files from actual MySQL
8.4.13 rotations and proved the next filename. It refused the current active
log. The collector and archive runner now connect the socket-only catalog,
bounded staging, encrypted publication and read-back to the checkpoint.
The checkpoint advances only when the next expected file and the verified
publication match. Rotation cadence, backlog alerts and timed replay against
the accepted destination still require operational proof. The frozen-base
reviewer verified both publication chains against independently pinned SQLite
snapshot and MySQL coordinate evidence in a local fixture. It rejected
preflight-only evidence and reused backup IDs.

The native archive coordinator trial used the stock PHP base producer,
MySQL 8.4.13 and disposable TLS S3 storage. The collector ran as UID 1000
with a read-only mount of the source log volume. A failed provider call left
the checkpoint byte-identical. A one-file batch limit required two successive
verified publications with distinct IDs. The first left a closed log pending
and the second caught up to the observed active file. A final idle run while
the provider was unreachable kept the checkpoint and receipt set unchanged.
The archive phase took 17.385 seconds for this small fixture. Its synthetic
containers, volume and network were removed. The report SHA-256 is
`9358b239c2e9898fbef28fcb163c371747ecb794b4435da7eedc4c493a1b1c2b`.

The automated archive also passed a point-in-time restore from private TLS
S3. The stock base and two archive publications were the reader's only data
inputs. The source container and volume were removed before a fresh MySQL
instance began recovery. The reader verified artifact hashes, binlog CRCs and
the chosen commit boundary. Replay retained rows 1–3 and excluded the later
row 4. The chosen write took 21.313 seconds from its command to verified
archive read-back, including an injected provider refusal. Recovery took
6.594 seconds from fresh target startup through verification. These timings
cover a small synthetic fixture. The report SHA-256 is
`03dc243b96b8cf3d579c890e907b99cf8f8b360eb0080d4f8d40cef39b46594f`.

The dedicated rotation account passed a separate native MySQL 8.4.13 test.
Its locked-definer procedure advanced the active file exactly once. Direct
flush and reset were refused for the caller. Enabling automatic purge in the
disposable instance blocked rotation before the call and left the inventory
intact. The server exited normally and its private runtime was removed. The
report SHA-256 is
`7a96ef45b3e5a142000b6261c49ec9f5b30654bdd4b4d3d5485bc4b99f53bf85`.
The disabled-by-default two-minute timer, dispatch units and unknown-outcome
guard passed offline contract checks.

The combined Maverick rehearsal passed with transient systemd units on a
disposable native MySQL 8.4.13 source. Two real timer activations occurred
120.180 seconds apart. The first rotated one file and dispatched the archive
job on success. On the next tick, the rotator saw automatic purge enabled,
refused to call the routine, cleared the rotation guard and dispatched the
archive job on failure. The archive job used its separate account and an
explicit read-only mount. It verified both encrypted publications over
private loopback TLS and advanced the checkpoint through `binlog.000003`.
All twelve temporary units,
runtime directories and staged packet files were removed. The report SHA-256
is `c009060b0258d6a70a4f1c6c5a95a3663651d04f6f48790ff45de88256b06952`.

The [recovery heartbeat](../scripts/mysql/offsite/heartbeat.md) passed a
separate 19.86-second synthetic MySQL 8.4.13 rehearsal. The scoped source
writer recorded server UTC in one minute slot. A same-minute retry returned
the stored row with no change to the row count or binlog position. The closed
binlog contained exactly one committed heartbeat event. A fresh instance
restored the marker from a single-database dump after the source and its
volume were removed. Its SELECT-only account accepted the marker exactly 60
seconds before the target and rejected a 61-second gap and a marker after the
target. The recovered row remained unchanged. Both instances shut down
cleanly and the fixture resources were removed. The report SHA-256 is
`ea7dbd49f9409db8739257f8ee9fa4978326ed50c86074c9beca6bd2ea8dc4f9`.

The heartbeat has no production schema or timer installed. Verify the
one-minute cadence and clock on the approved source, then prove a preceding
marker after replay from the protected bucket on a separate recovery host.

The local cutover adapter froze a disposable SQLite Laravel state, copied
four fixture tables into MySQL, verified read-only application access and
refused promotion while the off-site backup gate was unsatisfied. Aborting
reopened SQLite and drained the queued job exactly once. The two recorded
intervals were 613 ms from freeze to the MySQL smoke and 440 ms from abort
to restored SQLite HTTP. This used a synthetic HTTP route and a one-shot
worker. The full timed host rehearsals still need the real ingress,
scheduler, writer inventory and representative load together.
The same test on `08a6e4d855ec29f52c9a24945be888cf41e8d94c` took 587 ms
through the target smoke and 350 ms from abort to the recovered HTTP read.

A pre-write abort against the published `0dfa65dd` source archive reopened
SQLite HTTP in 305 ms. The post-write fixture held HTTP and queue writes,
disconnected an identified MySQL session with an open transaction and proved
native read-only enforcement. The real runner required separate recovery.
The reverse exporter used a SELECT-only credential and created a fresh SQLite
file. All fields in four synthetic tables matched sealed MySQL. Laravel
reopened in 528 ms from the fixture hold, kept the acknowledged MySQL receipt,
ran the queued effect once and accepted a new SQLite HTTP write. The uncommitted
row was absent, and the original SQLite file stayed byte-identical. The pre-write
and post-write reports have SHA-256 digests
`e3fa03144cebbc05414e763e60bbcc2af20bfff50c70727f3948a1c9bd16533e` and
`ae67a58a804b6391f521d00f37588dbc056fb0b21c55bd6186a2b090bb743ff6`, respectively.
The production-sized service drains and recovery run are open.

In a separate isolated webhook trial, the actual capture adapter returned
HTTP 503 while its intake lock was held and while replica verification was
unavailable. An identical protected retry returned 204 and kept the encrypted
receipt unchanged. Two fresh replay processes recovered all seven acknowledged
receipts after the original spool was removed. They produced identical dry-run
projections, and a missing receipt stopped replay. The replica was a second
local directory. Live ingress, off-host durability and effects still need
their integrated rehearsal.

The snapshot-age check accepted the retained synthetic R2 receipt and exact
manifest at the 26-hour limit. It returned stale and a nonzero exit when the
test applied a one-second limit to the same evidence. Both checks ran locally
on Maverick, with no request to R2.

Two isolated pre-write rollback trials set the Laravel configuration cache to
an unavailable MySQL socket, then used the cutover journal and write fence to
restore the saved SQLite cache. The fence denied both admission probes during
authority publication. SQLite authority was restored in 332–340 ms and the
first permitted fresh Laravel read succeeded in 472–489 ms. The SQLite file
and saved configuration cache matched byte-for-byte, and one pending
synthetic job was preserved in each trial. The timings cover the local rollback
component from abort through the first admitted read. The host rehearsals
still need to measure service and worker restart and reopening.

An isolated post-write trial timed the sequence from disconnecting a pending
synthetic writer through the first fresh Laravel read from the new SQLite file
at 371 ms. It proved MySQL read-only before using the existing reverse exporter.
The new file kept both acknowledged transactions, excluded the uncommitted
job and accounting change, and matched all 34 independently compared field
values across eight rows and four tables. Next IDs matched and SQLite foreign
keys were enabled. The old SQLite file stayed byte-identical. This was a small
synthetic trial. The timed full-size host failback remains open.

The current SQLite backup producer and encrypted publication staging passed a
separate fixture with a WAL writer open. The exported single file retained the
committed row and excluded the uncommitted row. The source database and WAL
hashes stayed identical. Staging checked the backup bytes, SQLite integrity and
table count, then wrote the publisher manifest. The real destination and
independent recovery remain acceptance gates.

Concurrent notification updates initially deadlocked on MySQL. The fix locks
the user's row before adding or updating that user's notification state.
The native regression reproduced error 1213 with the original service and
passed with the fix. Two separate small comparisons then completed 1,600
measured service calls, including 320 writes, with the expected final state.
In this fixture MySQL was slower than SQLite. The measured paths run through
the Laravel service and do not represent peak HTTP/FPM or copied-data load.

The paired HTTP workload contract fixes the request schedule before either
engine runs and reports each route separately, including time waiting for
concurrency capacity. The contract tests refuse incomplete schedules,
uncomparable sources, database warnings and logical response differences.
Host load results require a supervised Laravel execution on the two copies,
alongside write, upload, queue and crash checks.

The synthetic FPM fixture completed 56 authenticated `GET /api/notifications`
requests across both engines and two engine orders, with four separate 401
checks. It used the existing route, Sanctum authentication, and request
telemetry. The measured requests produced zero errors, database warnings or
logical response differences. The small MySQL samples were slower under
this instrumented configuration. They establish behavior for one endpoint
and leave production-sized latency and capacity open.

The subsequent [two-route FPM comparison](../scripts/mysql/workload/HTTP-COMPARISON.md)
added incoming voice offers to notifications. All 224 authenticated requests
matched across both engines and engine orders. Four invalid-token and four
client-permission checks passed. The eligible and expired offers were correct,
the persisted voice state was unchanged, and no voice jobs were queued.
MySQL voice p95 was slower in both synthetic surge rounds. This is a signal
to measure the same route on the host with the copied dataset and realistic
worker and connection settings before accepting performance.

The first native two-route run against the server copies passed all 224
requests and 12 access checks. The sustained follow-up passed all 6,364
requests and 12 access checks with matching responses and zero observed
request errors or database warnings. MySQL p95 was within 2.5% of SQLite
across the measured groups. Wider route and workflow coverage and production
capacity checks remain open. The [workload report](../scripts/mysql/workload/HTTP-COMPARISON.md)
records the comparisons and the verified cleanup.

The native upload-acknowledgement run completed 224 matched GETs on the
server copies. The assigned photographer and staff could read the completed
batch. The client and unassigned photographer received 403. Separate
provider-disabled workflow regressions passed 14 cases and 114 assertions
per engine for upload retries, booking permissions and overlap protection,
and invoice report totals. Those regressions ran on fresh synthetic
databases. An independent SELECT-only check against the post-rehearsal
MySQL copy also matched 170,534 selected financial fields across 24,465
invoice, invoice-item, payment, allocation and refund rows against the
closed SQLite baseline.

An expanded synthetic run passed the same 19 cases and 136 assertions on
each engine, covering rescheduling, delivery, payment allocation and refunds,
payout review, and mocked SMS. All 48 cutover runner, write fence and readiness
contracts passed on Linux. The fixtures ran with networking disabled.

A four-client Laravel service comparison completed 6,400 notification reads
and 1,600 writes across both engines and two engine orders. Each round matched
the expected final notification state and all 418 shared-user receipts. Write
p95 was 19.60–19.63 ms on SQLite and 22.25–22.43 ms on MySQL. Separate queue
contract runs passed seven cases on SQLite and four on MySQL. These
measurements used synthetic data on memory-backed filesystems. The combined
HTTP and queue workload on the server copies remains an acceptance gate.

A short 20-client Laravel command-line load then ran alongside two actual
database queue workers. Across four engine-order rounds, all 8,000 notification
operations completed and all 960 jobs produced exactly one committed effect.
Both workers operated during each roughly 2.47-second interval with all
clients active. Queue backlogs drained to zero and final notification state
matched. In this fixture, write p95 was 80.18–130.53 ms on SQLite and
52.68–54.83 ms on MySQL. SQLite completed reads and queue drain faster.
The sustained HTTP load and crash-recovery timings still require the host
dress rehearsals.

The initial single-writer rule has a separately tested MySQL component.
Its lock row lives in a proposed control schema so the application snapshot
still compares exactly. On MySQL 8.4.13, 23 tests checked the scoped grants,
transaction isolation, lock timeout and rollback, savepoints, peer readers,
wrong-target refusal, and release after a worker was killed. The application
does not invoke this component yet. Before enabling it, verify all Laravel and
direct database write paths, the queue exception, retry and cache behavior,
restore provisioning, and representative contention.

A fixture-only integration exercised Laravel's actual transaction manager and
the same native writer lock. The 40 combined native contract tests covered
outer and nested transactions, deferred callbacks, pre-write retry, lock
failures caught by a caller, and a lost commit acknowledgement. They verified
that an uncertain commit cannot replay the callback. Cover autocommit writes,
raw connections, queue and cache behavior before considering application
registration.

The refreshed read-only host inventory observed 12 queue workers. Three use
Supervisor, one uses the media archives service, and eight appear under the
cron service. PHP-FPM and nginx are active. The final rehearsal must verify
the scheduler, worker restart sources, provider intake and manual/deploy
writers before reporting a freeze.

Network-isolated subprocess tests verified the runner and admission guard
against the same real lock and journal. An admitted operation held off a
competing freeze. A process killed after publishing authority proof left both
engine admissions closed until recovery. The private Telnyx inbox can also
authenticate an inventory of its captured receipts and flag multiple signed
versions or voice events for review. The host admission wrappers and
independently durable webhook capture and replay still need isolated
integration proof before any production wiring.

The exact deployment admission guard at release `91f6241429fc` also passed
98 isolated Linux cases using its fixed protected paths and actual exclusive
locks. It held deployments closed until the selected application engine and
reconciliation were both verified, and rejected stale activation proofs.
The guard, runner and readiness suites passed 53 checks on Maverick without
starting its application or workers.

A disposable Linux scheduler fixture reproduced a freeze gap. A background
writer detached, closed the inherited lease descriptor and committed one
SQLite row after the wrapper was killed and an operator acquired the exclusive
lock. The proposed durable permit refused the transition, refused another
launch while starts were closed, and allowed the transition only after the
existing writer exited. The fixture exercised real Linux processes and the
SQLite write. The systemd supervisor, permit retirement and Laravel scheduling
still need integration proof together.

The runner now requires a scheduler hold tied to the cutover identity and
boot before it can freeze the databases, prepare or promote MySQL, or seal a
SQLite rollback. The promotion approval binds the hold. A separate Linux
run passed 57 runner, cross-process lock, fence and readiness checks,
including a pending scheduler permit and a hold that changed during authority
publication. The fixture supplies the hold from local state. The host still
needs to supply it through the supervised scheduler.

Maverick's user-level systemd fixture stopped before it could start a scheduler
writer because its namespace controls left host interfaces, the manager socket
and a writable cgroup visible. A subsequent administrator-supervised test
passed both scenarios in temporary systemd units. It verified a private
network, blocked socket creation and access to the systemd manager and live
application files, and mounted the cgroup filesystem read-only.

The detached writer committed exactly once after its wrapper exited. Its
permit stayed present despite acquisition of the exclusive lock. The root
supervisor verified the bound writer exited, its cgroup was empty, and no
manager job remained before retiring the permit. It then cancelled a pending
manager start and confirmed it committed nothing. The two drains took 6.53 ms
and 6.27 ms, measured from the stop request to observed drain. The transient
units and scratch were removed, and the existing MySQL invocation stayed the
same. The synthetic witness report has SHA-256
`b82cea13b4fa9b79094de86936361bc2fc47f5fa5f8f44e0bbc92ae15e60ebc7`.
Laravel's scheduler and all other writer sources still need to exercise the
production adapter during the complete dress rehearsals.

A separate disposable PHP process recovered and authenticated all eight
synthetic acknowledged receipts from the second encrypted spool after the
source spool was removed. The dry-run projection recorded one SMS effect,
one duplicate and six receipts requiring review. Missing, additional or
altered receipts failed reconciliation.

Two separate native trials on `917f6357` exercised the stock host runner with
real Laravel HTTP and queues on disposable four-table databases. The hold to
verified MySQL read-only smoke took 576 and 582 ms. Verified SQLite recovery
took 357 ms from the pre-write abort and 623 ms from the post-write hold. The
second trial independently matched the reverse export, preserved the committed
MySQL write, excluded an uncommitted write and processed the queue job once.
Off-site refusal remained enforced. Both trials removed their containers and
volumes. The timestamped receipt has SHA-256
`cfe788228e8de3aac98adfa8255ec0b811a42f630c7e6ca8d40501c380f181c3`.

Full dress acceptance combines a fresh production-sized copy, all real writer
drains, provider intake, representative traffic, protected off-site recovery,
and both rollback paths under one clock. It requires two timed runs. Live
cutover requires distinct approval.

## November 8 refresh

The candidate now includes production release `46284c95`, retaining the newer
component/provider delivery, notification timing/privacy, travel-fee and Zillow
changes. The November 5 results below remain historical evidence; they do not
establish acceptance of this refreshed source or current production data.

Separate MySQL 8.4 host provisioning has been authorized. It is still **not
installed**: both SSH and the Maverick server chat require interactive
administrator authentication. The live application remains on SQLite, and no
application deployment or database switch is authorized. The server-side
administrator preflight is at
`/home/maverick/repro-mysql84-provisioning-20261107/admin-preflight.py`.

The service templates now use dedicated UID 64084 instead of the host's
dnsmasq UID 999, bounded container logs, and an isolated slow-log rotation
timer. An authenticated unit preflight runs before logrotate; its in-config
precheck alone cannot prevent older archives moving when the database is down.
Local synthetic validation passed fresh initialization, PHP UID 33 socket
access and account isolation, durable recovery of 100 committed rows with one
uncommitted row rolled back, nine log rotations, ownership, compression,
seven-file retention, and refusal to touch logs while the database was offline.
That smoke test used a 256 MiB buffer and 1 GiB cap to limit workstation memory;
it is not a host-capacity test. Rotation hooks used matching direct clients in
the fixture; actual host Compose/systemd execution remains to be accepted.

The three new delivery migrations now match signed imported or unsigned native
parent IDs and use a MySQL-compatible provider index name. Provider observation
locks its shoot before the duplicate check, preserving one event under
concurrent identical observations. External receipt identity comparisons sort
JSON object keys while retaining strict key, value and type checks, because
native MySQL JSON does not preserve insertion order. New native tests cover the
real migrations and two independent worker processes; cross-engine receipt
tests cover reordering, changed identity and revocation. The travel-fee test builds a portable
SQLite safety-backup fixture without weakening the repair command's checks.
Focused runs passed: 16 MySQL migration/concurrency tests with 224 assertions,
72 MySQL delivery/fixture checks with 766 assertions, and 49 affected SQLite
tests with 645 assertions. These runs overlap and are not summed. Removing the
provider row lock in a disposable source copy reproduced the duplicate-key
race; restoring it passed both native cases with 40 assertions. Broader refreshed application checks and CI
are recorded in the draft PR and protected refresh evidence against the final
candidate commit; this document does not turn historical results into current
production acceptance.

Protected refresh evidence is under
`C:\Users\shubh\.codex\repro-mysql-lab\20261105\host-refresh-20261108`.
Do not reuse the older customer snapshot for a production cutover. Actual-host
installation, fresh-copy parity, host load, off-host restore, provider/device
acceptance and a separately approved cutover window remain outstanding.

## November 5 rehearsal record

The isolated migration candidate is prepared and tested. Production activation
is **not approved or complete**. This migration work made no production
application/configuration/service or business-data changes. Read-only SSH inventory and an online
read-only SQLite backup were used for this rehearsal.

The remaining production gates are the actual selected MySQL 8.4 host,
administrator provisioning and operational acceptance, a fresh frozen-data
rehearsal there, final CI/release approval, and an agreed cutover window. Live
provider and physical-device acceptance must use a designated sandbox/device or
explicit authorization. No real payment, message or customer job was executed.

## Candidate and evidence

The backend candidate is based on upstream `bb4271b8`, preserving brokerage-logo
uploads, the newer rep impersonation release, Aryeo handoff and Symfony security
updates. The frontend reference is `98af9062`. Backend runtime changes were
reconciled and retested; the frontend has no migration-specific edits. Dirty primary checkouts were
preserved in separate worktrees. The final release commit, source hashes, image
identities and report checksums belong in the protected final manifest.

Private lab: `C:\Users\shubh\.codex\repro-mysql-lab\20261105`.
Application source: the isolated `mysql-migration-20261104` worktree's backend.
The older November 4 evidence and checkpoint were retained separately.
Customer snapshots, credentials, media and detailed reports are not committed.

The fresh source snapshot was taken at **2026-11-05 02:44 UTC**, from deployed
backend `c253567153e2beed1a4161b9f13ba60712aa6740`. It contains **231 tables and
1,185,728 original rows**, with SHA-256
`4a94bc0eef698a15852c08869ac039aacd9dfc9db8f1e00f20391f4eb93ab52f`.
Every original row/field matched immediately after conversion. Subsequent lab
activity is kept distinct from that initial data-preservation claim.

The owner-approved history is unchanged: **195 distinct rows, 223 missing
references across eight relationships**, including ten financial/audit rows.
No historical row was repaired/deleted or given an invented parent. The exact
exception manifest is private; all eight negative new-write checks rejected
invalid links. The final candidate has **385 foreign keys, 791 non-primary
indexes (180 unique, 611 non-unique), 15 checks, 35 triggers and 369 migration
names**. This includes MySQL's supporting FK indexes and the reviewed repairs.

## Verification results

| Area | Verified result |
| --- | --- |
| MySQL regression inventory | 6,024 cases in two independent server shards. One missing local TIFF tool and five PDF tool skips were resolved with a complete Ubuntu runtime and focused reruns; 12 SQLite-specific cases remain intentionally outside MySQL execution. |
| Current-release backend checks | 105 tests / 988 assertions passed on MySQL, including the newer permission release, TIFF/PDF/HDR, backups, native revision races and queue contracts. Zero skips/failures in this focused run. |
| Brokerage release reconciliation | The later brokerage-logo release is retained. Logo permissions, persistence, tours and failed-upload cleanup passed within 45 MySQL tests / 323 assertions and 43 SQLite tests / 303 assertions, including the shared failure-injection callers. The new frontend paths passed 31 focused cases. |
| SQLite fallback | 830 affected cases / 6,882 assertions passed, plus 74 current-release/media/deployment tests / 680 assertions. Counts overlap and are not summed as unique coverage. |
| Frontend | 5,862 of 5,863 cases passed in the full run; one registration case hit its 5-second timeout. Its unchanged file then passed all 18 cases. After upstream reconciliation, 54 affected cases, type check, lint baseline and production build passed. |
| Copied business APIs | 39 admin/role comparisons matched status and business values, apart from independently checked signed-URL expiry and numeric roundoff below 0.00000001. |
| Desktop/mobile web | Actual login form and eight page checks across two browser viewports passed without API server errors, unhandled browser errors or page-level horizontal overflow. Phone checks are emulation, not physical/native-device proof. |
| Real media sample | Eight original/thumbnail files (34,730,787 bytes) matched source hashes and scanned clean. Five permitted uploads/replays, three oversized paid-upload denials, eight owner download hashes, eight private previews, eight foreign denials and eight staff denials passed. ZIP entry integrity was verified for the sample; application archive contracts also run in the regression suite. |
| Existing public media | All 237,265 original photo-signature inputs matched after removing only MySQL's whole-second timestamp padding. Existing links require the original APP_KEY at eventual cutover. |
| Full backup/restore | Driver-aware helper produced a 311,610,171-byte protected dump. All 231 restored tables, data/DDL, column metadata, 35 triggers, 385 FKs and next IDs matched. |
| Worker recovery | Twelve actual Laravel workers processed 1,021 synthetic deliveries into 1,001 unique committed effects. A killed worker's transaction rolled back and its reserved job recovered; shared-cache pause held the backlog and resume drained it. No customer job ran. |
| Proposed server configuration | Pinned 8.4.13, Unix-socket-only operation, strict SQL, durable commits/binlogs, 5 GB buffer pool and 96 connections passed locally. PHP 8.3.6 as UID 33 connected and wrote only its own DB. Automatic restart preserved 100 committed rows and rejected an interrupted transaction. |
| Copied-server crash after HTTP load | All 164 newly committed notification receipts survived; their hashes and server UUID matched, and the existing FPM application reconnected with HTTP 200. |
| Packaged forward/reverse tools | Wrong UUID/nonempty destination refusal, exact historical exceptions, binary/date/decimal fields, next IDs and new-write constraints passed. Reverse export refused unrepresentable numeric precision instead of silently losing it. |
| Driver-specific migration ledger | A guarded completion tool reapplies the five idempotent MySQL repairs even when SQLite already marked them applied. It preserves the original ledger IDs/batches and data, validates the expected target UUID, and requires writer-freeze attestation. |
| Full post-write reverse recovery | Packaged reverse tool verified 231 tables / 1,187,699 current lab rows with zero field mismatches, 25 SQLite triggers, exact exceptions and FK enforcement. The same application booted on the read-only recovery artifact and retained all 164 load receipts, the earlier queued-job fixture and invoice queries. |
| Deployment guards | 23 storage safeguard fixtures and the public-storage deployment tests passed. The deployment wrapper now makes a driver-aware private database backup and the MySQL profile is included in the required `deploy.yml` quality workflow. |
| Real HDR tools | The complete local runtime includes both alignment and Enfuse; actual fusion passed on PHP 8.3.6 with five assertions. CI explicitly installs/checks both binaries and runs this real-tool case. |

Local runtime is Ubuntu 24.04, **PHP 8.3.6 FPM with OPcache** and MySQL
**8.4.13**, pinned to image digest
`sha256:a81d043930e51745522bd5c430961cf8286f0a23f0a9a2a6e0108f7ea71b0902`.
Tests and copied data use separate accounts/databases. Outbound application
network access and mail delivery are disabled, and copied queues/schedulers are
not running. The production APP_KEY was not copied into the lab.

The pristine dump SHA-256 is
`04b45c59c50779b349a00cef58bbc1979d44ff07afac8edb51eb358d4ff45894`.
Its comparison permits only redundant `CHARACTER SET utf8mb4` rendering before
the same collation; independent column metadata matched exactly. It was taken
before preview accounts and HTTP load, after the reviewed compatibility DDL.

## Measured performance

The controlled mixed workload used real nginx/FPM, copied data, authenticated
reads and notification writes. Both databases had four FPM children, 2 CPUs and
2 GB application memory. MySQL additionally had a 4-CPU, 4-GB database service
with a 2-GB buffer pool; SQLite runs inside FPM. This resource allocation and the
local Docker/storage environment limit comparisons with the actual server.

| Concurrent requests | SQLite req/s | MySQL req/s | SQLite p95 | MySQL p95 |
| ---: | ---: | ---: | ---: | ---: |
| 4, 400 measured requests per engine | 7.39 | 9.29 | 3,320.3 ms | 2,026.2 ms |
| 12, 1,200 measured requests per engine | 6.82 | 6.97 | 4,993.9 ms | 3,839.6 ms |

All **3,200 measured requests returned HTTP 200**, and all **320 measured
notification writes** were retained. Warmup writes are counted separately.
MySQL's aggregate p95 improved by 39% and 23%; throughput improved by 26% and 2%.
This is not a universal endpoint speedup: some medians and higher-load endpoint
tails were slower, including the dashboard overview and shoot list. Email search
still dominates the workload. Accept endpoint limits and capacity on the actual
host before production, rather than extrapolating an empty-DB or local result.

The `0dfa65dd` synthetic FPM repeat matched 224 paired GETs and 20 access
checks across notifications, voice and upload summaries. Peak/surge arrival
p95 exceeded SQLite by more than 10% in 10 of 16 route-round groups. The
largest was voice at 185.6 vs 294.1 ms over nine samples per engine. The
small populations, short test phases and disabled OPcache limit the result.
Use it to select routes for the representative performance run. The report
SHA-256 is `8af507fa7c062c86981d70906c90c44416abc83ced504f8c71a74bff0d968b00`.

A follow-up emphasized voice incoming offers and upload summaries. Across
four counterbalanced synthetic arms, 800 requests and 20 access checks
matched with zero errors, warnings or logical differences. Peak MySQL p95
rose 15% and 26% for voice, and 6% and 16% for upload summaries. Laravel
reported higher database time on MySQL for both routes in both peak rounds.
At surge, slower service in each second arm occupied both request slots and
accounted for the longer wait. Dispatch after a slot freed took under 1 ms.
The cause of the slower service and representative host performance remain
open. The verified timing report SHA-256 is
`5aba1afe821239b2e895408b9f5b4a2eb0a2e4f4df3e19c124def6da229eeab2`.

A repeat at `15300b26` started a fresh FPM container and database instance
for each arm. All 800 requests and 20 access checks passed. Surge dispatch
p95 stayed below 43 ms across all four arms. MySQL surge p95 for voice and
upload summaries stayed within 5% of SQLite. Logged database time remained
higher on MySQL, and OPcache was disabled in all arms. Separate fixture
clocks produced timestamp-sensitive payload differences, so exact cross-arm
voice and upload response parity remains open for this run. The verified
report SHA-256 is
`7dd3eb7a94426726988cb192e3a5cdc196ce8b083fab5efd2cbb866827ad1912`.

A separate upload-summary regression reproduced three service-item queries
for a photographer's request with two service items. The route now loads the
service items once and reuses the existing authorization check. The focused
suite passed nine tests and 57 assertions on each engine, including assigned
and unassigned photographers, unit scope and upload acknowledgement replay.

The fresh-container parity repeat at `9c54c7a9` pinned the same application
source, dependencies, request profile and logical UTC clock for both engines.
SQLite and MySQL returned identical canonical JSON in 200 matched request
pairs across voice offers, upload summaries and notifications. All ten access
checks passed and all fixture containers and volumes were removed. The
verified report SHA-256 is
`217a0b6f86eed0b9bd7a6932af49c04cee43059e87476f531cd0cdca84443c02`.
The representative host workload remains a separate gate.

A separate synthetic CLI comparison at `0dfa65dd` ran 8,000 measured
operations and 960 jobs with 20 clients and two Laravel workers in each arm.
Every expected job effect was present once, with zero failures or backlog.
SQLite to MySQL write p95 fell from 55.1 to 29.2 ms and from 130.8 to 58.9 ms
across the two engine orders. Read p95 and queue drain time rose in both
rounds. This comparison measures CLI and queue concurrency. The FPM trial
above exercised HTTP separately. The verification report SHA-256 is
`08ccc13193fa9fcf33d1dd1c2bbf5ebff7ae2cace478bd3126e686698f9d1824`.

## Operational work still requiring approval

The read-only inventory observed MySQL **8.0.46**, 32 CPUs, 32 GB RAM, 20 FPM
children and twelve application workers on Maverick. The separate socket-only
[native MySQL 8.4.13 service](../scripts/mysql/native/README.md) subsequently
passed empty-instance acceptance. The last attended check left its service
and timer stopped and disabled. Reconfirm the installed bundle and saved
identity before another attended trial.

Before cutover, the actual target needs administrator acceptance for service/boot
behavior, FPM and both worker owners, measured load, storage/disk alerts,
off-host backup destination/retention and an independent restore. Inventory all
cron/timer writers and provider event handling, designate any required live
provider/device checks, and approve a maintenance window. Revalidate current
release/data drift at that point. The read-only inventory and test copy are
time-specific; do not import this older snapshot into a live application.

Use the [native storage placement check](../scripts/mysql/native/README.md#read-only-storage-separation-acceptance)
to compare the reviewed originals drive with MySQL's actual private data
directory. The Laravel media health command checks the application's local
`database/` directory in MySQL mode. The native check passed its isolated
Linux tests. Actual placement on the reviewed host still needs a receipt.

The plan calls for seven clean days of verified backups, a snapshot age under
26 hours and off-site binlog lag within five minutes 99% of the time. Test
overwrite and delete refusals with the backup writer credential and verify
alert delivery independently. Time two end-to-end rehearsals with comparable
load, the whole writer inventory, provider intake and both rollback paths.
The agreed maximum production write outage is 60 minutes. Schedule a separate
cutover decision after these gates have evidence.

The [offline readiness checker](../scripts/cutover/readiness/README.md) now
requires independently reviewed report digests for that backup window, exact
provider retention, both external alert rounds, two point-in-time drills and
five consecutive nightly parity runs. The last two nightly runs must use the
exact frozen release. The checker also requires proof that the application
enforces the first-release MySQL writer serialization policy. The offline
backup-health adapter derives the seven-day elapsed metrics from independently
reviewed observations, including unverified recovery points.

Use the [cutover and rollback runbook](mysql-production-cutover.md). Its tools
produce verified artifacts and deliberately never switch a live application.
Reopening writes and eventual post-write rollback each require their own
controlled validation; the old frozen SQLite file cannot retain later MySQL
activity.
