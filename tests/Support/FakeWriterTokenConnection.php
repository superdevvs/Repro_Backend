<?php

namespace Tests\Support;

use App\Database\WriterTokenConnection;
use App\Database\WriterTokenTimeout;
use Closure;
use Illuminate\Database\Connection;
use Throwable;

/** Stand-in for the MySQL writer connection: records how long its token waits may retry. */
final class FakeWriterTokenConnection extends Connection implements WriterTokenConnection
{
    /** @var list<int> */
    public array $attemptsRequested = [];

    public int $acquisitionAttempts = 1;

    private ?WriterTokenTimeout $lastTimeout = null;

    private bool $exhausted = false;

    public function __construct(int $level, private ?Closure $whileWaiting = null)
    {
        parent::__construct(fn () => null, 'fake', '', ['name' => 'writer-fake']);
        $this->transactions = $level;
    }

    public function writerPolicyEnabled(): bool
    {
        return true;
    }

    public function isAcquisitionTimeout(Throwable $error): bool
    {
        return $error === $this->lastTimeout;
    }

    public function withAcquisitionAttempts(int $attempts, Closure $callback): mixed
    {
        $this->attemptsRequested[] = $attempts;
        $previous = $this->acquisitionAttempts;
        $this->acquisitionAttempts = max($previous, $attempts);
        try {
            if ($this->whileWaiting) {
                ($this->whileWaiting)($this);
            }

            return $callback();
        } finally {
            $this->acquisitionAttempts = $previous;
        }
    }

    /** The next write's token wait times out on every attempt. */
    public function exhaustTokenWait(): void
    {
        $this->exhausted = true;
    }

    public function write(): void
    {
        if ($this->exhausted) {
            throw $this->lastTimeout = new WriterTokenTimeout('The MySQL writer token is busy.');
        }
    }
}
