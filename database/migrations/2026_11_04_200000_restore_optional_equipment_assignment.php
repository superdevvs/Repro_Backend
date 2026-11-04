<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasTable('photographer_equipments')) {
            return;
        }
        $column = collect(Schema::getColumns('photographer_equipments'))->firstWhere('name', 'photographer_id');
        if ($column && ! $column['nullable']) {
            // The live legacy schema retained a mandatory owner, while current
            // validation and fresh migrations allow equipment awaiting assignment.
            // Preserve every saved owner and the existing FK/delete behavior.
            Schema::table('photographer_equipments', function (Blueprint $table) use ($column): void {
                $table->bigInteger('photographer_id', false, str_contains($column['type'], 'unsigned'))->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Keep equipment saved without an owner; never invent an assignment.
    }
};
