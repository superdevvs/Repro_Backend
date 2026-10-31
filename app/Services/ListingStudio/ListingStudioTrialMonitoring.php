<?php

namespace App\Services\ListingStudio;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;

/** Operational aggregates, separate from anonymous request/payload telemetry. */
class ListingStudioTrialMonitoring
{
    public const EVENTS = [
        'idempotency_replay', 'idempotency_conflict', 'trial_already_processing', 'trial_allowance_used',
        'trial_daily_limit', 'trial_ip_limit', 'trial_admission_limit', 'trial_rate_limit',
        'trial_challenge_failed', 'trial_challenge_unavailable', 'trial_admission_invalid', 'trial_admission_expired',
    ];

    public function count(string $event, ?string $service = null): void
    {
        if (! in_array($event, self::EVENTS, true)) {
            return;
        }
        $service = array_key_exists((string) $service, ListingStudioGuestTrialService::SERVICES) ? $service : 'all';
        $this->bestEffort(fn (PDO $pdo, string $prefix) => $pdo->prepare('INSERT INTO "'.$prefix.'listing_studio_trial_counters" (day, event, service, count) VALUES (?, ?, ?, 1) ON CONFLICT (day, event, service) DO UPDATE SET count = count + 1')
            ->execute([now('UTC')->toDateString(), $event, $service]));
    }

    public function workerStarted(string $trialId): void
    {
        $this->bestEffort(fn (PDO $pdo, string $prefix) => $pdo->prepare('UPDATE "'.$prefix.'listing_studio_guest_trials" SET worker_started_at = ? WHERE id = ? AND worker_started_at IS NULL')
            ->execute([now()->format('Y-m-d H:i:s'), $trialId]));
    }

    /** A successful authorized response was requested, not proof of completed browser delivery. */
    public function downloadRequested(string $trialId): void
    {
        $this->bestEffort(fn (PDO $pdo, string $prefix) => $pdo->prepare('UPDATE "'.$prefix.'listing_studio_guest_trials" SET download_request_count = download_request_count + 1, first_download_requested_at = COALESCE(first_download_requested_at, ?) WHERE id = ?')
            ->execute([now()->format('Y-m-d H:i:s'), $trialId]));
    }

    public function prune(): void
    {
        $this->bestEffort(fn (PDO $pdo, string $prefix) => $pdo->prepare('DELETE FROM "'.$prefix.'listing_studio_trial_counters" WHERE day < ?')
            ->execute([now('UTC')->subDays(89)->toDateString()]));
    }

