<?php

namespace Tests\Feature;

use App\Jobs\NotifyShootDeliveryUpdate;
use App\Jobs\SendShootReadyEmailJob;
use App\Models\Message;
use App\Models\MessageChannel;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\OutboundDeliveryGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ShootDeliveryContentUpdatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('America/New_York')->setTime(12, 0));
        Mail::fake();
        Queue::fake();
        OutboundDeliveryGuard::allowFakeProviderPipelineForTesting();
        MessageChannel::create([
            'type' => 'EMAIL', 'provider' => 'LOCAL_SMTP', 'display_name' => 'Test delivery',
            'from_email' => 'delivery@example.test', 'is_default' => true, 'owner_scope' => 'GLOBAL',
        ]);
    }

    public function test_finalized_new_content_sends_one_update_and_retries_reuse_it(): void
    {
        $shoot = $this->shoot();
        $file = $this->file($shoot);
        $this->notify($shoot);
        $this->assertSame(1, $this->messages($shoot)->count());

        // Notes, ordering and derivative image generation do not change delivery identity.
        $shoot->update(['shoot_notes' => 'Internal note']);
        $file->update(['sort_order' => 99, 'thumbnail_path' => 'new-thumbnail.jpg']);
        $this->notify($shoot);
        $this->assertSame(1, $this->messages($shoot)->count());

        // This is the mutation made by MediaVersionPublisher for an approved edit.
        $file->update(['content_version' => 2, 'path' => 'shoots/revised.jpg', 'storage_path' => 'shoots/revised.jpg']);
        $this->notify($shoot);
        $this->notify($shoot);
        $this->assertSame(2, $this->messages($shoot)->count());
        $latest = $this->messages($shoot)->last();
        $this->assertStringContainsString('Shoot Update', $latest->subject);
        $this->assertStringContainsString('New or revised content', $latest->body_html);
        $this->assertNotSame(
            data_get($this->messages($shoot)->first()->metadata, 'canonical_email.delivery_revision'),
            data_get($latest->metadata, 'canonical_email.delivery_revision'),
        );
    }

    public function test_pending_review_content_does_not_change_delivered_revision(): void
    {
        $shoot = $this->shoot();
        $file = $this->file($shoot, ['workflow_stage' => ShootFile::STAGE_COMPLETED, 'verified_at' => null]);
        $this->notify($shoot);
        $this->assertSame(1, $this->messages($shoot)->count());
        $file->update(['content_version' => 2]);
        $this->notify($shoot);
        $this->assertSame(1, $this->messages($shoot)->count());

        $file->update(['workflow_stage' => ShootFile::STAGE_VERIFIED, 'verified_at' => now()]);
        $this->notify($shoot);
        $this->assertSame(2, $this->messages($shoot)->count());
    }

    public function test_hiding_or_removing_released_media_sends_a_new_revision_without_exposing_pending_work(): void
    {
        $shoot = $this->shoot();
        $first = $this->file($shoot);
        $second = $this->file($shoot);
        $this->notify($shoot);
        $first->update(['is_hidden' => true]);
        $this->notify($shoot);
        $this->assertSame(2, $this->messages($shoot)->count());
        $second->delete();
        $this->notify($shoot);
        $this->notify($shoot);
        $this->assertSame(3, $this->messages($shoot)->count());
    }

    public function test_saved_delivery_link_change_sends_once_but_tracking_metadata_does_not(): void
    {
        $shoot = $this->shoot();
        $this->notify($shoot);
        $shoot->update(['tour_links' => ['branded' => 'https://example.test/new-tour']]);
        $this->notify($shoot);
        $this->assertSame(2, $this->messages($shoot)->count());
        $shoot->update(['tour_links' => ['branded' => 'https://example.test/new-tour', 'realtor_client_id' => 'internal-id']]);
        $this->notify($shoot);
        $this->assertSame(2, $this->messages($shoot)->count());
    }

    public function test_update_job_rechecks_delivery_and_suppression_before_sending(): void
    {
        $shoot = $this->shoot();
        $shoot->update(['workflow_status' => Shoot::STATUS_CANCELLED, 'status' => Shoot::STATUS_CANCELLED]);
        $this->notify($shoot);
        $this->assertSame(0, $this->messages($shoot)->count());
    }

    public function test_actual_publication_and_link_saves_enqueue_coalesced_updates_but_pending_work_does_not(): void
    {
        $shoot = $this->shoot();
        $file = $this->file($shoot, ['workflow_stage' => ShootFile::STAGE_COMPLETED, 'verified_at' => null]);
        Queue::assertNotPushed(NotifyShootDeliveryUpdate::class);
        $file->update(['thumbnail_path' => 'preview.jpg']);
        $shoot->update(['shoot_notes' => 'Internal instructions']);
        Queue::assertNotPushed(NotifyShootDeliveryUpdate::class);

        $file->update(['workflow_stage' => ShootFile::STAGE_VERIFIED, 'verified_at' => now()]);
        Queue::assertPushed(NotifyShootDeliveryUpdate::class, fn ($job) => $job->shootId === $shoot->id
            && $job->shootServiceId === null && $job->afterCommit && $job->queue === 'default');

        $second = $this->shoot();
        $second->update(['tour_links' => ['mls' => 'https://example.test/published-tour']]);
        Queue::assertPushed(NotifyShootDeliveryUpdate::class, fn ($job) => $job->shootId === $second->id);
    }

    public function test_partial_finalize_queues_client_delivery_email(): void
    {
        $shoot = $this->shoot(['workflow_status' => Shoot::STATUS_EDITING, 'status' => Shoot::STATUS_EDITING, 'delivery_status' => 'not_started']);
        $first = \App\Models\Service::factory()->create(['name' => 'Photos']);
        $second = \App\Models\Service::factory()->create(['name' => 'Floor plans']);
        $shoot->services()->attach($first->id, ['delivery_status' => 'ready', 'workflow_status' => 'ready', 'is_deliverable' => true]);
        $shoot->services()->attach($second->id, ['delivery_status' => 'not_started', 'workflow_status' => 'in_progress', 'is_deliverable' => true]);
        $serviceId = (int) $shoot->serviceItems()->where('service_id', $first->id)->first()->id;
        $this->file($shoot, ['shoot_service_id' => $serviceId, 'workflow_stage' => ShootFile::STAGE_COMPLETED, 'verified_at' => null]);
        $admin = User::factory()->create(['role' => 'admin']);

        (new \App\Jobs\FinalizeShootJob($shoot->id, $admin->id, null, $serviceId))
            ->handle(app(\App\Services\ShootActivityLogger::class));
        Queue::assertPushed(SendShootReadyEmailJob::class, fn ($job) => $job->shootId === $shoot->id
            && $job->shootServiceId === $serviceId && ! $job->isFullOrderDelivery);
    }

    public function test_partial_notification_names_only_delivered_services_without_claiming_complete_shoot(): void
    {
        $shoot = $this->shoot(['workflow_status' => Shoot::STATUS_EDITING, 'status' => Shoot::STATUS_EDITING, 'delivery_status' => 'partial']);
        $service = \App\Models\Service::factory()->create(['name' => 'Floor plans']);
        $shoot->services()->attach($service->id, ['delivery_status' => 'delivered', 'workflow_status' => 'delivered']);
        $serviceId = (int) $shoot->serviceItems()->first()->id;

        (new SendShootReadyEmailJob($shoot->id, $serviceId, false, false))
            ->handle(app(MailService::class), app(AutomationService::class));
        $message = Message::where('related_shoot_id', $shoot->id)->where('send_source', 'SHOOT_DELIVERED')->sole();
        $this->assertStringContainsString('Part of Your Shoot Is Ready', $message->subject);
        $this->assertStringContainsString('Other ordered services are still in progress', $message->body_html);
        $this->assertStringContainsString('Floor plans', $message->body_html);
        $this->assertStringNotContainsString('Your shoot is complete and ready to review', $message->body_html);
    }

    public function test_link_update_for_a_partial_delivery_names_only_finalized_segments(): void
    {
        $shoot = $this->shoot(['workflow_status' => Shoot::STATUS_EDITING, 'status' => Shoot::STATUS_EDITING, 'delivery_status' => 'partial']);
        foreach (['Floor plans' => 'delivered', 'Pending Photos' => 'not_started'] as $name => $status) {
            $service = \App\Models\Service::factory()->create(['name' => $name]);
            $shoot->services()->attach($service->id, ['delivery_status' => $status, 'workflow_status' => $status]);
        }
        $shoot->update(['tour_links' => ['mls' => 'https://example.test/finalized-plan']]);
        Queue::assertPushed(NotifyShootDeliveryUpdate::class, fn ($job) => $job->shootId === $shoot->id);
        $this->notify($shoot);
        $message = Message::where('related_shoot_id', $shoot->id)->where('send_source', 'SHOOT_DELIVERED')->sole();
        $this->assertStringContainsString('Floor plans', $message->body_html);
        $this->assertStringNotContainsString('Pending Photos', $message->body_html);
        $this->assertStringNotContainsString('https://example.test/finalized-plan', $message->body_html);
        $this->assertStringNotContainsString('Share &amp; Download Links', $message->body_html);
        $this->assertStringContainsString('Open Deliverables', $message->body_html);
    }

    public function test_partial_email_contains_verified_service_scoped_downloads_and_no_pending_video_or_override_leak(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('shoots/original.jpg', str_repeat('x', 20));
        $shoot = $this->shoot(['workflow_status' => Shoot::STATUS_EDITING, 'status' => Shoot::STATUS_EDITING, 'delivery_status' => 'partial']);
        $photos = \App\Models\Service::factory()->create(['name' => 'Final Photos', 'upload_intake_type' => 'photo']);
        $video = \App\Models\Service::factory()->create(['name' => 'Pending Video', 'upload_intake_type' => 'video']);
        $shoot->services()->attach($photos->id, ['delivery_status' => 'delivered', 'workflow_status' => 'delivered', 'is_deliverable' => true]);
        $shoot->services()->attach($video->id, ['delivery_status' => 'not_started', 'workflow_status' => 'in_progress', 'is_deliverable' => true]);
        $photoId = (int) $shoot->serviceItems()->where('service_id', $photos->id)->first()->id;
        $videoId = (int) $shoot->serviceItems()->where('service_id', $video->id)->first()->id;
        $this->file($shoot, ['shoot_service_id' => $photoId, 'required_for_editing' => true]);
        $this->file($shoot, ['shoot_service_id' => $videoId, 'media_type' => 'video', 'path' => 'https://example.test/pending.mp4']);
        $this->file($shoot, ['shoot_service_id' => $photoId, 'workflow_stage' => ShootFile::STAGE_COMPLETED, 'path' => 'https://example.test/pending-photo.jpg']);
        $shoot->update(['tour_links' => ['video_mls' => 'https://example.test/pending.mp4']]);
        \App\Services\SystemEmails\ProtectedEmailTemplates::installMissing();
        \App\Models\MessageTemplate::where('channel', 'EMAIL')->where('email_type', 'SHOOT_DELIVERED')->firstOrFail()->update([
            'override_enabled' => true, 'body_html' => '{{system_body_html}}<a href="{{video_download_link}}">{{services_provided}}</a>',
        ]);

        $share = app(\App\Services\SystemEmails\DeliveryShareLinksBuilder::class)->forDeliveredServices($shoot->fresh(), [$photoId]);
        $this->assertTrue($share['has_links']);
        foreach ($share['links'] as $link) {
            parse_str((string) parse_url($link['url'], PHP_URL_QUERY), $wrapper);
            parse_str((string) parse_url($wrapper['url'], PHP_URL_QUERY), $signed);
            $this->assertSame((string) $photoId, $signed['shoot_service_id']);
            $this->assertSame('1', $signed['verified_only']);
        }
        $this->notify($shoot);
        $message = Message::where('related_shoot_id', $shoot->id)->where('send_source', 'SHOOT_DELIVERED')->sole();
        $this->assertStringContainsString('Full-Size Images Download', $message->body_html);
        $this->assertStringNotContainsString('Pending Video', $message->body_html);
        $this->assertStringNotContainsString('pending.mp4', $message->body_html);
        $this->assertStringNotContainsString('pending-photo.jpg', $message->body_html);
    }

    public function test_finalized_unit_tour_changes_notify_but_unit_notes_and_pending_provider_lines_do_not(): void
    {
        $shoot = $this->shoot();
        $unit = \App\Models\ShootUnit::create(['shoot_id' => $shoot->id, 'client_key' => 'unit-a', 'label' => 'Unit A']);
        $service = \App\Models\Service::factory()->create(['name' => 'Unit Photos']);
        $shoot->services()->attach($service->id, ['shoot_unit_id' => $unit->id, 'is_deliverable' => true,
            'delivery_status' => 'delivered', 'workflow_status' => 'delivered']);
        $this->notify($shoot);
        $unit->update(['label' => 'Renamed unit', 'access_notes' => 'New internal instructions']);
        Queue::assertNotPushed(NotifyShootDeliveryUpdate::class);
        $this->notify($shoot);
        $this->assertSame(1, $this->messages($shoot)->count());

        $unit->update(['tour_links' => ['branded' => 'https://example.test/unit-tour']]);
        Queue::assertPushed(NotifyShootDeliveryUpdate::class, fn ($job) => $job->shootId === $shoot->id);
        $this->notify($shoot);
        $this->notify($shoot);
        $this->assertSame(2, $this->messages($shoot)->count());
        $unit->update(['provider_data' => ['iguide_last_synced_at' => now()->toIso8601String()]]);
        $this->notify($shoot);
        $this->assertSame(2, $this->messages($shoot)->count());

        $pendingUnit = \App\Models\ShootUnit::create(['shoot_id' => $shoot->id, 'client_key' => 'unit-b', 'label' => 'Pending unit']);
        $pendingService = \App\Models\Service::factory()->create(['name' => 'Pending unit tour']);
        $shoot->services()->attach($pendingService->id, ['shoot_unit_id' => $pendingUnit->id, 'is_deliverable' => true,
            'delivery_status' => 'not_started', 'workflow_status' => 'in_progress']);
        $pendingUnit->update(['tour_links' => ['branded' => 'https://example.test/pending-unit-tour']]);
        $this->notify($shoot);
        $this->assertSame(2, $this->messages($shoot)->count());
    }

    public function test_delivery_updates_observe_recipient_local_quiet_hours(): void
    {
        $this->travelTo(now('America/New_York')->setTime(2, 0));
        $shoot = $this->shoot();
        $this->notify($shoot);
        $message = $this->messages($shoot)->sole();
        $this->assertSame('SCHEDULED', $message->status);
        $this->assertSame('09:00', $message->scheduled_at->setTimezone('America/New_York')->format('H:i'));
    }

    public function test_a_batch_of_multiple_finalized_services_and_links_coalesces_to_one_email(): void
    {
        $shoot = $this->shoot(['workflow_status' => Shoot::STATUS_EDITING, 'status' => Shoot::STATUS_EDITING, 'delivery_status' => 'partial']);
        foreach (['Delivered Photos', 'Delivered Plans'] as $name) {
            $service = \App\Models\Service::factory()->create(['name' => $name]);
            $shoot->services()->attach($service->id, ['delivery_status' => 'delivered', 'workflow_status' => 'delivered', 'is_deliverable' => true]);
            $serviceId = (int) $shoot->serviceItems()->where('service_id', $service->id)->first()->id;
            $this->file($shoot, ['shoot_service_id' => $serviceId]);
        }
        $shoot->update(['tour_links' => ['mls' => 'https://example.test/updated']]);
        Queue::assertPushed(NotifyShootDeliveryUpdate::class, 1);
        $this->notify($shoot);
        $this->notify($shoot);
        $message = Message::where('related_shoot_id', $shoot->id)->where('send_source', 'SHOOT_DELIVERED')->sole();
        $this->assertStringContainsString('Delivered Photos', $message->body_html);
        $this->assertStringContainsString('Delivered Plans', $message->body_html);
    }

    public function test_full_summary_urls_keep_pending_replacements_out_of_existing_forwardable_archives(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('shoots/original.jpg', str_repeat('x', 20));
        $shoot = $this->shoot();
        $approved = $this->file($shoot, ['required_for_editing' => true]);
        $archives = app(\App\Services\Shoots\ShootMediaArchiveService::class);
        $share = app(\App\Services\SystemEmails\DeliveryShareLinksBuilder::class)->forShootSummary($shoot->fresh());
        $this->assertNotNull($share['full_zip_link']);
        $destination = \App\Models\ShortLink::query()->where('target_id', $shoot->id)
            ->where('target_key', 'like', '%verified%original%')->value('destination_url') ?? $share['full_zip_link'];
        parse_str((string) parse_url($destination, PHP_URL_QUERY), $wrapper);
        parse_str((string) parse_url($wrapper['url'], PHP_URL_QUERY), $signed);
        $this->assertSame('1', $signed['verified_only']);

        $pending = $this->file($shoot, ['workflow_stage' => ShootFile::STAGE_COMPLETED,
            'verified_at' => null, 'path' => 'shoots/pending-replacement.jpg', 'required_for_editing' => true]);
        $this->assertSame([$approved->id], $archives->getFilesForType($shoot, 'edited|verified')->pluck('id')->all());
        $this->assertContains($pending->id, $archives->getFilesForType($shoot, 'edited')->pluck('id')->all());
        $pending->update(['workflow_stage' => ShootFile::STAGE_VERIFIED, 'verified_at' => now()]);
        $this->assertContains($pending->id, $archives->getFilesForType($shoot, 'edited|verified')->pluck('id')->all());
    }

    public function test_safe_full_custom_template_preserves_copy_but_canonical_data_gates_video_values_and_heading(): void
    {
        $shoot = $this->shoot();
        $shoot->update(['tour_links' => ['branded' => 'https://example.test/property', 'video_mls' => 'https://example.test/pending.mp4']]);
        \App\Services\SystemEmails\ProtectedEmailTemplates::installMissing();
        $template = \App\Models\MessageTemplate::where('channel', 'EMAIL')->where('email_type', 'SHOOT_SUMMARY')->firstOrFail();
        $template->update(['override_enabled' => true,
            'body_html' => '{{system_body_html}}<p>Our custom delivery note.</p><p>PROPERTY AND VIDEO</p><a href="{{video_download_link}}">View video</a><a href="{{video_link}}">Play video</a>',
            'body_text' => '{{system_body_text}}',
        ]);
        $resolver = \Mockery::mock(\App\Services\Messaging\TemplateVariableResolver::class);
        $resolver->shouldReceive('resolve')->andReturn(['video_download_link' => 'https://example.test/pending.mp4', 'video_link' => 'https://example.test/pending.mp4']);
        $this->app->instance(\App\Services\Messaging\TemplateVariableResolver::class, $resolver);

        $this->notify($shoot);
        $html = $this->messages($shoot)->sole()->body_html;
        $this->assertStringContainsString('Our custom delivery note.', $html);
        $this->assertStringNotContainsString('PROPERTY AND VIDEO', $html);
        $this->assertStringNotContainsString('pending.mp4', $html);
        $this->assertStringNotContainsString('View video', $html);
        $this->assertSame('healthy', $template->fresh()->override_health_status);
    }

    public function test_full_custom_template_cannot_restore_a_literal_pending_video_download(): void
    {
        $shoot = $this->shoot();
        \App\Services\SystemEmails\ProtectedEmailTemplates::installMissing();
        $template = \App\Models\MessageTemplate::where('channel', 'EMAIL')->where('email_type', 'SHOOT_SUMMARY')->firstOrFail();
        $template->update(['override_enabled' => true,
            'body_html' => '{{system_body_html}}<p><a href="https://example.test/pending.mp4">Video Download</a></p>',
            'body_text' => '{{system_body_text}}',
        ]);

        $this->notify($shoot);
        $this->assertStringNotContainsString('pending.mp4', $this->messages($shoot)->sole()->body_html);
        $this->assertSame('unhealthy', $template->fresh()->override_health_status);
    }

    public function test_full_custom_plain_text_cannot_restore_a_literal_pending_video_url(): void
    {
        $shoot = $this->shoot();
        \App\Services\SystemEmails\ProtectedEmailTemplates::installMissing();
        $template = \App\Models\MessageTemplate::where('channel', 'EMAIL')->where('email_type', 'SHOOT_SUMMARY')->firstOrFail();
        $template->update(['override_enabled' => true,
            'body_html' => '{{system_body_html}}<p>Safe custom note.</p>',
            'body_text' => '{{system_body_text}} https://vimeo.com/987654321',
        ]);

        $this->notify($shoot);
        $this->assertStringNotContainsString('https://vimeo.com/987654321', $this->messages($shoot)->sole()->body_text);
        $this->assertSame('unhealthy', $template->fresh()->override_health_status);
    }

    private function shoot(array $attributes = []): Shoot
    {
        $client = User::factory()->create(['role' => 'client', 'phonenumber' => null]);

        return Shoot::factory()->create(array_merge([
            'client_id' => $client->id, 'total_quote' => 0, 'base_quote' => 0,
            'payment_status' => Shoot::PAYMENT_STATUS_NO_PAYMENT_REQUIRED,
            'workflow_status' => Shoot::STATUS_DELIVERED, 'status' => Shoot::STATUS_DELIVERED,
            'delivery_status' => 'delivered', 'shoot_ready_notified_at' => null, 'state' => 'NJ', 'timezone' => 'America/New_York',
        ], $attributes));
    }

    private function file(Shoot $shoot, array $attributes = []): ShootFile
    {
        return ShootFile::create(array_merge([
            'shoot_id' => $shoot->id, 'workflow_stage' => ShootFile::STAGE_VERIFIED,
            'scan_status' => ShootFile::SCAN_STATUS_CLEAN, 'content_version' => 1,
            'is_hidden' => false, 'path' => 'shoots/original.jpg', 'storage_path' => 'shoots/original.jpg',
            'verified_at' => now(), 'media_type' => 'edited', 'filename' => 'original.jpg',
            'stored_filename' => 'original.jpg', 'file_type' => 'image/jpeg', 'mime_type' => 'image/jpeg',
            'file_size' => 20, 'uploaded_by' => $shoot->client_id, 'uploaded_at' => now(),
        ], $attributes));
    }

    private function notify(Shoot $shoot): void
    {
        (new NotifyShootDeliveryUpdate($shoot->id))->handle(app(MailService::class), app(AutomationService::class));
    }

    private function messages(Shoot $shoot)
    {
        return Message::where('related_shoot_id', $shoot->id)->where('send_source', 'SHOOT_SUMMARY')->orderBy('id')->get();
    }
}
