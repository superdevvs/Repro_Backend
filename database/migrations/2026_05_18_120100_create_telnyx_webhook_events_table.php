<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telnyx_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider')->default('TELNYX')->index();
            $table->string('channel')->index();
            $table->string('telnyx_event_id')->index();
            $table->string('event_type')->index();
            $table->timestamp('event_received_at')->nullable();
            $table->longText('raw_event_json')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->text('processing_error')->nullable();
            $table->foreignId('related_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->foreignId('related_voice_call_id')->nullable()->constrained('voice_calls')->nullOnDelete();
            $table->timestamps();

            $table->unique(['provider', 'telnyx_event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telnyx_webhook_events');
    }
};
