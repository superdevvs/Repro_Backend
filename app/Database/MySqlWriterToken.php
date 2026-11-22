<?php

namespace App\Database;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Unregistered first-release primitive, not an application-wide write policy.
 * Owns BEGIN on the business PDO; the caller still owns COMMIT or ROLLBACK.
 * Provision the fixed control table separately; runtime never creates or seeds it.
 * Discard the PDO after any failure other than a rolled-back WriterTokenTimeout.
 */
final class MySqlWriterToken
{
    public const SCHEMA = 'repro_mysql_control';

    public const TABLE = 'writer_token';

    public function beginAndAcquire(PDO $pdo, string $expectedServerUuid, string $expectedDatabase): void
    {
        // Never roll back a transaction owned by somebody else.
        if ($pdo->inTransaction()) {
            throw new RuntimeException('Writer token cannot begin inside an existing transaction.');
        }
        $locking = false;
        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
                || $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
                || (int) $pdo->getAttribute(PDO::ATTR_AUTOCOMMIT) !== 1
                || ! preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/i', $expectedServerUuid)
                || ! preg_match('/\A[A-Za-z0-9_]{1,64}\z/', $expectedDatabase)
                || $expectedDatabase === self::SCHEMA) {
                throw new RuntimeException('Writer token requires a known MySQL writer PDO and explicit target identity.');
            }

            $identity = $pdo->query('SELECT DATABASE() AS database_name, @@server_uuid AS server_uuid,
                @@SESSION.transaction_isolation AS isolation_level,
                @@SESSION.innodb_lock_wait_timeout AS lock_wait_timeout,
                @@SESSION.lock_wait_timeout AS metadata_lock_wait_timeout,
                @@SESSION.transaction_read_only AS session_read_only, @@SESSION.autocommit AS autocommit,
                @@GLOBAL.read_only AS server_read_only, @@GLOBAL.super_read_only AS server_super_read_only')->fetch(PDO::FETCH_ASSOC);
            if ($identity['database_name'] !== $expectedDatabase
                || ! hash_equals(strtolower($expectedServerUuid), strtolower($identity['server_uuid']))
                || $identity['isolation_level'] !== 'READ-COMMITTED'
                || (int) $identity['lock_wait_timeout'] !== 5 || (int) $identity['metadata_lock_wait_timeout'] !== 5
                || (int) $identity['autocommit'] !== 1 || (int) $identity['session_read_only'] !== 0 || (int) $identity['server_read_only'] !== 0
                || (int) $identity['server_super_read_only'] !== 0) {
                throw new RuntimeException('Writer token target identity or transaction settings differ.');
            }

            // A one-shot SET TRANSACTION can differ from the session defaults.
            // Establish both actual transaction characteristics at the BEGIN boundary.
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED, READ WRITE');
            if (! $pdo->beginTransaction() || ! $pdo->inTransaction()) {
                throw new RuntimeException('Writer token could not establish its transaction; discard this database connection.');
            }
            $locking = true;
            $rows = $pdo->query('SELECT id, application_database, contract_version
                FROM `repro_mysql_control`.`writer_token` WHERE id = 1 FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
            $locking = false;
            // Check the engine while this transaction holds the table metadata lock.
            $table = $pdo->query("SELECT ENGINE, TABLE_TYPE FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = 'repro_mysql_control' AND TABLE_NAME = 'writer_token'")->fetch(PDO::FETCH_ASSOC);
            if (! $table || $table['ENGINE'] !== 'InnoDB' || $table['TABLE_TYPE'] !== 'BASE TABLE') {
                throw new RuntimeException('Writer token requires the separately provisioned InnoDB control table.');
            }

            if (count($rows) !== 1 || (int) $rows[0]['id'] !== 1
                || $rows[0]['application_database'] !== $expectedDatabase || (int) $rows[0]['contract_version'] !== 1) {
                throw new RuntimeException('Writer token row is missing or bound to a different application contract.');
            }
        } catch (Throwable $error) {
            $rolledBack = false;
            try {
                if ($pdo->inTransaction()) {
                    if (! $pdo->rollBack() || $pdo->inTransaction()) {
                        throw new RuntimeException('The writer transaction did not close after rollback.');
                    }
                    $rolledBack = true;
                }
            } catch (Throwable $rollbackError) {
                throw new RuntimeException('Writer token rollback failed; discard this database connection.', 0, $rollbackError);
            }
            if ($locking && $rolledBack && $error instanceof PDOException && (int) ($error->errorInfo[1] ?? 0) === 1205) {
                throw new WriterTokenTimeout('The MySQL writer token is busy.', 0, $error);
            }
            if ($error instanceof PDOException) {
                throw new RuntimeException('The MySQL writer token could not be acquired safely; discard this database connection.', 0, $error);
            }

            throw $error;
        }
    }
}
