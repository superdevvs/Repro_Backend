<?php

namespace App\Console\Commands;

use App\Models\Shoot;
use App\Models\User;
use App\Services\GoogleCalendar\GoogleCalendarSyncDispatcher;
use App\Services\Shoots\ShootMediaMutationSupportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Clears out shoots belonging to everyone except a named client, for resetting a
 * pre-launch environment between rounds of testing.
 *
 * Deliberately does NOT go through {@see \App\Services\Shoots\Actions\DeleteShootAction}.
 * That action emails the client a "shoot removed" notice and fires the
 * SHOOT_REMOVED automation, which is correct for a single deliberate deletion by
 * an admin but wrong for a bulk reset — it would mail every address in the purge
 * set. This command reproduces the parts of that action that clean up after a
 * shoot (media on disk, the Google Calendar event, cache invalidation via the
 * model's deleted hook) and skips the parts that talk to people.
 *
 * `shoots` has no soft deletes, so every removal here is permanent. Take a
 * database backup before running without --dry-run.
 */
class PurgeShootsExceptClient extends Command
{
    protected $signature = 'shoots:purge-except-client
                            {--client=House Account : Client name (exact, case-insensitive) whose shoots are kept}
                            {--client-id=* : Client user id(s) to keep, overrides --client when given}
                            {--dry-run : List what would be deleted and exit}
                            {--keep-media : Leave uploaded files on disk instead of deleting them}
                            {--allow-empty-keep : Permit running when the keep set matches no client}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Delete every shoot except those belonging to the given client, without notifying anyone';

    public function handle(
        ShootMediaMutationSupportService $mediaService,
        GoogleCalendarSyncDispatcher $calendarDispatcher
    ): int {
        $keepIds = $this->resolveKeepClientIds();

        if ($keepIds === [] && ! $this->option('allow-empty-keep')) {
            $this->error('No client matched, which would delete every shoot in the database.');
            $this->line('Pass --allow-empty-keep if that is genuinely what you want.');

            return self::FAILURE;
        }

        $this->reportKeepSet($keepIds);

        $doomed = Shoot::query()
            ->when($keepIds !== [], fn ($q) => $q->whereNotIn('client_id', $keepIds)->orWhereNull('client_id'))
            ->with('client:id,name,email')
            ->orderBy('id')
            ->get();

        if ($doomed->isEmpty()) {
            $this->info('Nothing to delete: every shoot already belongs to the kept client.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line("Shoots to delete: {$doomed->count()}");
        $this->table(
            ['id', 'client', 'status', 'scheduled'],
            $doomed->map(fn (Shoot $s) => [
                $s->id,
                $s->client?->name ?? '(none)',
                $s->status,
                $s->scheduled_date?->toDateString() ?? '-',
            ])->all()
        );

        $ids = $doomed->pluck('id')->all();
        $this->reportCollateral($ids);

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->info('Dry run: nothing was deleted.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn('This is permanent. `shoots` has no soft deletes and cascades reach 27 child tables.');
        if (! $this->option('force') && ! $this->confirm("Delete {$doomed->count()} shoots?", false)) {
            $this->warn('Cancelled, nothing was deleted.');

            return self::INVALID;
        }

        $deleteMedia = ! $this->option('keep-media');
        $deletedShoots = 0;
        $deletedFiles = 0;
        $failures = [];

        foreach ($doomed as $shoot) {
            $shootId = $shoot->id;
            try {
                if ($deleteMedia) {
                    $deletedFiles += $mediaService->deleteShootMediaAssets($shoot);
                }

                // Hard delete. Fires the model's deleted hook, which flushes the
                // dashboard/listing caches, and the FK cascades.
                $shoot->delete();
                $deletedShoots++;

                // Id-based and post-delete, matching DeleteShootAction, so the
                // external calendar entry does not outlive the shoot.
                $calendarDispatcher->dispatchShootRemoval($shootId);
            } catch (Throwable $e) {
                $failures[$shootId] = $e->getMessage();
                $this->error("shoot {$shootId}: {$e->getMessage()}");
            }
        }

        $orphanedMessages = $this->clearDanglingMessageReferences();

        $this->newLine();
        $this->info("Deleted {$deletedShoots} shoot(s).");
        $this->line("Media files removed: {$deletedFiles}".($deleteMedia ? '' : ' (--keep-media)'));
        if ($orphanedMessages > 0) {
            $this->line("Message rows whose related_shoot_id was cleared: {$orphanedMessages}");
        }
        $this->line('Remaining shoots: '.Shoot::count());

        if ($failures !== []) {
            $this->newLine();
            $this->error('Failed on '.count($failures).' shoot(s): '.implode(', ', array_keys($failures)));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, int>
     */
    protected function resolveKeepClientIds(): array
    {
        $explicit = array_values(array_filter(array_map('intval', (array) $this->option('client-id'))));
        if ($explicit !== []) {
            return $explicit;
        }

        $name = trim((string) $this->option('client'));
        if ($name === '') {
            return [];
        }

        return User::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  array<int, int>  $keepIds
     */
    protected function reportKeepSet(array $keepIds): void
    {
        if ($keepIds === []) {
            $this->warn('Keeping: nothing (every shoot is in scope).');

            return;
        }

        $kept = User::query()->whereIn('id', $keepIds)->get(['id', 'name', 'email']);
        $this->info('Keeping shoots for:');
        foreach ($kept as $user) {
            $count = Shoot::where('client_id', $user->id)->count();
            $this->line("  id={$user->id} {$user->name} <{$user->email}> — {$count} shoot(s)");
        }
    }

    /**
     * Shows what disappears alongside the shoots, so the operator sees the blast
     * radius before confirming rather than discovering it afterwards.
     *
     * @param  array<int, int>  $ids
     */
    protected function reportCollateral(array $ids): void
    {
        $cascade = [
            'shoot_service' => 'shoot_id',
            'shoot_files' => 'shoot_id',
            'payments' => 'shoot_id',
            'invoice_shoot' => 'shoot_id',
            'shoot_share_links' => 'shoot_id',
            'public_payment_access_tokens' => 'shoot_id',
            'tour_events' => 'shoot_id',
            'shoot_activity_logs' => 'shoot_id',
            'google_calendar_event_mappings' => 'shoot_id',
        ];
        $nulled = [
            'invoices' => 'shoot_id',
            'editing_requests' => 'shoot_id',
            'projects' => 'shoot_id',
            'payment_refunds' => 'shoot_id',
        ];

        $this->newLine();
        $this->line('Rows deleted with them (FK cascade):');
        foreach ($cascade as $table => $column) {
            $count = $this->countReferencing($table, $column, $ids);
            if ($count !== null && $count > 0) {
                $this->line(sprintf('  %-32s %d', $table, $count));
            }
        }

        $this->line('Rows kept but unlinked (shoot_id set to NULL):');
        foreach ($nulled as $table => $column) {
            $count = $this->countReferencing($table, $column, $ids);
            if ($count !== null && $count > 0) {
                $this->line(sprintf('  %-32s %d', $table, $count));
            }
        }

        if (Schema::hasTable('payments')) {
            $paid = (float) DB::table('payments')
                ->whereIn('shoot_id', $ids)
                ->where('status', 'completed')
                ->sum('amount');
            if ($paid > 0) {
                $this->warn('  completed payments being destroyed: '.number_format($paid, 2));
            }
        }
    }

    protected function countReferencing(string $table, string $column, array $ids): ?int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return null;
        }

        return DB::table($table)->whereIn($column, $ids)->count();
    }

    /**
     * `messages.related_shoot_id` has no foreign key, so nothing cleans it up and
     * the rows would keep pointing at ids that no longer exist.
     */
    protected function clearDanglingMessageReferences(): int
    {
        if (! Schema::hasTable('messages') || ! Schema::hasColumn('messages', 'related_shoot_id')) {
            return 0;
        }

        return DB::table('messages')
            ->whereNotNull('related_shoot_id')
            ->whereNotIn('related_shoot_id', function ($query) {
                $query->select('id')->from('shoots');
            })
            ->update(['related_shoot_id' => null]);
    }
}
