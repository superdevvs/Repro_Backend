<?php

namespace Tests\Feature;

use App\Jobs\FinalizeShootJob;
use App\Jobs\SendShootReadyEmailJob;
use App\Models\MessageChannel;
use App\Models\Message;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\SystemEmailDispatch;
use App\Models\User;
use App\Services\MailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Bug-condition exploration tests for QA issue #3
 * ("Delivery email must clearly say delivered").
 *
 * Property 1 (Bug Condition): the canonical delivery email must fire reliably
 * on the full-order delivered transition and must be free of content defects.
 *
 * These tests encode the EXPECTED (post-fix) behaviour. They are written to
 * FAIL on the current (unfixed) code, which confirms the bug exists:
 *   - Case A: the email-health gate silently suppresses the delivery email.
 *   - Case B: the media-presence gate skips the delivery email on no-media deliveries.
 *   - Case C: rendered email exposes client financials (Subtotal/Tax/Total).
 *   - Case D: rendered email shows the wrong support phone (202-868-1113).
 *   - Case E: rendered email contains "Leave a Review" filler + a duplicate Website URL tile.
 *   - Case F: rendered hero/eyebrow label does not match the subject.
 *
 * Validates: Requirements 2.1, 2.2, 2.3, 2.4, 2.5, 2.6, 2.7
 */
