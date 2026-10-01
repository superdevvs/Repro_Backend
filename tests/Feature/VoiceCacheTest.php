<?php

namespace Tests\Feature;

use App\Models\VoiceCall;
use App\Services\TelnyxAi\ConfirmationTokenService;
use App\Services\Voice\VoiceRecordingService;
use App\Support\VoiceCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\TestCase;

class VoiceCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'voice_calls.cache_store' => 'database']);
    }

    public function test_recording_url_cache_uses_shared_store_and_refreshes_after_expiry(): void
    {
        $call = new VoiceCall(['provider' => 'telnyx', 'recording_consent_given' => true, 'metadata' => ['recording_id' => 'recording-shared']]);
        $call->id = 123;
        $key = 'voice:recording-url:123:recording-shared';
        Cache::put($key, 'https://legacy.example/expired.mp3', 600);
        Http::fake(['https://api.telnyx.com/v2/recordings/recording-shared' => Http::sequence()
            ->push(['data' => ['download_urls' => ['mp3' => 'https://recordings.example/first.mp3']]])
            ->push(['data' => ['download_urls' => ['mp3' => 'https://recordings.example/refreshed.mp3']]])]);
        $service = app(VoiceRecordingService::class);
        $this->assertSame('https://recordings.example/first.mp3', $service->playbackUrl($call));
        $this->assertSame('https://recordings.example/first.mp3', VoiceCache::store()->get($key));
        $this->assertSame('https://recordings.example/first.mp3', $service->playbackUrl($call));
        Http::assertSentCount(1);
        $this->travel(241)->seconds();
        $this->assertSame('https://recordings.example/refreshed.mp3', $service->playbackUrl($call));
        Http::assertSentCount(2);
        $this->assertSame('https://legacy.example/expired.mp3', Cache::get($key));
        $call->recording_consent_given = false;
        $this->assertNull($service->playbackUrl($call));
        Http::assertSentCount(2);
    }

    public function test_confirmation_token_keeps_call_scope_result_and_expiry_in_shared_store(): void
    {
        $service = app(ConfirmationTokenService::class);
        $issued = $service->issue('update_shoot', ['shoot_id' => 12], 'Update the requested shoot', 77);
        $token = $issued['confirmation_token'];
        $key = 'telnyx-ai:tool-confirmation:'.hash('sha256', $token);
        $this->assertNull(Cache::get($key));
        $this->assertSame(77, VoiceCache::store()->get($key)['voice_call_id']);
        $this->assertNull($service->resolve($token, 'update_shoot', 78));
        $this->assertNull($service->resolve($token, 'cancel_shoot', 77));
        $this->assertSame(['shoot_id' => 12], $service->resolve($token, 'update_shoot', 77)['params']);
        $service->storeResult($token, ['ok' => true]);
        $this->assertSame(['ok' => true], $service->storedResult($token));
        $this->travel($issued['expires_in_seconds'] + 1)->seconds();
        $this->assertNull($service->resolve($token, 'update_shoot', 77));
        $this->assertNull($service->storedResult($token));
    }

    public function test_blank_voice_store_does_not_silently_use_general_cache(): void
    {
        config(['voice_calls.cache_store' => '']);
        $this->expectException(LogicException::class);
        VoiceCache::store();
    }
}
