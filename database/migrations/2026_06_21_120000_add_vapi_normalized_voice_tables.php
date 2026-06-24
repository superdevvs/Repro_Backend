<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_calls', function (Blueprint $table): void {
            if (!Schema::hasColumn('voice_calls', 'provider')) {
                $table->string('provider')->default('telnyx')->after('id')->index();
            }
            if (!Schema::hasColumn('voice_calls', 'vapi_call_id')) {
                $table->string('vapi_call_id')->nullable()->after('provider')->index();
            }
            if (!Schema::hasColumn('voice_calls', 'vapi_phone_number_id')) {
                $table->string('vapi_phone_number_id')->nullable()->after('vapi_call_id')->index();
            }
            if (!Schema::hasColumn('voice_calls', 'handled_by')) {
                $table->string('handled_by')->nullable()->after('status')->index();
            }
            if (!Schema::hasColumn('voice_calls', 'external_provider_status')) {
                $table->string('external_provider_status')->nullable()->after('callback_status')->index();
            }
            if (!Schema::hasColumn('voice_calls', 'provider_event_last_seen_at')) {
                $table->timestamp('provider_event_last_seen_at')->nullable()->after('external_provider_status');
            }
            if (!Schema::hasColumn('voice_calls', 'vapi_ended_reason')) {
                $table->string('vapi_ended_reason')->nullable()->after('provider_event_last_seen_at')->index();
            }
            if (!Schema::hasColumn('voice_calls', 'telnyx_failure_code')) {
                $table->string('telnyx_failure_code')->nullable()->after('vapi_ended_reason')->index();
            }
            if (!Schema::hasColumn('voice_calls', 'carrier_failure_reason')) {
                $table->text('carrier_failure_reason')->nullable()->after('telnyx_failure_code');
            }
            if (!Schema::hasColumn('voice_calls', 'ai_current_state')) {
                $table->string('ai_current_state')->nullable()->after('carrier_failure_reason')->index();
            }
            if (!Schema::hasColumn('voice_calls', 'ai_current_speaker')) {
                $table->string('ai_current_speaker')->nullable()->after('ai_current_state')->index();
            }
            if (!Schema::hasColumn('voice_calls', 'live_transcript_preview')) {
                $table->text('live_transcript_preview')->nullable()->after('ai_current_speaker');
            }
            if (!Schema::hasColumn('voice_calls', 'sentiment')) {
                $table->string('sentiment')->nullable()->after('summary')->index();
            }
            if (!Schema::hasColumn('voice_calls', 'booking_probability')) {
                $table->string('booking_probability')->nullable()->after('sentiment')->index();
            }
            if (!Schema::hasColumn('voice_calls', 'needs_follow_up')) {
                $table->boolean('needs_follow_up')->default(false)->after('booking_probability')->index();
            }
            if (!Schema::hasColumn('voice_calls', 'summary_generated_at')) {
                $table->timestamp('summary_generated_at')->nullable()->after('needs_follow_up');
            }
            if (!Schema::hasColumn('voice_calls', 'recording_provider')) {
                $table->string('recording_provider')->nullable()->after('recording_url')->index();
            }
            if (!Schema::hasColumn('voice_calls', 'answered_at')) {
                $table->timestamp('answered_at')->nullable()->after('started_at');
            }
        });

        Schema::create('voice_call_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('voice_call_id')->nullable()->constrained('voice_calls')->nullOnDelete();
            $table->string('provider')->index();
            $table->string('event_type')->index();
            $table->string('normalized_type')->nullable()->index();
            $table->json('raw_payload')->nullable();
            $table->timestamp('received_at')->nullable()->index();
            $table->timestamp('processed_at')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->text('processing_error')->nullable();
            $table->timestamps();
        });

        Schema::create('voice_call_transcripts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('voice_call_id')->constrained('voice_calls')->cascadeOnDelete();
            $table->string('provider_message_id')->nullable()->index();
            $table->string('speaker')->index();
            $table->string('transcript_type')->default('final')->index();
            $table->longText('text');
            $table->decimal('confidence', 5, 4)->nullable();
            $table->timestamp('occurred_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('voice_call_tool_invocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('voice_call_id')->nullable()->constrained('voice_calls')->nullOnDelete();
            $table->string('tool_name')->index();
            $table->string('provider_tool_call_id')->nullable()->index();
            $table->string('status')->default('pending')->index();
            $table->json('input_payload')->nullable();
            $table->json('output_payload')->nullable();
            $table->boolean('requires_confirmation')->default(false)->index();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_call_tool_invocations');
        Schema::dropIfExists('voice_call_transcripts');
        Schema::dropIfExists('voice_call_events');

        Schema::table('voice_calls', function (Blueprint $table): void {
            foreach ([
                'answered_at',
                'recording_provider',
                'summary_generated_at',
                'needs_follow_up',
                'booking_probability',
                'sentiment',
                'live_transcript_preview',
                'ai_current_speaker',
                'ai_current_state',
                'carrier_failure_reason',
                'telnyx_failure_code',
                'vapi_ended_reason',
                'provider_event_last_seen_at',
                'external_provider_status',
                'handled_by',
                'vapi_phone_number_id',
                'vapi_call_id',
                'provider',
            ] as $column) {
                if (Schema::hasColumn('voice_calls', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
