<?php

namespace Tests\Feature;

use App\Models\VoiceCall;
use App\Services\TelnyxAi\VoiceWebhookProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class VoiceRecordingWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_format_keyed_recording_url_is_saved_only_after_consent_and_not_erased_by_a_late_empty_event(): void
    {
        $call = VoiceCall::query()->create(['direction' => 'INBOUND', 'provider' => 'telnyx', 'status' => 'completed', 'call_control_id' => 'recording-leg', 'recording_consent_given' => true]);
        $this->event($call, ['recording_urls' => ['mp3' => 'https://api.telnyx.com/recordings/test.mp3', 'wav' => 'https://api.telnyx.com/recordings/test.wav'], 'recording_id' => 'recording-resource']);
        $this->assertSame('https://api.telnyx.com/recordings/test.mp3', $call->fresh()->recording_url);
        $this->assertSame('recording-resource', data_get($call->fresh()->metadata, 'recording_id'));
        $this->event($call, []);
        $this->assertSame('https://api.telnyx.com/recordings/test.mp3', $call->fresh()->recording_url);
        $call->forceFill(['recording_consent_given' => false, 'recording_url' => null])->save();
        $this->event($call, ['recording_urls' => ['mp3' => 'https://api.telnyx.com/recordings/unconsented.mp3']]);
        $this->assertNull($call->fresh()->recording_url);
    }

    private function event(VoiceCall $call, array $payload): void
    {
        $data = ['data' => ['id' => (string) Str::uuid(), 'event_type' => 'call.recording.saved', 'payload' => array_merge(['call_control_id' => $call->call_control_id], $payload)]];
        app(VoiceWebhookProcessor::class)->process($data, json_encode($data));
    }
}
