<?php

namespace App\Console\Commands;

use App\Models\GoogleCalendarConnection;
use App\Models\GoogleCalendarEventMapping;
use App\Services\GoogleCalendar\GoogleCalendarShootSyncService;
use App\Services\GoogleCalendar\GoogleCalendarSyncDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Re-push every connected photographer's shoots through the current sync rules
 * (one Google Calendar event per shoot). Obsolete per-service mappings are
 * deleted from Google during syncShoot().
 */
class ResyncGoogleCalendarCommand extends Command
{
    protected $signature = 'google-calendar:resync
                            {--user= : Limit to a single users.id with a Google Calendar connection}
                            {--sync : Run inline instead of queueing ResyncGoogleCalendarForUserJob}
                            {--dry-run : Show connection/mapping counts without dispatching}';

    protected $description = 'Resync Google Calendar for connected photographers (collapses legacy multi-service events)';

    public function handle(
        GoogleCalendarSyncDispatcher $dispatcher,
        GoogleCalendarShootSyncService $syncService
    ): int {
        $query = GoogleCalendarConnection::query()->where('sync_enabled', true);
        if ($this->option('user') !== null && $this->option('user') !== '') {
            $query->where('user_id', (int) $this->option('user'));
        }

        $connections = $query->orderBy('user_id')->get(['id', 'user_id', 'calendar_id', 'last_synced_at', 'last_error']);
        if ($connections->isEmpty()) {
            $this->warn('No enabled Google Calendar connections matched.');
            return self::SUCCESS;
        }

        $multi = GoogleCalendarEventMapping::query()
            ->select('shoot_id', DB::raw('COUNT(*) as c'))
            ->groupBy('shoot_id')
            ->having('c', '>', 1)
            ->count();
        $serviceLevel = GoogleCalendarEventMapping::query()->whereNotNull('shoot_service_id')->count();
        $totalMappings = GoogleCalendarEventMapping::query()->count();

        $this->info('Enabled connections: '.$connections->count());
        $this->line("Mappings total={$totalMappings} multi_mapping_shoots={$multi} with_shoot_service_id={$serviceLevel}");

        if ($this->option('dry-run')) {
            foreach ($connections as $connection) {
                $this->line("user_id={$connection->user_id} calendar={$connection->calendar_id} last_synced_at={$connection->last_synced_at}");
            }
            $this->comment('Dry run only — nothing dispatched.');
            return self::SUCCESS;
        }

        foreach ($connections as $connection) {
            $userId = (int) $connection->user_id;
            if ($this->option('sync')) {
                $this->line("Syncing user_id={$userId} inline...");
                $syncService->resyncUser($userId);
            } else {
                $dispatcher->dispatchUserResync($userId);
                $this->line("Queued resync for user_id={$userId}");
            }
        }

        $this->info($this->option('sync') ? 'Inline resync finished.' : 'Resync jobs queued on the default queue.');

        return self::SUCCESS;
    }
}
