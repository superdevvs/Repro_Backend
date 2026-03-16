<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shoot_files', function (Blueprint $table) {
            if (!Schema::hasColumn('shoot_files', 'is_hidden')) {
                $table->boolean('is_hidden')->default(false)->after('is_favorite');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shoot_files', function (Blueprint $table) {
            if (Schema::hasColumn('shoot_files', 'is_hidden')) {
                $table->dropColumn('is_hidden');
            }
        });
    }
};
