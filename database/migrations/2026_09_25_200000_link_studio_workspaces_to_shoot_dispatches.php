<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('studio_workspaces', function (Blueprint $table) {
            $table->foreignId('shoot_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('parent_workspace_id')->nullable()->index();
            $table->string('shoot_dispatch_key', 64)->nullable();
            $table->string('shoot_dispatch_hash', 64)->nullable();
            $table->json('shoot_service_ids')->nullable();
            $table->unique(['shoot_id', 'shoot_dispatch_key', 'preset_id'], 'studio_shoot_dispatch_unique');
        });
    }

    public function down(): void
    {
        Schema::table('studio_workspaces', function (Blueprint $table) {
            $table->dropUnique('studio_shoot_dispatch_unique');
            $table->dropColumn(['shoot_id', 'parent_workspace_id', 'shoot_dispatch_key', 'shoot_dispatch_hash', 'shoot_service_ids']);
        });
    }
};
