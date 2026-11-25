<?php

namespace Tests\MySql;

use PHPUnit\Framework\TestCase;

/**
 * database/schema/mysql-schema.sql is the schema-only baseline that migrate:fresh
 * loads on MySQL, and the contracts copy single tables from it. It must carry every
 * migration, so nothing is left to run after the load.
 */
final class MySqlSchemaBaselineTest extends TestCase
{
    private static function schema(): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/database/schema/mysql-schema.sql');
    }

    public function test_ledger_lists_every_migration_file_once(): void
    {
        preg_match_all("/^\((\d+),'([^']+)',\d+\)[,;]$/m", self::schema(), $rows);
        $this->assertSame(count($rows[1]), count(array_unique($rows[1])), 'Ledger IDs repeat.');
        $this->assertSame(count($rows[2]), count(array_unique($rows[2])), 'Ledger names repeat.');
        $files = array_map(fn (string $path) => basename($path, '.php'), glob(dirname(__DIR__, 2).'/database/migrations/*.php'));
        $this->assertSame([], array_values(array_diff($files, $rows[2])),
            'These migrations are not in the baseline ledger. Regenerate database/schema/mysql-schema.sql.');
    }

    public function test_each_table_is_defined_once(): void
    {
        preg_match_all('/^CREATE TABLE `([^`]+)` \(/m', self::schema(), $tables);
        $this->assertSame([], array_keys(array_filter(array_count_values($tables[1]), fn (int $count) => $count > 1)));
    }

    public function test_recruit_application_receipts_has_the_replay_identity_columns(): void
    {
        $this->assertSame(1, preg_match('/^CREATE TABLE `recruit_application_receipts` \((.*?)^\)/ms', self::schema(), $table));
        $this->assertMatchesRegularExpression('/^  `payload_sha256` char\(64\) .*DEFAULT NULL,$/m', $table[1]);
        $this->assertMatchesRegularExpression('/^  `attachment_sha256s` text /m', $table[1]);
    }
}
