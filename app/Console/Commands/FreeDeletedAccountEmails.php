<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Free the email/username held by already soft-deleted accounts so the same address
 * can be reused for a new account. Mirrors what AccountStatusService::softDelete now
 * does for new deletions; this backfills accounts deleted before that change.
 *
 * Idempotent: rows already tombstoned (email ends with @deleted.invalid, or the
 * original is already stashed in metadata) are skipped.
 */
class FreeDeletedAccountEmails extends Command
{
    protected $signature = 'users:free-deleted-emails {--dry-run : List what would change without writing}';

    protected $description = 'Tombstone emails/usernames of already soft-deleted users so the addresses can be reused.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $deleted = User::onlyTrashed()->get();
        $this->info("Found {$deleted->count()} soft-deleted user(s).");

        $changed = 0;
        $skipped = 0;
        foreach ($deleted as $user) {
            $email = (string) $user->email;
            $alreadyTombstoned = str_ends_with($email, '@deleted.invalid')
                || (is_array($user->metadata) && !empty($user->metadata['deleted_original_email']));

            if ($alreadyTombstoned || $email === '') {
                $skipped++;
                continue;
            }

            $metadata = is_array($user->metadata) ? $user->metadata : [];
            $tombstone = 'deleted_' . $user->id . '_' . now()->timestamp;

            $this->line(sprintf('  [#%d] %s -> %s@deleted.invalid', $user->id, $email, $tombstone));

            if (!$dryRun) {
                $metadata['deleted_original_email'] = $email;
                $updates = ['email' => $tombstone . '@deleted.invalid', 'metadata' => $metadata];

                if (!empty($user->username)) {
                    $metadata['deleted_original_username'] = $user->username;
                    $updates['username'] = $tombstone;
                    $updates['metadata'] = $metadata;
                }

                $user->forceFill($updates)->saveQuietly();
            }
            $changed++;
        }

        $this->newLine();
        $this->info(($dryRun ? '[dry-run] ' : '') . "Freed: {$changed}, skipped (already free): {$skipped}");

        return self::SUCCESS;
    }
}
