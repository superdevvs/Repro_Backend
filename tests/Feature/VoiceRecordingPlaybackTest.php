<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VoiceCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VoiceRecordingPlaybackTest extends TestCase
{
    use RefreshDatabase;

    public function test_playback_renews_an_expired_telnyx_link_and_never_fetches_the_stored_url(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'sanctum');
        $call = VoiceCall::query()->create(['provider' => 'telnyx', 'direction' => 'INBOUND', 'status' => 'completed', 'recording_consent_given' => true, 'recording_url' => 'https://old.example/test.wav?Expires=1', 'metadata' => ['recording_id' => 'recording-123']]);
        Http::fake(['https://api.telnyx.com/v2/recordings/recording-123' => Http::response(['data' => ['download_urls' => ['mp3' => 'https://storage.example/new-signed.mp3']]])]);
        $this->getJson("/api/voice/calls/{$call->id}/recording-url")->assertOk()->assertJsonPath('url', 'https://storage.example/new-signed.mp3')->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson("/api/voice/calls/{$call->id}/recording-url")->assertOk();
        Http::assertSentCount(1);
        $call->forceFill(['recording_consent_given' => false])->save();
        $this->getJson("/api/voice/calls/{$call->id}/recording-url")->assertOk()->assertJsonPath('url', null);
        Http::assertSentCount(1);
    }

    public function test_carrier_failure_surfaces_without_reusing_an_expired_url(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'sanctum');
        $call = VoiceCall::query()->create(['provider' => 'telnyx', 'direction' => 'INBOUND', 'status' => 'completed', 'recording_consent_given' => true, 'metadata' => ['recording_id' => 'recording-123']]);
        Http::fake(['*' => Http::response([], 503)]);
        $this->getJson("/api/voice/calls/{$call->id}/recording-url")->assertStatus(502);
        $call->forceFill(['metadata' => [], 'recording_url' => 'https://storage.example/expired.mp3?X-Amz-Date=20200101T000000Z&X-Amz-Expires=600'])->save();
        $this->getJson("/api/voice/calls/{$call->id}/recording-url")->assertStatus(410);
        Http::assertSentCount(1);
    }
}
