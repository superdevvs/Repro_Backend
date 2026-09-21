<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\SmsNumber;
use App\Models\User;
use App\Models\VoiceCall;
use App\Services\TelnyxAi\TelnyxVoiceCallService;
use App\Services\TelnyxAi\VoiceNumberSettingsResolver;
use App\Services\TelnyxAi\VoiceSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VoiceNumberRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        User::factory()->create(['role' => 'admin']);
        config([
            'services.voice.provider' => 'telnyx', 'services.voice.canary_mode' => false,
            'services.telnyx.api_key' => 'test-key', 'services.telnyx.public_key' => null,
            'services.telnyx.from_number' => '+12025550100', 'services.telnyx.voice.enabled' => true,
            'services.telnyx.voice.assistant_id' => 'env-assistant',
            'services.telnyx.voice.connection_id' => 'connection',
            'services.telnyx.voice.webhook_url' => 'https://example.test/api/webhooks/telnyx/voice',
            'services.telnyx.voice.support_handoff_number' => null,
        ]);
        Http::fake(['*' => Http::response(['data' => ['result' => 'ok', 'call_control_id' => 'outbound-call', 'conversation_id' => 'conversation']])]);
    }

    public function test_canonical_number_and_explicit_disable_are_deterministic_across_duplicate_rows(): void
    {
        $older = $this->number(['voice_ai_enabled' => false, 'voice_assistant_id_override' => 'old-assistant']);
        $canonical = $this->number(['provider' => 'telnyx', 'phone_number' => '(202) 555-0100',
            'is_default' => true, 'voice_ai_enabled' => true, 'voice_assistant_id_override' => 'line-assistant']);
        $policy = app(VoiceNumberSettingsResolver::class)->forNumber('+1 202 555 0100');
        $this->assertSame($canonical->id, $policy['number_id']);
        $this->assertFalse($policy['enabled']);
        $this->assertSame('line-assistant', $policy['assistant_id']);
        $this->assertNotSame($older->id, $policy['number_id']);
    }

    public function test_null_inherits_global_master_switch_and_stored_assistant(): void
    {
        $this->number(['voice_ai_enabled' => null]);
        $this->settings(['enabled' => true, 'assistant_id' => 'stored-assistant']);
        $resolver = app(VoiceNumberSettingsResolver::class);
        $this->assertTrue($resolver->forNumber('+12025550100')['enabled']);
        $this->assertSame('stored-assistant', $resolver->forNumber('+12025550100')['assistant_id']);
        $this->settings(['enabled' => false, 'assistant_id' => 'stored-assistant']);
        SmsNumber::query()->update(['voice_ai_enabled' => true]);
        $this->assertFalse($resolver->forNumber('+12025550100')['enabled']);
    }

    public function test_number_list_and_updates_align_duplicates_without_deleting_identity_or_ownership(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $older = $this->number(['voice_ai_enabled' => false, 'owner_type' => 'USER', 'owner_id' => $admin->id]);
        $canonical = $this->number(['provider' => 'telnyx', 'phone_number' => '(202) 555-0100', 'is_default' => true]);
        $otherProvider = $this->number(['provider' => 'TWILIO', 'voice_ai_enabled' => false]);
        $otherPhone = $this->number(['phone_number' => '+12025550200', 'voice_ai_enabled' => false]);
        $this->actingAs($admin, 'sanctum')->getJson('/api/voice/numbers')->assertOk()
            ->assertJsonCount(2, 'numbers')->assertJsonPath('numbers.0.id', $canonical->id)
            ->assertJsonPath('numbers.0.voice_ai_enabled', false);
        $this->patchJson("/api/voice/numbers/{$older->id}", [
            'voice_ai_enabled' => true, 'voice_assistant_id_override' => 'new-assistant', 'sms_ai_enabled' => true,
        ])->assertOk()->assertJsonPath('id', $canonical->id);
        foreach ([$older, $canonical] as $number) {
            $this->assertTrue($number->fresh()->voice_ai_enabled);
            $this->assertTrue($number->fresh()->sms_ai_enabled);
            $this->assertSame('new-assistant', $number->fresh()->voice_assistant_id_override);
        }
        $this->assertSame('USER', $older->fresh()->owner_type);
        $this->assertSame($admin->id, $older->fresh()->owner_id);
        $this->assertFalse($otherProvider->fresh()->voice_ai_enabled);
        $this->assertFalse($otherPhone->fresh()->voice_ai_enabled);
        $this->assertDatabaseCount('sms_numbers', 4);
    }

    public function test_disabled_number_transfers_to_staff_without_assistant_or_callback(): void
    {
        $this->number(['voice_ai_enabled' => false]);
        $this->settings(['enabled' => true, 'support_handoff_number' => '+12025550777']);
        $this->webhook('disabled-inbound', 'call.initiated');
        $call = VoiceCall::query()->sole();
        $this->assertSame('human_handoff', $call->status);
        $this->assertSame('transfer_requested', $call->disposition);
        $this->assertNull($call->handled_by);
        $this->assertFalse($call->metadata['voice_routing']['enabled']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/actions/transfer') && $request['to'] === '+12025550777');
        $this->assertNoAssistantOrCallback();
    }

    public function test_global_disabled_plays_static_unavailable_message_then_ends_on_gather_completion(): void
    {
        $this->number(['voice_ai_enabled' => true]);
        $this->settings(['enabled' => false, 'support_handoff_number' => null]);
        $this->webhook('unavailable-inbound', 'call.initiated');
        $call = VoiceCall::query()->sole();
        $this->assertSame('staff_unavailable', $call->disposition);
        $this->assertNull($call->ended_at);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/actions/gather_using_speak')
            && str_contains($request['payload'], 'unavailable'));
        $this->webhook('unavailable-gather', 'call.gather.ended');
        $this->assertNotNull($call->fresh()->ended_at);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/actions/hangup'));
        $this->assertNoAssistantOrCallback();
    }

    public function test_disabled_transfer_failure_uses_unavailable_fallback_without_ai_callback(): void
    {
        $this->number(['voice_ai_enabled' => false]);
        $this->settings(['enabled' => true, 'support_handoff_number' => '+12025550777']);
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([
            '*/actions/transfer' => Http::response(['errors' => []], 503),
            '*' => Http::response(['data' => ['result' => 'ok']]),
        ]);
        $this->webhook('transfer-fails-inbound', 'call.initiated');
        $this->webhook('transfer-fails-event', 'call.transfer.failed');
        $this->assertSame('staff_unavailable', VoiceCall::query()->sole()->disposition);
        $this->assertNoAssistantOrCallback();
    }

    public function test_inbound_assistant_override_is_snapshotted_before_menu_and_used_for_start(): void
    {
        $number = $this->number(['voice_ai_enabled' => null, 'voice_assistant_id_override' => 'line-assistant']);
        $this->settings(['enabled' => true, 'assistant_id' => 'stored-assistant']);
        $this->webhook('enabled-inbound', 'call.initiated');
        $call = VoiceCall::query()->sole();
        $this->assertSame('line-assistant', $call->assistant_id);
        $number->update(['voice_assistant_id_override' => 'changed-after-initiation']);
        $this->webhook('enabled-menu', 'call.gather.ended', ['digits' => '1']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/actions/ai_assistant_start')
            && $request['assistant']['id'] === 'line-assistant');
        $this->assertSame('ai', $call->fresh()->handled_by);
    }

    public function test_outbound_uses_source_line_override_and_disabled_line_blocks_before_dial(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $number = $this->number(['voice_ai_enabled' => true, 'voice_assistant_id_override' => 'line-assistant']);
        $this->actingAs($admin, 'sanctum')->postJson('/api/voice/calls/outbound', ['to' => '+12025550999'])
            ->assertCreated()->assertJsonPath('assistant_id', 'line-assistant')
            ->assertJsonPath('metadata.voice_routing.number_id', $number->id);
        $number->update(['voice_ai_enabled' => false]);
        $this->postJson('/api/voice/calls/outbound', ['to' => '+12025550999', 'assistant_id' => 'explicit-assistant'])
            ->assertUnprocessable()->assertJsonFragment(['AI calling is disabled for the selected phone number.']);
        $this->assertDatabaseCount('voice_calls', 1);
        Http::assertSentCount(1);
    }

    public function test_existing_outbound_assistant_snapshot_survives_initiated_webhook(): void
    {
        $this->number(['voice_ai_enabled' => true, 'voice_assistant_id_override' => 'line-assistant']);
        $call = VoiceCall::query()->create([
            'provider' => 'telnyx', 'direction' => 'OUTBOUND', 'status' => 'dialing', 'call_control_id' => 'test-call',
            'from_phone' => '+12025550100', 'to_phone' => '+12025550124', 'assistant_id' => 'explicit-assistant',
            'metadata' => ['voice_routing' => ['enabled' => true, 'assistant_id' => 'original-snapshot']],
        ]);
        $this->webhook('outbound-initiated', 'call.initiated', [
            'direction' => 'outgoing', 'from' => '+12025550100', 'to' => '+12025550124',
        ]);
        $this->assertSame('explicit-assistant', $call->fresh()->assistant_id);
        $this->assertSame('original-snapshot', $call->fresh()->metadata['voice_routing']['assistant_id']);
        Http::assertNothingSent();
    }

    public function test_low_level_assistant_start_cannot_bypass_current_number_disable(): void
    {
        $this->number(['voice_ai_enabled' => false]);
        $call = VoiceCall::query()->create([
            'direction' => 'INBOUND', 'status' => 'active', 'to_phone' => '+12025550100',
            'call_control_id' => 'test-call', 'assistant_id' => 'explicit-assistant',
            'metadata' => ['voice_routing' => ['enabled' => true]],
        ]);
        $this->assertFalse(app(TelnyxVoiceCallService::class)->startAssistant($call, []));
        Http::assertNothingSent();
        $this->assertSame('voice_ai_disabled', $call->fresh()->last_telnyx_command_status['error']);
    }

    private function number(array $attributes = []): SmsNumber
    {
        return SmsNumber::query()->create(array_merge([
            'provider' => 'TELNYX', 'phone_number' => '+12025550100', 'label' => 'Main', 'owner_type' => 'GLOBAL',
        ], $attributes));
    }

    private function settings(array $settings): void
    {
        Setting::query()->updateOrCreate(['key' => VoiceSettingsService::SETTINGS_KEY], [
            'type' => 'json', 'value' => json_encode($settings, JSON_THROW_ON_ERROR),
        ]);
    }

    private function webhook(string $id, string $type, array $fields = []): void
    {
        $this->postJson('/api/webhooks/telnyx/voice', ['data' => [
            'id' => $id, 'event_type' => $type, 'payload' => array_merge([
                'call_control_id' => 'test-call', 'direction' => 'incoming',
                'from' => '+12025550124', 'to' => '+12025550100',
            ], $fields),
        ]])->assertOk();
    }

    private function assertNoAssistantOrCallback(): void
    {
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/actions/ai_assistant_start'));
        $this->assertDatabaseCount('scheduled_voice_calls', 0);
    }
}
