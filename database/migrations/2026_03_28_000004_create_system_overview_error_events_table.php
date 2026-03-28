<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_overview_error_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('system_overview_session_id')->nullable()->constrained('system_overview_sessions')->nullOnDelete();
            $table->string('session_key')->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('trace_id')->nullable()->index();
            $table->string('source')->index();
            $table->string('severity')->nullable()->index();
            $table->string('route_path')->nullable()->index();
            $table->string('component_name')->nullable()->index();
            $table->string('blocker_type')->nullable()->index();
            $table->string('error_class')->nullable()->index();
            $table->text('message');
            $table->json('context_summary')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_overview_error_events');
    }
};
