<?php

namespace App\Database;

use App\Services\Scheduling\GoogleRoutesBudget;
use Closure;
use Illuminate\Database\QueryException;
use LogicException;
use PDO;
use RuntimeException;
use Throwable;

final class MySqlConnection extends \Illuminate\Database\MySqlConnection
{
    protected function getDefaultQueryGrammar()
    {
        return new MySqlQueryGrammar($this);
    }

    protected function runQueryCallback($query, $bindings, Closure $callback)
    {
        if (! $this->guardsRouteBudget($query) || $this->pretending()) {
            return parent::runQueryCallback($query, $bindings, $callback);
        }

        if ($this->transactionLevel() !== 0) {
            throw new LogicException('Reserve route usage outside the booking transaction.');
        }

        $uuid = $this->getConfig('repro_expected_server_uuid');
        if (! is_string($uuid) || ! preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/i', $uuid)
            || ! str_starts_with((string) $this->getConfig('unix_socket'), '/')) {
            throw new LogicException('The route budget writer token requires an approved MySQL UUID and local socket.');
        }

        $pdo = $this->getPdo();
        if ($pdo->inTransaction() || $pdo->getAttribute(PDO::ATTR_PERSISTENT)) {
            throw new LogicException('The route budget writer token requires a standalone, nonpersistent MySQL session.');
        }

        $wasModified = $this->hasModifiedRecords();
        $commitAttempted = false;
        try {
            $restoreSession = $this->prepareRouteBudgetSession($pdo);
            (new MySqlWriterToken)->beginAndAcquire($pdo, $uuid, $this->getDatabaseName());

            // Hold the business table's metadata lock until COMMIT so the reservation
            // cannot run against a nontransactional replacement of the budget table.
            $pdo->query('SELECT id FROM scheduling_route_usage ORDER BY id LIMIT 1')->closeCursor();
            $engine = $pdo->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'scheduling_route_usage' AND TABLE_TYPE = 'BASE TABLE'")->fetchColumn();
            if ($engine !== 'InnoDB') {
                throw new RuntimeException('The route budget writer token requires the InnoDB budget table.');
            }

            $result = parent::runQueryCallback($query, $bindings, $callback);
            $commitAttempted = true;
            if (! $pdo->inTransaction() || ! $pdo->commit() || $pdo->inTransaction()) {
                throw new RuntimeException('MySQL did not confirm the route budget reservation commit.');
            }
        } catch (Throwable $failure) {
            $this->setRecordModificationState($wasModified);
            if (! $commitAttempted) {
                try {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                } catch (Throwable) {
                    // Disconnect below even if rollback could not be confirmed.
                }
            }
            // Never send ROLLBACK on a session with an ambiguous COMMIT or retry a reservation.
            $this->disconnect();
            if ($commitAttempted) {
                throw new RuntimeException('The outcome of the MySQL route budget reservation is unknown.', 0, $failure);
            }

            throw $failure;
        }

        try {
            if ($restoreSession !== null && $pdo->exec($restoreSession) === false) {
                $this->disconnect();
            }
        } catch (Throwable) {
            // The reservation is durable. Do not reuse a session with uncertain defaults.
            $this->disconnect();
        }

        // Laravel dispatches QueryExecuted from run() after the confirmed commit.
        return $result;
    }

    protected function handleQueryException(QueryException $error, $query, $bindings, Closure $callback)
    {
        if ($this->guardsRouteBudget($query)) {
            throw $error;
        }

        return parent::handleQueryException($error, $query, $bindings, $callback);
    }

    private function guardsRouteBudget($query): bool
    {
        return $query === GoogleRoutesBudget::RESERVATION_SQL
            && $this->getConfig('repro_route_budget_writer_token') === true;
    }

    private function prepareRouteBudgetSession(PDO $pdo): ?string
    {
        $settings = $pdo->query('SELECT @@SESSION.transaction_isolation, @@SESSION.innodb_lock_wait_timeout,
            @@SESSION.lock_wait_timeout')->fetch(PDO::FETCH_NUM);
        if ($settings === false || ! in_array($settings[0], ['READ-UNCOMMITTED', 'READ-COMMITTED', 'REPEATABLE-READ', 'SERIALIZABLE'], true)) {
            throw new RuntimeException('The route budget writer token could not read the MySQL session settings.');
        }

        [$isolation, $rowTimeout, $metadataTimeout] = $settings;
        $rowTimeout = (int) $rowTimeout;
        $metadataTimeout = (int) $metadataTimeout;
        if ($isolation === 'READ-COMMITTED' && $rowTimeout === 5 && $metadataTimeout === 5) {
            return null;
        }

        $pdo->exec("SET SESSION transaction_isolation = 'READ-COMMITTED', SESSION innodb_lock_wait_timeout = 5, SESSION lock_wait_timeout = 5");

        return "SET SESSION transaction_isolation = '{$isolation}', SESSION innodb_lock_wait_timeout = {$rowTimeout}, SESSION lock_wait_timeout = {$metadataTimeout}";
    }
}
