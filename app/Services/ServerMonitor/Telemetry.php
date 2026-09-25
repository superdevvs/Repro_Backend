<?php

namespace App\Services\ServerMonitor;

/** Bounded, best-effort local telemetry. No HTTP calls or database writes. */
class Telemetry
{
    public function emit(string $kind, array $fields = []): void
    {
        if (!config('server-monitor.enabled')) return;
        try {
            $payload = json_encode(['version' => 1, 'kind' => $kind, 'timestamp' => now()->utc()->toIso8601ZuluString()] + $fields, JSON_THROW_ON_ERROR)."\n";
            if (strlen($payload) > 32768) return;
            // The socket is local. Connection wait is capped at 2 ms; writes never block.
            $socket = @stream_socket_client('unix://'.config('server-monitor.socket'), $errno, $error, 0.002, STREAM_CLIENT_CONNECT);
            if ($socket === false) return;
            stream_set_blocking($socket, false);
            @fwrite($socket, $payload);
            fclose($socket);
        } catch (\Throwable) {
            // Observability must not affect the monitored operation.
        }
    }

    public function scheduleId(\Illuminate\Console\Scheduling\Event $event): string
    {
        return substr(hash('sha256', $event->expression.'|'.$event->command.'|'.$event->description.'|'.$event->timezone), 0, 24);
    }

    public function catalog(): void
    {
        if (!config('server-monitor.enabled')) return;
        try {
            $rows = [];
            foreach (app(\Illuminate\Console\Scheduling\Schedule::class)->events() as $event) {
                $command = $event->command ?? $event->description ?? 'callback';
                preg_match('/artisan[\x27\x22]?\s+([a-zA-Z0-9:_-]+)/', $command, $match);
                $rows[] = ['id' => $this->scheduleId($event), 'name' => $match[1] ?? class_basename($event),
                    'source' => 'laravel', 'expression' => $event->expression, 'timezone' => (string) ($event->timezone ?: config('app.timezone')),
                    'nextRun' => $event->nextRunDate()->utc()->toIso8601ZuluString(), 'lastRun' => null, 'outcome' => 'unknown', 'durationMs' => null];
            }
            $this->emit('catalog', ['schedules' => array_slice($rows, 0, 100)]);
        } catch (\Throwable) {
        }
    }
}