class DeliveryEmailBugConditionTest extends TestCase
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

    /**
     * Full-order deliverable shoot with one service line and one edited
     * (completed-stage) file, ready to finalize/deliver.
     */
    private function createDeliverableShoot(User $client, array $overrides = []): Shoot
    {
        $service = Service::factory()->create([
            'name' => 'Delivery Test Service',
            'price' => 250.00,
        ]);

        $shoot = Shoot::factory()->create(array_merge([
            'client_id' => $client->id,
            'service_id' => $service->id,
            'address' => '742 QA Delivery Test Ave',
            'city' => 'Washington',
            'state' => 'DC',
            'zip' => '20001',
            'status' => Shoot::STATUS_READY,
            'workflow_status' => Shoot::STATUS_READY,
            'base_quote' => 250.00,
            'total_quote' => 250.00,
        ], $overrides));

        $shoot->services()->attach($service->id, [
            'price' => $service->price,
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $shoot->fresh(['client', 'services']);
    }

    private function createEditedFile(Shoot $shoot, User $uploadedBy): ShootFile
    {
        return ShootFile::create([
            'shoot_id' => $shoot->id,
            'filename' => 'final.jpg',
            'stored_filename' => 'final.jpg',
            'path' => "shoots/{$shoot->id}/completed/final.jpg",
            'file_type' => 'image/jpeg',
            'file_size' => 1024,
            'media_type' => 'edited',
            'uploaded_by' => $uploadedBy->id,
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
        ]);
    }

    /**
     * Render the canonical delivery email through the real pipeline and return
     * its stored HTML body.
     */
    private function renderDeliveredEmailHtml(): string
    {
        Mail::fake();
        $this->createDefaultEmailChannel();

        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'delivered-content@example.com',
            'name' => 'Delivered Content Client',
            'email_status' => 'verified',
        ]);

        $shoot = $this->createDeliverableShoot($client);

        /** @var MailService $mail */
        $mail = $this->app->make(MailService::class);
        $mail->sendShootReadyEmail($client, $shoot);

        $message = Message::query()
            ->where('related_shoot_id', $shoot->id)
            ->where('send_source', 'SHOOT_DELIVERED')
            ->latest('id')
            ->first();

        $this->assertNotNull($message, 'Expected a SHOOT_DELIVERED message to be recorded for the delivered email.');

        return (string) $message->body_html;
    }

    // ---------------------------------------------------------------------
    // Case A: email-health gate suppression
    // ---------------------------------------------------------------------
    public function test_case_a_health_gate_does_not_suppress_delivery_email(): void
    {
        Mail::fake();
        $this->createDefaultEmailChannel();

        // A bounced client trips the email-health gate (SHOOT_DELIVERED is not
        // on the hard-failure allowlist), suppressing the delivery email on
        // unfixed code.
        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'bounced-client@example.com',
            'name' => 'Bounced Client',
            'email_status' => 'bounced',
        ]);

        $shoot = $this->createDeliverableShoot($client);

        /** @var MailService $mail */
        $mail = $this->app->make(MailService::class);
        $mail->sendShootReadyEmail($client, $shoot);

        $this->assertSame(
            1,
            SystemEmailDispatch::query()
                ->where('email_alias', 'SHOOT_DELIVERED')
                ->where('related_shoot_id', $shoot->id)
                ->where('recipient_email', $client->email)
                ->count(),
            'The mandatory delivery email must not be silently suppressed by the email-health gate.'
        );
    }

    // ---------------------------------------------------------------------
    // Case B: no-media (fast-forward) delivery
    // ---------------------------------------------------------------------
    public function test_case_b_no_media_full_order_delivery_dispatches_ready_email(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        $client = User::factory()->create(['role' => 'client']);

        // A no-media, full-order delivery (no completed files at all).
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'service_id' => null,
            'status' => Shoot::STATUS_READY,
            'workflow_status' => Shoot::STATUS_READY,
            'shoot_type' => Shoot::SHOOT_TYPE_SAMPLE_UPLOAD,
            'product_status' => Shoot::PRODUCT_STATUS_NO_PRODUCT,
            'base_quote' => 0,
            'tax_amount' => 0,
            'total_quote' => 0,
            'payment_status' => 'paid',
            'bypass_paywall' => true,
        ]);

        (new FinalizeShootJob($shoot->id, $admin->id, 'completed', null, true))
            ->handle(app(\App\Services\ShootActivityLogger::class));

        Queue::assertPushed(
            SendShootReadyEmailJob::class,
            fn (SendShootReadyEmailJob $job) => $job->shootId === $shoot->id && $job->isFullOrderDelivery === true
        );
    }

    // ---------------------------------------------------------------------
    // Case C: client financials must be suppressed
    // ---------------------------------------------------------------------
    public function test_case_c_delivered_email_does_not_expose_financials(): void
    {
        $html = $this->renderDeliveredEmailHtml();

        $this->assertStringNotContainsString(
            'Subtotal',
            $html,
            'The delivered email must not expose the financial breakdown (Subtotal/Tax/Total).'
        );
    }

    // ---------------------------------------------------------------------
    // Case D: correct support phone
    // ---------------------------------------------------------------------
    public function test_case_d_delivered_email_uses_correct_support_phone(): void
    {
        $html = $this->renderDeliveredEmailHtml();

        $this->assertStringContainsString('(202) 868-1663', $html, 'The delivered email must show the correct support phone.');
        $this->assertStringNotContainsString('202-868-1113', $html, 'The delivered email must not show the wrong support phone.');
    }

    // ---------------------------------------------------------------------
    // Case E: single canonical URL, no "Leave a Review" filler
    // ---------------------------------------------------------------------
    public function test_case_e_delivered_email_has_single_url_and_no_review_filler(): void
    {
        $html = $this->renderDeliveredEmailHtml();

        $this->assertStringNotContainsString('Leave a Review', $html, 'The delivered email must not contain the "Leave a Review" filler.');
        $this->assertStringNotContainsString('>Website</a>', $html, 'The delivered email must present a single canonical URL (no duplicate Website tile).');
    }

    // ---------------------------------------------------------------------
    // Case F: hero/eyebrow label matches subject
    // ---------------------------------------------------------------------
    public function test_case_f_delivered_email_hero_label_matches_subject(): void
    {
        $html = $this->renderDeliveredEmailHtml();

        $this->assertStringContainsString(
            '>Your Shoot Has Been Delivered</p>',
            $html,
            'The delivered email hero/eyebrow label must match the subject "Your Shoot Has Been Delivered".'
        );
    }
}
