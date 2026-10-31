<?php

namespace Tests\Feature;

use App\Exceptions\PublicApiException;
use App\Jobs\ProcessStudioWorkspace;
use App\Models\ListingStudioOrder;
use App\Models\ListingStudioOrderItem;
use App\Models\ListingStudioQuote;
use App\Models\ListingStudioRevisionDelivery;
use App\Models\ListingStudioRevisionTask;
use App\Models\ListingStudioUpload;
use App\Models\StudioWorkspace;
use App\Models\User;
use App\Services\ListingStudio\ListingStudioPaidMedia;
use App\Services\ListingStudio\ListingStudioPaidOutcomeGuard;
use App\Services\ListingStudio\ListingStudioPaidPipeline;
use App\Services\ListingStudio\ListingStudioPaidRecipe;
use App\Services\ListingStudio\ListingStudioPaidSourceResolver;
use App\Services\ListingStudio\ListingStudioRevisionService;
use App\Services\Studio\WorkspaceImageOperations;
use App\Services\Studio\WorkspaceMediaService;
use App\Services\Studio\WorkspaceProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ListingStudioPaidProcessingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['listing-studio.paid_enabled' => true, 'listing-studio.human_revisions_enabled' => true, 'listing-studio.paid_services.grass' => true]);
        foreach (['listing_studio_orders', 'listing_studio_trials'] as $name) {
            $disk = Storage::fake($name, config('filesystems.disks.'.$name));
            $disk->makeDirectory('');
            $disk->setVisibility('', 'private');
        }
        Storage::fake('public');
        Queue::fake();
        Http::preventStrayRequests();
    }

    public function test_upload_is_private_idempotent_and_changed_bytes_cannot_reuse_key(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $key = (string) Str::uuid();
        $photo = UploadedFile::fake()->image('property.jpg', 120, 80);
        $service = app(ListingStudioPaidMedia::class);
        $upload = $service->upload($client, $photo, $key);
        $this->assertSame($upload->id, $service->upload($client, $photo, $key)->id);
        $this->assertSame(1, ListingStudioUpload::count());
        Storage::disk('public')->assertMissing($upload->path);
        Storage::disk('listing_studio_orders')->assertExists([$upload->path, $upload->preview_path]);
        $this->assertArrayNotHasKey('path', $service->presentUpload($upload));
        $this->expectException(PublicApiException::class);
        $service->upload($client, UploadedFile::fake()->image('changed.jpg', 121, 80), $key);
    }

    public function test_upload_gate_closes_new_work_but_does_not_break_exact_replay(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $photo = UploadedFile::fake()->image('property.jpg');
        $key = (string) Str::uuid();
        $service = app(ListingStudioPaidMedia::class);
        $upload = $service->upload($client, $photo, $key);
        config(['listing-studio.paid_enabled' => false]);
        $this->assertSame($upload->id, $service->upload($client, $photo, $key)->id);
        $this->expectException(PublicApiException::class);
        $service->upload($client, $photo, (string) Str::uuid());
    }

    public function test_upload_rejects_non_image_and_another_client_cannot_resolve_source(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        try {
            app(ListingStudioPaidMedia::class)->upload($client, UploadedFile::fake()->createWithContent('fake.jpg', '<script>bad</script>'), (string) Str::uuid());
            $this->fail();
        } catch (PublicApiException $error) {
            $this->assertSame('validation_failed', $error->errorCode);
        }
        $upload = app(ListingStudioPaidMedia::class)->upload($client, UploadedFile::fake()->image('photo.png'), (string) Str::uuid());
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(ListingStudioPaidSourceResolver::class)->resolve(User::factory()->create(['role' => 'client']), 'ai_edit', 'upload', $upload->id);
    }

    public function test_source_pinning_rechecks_expiry_inside_acceptance_and_materializes_both_owned_inputs(): void
    {
        [$item, $upload] = $this->item();
        $resolver = app(ListingStudioPaidSourceResolver::class);
        DB::transaction(fn () => $resolver->pin($item->source_snapshot, $item->id));
        $this->assertNotNull($upload->fresh()->pinned_at);
        $snapshot = app(ListingStudioPaidMedia::class)->materialize($item);
        $this->assertSame($item->source_snapshot['sha256'], $snapshot['sha256']);
        $this->assertSame('items/'.$item->id.'/input.jpg', $snapshot['path']);
        $this->assertSame('items/'.$item->id.'/property-original.jpg', $snapshot['original']['path']);
        $this->assertSame((int) $item->client_id, $snapshot['clientId']);
        $upload->update(['expires_at' => now()->subSecond()]);
        $this->expectException(PublicApiException::class);
        DB::transaction(fn () => $resolver->pin($item->source_snapshot, $item->id));
    }

    public function test_paid_workspace_is_private_and_rejects_tampered_recipe_and_foreign_source(): void
    {
        [$item] = $this->item();
        $workspace = $this->workspace($item);
        $media = app(WorkspaceMediaService::class);
        $media->authorizeWorkspace($workspace);
        $stored = $media->store($workspace, $this->jpeg(), 'output');
        $this->assertNull($stored['url']);
        Storage::disk('listing_studio_orders')->assertExists($stored['path']);
        Storage::disk('public')->assertMissing($stored['path']);
        $config = $workspace->config;
        $config['prompt'] = 'A client supplied prompt';
        $workspace->update(['config' => $config]);
        $this->expectException(\App\Exceptions\StudioProviderException::class);
        $media->authorizeWorkspace($workspace->fresh());
    }

    public function test_new_provider_submission_is_blocked_when_paid_gate_closes(): void
    {
        [$item] = $this->item();
        $workspace = $this->workspace($item);
        config(['listing-studio.paid_enabled' => false]);
        $this->expectException(\App\Exceptions\StudioProviderException::class);
        app(ListingStudioPaidPipeline::class)->assertMaySubmit($workspace);
    }

    public function test_paid_workspace_cannot_substitute_another_clients_valid_paid_source(): void
    {
        [$ownerItem] = $this->item();
        [$foreignItem] = $this->item();
        $workspace = $this->workspace($ownerItem);
        $foreignWorkspace = $this->workspace($foreignItem);
        $workspace->update(['media' => $foreignWorkspace->media]);
        $this->expectException(\App\Exceptions\StudioProviderException::class);
        app(WorkspaceMediaService::class)->authorizeWorkspace($workspace->fresh());
    }

    public function test_paid_upload_http_requires_own_client_and_keeps_preview_private(): void
    {
        $client = User::factory()->create(['role' => 'client', 'secondary_roles' => ['editor']]);
        Sanctum::actingAs($client);
        $response = $this->withHeaders(['Idempotency-Key' => (string) Str::uuid(), 'Accept' => 'application/json'])
            ->post('/api/listing-studio/client/uploads', ['photo' => UploadedFile::fake()->image('photo.jpg')])->assertOk();
        $response->assertJsonStructure(['success', 'data' => ['upload' => ['id', 'mime', 'width', 'height', 'originalPreviewAvailable', 'expiresAt']]]);
        $id = $response->json('data.upload.id');
        $this->get('/api/listing-studio/client/uploads/'.$id.'/preview')->assertOk()->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('Cache-Control', 'no-store, private');
        Sanctum::actingAs(User::factory()->create(['role' => 'client']));
        $this->getJson('/api/listing-studio/client/uploads/'.$id.'/preview')->assertNotFound();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/listing-studio/client/uploads', [])->assertForbidden();
        $this->assertSame(1, ListingStudioUpload::count());
    }

    public function test_paid_routes_keep_existing_verification_policy(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'client']));
        $this->mock(\App\Services\Users\EmailVerificationPilot::class)->shouldReceive('status')->andReturn(['required' => true]);
        $this->postJson('/api/listing-studio/client/uploads', [])->assertForbidden()->assertJsonPath('code', 'email_verification_required');
        $this->assertSame(0, ListingStudioUpload::count());
    }

    public function test_unconfirmed_provider_marker_blocks_recovery_and_financial_release(): void
    {
        [$item] = $this->item();
        $workspace = $this->workspace($item);
        $operation = $workspace->operation;
        $operation['providerState']['fal-submitting-source'] = ['started' => true];
        $workspace->update(['status' => 'failed', 'operation' => $operation]);
        $item->update(['status' => 'needs_attention']);
        try {
            app(ListingStudioPaidPipeline::class)->resume($item->id);
            $this->fail();
        } catch (\App\Exceptions\StudioProviderException $error) {
            $this->assertTrue($error->ambiguous);
        }
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->expectException(PublicApiException::class);
        app(ListingStudioPaidOutcomeGuard::class)->assertDefinitiveStop($item->fresh());
    }

    public function test_interrupted_worker_recovery_requires_stale_or_terminal_workspace_and_keeps_checkpoint(): void
    {
        [$item] = $this->item();
        $workspace = $this->workspace($item);
        $operation = $workspace->operation;
        $operation['providerState']['autoenhance-source'] = ['imageId' => 'accepted-image'];
        $workspace->update(['operation' => $operation]);
        config(['listing-studio.paid_enabled' => false]);
        try {
            app(ListingStudioPaidPipeline::class)->resume($item->id);
            $this->fail();
        } catch (\App\Exceptions\StudioProviderException) {
            Queue::assertNothingPushed();
        }
        $workspace->update(['updated_at' => now()->subMinutes(151)]);
        app(ListingStudioPaidPipeline::class)->resume($item->id);
        $this->assertSame($operation['id'], $workspace->fresh()->operation['id']);
        $this->assertSame($operation['providerState'], $workspace->fresh()->operation['providerState']);
        $this->assertSame($workspace->id, $item->fresh()->workspace_id);
        $this->assertNull($item->fresh()->processing_lease_token);
        Queue::assertPushed(ProcessStudioWorkspace::class, 1);
        Http::assertNothingSent();
    }

    public function test_completed_workspace_finalization_gap_recovers_privately_even_with_new_starts_disabled(): void
    {
        [$item] = $this->item();
        $workspace = $this->workspace($item);
        $stored = app(WorkspaceMediaService::class)->store($workspace, $this->jpeg(), 'saved-output');
        $workspace->update(['status' => 'completed', 'outputs' => [['path' => $stored['path'], 'url' => null, 'kind' => 'image', 'status' => 'completed']]]);
        config(['listing-studio.paid_enabled' => false]);
        app(ListingStudioPaidPipeline::class)->resume($item->id);
        $this->assertSame('completed', $item->fresh()->status);
        $this->assertSame($stored['path'], $item->fresh()->output_descriptor['path']);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_team_scope_is_central_managers_and_assigned_editors_only(): void
    {
        [$item] = $this->item('human_revision');
        $editor = User::factory()->create(['role' => 'editor']);
        $task = ListingStudioRevisionTask::create(['order_item_id' => $item->id, 'status' => 'assigned', 'assignee_id' => $editor->id]);
        $service = app(ListingStudioRevisionService::class);
        $this->assertSame(1, $service->visible($editor)->count());
        $this->assertSame(0, $service->visible(User::factory()->create(['role' => 'editor']))->count());
        $manager = User::factory()->create(['role' => 'editing_manager']);
        $dto = $service->present($manager, $task);
        $this->assertTrue($dto['actions']['canAssign']);
        $this->assertSame(10485760, $dto['deliveryConstraints']['maxFileBytes']);
        $this->assertStringNotContainsString('source_snapshot', json_encode($dto));
        $task->update(['team_id' => 912]);
        $this->assertSame(0, $service->visible($manager)->count());
    }

    public function test_team_http_dto_matches_client_contract_and_hides_private_persistence(): void
    {
        [$item] = $this->item('human_revision');
        $manager = User::factory()->create(['role' => 'editing_manager']);
        $task = ListingStudioRevisionTask::create(['order_item_id' => $item->id, 'status' => 'queued']);
        Sanctum::actingAs($manager);
        $response = $this->getJson('/api/listing-studio/team/revisions?perPage=5')->assertOk();
        $response->assertJsonStructure(['success', 'data' => ['tasks' => [['id', 'orderId', 'itemId', 'version', 'status', 'itemStatus',
            'client' => ['id', 'name'], 'updatedAt', 'assignedAt', 'recovery' => ['state', 'reasonCode', 'message'],
            'actions' => ['canAssign', 'canStart', 'canDeliver', 'canViewSource', 'canViewDelivery', 'canResumeDelivery', 'canHold', 'canFail', 'canCancel'],
            'deliveryConstraints' => ['mimeTypes', 'maxFileBytes', 'maxPixels', 'maxFiles']]],
            'pagination' => ['page', 'perPage', 'total', 'hasMore']]]);
        $this->assertIsString($response->json('data.tasks.0.client.id'));
        $this->assertStringNotContainsString('source_snapshot', $response->getContent());
        $this->assertStringNotContainsString('listing_studio_orders', $response->getContent());
        $this->getJson('/api/listing-studio/team/revisions/'.$task->id.'/assignees')->assertOk()
            ->assertJsonStructure(['data' => ['taskId', 'version', 'assignees' => [['id', 'name', 'role']]]]);
        $this->getJson('/api/listing-studio/team/revisions?perPage=101')->assertUnprocessable();
        Sanctum::actingAs(User::factory()->create(['role' => 'editor']));
        $this->getJson('/api/listing-studio/team/revisions/'.$task->id)->assertNotFound();
    }

    public function test_unresolved_delivery_prevents_credit_release_and_foreign_candidate_settlement(): void
    {
        [$item] = $this->item('human_revision');
        $task = ListingStudioRevisionTask::create(['order_item_id' => $item->id, 'status' => 'in_progress']);
        $id = (string) Str::uuid();
        $descriptor = ['disk' => 'listing_studio_orders', 'path' => 'revision-deliveries/'.$id.'/result.jpg', 'sha256' => str_repeat('a', 64)];
        $delivery = ListingStudioRevisionDelivery::create(['id' => $id, 'task_id' => $task->id, 'request_key' => (string) Str::uuid(), 'request_hash' => str_repeat('b', 64), 'expected_version' => 1, 'descriptor' => $descriptor, 'status' => 'pending']);
        $task->update(['delivery_attempt_id' => $delivery->id]);
        $guard = app(ListingStudioPaidOutcomeGuard::class);
        $guard->assertDeliverableOutput($item, $descriptor);
        try {
            $guard->assertDeliverableOutput($item, [...$descriptor, 'sha256' => str_repeat('c', 64)]);
            $this->fail();
        } catch (PublicApiException $error) {
            $this->assertSame('outcome_unresolved', $error->errorCode);
        }
        $this->expectException(PublicApiException::class);
        $guard->assertDefinitiveStop($item);
    }

    public function test_expired_unused_upload_cleanup_never_deletes_an_accepted_source(): void
    {
        [$item, $accepted] = $this->item();
        $unused = app(ListingStudioPaidMedia::class)->upload($item->client, UploadedFile::fake()->image('unused.jpg'), (string) Str::uuid());
        $accepted->update(['expires_at' => now()->subMinute()]);
        $unused->update(['expires_at' => now()->subMinute()]);
        $this->assertSame(1, app(ListingStudioPaidMedia::class)->pruneUnusedUploads());
        Storage::disk('listing_studio_orders')->assertExists($accepted->path);
        Storage::disk('listing_studio_orders')->assertMissing($unused->path);
        $this->assertNull($accepted->fresh()->media_purged_at);
        $this->assertNotNull($unused->fresh()->media_purged_at);
    }

    public function test_unpaid_pinned_expired_source_counts_once_toward_upload_cap_but_funded_history_does_not(): void
    {
        [$item, $upload] = $this->item();
        $item->update(['status' => 'awaiting_funding']);
        $item->order->update(['funding_status' => 'pending', 'paid_amount_cents' => 0]);
        $upload->update(['pinned_at' => now(), 'expires_at' => now()->subDay()]);
        // Several unpaid orders using the same source still occupy one source slot.
        $duplicate = $item->replicate();
        $duplicate->id = (string) Str::uuid();
        $quote = $item->order->quote->replicate();
        $quote->id = (string) Str::uuid();
        $quote->save();
        $order = $item->order->replicate();
        $order->id = (string) Str::uuid();
        $order->quote_id = $quote->id;
        $order->idempotency_key = (string) Str::uuid();
        $order->save();
        $duplicate->order_id = $order->id;
        $duplicate->save();
        config(['listing-studio.max_active_sources_per_client' => 2]);
        $media = app(ListingStudioPaidMedia::class);
        $second = $media->upload($item->client, UploadedFile::fake()->image('second.jpg'), (string) Str::uuid());
        $this->assertSame(2, ListingStudioUpload::count());
        try {
            $media->upload($item->client, UploadedFile::fake()->image('third.jpg'), (string) Str::uuid());
            $this->fail();
        } catch (PublicApiException $error) {
            $this->assertSame('upload_limit', $error->errorCode);
        }
        $this->assertSame(2, ListingStudioUpload::count());
        $item->update(['status' => 'completed']);
        $duplicate->update(['status' => 'completed']);
        $media->upload($item->client, UploadedFile::fake()->image('after-payment.jpg'), (string) Str::uuid());
        $this->assertSame(3, ListingStudioUpload::count());
        Storage::disk('listing_studio_orders')->assertExists([$upload->path, $second->path]);
    }

    public function test_never_paid_cancelled_source_stays_capped_until_expiry_and_safe_successful_prune(): void
    {
        $this->checkoutTable();
        [$item, $upload] = $this->item();
        $item->update(['status' => 'cancelled']);
        $item->order->update(['funding_status' => 'pending', 'paid_amount_cents' => 0]);
        $upload->update(['pinned_at' => now()]);
        config(['listing-studio.max_active_sources_per_client' => 1]);
        $media = app(ListingStudioPaidMedia::class);
        $this->assertSame(0, $media->pruneUnusedUploads());
        $upload->update(['expires_at' => now()->subMinute()]);
        try {
            $media->upload($item->client, UploadedFile::fake()->image('bypass.jpg'), (string) Str::uuid());
            $this->fail();
        } catch (PublicApiException $error) {
            $this->assertSame('upload_limit', $error->errorCode);
        }
        $attempt = (string) Str::uuid();
        DB::table('listing_studio_checkout_attempts')->insert(['id' => $attempt, 'client_id' => $item->client_id, 'order_id' => $item->order_id,
            'kind' => 'order', 'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('c', 64), 'account_id' => 'acct_test',
            'livemode' => false, 'currency' => 'usd', 'amount_cents' => 100, 'status' => 'awaiting_reconciliation', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(0, $media->pruneUnusedUploads());
        $this->assertNull($upload->fresh()->media_purged_at);
        Storage::disk('listing_studio_orders')->assertExists($upload->path);
        DB::table('listing_studio_checkout_attempts')->where('id', $attempt)->update(['status' => 'expired']);
        $this->assertSame(1, $media->pruneUnusedUploads());
        $this->assertNotNull($upload->fresh()->media_purged_at);
        Storage::disk('listing_studio_orders')->assertMissing($upload->path);
        $this->assertSame('cancelled', $item->fresh()->status);
        $this->assertSame(1, ListingStudioOrder::count());
        $this->assertSame(1, DB::table('listing_studio_checkout_attempts')->count());
        $media->upload($item->client, UploadedFile::fake()->image('after-prune.jpg'), (string) Str::uuid());
        $this->assertSame(2, ListingStudioUpload::count());
    }

    public function test_expired_cancelled_source_is_never_pruned_after_payment_or_workspace_creation(): void
    {
        $this->checkoutTable();
        [$paid, $upload] = $this->item();
        $paid->update(['status' => 'cancelled']);
        $upload->update(['pinned_at' => now(), 'expires_at' => now()->subMinute()]);
        $this->assertSame(0, app(ListingStudioPaidMedia::class)->pruneUnusedUploads());
        [$started, $startedUpload] = $this->item();
        $workspace = $this->workspace($started);
        $started->update(['status' => 'failed']);
        $started->order->update(['funding_status' => 'pending', 'paid_amount_cents' => 0]);
        $startedUpload->update(['pinned_at' => now(), 'expires_at' => now()->subMinute()]);
        $this->assertSame(0, app(ListingStudioPaidMedia::class)->pruneUnusedUploads());
        Storage::disk('listing_studio_orders')->assertExists([$upload->path, $startedUpload->path]);
        $this->assertNull($upload->fresh()->media_purged_at);
        $this->assertNull($startedUpload->fresh()->media_purged_at);
        $this->assertSame($workspace->id, $started->fresh()->workspace_id);
    }

    public function test_funded_ai_dispatch_reuses_one_workspace_and_finishes_private_output_once(): void
    {
        [$item] = $this->item();
        $attempt = $item->processing_attempt_id;
        $pipeline = app(ListingStudioPaidPipeline::class);
        $pipeline->start($item->id, $attempt);
        $first = $item->fresh()->workspace_id;
        $pipeline->start($item->id, $attempt);
        $this->assertSame($first, $item->fresh()->workspace_id);
        $this->assertSame(1, StudioWorkspace::where('listing_studio_order_item_id', $item->id)->count());
        $this->assertNull($item->fresh()->processing_lease_token);
        Queue::assertPushed(ProcessStudioWorkspace::class, 2);
        $this->mock(WorkspaceImageOperations::class)->shouldReceive('edit')->once()->andReturn($this->jpeg());
        $workspace = StudioWorkspace::findOrFail($first);
        $job = new ProcessStudioWorkspace($workspace->id, $workspace->operation['id']);
        $job->handle(app(WorkspaceProcessor::class));
        $job->handle(app(WorkspaceProcessor::class));
        $result = $item->fresh();
        $this->assertSame('completed', $result->status);
        $this->assertSame($item->id, $result->output_descriptor['itemId']);
        Storage::disk('listing_studio_orders')->assertExists([$result->output_descriptor['path'], $result->output_descriptor['preview']['path']]);
        Storage::disk('public')->assertMissing($result->output_descriptor['path']);
        Http::assertNothingSent();
    }

    public function test_team_delivery_is_durable_and_exact_replay_precedes_version_or_terminal_rejection(): void
    {
        [$item] = $this->item('human_revision');
        $item->update(['status' => 'queued', 'processing_attempt_id' => null]);
        $editor = User::factory()->create(['role' => 'editor']);
        $task = ListingStudioRevisionTask::create(['order_item_id' => $item->id, 'status' => 'assigned', 'assignee_id' => $editor->id]);
        $service = app(ListingStudioRevisionService::class);
        $task = $service->mutate($editor, $task->id, 'start', ['expectedVersion' => 1], (string) Str::uuid());
        $photo = UploadedFile::fake()->image('delivery.jpg', 64, 48);
        $key = (string) Str::uuid();
        $version = $task->version;
        $accepted = $service->deliver($editor, $task->id, $photo, $version, $key);
        $this->assertTrue($accepted['created']);
        $this->assertSame('processing', $item->fresh()->status);
        $this->assertSame('pending', $accepted['task']->deliveryAttempt->status);
        $this->assertFalse($service->deliver($editor, $task->id, $photo, $version, $key)['created']);
        $delivery = $accepted['task']->deliveryAttempt;
        $service->finalize($delivery->id);
        $this->assertSame('completed', $item->fresh()->status);
        $this->assertSame('delivered', $task->fresh()->status);
        $this->assertFalse($service->deliver($editor, $task->id, $photo, $version, $key)['created']);
        $service->finalize($delivery->id);
        $this->assertSame(1, ListingStudioRevisionDelivery::where('task_id', $task->id)->count());
        $this->assertSame(1, \App\Models\ListingStudioOutbox::where('type', 'finalize_revision_delivery')->count());
        $this->expectException(PublicApiException::class);
        $service->deliver($editor, $task->id, UploadedFile::fake()->image('changed.jpg', 65, 48), $version, $key);
    }

    public function test_delivery_preview_failure_holds_same_candidate_and_resume_completes_without_new_purchase(): void
    {
        [$item] = $this->item('human_revision');
        $editor = User::factory()->create(['role' => 'editor']);
        $task = ListingStudioRevisionTask::create(['order_item_id' => $item->id, 'status' => 'in_progress', 'assignee_id' => $editor->id]);
        $service = app(ListingStudioRevisionService::class);
        $result = $service->deliver($editor, $task->id, UploadedFile::fake()->image('delivery.jpg'), 1, (string) Str::uuid());
        $delivery = $result['task']->deliveryAttempt;
        $realMedia = app(ListingStudioPaidMedia::class);
        $this->mock(ListingStudioPaidMedia::class)->shouldReceive('completeOutput')->once()->andThrow(new \RuntimeException('Simulated local preview failure.'));
        try {
            $service->finalize($delivery->id);
            $this->fail();
        } catch (\RuntimeException) {
            $this->assertSame('needs_attention', $delivery->fresh()->status);
        }
        $this->assertSame('needs_attention', $item->fresh()->status);
        $this->assertSame('settled', $item->order->fresh()->funding_status);
        $this->assertSame(0, \App\Models\ListingStudioOutbox::where('type', 'refund_order')->count());
        $this->app->instance(ListingStudioPaidMedia::class, $realMedia);
        $service->resume($editor, $task->id, $delivery->id, $task->fresh()->version);
        $service->finalize($delivery->id);
        $this->assertSame('completed', $item->fresh()->status);
        $this->assertSame(1, ListingStudioRevisionDelivery::count());
        Http::assertNothingSent();
    }

    private function item(string $kind = 'ai_edit'): array
    {
        $client = User::factory()->create(['role' => 'client']);
        $upload = app(ListingStudioPaidMedia::class)->upload($client, UploadedFile::fake()->image('photo.jpg', 64, 48), (string) Str::uuid());
        $snapshot = app(ListingStudioPaidSourceResolver::class)->resolve($client, 'ai_edit', 'upload', $upload->id);
        $quote = ListingStudioQuote::create(['client_id' => $client->id, 'kind' => $kind, 'service' => 'grass', 'source_kind' => 'upload', 'source_id' => $upload->id,
            'source_snapshot' => $snapshot, 'options' => [], 'pricing_snapshot' => [], 'catalog_version' => 'test', 'request_hash' => str_repeat('a', 64), 'expires_at' => now()->addMinutes(10)]);
        $order = ListingStudioOrder::create(['client_id' => $client->id, 'quote_id' => $quote->id, 'kind' => $kind, 'service' => 'grass', 'funding_method' => 'card', 'funding_status' => 'settled',
            'total_cents' => 100, 'paid_amount_cents' => 100, 'pricing_snapshot' => [], 'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('b', 64)]);
        $item = ListingStudioOrderItem::create(['order_id' => $order->id, 'client_id' => $client->id, 'kind' => $kind, 'service' => 'grass', 'status' => 'processing',
            'source_kind' => 'upload', 'source_id' => $upload->id, 'source_snapshot' => $snapshot, 'options' => [], 'processing_attempt_id' => (string) Str::uuid()]);

        return [$item, $upload];
    }

    private function workspace(ListingStudioOrderItem $item): StudioWorkspace
    {
        $snapshot = app(ListingStudioPaidMedia::class)->materialize($item);
        $item->update(['source_snapshot' => $snapshot]);
        $recipe = ListingStudioPaidRecipe::definition($item->service);
        $workspace = StudioWorkspace::create(['listing_studio_order_item_id' => $item->id, 'created_by' => $item->client_id, 'team_id' => null, 'name' => 'Paid test',
            'preset_id' => $recipe['preset'], 'status' => 'generating', 'media' => [['id' => 'source', 'orderItemId' => $item->id, 'mediaRef' => $snapshot['path'], 'kind' => 'image']],
            'config' => ['prompt' => $recipe['prompt'], 'adjustments' => $recipe['adjustments']],
            'operation' => ['id' => (string) Str::uuid(), 'type' => $recipe['type'], 'recipeVersion' => $recipe['version'], 'payload' => $recipe['payload'], 'routing' => [$recipe['routeKey'] => $recipe['route']]]]);
        $item->update(['workspace_id' => $workspace->id]);

        return $workspace;
    }

    private function jpeg(): string
    {
        $photo = UploadedFile::fake()->image('result.jpg', 64, 48);

        return file_get_contents($photo->getRealPath());
    }

    private function checkoutTable(): void
    {
        // The full integration supplies this through payment migration002000. This small
        // compatible schema also permits the processing commit to be tested independently.
        if (Schema::hasTable('listing_studio_checkout_attempts')) {
            return;
        }
        Schema::create('listing_studio_checkout_attempts', function ($table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('client_id');
            $table->uuid('order_id')->nullable();
            foreach (['kind', 'idempotency_key', 'request_hash', 'account_id', 'currency', 'status'] as $column) {
                $table->string($column);
            }
            $table->boolean('livemode');
            $table->unsignedInteger('amount_cents');
            $table->timestamps();
        });
    }
}
