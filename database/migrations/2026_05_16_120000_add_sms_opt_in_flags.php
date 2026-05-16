<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            if (!Schema::hasColumn('contacts', 'sms_opt_out')) {
                $table->boolean('sms_opt_out')->default(false);
            }
            if (!Schema::hasColumn('contacts', 'sms_opt_out_at')) {
                $table->timestamp('sms_opt_out_at')->nullable();
            }
            if (!Schema::hasColumn('contacts', 'sms_ai_enabled')) {
                $table->boolean('sms_ai_enabled')->default(false);
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            if (!Schema::hasColumn('users', 'sms_opt_out')) {
                $table->boolean('sms_opt_out')->default(false);
            }
            if (!Schema::hasColumn('users', 'sms_opt_out_at')) {
                $table->timestamp('sms_opt_out_at')->nullable();
            }
            if (!Schema::hasColumn('users', 'sms_ai_enabled')) {
                $table->boolean('sms_ai_enabled')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            foreach (['sms_opt_out', 'sms_opt_out_at', 'sms_ai_enabled'] as $col) {
                if (Schema::hasColumn('contacts', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            foreach (['sms_opt_out', 'sms_opt_out_at', 'sms_ai_enabled'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
