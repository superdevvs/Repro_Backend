<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        // MySQL does not execute child triggers for FK cascades. Notify each
        // affected shoot before the parent disappears, including transitive
        // cascades and allocations attached to invoice-only payments.
        $definitions = json_decode(file_get_contents(database_path('schema/mysql-cascade-triggers.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach ($definitions as $trigger) {
            DB::unprepared('DROP TRIGGER IF EXISTS `'.$trigger['name'].'`');
            DB::unprepared($trigger['sql']);
        }
    }

    public function down(): void
    {
        // Retain change-feed coverage during application rollback.
    }
};
