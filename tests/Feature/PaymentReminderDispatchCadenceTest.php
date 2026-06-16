<?php

namespace Tests\Feature;

use App\Jobs\DispatchScheduledMessages;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\PaymentReminder;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\AutomationWorkflowExecutor;
use App\Services\Messaging\MessagingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Feature: per-shoot-notifications-payment-reminders — end-to-end cadence DISPATCH.
 *
 * Validates: Requirements 4.4, 5.1, 5.2, 5.3, 5.4
 *
 * The scheduler/wiring tests prove the cadence *math* and that rows get *created*. This test
 * closes the remaining gap: it runs the real {@see DispatchScheduledMessages} job at each due
 * instant (time-travel via Carbon::setTestNow) and proves the reminders actually *dispatch* on
 * BOTH channels (email + SMS), in the correct Day 1/3/7 → weekly (14/21/28) → monthly
 * (last-Sunday 09:00) order, that each row flips pending → sent exactly once (no re-send on a
 * later run), and that once the shoot is marked paid the cadence STOPS — the next due reminder
 * is cancelled rather than sent (Req 5.3/5.4).
 *
 * MessagingService is mocked so no real email/SMS leaves the box; the mock records every
 * send per channel so we can assert exactly one email and one SMS fire per due reminder. The
 * real AutomationService (resolved from the container, holding the mocked MessagingService) and
 * the real cadence persistence are exercised end to end.
 */
class PaymentReminderDispatchCadenceTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{to: mixed, tags: mixed}> */
    private array $emailSends = [];

    /** @var list<array{to: mixed, tags: mixed}> */
    private array $smsSends = [];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Seed the active `payment-due-reminder` template the dual-channel send path renders.
     */
    private function seedPaymentReminderTemplate(): void
    {
        MessageTemplate::updateOrCreate(
            ['slug' => 'payment-due-reminder'],
            [
                'channel'   => 'EMAIL',
                'name'      => 'Invoice Payment Reminder',
                'category'  => 'INVOICE',
                'subject'   => 'Payment due for your shoot',
                'body_html' => '<p>Hi {{recipient_name}}, your balance is due.</p>',
                'body_text' => 'Hi {{recipient_name}}, your balance is due.',
                'scope'     => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ]
        );
    }

    /**
     * Mock MessagingService so both channels are recorded (not really sent). Each call returns a
     * Message so the dispatcher links message_id and marks the reminder row sent.
     */
    private function mockMessagingRecorder(): void
    {
        $this->emailSends = [];
        $this->smsSends = [];

        $this->mock(MessagingService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendEmail')
                ->zeroOrMoreTimes()
                ->andReturnUsing(function (array $payload): Message {
                    $this->emailSends[] = [
                        'to'   => $payload['to'] ?? null,
                        'tags' => $payload['tags_json'] ?? null,
                    ];

                    return Message::make([
                        'channel'    => 'EMAIL',
                        'to_address' => $payload['to'] ?? null,
                        'status'     => 'SENT',
                    ]);
                });

            $mock->shouldReceive('sendSms')
                ->zeroOrMoreTimes()
                ->andReturnUsing(function (array $payload): Message {
                    $this->smsSends[] = [
                        'to'   => $payload['to'] ?? null,
                        'tags' => $payload['tags_json'] ?? null,
                    ];

                    return Message::make([
                        'channel'    => 'SMS',
                        'to_address' => $payload['to'] ?? null,
                        'status'     => 'SENT',
                    ]);
                });
        });
    }

    private function makeUnpaidShootWithBothChannels(string $anchor): Shoot
    {
        $client = User::factory()->create([
            'email'       => 'client@example.com',
            'name'        => 'Casey Client',
            'phonenumber' => '+15551234567',
        ]);

        // ShootFactory defaults payment_status to 'paid'; create unpaid explicitly and stamp the
        // cadence anchor (the shoot_ready_notified_at timestamp).
        return Shoot::factory()->create([
            'client_id'               => $client->id,
            'payment_status'          => 'unpaid',
            'shoot_ready_notified_at' => Carbon::parse($anchor),
        ]);
    }

    /**
     * Run the real dispatch job at the current (test) time with container-resolved dependencies.
     */
    private function runDispatch(): void
    {
        (new DispatchScheduledMessages())->handle(
            app(MessagingService::class),
            app(AutomationWorkflowExecutor::class),
            app(AutomationService::class),
        );
    }

    public function test_full_cadence_dispatches_on_both_channels_and_stops_on_paid(): void
    {
        $this->seedPaymentReminderTemplate();
        $this->mockMessagingRecorder();

        $anchor = '2026-01-01 10:00:00';
        $shoot = $this->makeUnpaidShootWithBothChannels($anchor);

        // Schedule at anchor time so every cadence timestamp is in the future (future-only guard).
        Carbon::setTestNow($anchor);
        app(AutomationService::class)->schedulePaymentReminders($shoot->fresh());

        $scheduled = PaymentReminder::where('shoot_id', $shoot->id)
            ->orderBy('scheduled_at')
            ->get();

        // The rolling 3-month horizon yields the full first month plus the first couple of
        // monthly reminders: Day 1/3/7, weekly 14/21/28, then last-Sunday months.
        $this->assertGreaterThanOrEqual(
            8,
            $scheduled->count(),
            'expected Day 1/3/7 + weekly 14/21/28 + at least two monthly reminders'
        );

        $anchorTs = Carbon::parse($anchor);

        // Phase 1 (Day 1/3/7) and Phase 2 (weekly 14/21/28) are the first six, in order.
        foreach ([1, 3, 7, 14, 21, 28] as $i => $offset) {
            $this->assertSame(
                $anchorTs->copy()->addDays($offset)->toDateString(),
                $scheduled[$i]->scheduled_at->toDateString(),
                "reminder #{$i} should be Day {$offset} of the cadence"
            );
        }

        // Phase 3: the seventh reminder is the first monthly reminder — a last Sunday at 09:00.
        $firstMonthly = $scheduled[6];
        $this->assertSame(
            Carbon::SUNDAY,
            $firstMonthly->scheduled_at->dayOfWeek,
            'the first monthly reminder must fall on a Sunday'
        );
        $this->assertSame('09:00', $firstMonthly->scheduled_at->format('H:i'), 'monthly reminders fire at 09:00');
        $this->assertTrue(
            $firstMonthly->scheduled_at->copy()->addWeek()->month !== $firstMonthly->scheduled_at->month,
            'the monthly reminder must be the LAST Sunday of its month'
        );

        // Walk the cadence: dispatch at each due instant for the first seven reminders
        // (Phase 1 → Phase 2 → first Phase 3), asserting exactly one email + one SMS per step.
        $expectedSends = 0;
        for ($i = 0; $i <= 6; $i++) {
            $reminder = $scheduled[$i];
            Carbon::setTestNow($reminder->scheduled_at->copy());
            $this->runDispatch();
            $expectedSends++;

            $this->assertCount(
                $expectedSends,
                $this->emailSends,
                "after due reminder #{$i} there should be {$expectedSends} email send(s)"
            );
            $this->assertCount(
                $expectedSends,
                $this->smsSends,
                "after due reminder #{$i} there should be {$expectedSends} SMS send(s)"
            );

            $this->assertSame(
                PaymentReminder::STATUS_SENT,
                $reminder->fresh()->status,
                "reminder #{$i} should flip pending → sent after dispatch"
            );
        }

        // No re-send: running the job again at the same instant must not re-fire any sent row.
        $emailCountBeforeRerun = count($this->emailSends);
        $smsCountBeforeRerun = count($this->smsSends);
        $this->runDispatch();
        $this->assertCount($emailCountBeforeRerun, $this->emailSends, 'a re-run must not re-send already-sent reminders');
        $this->assertCount($smsCountBeforeRerun, $this->smsSends, 'a re-run must not re-send already-sent reminders');

        // Confirm there is still a pending reminder in the future (the next monthly one) to prove
        // the stop-on-paid path below actually has something to cancel.
        $nextPending = PaymentReminder::where('shoot_id', $shoot->id)
            ->where('status', PaymentReminder::STATUS_PENDING)
            ->orderBy('scheduled_at')
            ->first();
        $this->assertNotNull($nextPending, 'a later monthly reminder should still be pending');

        // Stop-on-paid (Req 5.3/5.4): the client pays. Advance to the next due reminder and
        // dispatch — the dispatcher must re-check payment and CANCEL the reminder instead of
        // sending, leaving the dual-channel send counts unchanged.
        $shoot->forceFill(['payment_status' => 'paid'])->save();

        Carbon::setTestNow($nextPending->scheduled_at->copy());
        $this->runDispatch();

        $this->assertCount(
            $emailCountBeforeRerun,
            $this->emailSends,
            'no email reminder may be sent once the shoot is paid'
        );
        $this->assertCount(
            $smsCountBeforeRerun,
            $this->smsSends,
            'no SMS reminder may be sent once the shoot is paid'
        );

        // Every remaining reminder must now be cancelled (none left pending).
        $this->assertSame(
            0,
            PaymentReminder::where('shoot_id', $shoot->id)
                ->where('status', PaymentReminder::STATUS_PENDING)
                ->count(),
            'paying the shoot must cancel all remaining pending reminders'
        );
        $this->assertGreaterThan(
            0,
            PaymentReminder::where('shoot_id', $shoot->id)
                ->where('status', PaymentReminder::STATUS_CANCELLED)
                ->count(),
            'the unsent future reminders should be marked cancelled, not sent'
        );
    }
}
