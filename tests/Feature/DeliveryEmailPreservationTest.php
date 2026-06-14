<?php

namespace Tests\Feature;

use App\Jobs\FinalizeShootJob;
use App\Jobs\PublishShootToBrightMlsJob;
use App\Jobs\SendShootReadyEmailJob;
use App\Models\AutomationRule;
use App\Models\Message;
use App\Models\MessageChannel;
use App\Models\MessageTemplate;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootActivityLog;
use App\Models\ShootFile;
use App\Models\Payment;
use App\Models\SystemEmailDispatch;
use App\Models\User;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\ShootActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Preservation tests for QA issue #3.
 *
 * Property 2 (Preservation): inputs where the bug condition does NOT hold must
 * behave exactly as before — non-delivered notifications, finalize side effects,
 * and the shared layout's behavior for non-delivered templates.
 *
 * These tests are written to PASS on the current (unfixed) code, capturing the
 * baseline behavior that the fix must preserve.
 *
 * Validates: Requirements 3.1, 3.2, 3.3, 3.4
 */
class DeliveryEmailPreservationTest extends TestCase
{
    use RefreshDatabase;

    private function createDefaultEmailChannel(): MessageChannel
    {
        return MessageChannel::create([
            'type' => 'EMAIL',
            'provider' => 'LOCAL_SMTP',
            'display_name' => 'Default',
            'from_email' => 'contact@reprophotos.com',
            'is_default' => true,
            'owner_scope' => 'GLOBAL',
        ]);
    }

    private function createShootForClient(User $client, array $overrides = []): Shoot
    {
        $service = Service::factory()->create([
            'name' => 'Preservation Service',
            'price' => 200.00,
        ]);

        $shoot = Shoot::factory()->create(array_merge([
            'client_id' => $client->id,
            'service_id' => $service->id,
            'address' => '900 Preservation Way',
            'city' => 'Washington',
            'state' => 'DC',
            'zip' => '20001',
            'status' => Shoot::STATUS_READY,
            'workflow_status' => Shoot::STATUS_READY,
            'base_quote' => 200.00,
            'total_quote' => 200.00,
            'scheduled_at' => now()->addDays(2)->setTime(11, 0),
            'scheduled_date' => now()->addDays(2)->toDateString(),
            'time' => '11:00',
        ], $overrides));

        $shoot->services()->attach($service->id, [
            'price' => $service->price,
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $shoot->fresh(['client', 'services']);
    }

    // ---------------------------------------------------------------------
    // Req 3.3 - finalize side effects unchanged
    // ---------------------------------------------------------------------
    public function test_finalize_side_effects_are_preserved(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        $client = User::factory()->create(['role' => 'client']);
        $shoot = $this->createShootForClient($client);

        ShootFile::create([
            'shoot_id' => $shoot->id,
            'filename' => 'final.jpg',
            'stored_filename' => 'final.jpg',
            'path' => "shoots/{$shoot->id}/completed/final.jpg",
            'file_type' => 'image/jpeg',
            'file_size' => 1024,
            'media_type' => 'edited',
            'uploaded_by' => $admin->id,
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
        ]);

        (new FinalizeShootJob($shoot->id, $admin->id, 'completed'))
            ->handle(app(ShootActivityLogger::class));

        $shoot->refresh();
        $this->assertSame(Shoot::STATUS_DELIVERED, $shoot->workflow_status);
        $this->assertSame(ShootFile::STAGE_VERIFIED, $shoot->files()->first()->workflow_stage);

        $this->assertSame(
            1,
            ShootActivityLog::query()
                ->where('shoot_id', $shoot->id)
                ->where('action', 'shoot_finalized_delivered')
                ->count()
        );

        // Media-dependent side effects still dispatched when media is present.
        Queue::assertPushed(SendShootReadyEmailJob::class, fn (SendShootReadyEmailJob $job) => $job->shootId === $shoot->id);
        Queue::assertPushed(PublishShootToBrightMlsJob::class, fn (PublishShootToBrightMlsJob $job) => $job->shootId === $shoot->id);
    }

    // ---------------------------------------------------------------------
    // Req 3.1 / 3.2 - non-delivered notifications still sent
    // ---------------------------------------------------------------------
    public function test_booking_scheduled_email_still_sent(): void
    {
        Mail::fake();
        $this->createDefaultEmailChannel();

        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'preservation-booking@example.com',
            'email_status' => 'verified',
        ]);
        $shoot = $this->createShootForClient($client, [
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ]);

        /** @var MailService $mail */
        $mail = $this->app->make(MailService::class);
        $this->assertTrue($mail->sendShootScheduledEmail($client, $shoot, 'https://reprodashboard.com/pay/test', false));

        $this->assertSame(
            1,
            SystemEmailDispatch::query()
                ->where('email_alias', 'SHOOT_SCHEDULED')
                ->where('related_shoot_id', $shoot->id)
                ->where('recipient_email', $client->email)
                ->count()
        );

        // The booking notification keeps its "New Shoot Scheduled" subject.
        $message = Message::query()
            ->where('related_shoot_id', $shoot->id)
            ->where('send_source', 'SHOOT_SCHEDULED')
            ->latest('id')
            ->first();
        $this->assertNotNull($message);
        $this->assertSame('New Shoot Scheduled', (string) $message->subject);
    }

