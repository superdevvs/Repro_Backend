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

    private array $transcriptionError = [];

    private mixed $transcriptionText = 'Complete recorded audio transcript.';

    private string $downloadUrl = 'https://s3.amazonaws.com/telephony-recorder-prod/account/customer.mp3?signature=secret';

    private string $audio = "ID3\x04\x00\x00\x00\x00\x00\x00test-audio";

    private int $downloadStatus = 200;

    private array $downloadHeaders = ['Content-Type' => 'audio/mpeg'];

    private bool $downloadTimeout = false;

    private int $downloadChunks = 1;

    private array $downloadRequests = [];

    private ?string $temporaryPath = null;

    private $afterDownload = null;

    private $afterTranscription = null;

    private bool $useWav = false;

    private int $writtenBytes = 0;

    private array $providerOptions = [];

    private string $submittedBody = '';

    private array $metadataOverrides = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telnyx.api_key' => 'test', 'services.telnyx.voice.browser_enabled' => true]);
        Queue::fake();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($this->admin, 'sanctum');
        Http::preventStrayRequests();
        $transport = new \GuzzleHttp\Client(['handler' => function ($request, $options) {
            $this->downloadRequests[] = ['request' => $request, 'options' => $options];
            $this->temporaryPath = $options['sink']->getMetadata('uri');
            $this->assertSame(0600, fileperms($this->temporaryPath) & 0777);
            if ($this->downloadTimeout) {
                throw new \GuzzleHttp\Exception\ConnectException('Connection timeout with private URL', $request);
            }
            $response = new \GuzzleHttp\Psr7\Response($this->downloadStatus, $this->downloadHeaders);
            $options['on_headers']($response);
            for ($i = 0; $i < $this->downloadChunks; $i++) {
                $this->writtenBytes += $options['sink']->write($this->audio);
            }
            if ($this->afterDownload) {
                ($this->afterDownload)();
            }

            return \GuzzleHttp\Promise\Create::promiseFor($response);
        }]);
        $this->app->instance(VoiceTranscriptRecoveryService::class, new class($transport) extends VoiceTranscriptRecoveryService
        {
            public function __construct(private \GuzzleHttp\ClientInterface $transport) {}

            protected function recordingClient(): \GuzzleHttp\ClientInterface
            {
                return $this->transport;
            }
        });
        Http::fake(function ($request, $options) {
            $this->providerOptions[] = $options;
            if (str_ends_with($request->url(), '/recordings/recording-test')) {
                return Http::response(['data' => array_merge(['id' => 'recording-test', 'call_control_id' => 'customer-recorded',
                    'download_urls' => [$this->useWav ? 'wav' : 'mp3' => $this->downloadUrl]], $this->metadataOverrides)], $this->recordingStatus);
            }
            if (str_ends_with($request->url(), '/ai/audio/transcriptions')) {
                $this->submittedBody = $request->body();
                if ($this->transcriptionTimeout) {
                    throw new \Illuminate\Http\Client\ConnectionException('Timed out');
                }

                if ($this->transcriptionError !== []) {
                    return Http::response($this->transcriptionError, 400, ['x-request-id' => 'provider-request-123']);
                }
                if ($this->afterTranscription) {
                    ($this->afterTranscription)();
                }

                return Http::response(['text' => $this->transcriptionText]);
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
            && $r->hasFile('file', null, 'recording.mp3') && $r->hasFile('model', 'openai/whisper-large-v3-turbo'));
        $this->assertStringContainsString($this->audio, $this->submittedBody);
        $this->assertStringContainsString('Content-Type: audio/mpeg', $this->submittedBody);
        $this->assertStringNotContainsString('file_url', $this->submittedBody);
        $this->assertStringNotContainsString('signature=secret', $this->submittedBody);
        $this->assertCount(1, $this->downloadRequests);
        $this->assertFalse($this->downloadRequests[0]['request']->hasHeader('Authorization'));
        $this->assertFalse($this->downloadRequests[0]['options']['allow_redirects']);
        $this->assertTrue($this->downloadRequests[0]['options']['verify']);
        $this->assertSame(10, $this->downloadRequests[0]['options']['timeout']);
        $this->assertSame(35, $this->providerOptions[1]['timeout']);
        $this->assertFalse($this->providerOptions[0]['allow_redirects']);
        $this->assertFalse($this->providerOptions[1]['allow_redirects']);
        $this->assertFileDoesNotExist($this->temporaryPath);
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

    public function test_provider_rejection_persists_only_fixed_classification_without_private_provider_prose(): void
    {
        config(['services.telnyx.api_key' => 'provider-secret']);
        $call = $this->recordedCall();
        $this->transcriptionError = ['errors' => [[
            'code' => 'invalid_audio', 'title' => 'Download rejected',
            'detail' => 'Cannot load https://recordings.example.test/private.mp3?signature=secret using provider-secret for +13016375700 and person@example.test',
            'request' => ['file_url' => 'must-not-log', 'authorization' => 'must-not-log'],
        ]], 'request_body' => 'must-not-log'];
        $this->postJson($this->url($call), ['idempotency_key' => 'diagnosable-failure'])->assertAccepted();
        $recovery = VoiceTranscriptRecovery::firstOrFail();
        app(VoiceTranscriptRecoveryService::class)->run($recovery->id);
        $this->assertSame('failed', $recovery->fresh()->status);
        $this->assertSame(1, $recovery->fresh()->attempt);
        $this->assertStringNotContainsString('private.mp3', $recovery->fresh()->error);
        $this->assertSame('The transcription provider rejected recovery (HTTP 400; invalid_audio). Original segments were preserved.', $recovery->fresh()->error);
        $this->assertFileDoesNotExist($this->temporaryPath);
        $this->assertDatabaseCount('voice_call_transcripts', 0);
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

    #[\PHPUnit\Framework\Attributes\DataProvider('unapprovedRecordingUrls')]
    public function test_unapproved_recording_origins_never_download_or_submit(string $url): void
    {
        $this->downloadUrl = $url;
        $recovery = $this->recover($this->recordedCall());
        $this->assertSame('failed', $recovery->status);
        $this->assertStringContainsString('[recording_origin_unapproved]', $recovery->error);
        $this->assertCount(0, $this->downloadRequests);
        Http::assertSentCount(1);
    }

    public static function unapprovedRecordingUrls(): array
    {
        return array_map(fn ($url) => [$url], [
            'http://s3.amazonaws.com/telephony-recorder-prod/file.mp3',
            'https://127.0.0.1/telephony-recorder-prod/file.mp3',
            'https://s3.amazonaws.com.attacker.test/telephony-recorder-prod/file.mp3',
            'https://s3.amazonaws.com/different-bucket/file.mp3',
            'https://s3.amazonaws.com:444/telephony-recorder-prod/file.mp3',
            'https://user:secret@s3.amazonaws.com/telephony-recorder-prod/file.mp3',
            'https://s3.amazonaws.com/telephony-recorder-prod/%2e%2e/file.mp3',
            'https://s3.amazonaws.com/telephony-recorder-prod/file.mp3#fragment',
        ]);
    }

    public function test_wav_binary_filename_mime_and_cleanup(): void
    {
        $this->useWav = true;
        $this->audio = "RIFF\x14\x00\x00\x00WAVEfmt test-audio";
        $this->downloadHeaders = ['Content-Type' => 'audio/x-wav', 'Content-Length' => (string) strlen($this->audio)];
        $recovery = $this->recover($this->recordedCall());
        $this->assertSame('succeeded', $recovery->status);
        Http::assertSent(fn ($request) => $request->hasFile('file', null, 'recording.wav'));
        $this->assertStringContainsString($this->audio, $this->submittedBody);
        $this->assertStringContainsString('Content-Type: audio/wav', $this->submittedBody);
        $this->assertFileDoesNotExist($this->temporaryPath);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidDownloads')]
    public function test_download_failure_cannot_submit_audio_and_cleans_private_file(string $mode, string $code): void
    {
        if ($mode === 'redirect') {
            $this->downloadStatus = 302;
            $this->downloadHeaders['Location'] = 'http://127.0.0.1/private';
        }
        if ($mode === 'size_header') {
            $this->downloadHeaders['Content-Length'] = '100000001';
        }
        if ($mode === 'incomplete') {
            $this->downloadHeaders['Content-Length'] = '999';
        }
        if ($mode === 'html') {
            $this->audio = '<html>private carrier error</html>';
            $this->downloadHeaders['Content-Type'] = 'text/html';
        }
        if ($mode === 'wrong_magic') {
            $this->audio = '<html>not audio</html>';
        }
        if ($mode === 'empty') {
            $this->audio = '';
        }
        if ($mode === 'timeout') {
            $this->downloadTimeout = true;
        }
        $recovery = $this->recover($this->recordedCall());
        $this->assertSame('failed', $recovery->status);
        $this->assertStringContainsString('['.$code.']', $recovery->error);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('voice_call_transcripts', 0);
        $this->assertFileDoesNotExist($this->temporaryPath);
    }

    public static function invalidDownloads(): array
    {
        return [['redirect', 'audio_download_http'], ['size_header', 'audio_download_too_large'], ['incomplete', 'audio_download_incomplete'],
            ['html', 'audio_format_invalid'], ['wrong_magic', 'audio_format_invalid'], ['empty', 'audio_download_incomplete'], ['timeout', 'audio_download_timeout']];
    }

    public function test_unknown_length_stream_is_hard_capped_before_an_over_limit_write(): void
    {
        $this->audio = 'ID3'.str_repeat('a', 999997);
        $this->downloadChunks = 101;
        $recovery = $this->recover($this->recordedCall());
        $this->assertSame('failed', $recovery->status);
        $this->assertStringContainsString('[audio_download_too_large]', $recovery->error);
        $this->assertSame(100000000, $this->writtenBytes);
        Http::assertSentCount(1);
        $this->assertFileDoesNotExist($this->temporaryPath);
    }

    public function test_exact_size_limit_is_downloaded_but_revoked_consent_still_prevents_upload(): void
    {
        $call = $this->recordedCall();
        $this->audio = 'ID3'.str_repeat('a', 999997);
        $this->downloadChunks = 100;
        $this->downloadHeaders['Content-Length'] = '100000000';
        $this->afterDownload = fn () => $call->update(['recording_consent_given' => false]);
        $recovery = $this->recover($call);
        $this->assertSame(100000000, $this->writtenBytes);
        $this->assertSame('Consent, recording or staff permission changed; no audio was submitted.', $recovery->error);
        Http::assertSentCount(1);
        $this->assertFileDoesNotExist($this->temporaryPath);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mismatchedMetadata')]
    public function test_fresh_carrier_metadata_must_match_customer_recording(array $overrides): void
    {
        $this->metadataOverrides = $overrides;
        $recovery = $this->recover($this->recordedCall());
        $this->assertStringContainsString('[recording_metadata_mismatch]', $recovery->error);
        $this->assertCount(0, $this->downloadRequests);
        Http::assertSentCount(1);
    }

    public static function mismatchedMetadata(): array
    {
        return [[['id' => 'other-recording']], [['call_control_id' => 'staff-leg']], [['conference_id' => 'private-conference']]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('guardChanges')]
    public function test_authority_is_rechecked_after_download_and_provider_response(string $phase, string $guard): void
    {
        $call = $this->recordedCall();
        $change = function () use ($call, $guard) {
            if ($guard === 'consent') {
                $call->update(['recording_consent_given' => false]);
            }
            if ($guard === 'permission') {
                $this->admin->update(['permission_overrides' => ['deny' => ['voice-calls-operate'], 'allow' => []]]);
            }
            if ($guard === 'recording') {
                $call->update(['metadata' => array_merge($call->metadata ?? [], ['recording_id' => 'different-recording'])]);
            }
        };
        if ($phase === 'download') {
            $this->afterDownload = $change;
        } else {
            $this->afterTranscription = $change;
        }
        $recovery = $this->recover($call);
        $this->assertSame('failed', $recovery->status);
        $this->assertDatabaseCount('voice_call_transcripts', 0);
        Http::assertSentCount($phase === 'download' ? 1 : 2);
        $this->assertFileDoesNotExist($this->temporaryPath);
    }

    public static function guardChanges(): array
    {
        return [['download', 'consent'], ['download', 'permission'], ['download', 'recording'],
            ['transcription', 'consent'], ['transcription', 'permission'], ['transcription', 'recording']];
    }

    public function test_arbitrary_provider_error_code_is_not_persisted(): void
    {
        $this->transcriptionError = ['error' => ['code' => 'secret phone +13016375700 https://private.test/audio', 'message' => 'private speech']];
        $recovery = $this->recover($this->recordedCall());
        $this->assertSame('The transcription provider rejected recovery (HTTP 400; transcription_rejected). Original segments were preserved.', $recovery->error);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unusableTranscriptionTexts')]
    public function test_unusable_provider_text_is_not_converted_to_speech(mixed $text): void
    {
        $this->transcriptionText = $text;
        $call = $this->recordedCall();
        $call->update(['transcript' => 'Original saved speech.']);
        $recovery = $this->recover($call);
        $this->assertSame('failed', $recovery->status);
        $this->assertSame('The provider returned no usable speech transcript.', $recovery->error);
        $this->assertSame('Original saved speech.', $call->fresh()->transcript);
        $this->assertDatabaseCount('voice_call_transcripts', 0);
        $this->assertFileDoesNotExist($this->temporaryPath);
    }

    public static function unusableTranscriptionTexts(): array
    {
        return [
            'array' => [['text' => 'Not actual provider speech']],
            'null' => [null],
            'number' => [123],
            'boolean' => [true],
            'empty' => [''],
            'whitespace' => [" \n\t"],
            'oversized' => [str_repeat('a', 500001)],
        ];
    }

    private function recover(VoiceCall $call): VoiceTranscriptRecovery
    {
        $this->postJson($this->url($call), ['idempotency_key' => 'bounded-upload'])->assertAccepted();
        $recovery = VoiceTranscriptRecovery::latest()->firstOrFail();
        app(VoiceTranscriptRecoveryService::class)->run($recovery->id);

        return $recovery->fresh();
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
