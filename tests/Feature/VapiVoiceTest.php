<?php

namespace Tests\Feature;

use App\Models\VoiceCall;
use App\Models\VoiceCallEvent;
use App\Models\VoiceCallToolInvocation;
use App\Models\VoiceCallTranscript;
use App\Services\TelnyxAi\VoiceLiveStreamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VapiVoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_vapi_status_update_creates_inbound_call(): void
    {
        $payload = $this->statusPayload('in-progress');

        $this->postJson('/api/webhooks/vapi', $payload)
            ->assertOk()
            ->assertJsonPath('status', 'processed');

        $call = VoiceCall::query()->where('vapi_call_id', 'vapi-call-1')->firstOrFail();
        $this->assertSame('vapi_telnyx', $call->provider);
        $this->assertSame('INBOUND', $call->direction);
        $this->assertSame('answered', $call->status);
        $this->assertSame('ai', $call->handled_by);
        $this->assertNotNull($call->answered_at);
        $this->assertDatabaseHas('voice_call_events', [
            'provider' => 'vapi',
            'event_type' => 'status-update',
            'voice_call_id' => $call->id,
        ]);
    }

    public function test_vapi_transcript_updates_live_stream_state(): void
    {
        $call = $this->createInboundCall();

        $this->postJson('/api/webhooks/vapi', [
            'message' => [
                'type' => 'transcript',
                'transcript' => 'I need photos for a new listing.',
                'role' => 'user',
                'transcriptType' => 'final',
                'confidence' => 0.91,
                'call' => $this->vapiCall(),
            ],
        ])->assertOk();

        $call->refresh();
        $this->assertSame('I need photos for a new listing.', $call->live_transcript_preview);
        $this->assertStringContainsString('I need photos', (string) $call->transcript);
        $this->assertSame(1, VoiceCallTranscript::query()->where('voice_call_id', $call->id)->count());

        $admin = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'sanctum')
            ->get("/api/voice/calls/{$call->id}/stream?once=1")
            ->assertOk();

        $snapshot = app(VoiceLiveStreamService::class)->snapshot($call->fresh());
        $this->assertSame('I need photos for a new listing.', $snapshot['transcript']['chunks'][0]['text']);
    }

    public function test_vapi_end_of_call_report_saves_summary_and_analysis(): void
    {
        $call = $this->createInboundCall();

        $this->postJson('/api/webhooks/vapi', [
            'message' => [
                'type' => 'end-of-call-report',
                'endedReason' => 'customer-ended-call',
                'call' => $this->vapiCall(),
                'artifact' => [
                    'transcript' => 'AI: Hi. User: I want to book.',
                    'recording' => ['url' => 'https://example.test/recording.wav'],
                ],
                'analysis' => [
                    'summary' => 'Caller wants to book a listing shoot.',
                    'structuredData' => [
                        'intent' => 'book_shoot',
                        'sentiment' => 'interested',
                        'booking_probability' => 'High',
                        'needs_follow_up' => true,
                    ],
                ],
                'durationSeconds' => 64,
            ],
        ])->assertOk();

        $call->refresh();
        $this->assertSame('completed', $call->status);
        $this->assertSame('Caller wants to book a listing shoot.', $call->summary);
        $this->assertSame('book_shoot', $call->intent);
        $this->assertSame('interested', $call->sentiment);
        $this->assertSame('High', $call->booking_probability);
        $this->assertTrue($call->needs_follow_up);
        $this->assertSame('vapi', $call->recording_provider);
        $this->assertSame(64, $call->duration_seconds);
    }

    public function test_vapi_tool_call_creates_confirmation_invocation_for_risky_tool(): void
    {
        $call = $this->createInboundCall();

        $this->postJson('/api/webhooks/vapi', [
            'message' => [
                'type' => 'tool-calls',
                'call' => $this->vapiCall(),
                'toolCallList' => [
                    [
                        'id' => 'tool-1',
                        'function' => [
                            'name' => 'book_shoot',
                            'arguments' => ['shoot_id' => 10],
                        ],
                    ],
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('tool_response.result.requires_confirmation', true);

        $this->assertDatabaseHas('voice_call_tool_invocations', [
            'voice_call_id' => $call->id,
            'tool_name' => 'book_shoot',
            'provider_tool_call_id' => 'tool-1',
            'status' => VoiceCallToolInvocation::STATUS_PENDING,
            'requires_confirmation' => true,
        ]);
    }

    public function test_vapi_webhook_is_idempotent(): void
    {
        $payload = $this->statusPayload('ringing');

        $this->postJson('/api/webhooks/vapi', $payload)->assertOk()->assertJsonPath('status', 'processed');
        $this->postJson('/api/webhooks/vapi', $payload)->assertOk()->assertJsonPath('status', 'duplicate');

        $this->assertSame(1, VoiceCallEvent::query()->where('provider', 'vapi')->count());
        $this->assertSame(1, VoiceCall::query()->where('vapi_call_id', 'vapi-call-1')->count());
    }

    public function test_outbound_endpoint_calls_vapi(): void
    {
        config(['services.vapi.api_key' => 'test-vapi-key']);
        config(['services.vapi.assistant_id' => 'asst-1']);
        config(['services.vapi.phone_number_id' => 'pn-1']);
        Http::fake([
            'https://api.vapi.ai/call' => Http::response([
                'id' => 'vapi-outbound-1',
                'status' => 'queued',
                'phoneNumberId' => 'pn-1',
            ]),
        ]);

        $admin = \App\Models\User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/voice/calls/outbound', [
                'to' => '+12025550198',
                'assistant_mode' => 'robbie_ai',
                'source' => 'smart_dialer',
            ])
            ->assertCreated()
            ->assertJsonPath('vapi_call_id', 'vapi-outbound-1')
            ->assertJsonPath('provider', 'vapi_telnyx');

        Http::assertSent(fn ($request) => $request->url() === 'https://api.vapi.ai/call'
            && $request['assistantId'] === 'asst-1'
            && $request['phoneNumberId'] === 'pn-1'
            && $request['customer']['number'] === '+12025550198');
    }

    private function createInboundCall(): VoiceCall
    {
        $this->postJson('/api/webhooks/vapi', $this->statusPayload('in-progress'))->assertOk();

        return VoiceCall::query()->where('vapi_call_id', 'vapi-call-1')->firstOrFail();
    }

    private function statusPayload(string $status): array
    {
        return [
            'message' => [
                'type' => 'status-update',
                'status' => $status,
                'call' => $this->vapiCall(),
            ],
        ];
    }

    private function vapiCall(): array
    {
        return [
            'id' => 'vapi-call-1',
            'type' => 'inboundPhoneCall',
            'status' => 'in-progress',
            'assistantId' => 'asst-1',
            'phoneNumberId' => 'pn-1',
            'customer' => ['number' => '+12025550123'],
            'phoneNumber' => ['number' => '+18888041663'],
        ];
    }
}
