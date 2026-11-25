<?php

namespace Tests\Unit\Database;

use App\Database\WriterStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WriterStatementTest extends TestCase
{
    public static function statements(): array
    {
        return [
            // Writes: wrapped as BEGIN, token, statement, COMMIT at level 0.
            'builder insert' => ['insert into `jobs` (`queue`) values (?)', WriterStatement::WRITE],
            'insert ignore' => ['insert ignore into `cache` (`key`) values (?)', WriterStatement::WRITE],
            'upsert' => ['insert into `cache` (`key`) values (?) as laravel_upsert_alias on duplicate key update `value` = ?', WriterStatement::WRITE],
            'update with join' => ['update `shoots` inner join `users` on 1 = 1 set `a` = ?', WriterStatement::WRITE],
            'delete' => ['delete from `cache_locks` where `key` = ?', WriterStatement::WRITE],
            'replace' => ['REPLACE INTO t VALUES (1)', WriterStatement::WRITE],
            'leading comment and space' => ["  /* audit */\n -- note\n  UPDATE t SET a = 1", WriterStatement::WRITE],
            'hash comment' => ["# note\nDELETE FROM t", WriterStatement::WRITE],
            'cte update' => ['WITH x AS (SELECT id FROM t) UPDATE t JOIN x USING (id) SET a = 1', WriterStatement::WRITE],
            'cte delete' => ['with recursive x (id) as (select 1 union all select id + 1 from x where id < 3) delete from t where id in (select id from x)', WriterStatement::WRITE],
            'insert select' => ['INSERT INTO t (a) SELECT ? WHERE COALESCE((SELECT SUM(a) FROM t), 0) + ? <= ?', WriterStatement::WRITE],
            'call' => ['CALL repair_rows()', WriterStatement::WRITE],
            'load data' => ["LOAD DATA LOCAL INFILE 'x' INTO TABLE t", WriterStatement::WRITE],

            // Reads are never wrapped.
            'select' => ['select * from `shoots` where `id` = ? limit 1', WriterStatement::READ],
            'select mentioning update in a name' => ['select `update_count` from `t` where `for_update` = ?', WriterStatement::READ],
            'parenthesised union' => ['(select 1) union (select 2) order by 1', WriterStatement::READ],
            'cte select' => ['WITH x AS (SELECT 1 AS id) SELECT * FROM x', WriterStatement::READ],
            'cte select that mentions update in a string' => ["with x as (select 'update' as w) select * from x", WriterStatement::READ],
            'show' => ['SHOW GRANTS', WriterStatement::READ],
            'explain' => ['explain select 1', WriterStatement::READ],
            'describe' => ['DESCRIBE t', WriterStatement::READ],

            // Locking reads.
            'for update' => ['select * from `jobs` where `queue` = ? order by `id` asc limit 1 for update skip locked', WriterStatement::LOCKING_READ],
            'for update nowait' => ['SELECT * FROM t FOR UPDATE NOWAIT', WriterStatement::LOCKING_READ],
            'shared lock' => ['select * from `t` lock in share mode', WriterStatement::LOCKING_READ],
            'for share' => ['select * from `t` for share', WriterStatement::LOCKING_READ],
            'cte locking read' => ['with x as (select 1 as id) select * from t join x using (id) for update', WriterStatement::LOCKING_READ],

            // DDL is never wrapped and never runs inside a transaction.
            'create table' => ['create table `t` (`id` bigint)', WriterStatement::DDL],
            'alter' => ['ALTER TABLE t ADD c INT', WriterStatement::DDL],
            'drop' => ['drop table if exists `t`', WriterStatement::DDL],
            'truncate' => ['truncate table `t`', WriterStatement::DDL],
            'rename' => ['RENAME TABLE a TO b', WriterStatement::DDL],
            'create trigger' => ['CREATE TRIGGER t AFTER INSERT ON x FOR EACH ROW BEGIN INSERT INTO y VALUES (1); END', WriterStatement::DDL],
            'grant' => ['GRANT SELECT ON t TO u', WriterStatement::DDL],

            // Temporary tables do not commit implicitly.
            'create temporary table' => ['CREATE TEMPORARY TABLE tmp (id INT)', WriterStatement::TEMPORARY],
            'drop temporary table' => ['drop temporary table if exists tmp', WriterStatement::TEMPORARY],

            // Session statements are never wrapped.
            'set' => ['SET SESSION innodb_lock_wait_timeout = 5', WriterStatement::SESSION],
            'use' => ['use `repro_mysql_test`', WriterStatement::SESSION],
            'do' => ['DO SLEEP(0)', WriterStatement::SESSION],

            // Transaction control bypasses Laravel's state and is refused.
            'begin' => ['BEGIN', WriterStatement::TRANSACTION_CONTROL],
            'start transaction' => ['start transaction', WriterStatement::TRANSACTION_CONTROL],
            'commit' => ['COMMIT', WriterStatement::TRANSACTION_CONTROL],
            'rollback' => ['rollback', WriterStatement::TRANSACTION_CONTROL],
            'savepoint' => ['SAVEPOINT x', WriterStatement::TRANSACTION_CONTROL],
            'release' => ['RELEASE SAVEPOINT x', WriterStatement::TRANSACTION_CONTROL],
            'lock tables' => ['LOCK TABLES t WRITE', WriterStatement::TRANSACTION_CONTROL],
            'unlock tables' => ['UNLOCK TABLES', WriterStatement::TRANSACTION_CONTROL],
            'xa' => ["XA START 'x'", WriterStatement::TRANSACTION_CONTROL],

            // A batch is refused: the server would run every statement, the token check sees only one.
            'read then write' => ['SELECT 1; UPDATE users SET name = 1', WriterStatement::MULTIPLE],
            'exempt write then business write' => ['insert into `cache_locks` (`key`) values (?); update `users` set `name` = ?', WriterStatement::MULTIPLE],
            'two dashes without a space are not a comment' => ['SELECT --1; UPDATE t SET a = 1', WriterStatement::MULTIPLE],
            'two tables then a write' => ['CREATE TABLE a (id INT); UPDATE users SET name = 1', WriterStatement::MULTIPLE],
            'trailing semicolon' => ['UPDATE t SET a = 1;', WriterStatement::WRITE],
            'trailing semicolon and comment' => ["select 1; -- done\n", WriterStatement::READ],
            'semicolon inside a string' => ["update t set a = 'x; delete from users'", WriterStatement::WRITE],
            'procedure body' => ['CREATE PROCEDURE p() BEGIN UPDATE t SET a = 1; SELECT 1; END', WriterStatement::DDL],
            'trigger body with definer' => ["CREATE DEFINER=`a`@`localhost` TRIGGER t BEFORE DELETE ON x FOR EACH ROW BEGIN\n DELETE FROM y WHERE id = OLD.id;\nEND", WriterStatement::DDL],

            'empty' => ['   ', WriterStatement::OTHER],
            'analyze' => ['ANALYZE TABLE t', WriterStatement::OTHER],
        ];
    }

    #[DataProvider('statements')]
    public function test_classifies_statement(string $sql, string $expected): void
    {
        $this->assertSame($expected, WriterStatement::classify($sql));
    }

    public static function writeTargets(): array
    {
        return [
            ['insert into `cache_locks` (`key`) values (?)', 'cache_locks'],
            ['insert ignore into `jobs` (`queue`) values (?)', 'jobs'],
            ['update `jobs` set `reserved_at` = ? where `id` = ?', 'jobs'],
            ['delete from `cache` where `key` = ?', 'cache'],
            ['DELETE FROM repro_mysql_test.jobs WHERE id = 1', null],
            ['update `jobs` inner join `shoots` on 1 = 1 set `jobs`.`queue` = ?', null],
            ['delete `jobs` from `jobs` where 1', null],
            ['replace into cache values (1)', 'cache'],
            ['WITH x AS (SELECT 1) DELETE FROM jobs', null],
            ['delete from `cache_locks` where `key` = ?;', 'cache_locks'],
            ['insert into `cache_locks` (`key`) values (?); update `users` set `name` = ?', null],
            ['delete from cache_locks; delete from users', null],
        ];
    }

    #[DataProvider('writeTargets')]
    public function test_reports_a_single_plain_target_table_or_nothing(string $sql, ?string $table): void
    {
        $this->assertSame($table, WriterStatement::singleTargetTable($sql));
    }
}
