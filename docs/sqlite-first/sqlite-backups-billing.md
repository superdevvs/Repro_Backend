# SQLite backups and the billing gap check

Operator note for Phase 0 items 1, 3 and 8 of the SQLite to MySQL plan.
Production stays on SQLite. A deploy of this code installs nothing and turns
nothing on. Each step marked "needs approval" is a production change.

## 1. Nightly copy to the second disk (item 1)

`scripts/backup/sqlite-nightly-copy.php` makes a compact copy of the live
database every night.

How it works:

- It runs `VACUUM INTO` through a read-only connection. In WAL mode readers
  never block writers, so the app keeps writing during the copy.
- It refuses a database that is not in WAL mode, because then the copy would
  block writers for its whole run.
- Each copy passes `PRAGMA quick_check` before it gets its final name,
  `database-YYYYMMDD-HHMMSS.sqlite`, and then a `.sha256` file that
  `sha256sum -c` understands.
- It keeps the newest 7 copies (`--keep=N`). Old copies are removed only after
  a good new copy, so a failing night never lowers the number of good copies.
  Only a copy with its `.sha256` file counts. A run stopped between the two
  leaves a copy without one; it is not counted and is removed once it is older
  than the kept copies.
- Every run writes `last-run.json` in the copy folder (`status`, `finished_at`,
  `file`, `bytes`, `sha256`). Use it for the backup-age alert. The file is 0600
  inside the 0700 folder, so only `maverick` can read it: the backup-age check
  (item 11) must run as `maverick`, not as `www-data`.

It refuses to run when:

- the copy folder is on the same disk as the live file (protects against an
  unmounted `/mnt/16tb`);
- the process group differs from the database file's group (new `-wal`/`-shm`
  files must stay writable by PHP-FPM, so run it as `www-data` group);
- another copy is still running (exit code 75);
- PHP has no `posix` extension, so the group check cannot run;
- there is not enough free space (live file + WAL + 2 GiB).

While a copy runs, the WAL file can grow, because the copy holds one snapshot.
It shrinks at the next checkpoint.

Each copy holds the whole database, including personal data and stored
provider tokens. Keep the folder at mode 0700. The app encryption key is not in
the copy; it belongs in the recovery kit (item 1c).

### Install (needs approval, item 1b)

As `maverick` on the server:

1. Check free space: `df -h /mnt/16tb`. Each copy is about the size of the live
   file minus its free pages (roughly 3.7 to 4.5 GB). Check the database file's
   group with `stat -c '%G' /var/www/backend/database/database.sqlite`; the
   templates assume `www-data`. Check the copy disk supports hard links:
   `stat -f -c %T /mnt/16tb` should print `ext2/ext3` (ext4), `xfs` or
   `btrfs`. The copy publishes with `link()`, so on exFAT or vfat every run
   fails closed. Deploy backup compression also uses `link()`; run the same
   check on `/home/maverick/repro-deploy-backups`.
2. `mkdir -m 0700 /mnt/16tb/repro-db-backups`
3. Run one copy by hand:

   ```sh
   sg www-data -c "php /var/www/backend/scripts/backup/sqlite-nightly-copy.php \
     /var/www/backend/database/database.sqlite /mnt/16tb/repro-db-backups --keep=7"
   ```

4. Prove a restore on a scratch folder, never inside `/var/www`:

   ```sh
   mkdir -m 0700 -p /mnt/16tb/repro-restore-scratch
   php /var/www/backend/scripts/backup/sqlite-restore-proof.php \
     /mnt/16tb/repro-db-backups/database-YYYYMMDD-HHMMSS.sqlite /mnt/16tb/repro-restore-scratch
   ```

   It checks the checksum, restores to a new file, runs a full
   `integrity_check`, takes and releases a write lock, counts every table, then
   deletes the restored file (add `--keep-restored` to keep it). It prints JSON;
   `foreign_key_issues` is reported, not failed, because production keeps
   reviewed historical exceptions.
5. Schedule it with one of these, never both:
   - the cron line in `scripts/backup/sqlite-nightly-copy.cron` (add it to the
     `maverick` crontab), or
   - the systemd service and timer in `scripts/backup/systemd/` (needs root).

   Both run at 01:40 server time with low CPU and disk priority.

