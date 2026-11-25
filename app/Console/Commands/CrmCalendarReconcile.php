<?php

namespace App\Console\Commands;

use App\Models\GoogleCalendarConnection;
use App\Services\Crm\Calendar\CrmCalendarSync;
use App\Services\GoogleCalendar\GoogleCalendarService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Producer replay (P2-08). Before crm:calendar-sync is released after the
 * switch, compare every linked CRM follow-up with its Google event: moved or
 * deleted in Google, a local change still waiting to push (which would
 * overwrite Google), a create Google never confirmed, and changes outside
 * the poll window or limit. Reads only. A connection whose token needs a
 * refresh is reported, not refreshed, unless --allow-token-refresh is given
 * (a refresh saves the new token).
 * Done or missed follow-ups (the sync leaves them in Google on purpose) and
 * links without a Google connection are listed but never acted on by the
 * poller, so they do not block. Exit 0 when nothing else is found, 1 when
 * something needs a decision.
 */
class CrmCalendarReconcile extends Command
{
    /** Listed, but the poller never acts on them, so they need no decision. */
    private const NOT_BLOCKING = ['in_sync', 'closed_locally', 'no_connection'];

    protected $signature = 'crm:calendar-reconcile
        {--dry-run : Required. Report only; nothing in the CRM or Google changes}
        {--allow-token-refresh : Let expired Google tokens refresh (writes google_calendar_connections)}
        {--json : Print the report as JSON}';

    protected $description = 'Dry run: report CRM follow-ups that differ from Google Calendar before the poller is released.';

    public function handle(GoogleCalendarService $google): int
    {
        if (! $this->option('dry-run')) {
            $this->error('Only a dry run exists. Run with --dry-run.');

            return 2;
        }

        $windowStart = now()->subDays(CrmCalendarSync::POLL_PAST_DAYS);
        $windowEnd = now()->addDays(CrmCalendarSync::POLL_FUTURE_DAYS);
        $links = DB::table('crm_event_calendar_links as l')->join('crm_events as e', 'e.id', '=', 'l.event_id')
            ->where(fn ($q) => $q->whereNotNull('l.google_event_id')->orWhere('l.push_pending', true))->orderBy('l.id')
            ->get(['l.id as link_id', 'l.user_id', 'l.calendar_id', 'l.google_event_id', 'l.push_pending',
                'e.id as event_id', 'e.status', 'e.starts_at', 'e.duration_min']);
        $connections = [];
        $rows = [];
        foreach ($links as $link) {
            $connection = $connections[$link->user_id] ??= GoogleCalendarConnection::query()
                ->where('user_id', $link->user_id)->where('sync_enabled', true)->first() ?? false;
            $startsAt = Carbon::parse($link->starts_at)->utc();
            [$category, $remote] = $this->compare($google, $connection, $link, $startsAt);
            $rows[] = ['event_id' => (int) $link->event_id, 'link_id' => (int) $link->link_id, 'user_id' => (int) $link->user_id,
                'category' => $category, 'local_status' => $link->status, 'local_starts_at' => $startsAt->toIso8601String(),
                'local_duration_min' => (int) $link->duration_min,
                'in_poll_window' => $link->status === 'planned' && $startsAt->betweenIncluded($windowStart, $windowEnd)] + $remote;
        }
        $counts = array_count_values(array_column($rows, 'category'));
        ksort($counts);
        $report = ['window' => [$windowStart->toIso8601String(), $windowEnd->toIso8601String()], 'counts' => $counts, 'rows' => $rows];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($rows as $row) {
                if ($row['category'] !== 'in_sync') {
                    $this->line(sprintf('event %d (link %d, user %d): %s%s', $row['event_id'], $row['link_id'], $row['user_id'],
                        $row['category'], $row['in_poll_window'] ? '' : ' [outside poll window]'));
                }
            }
            foreach ($counts as $category => $count) {
                $this->line(str_pad($category, 24).$count);
            }
        }

        return array_diff(array_keys($counts), self::NOT_BLOCKING) === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{0: string, 1: array<string, mixed>} the category and what Google holds */
    private function compare(GoogleCalendarService $google, GoogleCalendarConnection|false $connection, object $link, Carbon $startsAt): array
    {
        $none = ['remote_starts_at' => null, 'remote_duration_min' => null, 'remote_differs' => null];
        if (in_array($link->status, ['done', 'missed'], true)) {
            return ['closed_locally', $none]; // push() keeps these in Google and the poll skips them
        }
        if (! $connection) {
            return ['no_connection', $none];
        }
        if ($link->google_event_id === null) {
            // A pending push with no Google id: an earlier create may have reached Google
            // with its response lost, and the release would patch that event (409 path).
            return ['unconfirmed_create', $none];
        }
        if (! $this->option('allow-token-refresh') && $connection->token_expires_at && $connection->token_expires_at->lt(now()->addMinutes(2))) {
            return ['token_refresh_needed', $none];
        }
        try {
            $remote = $google->getEvent($connection, (string) $link->google_event_id, (string) $link->calendar_id);
        } catch (\Throwable $e) {
            return ['error', $none + ['error' => class_basename($e)]];
        }
        $start = data_get($remote, 'start.dateTime');
        $end = data_get($remote, 'end.dateTime');
        $remoteStart = $start ? Carbon::parse($start)->utc() : null;
        $minutes = $remoteStart && $end ? max(5, (int) round($remoteStart->diffInMinutes(Carbon::parse($end)))) : null;
        $differs = $remote === null || ! $remoteStart || ! $remoteStart->equalTo($startsAt) || $minutes !== (int) $link->duration_min;
        $fields = ['remote_starts_at' => $remoteStart?->toIso8601String(), 'remote_duration_min' => $minutes, 'remote_differs' => $differs];

        return [match (true) {
            (bool) $link->push_pending => 'push_pending',
            $link->status !== 'planned' => $remote === null ? 'in_sync' : 'cancelled_still_in_google',
            $remote === null => 'deleted_in_google',
            ! $remoteStart => 'remote_without_time',
            $differs => 'moved_in_google',
            default => 'in_sync',
        }, $fields];
    }
}
