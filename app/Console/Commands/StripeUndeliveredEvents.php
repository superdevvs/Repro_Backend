<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Stripe\StripeClient;

/**
 * Producer replay (P2-10), read-only. After the freeze, list Stripe events
 * from the window that still have webhook deliveries pending, so the lead
 * can check at +30 minutes and +2 hours. Uses a restricted read-only key.
 * Exit 0 when nothing is pending, 1 when something is, 2 on bad input.
 */
class StripeUndeliveredEvents extends Command
{
    protected $signature = 'stripe:undelivered-events
        {--since= : Start of the window (ISO 8601), usually the freeze start}
        {--until= : End of the window (ISO 8601), default now}
        {--account=dashboard : dashboard or listing-studio}
        {--json : Print the report as JSON}';

    protected $description = 'Read-only: list Stripe events in a window whose webhook deliveries are still pending.';

    public function handle(): int
    {
        $account = (string) $this->option('account');
        $key = trim((string) config('producer_replay.stripe_events_read_keys.'.$account));
        if ($key === '') {
            $this->error('Set the restricted Events read key for "'.$account.'" first (STRIPE_EVENTS_READ_KEY or LISTING_STUDIO_STRIPE_EVENTS_READ_KEY).');

            return 2;
        }
        try {
            $since = Carbon::parse((string) $this->option('since'));
            $until = $this->option('until') ? Carbon::parse((string) $this->option('until')) : now();
        } catch (\Throwable) {
            $since = null;
        }
        if (! $this->option('since') || ! $since || $until->lt($since)) {
            $this->error('Give --since (and optionally --until) as ISO 8601 times, --since first.');

            return 2;
        }

        $client = new StripeClient(['api_key' => $key, 'max_network_retries' => 2]);
        $events = $client->events->all([
            'created' => ['gte' => $since->getTimestamp(), 'lte' => $until->getTimestamp()],
            'delivery_success' => false,
            'limit' => 100,
        ]);
        $pending = [];
        foreach ($events->autoPagingIterator() as $event) {
            if ((int) ($event->pending_webhooks ?? 0) > 0) {
                $pending[] = ['id' => $event->id, 'type' => $event->type,
                    'created' => Carbon::createFromTimestamp($event->created)->utc()->toIso8601String(),
                    'pending_webhooks' => (int) $event->pending_webhooks];
            }
        }
        $report = ['account' => $account, 'since' => $since->utc()->toIso8601String(), 'until' => $until->utc()->toIso8601String(),
            'pending_count' => count($pending), 'pending' => $pending];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($pending as $event) {
                $this->line($event['created'].'  '.$event['id'].'  '.$event['type'].'  pending='.$event['pending_webhooks']);
            }
            $this->line(count($pending).' event(s) with pending deliveries.');
        }

        return $pending ? self::FAILURE : self::SUCCESS;
    }
}
