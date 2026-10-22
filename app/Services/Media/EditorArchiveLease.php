<?php

namespace App\Services\Media;

/** Owns an advisory lock for the lifetime of generation or a PHP response stream. */
final class EditorArchiveLease
{
    public function __construct(private mixed $handle) {}

    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
