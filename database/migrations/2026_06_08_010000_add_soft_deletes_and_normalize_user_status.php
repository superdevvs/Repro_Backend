<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            DB::table('users')
                ->where(function ($query) {
                    $query->whereNull('account_status')
                        ->orWhere('account_status', '');
                })
                ->update(['account_status' => 'active']);

            Schema::table('users', function (Blueprint $table) {
                if (!Schema::hasColumn('users', 'deleted_at')) {
                    $table->softDeletes()->after('remember_token');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'deleted_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
