<?php

namespace App\Logging;

use Monolog\Handler\RotatingFileHandler;
use Monolog\LogRecord;

/**
 * Daily log files that every member of the log directory's group can append to.
 *
 * storage/logs has no setgid bit, so a file takes its creator's primary group.
 * When the deploy user creates a day's file first, web workers in the
 * www-data group could not append to it for the rest of that day. After
 * opening a file it owns, this handler moves it to the directory's group and
 * applies the channel's permission.
 */
class SharedGroupRotatingFileHandler extends RotatingFileHandler
{
    protected function write(LogRecord $record): void
    {
        $opening = ! is_resource($this->stream);

        parent::write($record);

        if ($opening && is_string($this->url)) {
            self::shareWithDirectoryGroup($this->url, $this->filePermission ?? 0660);
        }
    }

    public static function shareWithDirectoryGroup(string $file, int $permission): void
    {
        if (! function_exists('posix_geteuid') || ! is_file($file)) {
            return;
        }

        clearstatcache(true, $file);
        $stat = @stat($file);
        $directoryGroup = @filegroup(dirname($file));
        // Only the owner may change a file's group or mode.
        if ($stat === false || $directoryGroup === false || $stat['uid'] !== posix_geteuid()) {
            return;
        }

        if ($stat['gid'] !== $directoryGroup) {
            @chgrp($file, $directoryGroup);
        }
        @chmod($file, $permission);
    }
}
