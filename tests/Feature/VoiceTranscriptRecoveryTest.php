<?php

namespace Tests\Feature;

use App\Jobs\RecoverVoiceTranscript;
use App\Models\User;
use App\Models\VoiceCall;
use App\Models\VoiceCallTranscript;
use App\Models\VoiceTranscriptRecovery;
use App\Services\Voice\VoiceTranscriptRecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class VoiceTranscriptRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $recordingStatus = 200;

    private bool $transcriptionTimeout = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telnyx.api_key' => 'test', 'services.telnyx.voice.browser_enabled' => true]);
        Queue::fake();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($this->admin, 'sanctum');
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/recordings/recording-test')) {
                return Http::response(['data' => ['download_urls' => ['mp3' => 'https://recordings.example.test/customer.mp3']]], $this->recordingStatus);
            }
            if (str_ends_with($request->url(), '/ai/audio/transcriptions')) {
                if ($this->transcriptionTimeout) {
                    throw new \Illuminate\Http\Client\ConnectionException('Timed out');
                }

                return Http::response(['text' => 'Complete recorded audio transcript.']);
            }
            throw new \RuntimeException('Unexpected provider request in test.');
        });
    }

    public function test_signed_recording_event_to_api_queue_recovery_and_replay_preserves_original_segments(): void
    {
        $call = $this->recordedCall();
        VoiceCallTranscript::create(['voice_call_id' => $call->id, 'provider_message_id' => 'telnyx:partial', 'speaker' => 'customer',
            'transcript_type' => 'final', 'text' => 'Original partial.', 'occurred_at' => now()->subMinutes(4)]);
        $call->update(['summary' => 'An earlier summary', 'summary_generated_at' => now()->subMinute()]);
        $this->getJson('/api/voice/calls/'.$call->id.'/transcript')->assertOk()->assertJsonPath('can_retry_recording', true);
        $this->postJson($this->url($call), ['idempotency_key' => 'recover', 'file_url' => 'http://127.0.0.1/private'])
            ->assertAccepted()->assertJsonPath('recovery.status', 'queued')->assertJsonPath('can_retry_recording', false);
        $this->postJson($this->url($call), ['idempotency_key' => 'recover'])->assertAccepted();
        $this->postJson($this->url($call), ['idempotency_key' => 'different-tab'])->assertAccepted();
        Queue::assertPushed(RecoverVoiceTranscript::class, 1);
        $recovery = VoiceTranscriptRecovery::firstOrFail();
        app(VoiceTranscriptRecoveryService::class)->run($recovery->id);
        app(VoiceTranscriptRecoveryService::class)->run($recovery->id);
        $this->getJson('/api/voice/calls/'.$call->id.'/transcript')->assertOk()->assertJsonPath('source', 'recording_recovery')
            ->assertJsonPath('transcript', 'Complete recorded audio transcript.')->assertJsonPath('recovery.status', 'succeeded')
            ->assertJsonPath('summary_stale', true)->assertJsonPath('can_retry_recording', false);
        $updatedAt = $call->fresh()->updated_at->toIso8601String();
        $this->travel(2)->seconds();
        $this->getJson('/api/voice/calls/'.$call->id.'/transcript')->assertOk();
        $this->assertSame($updatedAt, $call->fresh()->updated_at->toIso8601String(), 'Polling unchanged transcript must not rewrite the SQLite call row.');
        $this->assertDatabaseCount('voice_call_transcripts', 2);
        $this->assertDatabaseHas('voice_call_transcripts', ['provider_message_id' => 'telnyx:partial', 'text' => 'Original partial.']);
        $this->assertSame('recorded_customer_audio_only', data_get($call->fresh()->metadata, 'recording_recovery.scope'));
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/ai/audio/transcriptions')
            && $r->hasFile('file_url', 'https://recordings.example.test/customer.mp3') && $r->hasFile('model', 'openai/whisper-large-v3-turbo'));
        // A delayed original segment must not append duplicate speech to recovered text.
        VoiceCallTranscript::create(['voice_call_id' => $call->id, 'provider_message_id' => 'telnyx:late', 'speaker' => 'customer',
            'transcript_type' => 'final', 'text' => 'Late original words.', 'occurred_at' => now()]);
        $this->postJson('/api/voice/calls/'.$call->id.'/transcript/reconcile')->assertOk()->assertJsonPath('transcript', 'Complete recorded audio transcript.');
    }

    public function test_unverified_staff_or_conference_recordings_and_missing_consent_cannot_be_submitted(): void
    {
        $call = $this->recordedCall();
        $event = $call->events()->where('event_type', 'call.recording.saved')->firstOrFail();
        $payload = $event->raw_payload;
        $payload['data']['payload']['call_control_id'] = 'private-coaching-leg';
        $event->update(['raw_payload' => $payload]);
        $this->postJson($this->url($call), ['idempotency_key' => 'private'])->assertUnprocessable();
        $payload['data']['payload']['call_control_id'] = $call->call_control_id;
        $payload['data']['payload']['conference_id'] = 'whole-conference';
        $event->update(['raw_payload' => $payload]);
        $this->postJson($this->url($call), ['idempotency_key' => 'conference'])->assertUnprocessable();
        unset($payload['data']['payload']['conference_id']);
        $event->update(['raw_payload' => $payload, 'processed_at' => null]);
        $this->postJson($this->url($call), ['idempotency_key' => 'unverified'])->assertUnprocessable();
        $event->update(['processed_at' => now()]);
        $call->update(['recording_consent_given' => false]);
        $this->postJson($this->url($call), ['idempotency_key' => 'unconsented'])->assertUnprocessable();
        Http::assertNothingSent();
        Queue::assertNotPushed(RecoverVoiceTranscript::class);
    }

    public function test_initial_projection_does_not_mark_an_unchanged_existing_summary_stale(): void
    {
        $this->travelTo(now()->startOfSecond());
        $call = VoiceCall::create(['provider' => 'telnyx', 'direction' => 'INBOUND', 'status' => 'completed', 'ended_at' => now()->subMinutes(5),
            'transcript' => 'Previously saved text.', 'summary' => 'Current summary.', 'summary_generated_at' => now()]);
        $row = VoiceCallTranscript::create(['voice_call_id' => $call->id, 'provider_message_id' => 'assistant-message', 'speaker' => 'customer',
            'transcript_type' => 'final', 'text' => 'Previously saved text.', 'occurred_at' => now()->subMinutes(5)]);
        $row->forceFill(['created_at' => now()->subMinutes(4)])->save();
        $this->getJson('/api/voice/calls/'.$call->id.'/transcript')->assertOk()->assertJsonPath('summary_stale', false)
            ->assertJsonPath('transcript', 'Previously saved text.');
    }

    public function test_failed_recovery_retains_original_text_and_is_bounded_to_three_explicit_attempts(): void
    {
        $call = $this->recordedCall();
        $call->update(['transcript' => 'Original legacy text.']);
        $this->recordingStatus = 404;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->postJson($this->url($call), ['idempotency_key' => 'retry-'.$attempt])->assertAccepted();
            $recovery = VoiceTranscriptRecovery::where('attempt', $attempt)->firstOrFail();
            app(VoiceTranscriptRecoveryService::class)->run($recovery->id);
            $this->assertSame('failed', $recovery->fresh()->status);
        }
        $this->postJson($this->url($call), ['idempotency_key' => 'retry-4'])->assertUnprocessable();
        $this->getJson('/api/voice/calls/'.$call->id.'/transcript')->assertOk()->assertJsonPath('can_retry_recording', false)
            ->assertJsonPath('transcript', 'Original legacy text.')->assertJsonPath('recovery.attempt', 3);
        Http::assertSentCount(3);
    }

    public function test_worker_rechecks_permission_and_consent_and_stuck_work_becomes_explicitly_uncertain(): void
    {
        $call = $this->recordedCall();
        $this->postJson($this->url($call), ['idempotency_key' => 'permission'])->assertAccepted();
        $recovery = VoiceTranscriptRecovery::firstOrFail();
        $this->admin->update(['permission_overrides' => ['deny' => ['voice-calls-operate'], 'allow' => []]]);
        app(VoiceTranscriptRecoveryService::class)->run($recovery->id);
        $this->assertSame('failed', $recovery->fresh()->status);
        Http::assertNothingSent();
        $this->admin->update(['permission_overrides' => []]);
        $this->postJson($this->url($call), ['idempotency_key' => 'interrupted'])->assertAccepted();
        $stuck = VoiceTranscriptRecovery::where('attempt', 2)->firstOrFail();
        $stuck->update(['status' => 'processing', 'started_at' => now()->subMinutes(4)]);
        $this->artisan('voice-browser:reconcile')->assertSuccessful();
        $this->assertSame('uncertain', $stuck->fresh()->status);
        app(VoiceTranscriptRecoveryService::class)->run($stuck->id);
        Http::assertNothingSent();
        $this->getJson('/api/voice/calls/'.$call->id.'/transcript')->assertOk()->assertJsonPath('can_retry_recording', true);
    }

    public function test_provider_timeout_is_uncertain_without_an_automatic_paid_retry_and_queue_stall_is_visible(): void
    {
        $call = $this->recordedCall();
        $this->postJson($this->url($call), ['idempotency_key' => 'timeout'])->assertAccepted();
        $this->transcriptionTimeout = true;
        $recovery = VoiceTranscriptRecovery::firstOrFail();
        app(VoiceTranscriptRecoveryService::class)->run($recovery->id);
        $this->assertSame('uncertain', $recovery->fresh()->status);
        app(VoiceTranscriptRecoveryService::class)->run($recovery->id);
        Queue::assertPushed(RecoverVoiceTranscript::class, 1);
        $this->postJson($this->url($call), ['idempotency_key' => 'queue-stalled'])->assertAccepted();
        $queued = VoiceTranscriptRecovery::where('attempt', 2)->firstOrFail();
        $queued->update(['created_at' => now()->subMinutes(16)]);
        $this->artisan('voice-browser:reconcile')->assertSuccessful();
        $this->assertSame('failed', $queued->fresh()->status);
        $this->assertStringContainsString('queue worker', $queued->fresh()->error);
    }

    public function test_consent_revoked_after_queueing_never_submits_audio(): void
    {
        $call = $this->recordedCall();
        $this->postJson($this->url($call), ['idempotency_key' => 'revoke'])->assertAccepted();
        $call->update(['recording_consent_given' => false]);
        $recovery = VoiceTranscriptRecovery::firstOrFail();
        app(VoiceTranscriptRecoveryService::class)->run($recovery->id);
        $this->assertSame('failed', $recovery->fresh()->status);
        Http::assertNothingSent();
        $this->actingAs(User::factory()->create(['role' => 'client']), 'sanctum')->postJson($this->url($call), ['idempotency_key' => 'client'])->assertForbidden();
    }

    private function url(VoiceCall $call): string
    {
        return '/api/voice/calls/'.$call->id.'/transcript/retry';
    }

    private function recordedCall(): VoiceCall
    {
        $call = VoiceCall::create(['provider' => 'telnyx', 'direction' => 'INBOUND', 'status' => 'completed', 'ended_at' => now()->subMinutes(5),
            'call_control_id' => 'customer-recorded', 'recording_consent_given' => true]);
        $keys = sodium_crypto_sign_keypair();
        config(['services.telnyx.public_key' => base64_encode(sodium_crypto_sign_publickey($keys))]);
        $raw = json_encode(['data' => ['id' => (string) Str::uuid(), 'event_type' => 'call.recording.saved', 'payload' => [
            'call_control_id' => $call->call_control_id, 'recording_id' => 'recording-test',
            'recording_urls' => ['mp3' => 'https://recordings.example.test/old-signed-url.mp3'],
        ]]]);
        $timestamp = (string) time();
        $this->call('POST', '/api/webhooks/telnyx/voice', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_TELNYX_TIMESTAMP' => $timestamp, 'HTTP_TELNYX_SIGNATURE_ED25519' => base64_encode(sodium_crypto_sign_detached($timestamp.'|'.$raw, sodium_crypto_sign_secretkey($keys)))], $raw)->assertOk();

        return $call->fresh();
    }
}
