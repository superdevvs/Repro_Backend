<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\PaymentReminder;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Messaging\ManualNotificationService;
use App\Services\Messaging\MessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Feature: per-shoot-notifications-payment-reminders, Task 2 — Gap A cadence wiring.
 *
 * Validates: Requirements 4.1, 4.3, 4.4, 4.5
 *
 * Sending a manual `shoot_ready` notification must do two things together:
 *   (1) stamp `shoot_ready_notified_at` on the shoot (the cadence anchor), and
 *   (2) start the payment-reminder cadence by creating pending PaymentReminder rows
 *       anchored on that timestamp (Day 1/3/7 at minimum) — Req 4.1, 4.4.
 *
 * Re-sending `shoot_ready` must not duplicate reminder rows (the (shoot_id, scheduled_date)
 * upsert is idempotent) — Req 4.5. A shoot that is already paid when the ready send happens
 * must schedule no reminders — Req 4.3.
 *
 * MessagingService is mocked so no real email/SMS is dispatched; the real AutomationService is
 * resolved from the container (the wiring under test), so the PaymentReminder rows it persists
 * are exercised end to end.
 */
class ManualNotificationCadenceWiringTest extends TestCase
{
    use RefreshDatabase;

    private function seedReadyTemplate(): void
    {
        MessageTemplate::updateOrCreate(
            ['slug' => 'shoot-ready'],
            [
                'channel'   => 'EMAIL',
                'name'      => 'Shoot Ready',
                'category'  => 'GENERAL',
                'subject'   => 'Your shoot is ready',
                'body_html' => '<p>Hello {{recipient_first_name}}</p>',
                'body_text' => 'Hello {{recipient_first_name}}',
                'scope'     => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ]
        );
    }

    private function mockMessaging(): void
    {
        $this->mock(MessagingService::class, function (MockInterface $mock): void {
            $message = Message::make([
                'channel'    => 'EMAIL',
                'to_address' => 'recipient@example.com',
                'status'     => 'SENT',
            ]);

            $mock->shouldReceive('sendEmail')->zeroOrMoreTimes()->andReturn($message);
            $mock->shouldReceive('sendSms')->zeroOrMoreTimes()->andReturn($message);
        });
    }

    private function makeUnpaidShoot(): Shoot
    {
        $client = User::factory()->create([
            'email' => 'client@example.com',
            'name'  => 'Casey Client',
        ]);

        return Shoot::factory()->create([
            'client_id'               => $client->id,
            'payment_status'          => 'unpaid',
            'shoot_ready_notified_at' => null,
        ]);
    }

    public function test_manual_shoot_ready_send_stamps_anchor_and_starts_cadence(): void
    {
        $this->seedReadyTemplate();
        $this->mockMessaging();

        $sender = User::factory()->create(['role' => 'admin']);
        $shoot = $this->makeUnpaidShoot();

        app(ManualNotificationService::class)
            ->send($shoot, 'shoot_ready', 'client', 'email', $sender);

        $fresh = $shoot->fresh();

        // Req 4.1 — the anchor is stamped.
        $this->assertNotNull($fresh->shoot_ready_notified_at, 'shoot_ready must stamp the cadence anchor');

        // Req 4.1 / 4.4 — pending reminders now exist for this shoot.
        $reminders = PaymentReminder::where('shoot_id', $shoot->id)->get();
        $this->assertGreaterThan(0, $reminders->count(), 'shoot_ready send must start the payment-reminder cadence');
        $this->assertTrue(
            $reminders->every(fn (PaymentReminder $r) => $r->status === PaymentReminder::STATUS_PENDING),
            'all freshly scheduled reminders should be pending'
        );

        // Req 4.4 — the Day 1/3/7 timestamps relative to the anchor are present.
        $anchor = $fresh->shoot_ready_notified_at->copy();
        $dates = $reminders->pluck('scheduled_date')->map(fn ($d) => $d->format('Y-m-d'))->all();
        foreach ([1, 3, 7] as $offset) {
            $expected = $anchor->copy()->addDays($offset)->format('Y-m-d');
            $this->assertContains(
                $expected,
                $dates,
                "expected a Day {$offset} reminder at {$expected}"
            );
        }
    }

    public function test_resending_shoot_ready_does_not_duplicate_reminder_rows(): void
    {
        $this->seedReadyTemplate();
        $this->mockMessaging();

        $sender = User::factory()->create(['role' => 'admin']);
        $shoot = $this->makeUnpaidShoot();

        $service = app(ManualNotificationService::class);

        $service->send($shoot, 'shoot_ready', 'client', 'email', $sender);
        $countAfterFirst = PaymentReminder::where('shoot_id', $shoot->id)->count();
        $this->assertGreaterThan(0, $countAfterFirst);

        // Re-send the ready notification — the (shoot_id, scheduled_date) upsert must not
        // create duplicate rows (Req 4.5).
        $service->send($shoot->fresh(), 'shoot_ready', 'client', 'email', $sender);
        $countAfterSecond = PaymentReminder::where('shoot_id', $shoot->id)->count();

        $this->assertSame($countAfterFirst, $countAfterSecond, 'resending shoot_ready must not duplicate reminders');

        // No duplicate (shoot_id, scheduled_date) pairs.
        $rows = PaymentReminder::where('shoot_id', $shoot->id)->get();
        $this->assertSame(
            $rows->count(),
            $rows->pluck('scheduled_date')->map(fn ($d) => $d->format('Y-m-d'))->unique()->count(),
            'each scheduled_date must be unique per shoot'
        );
    }

    public function test_paid_shoot_ready_send_schedules_no_reminders(): void
    {
        $this->seedReadyTemplate();
        $this->mockMessaging();

        $sender = User::factory()->create(['role' => 'admin']);
        $client = User::factory()->create(['email' => 'paid@example.com', 'name' => 'Paid Client']);
        $shoot = Shoot::factory()->create([
            'client_id'               => $client->id,
            'payment_status'          => 'paid',
            'shoot_ready_notified_at' => null,
        ]);

        app(ManualNotificationService::class)
            ->send($shoot, 'shoot_ready', 'client', 'email', $sender);

        // Req 4.3 — a paid shoot starts no cadence.
        $this->assertSame(
            0,
            PaymentReminder::where('shoot_id', $shoot->id)->count(),
            'a paid shoot must not schedule any payment reminders'
        );
    }
}
