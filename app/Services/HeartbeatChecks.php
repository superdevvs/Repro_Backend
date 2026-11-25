<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Read-only checks for the outside heartbeat: database, backup age, free disk
 * and the oldest waiting queue job. Details carry numbers, file names and
 * queue names only.
 */
final class HeartbeatChecks
{
    public function __construct(private readonly DatabaseHealth $database) {}

    /** @return list<array{name: string, state: 'ok'|'fail'|'skipped', detail: string}> */
    public function run(): array
    {
        return [
            $this->check('database', fn () => $this->database()),
            $this->check('backup', fn () => $this->backup()),
            $this->check('disk', fn () => $this->disk()),
            $this->check('queue', fn () => $this->queue()),
        ];
    }

    /** @param  callable(): array{0: 'ok'|'fail'|'skipped', 1: string}  $check */
    private function check(string $name, callable $check): array
    {
        try {
            [$state, $detail] = $check();
        } catch (Throwable $e) {
            report($e);
            [$state, $detail] = ['fail', 'error='.class_basename($e)];
        }

        return ['name' => $name, 'state' => $state, 'detail' => $detail];
    }

    private function database(): array
    {
        $result = $this->database->check();
        $engine = 'engine='.($result['engine'] ?? 'unknown');

        return $result['status'] === 'ok'
            ? ['ok', $engine.(isset($result['server_identity']) ? ' server_identity='.$result['server_identity'] : '')]
            : ['fail', $result['reason'].' '.$engine];
    }

    private function backup(): array
    {
        $pattern = trim((string) config('heartbeat.backup.glob', ''));
        if ($pattern === '') {
            return ['skipped', 'HEARTBEAT_BACKUP_GLOB is empty'];
        }

        $newest = null;
        $newestTime = 0;
        foreach (glob($pattern, GLOB_NOSORT) ?: [] as $file) {
            $modified = is_file($file) ? @filemtime($file) : false;
            if ($modified !== false && ($newest === null || $modified > $newestTime)) {
                [$newest, $newestTime] = [$file, $modified];
            }
        }
        if ($newest === null) {
            return ['fail', 'no backup file matches'];
        }

        $maxHours = (float) config('heartbeat.backup.max_age_hours', 26);
        $ageHours = max(0, time() - $newestTime) / 3600;
        $detail = sprintf('newest=%s age_hours=%.1f max_hours=%s', basename($newest), $ageHours, $maxHours);
        if ((int) @filesize($newest) === 0) {
            return ['fail', $detail.' empty_file'];
        }

        return [$ageHours <= $maxHours ? 'ok' : 'fail', $detail];
    }

    private function disk(): array
    {
        $paths = self::list(config('heartbeat.disk.paths', '')) ?: [base_path()];
        $minimum = (float) config('heartbeat.disk.min_free_gb', 20);
        $healthy = true;
        $parts = [];
        foreach ($paths as $path) {
            $free = @disk_free_space($path);
            if ($free === false) {
                $healthy = false;
                $parts[] = $path.' unreadable';

                continue;
            }
            $gigabytes = $free / 1024 ** 3;
            $healthy = $healthy && $gigabytes >= $minimum;
            $parts[] = sprintf('%s free_gb=%.1f', $path, $gigabytes);
        }

        return [$healthy ? 'ok' : 'fail', implode(' ', $parts).' min_gb='.$minimum];
    }

    private function queue(): array
    {
        $name = (string) config('queue.default');
        $connection = config("queue.connections.{$name}");
        if (! is_array($connection) || ($connection['driver'] ?? null) !== 'database') {
            return ['skipped', 'the default queue does not use the database'];
        }

        $now = time();
        $query = DB::connection($connection['connection'] ?? null)
            ->table($connection['table'] ?? 'jobs')
            ->whereNull('reserved_at')
            ->where('available_at', '<=', $now);
        if (($queues = self::list(config('heartbeat.queue.queues', ''))) !== []) {
            $query->whereIn('queue', $queues);
        }
        $oldest = $query->orderBy('available_at')->orderBy('id')->first(['queue', 'available_at']);
        if ($oldest === null) {
            return ['ok', 'waiting=0'];
        }

        $age = max(0, $now - (int) $oldest->available_at);
        $maxSeconds = (int) config('heartbeat.queue.max_age_minutes', 10) * 60;
        $queue = preg_replace('/[^A-Za-z0-9_.:-]/', '?', (string) $oldest->queue);

        return [$age <= $maxSeconds ? 'ok' : 'fail',
            sprintf('oldest_waiting_seconds=%d queue=%s max_seconds=%d', $age, $queue, $maxSeconds)];
    }

    /** @return list<string> */
    private static function list(mixed $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $value)), fn ($item) => $item !== ''));
    }
}
