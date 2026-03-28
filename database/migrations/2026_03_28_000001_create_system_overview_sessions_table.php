<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_overview_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('session_key')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->string('user_role')->nullable();
            $table->boolean('is_authenticated')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('current_route')->nullable()->index();
            $table->string('current_page')->nullable();
            $table->string('current_action')->nullable();
            $table->json('component_stack')->nullable();
            $table->string('blocker_state')->nullable();
            $table->text('blocker_message')->nullable();
            $table->string('last_api_path')->nullable();
            $table->string('last_trace_id')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('last_activity_at')->nullable()->index();
            $table->timestamp('ended_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_overview_sessions');
    }
};
