<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'two_factor_secret' => ! Schema::hasColumn('users', 'two_factor_secret'),
            'two_factor_recovery_codes' => ! Schema::hasColumn('users', 'two_factor_recovery_codes'),
            'two_factor_confirmed_at' => ! Schema::hasColumn('users', 'two_factor_confirmed_at'),
            'two_factor_last_used_step' => ! Schema::hasColumn('users', 'two_factor_last_used_step'),
            'password_changed_at' => ! Schema::hasColumn('users', 'password_changed_at'),
        ];

        if (! in_array(true, $columns, true)) {
            return;
        }

        Schema::table('users', function (Blueprint $table) use ($columns) {
            if ($columns['two_factor_secret']) {
                $table->text('two_factor_secret')->nullable()->after('password');
            }
            if ($columns['two_factor_recovery_codes']) {
                $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            }
            if ($columns['two_factor_confirmed_at']) {
                $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
            }
            if ($columns['two_factor_last_used_step']) {
                $table->unsignedBigInteger('two_factor_last_used_step')->nullable()->after('two_factor_confirmed_at');
            }
            if ($columns['password_changed_at']) {
                $table->timestamp('password_changed_at')->nullable()->after('two_factor_last_used_step');
            }
        });
    }

    public function down(): void
    {
        $columns = collect([
            'two_factor_secret',
            'two_factor_recovery_codes',
            'two_factor_confirmed_at',
            'two_factor_last_used_step',
            'password_changed_at',
        ])->filter(fn (string $column) => Schema::hasColumn('users', $column))->all();

        if ($columns !== []) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
