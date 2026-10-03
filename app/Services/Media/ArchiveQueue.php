<?php

namespace App\Services\Media;

final class ArchiveQueue
{
    public static function name(int $shootId): string
    {
        return app(MediaStorage::class)->performanceEnabled('archive_dedicated_queue', $shootId)
            ? 'media-archives' : 'default';
    }

    public static function lockSeconds(): int
    {
        // Cover queue wait plus the existing database reservation and job timeout.
        return max(3600, (int) config('queue.connections.database.retry_after', 2100) + 900);
    }
}