    private function bestEffort(callable $write): void
    {
        $pdo = null;
        $restoreTimeout = null;
        $primary = null;
        try {
            $primary = DB::connection();
            if ($primary->getDriverName() !== 'sqlite') {
                throw new \RuntimeException('Unsupported telemetry database.');
            }
            $primaryPdo = $primary->getPdo();
            if ($primaryPdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
                throw new \RuntimeException('Unsupported telemetry database.');
            }
            $prefix = $primary->getTablePrefix();
            if (! preg_match('/\A[a-zA-Z0-9_]*\z/', $prefix)) {
                throw new \RuntimeException('Unsupported telemetry table prefix.');
            }
            $main = collect($primaryPdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC))->firstWhere('name', 'main');
            if (($main['file'] ?? null) === '' && $primary->getDatabaseName() === ':memory:') {
                // An isolated PDO cannot see an anonymous in-memory test database.
                // Restore its timeout even on failure; production file connections never change.
                $pdo = $primaryPdo;
                $restoreTimeout = (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn();
            } else {
                $file = $main['file'] ?? '';
                if ($file !== '') { clearstatcache(true, $file); }
                $canonical = $file !== '' ? realpath($file) : false;
                if (! $canonical || ! is_file($canonical) || ! is_readable($canonical) || ! is_writable($canonical)) {
                    throw new \RuntimeException('Unsupported telemetry database file.');
                }
                // mode=rw prevents SQLite creating a new database if the verified file disappears.
                $pdo = new PDO('sqlite:file:'.str_replace('%2F', '/', rawurlencode($canonical)).'?mode=rw', null, null,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 0]);
                $opened = collect($pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC))->firstWhere('name', 'main');
                if (realpath($opened['file'] ?? '') !== $canonical) {
                    throw new \RuntimeException('Telemetry database identity changed.');
                }
            }
            $pdo->exec('PRAGMA busy_timeout = 100');
            $write($pdo, $prefix);
        } catch (\Throwable) {
            // Do not attach exceptions, request context, or identifiers to this message.
            try { Log::error('Listing Studio operational telemetry could not be stored.'); } catch (\Throwable) { /* Logging is best-effort too. */ }
        } finally {
            if ($restoreTimeout !== null && $pdo) {
                try {
                    $pdo->exec('PRAGMA busy_timeout = '.$restoreTimeout);
                } catch (\Throwable) {
                    // Never leave a reduced timeout on a connection reused by business writes.
                    try { $primary?->disconnect(); } catch (\Throwable) { /* The failed connection is unusable. */ }
                }
            }
            $pdo = null;
        }
    }

    /** No writes, reconciliation, media reads, or network calls. */
    public function snapshot(): array
    {
        $now = now();
        $since = $now->copy()->subDay();
        $rows = DB::table('listing_studio_guest_trials as t')->leftJoin('users as u', 'u.id', '=', 't.client_id')
            ->where('t.started_at', '>', $since)
            ->get(['t.id', 't.service', 't.status', 't.started_at', 't.worker_started_at', 't.completed_at', 't.claimed_at',
                't.client_id', 't.first_download_requested_at', 't.download_request_count', 'u.created_at as account_created_at']);
        $services = [];
        foreach (ListingStudioGuestTrialService::SERVICES as $service => $label) {
            $items = $rows->where('service', $service);
            $delays = $items->filter(fn ($row) => $row->worker_started_at)->map(fn ($row) => max(0,
                (int) Carbon::parse($row->started_at)->diffInSeconds(Carbon::parse($row->worker_started_at))))->sort()->values();
            $services[$service] = ['starts' => $items->count(), 'completed' => $items->whereNotNull('completed_at')->count(),
                'statuses' => $items->countBy('status')->all(), 'queue_delay_samples' => $delays->count(),
                'queue_delay_seconds_p95' => $delays->isEmpty() ? null : $delays[(int) ceil($delays->count() * .95) - 1]];
        }
        $live = DB::table('listing_studio_guest_trials')->whereIn('status', ['queued', 'processing', 'needs_attention']);
        $queued = (clone $live)->where('status', 'queued');
        $processing = (clone $live)->where('status', 'processing');
        $oldestQueued = (clone $queued)->min('started_at');
        $active = ['queued' => (clone $queued)->count(), 'processing' => (clone $processing)->count(),
            'needs_attention' => (clone $live)->where('status', 'needs_attention')->count(),
            'queued_over_5_minutes' => (clone $queued)->where('started_at', '<', $now->copy()->subMinutes(5))->count(),
            'processing_over_15_minutes' => (clone $processing)->whereRaw('COALESCE(worker_started_at, started_at) < ?', [$now->copy()->subMinutes(15)])->count(),
            'oldest_queue_seconds' => $oldestQueued ? max(0, (int) Carbon::parse($oldestQueued)->diffInSeconds($now)) : null,
            'finalization_gap' => DB::table('listing_studio_guest_trials as t')->join('studio_workspaces as w', 'w.id', '=', 't.workspace_id')
                ->where('w.status', 'completed')->whereIn('t.status', ['queued', 'processing', 'needs_attention'])->count()];
        $starts = $rows->count();
        $limit = max(0, (int) config('listing-studio-trials.global_daily_limit'));
        $ipLimit = max(0, (int) config('listing-studio-trials.ip_daily_limit'));
        $ipCounts = DB::table('listing_studio_guest_trials')->where('started_at', '>', $since)
            ->groupBy('ip_hash')->selectRaw('COUNT(*) AS total')->pluck('total');
        $quota = ['window' => 'rolling_24_hours', 'starts' => $starts, 'global_limit' => $limit,
            'global_remaining' => max(0, $limit - $starts), 'ip_limit' => $ipLimit,
            'max_starts_from_one_connection' => (int) ($ipCounts->max() ?? 0),
            'connections_at_limit' => $ipCounts->filter(fn ($count) => $count >= $ipLimit)->count()];
        $excluded = config('listing-studio-trials.monitoring_qa_trial_ids', []);
        $conversionRows = $rows->reject(fn ($row) => in_array($row->id, $excluded, true));
        $clientGroups = $conversionRows->whereNotNull('client_id')->groupBy('client_id');
        $newClients = $clientGroups->filter(fn ($items) => $items->first()->account_created_at
            && Carbon::parse($items->first()->account_created_at)->gte(Carbon::parse($items->min('started_at'))));
        $conversion = ['cohort' => 'trials_started_in_rolling_24_hours', 'qa_trials_excluded' => $rows->count() - $conversionRows->count(),
            'starts' => $conversionRows->count(), 'completed' => $conversionRows->whereNotNull('completed_at')->count(),
            'claimed_trials' => $conversionRows->whereNotNull('claimed_at')->count(), 'claimed_clients' => $clientGroups->count(),
            'trials_with_download_request' => $conversionRows->whereNotNull('first_download_requested_at')->count(),
            'download_requests' => $conversionRows->sum('download_request_count'),
            'accounts_created_after_trial_start' => $newClients->count(),
            'new_accounts_with_download_request' => $newClients->filter(fn ($items) => $items->whereNotNull('first_download_requested_at')->isNotEmpty())->count(),
            'note' => 'Account creation timing is an attribution proxy. A download request is not proof of complete file transfer.'];
        $warnings = [];
        foreach (['needs_attention', 'queued_over_5_minutes', 'processing_over_15_minutes', 'finalization_gap'] as $key) {
            if ($active[$key] > 0) { $warnings[] = $key; }
        }
        if ($limit > 0 && $starts >= $limit * .8) { $warnings[] = 'global_quota_at_80_percent'; }

        return ['generated_at' => $now->toIso8601String(), 'since' => $since->toIso8601String(), 'services' => $services,
            'active' => $active, 'quota' => $quota, 'conversion' => $conversion,
            'event_counter_window' => 'current_and_previous_UTC_calendar_day',
            'event_counters' => DB::table('listing_studio_trial_counters')->where('day', '>=', now('UTC')->subDay()->toDateString())
                ->orderBy('day')->orderBy('event')->orderBy('service')->get(['day', 'event', 'service', 'count'])->all(), 'warnings' => $warnings];
    }
}
