<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('templates', function (Blueprint $table): void {
            // Null preserves the existing editor-owned / privileged-team contract.
            // New API writes explicitly default to private; never publish old recipes.
            $table->string('visibility', 16)->nullable();
            $table->string('preset_id', 64)->nullable();
            $table->index(['team_id', 'visibility', 'created_by']);
        });
    }

    public function down(): void
    {
        Schema::table('templates', function (Blueprint $table): void {
            $table->dropIndex(['team_id', 'visibility', 'created_by']);
            $table->dropColumn(['visibility', 'preset_id']);
        });
    }
};
