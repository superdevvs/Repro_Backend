<?php

namespace App\Console\Commands;

use App\Services\HeartbeatChecks;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

class OpsHeartbeat extends Command
{
    protected $signature = 'ops:heartbeat {--dry-run : Run the checks and print them without pinging}';

    protected $description = 'Check the database, backup age, free disk and oldest waiting job, then ping the alert service';

    public function handle(HeartbeatChecks $checks): int
    {
        $results = $checks->run();
        $healthy = collect($results)->every(fn (array $result) => $result['state'] !== 'fail');
        $summary = collect($results)
            ->map(fn (array $result) => sprintf('%s: %s %s', $result['name'], $result['state'] === 'fail' ? 'FAIL' : $result['state'], $result['detail']))
            ->implode("\n");
        $this->line($summary);
        $exitCode = $healthy ? self::SUCCESS : self::FAILURE;

        if ($this->option('dry-run')) {
            return $exitCode;
        }

        $url = trim((string) config('heartbeat.url', ''));
        if ($url === '') {
            $this->info('Heartbeat ping is off: HEARTBEAT_PING_URL is empty.');

            return $exitCode;
        }

        $failUrl = trim((string) config('heartbeat.fail_url', '')) ?: rtrim($url, '/').'/fail';
        foreach ([$url, $failUrl] as $address) {
            if (! str_starts_with(strtolower($address), 'https://')) {
                // The address itself is never printed: it is a private token.
                $this->error('Heartbeat addresses must start with https://. Nothing was sent.');

                return self::FAILURE;
            }
        }

        try {
            $response = Http::timeout(max(1, (int) config('heartbeat.timeout_seconds', 10)))
                ->withBody($summary, 'text/plain')
                ->post($healthy ? $url : $failUrl);
        } catch (Throwable $e) {
            $this->error('Heartbeat ping failed: '.class_basename($e).'.');

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->error('Heartbeat ping failed: HTTP '.$response->status().'.');

            return self::FAILURE;
        }

        return $exitCode;
    }
}
