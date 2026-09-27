<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('studio_workspaces', function (Blueprint $table) {
            $table->json('shoot_service_item_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('studio_workspaces', fn (Blueprint $table) => $table->dropColumn('shoot_service_item_ids'));
    }
};
