<?php

namespace Tests\MySql\Support;

use Illuminate\Database\DatabaseTransactionsManager;

/** Test policy for an outcome that cannot be reported as a rollback. */
final class FixtureWriterTransactionsManager extends DatabaseTransactionsManager
{
    public function abandon(string $connection): void
    {
        unset($this->currentTransaction[$connection]);
        $belongsToConnection = fn ($transaction) => $transaction->connection === $connection;
        $this->pendingTransactions = $this->pendingTransactions->reject($belongsToConnection)->values();
        $this->committedTransactions = $this->committedTransactions->reject($belongsToConnection)->values();
    }
}
