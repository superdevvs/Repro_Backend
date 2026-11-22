<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        $column = collect(Schema::getColumns('invoices'))->firstWhere('name', 'role');
        if ($column && ! $column['nullable']) {
            $default = DB::table('information_schema.COLUMNS')->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
                ->where('TABLE_NAME', 'invoices')->where('COLUMN_NAME', 'role')->value('COLUMN_DEFAULT');
            // The historical nullable-role migration skipped SQLite, but its
            // applied ledger was carried into MySQL. Complete that contract.
            Schema::table('invoices', function (Blueprint $table) use ($default): void {
                $table->string('role')->nullable()->default($default)->change();
            });
        }
    }

    public function down(): void
    {
        // Preserve any supported legacy invoices subsequently saved with no role.
    }
};
