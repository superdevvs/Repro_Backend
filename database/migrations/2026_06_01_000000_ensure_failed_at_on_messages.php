<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('messages', 'failed_at')) {
            return; // Idempotent no-op: column already present (Req 1.2)
        }

        Schema::table('messages', function (Blueprint $table) {
            // Add-only operation is supported by the SQLite driver (Req 1.4)
            $table->timestamp('failed_at')->nullable()->after('delivered_at');
        });
    }

    public function down(): void
    {
        // Intentionally non-destructive: the base messaging migration owns this column,
        // so the guard does not drop it on rollback to avoid data loss / drift.
    }
};
