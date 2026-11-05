<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        $expression = 'CAST(COALESCE(billing_period_start, issue_date, period_start, created_at) AS DATETIME(6))';
        if (Schema::hasIndex('invoices', 'invoices_effective_period_sort')) {
            $existing = DB::table('information_schema.STATISTICS')->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
                ->where('TABLE_NAME', 'invoices')->where('INDEX_NAME', 'invoices_effective_period_sort')->where('SEQ_IN_INDEX', 1)->value('EXPRESSION');
            $normalize = fn ($value) => strtolower(preg_replace('/[\s`]+/', '', (string) $value));
            if ($normalize($existing) === $normalize($expression)) {
                return;
            }
            Schema::table('invoices', fn ($table) => $table->dropIndex('invoices_effective_period_sort'));
        }
        // The explicit type also avoids a MySQL hidden-column zero default when
        // mysqldump creates this index under its temporary relaxed SQL mode.
        DB::statement('CREATE INDEX invoices_effective_period_sort ON invoices (('.$expression.') DESC, id DESC)');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql' && Schema::hasIndex('invoices', 'invoices_effective_period_sort')) {
            Schema::table('invoices', fn ($table) => $table->dropIndex('invoices_effective_period_sort'));
        }
    }
};
