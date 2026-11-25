<?php

namespace Tests\MySql;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * database/schema/mysql-schema.sql is the schema-only baseline that migrate:fresh
 * loads on MySQL, and the contracts copy single tables from it. It must carry every
 * migration, so nothing is left to run after the load.
 *
 * These tests need no database server. The parity tests compare the baseline with a
 * fresh SQLite migrate of this checkout, run once in a child process against its own
 * temporary SQLite file, so they behave the same under phpunit.xml and phpunit.mysql.xml.
 * They compare names only: tables, columns, indexes and triggers. Column types, index
 * columns, trigger bodies, foreign keys and CHECK constraints are not compared.
 */
final class MySqlSchemaBaselineTest extends TestCase
{
    /**
     * Tables of the baseline that no migration creates. The baseline is converted from
     * production, which has them; the reviewed scope keeps every row (class A).
     */
    private const PRODUCTION_ONLY_TABLES = [
        'ai_video_generation_jobs_backup', 'historical_import_releases', 'historical_nocharge_releases',
        'legacy_activity_imports', 'messaging_settings', 'september_import_assets', 'september_import_ledger',
    ];

    /** Every reviewed column difference between the baseline and a fresh SQLite migrate, with its reason. */
    private const REVIEWED_COLUMN_DIFFERENCES = [
        'automation_dispatches' => [
            'baseline_only' => ['message_id', 'scheduled_at', 'sent_at', 'trigger_context_json', 'trigger_event'], 'sqlite_only' => [],
            'reason' => 'Production created the table with an earlier schema. 2025_11_27_193031 now creates only id and '
                .'timestamps, and 2026_03_22_000002 adds its columns only when missing, so no migration creates these.',
        ],
        'message_channels' => [
            'baseline_only' => ['label'], 'sqlite_only' => [],
            'reason' => 'A legacy production column. 2025_11_28_000500 copies it into display_name when it exists; no migration creates it.',
        ],
    ];

    /**
     * Every reviewed index difference, after the SQLite names are converted the way
     * scripts/mysql/conversion.py names them and MySQL's foreign-key indexes are left out.
     */
    private const REVIEWED_INDEX_DIFFERENCES = [
        'automation_rules' => [
            'baseline_only' => [], 'sqlite_only' => ['automation_rules_trigger_type_is_active_index'],
            'reason' => 'Absent from the production table the baseline was converted from, which also lacks the foreign keys '
                .'2025_11_28_100000 declares: that migration creates the table (with this index) only when it does not exist.',
        ],
        'invoices' => [
            'baseline_only' => ['invoices_effective_period_sort'], 'sqlite_only' => [],
            'reason' => '2026_11_04_190000_index_mysql_invoice_effective_period creates this expression index on MySQL only.',
        ],
        'listing_studio_subscription_receipts' => [
            'baseline_only' => ['idx_listing_studio_subscription_receipts_subscrip_ce28a6600a6a'],
            'sqlite_only' => ['ls_receipt_subscription_invoice_unique'],
            'reason' => 'The same unique index. Production created it under Laravel\'s default name '
                .'listing_studio_subscription_receipts_subscription_id_invoice_id_unique (70 characters), which the '
                .'converter bounds to 64; the migration now names it ls_receipt_subscription_invoice_unique.',
        ],
        'message_templates' => [
            'baseline_only' => [], 'sqlite_only' => ['message_templates_channel_scope_index'],
            'reason' => 'Created by 2025_11_27_200000 on a fresh database but absent from the production table the baseline '
                .'was converted from (2026_11_04_180000 notes that historical SQLite rebuilds of the messaging tables lost parts).',
        ],
        'messages' => [
            'baseline_only' => [],
            'sqlite_only' => ['messages_channel_status_scheduled_at_index', 'messages_related_shoot_id_index', 'messages_thread_id_index'],
            'reason' => 'As for message_templates: created by 2025_11_27_200000, absent from the production table.',
        ],
    ];

    /** @var array{tables: array<string, array{columns: list<string>, indexes: list<string>}>, triggers: list<string>}|null */
    private static ?array $sqlite = null;

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

