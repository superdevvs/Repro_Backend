<?php

namespace Tests\Support;

use App\Database\WriterTokenConnection;
use App\Database\WriterTokenTimeout;
use Closure;
use Illuminate\Database\Connection;
use Throwable;

/** Stand-in for the MySQL writer connection: simulates acquisition timeouts before the callback. */
final class FakeWriterTokenConnection extends Connection implements WriterTokenConnection
{
    public array $transactionAttempts = [];

    public int $acquisitions = 0;

    public bool $holdsToken = false;

    private ?WriterTokenTimeout $lastTimeout = null;

    public function __construct(private int $timeoutsBeforeAcquired, int $level)
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

    public function transaction(Closure $callback, $attempts = 1)
    {
        $this->transactionAttempts[] = $attempts;
        for ($attempt = 1; ; $attempt++) {
            $this->acquisitions++;
            if ($this->timeoutsBeforeAcquired-- > 0) {
                $this->lastTimeout = new WriterTokenTimeout('The MySQL writer token is busy.');
                if ($attempt >= $attempts) {
                    throw $this->lastTimeout;
                }

                continue;
            }
            $this->holdsToken = true;
            $this->transactions++;
            try {
                return $callback($this);
            } finally {
                $this->transactions--;
                $this->holdsToken = false;
            }
        }
    }
}
