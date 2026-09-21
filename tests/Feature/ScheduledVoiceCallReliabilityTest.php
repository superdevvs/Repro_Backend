<?php

namespace Tests\Feature;

use App\Jobs\ScheduledVoiceCallJob;
use App\Models\ScheduledVoiceCall;
use App\Models\User;
use App\Models\VoiceCall;
use App\Services\TelnyxAi\ScheduledVoiceCallService;
use App\Services\TelnyxAi\VoiceIntelligenceService;
use App\Services\TelnyxAi\VoiceSettingsService;
use App\Services\Voice\VoiceCallService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ScheduledVoiceCallReliabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC', 'services.telnyx.public_key' => null]);
        $this->travelTo(CarbonImmutable::parse('2026-09-21 12:00:00', 'UTC'));
        app(VoiceSettingsService::class)->update([
            'quiet_hours' => ['enabled' => false],
            'callback_retry_delay_minutes' => 30,
            'callback_max_attempts' => 2,
        ]);
        Http::preventStrayRequests();
    }

    public function test_outbound_callback_targets_the_customer_and_reuses_a_deferred_callback(): void
    {
        $call = VoiceCall::query()->create([
            'direction' => 'OUTBOUND', 'status' => 'completed',
            'from_phone' => '+12025550100', 'to_phone' => '+12025550124',
        ]);
        $service = app(ScheduledVoiceCallService::class);
        $callback = $service->createCallbackForCall($call, 'missed_call');
        $this->assertSame('+12025550124', $callback->target_phone);
        $this->assertSame('+12025550100', $callback->from_phone);
        $callback->update(['status' => ScheduledVoiceCall::STATUS_DEFERRED]);

        $again = $service->createCallbackForCall($call, 'missed_call');
        $this->assertSame($callback->id, $again->id);
        $this->assertSame(1, ScheduledVoiceCall::query()->count());
    }

    public function test_manual_callbacks_inherit_global_quiet_hours_and_attempt_defaults(): void
    {
        $quiet = ['enabled' => true, 'start' => '20:00', 'end' => '08:00', 'timezone' => 'America/New_York'];
        app(VoiceSettingsService::class)->update(['quiet_hours' => $quiet]);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')->postJson('/api/voice/scheduled-calls', [
            'target_phone' => '+12025550124',
        ])->assertCreated()->assertJsonPath('quiet_hours', $quiet)->assertJsonPath('max_attempts', 2);
    }

    public function test_duplicate_job_cannot_dial_during_an_in_flight_provider_request(): void
    {
        $scheduled = $this->scheduled();
        $service = app(ScheduledVoiceCallService::class);
        $calls = Mockery::mock(VoiceCallService::class);
        $baselineTransactionLevel = DB::transactionLevel();
        $calls->shouldReceive('startOutbound')->once()->andReturnUsing(function (array $payload) use ($scheduled, $service, $calls, $baselineTransactionLevel): VoiceCall {
            $this->assertSame($baselineTransactionLevel, DB::transactionLevel(), 'Do not hold the claim transaction across provider HTTP.');
            $this->assertSame('dialing', $scheduled->fresh()->status);
            $this->assertSame(1, $scheduled->fresh()->attempts);
            (new ScheduledVoiceCallJob($scheduled->id))->handle($calls, $service);

            return $this->dialed($payload);
        });

        (new ScheduledVoiceCallJob($scheduled->id))->handle($calls, $service);
        $scheduled->refresh();
        $this->assertSame('dialing', $scheduled->status);
        $this->assertSame(1, $scheduled->attempts);
        $this->assertNull($scheduled->completed_at);
        $this->assertNull($scheduled->next_attempt_at);
        $this->assertNotNull($scheduled->result_voice_call_id);
        (new ScheduledVoiceCallJob($scheduled->id))->handle($calls, $service);
    }

    public function test_jobs_enforce_both_due_dates_and_the_attempt_ceiling(): void
    {
        $calls = Mockery::mock(VoiceCallService::class);
        $calls->shouldNotReceive('startOutbound');
        $service = app(ScheduledVoiceCallService::class);
        $future = $this->scheduled(['next_attempt_at' => now()->addHour()]);
        $futureSchedule = $this->scheduled(['scheduled_at' => now()->addHour()]);
        $exhausted = $this->scheduled(['attempts' => 2, 'max_attempts' => 2]);

        foreach ([$future, $futureSchedule, $exhausted] as $row) {
            (new ScheduledVoiceCallJob($row->id))->handle($calls, $service);
        }

        $this->assertSame(0, $future->fresh()->attempts);
        $this->assertSame(0, $futureSchedule->fresh()->attempts);
        $this->assertSame('exhausted', $exhausted->fresh()->status);
        $this->assertNull($exhausted->fresh()->next_attempt_at);
    }

    public function test_current_global_quiet_hours_protect_legacy_rows_without_quiet_hours(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-21 21:00:00', 'UTC'));
        app(VoiceSettingsService::class)->update(['quiet_hours' => [
            'enabled' => true, 'start' => '20:00', 'end' => '08:00', 'timezone' => 'UTC',
        ]]);
        $scheduled = $this->scheduled(['quiet_hours' => null]);
        $calls = Mockery::mock(VoiceCallService::class);
        $calls->shouldNotReceive('startOutbound');

        (new ScheduledVoiceCallJob($scheduled->id))->handle($calls, app(ScheduledVoiceCallService::class));

        $scheduled->refresh();
        $this->assertSame('deferred', $scheduled->status);
        $this->assertSame(0, $scheduled->attempts);
        $this->assertSame('2026-09-22 08:00:00', $scheduled->next_attempt_at->format('Y-m-d H:i:s'));
    }

    public function test_quiet_hours_end_is_exclusive_and_equal_boundaries_match_business_schedule(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-21 08:00:00', 'UTC'));
        app(VoiceSettingsService::class)->update(['quiet_hours' => [
            'enabled' => true, 'start' => '01:00', 'end' => '08:00', 'timezone' => 'UTC',
        ]]);
        $scheduled = $this->scheduled(['quiet_hours' => ['enabled' => true, 'start' => '08:00', 'end' => '08:00', 'timezone' => 'UTC']]);
        $calls = Mockery::mock(VoiceCallService::class);
        $calls->shouldReceive('startOutbound')->once()->andReturnUsing(fn (array $payload) => $this->dialed($payload));

        (new ScheduledVoiceCallJob($scheduled->id))->handle($calls, app(ScheduledVoiceCallService::class));
        $this->assertSame('dialing', $scheduled->fresh()->status);
    }

    public function test_legacy_scheduled_timezone_alias_defers_without_a_call_or_attempt(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-21 21:00:00', 'UTC'));
        $scheduled = $this->scheduled(['quiet_hours' => [
            'enabled' => true, 'start' => '20:00', 'end' => '08:00', 'timezone' => 'Asia/Calcutta',
        ]]);
        $calls = Mockery::mock(VoiceCallService::class);
        $calls->shouldNotReceive('startOutbound');
        (new ScheduledVoiceCallJob($scheduled->id))->handle($calls, app(ScheduledVoiceCallService::class));
        $this->assertSame('deferred', $scheduled->fresh()->status);
        $this->assertSame(0, $scheduled->fresh()->attempts);
        $this->assertSame('2026-09-22 02:30:00', $scheduled->fresh()->next_attempt_at->format('Y-m-d H:i:s'));
        Http::assertNothingSent();
    }

    public function test_answered_then_ended_webhooks_complete_the_current_attempt_once(): void
    {
        [$scheduled, $call] = $this->startScheduledCall();
        $this->webhook($call, 'call.answered', 'answered')->assertOk();
        $this->assertSame('dialing', $scheduled->fresh()->status);
        $this->webhook($call, 'call.conversation.ended', 'ended')->assertOk();
        $scheduled->refresh();
        $this->assertSame('completed', $scheduled->status);
        $this->assertNotNull($scheduled->completed_at);
        $this->assertNull($scheduled->next_attempt_at);

        $this->travel(1)->minutes();
        $this->webhook($call, 'call.conversation.ended', 'ended')->assertOk();
        $this->assertTrue($scheduled->completed_at->equalTo($scheduled->fresh()->completed_at));
        $this->assertSame(1, $scheduled->fresh()->attempts);
    }

    public function test_unanswered_webhook_retries_existing_callback_instead_of_creating_a_new_budget(): void
    {
        [$scheduled, $call] = $this->startScheduledCall();
        $this->webhook($call, 'call.no_answer', 'unanswered')->assertOk();
        $scheduled->refresh();
        $this->assertSame('failed', $scheduled->status);
        $this->assertSame(1, ScheduledVoiceCall::query()->count());
        $this->assertSame(1, $scheduled->attempts);
        $this->assertSame('2026-09-21 12:30:00', $scheduled->next_attempt_at->format('Y-m-d H:i:s'));
        $this->assertNull($scheduled->completed_at);
    }

    public function test_unsuccessful_last_attempt_exhausts_the_original_budget(): void
    {
        [$scheduled, $call] = $this->startScheduledCall(['attempts' => 1, 'max_attempts' => 2]);
        $this->webhook($call, 'call.no_answer', 'last-unanswered')->assertOk();
        $this->assertSame('exhausted', $scheduled->fresh()->status);
        $this->assertNull($scheduled->fresh()->next_attempt_at);
        $this->assertSame(1, ScheduledVoiceCall::query()->count());
    }

    public function test_terminal_webhook_that_beats_dial_response_is_not_overwritten(): void
    {
        $scheduled = $this->scheduled();
        $service = app(ScheduledVoiceCallService::class);
        $calls = Mockery::mock(VoiceCallService::class);
        $calls->shouldReceive('startOutbound')->once()->andReturnUsing(function (array $payload) use ($service): VoiceCall {
            $call = $this->dialed($payload);
            $call->update(['status' => 'completed', 'answered_at' => now(), 'ended_at' => now()]);
            $service->syncResult($call->fresh());

            return $call;
        });

        (new ScheduledVoiceCallJob($scheduled->id))->handle($calls, $service);
        $this->assertSame('completed', $scheduled->fresh()->status);
        $this->assertNotNull($scheduled->fresh()->result_voice_call_id);
    }

    public function test_late_answer_event_reconciles_an_ended_attempt_before_it_is_retried(): void
    {
        [$scheduled, $call] = $this->startScheduledCall();
        $call->update(['assistant_id' => 'must-not-restart-after-hangup']);
        $this->webhook($call, 'call.conversation.ended', 'ended-first')->assertOk();
        $this->assertSame('failed', $scheduled->fresh()->status);

        $this->webhook($call, 'call.answered', 'answered-late')->assertOk();
        $this->assertSame('completed', $scheduled->fresh()->status);
        $this->assertNull($scheduled->fresh()->next_attempt_at);
        $this->assertSame('completed', $call->fresh()->status);
        $this->assertNotNull($call->fresh()->answered_at);
        Http::assertNothingSent();
    }

    public function test_late_answer_event_preserves_explicit_failure_cancellation_and_transfer(): void
    {
        foreach (['failed', 'cancelled', 'transferred'] as $status) {
            $call = VoiceCall::query()->create([
                'provider' => 'telnyx', 'direction' => 'OUTBOUND', 'status' => $status,
                'from_phone' => '+12025550100', 'to_phone' => '+12025550124',
                'call_control_id' => 'terminal-'.$status, 'assistant_id' => 'must-not-restart',
                'ended_at' => now()->subMinute(),
            ]);
            $this->webhook($call, 'call.answered', 'late-'.$status)->assertOk();
            $this->assertSame($status, $call->fresh()->status);
            $this->assertNotNull($call->fresh()->answered_at);
        }
        Http::assertNothingSent();
    }

    public function test_unanswered_hangup_is_missed_with_zero_conversation_time_and_no_ai_summary(): void
    {
        $this->mock(VoiceIntelligenceService::class, fn ($mock) => $mock->shouldNotReceive('finalize'));
        [$scheduled, $call] = $this->startScheduledCall();
        $call->update(['started_at' => now()->subSeconds(45)]);
        $this->webhook($call, 'call.hangup', 'unanswered-hangup', ['duration_seconds' => 45])->assertOk();
        $this->assertSame('missed', $call->fresh()->status);
        $this->assertSame('missed', $call->fresh()->disposition);
        $this->assertSame(0, $call->fresh()->duration_seconds);
        $this->assertSame('failed', $scheduled->fresh()->status);
        $this->assertSame(1, ScheduledVoiceCall::query()->count());
        Http::assertNothingSent();
    }

    public function test_answered_hangup_measures_from_answer_and_duplicate_events_do_not_extend_it(): void
    {
        $this->mock(VoiceIntelligenceService::class, fn ($mock) => $mock->shouldReceive('finalize')->once()->andReturnNull());
        [$scheduled, $call] = $this->startScheduledCall();
        $call->update(['started_at' => now()->subMinutes(2), 'answered_at' => now()->subSeconds(30)]);
        $this->webhook($call, 'call.hangup', 'answered-hangup')->assertOk();
        $this->assertSame('completed', $call->fresh()->status);
        $this->assertSame(30, $call->fresh()->duration_seconds);
        $this->assertSame('completed', $scheduled->fresh()->status);
        $endedAt = $call->fresh()->ended_at;

        $this->travel(1)->minutes();
        $this->webhook($call, 'call.hangup', 'answered-hangup')->assertOk();
        $this->assertTrue($endedAt->equalTo($call->fresh()->ended_at));
        $this->assertSame(30, $call->fresh()->duration_seconds);
    }

    public function test_old_attempt_webhook_cannot_complete_a_new_attempt(): void
    {
        [$scheduled, $oldCall] = $this->startScheduledCall();
        $this->webhook($oldCall, 'call.no_answer', 'old-unanswered')->assertOk();
        $this->travel(31)->minutes();
        $service = app(ScheduledVoiceCallService::class);
        $calls = Mockery::mock(VoiceCallService::class);
        $calls->shouldReceive('startOutbound')->once()->andReturnUsing(function (array $payload) use ($oldCall, $service, $scheduled): VoiceCall {
            $oldCall->update(['status' => 'completed', 'answered_at' => now(), 'ended_at' => now()]);
            $service->syncResult($oldCall->fresh());
            $this->assertSame('dialing', $scheduled->fresh()->status);
            $this->assertNull($scheduled->fresh()->result_voice_call_id);

            return $this->dialed($payload);
        });

        (new ScheduledVoiceCallJob($scheduled->id))->handle($calls, $service);
        $this->assertSame('dialing', $scheduled->fresh()->status);
        $this->assertNotSame($oldCall->id, $scheduled->fresh()->result_voice_call_id);
    }

    public function test_uncertain_timeout_keeps_the_attempt_in_flight_without_automatic_redial(): void
    {
        $scheduled = $this->scheduled();
        $calls = Mockery::mock(VoiceCallService::class);
        $calls->shouldReceive('startOutbound')->once()->andReturnUsing(function (array $payload): never {
            $this->dialed($payload)->update(['status' => 'failed', 'call_control_id' => null]);
            throw new ConnectionException('The provider response timed out.');
        });
        $service = app(ScheduledVoiceCallService::class);
        (new ScheduledVoiceCallJob($scheduled->id))->handle($calls, $service);
        (new ScheduledVoiceCallJob($scheduled->id))->handle($calls, $service);
        $this->assertSame('dialing', $scheduled->fresh()->status);
        $this->assertNull($scheduled->fresh()->next_attempt_at);
        $this->assertNotNull($scheduled->fresh()->result_voice_call_id);
    }

    public function test_preflight_failure_retries_after_configured_delay_without_exceeding_limit(): void
    {
        $scheduled = $this->scheduled();
        $calls = Mockery::mock(VoiceCallService::class);
        $calls->shouldReceive('startOutbound')->twice()->andThrow(new \RuntimeException('Outbound is disabled.'));
        $service = app(ScheduledVoiceCallService::class);
        (new ScheduledVoiceCallJob($scheduled->id))->handle($calls, $service);
        $this->assertSame('failed', $scheduled->fresh()->status);
        $this->assertSame('2026-09-21 12:30:00', $scheduled->fresh()->next_attempt_at->format('Y-m-d H:i:s'));
        $this->travel(31)->minutes();
        (new ScheduledVoiceCallJob($scheduled->id))->handle($calls, $service);
        $this->assertSame('exhausted', $scheduled->fresh()->status);
        (new ScheduledVoiceCallJob($scheduled->id))->handle($calls, $service);
    }

    public function test_explicit_carrier_rejection_can_retry_but_a_post_request_failure_cannot_redial(): void
    {
        $service = app(ScheduledVoiceCallService::class);
        $rejected = $this->scheduled();
        $calls = Mockery::mock(VoiceCallService::class);
        $calls->shouldReceive('startOutbound')->once()->andReturnUsing(function (array $payload): never {
            $this->dialed($payload)->update(['status' => 'failed', 'call_control_id' => null]);
            throw new \RuntimeException('Telnyx dial failed (422): Invalid destination.');
        });
        (new ScheduledVoiceCallJob($rejected->id))->handle($calls, $service);
        $this->assertSame('failed', $rejected->fresh()->status);
        $this->assertNotNull($rejected->fresh()->next_attempt_at);

        $uncertain = $this->scheduled();
        $otherCalls = Mockery::mock(VoiceCallService::class);
        $otherCalls->shouldReceive('startOutbound')->once()->andReturnUsing(function (array $payload): never {
            $this->dialed($payload)->update(['status' => 'failed', 'call_control_id' => null]);
            throw new \RuntimeException('Database write failed after the carrier request.');
        });
        (new ScheduledVoiceCallJob($uncertain->id))->handle($otherCalls, $service);
        $this->assertSame('dialing', $uncertain->fresh()->status);
        $this->assertNull($uncertain->fresh()->next_attempt_at);
    }

    public function test_mutations_cannot_reset_an_active_or_exhausted_attempt(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $active = $this->scheduled(['status' => 'dialing', 'attempts' => 1]);
        $exhausted = $this->scheduled(['status' => 'exhausted', 'attempts' => 2, 'max_attempts' => 2]);
        $this->actingAs($admin, 'sanctum');
        $this->postJson("/api/voice/scheduled-calls/{$active->id}/retry")->assertConflict();
        $this->postJson("/api/voice/scheduled-calls/{$active->id}/cancel")->assertConflict();
        $this->patchJson("/api/voice/scheduled-calls/{$active->id}", ['status' => 'scheduled'])->assertConflict();
        $this->postJson("/api/voice/scheduled-calls/{$exhausted->id}/retry")->assertConflict();
        $this->patchJson("/api/voice/scheduled-calls/{$exhausted->id}", ['status' => 'completed'])->assertUnprocessable();
        $this->assertSame('dialing', $active->fresh()->status);
        Queue::assertNothingPushed();
    }

    private function scheduled(array $attributes = []): ScheduledVoiceCall
    {
        return ScheduledVoiceCall::query()->create(array_merge([
            'status' => 'scheduled', 'target_phone' => '+12025550124', 'from_phone' => '+12025550100',
            'scheduled_at' => now(), 'next_attempt_at' => now(), 'max_attempts' => 2,
            'created_by_user_id' => User::factory()->create(['role' => 'admin'])->id,
        ], $attributes));
    }

    private function dialed(array $payload): VoiceCall
    {
        return VoiceCall::query()->create([
            'provider' => 'telnyx', 'direction' => 'OUTBOUND', 'status' => 'dialing',
            'from_phone' => $payload['from'], 'to_phone' => $payload['to'],
            'call_control_id' => 'attempt-'.str()->uuid(),
            'metadata' => ['dynamic_variables' => $payload['dynamic_variables']],
        ]);
    }

    private function startScheduledCall(array $attributes = []): array
    {
        $scheduled = $this->scheduled($attributes);
        $calls = Mockery::mock(VoiceCallService::class);
        $calls->shouldReceive('startOutbound')->once()->andReturnUsing(fn (array $payload) => $this->dialed($payload));
        (new ScheduledVoiceCallJob($scheduled->id))->handle($calls, app(ScheduledVoiceCallService::class));

        return [$scheduled->fresh(), VoiceCall::query()->findOrFail($scheduled->fresh()->result_voice_call_id)];
    }

    private function webhook(VoiceCall $call, string $type, string $id, array $payload = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/webhooks/telnyx/voice', ['data' => [
            'id' => $id, 'event_type' => $type, 'payload' => array_merge(['call_control_id' => $call->call_control_id], $payload),
        ]]);
    }
}