    public function test_baseline_has_every_table_of_a_fresh_sqlite_migrate_and_only_reviewed_extras(): void
    {
        $baseline = array_keys(self::baselineTables());
        $sqlite = array_keys(self::sqlite()['tables']);

        $this->assertSame([], array_values(array_diff($sqlite, $baseline)), 'The baseline misses tables a fresh migrate creates. Regenerate it.');
        $this->assertSame(self::PRODUCTION_ONLY_TABLES, array_values(array_diff($baseline, $sqlite)));
    }

    /** The spot checks above miss a dropped column; this compares every column of every table both sides have. */
    public function test_each_shared_table_has_the_columns_of_a_fresh_sqlite_migrate(): void
    {
        $sqlite = self::sqlite()['tables'];
        $differences = [];
        foreach (self::baselineTables() as $table => $baseline) {
            if (isset($sqlite[$table])) {
                $differences += self::difference($table, $baseline['columns'], $sqlite[$table]['columns']);
            }
        }

        $this->assertSame(self::withoutReasons(self::REVIEWED_COLUMN_DIFFERENCES), $differences,
            'Columns differ from a fresh SQLite migrate. Regenerate the baseline, or review the difference and list it with its reason.');
    }

    public function test_each_shared_table_has_the_indexes_a_fresh_sqlite_migrate_converts_to(): void
    {
        $sqlite = self::sqlite()['tables'];
        $differences = [];
        foreach (self::baselineTables() as $table => $baseline) {
            if (isset($sqlite[$table])) {
                $differences += self::difference($table, $baseline['indexes'], $sqlite[$table]['indexes']);
            }
        }

        $this->assertSame(self::withoutReasons(self::REVIEWED_INDEX_DIFFERENCES), $differences,
            'Indexes differ from a fresh SQLite migrate. Regenerate the baseline, or review the difference and list it with its reason.');
    }

    public function test_baseline_triggers_are_those_of_a_fresh_sqlite_migrate_plus_the_mysql_only_ones(): void
    {
        preg_match_all('/\bTRIGGER `([A-Za-z0-9_]+)` (?:BEFORE|AFTER) /', self::schema(), $triggers);
        $expected = array_values(array_unique(array_merge(self::sqlite()['triggers'], ...array_values(self::mysqlOnlyTriggers()))));
        sort($expected);
        $baseline = $triggers[1];
        sort($baseline);

        $this->assertSame($expected, $baseline);
    }

    /**
     * Triggers the baseline has beyond those of a fresh SQLite migrate, by the rule that adds them.
     *
     * @return array<string, list<string>>
     */
    private static function mysqlOnlyTriggers(): array
    {
        $cascade = json_decode(file_get_contents(dirname(__DIR__, 2).'/database/schema/mysql-cascade-triggers.json'), true, flags: JSON_THROW_ON_ERROR);

        return [
            // scripts/mysql/migrate.py sqlite_triggers() adds the shoot_files and payments change-feed triggers when the
            // source lacks them: a SQLite rebuild of payments (2026_10_09_120000) drops the payments ones.
            'migrate.py' => ['aryeo_payment_status', 'aryeo_payments_DELETE', 'aryeo_payments_INSERT', 'aryeo_payments_UPDATE',
                'aryeo_payments_moved', 'aryeo_shoot_files_DELETE', 'aryeo_shoot_files_INSERT', 'aryeo_shoot_files_UPDATE',
                'aryeo_shoot_files_moved'],
            // 2026_11_04_180100 installs every trigger of this file, on MySQL only.
            'mysql-cascade-triggers.json' => array_column($cascade, 'name'),
            // 2026_11_22_120200 creates it on MySQL only; SQLite relies on its foreign-key cascades.
            '2026_11_22_120200' => ['iguide_lab_project_delete_dependencies'],
        ];
    }