## 2. Deploy backups (item 3a)

`scripts/deploy/deployment-storage.py` changes in two ways.

Always on (no behaviour change on today's SQLite bundles):

- Retention now recognises `database.sqlite.gz`, MySQL `database.sql.gz` and
  its `database-backup.json` manifest. Such bundles age out like any other
  instead of being kept forever.
- The deploy preflight reads the live engine from the cached Laravel config
  (`bootstrap/cache/config.php`), or `DB_CONNECTION` in `.env` when there is no
  cache. On MySQL it no longer looks at `database/database.sqlite` and estimates
  the next dump from the previous `database.sql.gz`. Unknown engines stop the
  deploy. It prints `deploy-database-engine=...`. It reads the cache with a
  bare `php`, without the autoloader, so a config value cached as an object or
  enum would stop every backend deploy here.
  `tests/Feature/CachedConfigReadableWithoutAutoloadTest.php` fails in CI first.

Off until switched on, in `/home/maverick/.repro-deploy-retention/settings.json`:

```json
{"compress_sqlite_backups": false, "tiered_retention": false}
```

No file means both are off. The file must be a regular file owned by
`maverick`, not a link. Unknown keys or values that are not `true`/`false` stop
retention and compression with an error, and nothing is deleted.

- `compress_sqlite_backups`: after each deploy, the newest backend bundle's
  `database.sqlite` becomes `database.sqlite.gz`. The gzip is decompressed and
  compared by sha256 before the raw copy is removed. It is skipped when free
  space is below the raw size plus the 20 GiB reserve. It runs after the site is
  back up, holds the backend deploy lock, and the deploy script waits for it.
  It uses gzip level 1 on one core. On a synthetic SQLite file on the
  development PC that ran at about 58 MB/s and kept 54% of the size (level 6:
  25 MB/s, 48%), which suggests about two minutes for a 4.5 GB file including
  the check. Real data has zeroed free pages and should shrink more; time the
  first real run. A failure prints a warning, keeps the raw copy, and the deploy
  still counts as done. Older bundles are not compressed; they age out. If a
  run is killed (reboot, out of memory), the next compress step or applied
  retention (both hold the deploy lock) removes its
  `.database.sqlite.gz.partial` file, so retention can still age the bundle
  out, also after the flag is switched off. Removing the file touches the
  bundle folder, so an old bundle is then kept 14 more days before it goes.
- `tiered_retention`: keep the newest 5 bundles, plus one per day for 14 days
  (that day's newest verified bundle, else its newest that holds a database,
  else its newest). These are still kept as
  before: the newest bundle, the newest verified bundle, the newest bundle that
  holds a database, future labels, and old labels touched in the last 14 days.
  Backend bundles with no database file become removable too.

Retention runs after every deploy and daily at 03:47, so switching on
`tiered_retention` deletes bundles at the next run. That is item 3b and needs
approval. Preview it first (read-only):

```sh
python3 /var/www/backend/scripts/deploy/deployment-storage.py retention --policy tiered
```

To restore a compressed copy: `gzip -dc database.sqlite.gz > /scratch/restored.sqlite`
(`gzip -t` checks it without writing).

## 3. Weekly billing gap check (item 8)

```sh
php artisan billing:weekly-gap-dry-run                # last 8 completed weeks
php artisan billing:weekly-gap-dry-run --periods=12 --through=2026-11-23 --json
```

Dates are stored in two shapes: `2026-10-05` and `2026-10-05 00:00:00`. Some
weekly runs compare them as text, and a bare date sorts before
`2026-10-05 00:00:00`. The command replays each selection over Sunday to
Saturday weeks and compares today's exact comparison with a date-correct one
(the first ten characters as a calendar date).

Selections checked:

| Selection | Run it copies | What the text comparison misses |
| --- | --- | --- |
| `sales_rep_invoices` | Weekly sales-rep invoices (commission per shoot) | First-day shoots stored as a bare date |
| `sales_rep_payout_report` | Weekly sales-rep payout report | Same |
| `photographer_compensations` | Weekly photographer payouts, complimentary-reshoot pay | First-day lines whose `earned_at` is a bare date (the app normally stores a time) |
| `sales_rep_compensations` | Weekly sales-rep invoices, complimentary-reshoot compensation lines | Same |
| `sales_rep_payout_report_compensations` | Weekly sales-rep payout report, compensation total (pay on any shoot except internal tests) | Same |
| `complimentary_reshoot_report` | Complimentary-reshoot summary in the payout report | Last-day shoots stored with a time |

Photographer pay for normal shoots is selected with `whereDate`, which is
already date-correct on SQLite, so it is not listed.

The sales-rep selections count only records the run would pay: a shoot with
no rep goes to its client's rep (read, not saved), and a recipient who is not
an active sales rep is left out, as the runs do.

Reading the output: "Picked now" is what the run selects today; "Date-correct"
is what it should select. Each skipped record lists the stored value, the
recipient, and any invoice lines that already cover it. "none" means no
invoice line covers it, so it may never have been billed or paid. Report rows
show "n/a".

The totals count records per selection, so a shoot skipped by both the invoice
run and the payout report counts twice in `skipped` and `unbilled`.
`distinct_skipped` and `distinct_unbilled` count each shoot or pay line once.

The command only reads. On SQLite it also sets `PRAGMA query_only` for its own
connection while it runs, so any write would fail. It changes nothing in the
generators. Whether to fix the comparison and whether to issue catch-up
invoices or payouts are owner decisions (item 8b).

To run it on the private snapshot, use a network-disabled copy of the app
pointed at a copy of the snapshot, and share only counts and record IDs.

On MySQL the DATE and DATETIME columns store one shape, so the dry run finds
no gap there. The tests that build the two text shapes run only on SQLite; a
mid-week test checks the selections against the real sales-rep run on both
engines.

## Flags and decisions

| Flag or setting | Default | Where |
| --- | --- | --- |
| `compress_sqlite_backups` | off | `/home/maverick/.repro-deploy-retention/settings.json` |
| `tiered_retention` | off | same file |
| Nightly copies kept (`--keep`) | 7 | cron line or systemd unit |
| Nightly copy time | 01:40 server time | cron line or systemd timer |

Owner decisions: when to switch on each flag (switching on `tiered_retention`
deletes old bundles), how many nightly copies to keep, whether to fix the text
date comparison in the weekly runs, and whether to issue catch-up invoices or
payouts for anything the dry run lists.

## Codex review round (25 Nov)

Codex reviewed `60995685`; fixes are on top of `ab30dbca`.

- `sqlite-backups-billing#1` (copy without `.sha256` counted toward `--keep`): fixed in `2aa5563f`; only complete pairs count.
- `sqlite-backups-billing#2` (sales-rep pay on standard shoots listed as a gap): fixed in `2a84cae0`; on MySQL too in `ba005bd4`.
- `sqlite-backups-billing#3` (stale partial gzip kept forever): the compress step already cleared it (`acde9e27`); applied retention now clears it too, so switching the flag off after a crash no longer strands the bundle (`accc51e9`).
- `sqlite-backups-billing#4` (Linux-only copy tests fail on Windows): fixed in `b0478f44`; they skip without Linux and posix, and CI's `--fail-on-skipped` still catches a missing posix.
- Reviewer 1 (full suite at `0ac446c9`): checked. 9,555 tests; the only failures are the known environment-only ones (email DNS, uid-33 file sharing, live ClamAV, the timing-flaky iGUIDE import retry). The container is gone.
- Reviewer 2 (preflight reads the cached config with bare `php`): works on today's config and fails closed. A CI test now catches a config value that would need the autoloader (`01536cbb`).
- Reviewer 3 (rotation counts a copy whose `.sha256` write crashed; `last-run.json` unreadable by `www-data`): rotation fixed with #1. The backup-age check must run as `maverick` (noted above).
- Reviewer 4 (`link()` fails on filesystems without hard links): install step 1 now checks the filesystem type. To check on the server in item 1b.
- Reviewer 5 (tiered day kept a newer source-only bundle): fixed in `d768a959`. Without a verified bundle, the day keeps its newest bundle that holds a database.
- Reviewer 6: the private-snapshot run (item 8), the production install and apply (items 1b and 3b), and the catch-up decision (item 8b) still need owner approval.
- Found while running on MySQL 8.4.13: five billing tests that build SQLite text dates failed there, already at `ab30dbca`. They now skip off SQLite (`ba005bd4`).
