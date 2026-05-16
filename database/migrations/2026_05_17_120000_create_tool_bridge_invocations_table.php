<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tool_bridge_invocations', function (Blueprint $table): void {
            $table->id();
            $table->string('tool')->index();
            $table->string('channel')->index();
            $table->string('phone_e164')->nullable()->index();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('telnyx_event_id')->nullable()->index();
            $table->string('telnyx_conversation_id')->nullable()->index();
            $table->string('call_control_id')->nullable()->index();
            $table->string('idempotency_key')->unique();
            $table->string('status')->index();
            $table->string('error_code')->nullable()->index();
            $table->unsignedInteger('latency_ms')->default(0);
            $table->json('request_json')->nullable();
            $table->json('response_json')->nullable();
            $table->json('metadata')->nullable();
            $table->string('raw_request_path')->nullable();
            $table->string('raw_response_path')->nullable();
            $table->timestamps();

            $table->index(['telnyx_event_id', 'tool']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tool_bridge_invocations');
    }
};
