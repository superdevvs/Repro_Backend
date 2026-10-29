<?php

namespace Tests\Feature;

use App\Models\AryeoConnection;
use App\Models\AryeoJob;
use App\Models\AryeoRequest;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\Aryeo\AryeoCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AryeoIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private string $prefix = '/api/integrations/aryeo/v1';

    private string $token = 'aryeo_test_worker_secret_at_least_32_characters';

    private AryeoConnection $connection;

    private Shoot $shoot;

    private User $admin;

    private ShootFile $file;

    private AryeoRequest $order;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        Queue::fake();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $client = User::factory()->create(['role' => 'client']);
        $this->shoot = Shoot::factory()->create(['client_id' => $client->id, 'status' => 'delivered', 'workflow_status' => 'delivered', 'admin_verified_at' => now(), 'payment_status' => 'paid']);
        $this->file = ShootFile::create(['shoot_id' => $this->shoot->id, 'filename' => 'photo.jpg', 'stored_filename' => 'photo.jpg', 'path' => 'shoots/test/photo.jpg', 'file_type' => 'image/jpeg', 'mime_type' => 'image/jpeg', 'file_size' => 100, 'uploaded_by' => $this->admin->id, 'workflow_stage' => 'verified', 'scan_status' => 'clean']);
        $this->connection = AryeoConnection::create(['name' => 'Test Mac', 'company_key' => 'repro', 'client_ids' => [$client->id], 'token_hash' => hash('sha256', $this->token), 'processing_enabled' => true, 'delivery_shoot_ids' => [$this->shoot->id], 'last_seen_at' => now(), 'capabilities' => ['processing_mode' => 'dashboard_jobs', 'shoot_executor' => true]]);
        $this->order = AryeoRequest::create(['connection_id' => $this->connection->id, 'source_id' => 'gmail-1', 'request_id' => 'aryeo-1', 'listing_id' => 'listing-1', 'shoot_id' => $this->shoot->id, 'match_status' => 'matched', 'discovery' => ['address' => $this->shoot->address, 'requester_email' => $client->email, 'required' => ['photos' => 1, 'floorplans' => 0, 'videos' => 0, 'tours' => 0], 'summary_present' => false]]);
    }

    private function worker(): static
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token);
    }

    private function enqueue(): string
    {
        return $this->actingAs($this->admin)->postJson('/api/shoots/'.$this->shoot->id.'/aryeo/requests/'.$this->order->id.'/process')->assertOk()->json('id');
    }

    private function claim(string $id = 'd02aba1b-a106-4f60-bc4e-7866f0189a80', string $token = 'claim_secret_12345678901234567890')
    {
        return $this->worker()->postJson($this->prefix.'/jobs/claim', ['claim_id' => $id, 'lease_token' => $token]);
    }

    public function test_lookup_includes_delivered_and_is_client_scoped(): void
    {
        $other = Shoot::factory()->create();
        $this->worker()->getJson($this->prefix.'/shoots')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->shoot->id);
        $this->worker()->getJson($this->prefix.'/shoots/'.$other->id)->assertNotFound();
        $this->withHeader('Authorization', 'Bearer invalid')->getJson($this->prefix.'/shoots')->assertUnauthorized();
    }

    public function test_preparation_does_not_claim_a_second_browser_lease_or_mutate_jobs(): void
    {
        $id = $this->enqueue();
        $queued = AryeoJob::findOrFail($id)->replicate();
        $queued->id = (string) \Illuminate\Support\Str::uuid();
        $queued->operation_key = hash('sha256', 'next-preparation');
        $queued->save();
        $this->claim()->assertOk()->assertJsonPath('job.id', $id);
        $before = AryeoJob::orderBy('id')->get()->toArray();
        $this->worker()->getJson($this->prefix.'/jobs/preparation')->assertOk()->assertJsonPath('job.id', $queued->id)
            ->assertJsonPath('job.status', 'queued')->assertJsonMissingPath('job.lease_hash');
        $this->worker()->getJson($this->prefix.'/jobs/'.$queued->id.'/preparation?media_version='.$queued->media_version)
            ->assertOk()->assertJsonPath('job.attempts', 0);
        $this->assertSame($before, AryeoJob::orderBy('id')->get()->toArray());
        $this->claim('c18c0b41-1867-4552-84d4-52df307d40d6')->assertOk()->assertJsonPath('job', null);
    }

    public function test_preparation_download_requires_current_queued_release_and_preserves_checksum(): void
    {
        $id = $this->enqueue();
        $job = AryeoJob::findOrFail($id);
        $url = $this->prefix.'/jobs/'.$id.'/assets/'.$this->file->id.'/preparation?media_version='.$job->media_version;
        $disk = \Illuminate\Support\Facades\Storage::fake('local');
        $bytes = 'queued approved original';
        $disk->put('shoots/test/photo.jpg', $bytes);
        $this->mock(\App\Services\Shoots\ShootFileAccessService::class, function ($mock) use ($disk) {
            $mock->shouldReceive('resolveLocalPath')->with('shoots/test/photo.jpg')->andReturn($disk->path('shoots/test/photo.jpg'));
        });
        $this->worker()->get($url)->assertOk()->assertHeader('X-Content-SHA256', hash('sha256', $bytes))->assertHeaderMissing('X-Accel-Redirect');
        $this->worker()->getJson(str_replace($job->media_version, str_repeat('a', 64), $url))->assertConflict();
        $this->shoot->update(['payment_status' => 'unpaid']);
        $this->worker()->getJson($url)->assertConflict();
        $this->worker()->getJson($this->prefix.'/jobs/preparation')->assertOk()->assertJsonPath('job', null);
        $this->shoot->update(['payment_status' => 'paid']);
        $this->claim()->assertOk();
        $this->worker()->getJson($url)->assertConflict();
    }

    public function test_preparation_rejects_disabled_scope_changed_identity_and_cancelled_job(): void
    {
        $id = $this->enqueue();
        $job = AryeoJob::findOrFail($id);
        $url = $this->prefix.'/jobs/'.$id.'/preparation?media_version='.$job->media_version;
        $this->connection->update(['delivery_shoot_ids' => []]);
        $this->worker()->getJson($url)->assertConflict();
        $this->connection->update(['delivery_shoot_ids' => [$this->shoot->id], 'processing_enabled' => false]);
        $this->worker()->getJson($url)->assertConflict();
        $this->connection->update(['processing_enabled' => true]);
        $this->order->update(['listing_id' => 'different-listing']);
        $this->worker()->getJson($url)->assertConflict();
        $this->order->update(['listing_id' => 'listing-1']);
        $job->update(['status' => 'cancelled']);
        $this->worker()->getJson($url)->assertConflict();
        $job->update(['status' => 'queued']);
        $this->file->update(['is_hidden' => true]);
        $this->worker()->getJson($url)->assertConflict();
        $this->file->update(['is_hidden' => false]);
        $this->file->update(['filename' => 'replacement.jpg']);
        $this->worker()->getJson($url)->assertConflict();
        $this->connection->update(['client_ids' => []]);
        $this->worker()->getJson($url)->assertNotFound();
        $this->worker()->getJson($this->prefix.'/jobs/preparation')->assertOk()->assertJsonPath('job', null);
    }

    public function test_preparation_never_exposes_another_connections_job_or_unapproved_asset(): void
    {
        $id = $this->enqueue();
        $job = AryeoJob::findOrFail($id);
        $this->worker()->getJson($this->prefix.'/jobs/'.$id.'/assets/999999/preparation?media_version='.$job->media_version)->assertNotFound();
        $other = $this->connection->replicate();
        $other->name = 'Other Mac';
        $other->token_hash = hash('sha256', 'another-worker-test-token-at-least-32-characters');
        $other->save();
        $this->withHeader('Authorization', 'Bearer another-worker-test-token-at-least-32-characters')->getJson($this->prefix.'/jobs/preparation')->assertOk()->assertJsonPath('job', null);
        $this->getJson($this->prefix.'/jobs/'.$id.'/preparation?media_version='.$job->media_version)->assertNotFound();
    }

    public function test_worker_token_cannot_access_admin_api(): void
    {
        $this->worker()->getJson('/api/shoots/'.$this->shoot->id.'/aryeo')->assertUnauthorized();
    }

    public function test_roles_are_enforced_on_panel_and_processing(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'client']))->postJson('/api/shoots/'.$this->shoot->id.'/aryeo/requests/'.$this->order->id.'/process')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'editing_manager']))->getJson('/api/shoots/'.$this->shoot->id.'/aryeo')->assertOk();
    }

    public function test_staff_privilege_does_not_bypass_customer_release(): void
    {
        $this->shoot->update(['payment_status' => 'unpaid']);
        $this->actingAs($this->admin)->postJson('/api/shoots/'.$this->shoot->id.'/aryeo/requests/'.$this->order->id.'/process')->assertConflict();
        $ready = app(AryeoCatalog::class)->readiness($this->shoot->fresh(), null, $this->order->discovery['required']);
        $this->assertFalse($ready['eligible']);
        $this->assertContains('payment_required', $ready['blockers']);
        $this->assertSame([], $ready['assets']);
    }

    public function test_missing_summary_is_not_a_readiness_blocker(): void
    {
        $this->worker()->getJson($this->prefix.'/shoots/'.$this->shoot->id.'/readiness?request_record_id='.$this->order->id)
            ->assertOk()->assertJsonPath('eligible', true)->assertJsonPath('dashboard', ['paid' => true, 'delivered' => true, 'summary_required' => false]);
    }

    public function test_paid_but_not_dashboard_delivered_cannot_be_processed(): void
    {
        $this->shoot->update(['status' => 'completed', 'workflow_status' => 'ready_for_client']);
        $ready = app(AryeoCatalog::class)->readiness($this->shoot->fresh(), null, $this->order->discovery['required']);
        $this->assertContains('dashboard_delivery_pending', $ready['blockers']);
        $this->assertFalse($ready['dashboard']['delivered']);
        $this->actingAs($this->admin)->postJson('/api/shoots/'.$this->shoot->id.'/aryeo/requests/'.$this->order->id.'/process')->assertConflict();
    }

    public function test_requested_category_without_quantity_uses_all_approved_media(): void
    {
        $payload = [...$this->order->discovery, 'source_id' => $this->order->source_id, 'request_id' => $this->order->request_id,
            'required' => ['photos' => null, 'floorplans' => 0, 'videos' => 0, 'tours' => 0]];
        $this->worker()->postJson($this->prefix.'/requests', $payload)->assertOk()->assertJsonPath('discovery.required.photos', null);
        $this->worker()->getJson($this->prefix.'/shoots/'.$this->shoot->id.'/readiness?request_record_id='.$this->order->id)
            ->assertOk()->assertJsonPath('eligible', true)->assertJsonCount(1, 'assets');
        $payload['required']['floorplans'] = null;
        $this->worker()->postJson($this->prefix.'/requests', $payload)->assertOk();
        $this->worker()->getJson($this->prefix.'/shoots/'.$this->shoot->id.'/readiness?request_record_id='.$this->order->id)
            ->assertOk()->assertJsonPath('eligible', false)->assertJsonFragment(['blockers' => ['missing_floorplans']]);
    }

    public function test_unknown_categories_can_be_discovered_but_not_processed(): void
    {
        $payload = [...$this->order->discovery, 'source_id' => $this->order->source_id, 'request_id' => $this->order->request_id, 'required' => null];
        $this->worker()->postJson($this->prefix.'/requests', $payload)->assertOk();
        $this->worker()->getJson($this->prefix.'/shoots/'.$this->shoot->id.'/readiness?request_record_id='.$this->order->id)
            ->assertOk()->assertJsonPath('eligible', false)->assertJsonFragment(['blockers' => ['request_requirements_unknown']]);
        $payload['required'] = ['photos' => null];
        $this->worker()->postJson($this->prefix.'/requests', $payload)->assertUnprocessable();
    }

    public function test_job_and_receipt_include_only_requested_categories(): void
    {
        $floorplan = $this->file->replicate();
        $floorplan->filename = 'floorplan.jpg';
        $floorplan->media_type = 'floorplan';
        $floorplan->save();
        $video = $this->file->replicate();
        $video->filename = 'video.mp4';
        $video->file_type = 'video/mp4';
        $video->save();
        $this->shoot->update(['tour_links' => ['zillow_3d' => 'https://www.zillow.com/view-3d-home/test']]);

        $id = $this->enqueue();
        $job = AryeoJob::findOrFail($id);
        $this->assertSame(['photos' => 1, 'floorplans' => 1, 'videos' => 1, 'tours' => 1], $job->snapshot['available']);
        $this->assertSame([$this->file->id], array_column($job->snapshot['assets'], 'id'));
        $this->assertSame([], $job->snapshot['tours']);
        $this->worker()->getJson($this->prefix.'/jobs/'.$id.'/assets/'.$floorplan->id.'/preparation?media_version='.$job->media_version)->assertNotFound();
        $this->claim()->assertOk();
        $this->worker()->postJson($this->prefix.'/jobs/'.$id.'/result', [
            'lease_token' => 'claim_secret_12345678901234567890', 'steps' => ['delivery' => 'success'],
            'receipt' => ['request_id' => 'aryeo-1', 'listing_id' => 'listing-1', 'media_version' => $job->media_version,
                'verified_at' => now()->toIso8601String(), 'asset_ids' => [$this->file->id], 'tour_ids' => []],
        ])->assertOk();
    }

    public function test_unspecified_quantities_include_requested_photos_and_tours_without_other_media(): void
    {
        $floorplan = $this->file->replicate();
        $floorplan->media_type = 'floorplan';
        $floorplan->save();
        $this->shoot->update(['tour_links' => ['zillow_3d' => 'https://www.zillow.com/view-3d-home/test']]);
        $requirements = ['photos' => null, 'floorplans' => 0, 'videos' => 0, 'tours' => null];
        $ready = app(AryeoCatalog::class)->readiness($this->shoot->fresh(), null, $requirements);
        $this->assertTrue($ready['eligible']);
        $this->assertSame([$this->file->id], array_column($ready['assets'], 'id'));
        $this->assertSame(['zillow_3d'], array_column($ready['tours'], 'id'));
        $floorplan->update(['filename' => 'replacement-floorplan.jpg']);
        $this->assertSame($ready['media_version'], app(AryeoCatalog::class)->readiness($this->shoot->fresh(), null, $requirements)['media_version']);
        $empty = app(AryeoCatalog::class)->readiness($this->shoot->fresh(), null, array_fill_keys(array_keys($requirements), 0));
        $this->assertFalse($empty['eligible']);
        $this->assertContains('no_approved_media', $empty['blockers']);
    }

    public function test_paywall_override_does_not_replace_paid_requirement_for_aryeo(): void
    {
        $this->shoot->update(['payment_status' => 'unpaid', 'bypass_paywall' => true]);
        $ready = app(AryeoCatalog::class)->readiness($this->shoot->fresh(), null, $this->order->discovery['required']);
        $this->assertContains('payment_required', $ready['blockers']);
        $this->assertFalse($ready['eligible']);
    }

    public function test_non_media_fee_does_not_hide_an_approved_tour(): void
    {
        $this->shoot->update(['tour_links' => ['zillow_3d' => 'https://www.zillow.com/view-3d-home/test']]);
        $line = \App\Models\ShootService::create(['shoot_id' => $this->shoot->id, 'service_id' => $this->shoot->service_id, 'price' => 100, 'quantity' => 1, 'is_deliverable' => true, 'delivery_status' => 'delivered']);
        \App\Models\ShootService::create(['shoot_id' => $this->shoot->id, 'service_id' => \App\Models\Service::factory()->create()->id, 'price' => 25, 'quantity' => 1, 'is_deliverable' => false, 'delivery_status' => 'not_started']);
        $ready = app(AryeoCatalog::class)->readiness($this->shoot->fresh(), null, ['photos' => 1, 'floorplans' => 0, 'videos' => 0, 'tours' => 1]);
        $this->assertTrue($ready['eligible']);
        $this->assertSame(1, $ready['available']['tours']);
        $line->update(['delivery_status' => 'not_started']);
        $ready = app(AryeoCatalog::class)->readiness($this->shoot->fresh(), null, ['tours' => 1]);
        $this->assertSame(0, $ready['available']['tours']);
        $this->assertContains('missing_tours', $ready['blockers']);
    }

    public function test_hidden_quarantined_and_raw_files_are_not_in_manifest(): void
    {
        $this->file->update(['is_hidden' => true]);
        $this->worker()->getJson($this->prefix.'/shoots/'.$this->shoot->id.'/readiness?request_record_id='.$this->order->id)->assertOk()->assertJsonCount(0, 'assets')->assertJsonPath('eligible', false);
        $this->file->update(['is_hidden' => false, 'scan_status' => 'quarantined']);
        $this->assertSame([], app(AryeoCatalog::class)->readiness($this->shoot->fresh(), null)['assets']);
    }

    public function test_processing_disabled_by_default_and_selected_shoot_enforced(): void
    {
        $this->connection->update(['processing_enabled' => false]);
        $this->actingAs($this->admin)->postJson('/api/shoots/'.$this->shoot->id.'/aryeo/requests/'.$this->order->id.'/process')->assertConflict();
        $this->connection->update(['processing_enabled' => true, 'delivery_shoot_ids' => []]);
        $this->actingAs($this->admin)->postJson('/api/shoots/'.$this->shoot->id.'/aryeo/requests/'.$this->order->id.'/process')->assertConflict();
    }

    public function test_duplicate_clicks_and_claim_retries_reuse_job(): void
    {
        $id = $this->enqueue();
        $this->assertSame($id, $this->enqueue());
        $this->claim()->assertOk()->assertJsonPath('job.id', $id);
        $this->claim()->assertOk()->assertJsonPath('job.id', $id);
        $this->claim('bdc5c716-05f7-4e26-b17a-ea10dfb2d133')->assertOk()->assertJsonPath('job', null);
        $this->assertSame(1, AryeoJob::count());
    }

    public function test_lease_theft_and_expired_worker_cannot_act(): void
    {
        $id = $this->enqueue();
        $this->claim()->assertOk();
        $this->worker()->postJson($this->prefix.'/jobs/'.$id.'/authorize', ['lease_token' => 'wrong'])->assertConflict();
        $this->travel(100)->seconds();
        $this->worker()->postJson($this->prefix.'/jobs/'.$id.'/authorize', ['lease_token' => 'claim_secret_12345678901234567890'])->assertConflict();
        $this->claim('bdc5c716-05f7-4e26-b17a-ea10dfb2d133', 'second_claim_12345678901234567890')->assertOk()->assertJsonPath('reconciliation_required', true);
        $this->worker()->postJson($this->prefix.'/jobs/'.$id.'/authorize', ['lease_token' => 'second_claim_12345678901234567890'])->assertConflict();
    }

    public function test_live_progress_distinguishes_activity_from_lease_heartbeats_and_rejects_stale_updates(): void
    {
        $id = $this->enqueue();
        $this->claim()->assertOk();
        $url = $this->prefix.'/jobs/'.$id.'/renew';
        $body = ['lease_token' => 'claim_secret_12345678901234567890', 'phase' => 'uploading',
            'progress' => ['action' => 'adding_files', 'message' => 'Adding photo to Aryeo', 'completed' => 0, 'total' => 2,
                'current_file' => 'photo.jpg', 'observed_at' => now()->toIso8601String()]];
        $first = $this->worker()->postJson($url, $body)->assertOk()->assertJsonPath('error', null)->assertJsonPath('progress.completed', 0)->json('progress');
        $this->travel(10)->seconds();
        $body['progress']['observed_at'] = now()->toIso8601String();
        $second = $this->worker()->postJson($url, $body)->assertOk()->json('progress');
        $this->assertSame($first['activity_at'], $second['activity_at']);
        $this->assertNotSame($first['updated_at'], $second['updated_at']);
        $body['progress']['completed'] = 1;
        $third = $this->worker()->postJson($url, $body)->assertOk()->json('progress');
        $this->assertNotSame($first['activity_at'], $third['activity_at']);
        $body['progress']['completed'] = 0;
        $body['progress']['observed_at'] = $first['observed_at'];
        $this->worker()->postJson($url, $body)->assertOk()->assertJsonPath('progress.completed', 1);
        $this->actingAs($this->admin)->getJson('/api/shoots/'.$this->shoot->id.'/aryeo')->assertOk()->assertJsonPath('orders.0.jobs.0.progress.completed', 1);
    }

    public function test_progress_requires_lease_ownership_and_valid_counts_and_does_not_erase_errors(): void
    {
        $id = $this->enqueue();
        $this->claim()->assertOk();
        $url = $this->prefix.'/jobs/'.$id.'/renew';
        $body = ['lease_token' => 'wrong', 'progress' => ['action' => 'uploading', 'message' => 'Uploading photo', 'completed' => 0, 'total' => 2, 'observed_at' => now()->toIso8601String()]];
        $this->worker()->postJson($url, $body)->assertConflict();
        $this->assertNull(AryeoJob::find($id)->progress);
        $body['lease_token'] = 'claim_secret_12345678901234567890';
        $body['progress']['completed'] = 3;
        $this->worker()->postJson($url, $body)->assertUnprocessable();
        AryeoJob::find($id)->update(['error' => 'Upload needs attention']);
        $body['progress']['completed'] = 0;
        $this->worker()->postJson($url, $body)->assertOk()->assertJsonPath('error', 'Upload needs attention');
    }

    public function test_legacy_worker_phase_is_progress_not_an_error(): void
    {
        $id = $this->enqueue();
        $this->claim()->assertOk();
        AryeoJob::find($id)->update(['error' => 'Progress: uploading']);
        $this->worker()->postJson($this->prefix.'/jobs/'.$id.'/renew', ['lease_token' => 'claim_secret_12345678901234567890', 'phase' => 'uploading'])
            ->assertOk()->assertJsonPath('error', null)->assertJsonPath('progress.action', 'uploading');
    }

    public function test_cancellation_after_claim_revokes_delivery_permission(): void
    {
        $id = $this->enqueue();
        $this->claim()->assertOk();
        $this->shoot->update(['status' => 'cancelled', 'workflow_status' => 'cancelled']);
        $this->worker()->postJson($this->prefix.'/jobs/'.$id.'/authorize', ['lease_token' => 'claim_secret_12345678901234567890'])->assertConflict();
    }

    public function test_uploaded_does_not_mean_delivered_and_receipt_requires_all_assets(): void
    {
        $id = $this->enqueue();
        $this->claim()->assertOk();
        $body = ['lease_token' => 'claim_secret_12345678901234567890', 'steps' => ['delivery' => 'success']];
        $this->worker()->postJson($this->prefix.'/jobs/'.$id.'/result', $body)->assertUnprocessable();
        $body['receipt'] = ['request_id' => 'aryeo-1', 'listing_id' => 'listing-1', 'media_version' => AryeoJob::find($id)->media_version, 'verified_at' => now()->toIso8601String(), 'asset_ids' => [], 'tour_ids' => []];
        $this->worker()->postJson($this->prefix.'/jobs/'.$id.'/result', $body)->assertUnprocessable();
        $body['receipt']['asset_ids'] = [$this->file->id];
        $this->worker()->postJson($this->prefix.'/jobs/'.$id.'/result', $body)->assertOk()->assertJsonPath('status', 'followup_pending')->assertJsonPath('steps.summary_forwarding', 'not_applicable');
        $this->worker()->postJson($this->prefix.'/jobs/'.$id.'/result', $body)->assertOk();
    }

    public function test_bulk_media_changes_and_deletions_are_in_change_feed(): void
    {
        $cursor = DB::table('aryeo_changes')->max('id');
        DB::table('shoot_files')->where('id', $this->file->id)->update(['is_hidden' => true]);
        DB::table('shoot_files')->where('id', $this->file->id)->delete();
        $this->worker()->getJson($this->prefix.'/changes?cursor='.$cursor)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.1.kind', 'shoot_files.DELETE');
    }

    public function test_inventory_starts_unknown_and_rejects_wrong_listing(): void
    {
        $this->actingAs($this->admin)->getJson('/api/shoots/'.$this->shoot->id.'/aryeo')->assertOk()->assertJsonPath('orders.0.inventory', null);
        $this->worker()->putJson($this->prefix.'/requests/'.$this->order->id.'/inventory', ['request_id' => 'aryeo-1', 'listing_id' => 'wrong', 'checked_at' => now()->toIso8601String(), 'complete' => true, 'assets' => []])->assertConflict();
    }

    public function test_empty_verified_inventory_is_valid_and_allows_revalidation(): void
    {
        $id = $this->enqueue();
        $this->claim()->assertOk();
        $this->worker()->postJson($this->prefix.'/jobs/'.$id.'/authorize', ['lease_token' => 'claim_secret_12345678901234567890'])->assertConflict();
        $this->worker()->putJson($this->prefix.'/requests/'.$this->order->id.'/inventory', ['request_id' => 'aryeo-1', 'listing_id' => 'listing-1', 'checked_at' => now()->toIso8601String(), 'complete' => true, 'assets' => []])->assertOk();
        $this->worker()->postJson($this->prefix.'/jobs/'.$id.'/authorize', ['lease_token' => 'claim_secret_12345678901234567890'])->assertOk()->assertJsonPath('eligible', true);
    }

    public function test_duplicate_provider_request_from_another_email_is_not_a_second_order(): void
    {
        $payload = [...$this->order->discovery, 'source_id' => 'second-email', 'request_id' => 'aryeo-1', 'listing_id' => 'listing-1'];
        $this->worker()->postJson($this->prefix.'/requests', $payload)->assertOk()->assertJsonPath('id', $this->order->id);
        $this->assertSame(1, AryeoRequest::count());
    }

    public function test_discovery_matches_verified_client_with_abbreviated_street_suffix(): void
    {
        foreach (['1036 Scenic Drive' => '  1036   Scenic Dr.  ', '1732 Fletchers Drive' => '1732 Fletchers Dr', '9407 Reservoir Road' => '9407 Reservoir Rd'] as $canonical => $source) {
            $this->shoot->update(['address' => $canonical]);
            $payload = [...$this->order->discovery, 'source_id' => $canonical, 'request_id' => $canonical, 'address' => $source];
            $this->worker()->postJson($this->prefix.'/requests', $payload)->assertOk()
                ->assertJsonPath('shoot_id', $this->shoot->id)->assertJsonPath('match_status', 'matched')
                ->assertJsonPath('discovery.address', trim($source));
        }
    }

    private function scheduledDiscoveryFixture(): array
    {
        $client = User::factory()->create(['role' => 'client', 'name' => 'Trish May', 'email' => 'trishmoveshomes@example.test']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id, 'address' => '413 Mahan Ln Annapolis',
            'city' => 'Annapolis', 'state' => 'MD', 'zip' => '21401', 'status' => 'scheduled', 'workflow_status' => 'scheduled',
            'scheduled_date' => '2026-10-21', 'admin_verified_at' => null, 'payment_status' => 'paid']);
        $payload = ['source_id' => 'gmail-scheduled', 'request_id' => 'request-scheduled', 'address' => '413 Mahan Ln',
            'requester_email' => $client->email, 'summary_present' => false,
            'required' => ['photos' => null, 'floorplans' => 0, 'videos' => 0, 'tours' => null]];

        return [$client, $shoot, $payload];
    }

    public function test_all_client_discovery_is_opt_in_and_can_be_enabled_without_rotating_or_expanding_media_access(): void
    {
        [$client, $shoot, $payload] = $this->scheduledDiscoveryFixture();
        $before = $this->connection->only(['token_hash', 'client_ids', 'delivery_shoot_ids', 'processing_enabled']);
        $this->worker()->postJson($this->prefix.'/requests', $payload)->assertOk()
            ->assertJsonPath('shoot_id', null)->assertJsonPath('identity', null)->assertJsonPath('match_status', 'unmatched');
        $this->actingAs($this->admin)->getJson('/api/shoots/'.$shoot->id.'/aryeo')->assertOk()->assertJsonPath('configured', false);

        $this->artisan('aryeo:connection', ['name' => $this->connection->name, '--enable-discovery' => true])->assertSuccessful();
        $this->assertTrue($this->connection->fresh()->discover_all_clients);
        $this->assertSame($before, $this->connection->fresh()->only(array_keys($before)));
        $this->worker()->postJson($this->prefix.'/requests', $payload)->assertOk()
            ->assertJsonPath('shoot_id', $shoot->id)->assertJsonPath('discovery_only', true)->assertJsonPath('identity.client_id', $client->id);
        $this->worker()->postJson($this->prefix.'/heartbeat', ['processing_mode' => 'dashboard_jobs', 'shoot_executor' => true,
            'inventory_refresh' => true, 'version' => 'test'])->assertOk()->assertJsonPath('discover_all_clients', true);
        $this->artisan('aryeo:connection', ['name' => $this->connection->name, '--disable-discovery' => true])->assertSuccessful();
        $this->worker()->postJson($this->prefix.'/requests', $payload)->assertNotFound();
    }

    public function test_metadata_discovery_shows_a_scheduled_shoot_before_media_readiness_and_never_grants_media_or_delivery_access(): void
    {
        [$client, $shoot, $payload] = $this->scheduledDiscoveryFixture();
        $this->connection->update(['discover_all_clients' => true]);
        $response = $this->worker()->postJson($this->prefix.'/requests', $payload)->assertOk()
            ->assertJsonPath('match_status', 'matched')->assertJsonPath('shoot_id', $shoot->id)->assertJsonPath('discovery_only', true)
            ->assertJsonPath('identity.status', 'scheduled')->assertJsonPath('identity.client_email', $client->email)
            ->assertJsonMissingPath('inventory')->assertJsonMissingPath('assets')->assertJsonMissingPath('identity.assets');
        $recordId = $response->json('id');
        $this->worker()->postJson($this->prefix.'/requests', $payload)->assertOk()->assertJsonPath('id', $recordId);
        $this->assertSame(1, AryeoRequest::where('request_id', $payload['request_id'])->count());
        $this->assertSame([$this->shoot->client_id], $this->connection->fresh()->client_ids);
        $this->assertSame([$this->shoot->id], $this->connection->fresh()->delivery_shoot_ids);
        $this->assertSame(0, AryeoJob::count());
        $this->worker()->getJson($this->prefix.'/shoots/'.$shoot->id)->assertNotFound();
        $this->worker()->getJson($this->prefix.'/shoots/'.$shoot->id.'/readiness?request_record_id='.$recordId)->assertNotFound();
        $this->worker()->getJson($this->prefix.'/requests/'.$recordId.'/assets/'.$this->file->id.'/original')->assertNotFound();
        $this->worker()->putJson($this->prefix.'/requests/'.$recordId.'/inventory', [])->assertNotFound();
        $this->worker()->getJson($this->prefix.'/shoots')->assertOk()->assertJsonCount(1, 'data');

        $panel = $this->actingAs($this->admin)->getJson('/api/shoots/'.$shoot->id.'/aryeo')->assertOk()
            ->assertJsonPath('configured', true)->assertJsonPath('connections.0.discovery_only', true)
            ->assertJsonPath('connections.0.shoot_enabled', false)->assertJsonPath('orders.0.id', $recordId)
            ->assertJsonPath('orders.0.readiness.eligible', false)->assertJsonPath('orders.0.readiness.available.photos', 0);
        $this->assertContains('editing_not_approved', $panel->json('orders.0.readiness.blockers'));
        $this->actingAs($this->admin)->postJson('/api/shoots/'.$shoot->id.'/aryeo/requests/'.$recordId.'/process')->assertNotFound();
        $this->assertSame(0, AryeoJob::count());
    }

    public function test_metadata_discovery_keeps_email_location_and_duplicate_booking_checks(): void
    {
        [$client, $shoot, $payload] = $this->scheduledDiscoveryFixture();
        $this->connection->update(['discover_all_clients' => true]);
        foreach ([['requester_email' => 'wrong@example.test'], ['address' => '414 Mahan Ln'],
            ['address' => '413 Mahan Ln Baltimore'], ['address' => '413 Mahan Ln Annapolis MD 99999']] as $index => $change) {
            $this->worker()->postJson($this->prefix.'/requests', [...$payload, ...$change,
                'source_id' => 'wrong-'.$index, 'request_id' => 'wrong-'.$index])->assertOk()
                ->assertJsonPath('shoot_id', null)->assertJsonPath('identity', null)->assertJsonPath('match_status', 'unmatched');
        }
        Shoot::factory()->create(['client_id' => $client->id, 'address' => '413 Mahan Lane', 'city' => 'Annapolis']);
        $this->worker()->postJson($this->prefix.'/requests', $payload)->assertOk()
            ->assertJsonPath('shoot_id', null)->assertJsonPath('match_status', 'needs_matching');
    }

    public function test_metadata_discovery_reconsiders_an_email_when_its_booking_arrives_later(): void
    {
        [$client, $shoot, $payload] = $this->scheduledDiscoveryFixture();
        $this->connection->update(['discover_all_clients' => true]);
        $shoot->update(['address' => '99 Different Road']);
        $id = $this->worker()->postJson($this->prefix.'/requests', $payload)->assertOk()
            ->assertJsonPath('match_status', 'unmatched')->json('id');
        $shoot->update(['address' => '413 Mahan Lane Annapolis']);
        $this->worker()->postJson($this->prefix.'/requests', $payload)->assertOk()
            ->assertJsonPath('id', $id)->assertJsonPath('shoot_id', $shoot->id)->assertJsonPath('match_status', 'matched');
        $this->assertSame(0, AryeoJob::count());
    }

    public function test_metadata_match_is_revoked_when_the_shoot_is_reassigned(): void
    {
        [$client, $shoot, $payload] = $this->scheduledDiscoveryFixture();
        $this->connection->update(['discover_all_clients' => true]);
        $id = $this->worker()->postJson($this->prefix.'/requests', $payload)->assertOk()->assertJsonPath('shoot_id', $shoot->id)->json('id');
        $shoot->update(['client_id' => User::factory()->create(['role' => 'client'])->id]);
        $this->worker()->postJson($this->prefix.'/requests', $payload)->assertOk()
            ->assertJsonPath('id', $id)->assertJsonPath('shoot_id', null)->assertJsonPath('identity', null);
    }

    public function test_metadata_discovery_does_not_ignore_an_explicit_wrong_unit(): void
    {
        [$client, $shoot, $payload] = $this->scheduledDiscoveryFixture();
        $this->connection->update(['discover_all_clients' => true]);
        $unit = $shoot->units()->create(['client_key' => 'unit-a', 'label' => 'Unit A', 'kind' => 'unit']);
        $this->worker()->postJson($this->prefix.'/requests', [...$payload, 'unit_label' => 'Unit B'])->assertOk()
            ->assertJsonPath('shoot_id', null)->assertJsonPath('match_status', 'needs_matching');
        $this->worker()->postJson($this->prefix.'/requests', [...$payload, 'unit_label' => 'Unit A'])->assertOk()
            ->assertJsonPath('shoot_id', $shoot->id)->assertJsonPath('unit_id', $unit->id);
    }

    public function test_unmatched_review_excludes_unrelated_clients_at_other_addresses(): void
    {
        [$client, $shoot, $payload] = $this->scheduledDiscoveryFixture();
        $this->connection->update(['discover_all_clients' => true]);
        $this->worker()->postJson($this->prefix.'/requests', [...$payload, 'address' => '999 Other Road'])->assertOk();
        $this->worker()->postJson($this->prefix.'/requests', [...$payload, 'source_id' => 'someone-else', 'request_id' => 'someone-else',
            'requester_email' => 'someone-else@example.test', 'address' => '998 Unrelated Road'])->assertOk();
        $this->actingAs($this->admin)->getJson('/api/shoots/'.$shoot->id.'/aryeo')->assertOk()
            ->assertJsonCount(1, 'unmatched')->assertJsonPath('unmatched.0.requester_email', $client->email);
    }

    public function test_different_email_is_visible_as_a_review_candidate_and_a_staff_match_survives_sync_without_media_scope(): void
    {
        [$client, $shoot, $payload] = $this->scheduledDiscoveryFixture();
        $this->connection->update(['discover_all_clients' => true]);
        $payload = [...$payload, 'requester_name' => 'Trish May', 'requester_email' => 'trish@business.example.test',
            'city' => 'Annapolis', 'state' => 'MD', 'zip' => '21401'];
        $id = $this->worker()->postJson($this->prefix.'/requests', $payload)->assertOk()
            ->assertJsonPath('shoot_id', null)->assertJsonPath('match_confirmed', false)->json('id');
        $this->actingAs($this->admin)->getJson('/api/shoots/'.$shoot->id.'/aryeo')->assertOk()
            ->assertJsonCount(1, 'unmatched')->assertJsonPath('unmatched.0.id', $id);
        $this->actingAs($this->admin)->postJson('/api/shoots/'.$shoot->id.'/aryeo/requests/'.$id.'/match', ['confirm_identity' => true])
            ->assertOk()->assertJsonPath('shoot_id', $shoot->id)->assertJsonMissingPath('match_verification');
        $this->assertSame($this->admin->id, AryeoRequest::findOrFail($id)->match_verification['confirmed_by']);
        $this->worker()->postJson($this->prefix.'/requests', $payload)->assertOk()->assertJsonPath('id', $id)
            ->assertJsonPath('shoot_id', $shoot->id)->assertJsonPath('match_confirmed', true)->assertJsonPath('discovery_only', true)
            ->assertJsonPath('identity.client_email', $client->email)->assertJsonPath('discovery.requester_email', $payload['requester_email']);
        $fallback = array_diff_key($payload, array_flip(['city', 'state', 'zip']));
        $fallback['requester_email'] = null;
        $this->worker()->postJson($this->prefix.'/requests', $fallback)->assertOk()->assertJsonPath('shoot_id', $shoot->id)
            ->assertJsonPath('match_confirmed', true)->assertJsonPath('discovery.requester_email', $payload['requester_email'])
            ->assertJsonPath('discovery.city', 'Annapolis');
        $this->assertNotContains($client->id, $this->connection->fresh()->client_ids);
        $this->assertNotContains($shoot->id, $this->connection->fresh()->delivery_shoot_ids);
        $this->worker()->getJson($this->prefix.'/shoots/'.$shoot->id)->assertNotFound();
        $client->update(['email' => 'changed@example.test']);
        $this->worker()->postJson($this->prefix.'/requests', $payload)->assertOk()
            ->assertJsonPath('shoot_id', null)->assertJsonPath('match_confirmed', false);
    }

    public function test_coordinator_review_candidates_retain_city_state_zip_date_and_street_checks(): void
    {
        [$client, $shoot, $payload] = $this->scheduledDiscoveryFixture();
        $this->connection->update(['discover_all_clients' => true]);
        foreach ([['city' => 'Baltimore'], ['state' => 'VA'], ['zip' => '99999'], ['address' => '414 Mahan Ln'],
            ['address' => '413 Another Lane'], ['scheduled_date' => '2026-10-22']] as $index => $change) {
            $this->worker()->postJson($this->prefix.'/requests', [...$payload, 'requester_name' => 'Listing Coordinator',
                'requester_email' => 'different@business.example.test', ...$change,
                'source_id' => 'review-'.$index, 'request_id' => 'review-'.$index])->assertOk();
        }
        $this->actingAs($this->admin)->getJson('/api/shoots/'.$shoot->id.'/aryeo')->assertOk()->assertJsonCount(0, 'unmatched');
    }

    public function test_coordinator_mail_is_visible_at_the_exact_scheduled_address_without_matching_or_granting_access(): void
    {
        [$client, $shoot, $payload] = $this->scheduledDiscoveryFixture();
        $shoot->update(['payment_status' => 'unpaid']);
        $this->connection->update(['discover_all_clients' => true]);
        $scope = $this->connection->only(['client_ids', 'delivery_shoot_ids', 'processing_enabled', 'token_hash']);
        $payload = [...$payload, 'requester_name' => 'Listing Coordinator', 'requester_email' => null, 'required' => null];
        $id = $this->worker()->postJson($this->prefix.'/requests', $payload)->assertOk()
            ->assertJsonPath('shoot_id', null)->assertJsonPath('match_confirmed', false)->json('id');
        $before = AryeoRequest::findOrFail($id)->toArray();
        $this->actingAs($this->admin)->getJson('/api/shoots/'.$shoot->id.'/aryeo')->assertOk()
            ->assertJsonCount(0, 'orders')->assertJsonCount(1, 'unmatched')->assertJsonPath('unmatched.0.id', $id)
            ->assertJsonPath('unmatched.0.requester_name', 'Listing Coordinator')->assertJsonPath('unmatched.0.requester_email', null);
        $this->assertSame($before, AryeoRequest::findOrFail($id)->toArray());
        $this->assertSame($scope, $this->connection->fresh()->only(array_keys($scope)));
        $this->actingAs($this->admin)->postJson('/api/shoots/'.$shoot->id.'/aryeo/requests/'.$id.'/match', ['confirm_identity' => false])->assertUnprocessable();
        $this->actingAs($this->admin)->postJson('/api/shoots/'.$shoot->id.'/aryeo/requests/'.$id.'/process')->assertNotFound();
        $this->worker()->getJson($this->prefix.'/shoots/'.$shoot->id)->assertNotFound();
        $this->assertSame('scheduled', $shoot->fresh()->status);
        $this->assertSame('unpaid', $shoot->fresh()->payment_status);
        $this->assertSame(0, AryeoJob::count());
    }

    public function test_verified_coordinator_email_and_whitespace_still_require_staff_review(): void
    {
        [$client, $shoot, $payload] = $this->scheduledDiscoveryFixture();
        $this->connection->update(['discover_all_clients' => true]);
        $id = $this->worker()->postJson($this->prefix.'/requests', [...$payload,
            'requester_name' => 'Listing Coordinator', 'requester_email' => 'coordinator@example.test',
            'address' => '  413    Mahan   Ln.  ', 'scheduled_date' => '2026-10-21'])->assertOk()
            ->assertJsonPath('shoot_id', null)->assertJsonPath('match_confirmed', false)->json('id');
        $this->actingAs($this->admin)->getJson('/api/shoots/'.$shoot->id.'/aryeo')->assertOk()
            ->assertJsonCount(1, 'unmatched')->assertJsonPath('unmatched.0.id', $id);
        $this->assertNull(AryeoRequest::findOrFail($id)->shoot_id);
        $this->assertSame(0, AryeoJob::count());
    }

    public function test_address_only_review_requires_metadata_optin_and_never_exposes_disabled_connections(): void
    {
        [$client, $shoot, $payload] = $this->scheduledDiscoveryFixture();
        $this->connection->update(['client_ids' => [$this->shoot->client_id, $client->id]]);
        $id = $this->worker()->postJson($this->prefix.'/requests', [...$payload,
            'requester_name' => 'Listing Coordinator', 'requester_email' => 'coordinator@example.test'])->assertOk()->json('id');
        $this->actingAs($this->admin)->getJson('/api/shoots/'.$shoot->id.'/aryeo')->assertOk()->assertJsonCount(0, 'unmatched');
        $this->connection->update(['discover_all_clients' => true]);
        $this->actingAs($this->admin)->getJson('/api/shoots/'.$shoot->id.'/aryeo')->assertOk()
            ->assertJsonCount(1, 'unmatched')->assertJsonPath('unmatched.0.id', $id);
        $this->connection->update(['enabled' => false]);
        $this->actingAs($this->admin)->getJson('/api/shoots/'.$shoot->id.'/aryeo')->assertOk()
            ->assertJsonPath('configured', false)->assertJsonCount(0, 'unmatched');
    }

    public function test_client_cannot_read_coordinator_review_metadata(): void
    {
        [$client, $shoot, $payload] = $this->scheduledDiscoveryFixture();
        $this->connection->update(['discover_all_clients' => true]);
        $this->worker()->postJson($this->prefix.'/requests', [...$payload,
            'requester_name' => 'Listing Coordinator', 'requester_email' => 'coordinator@example.test'])->assertOk();
        $this->actingAs($client)->getJson('/api/shoots/'.$shoot->id.'/aryeo')->assertForbidden();
    }

    public function test_an_email_without_provider_browser_access_is_visible_but_never_automatically_matched_or_deliverable(): void
    {
        [$client, $shoot, $payload] = $this->scheduledDiscoveryFixture();
        $payload = [...$payload, 'requester_email' => null, 'requester_name' => 'Trish May'];
        $this->worker()->postJson($this->prefix.'/requests', $payload)->assertUnprocessable();
        $this->connection->update(['discover_all_clients' => true]);
        $id = $this->worker()->postJson($this->prefix.'/requests', $payload)->assertOk()
            ->assertJsonPath('shoot_id', null)->assertJsonPath('identity', null)->assertJsonPath('discovery_only', true)->json('id');
        $this->actingAs($this->admin)->getJson('/api/shoots/'.$shoot->id.'/aryeo')->assertOk()
            ->assertJsonPath('configured', true)->assertJsonCount(1, 'unmatched')
            ->assertJsonPath('unmatched.0.requester_name', 'Trish May')->assertJsonPath('unmatched.0.requester_email', null);
        // Even a released, media-scoped shoot cannot queue a request whose
        // recipient is still unknown. A staff match only confirms association.
        $this->order->update(['discovery' => [...$this->order->discovery, 'requester_email' => null]]);
        $this->actingAs($this->admin)->postJson('/api/shoots/'.$this->shoot->id.'/aryeo/requests/'.$this->order->id.'/process')
            ->assertConflict();
        $this->assertSame(0, AryeoJob::count());
        $this->worker()->postJson($this->prefix.'/requests', [...$payload, 'requester_email' => $client->email])->assertOk()
            ->assertJsonPath('id', $id)->assertJsonPath('shoot_id', $shoot->id)->assertJsonPath('match_status', 'matched');
    }

    public function test_abbreviated_street_match_does_not_guess_between_repeat_shoots(): void
    {
        $this->shoot->update(['address' => '1036 Scenic Drive']);
        Shoot::factory()->create(['client_id' => $this->shoot->client_id, 'address' => '1036 Scenic Dr']);
        $payload = [...$this->order->discovery, 'source_id' => 'repeat-address', 'request_id' => 'repeat-address', 'address' => '1036 Scenic Dr.'];
        $this->worker()->postJson($this->prefix.'/requests', $payload)->assertOk()
            ->assertJsonPath('shoot_id', null)->assertJsonPath('match_status', 'needs_matching');
    }

    public function test_abbreviated_street_match_retains_client_and_street_number_checks(): void
    {
        $this->shoot->update(['address' => '1036 Scenic Drive']);
        foreach ([['address' => '1036 Scenic Dr', 'requester_email' => 'another-client@example.test'], ['address' => '1037 Scenic Dr']] as $index => $changes) {
            $payload = [...$this->order->discovery, 'source_id' => 'wrong-identity-'.$index, 'request_id' => 'wrong-identity-'.$index, ...$changes];
            $this->worker()->postJson($this->prefix.'/requests', $payload)->assertOk()
                ->assertJsonPath('shoot_id', null)->assertJsonPath('match_status', 'unmatched');
        }
    }

    public function test_paid_shoot_does_not_release_unready_service_media(): void
    {
        $line = \App\Models\ShootService::create(['shoot_id' => $this->shoot->id, 'service_id' => $this->shoot->service_id, 'price' => 100, 'quantity' => 1, 'is_deliverable' => true, 'delivery_status' => 'not_started']);
        $this->file->update(['shoot_service_id' => $line->id]);
        $this->worker()->getJson($this->prefix.'/shoots/'.$this->shoot->id.'/readiness?request_record_id='.$this->order->id)->assertOk()->assertJsonPath('available.photos', 0)->assertJsonPath('eligible', false);
    }

    public function test_client_scope_transfer_reports_revocation_and_prevents_old_job_reads(): void
    {
        $id = $this->enqueue();
        $cursor = DB::table('aryeo_changes')->max('id');
        $otherClient = User::factory()->create(['role' => 'client']);
        DB::table('shoots')->where('id', $this->shoot->id)->update(['client_id' => $otherClient->id]);
        $this->worker()->getJson($this->prefix.'/changes?cursor='.$cursor)->assertOk()->assertJsonPath('data.0.kind', 'scope_revoked');
        $this->worker()->getJson($this->prefix.'/jobs/'.$id)->assertNotFound();
    }

    public function test_original_retains_verified_checksum_when_download_offload_is_enabled(): void
    {
        config(['media.download_offload' => true, 'media.performance_shoot_ids' => []]);
        $disk = \Illuminate\Support\Facades\Storage::fake('local');
        $bytes = 'approved original bytes';
        $disk->put('shoots/test/photo.jpg', $bytes);
        $this->mock(\App\Services\Shoots\ShootFileAccessService::class, function ($mock) use ($disk) {
            $mock->shouldReceive('resolveLocalPath')->with('shoots/test/photo.jpg')->andReturn($disk->path('shoots/test/photo.jpg'));
        });

        $response = $this->worker()->get($this->prefix.'/requests/'.$this->order->id.'/assets/'.$this->file->id.'/original');
        $response->assertOk()->assertHeader('X-Content-SHA256', hash('sha256', $bytes))->assertHeaderMissing('X-Accel-Redirect');
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\BinaryFileResponse::class, $response->baseResponse);
        $this->assertSame($bytes, file_get_contents($response->baseResponse->getFile()->getPathname()));
    }

    public function test_missing_original_does_not_fall_back_to_thumbnail(): void
    {
        $this->mock(\App\Services\Shoots\ShootFileAccessService::class, function ($mock) {
            $mock->shouldReceive('resolveLocalPath')->with('shoots/test/photo.jpg')->andReturn(null);
            $mock->shouldReceive('downloadStoredFileToTemp')->with('shoots/test/photo.jpg')->andReturn(null);
        });
        $this->file->update(['thumbnail_path' => 'available-thumbnail.jpg']);
        $this->worker()->getJson($this->prefix.'/requests/'.$this->order->id.'/assets/'.$this->file->id.'/original')->assertNotFound();
    }

    public function test_unknown_quote_is_not_treated_as_free_release(): void
    {
        $this->shoot->forceFill(['payment_status' => 'pending', 'total_quote' => null]);
        $ready = app(AryeoCatalog::class)->readiness($this->shoot, null, $this->order->discovery['required']);
        $this->assertFalse($ready['eligible']);
        $this->assertSame([], $ready['assets']);
    }

    public function test_provisioning_writes_secret_to_file_and_defaults_to_read_only(): void
    {
        $path = sys_get_temp_dir().'/aryeo-'.bin2hex(random_bytes(8));
        try {
            $this->artisan('aryeo:connection', ['name' => 'Provisioned worker', '--company' => 'repro', '--clients' => (string) $this->shoot->client_id, '--token-file' => $path])->assertSuccessful();
            $token = trim(file_get_contents($path));
            $connection = AryeoConnection::where('name', 'Provisioned worker')->firstOrFail();
            $this->assertSame(hash('sha256', $token), $connection->token_hash);
            $this->assertFalse($connection->processing_enabled);
            $this->assertSame(0600, fileperms($path) & 0777);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_imported_pro_identity_uses_verified_source_namespace(): void
    {
        $this->shoot->update(['external_booking_payload' => ['legacy_migration' => ['source' => 'pro.reprophotos.com', 'source_id' => 'pro-2468']]]);
        $this->worker()->getJson($this->prefix.'/shoots/'.$this->shoot->id)->assertOk()->assertJsonPath('imported_pro_shoot_id', 'pro-2468');
        $this->shoot->update(['external_booking_payload' => ['legacy_migration' => ['source' => 'unrelated-provider', 'source_id' => '2468']]]);
        $this->worker()->getJson($this->prefix.'/shoots/'.$this->shoot->id)->assertOk()->assertJsonPath('imported_pro_shoot_id', null);
    }

    private function providerStatusPayload(): array
    {
        return ['request_id' => $this->order->request_id, 'requester_email' => $this->order->discovery['requester_email'],
            'listing_id' => $this->order->listing_id, 'checked_at' => now()->toIso8601String(), 'delivery_status' => 'delivered',
            'counts' => ['photos' => 1, 'floorplans' => 0, 'videos' => 0, 'tours' => 0], 'delivered_counts' => null];
    }

    public function test_provider_status_can_report_an_out_of_media_scope_request_without_granting_media_or_execution(): void
    {
        [$client, $shoot, $payload] = $this->scheduledDiscoveryFixture();
        $this->connection->update(['discover_all_clients' => true]);
        $clients = $this->connection->client_ids;
        $allowed = $this->connection->delivery_shoot_ids;
        $token = $this->connection->token_hash;
        $id = $this->worker()->postJson($this->prefix.'/requests', $payload)->assertOk()->json('id');
        $this->worker()->putJson($this->prefix.'/requests/'.$id.'/provider-status', [
            'request_id' => $payload['request_id'], 'requester_email' => $client->email, 'listing_id' => 'external-listing',
            'checked_at' => now()->toIso8601String(), 'delivery_status' => 'delivered',
            'counts' => ['photos' => 40, 'floorplans' => 5, 'videos' => 0, 'tours' => 1],
        ])->assertOk()->assertJsonPath('provider_status.counts.photos', 40)
            ->assertJsonPath('provider_status.delivered_counts', null)->assertJsonMissingPath('inventory')->assertJsonMissingPath('assets');
        $this->actingAs($this->admin)->getJson('/api/shoots/'.$shoot->id.'/aryeo')->assertOk()
            ->assertJsonPath('orders.0.inventory.provider_status.delivery_status', 'delivered')
            ->assertJsonPath('orders.0.inventory_checked_at', null)->assertJsonMissingPath('orders.0.inventory.complete');
        $this->worker()->getJson($this->prefix.'/shoots/'.$shoot->id)->assertNotFound();
        $this->worker()->getJson($this->prefix.'/requests/'.$id.'/assets/'.$this->file->id.'/original')->assertNotFound();
        $this->assertSame('scheduled', $shoot->fresh()->status);
        $this->assertSame($clients, $this->connection->fresh()->client_ids);
        $this->assertSame($allowed, $this->connection->fresh()->delivery_shoot_ids);
        $this->assertSame($token, $this->connection->fresh()->token_hash);
        $this->assertSame(0, AryeoJob::count());
    }

    public function test_observed_provider_delivery_blocks_a_duplicate_job_and_repeated_external_action(): void
    {
        $id = $this->enqueue();
        $this->claim()->assertOk();
        $payload = $this->providerStatusPayload();
        $this->worker()->putJson($this->prefix.'/requests/'.$this->order->id.'/provider-status', $payload)->assertOk();
        $this->worker()->postJson($this->prefix.'/jobs/'.$id.'/authorize', ['lease_token' => 'claim_secret_12345678901234567890'])
            ->assertConflict();
        AryeoJob::findOrFail($id)->update(['status' => 'cancelled']);
        $this->actingAs($this->admin)->postJson('/api/shoots/'.$this->shoot->id.'/aryeo/requests/'.$this->order->id.'/process')
            ->assertConflict();
        $this->assertSame(1, AryeoJob::count());
        $this->assertNull(AryeoJob::findOrFail($id)->receipt);
    }

    public function test_provider_status_rejects_wrong_identity_listing_and_invalid_observation_counts(): void
    {
        $route = $this->prefix.'/requests/'.$this->order->id.'/provider-status';
        $payload = $this->providerStatusPayload();
        foreach ([['request_id' => 'another-request'], ['requester_email' => 'another@example.test'], ['listing_id' => 'another-listing']] as $changes) {
            $this->worker()->putJson($route, [...$payload, ...$changes])->assertConflict();
        }
        foreach ([['checked_at' => now()->addMinute()->toIso8601String()], ['checked_at' => now()->subMinutes(11)->toIso8601String()],
            ['counts' => ['photos' => 1]], ['counts' => [...$payload['counts'], 'photos' => -1]],
            ['delivered_counts' => [...$payload['counts'], 'photos' => 2]]] as $changes) {
            $this->worker()->putJson($route, [...$payload, ...$changes])->assertUnprocessable();
        }
        $this->assertNull($this->order->fresh()->inventory);
    }

    public function test_provider_status_is_idempotent_and_rejects_older_or_same_time_conflicting_evidence(): void
    {
        $route = $this->prefix.'/requests/'.$this->order->id.'/provider-status';
        $payload = $this->providerStatusPayload();
        $first = $this->worker()->putJson($route, $payload)->assertOk()->json();
        $this->worker()->putJson($route, $payload)->assertOk()->assertExactJson($first);
        $this->worker()->putJson($route, [...$payload, 'checked_at' => now()->subSeconds(2)->toIso8601String()])->assertConflict();
        $this->worker()->putJson($route, [...$payload, 'delivery_status' => 'pending'])->assertConflict();
        $this->assertSame('delivered', $this->order->fresh()->inventory['provider_status']['delivery_status']);
    }

    public function test_provider_status_keeps_observation_precision_when_normalizing_timezone(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-23T05:30:00Z'));
        $route = $this->prefix.'/requests/'.$this->order->id.'/provider-status';
        $payload = [...$this->providerStatusPayload(), 'checked_at' => '2026-10-23T01:29:59.123456-04:00'];
        $this->worker()->putJson($route, $payload)->assertOk()
            ->assertJsonPath('provider_status.checked_at', '2026-10-23T05:29:59.123456Z');
        $this->worker()->putJson($route, [...$payload, 'checked_at' => '2026-10-23T05:29:59.123456Z'])->assertOk();
        $this->worker()->putJson($route, [...$payload, 'checked_at' => '2026-10-23T05:29:59.123455Z'])->assertConflict();
        $this->travelBack();
    }

    public function test_unmatched_pending_provider_status_requires_optin_and_never_invents_a_listing_or_job(): void
    {
        $this->order->update(['shoot_id' => null, 'listing_id' => null, 'match_status' => 'unmatched']);
        $payload = [...$this->providerStatusPayload(), 'delivery_status' => 'pending',
            'counts' => ['photos' => null, 'floorplans' => null, 'videos' => null, 'tours' => null]];
        $route = $this->prefix.'/requests/'.$this->order->id.'/provider-status';
        $this->worker()->putJson($route, $payload)->assertNotFound();
        $this->connection->update(['discover_all_clients' => true]);
        $this->worker()->putJson($route, $payload)->assertOk()->assertJsonPath('listing_id', null)
            ->assertJsonPath('provider_status.counts.photos', null);
        $this->worker()->putJson($route, [...$payload, 'delivery_status' => 'delivered'])->assertUnprocessable();
        $this->assertNull($this->order->fresh()->shoot_id);
        $this->assertSame('unmatched', $this->order->fresh()->match_status);
        $this->assertSame(0, AryeoJob::count());
    }

    public function test_provider_status_is_connection_scoped_and_cannot_follow_a_reassigned_client(): void
    {
        [$client, $shoot, $payload] = $this->scheduledDiscoveryFixture();
        $this->connection->update(['discover_all_clients' => true]);
        $id = $this->worker()->postJson($this->prefix.'/requests', $payload)->assertOk()->json('id');
        $shoot->update(['client_id' => User::factory()->create(['role' => 'client'])->id]);
        $status = [...$this->providerStatusPayload(), 'request_id' => $payload['request_id'], 'requester_email' => $client->email];
        $this->worker()->putJson($this->prefix.'/requests/'.$id.'/provider-status', $status)->assertConflict();
        $other = $this->connection->replicate();
        $other->name = 'Another company';
        $other->token_hash = hash('sha256', 'another-worker-token');
        $other->save();
        $this->order->update(['connection_id' => $other->id]);
        $this->worker()->putJson($this->prefix.'/requests/'.$this->order->id.'/provider-status', $this->providerStatusPayload())->assertNotFound();
    }

    public function test_provider_status_preserves_inventory_receipts_and_survives_full_inventory_refresh(): void
    {
        $receipt = ['operation_id' => 'supervised-existing-delivery'];
        $this->order->update(['inventory' => ['manual_delivery_receipt' => $receipt]]);
        $route = $this->prefix.'/requests/'.$this->order->id;
        $this->worker()->putJson($route.'/provider-status', $this->providerStatusPayload())->assertOk();
        $this->assertSame($receipt, $this->order->fresh()->inventory['manual_delivery_receipt']);
        $status = $this->order->fresh()->inventory['provider_status'];
        $this->worker()->putJson($route.'/inventory', ['request_id' => $this->order->request_id, 'listing_id' => $this->order->listing_id,
            'checked_at' => now()->toIso8601String(), 'complete' => true, 'assets' => []])->assertOk();
        $this->assertSame($status, $this->order->fresh()->inventory['provider_status']);
        $this->assertSame($receipt, $this->order->fresh()->inventory['manual_delivery_receipt']);
    }

    public function test_provider_delivery_observation_retains_existing_managed_update_workflow(): void
    {
        $id = $this->enqueue();
        $job = AryeoJob::findOrFail($id);
        $job->update(['status' => 'completed', 'steps' => [...$job->steps, 'delivery' => 'success'],
            'receipt' => ['request_id' => $job->snapshot['request_id'], 'listing_id' => $job->snapshot['listing_id'],
                'media_version' => $job->media_version, 'asset_ids' => [$this->file->id], 'tour_ids' => [], 'verified_at' => now()->toIso8601String()]]);
        $this->worker()->putJson($this->prefix.'/requests/'.$this->order->id.'/provider-status', $this->providerStatusPayload())->assertOk();
        $this->file->update(['file_size' => 1234]);
        $this->assertNotSame($id, $this->enqueue());
        $this->assertSame(2, AryeoJob::count());
    }

    public function test_two_orders_cannot_drive_the_shared_browser_concurrently(): void
    {
        $first = $this->enqueue();
        $second = $this->order->replicate();
        $second->source_id = 'second-source';
        $second->request_id = 'second-order';
        $second->save();
        $this->actingAs($this->admin)->postJson('/api/shoots/'.$this->shoot->id.'/aryeo/requests/'.$second->id.'/process')->assertOk();
        $this->claim()->assertOk()->assertJsonPath('job.id', $first);
        $this->claim('bdc5c716-05f7-4e26-b17a-ea10dfb2d133')->assertOk()->assertJsonPath('job', null);
    }
}
