<?php

namespace Tests\Feature;

use App\Models\VoiceCall;
use App\Services\TelnyxAi\TelnyxVoiceCallService;
use App\Services\TelnyxAi\VoiceSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class VoiceRecordingControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telnyx.api_key' => 'fixture', 'services.telnyx.api_base' => 'https://api.telnyx.com/v2']);
        app(VoiceSettingsService::class)->update(['recording_enabled' => true]);
    }

    public function test_restart_uses_new_command_id_while_duplicate_start_is_a_noop(): void
    {
        Http::fake(['api.telnyx.com/*' => Http::response(['data' => ['result' => 'ok']])]);
        $call = $this->recordingCall();
        $service = app(TelnyxVoiceCallService::class);
        $this->assertTrue($service->setRecordingConsent($call, true)['recording']);
        $this->assertTrue($service->setRecordingConsent($call, true)['recording']);
        Http::assertSentCount(1);
        $this->assertFalse($service->stopRecording($call)['recording']);
        $this->assertTrue($service->setRecordingConsent($call, true)['recording']);
        $requests = Http::recorded(fn ($request) => str_ends_with($request->url(), '/record_start'))->values();
        $this->assertCount(2, $requests);
        $this->assertNotSame($requests[0][0]['command_id'], $requests[1][0]['command_id']);
        $this->assertSame(1, $call->fresh()->metadata['recording_generation']);
    }

    public function test_failed_recording_stop_does_not_skip_transcription_stop_and_retry_keeps_its_id(): void
    {
        $recordingAttempts = 0;
        Http::fake(function ($request) use (&$recordingAttempts) {
            if (str_ends_with($request->url(), '/record_stop') && ++$recordingAttempts === 1) {
                return Http::response(['error' => 'unavailable'], 503);
            }

            return Http::response(['data' => ['result' => 'ok']]);
        });
        $call = $this->recordingCall(['recording_consent_given' => true, 'metadata' => ['recording_started_at' => now()->toIso8601String(), 'browser_transcription_enabled' => true]]);
        $service = app(TelnyxVoiceCallService::class);
        try {
            $service->setRecordingConsent($call, false);
            $this->fail('Unconfirmed recording stop must not return success.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('recording stopped', $exception->getMessage());
        }
        $state = $call->fresh();
        $this->assertFalse($state->recording_consent_given);
        $this->assertTrue($state->metadata['recording_stop_pending']);
        $this->assertFalse($state->metadata['browser_transcription_enabled']);
        $this->assertNotEmpty($state->metadata['recording_started_at']);
        Http::assertSentCount(2);
        $this->assertFalse($service->setRecordingConsent($call, false)['recording']);
        Http::assertSentCount(3);
        $requests = Http::recorded(fn ($request) => str_ends_with($request->url(), '/record_stop'))->values();
        $this->assertSame($requests[0][0]['command_id'], $requests[1][0]['command_id']);
        $this->assertFalse($call->fresh()->metadata['recording_stop_pending']);
    }

    public function test_unconfirmed_transcription_stop_blocks_restart_until_retry_succeeds(): void
    {
        $attempts = 0;
        Http::fake(function ($request) use (&$attempts) {
            if (str_ends_with($request->url(), '/transcription_stop') && ++$attempts === 1) {
                return Http::response(['error' => 'unavailable'], 503);
            }

            return Http::response(['data' => ['result' => 'ok']]);
        });
        $call = $this->recordingCall(['recording_consent_given' => true, 'metadata' => ['recording_started_at' => now()->toIso8601String(), 'browser_transcription_pending' => true]]);
        $service = app(TelnyxVoiceCallService::class);
        try {
            $service->stopRecording($call);
            $this->fail('Stop must be unconfirmed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('transcription stopped', $exception->getMessage());
        }
        $this->assertArrayNotHasKey('recording_started_at', $call->fresh()->metadata);
        try {
            $service->setRecordingConsent($call, true);
            $this->fail('Restart must wait for stop.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('previous stop is not confirmed', $exception->getMessage());
        }
        Http::assertSentCount(2);
        $service->stopRecording($call);
        Http::assertSentCount(3);
        $this->assertFalse($call->fresh()->metadata['browser_transcription_pending']);
        $this->assertFalse($call->fresh()->metadata['recording_stop_pending']);
    }

    public function test_ambiguous_start_is_stopped_when_consent_is_revoked(): void
    {
        Http::fake(fn ($request) => Http::response(['data' => ['result' => 'ok']], str_ends_with($request->url(), '/record_start') ? 503 : 200));
        $call = $this->recordingCall();
        $service = app(TelnyxVoiceCallService::class);
        $this->assertFalse($service->setRecordingConsent($call, true)['recording']);
        $this->assertTrue($call->fresh()->metadata['recording_start_pending']);
        $service->setRecordingConsent($call, false);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/record_stop'));
        $this->assertFalse($call->fresh()->metadata['recording_start_pending']);
    }

    private function recordingCall(array $attributes = []): VoiceCall
    {
        return VoiceCall::query()->create(array_merge(['direction' => 'INBOUND', 'provider' => 'telnyx', 'status' => 'active', 'call_control_id' => 'recording-control-leg'], $attributes));
    }
}
