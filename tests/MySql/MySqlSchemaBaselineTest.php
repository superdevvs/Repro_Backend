<?php

namespace Tests\MySql;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * database/schema/mysql-schema.sql is the schema-only baseline that migrate:fresh
 * loads on MySQL, and the contracts copy single tables from it. It must carry every
 * migration, so nothing is left to run after the load.
 *
 * These tests read files only; they need no database connection.
 */
final class MySqlSchemaBaselineTest extends TestCase
{
    private static function schema(): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/database/schema/mysql-schema.sql');
    }

    /** The column lines of one CREATE TABLE statement. */
    private static function table(string $name): string
    {
        self::assertSame(1, preg_match('/^CREATE TABLE `'.preg_quote($name, '/').'` \((.*?)^\)/ms', self::schema(), $table),
            "The baseline has no CREATE TABLE for {$name}.");

        return $table[1];
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

    /**
     * A complete ledger proves nothing about the DDL above it. The reviewed migration
     * scope names every table of the release, so the baseline must create exactly those.
     */
    public function test_baseline_creates_exactly_the_tables_of_the_reviewed_scope(): void
    {
        $scope = json_decode(file_get_contents(dirname(__DIR__, 2).'/database/schema/migration-scope.json'), true, flags: JSON_THROW_ON_ERROR);
        preg_match_all('/^CREATE TABLE `([^`]+)` \(/m', self::schema(), $tables);
        $expected = array_keys($scope['tables']);
        $this->assertSame([], array_values(array_diff($expected, $tables[1])),
            'These tables of database/schema/migration-scope.json are missing from the baseline. Regenerate it.');
        $this->assertSame([], array_values(array_diff($tables[1], $expected)),
            'The baseline creates tables that database/schema/migration-scope.json does not list.');
    }

    public function test_recruit_application_receipts_has_the_replay_identity_columns(): void
    {
        $table = self::table('recruit_application_receipts');
        $this->assertMatchesRegularExpression('/^  `payload_sha256` char\(64\) .*DEFAULT NULL,$/m', $table);
        $this->assertMatchesRegularExpression('/^  `attachment_sha256s` text /m', $table);
    }

    /** @return array<string, array{0: string, 1: string}> One column each table changed by the 45 regenerated migrations. */
    public static function columnsOfTheRegeneratedMigrations(): array
    {
        return [
            'services (2026_11_05_190000)' => ['services', '/^  `financial_only` tinyint\(1\) NOT NULL DEFAULT \'0\',$/m'],
            'shoot_service (2026_11_05_190000)' => ['shoot_service', '/^  `delivery_requirements` json DEFAULT NULL,$/m'],
            'shoots (2026_11_05_190000)' => ['shoots', '/^  `active_work_is_reopened` tinyint\(1\) DEFAULT NULL,$/m'],
            'shoots (2026_11_06_000001)' => ['shoots', '/^  `active_work_cycle_key` char\(36\) .*DEFAULT NULL,$/m'],
            'shoots (2026_11_21_100000)' => ['shoots', '/^  `standing_editor_notes_snapshot` json DEFAULT NULL,$/m'],
            'workflow_logs (2026_11_06_000001)' => ['workflow_logs', '/^  `user_id` bigint DEFAULT NULL,$/m'],
            'editor_payouts (2026_11_11_173000)' => ['editor_payouts', '/^  `removed_shoot_service_id` bigint unsigned DEFAULT NULL,$/m'],
            'shoot_files (2026_11_19_120000)' => ['shoot_files', '/^  `is_client_hidden` tinyint\(1\) NOT NULL DEFAULT \'0\',$/m'],
        ];
    }

    #[DataProvider('columnsOfTheRegeneratedMigrations')]
    public function test_tables_changed_by_the_regenerated_migrations_have_their_new_columns(string $table, string $column): void
    {
        $this->assertMatchesRegularExpression($column, self::table($table));
    }

    public function test_the_iguide_lab_project_delete_trigger_is_present(): void
    {
        // 2026_11_22_120200, the one trigger the 45 regenerated migrations added.
        $this->assertMatchesRegularExpression('/TRIGGER `iguide_lab_project_delete_dependencies` BEFORE DELETE ON `iguide_lab_projects`/', self::schema());
    }
}
