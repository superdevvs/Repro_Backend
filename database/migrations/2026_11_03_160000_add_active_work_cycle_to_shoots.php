<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            $table->timestamp('active_work_started_at')->nullable();
            $table->timestamp('active_work_completed_at')->nullable();
            $table->timestamp('active_work_at')->nullable()->index();
            $table->date('active_work_date')->nullable()->index();
            $table->string('active_work_time', 5)->nullable();
        });
        Schema::table('shoot_service', fn (Blueprint $table) => $table->json('active_work_link_output')->nullable());
    }

    public function down(): void
    {
        Schema::table('shoot_service', fn (Blueprint $table) => $table->dropColumn('active_work_link_output'));
        Schema::table('shoots', function (Blueprint $table) {
            $table->dropIndex(['active_work_at']);
            $table->dropIndex(['active_work_date']);
        });
        Schema::table('shoots', fn (Blueprint $table) => $table->dropColumn([
            'active_work_started_at', 'active_work_completed_at', 'active_work_at', 'active_work_date', 'active_work_time',
        ]));
    }
};
