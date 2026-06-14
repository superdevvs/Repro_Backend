<?php

namespace Tests\Feature;

use App\Jobs\SendShootReadyEmailJob;
use App\Models\PaymentReminder;
use App\Models\Shoot;
use App\Models\User;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Feature: per-shoot-notifications-payment-reminders, Task 3 — Gap C anchor wiring.
 *
 * Validates: Requirements 4.2, 4.3, 4.4, 4.5
 *
 * Unlike the manual `shoot_ready` send (Task 2), the automated ready/delivered path
 * `SendShootReadyEmailJob` must also start the payment-reminder cadence. For a successful
 * full-order delivery the job must:
 *   (1) stamp `shoot_ready_notified_at` when it is not already set (the cadence anchor) — Req 4.2, and
 *   (2) start the cadence by creating pending PaymentReminder rows at Day 1/3/7 (at minimum) — Req 4.4.
 *
 * If the anchor is already set, the job must NOT move it (anchor stability, Req 4.2) and must NOT
 * duplicate reminder rows (the (shoot_id, scheduled_date) upsert is idempotent — Req 4.5). A shoot
 * that is already paid must schedule no reminders (Req 4.3).
 *
 * MailService is mocked so no real email goes out; the real AutomationService is resolved from the
 * container (the wiring under test), so the PaymentReminder rows it persists are exercised end to end.
 */
class SendShootReadyEmailJobCadenceWiringTest extends TestCase
{
    use RefreshDatabase;

    private function mockMail(): void
    {
        $this->mock(MailService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendShootReadyEmail')->zeroOrMoreTimes()->andReturnNull();
        });
    }

    private function makeUnpaidShoot(?CarbonImmutable $anchor = null): Shoot
    {
        $client = User::factory()->create([
            'email' => 'client@example.com',
            'name'  => 'Casey Client',
        ]);

        // ShootFactory defaults payment_status to 'paid', so set 'unpaid' explicitly.
        return Shoot::factory()->create([
            'client_id'               => $client->id,
            'payment_status'          => 'unpaid',
            'shoot_ready_notified_at' => $anchor,
        ]);
    }

    private function runJob(Shoot $shoot): void
    {
        (new SendShootReadyEmailJob($shoot->id, null, true, true))
            ->handle(app(MailService::class), app(AutomationService::class));
    }

    public function test_full_order_delivery_stamps_anchor_and_starts_cadence(): void
    {
        $this->mockMail();

        $shoot = $this->makeUnpaidShoot();

        $this->runJob($shoot);

        $fresh = $shoot->fresh();

        // Req 4.2 — the anchor is stamped by the automated path.
        $this->assertNotNull(
            $fresh->shoot_ready_notified_at,
            'automated full-order delivery must stamp the cadence anchor'
        );

        // Req 4.2 / 4.4 — pending reminders now exist for this shoot.
        $reminders = PaymentReminder::where('shoot_id', $shoot->id)->get();
        $this->assertGreaterThan(0, $reminders->count(), 'automated delivery must start the payment-reminder cadence');
        $this->assertTrue(
            $reminders->every(fn (PaymentReminder $r) => $r->status === PaymentReminder::STATUS_PENDING),
            'all freshly scheduled reminders should be pending'
        );

        // Req 4.4 — the Day 1/3/7 timestamps relative to the anchor are present.
        $anchor = $fresh->shoot_ready_notified_at->copy();
        $dates = $reminders->pluck('scheduled_date')->map(fn ($d) => $d->format('Y-m-d'))->all();
        foreach ([1, 3, 7] as $offset) {
            $expected = $anchor->copy()->addDays($offset)->format('Y-m-d');
            $this->assertContains($expected, $dates, "expected a Day {$offset} reminder at {$expected}");
        }
    }

    public function test_existing_anchor_is_not_moved_and_reminders_not_duplicated(): void
    {
        $this->mockMail();

        // Anchor already set well in the past; the job must NOT move it.
        $anchor = CarbonImmutable::parse('2026-01-01 10:00:00');
        $shoot = $this->makeUnpaidShoot($anchor);

        // First run establishes the schedule against the pre-existing anchor.
        $this->runJob($shoot);
        $countAfterFirst = PaymentReminder::where('shoot_id', $shoot->id)->count();
        $this->assertGreaterThan(0, $countAfterFirst);

        // Anchor must remain exactly where it was (Req 4.2 anchor stability).
        $this->assertSame(
            $anchor->toDateTimeString(),
            $shoot->fresh()->shoot_ready_notified_at->toDateTimeString(),
            'an already-set anchor must not be moved by a re-run'
        );

        // Second run must not duplicate reminder rows (Req 4.5).
        $this->runJob($shoot->fresh());
        $countAfterSecond = PaymentReminder::where('shoot_id', $shoot->id)->count();
        $this->assertSame($countAfterFirst, $countAfterSecond, 're-running the job must not duplicate reminders');

        // Anchor still unchanged after the second run.
        $this->assertSame(
            $anchor->toDateTimeString(),
            $shoot->fresh()->shoot_ready_notified_at->toDateTimeString(),
            'anchor must remain stable across repeated runs'
        );

        // No duplicate (shoot_id, scheduled_date) pairs.
        $rows = PaymentReminder::where('shoot_id', $shoot->id)->get();
        $this->assertSame(
            $rows->count(),
            $rows->pluck('scheduled_date')->map(fn ($d) => $d->format('Y-m-d'))->unique()->count(),
            'each scheduled_date must be unique per shoot'
        );
    }

    public function test_paid_shoot_schedules_no_reminders(): void
    {
        $this->mockMail();

        $client = User::factory()->create(['email' => 'paid@example.com', 'name' => 'Paid Client']);
        $shoot = Shoot::factory()->create([
            'client_id'               => $client->id,
            'payment_status'          => 'paid',
            'shoot_ready_notified_at' => null,
        ]);

        $this->runJob($shoot);

        // Req 4.3 — a paid shoot starts no cadence (even though the anchor may be stamped).
        $this->assertSame(
            0,
            PaymentReminder::where('shoot_id', $shoot->id)->count(),
            'a paid shoot must not schedule any payment reminders'
        );
    }
}
