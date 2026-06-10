<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shoot_files', function (Blueprint $table) {
            // Upload virus-scan state machine (Req 14/15). Files land in quarantine and are
            // only released to processing once scanned clean.
            if (!Schema::hasColumn('shoot_files', 'scan_status')) {
                $table->enum('scan_status', ['quarantined', 'clean', 'infected', 'failed'])
                    ->default('quarantined');
                $table->index('scan_status', 'shoot_files_scan_status_index');
            }
            if (!Schema::hasColumn('shoot_files', 'scan_result')) {
                $table->text('scan_result')->nullable();
            }
            if (!Schema::hasColumn('shoot_files', 'scanned_at')) {
                $table->timestamp('scanned_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('shoot_files', function (Blueprint $table) {
            if (Schema::hasColumn('shoot_files', 'scan_status')) {
                try {
                    $table->dropIndex('shoot_files_scan_status_index');
                } catch (\Throwable) {
                    // index may not exist on some drivers; ignore
                }
            }
            foreach (['scanned_at', 'scan_result', 'scan_status'] as $column) {
                if (Schema::hasColumn('shoot_files', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