    /** @return array<string, array{columns: list<string>, indexes: list<string>}> */
    private static function baselineTables(): array
    {
        preg_match_all('/^CREATE TABLE `([^`]+)` \((.*?)^\)/ms', self::schema(), $tables, PREG_SET_ORDER);
        $result = [];
        foreach ($tables as [, $name, $body]) {
            preg_match_all('/^  `([^`]+)` /m', $body, $columns);
            preg_match_all('/^  (?:UNIQUE |FULLTEXT |SPATIAL )?KEY `([^`]+)`/m', $body, $indexes);
            // MySQL adds an index named after a foreign key whose columns have none; SQLite has no such index.
            preg_match_all('/^  CONSTRAINT `([^`]+)` FOREIGN KEY/m', $body, $foreignKeys);
            $result[$name] = ['columns' => $columns[1], 'indexes' => array_values(array_diff($indexes[1], $foreignKeys[1]))];
        }
        ksort($result);

        return $result;
    }

    /** @return array<string, array{baseline_only: list<string>, sqlite_only: list<string>}> */
    private static function difference(string $table, array $baseline, array $sqlite): array
    {
        $baselineOnly = array_values(array_diff($baseline, $sqlite));
        $sqliteOnly = array_values(array_diff($sqlite, $baseline));
        sort($baselineOnly);
        sort($sqliteOnly);

        return $baselineOnly === [] && $sqliteOnly === [] ? [] : [$table => ['baseline_only' => $baselineOnly, 'sqlite_only' => $sqliteOnly]];
    }

    private static function withoutReasons(array $reviewed): array
    {
        return array_map(fn (array $difference) => array_diff_key($difference, ['reason' => true]), $reviewed);
    }

    /** The MySQL name scripts/mysql/conversion.py index_statement() gives a SQLite index. */
    private static function convertedIndexName(string $table, string $name, array $columns): string
    {
        $bounded = fn (string $prefix, string $text) => substr($prefix.$text, 0, 49).'_'.substr(hash('sha256', $text), 0, 12);
        if (str_starts_with($name, 'sqlite_')) {
            return $bounded('unique_', $table.'_'.implode('_', $columns));
        }

        return strlen($name) > 64 ? $bounded('idx_', $name) : $name;
    }

    /**
     * Migrate a new temporary SQLite file in a child process, once per run, and read its
     * layout. The child gets its own database settings and never reads a cached config.
     *
     * @return array{tables: array<string, array{columns: list<string>, indexes: list<string>}>, triggers: list<string>}
     */
    private static function sqlite(): array
    {
        if (self::$sqlite !== null) {
            return self::$sqlite;
        }
        $root = dirname(__DIR__, 2);
        $file = tempnam(sys_get_temp_dir(), 'mysql-baseline-parity-');
        try {
            $process = new Process([PHP_BINARY, $root.'/artisan', 'migrate', '--force', '--no-interaction'], $root, [
                'APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $file, 'DB_URL' => '',
                'APP_CONFIG_CACHE' => $file.'-config.php',
            ]);
            $process->setTimeout(600);
            $process->run();
            self::assertTrue($process->isSuccessful(), 'The fresh SQLite migrate failed: '.$process->getErrorOutput().$process->getOutput());
            $db = new \PDO('sqlite:'.$file, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            self::assertSame(count(glob($root.'/database/migrations/*.php')), (int) $db->query('SELECT count(*) FROM migrations')->fetchColumn(),
                'The child did not migrate the temporary file.');
            $tables = [];
            foreach ($db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN) as $table) {
                $columns = $db->query('SELECT name FROM pragma_table_info('.$db->quote($table).')')->fetchAll(\PDO::FETCH_COLUMN);
                $indexes = [];
                foreach ($db->query('SELECT name, origin FROM pragma_index_list('.$db->quote($table).')')->fetchAll(\PDO::FETCH_ASSOC) as $index) {
                    if ($index['origin'] !== 'pk') { // the converter turns these into the MySQL primary key
                        $keys = $db->query('SELECT name FROM pragma_index_info('.$db->quote($index['name']).') ORDER BY seqno')->fetchAll(\PDO::FETCH_COLUMN);
                        $indexes[] = self::convertedIndexName($table, $index['name'], $keys);
                    }
                }
                $tables[$table] = ['columns' => $columns, 'indexes' => $indexes];
            }
            $triggers = $db->query("SELECT name FROM sqlite_master WHERE type = 'trigger' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
            $db = null;

            return self::$sqlite = ['tables' => $tables, 'triggers' => $triggers];
        } finally {
            @unlink($file);
        }
    }
}
