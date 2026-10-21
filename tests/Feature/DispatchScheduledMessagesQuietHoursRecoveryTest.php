<?php

namespace Tests\Feature;

use App\Jobs\DispatchScheduledMessages;
use App\Models\AutomationRule;
use App\Models\Message;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\AutomationWorkflowExecutor;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\OutboundDeliveryGuard;
use App\Services\Messaging\Providers\FakeCakemailProvider;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DispatchScheduledMessagesQuietHoursRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC', 'messaging.email_primary' => 'CAKEMAIL']);
        AutomationRule::query()->update(['is_active' => false]);
        OutboundDeliveryGuard::allowFakeProviderPipelineForTesting();
        Carbon::setTestNow(Carbon::parse('2026-10-21 05:00', 'America/New_York')->utc());
        $this->client = User::factory()->create(['role' => 'client', 'timezone' => 'America/New_York']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_quiet_hours_held_receipt_is_released_on_the_same_message_only_once(): void
    {
        $message = $this->heldMessage();
        $originalId = $message->id;
        $originalSchedule = $message->scheduled_at->toIso8601String();

        $this->dispatch();
        $this->dispatch();

        $fresh = $message->fresh();
        $this->assertSame($originalId, $fresh->id);
        $this->assertSame('SENT', $fresh->status);
        $this->assertSame(now()->toDateTimeString(), $fresh->sent_at->toDateTimeString());
        $this->assertSame($originalSchedule, $fresh->scheduled_at->toIso8601String(), 'Recovery must not rewrite the original hold timestamp.');
        $this->assertSame(1, Message::count());
        $this->assertCount(1, FakeCakemailProvider::sent());
    }

    public function test_an_explicit_future_user_schedule_is_not_advanced(): void
    {
        $scheduledAt = now()->addHours(6);
        $message = app(MessagingService::class)->scheduleEmail([
            'to' => $this->client->email,
            'subject' => 'Scheduled receipt',
            'body_text' => 'Keep my chosen delivery time.',
            'send_source' => 'MANUAL',
            'metadata' => ['notification' => ['trigger' => 'PAYMENT_COMPLETED']],
        ], $scheduledAt);

        $this->dispatch();

        $this->assertSame('SCHEDULED', $message->fresh()->status);
        $this->assertSame($scheduledAt->toDateTimeString(), $message->fresh()->scheduled_at->toDateTimeString());
        $this->assertNull(data_get($message->fresh()->metadata, 'delivery.deferred_reason'));
        $this->assertCount(0, FakeCakemailProvider::sent());
    }

    public function test_a_later_schedule_with_an_inherited_quiet_hours_marker_is_not_advanced(): void
    {
        $message = $this->heldMessage();
        $rescheduledAt = now()->addDay();
        $message->update(['scheduled_at' => $rescheduledAt]);

        $this->dispatch();

        $this->assertSame('SCHEDULED', $message->fresh()->status);
        $this->assertSame($rescheduledAt->toDateTimeString(), $message->fresh()->scheduled_at->toDateTimeString());
        $this->assertCount(0, FakeCakemailProvider::sent());
    }

    public function test_an_appointment_reminder_stays_held_until_its_allowed_time(): void
    {
        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'status' => 'scheduled',
            'workflow_status' => 'scheduled',
            'timezone' => 'America/New_York',
            'scheduled_at' => Carbon::parse('2026-10-21 15:00', 'America/New_York')->utc(),
        ]);
        $message = $this->heldMessage('SHOOT_REMINDER', ['related_shoot_id' => $shoot->id]);

        $this->dispatch();

        $this->assertSame('SCHEDULED', $message->fresh()->status);
        $this->assertNull($message->fresh()->sent_at);
        $this->assertCount(0, FakeCakemailProvider::sent());

        Carbon::setTestNow(Carbon::parse('2026-10-21 09:00', 'America/New_York')->utc());
        $this->dispatch();
        $this->dispatch();

        $this->assertSame('SENT', $message->fresh()->status);
        $this->assertCount(1, FakeCakemailProvider::sent());
    }

    public function test_recovery_uses_the_existing_dispatch_lock_before_sending(): void
    {
        $message = $this->heldMessage();
        $lock = Cache::lock('scheduled-message:'.$message->id, 300);
        $this->assertTrue($lock->get());
        try {
            $this->dispatch();
            $this->assertSame('SCHEDULED', $message->fresh()->status);
            $this->assertCount(0, FakeCakemailProvider::sent());
        } finally {
            $lock->release();
        }

        $this->dispatch();
        $this->dispatch();

        $this->assertSame('SENT', $message->fresh()->status);
        $this->assertSame(1, Message::count());
        $this->assertCount(1, FakeCakemailProvider::sent());
    }

    public function test_recovery_reaches_eligible_messages_beyond_a_full_chunk_of_future_holds(): void
    {
        foreach (range(1, 50) as $index) {
            $held = $this->heldMessage();
            // A changed schedule is deliberately ineligible for recovery.
            $held->update(['scheduled_at' => now()->addDays(2)]);
        }
        $receipt = $this->heldMessage();

        $this->dispatch();
        $this->dispatch();

        $this->assertSame('SENT', $receipt->fresh()->status);
        $this->assertSame(50, Message::where('status', 'SCHEDULED')->count());
        $this->assertSame(1, Message::where('status', 'SENT')->count());
        $this->assertCount(1, FakeCakemailProvider::sent());
    }

    private function heldMessage(string $trigger = 'PAYMENT_COMPLETED', array $attributes = []): Message
    {
        $heldUntil = Carbon::parse('2026-10-21 09:00', 'America/New_York')->utc();

        return Message::create(array_merge([
            'channel' => 'EMAIL',
            'direction' => 'OUTBOUND',
            'provider' => 'CAKEMAIL',
            'status' => 'SCHEDULED',
            'send_source' => 'AUTOMATION',
            'from_address' => 'noreply@example.test',
            'to_address' => $this->client->email,
            'subject' => 'Deferred notification',
            'body_text' => 'Saved notification body',
            'scheduled_at' => $heldUntil,
            'metadata' => [
                'notification' => ['trigger' => $trigger],
                'delivery' => [
                    'status' => 'SCHEDULED',
                    'deferred_reason' => 'client_notification_quiet_hours',
                    'deferred_until' => $heldUntil->toIso8601String(),
                ],
            ],
        ], $attributes));
    }

    private function dispatch(): void
    {
        (new DispatchScheduledMessages)->handle(
            app(MessagingService::class),
            app(AutomationWorkflowExecutor::class),
            app(AutomationService::class),
        );
    }
}
