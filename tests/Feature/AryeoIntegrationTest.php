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
        $this->worker()->getJson($this->prefix.'/shoots/'.$this->shoot->id.'/readiness?request_record_id='.$this->order->id)->assertOk()->assertJsonPath('eligible', true);
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
