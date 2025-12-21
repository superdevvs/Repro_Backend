<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('shoots', 'editor_id')) {
            Schema::table('shoots', function (Blueprint $table) {
                $table->unsignedBigInteger('editor_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('shoots', 'editor_id')) {
            Schema::table('shoots', function (Blueprint $table) {
                $table->dropIndex(['editor_id']);
                $table->dropColumn('editor_id');
            });
        }
    }
};
