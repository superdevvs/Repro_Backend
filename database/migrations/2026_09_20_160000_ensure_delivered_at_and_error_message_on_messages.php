<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('messages', 'delivered_at')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->timestamp('delivered_at')->nullable();
            });
        }

        if (! Schema::hasColumn('messages', 'error_message')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->text('error_message')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive: the base messaging migration owns these
        // columns, so the guard does not drop them on rollback.
    }
};
