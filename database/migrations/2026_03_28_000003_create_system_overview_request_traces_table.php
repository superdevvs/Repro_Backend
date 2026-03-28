<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_overview_request_traces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('system_overview_session_id')->nullable()->constrained('system_overview_sessions')->nullOnDelete();
            $table->string('session_key')->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('trace_id')->unique();
            $table->string('domain')->nullable()->index();
            $table->string('route_name')->nullable()->index();
            $table->string('method', 12)->index();
            $table->string('path')->index();
            $table->string('current_route')->nullable()->index();
            $table->string('controller_action')->nullable()->index();
            $table->unsignedSmallInteger('status_code')->nullable()->index();
            $table->unsignedInteger('duration_ms')->default(0)->index();
            $table->unsignedInteger('request_bytes')->default(0);
            $table->unsignedInteger('response_bytes')->default(0);
            $table->string('blocker_type')->nullable()->index();
            $table->text('blocker_message')->nullable();
            $table->string('error_class')->nullable()->index();
            $table->json('request_payload_summary')->nullable();
            $table->json('response_payload_summary')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_overview_request_traces');
    }
};
