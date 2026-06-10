<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            if (!Schema::hasColumn('shoots', 'cubicasa_sync_status')) {
                $table->string('cubicasa_sync_status')->nullable()->after('cubicasa_last_status_at');
            }
            if (!Schema::hasColumn('shoots', 'cubicasa_sync_job_id')) {
                $table->string('cubicasa_sync_job_id')->nullable()->after('cubicasa_sync_status');
            }
            if (!Schema::hasColumn('shoots', 'cubicasa_sync_started_at')) {
                $table->timestamp('cubicasa_sync_started_at')->nullable()->after('cubicasa_sync_job_id');
            }
            if (!Schema::hasColumn('shoots', 'cubicasa_last_sync_error')) {
                $table->text('cubicasa_last_sync_error')->nullable()->after('cubicasa_sync_started_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            foreach ([
                'cubicasa_last_sync_error',
                'cubicasa_sync_started_at',
                'cubicasa_sync_job_id',
                'cubicasa_sync_status',
            ] as $column) {
                if (Schema::hasColumn('shoots', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