    public function test_updated_email_still_sent(): void
    {
        Mail::fake();
        $this->createDefaultEmailChannel();

        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'preservation-updated@example.com',
            'email_status' => 'verified',
        ]);
        $shoot = $this->createShootForClient($client, [
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ]);

        /** @var MailService $mail */
        $mail = $this->app->make(MailService::class);
        $this->assertTrue($mail->sendShootUpdatedEmail($client, $shoot, 'Time changed to 2:00 PM', true, false));

        $this->assertSame(
            1,
            SystemEmailDispatch::query()
                ->where('email_alias', 'SHOOT_UPDATED')
                ->where('related_shoot_id', $shoot->id)
                ->where('recipient_email', $client->email)
                ->count()
        );
    }

    public function test_reminder_email_still_sent(): void
    {
        Mail::fake();
        $this->createDefaultEmailChannel();

        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'preservation-reminder@example.com',
            'email_status' => 'verified',
        ]);
        $shoot = $this->createShootForClient($client, [
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ]);

        /** @var MailService $mail */
        $mail = $this->app->make(MailService::class);
        $this->assertTrue($mail->sendShootReminderEmail($client, $shoot, now()->addDay(), [], false));

        $this->assertSame(
            1,
            SystemEmailDispatch::query()
                ->where('email_alias', 'SHOOT_REMINDER')
                ->where('related_shoot_id', $shoot->id)
                ->where('recipient_email', $client->email)
                ->count()
        );
    }

    public function test_payment_confirmation_email_still_sent(): void
    {
        Mail::fake();
        $this->createDefaultEmailChannel();

        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'preservation-payment@example.com',
            'email_status' => 'verified',
        ]);
        $shoot = $this->createShootForClient($client);

        $payment = Payment::factory()->create([
            'shoot_id' => $shoot->id,
            'amount' => 200.00,
        ]);

        /** @var MailService $mail */
        $mail = $this->app->make(MailService::class);
        $this->assertTrue($mail->sendPaymentConfirmationEmail($client, $shoot, $payment));

        $this->assertSame(
            1,
            SystemEmailDispatch::query()
                ->where('email_alias', 'PAYMENT_CONFIRMATION')
                ->where('related_shoot_id', $shoot->id)
                ->where('recipient_email', $client->email)
                ->count()
        );
    }

    public function test_cancellation_email_still_sent(): void
    {
        Mail::fake();
        $this->createDefaultEmailChannel();

        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'preservation-cancel@example.com',
            'email_status' => 'verified',
        ]);
        $shoot = $this->createShootForClient($client, [
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ]);

        /** @var MailService $mail */
        $mail = $this->app->make(MailService::class);
        $this->assertTrue($mail->sendShootCancelledEmail($client, $shoot));

        $this->assertSame(
            1,
            SystemEmailDispatch::query()
                ->where('email_alias', 'SHOOT_CANCELLED')
                ->where('related_shoot_id', $shoot->id)
                ->where('recipient_email', $client->email)
                ->count()
        );
    }

    // ---------------------------------------------------------------------
    // Req 3.4 - shared layout behavior preserved for non-delivered templates
    // The footer "Website" tile and "Leave a Review" tile must remain present
    // on non-delivered templates (default-on toggles preserve their output).
    // ---------------------------------------------------------------------
    public function test_shared_layout_footer_tiles_preserved_for_non_delivered_template(): void
    {
        Mail::fake();
        $this->createDefaultEmailChannel();

        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'preservation-layout@example.com',
            'email_status' => 'verified',
        ]);
        $shoot = $this->createShootForClient($client, [
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ]);

        /** @var MailService $mail */
        $mail = $this->app->make(MailService::class);
        $mail->sendShootScheduledEmail($client, $shoot, 'https://reprodashboard.com/pay/test', false);

        $message = Message::query()
            ->where('related_shoot_id', $shoot->id)
            ->where('send_source', 'SHOOT_SCHEDULED')
            ->latest('id')
            ->first();

        $this->assertNotNull($message);
        $html = (string) $message->body_html;

        $this->assertStringContainsString('>Website</a>', $html, 'Non-delivered templates must keep the footer Website tile.');
        $this->assertStringContainsString('Leave a Review', $html, 'Non-delivered templates must keep the "Leave a Review" tile.');
    }

    // ---------------------------------------------------------------------
    // Req 3.4 - non-delivered templates render with their original content.
    // The content corrections (financials toggle, single URL, no review tile,
    // hero label) are scoped to the delivered email only, so non-delivered
    // templates keep their subject, financial summary, and footer tiles.
    // ---------------------------------------------------------------------
    public function test_non_delivered_templates_render_with_preserved_content(): void
    {
        Mail::fake();
        $this->createDefaultEmailChannel();

        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'preservation-render@example.com',
            'email_status' => 'verified',
        ]);
        $shoot = $this->createShootForClient($client, [
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ]);

        /** @var MailService $mail */
        $mail = $this->app->make(MailService::class);

        // Booking, reminder and cancellation are representative non-delivered
        // notifications. Each must retain the shared-layout footer tiles that
        // are only removed on the delivered email.
        $mail->sendShootScheduledEmail($client, $shoot, 'https://reprodashboard.com/pay/test', false);
        $mail->sendShootReminderEmail($client, $shoot, now()->addDay(), [], false);
        $mail->sendShootCancelledEmail($client, $shoot);

        $cases = [
            'SHOOT_SCHEDULED' => 'New Shoot Scheduled',
            'SHOOT_REMINDER' => 'Shoot Reminder: 24 Hours to Go',
            'SHOOT_CANCELLED' => 'Your Shoot Has Been Cancelled',
        ];

        foreach ($cases as $source => $expectedSubject) {
            $message = Message::query()
                ->where('related_shoot_id', $shoot->id)
                ->where('send_source', $source)
                ->latest('id')
                ->first();

            $this->assertNotNull($message, "Expected a {$source} message to be rendered.");
            $this->assertSame($expectedSubject, (string) $message->subject);

            $html = (string) $message->body_html;
            // Shared-layout footer tiles remain present on non-delivered
            // templates (default-on toggles preserve their output).
            $this->assertStringContainsString('>Website</a>', $html, "{$source} must keep the footer Website tile.");
            $this->assertStringContainsString('Leave a Review', $html, "{$source} must keep the \"Leave a Review\" tile.");
            // The corrected canonical support phone is rendered everywhere.
            $this->assertStringContainsString('202-868-1113', $html, "{$source} must render the support phone.");
        }
    }

    // ---------------------------------------------------------------------
    // Req 3.1 / 3.2 - duplicate-avoidance baseline.
    // When both the protected delivery path AND the SHOOT_COMPLETED automation
    // event execute (the full SendShootReadyEmailJob flow), exactly one client
    // delivery email is recorded.
    // ---------------------------------------------------------------------
    public function test_exactly_one_client_delivery_email_when_both_paths_fire(): void
    {
        Mail::fake();
        $this->createDefaultEmailChannel();

        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'preservation-dupe@example.com',
            'email_status' => 'verified',
        ]);
        $shoot = $this->createShootForClient($client, [
            'status' => Shoot::STATUS_DELIVERED,
            'workflow_status' => Shoot::STATUS_DELIVERED,
        ]);

        // Drives the protected delivery send AND fires the SHOOT_COMPLETED
        // automation event (with the system_email_already_sent flag) inline.
        (new SendShootReadyEmailJob($shoot->id, null, true, true))
            ->handle($this->app->make(MailService::class), $this->app->make(AutomationService::class));

        $this->assertSame(
            1,
            SystemEmailDispatch::query()
                ->where('email_alias', 'SHOOT_DELIVERED')
                ->where('related_shoot_id', $shoot->id)
                ->where('recipient_email', $client->email)
                ->count(),
            'Exactly one client delivery email must be recorded when both delivery paths execute.'
        );

        // Belt-and-suspenders: re-invoking the canonical send (as the
        // automation executor would) is deduplicated by the idempotency key,
        // so still exactly one client delivery email exists.
        $this->app->make(MailService::class)->sendShootReadyEmail($client, $shoot->fresh());

        $this->assertSame(
            1,
            SystemEmailDispatch::query()
                ->where('email_alias', 'SHOOT_DELIVERED')
                ->where('related_shoot_id', $shoot->id)
                ->where('recipient_email', $client->email)
                ->count(),
            'Re-sending the canonical delivery email must not create a duplicate.'
        );
    }
}
