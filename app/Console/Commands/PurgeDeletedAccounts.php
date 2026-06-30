<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AccountStatusService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Account lifecycle stage 3: permanently purge/anonymize deleted accounts once their
 * 14-day restore window has elapsed.
 *
 * Runs daily (see Console\Kernel). For each eligible soft-deleted user it delegates to
 * AccountStatusService::purge(), which anonymizes the surviving row ("Deleted User"),
 * scrubs personal fields/metadata, and deletes personal child data while preserving all
 * business/audit history. The user row is NEVER force-deleted (FK cascade safety).
 *
 * Eligibility:
 *   - onlyTrashed() AND restore_until <= now()
 *   - OR (data-anomaly fallback) onlyTrashed() with no restore_until but deleted_at older
 *     than the restore window.
 * Already-purged rows (metadata.purged_at set) are skipped by the service.
 */
class PurgeDeletedAccounts extends Command
{
    protected $signature = 'users:purge-deleted {--dry-run : List what would be purged without writing}';

    protected $description = 'Anonymize/purge soft-deleted accounts whose 14-day restore window has expired.';

    public function handle(AccountStatusService $accountStatus): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $now = now();
        $windowDays = AccountStatusService::RESTORE_WINDOW_DAYS;

        $candidates = User::onlyTrashed()->get()->filter(function (User $user) use ($now, $windowDays) {
            $metadata = is_array($user->metadata) ? $user->metadata : [];
            if (!empty($metadata['purged_at'])) {
                return false; // already purged
            }

            if ($user->restore_until !== null) {
                return $now->greaterThanOrEqualTo($user->restore_until);
            }

            // Fallback: no restore_until — purge if deleted_at is older than the window.
            if ($user->deleted_at !== null) {
                return $now->greaterThanOrEqualTo(
                    Carbon::parse($user->deleted_at)->addDays($windowDays)
                );
            }

            // No restore_until and no deleted_at: leave it for manual review.
            return false;
        });

        $this->info("Found {$candidates->count()} account(s) eligible for purge.");

        $purged = 0;
        foreach ($candidates as $user) {
            $until = $user->restore_until ? $user->restore_until->toDateTimeString() : '(none)';
            $this->line(sprintf('  [#%d] deleted_at=%s restore_until=%s', $user->id, (string) $user->deleted_at, $until));

            if (!$dryRun) {
                try {
                    $accountStatus->purge($user);
                    $purged++;
                } catch (\Throwable $e) {
                    $this->error(sprintf('  Failed to purge #%d: %s', $user->id, $e->getMessage()));
                }
            }
        }

        $this->newLine();
        $this->info(($dryRun ? '[dry-run] ' : '') . "Purged: {$purged}");

        return self::SUCCESS;
    }
}
