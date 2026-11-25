<?php

namespace App\Console\Commands;

use App\Support\TelnyxMaintenanceSpool;
use Illuminate\Console\Command;

/**
 * Lists calls whose Telnyx voice or Vapi events arrived while the app was in
 * maintenance mode. These events are kept for staff and never replayed.
 */
class ListTelnyxMissedCalls extends Command
{
    protected $signature = 'telnyx:missed-calls';

    protected $description = 'List calls that reached the app while it was in maintenance mode.';

    public function handle(): int
    {
        $timezone = (string) config('schedule.calendar_timezone', 'America/New_York');
        $calls = [];
        foreach (TelnyxMaintenanceSpool::fromConfig()->entries(TelnyxMaintenanceSpool::CALLS) as $entry) {
            if (! $entry['readable']) {
                continue;
            }
            $payload = json_decode($entry['body'], true) ?: [];
            if ($entry['route'] === 'vapi') {
                $key = 'vapi:'.(data_get($payload, 'message.call.id') ?: $entry['id']);
                $from = data_get($payload, 'message.call.customer.number');
                $to = data_get($payload, 'message.phoneNumber.number') ?? data_get($payload, 'message.call.phoneNumber.number');
                $event = data_get($payload, 'message.type');
            } else {
                $call = (array) data_get($payload, 'data.payload', []);
                $key = 'telnyx:'.($call['call_session_id'] ?? $call['call_control_id'] ?? $entry['id']);
                [$from, $to, $event] = [$call['from'] ?? null, $call['to'] ?? null, data_get($payload, 'data.event_type')];
            }
            $calls[$key] ??= ['first' => $entry['arrived_at'], 'provider' => $entry['route'] === 'vapi' ? 'Vapi' : 'Telnyx',
                'from' => null, 'to' => null, 'events' => []];
            $calls[$key]['from'] ??= is_string($from) ? $from : null;
            $calls[$key]['to'] ??= is_string($to) ? $to : null;
            if (is_string($event) && ! in_array($event, $calls[$key]['events'], true)) {
                $calls[$key]['events'][] = $event;
            }
        }

        $this->info(count($calls).' call(s) reached the app during maintenance. They were not answered by the app and are never replayed.');
        if ($calls !== []) {
            $this->table(["First seen ({$timezone})", 'Provider', 'From', 'To', 'Events'], array_map(fn (array $call) => [
                $call['first']->setTimezone($timezone)->format('Y-m-d H:i:s'), $call['provider'],
                $call['from'] ?? '-', $call['to'] ?? '-', implode(', ', $call['events']),
            ], array_values($calls)));
        }

        return self::SUCCESS;
    }
}
