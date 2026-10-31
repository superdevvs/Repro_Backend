<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('studio_workspaces', function (Blueprint $table): void {
            $table->json('access_media')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('studio_workspaces', function (Blueprint $table): void {
            $table->dropColumn('access_media');
        });
    }
};
