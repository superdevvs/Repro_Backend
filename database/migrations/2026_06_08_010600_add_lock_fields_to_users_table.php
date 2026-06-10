<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Locked account state (Req 16). account_status already exists; locked_at records
            // when the account was locked so the lifecycle service can derive/restore state.
            if (!Schema::hasColumn('users', 'locked_at')) {
                $table->timestamp('locked_at')->nullable()->after('account_status');
            }

            // Forced credential refresh on restore (Req 16.8/17).
            if (!Schema::hasColumn('users', 'password_reset_required')) {
                $table->boolean('password_reset_required')->default(false)->after('locked_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['password_reset_required', 'locked_at'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
