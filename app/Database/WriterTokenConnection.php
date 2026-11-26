<?php

namespace App\Database;

use Illuminate\Database\ConnectionInterface;
use Throwable;

/** A connection that keeps the one-writer rule by taking the writer token. */
interface WriterTokenConnection extends ConnectionInterface
{
    public function writerPolicyEnabled(): bool;

    /** True only for this connection's own timeout while taking the token, before any callback ran. */
    public function isAcquisitionTimeout(Throwable $error): bool;

    /**
     * Run the callback once while every token wait on this connection may retry
     * where it stands, up to the given number of waits. Nothing is replayed.
     */
    public function withAcquisitionAttempts(int $attempts, \Closure $callback): mixed;
}
