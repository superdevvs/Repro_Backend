<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite did not enforce VARCHAR length. Imported source labels are
        // historical evidence; only the public room-name projection is bounded.
        if (DB::getDriverName() !== 'mysql' || Schema::getColumnType('iguide_lab_cases', 'label') === 'longtext') return;
        Schema::table('iguide_lab_cases', fn (Blueprint $table) => $table->longText('label')->change());
    }

    public function down(): void
    {
        // The wider string storage remains compatible with the prior app.
        // Preserve historical labels when rolling code back; never narrow them.
    }
};
