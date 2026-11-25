<?php

namespace App\Support;

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use RuntimeException;

/**
 * File-based scheduler hold. Each owner (deploy, cutover, ...) writes its own
 * scheduler-hold.<owner>.json naming the scheduled tasks to skip, or "*".
 * Every hold expires by itself; a broken or expired file holds nothing.
 */
final class SchedulerHold
{
    public const OWNER_PATTERN = '/\A[a-z0-9][a-z0-9-]{0,39}\z/';

    public function __construct(
        private readonly string $directory,
        private readonly int $maxMinutes = 720,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('schedule.hold.directory', storage_path('framework')),
            (int) config('schedule.hold.max_minutes', 720),
        );
    }

    /** @return array<string, array{hold: list<string>, reason: string, expires_at: int}> */
    public function active(?int $now = null): array
    {
        $now ??= time();
        $holds = [];
        foreach (glob($this->directory.'/scheduler-hold.*.json') ?: [] as $path) {
            $owner = substr(basename($path), strlen('scheduler-hold.'), -strlen('.json'));
            if (! preg_match(self::OWNER_PATTERN, $owner)) {
                continue;
            }
            $hold = $this->read($path);
            $modified = @filemtime($path);
            if ($hold === null || $modified === false) {
                continue;
            }
            $expiresAt = min($hold['expires_at'], $modified + $this->maxMinutes * 60);
            if ($expiresAt > $now) {
                $holds[$owner] = ['expires_at' => $expiresAt] + $hold;
            }
        }
        ksort($holds);

        return $holds;
    }

    /** @return list<string> Every name held right now, "*" meaning all. */
    public function heldNames(?int $now = null): array
    {
        return array_values(array_unique(array_merge([], ...array_column($this->active($now), 'hold'))));
    }

    /** @param list<string> $heldNames */
    public static function isHeld(Event $event, array $heldNames): bool
    {
        return in_array('*', $heldNames, true) || in_array(self::eventName($event), $heldNames, true);
    }

    /** The artisan command name, or the name given to a callback or job. */
    public static function eventName(Event $event): string
    {
        if ($event instanceof CallbackEvent || ! is_string($event->command)) {
            return (string) $event->description;
        }
        if (preg_match("/(?:\\A|\\s)'?artisan'?\\s+'?([^\\s']+)/", $event->command, $match)) {
            return $match[1];
        }

        return trim($event->command);
    }

    /** @param list<string> $names */
    public function set(string $owner, array $names, int $minutes, string $reason): string
    {
        $this->assertOwner($owner);
        if ($minutes < 1 || $minutes > $this->maxMinutes) {
            throw new RuntimeException("A hold must last between 1 and {$this->maxMinutes} minutes.");
        }
        $names = array_values(array_unique(array_map('strval', $names ?: ['*'])));
        $path = $this->path($owner);
        $temporary = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        $json = json_encode(['hold' => $names, 'reason' => $reason, 'expires_at' => time() + $minutes * 60],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        if (@file_put_contents($temporary, $json) !== strlen($json) || ! @rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Cannot write the scheduler hold file.');
        }

        return $path;
    }

    public function release(string $owner): bool
    {
        $this->assertOwner($owner);
        $path = $this->path($owner);

        return ! file_exists($path) || @unlink($path);
    }

    /** @return array{hold: list<string>, reason: string, expires_at: int}|null */
    private function read(string $path): ?array
    {
        $contents = @file_get_contents($path, length: 65536);
        $data = is_string($contents) ? json_decode($contents, true) : null;
        if (! is_array($data) || ! is_array($data['hold'] ?? null) || ! is_int($data['expires_at'] ?? null)) {
            return null;
        }
        $names = array_values(array_filter($data['hold'], 'is_string'));

        return $names === [] ? null : [
            'hold' => $names,
            'reason' => is_string($data['reason'] ?? null) ? $data['reason'] : '',
            'expires_at' => $data['expires_at'],
        ];
    }

    private function path(string $owner): string
    {
        return $this->directory.'/scheduler-hold.'.$owner.'.json';
    }

    private function assertOwner(string $owner): void
    {
        if (! preg_match(self::OWNER_PATTERN, $owner)) {
            throw new RuntimeException('Hold owners use lowercase letters, digits and dashes.');
        }
    }
}
