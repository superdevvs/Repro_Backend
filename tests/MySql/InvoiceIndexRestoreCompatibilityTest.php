<?php

namespace Tests\MySql;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\FreshDatabaseOutsideTransaction;
use Tests\TestCase;

final class InvoiceIndexRestoreCompatibilityTest extends TestCase
{
    use FreshDatabaseOutsideTransaction;

    public function test_dump_created_invoice_index_permits_a_later_unrelated_column_change(): void
    {
        $definition = (array) DB::selectOne('SHOW CREATE TABLE invoices');
        $columns = array_values(array_filter(explode("\n", $definition['Create Table']),
            fn ($line) => preg_match('/^\s*`(?:id|role|billing_period_start|issue_date|period_start|created_at)` /', $line)));
        $this->assertCount(6, $columns);
        $columns = array_map(fn ($line) => rtrim(trim($line), ','), $columns);
        $expression = DB::table('information_schema.STATISTICS')->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', 'invoices')->where('INDEX_NAME', 'invoices_effective_period_sort')->where('SEQ_IN_INDEX', 1)->value('EXPRESSION');
        $this->assertNotNull($expression);
        $pdo = DB::connection()->getPdo();
        $mode = $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        $this->assertMatchesRegularExpression('/\A[A-Z_,]+\z/', $mode);
        try {
            // Standard mysqldump temporarily relaxes SQL mode while rebuilding
            // tables. Probe the candidate's real expression, not a test copy.
            $pdo->exec("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
            $pdo->exec('CREATE TABLE _invoice_index_restore_probe ('.implode(',', $columns)
                .',PRIMARY KEY(id),KEY invoices_effective_period_sort (('.$expression.') DESC,id DESC)) ENGINE=InnoDB');
            $pdo->exec("SET SESSION sql_mode = '".$mode."'");
            $pdo->exec('ALTER TABLE _invoice_index_restore_probe MODIFY role VARCHAR(300) NULL');
            $changed = collect(Schema::getColumns('_invoice_index_restore_probe'))->firstWhere('name', 'role');
            $this->assertTrue($changed['nullable']);
            $this->assertStringContainsString('300', $changed['type']);
        } finally {
            $pdo->exec("SET SESSION sql_mode = '".$mode."'");
            $pdo->exec('DROP TABLE IF EXISTS _invoice_index_restore_probe');
        }
    }
}
