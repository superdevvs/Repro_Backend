# Service 55 range boundary correction — prepared, not applied

Audit finding: service 55 (HDR Photos, Video & 3D Matterport) has overlapping
inclusive ranges: range 153 is 1–1500 at $500, and range 154 is 1500–3000 at $575.
Only range 154's lower bound should change from 1500 to 1501.

**Owner:** the production deployment owner, coordinated with the marketing release.
**Gate:** explicit production correction authorization, a verified SQLite backup,
and a fresh successful dry run. This is separate from application deployment and
is not a migration. Until applied, marketing must block an ambiguous 1500 sqft
selection for this service rather than choose one price silently.

Prepared script: `scripts/ops/correct-service-55-sqft-boundary.php`.
Supply the exact verified production database path; the script does not infer it.

```sh
# Read only: checks the exact range identity, bounds and price.
php scripts/ops/correct-service-55-sqft-boundary.php --database=/absolute/path/database.sqlite

# Only after authorization and backup verification:
php scripts/ops/correct-service-55-sqft-boundary.php --database=/absolute/path/database.sqlite --apply --confirm=service-55-range-154-1500-to-1501 --backup=/absolute/path/verified-backup.sqlite
```

Apply holds a SQLite write lock, rechecks the row and updates only `sqft_from`.
It leaves prices, other ranges, timestamps, payout fields and bookings unchanged.
It sends no notifications. Any unexpected service, upper bound or price refuses
the operation. A repeat after correction is a successful no-op. After applying,
repeat the dry run and verify the public sanitized catalog has range 154 starting
at 1501, with 1500 selecting the $500 range and 1501 selecting $575.

No live execution or mutation was performed when preparing this artifact.
