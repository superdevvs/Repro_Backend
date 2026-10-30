<?php

namespace Tests\Feature;

use App\Models\AryeoConnection;
use App\Models\AryeoJob;
use App\Models\AryeoRequest;
use App\Models\Shoot;
use App\Models\ShootActivityLog;
use App\Models\ShootFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AryeoDeliveryEnablementTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'aryeo_test_unchanged_worker_secret_at_least_32_characters';

    private User $admin;

    private User $client;

    private Shoot $shoot;

    private AryeoConnection $connection;

    private AryeoRequest $order;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        Queue::fake();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->client = User::factory()->create(['role' => 'client']);
        $old = Shoot::factory()->create();
        $this->shoot = Shoot::factory()->create(['client_id' => $this->client->id, 'status' => 'delivered',
            'workflow_status' => 'delivered', 'admin_verified_at' => now(), 'payment_status' => 'paid']);
        $this->connection = AryeoConnection::create(['name' => 'Mac', 'company_key' => 'repro', 'enabled' => true,
            'client_ids' => [$old->client_id], 'delivery_shoot_ids' => [$old->id], 'discover_all_clients' => true,
            'processing_enabled' => true, 'token_hash' => hash('sha256', self::TOKEN), 'last_seen_at' => now()]);
        $this->order = AryeoRequest::create(['connection_id' => $this->connection->id, 'source_id' => 'mail-new',
            'request_id' => 'request-new', 'match_status' => 'unmatched',
            'discovery' => ['address' => $this->shoot->address, 'requester_email' => 'coordinator@example.test',
                'required' => ['photos' => null, 'floorplans' => 0, 'videos' => 0, 'tours' => 1], 'summary_present' => false]]);
        $this->actingAs($this->admin)->postJson($this->base().'/match', ['confirm_identity' => true])->assertOk();
    }

    private function base(): string
    {
        return '/api/shoots/'.$this->shoot->id.'/aryeo/requests/'.$this->order->id;
    }

    public function test_explicit_enablement_preserves_grants_and_credentials_without_queueing_delivery(): void
    {
        $before = $this->connection->fresh();
        $this->getJson('/api/shoots/'.$this->shoot->id.'/aryeo')->assertOk()
            ->assertJsonPath('orders.0.delivery_setup.can_enable', true)
            ->assertJsonPath('orders.0.delivery_setup.client_access_required', true)
            ->assertJsonPath('connections.0.shoot_enabled', false);
        $this->postJson($this->base().'/enable-delivery', ['confirm_access' => true])->assertOk()
            ->assertJsonPath('shoot_enabled', true)->assertJsonPath('shoot_id', $this->shoot->id);
        $after = $this->connection->fresh();
        $this->assertSame([...$before->client_ids, $this->client->id], $after->client_ids);
        $this->assertSame([...$before->delivery_shoot_ids, $this->shoot->id], $after->delivery_shoot_ids);
        $this->assertSame($before->token_hash, $after->token_hash);
        $this->assertSame($before->processing_enabled, $after->processing_enabled);
        $this->assertSame($before->capabilities, $after->capabilities);
        $this->postJson($this->base().'/enable-delivery', ['confirm_access' => true])->assertOk();
        $this->assertSame(1, ShootActivityLog::where('action', 'aryeo_delivery_enabled')->count());
        $this->assertSame(0, AryeoJob::count());
        $this->getJson('/api/shoots/'.$this->shoot->id.'/aryeo')->assertOk()
            ->assertJsonPath('connections.0.shoot_enabled', true)->assertJsonPath('connections.0.discovery_only', false);
        $unrelated = Shoot::factory()->create();
        $this->withHeader('Authorization', 'Bearer '.self::TOKEN)->getJson('/api/integrations/aryeo/v1/shoots/'.$unrelated->id)->assertNotFound();
    }

    public function test_enablement_requires_admin_access_and_explicit_consent(): void
    {
        $this->postJson($this->base().'/enable-delivery')->assertUnprocessable();
        $this->actingAs($this->client)->postJson($this->base().'/enable-delivery', ['confirm_access' => true])->assertForbidden();
        $manager = User::factory()->create(['role' => 'editing_manager']);
        $this->actingAs($manager)->postJson($this->base().'/enable-delivery', ['confirm_access' => true])->assertForbidden();
        $this->getJson('/api/shoots/'.$this->shoot->id.'/aryeo')->assertOk()->assertJsonPath('orders.0.delivery_setup.can_enable', false);
        $this->assertNotContains($this->client->id, $this->connection->fresh()->client_ids);
    }

    public function test_enablement_cannot_override_paused_or_revoked_connections(): void
    {
        $before = $this->connection->fresh()->client_ids;
        $this->connection->update(['processing_enabled' => false]);
        $this->postJson($this->base().'/enable-delivery', ['confirm_access' => true])->assertConflict();
        $this->assertFalse($this->connection->fresh()->processing_enabled);
        $this->connection->update(['enabled' => false, 'processing_enabled' => true]);
        $this->postJson($this->base().'/enable-delivery', ['confirm_access' => true])->assertNotFound();
        $this->assertSame($before, $this->connection->fresh()->client_ids);
        $this->assertSame(0, AryeoJob::count());
    }

    public function test_stale_identity_wrong_unit_and_other_request_cannot_grant_access(): void
    {
        $this->postJson($this->base().'/enable-delivery', ['confirm_access' => true, 'unit_id' => 9999])->assertConflict();
        $other = Shoot::factory()->create();
        $this->postJson('/api/shoots/'.$other->id.'/aryeo/requests/'.$this->order->id.'/enable-delivery', ['confirm_access' => true])->assertNotFound();
        $this->shoot->update(['client_id' => $other->client_id]);
        $this->postJson($this->base().'/enable-delivery', ['confirm_access' => true])->assertConflict();
        $this->shoot->update(['client_id' => $this->client->id, 'address' => '99 Different Street']);
        $this->postJson($this->base().'/enable-delivery', ['confirm_access' => true])->assertConflict();
        $this->assertNotContains($this->client->id, $this->connection->fresh()->client_ids);
        $this->assertSame(0, AryeoJob::count());
    }

    public function test_an_automatic_match_requires_current_exact_client_and_street(): void
    {
        $this->order->update(['match_verification' => null, 'discovery' => [...$this->order->fresh()->discovery,
            'requester_email' => $this->client->email]]);
        $this->postJson($this->base().'/enable-delivery', ['confirm_access' => true])->assertOk();
        $this->assertContains($this->shoot->id, $this->connection->fresh()->delivery_shoot_ids);
        $another = Shoot::factory()->create(['client_id' => $this->client->id]);
        $this->assertNotContains($another->id, $this->connection->fresh()->delivery_shoot_ids);
        $this->assertSame(0, AryeoJob::count());
    }

    public function test_unknown_requester_and_changed_request_cannot_use_old_confirmation(): void
    {
        $original = $this->order->fresh()->discovery;
        $this->order->update(['discovery' => [...$original, 'requester_email' => null]]);
        $this->postJson($this->base().'/enable-delivery', ['confirm_access' => true])->assertConflict();
        $this->order->update(['discovery' => $original, 'request_id' => 'replacement-request']);
        $this->postJson($this->base().'/enable-delivery', ['confirm_access' => true])->assertConflict();
        $this->assertNotContains($this->client->id, $this->connection->fresh()->client_ids);
    }

    public function test_worker_grants_follow_explicit_client_shoot_and_current_identity(): void
    {
        $url = '/api/integrations/aryeo/v1/delivery-grants';
        $this->withHeader('Authorization', 'Bearer '.self::TOKEN)->getJson($url)->assertOk()->assertJsonCount(0, 'grants');
        $this->postJson($this->base().'/enable-delivery', ['confirm_access' => true])->assertOk();
        $this->getJson($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(1, 'grants')->assertJsonPath('grants.0.shoot_id', $this->shoot->id)
            ->assertJsonPath('grants.0.record_id', $this->order->id)->assertJsonPath('grants.0.listing_id', null)
            ->assertJsonPath('grants.0.match_confirmed', true)->assertJsonPath('grants.0.client_id', $this->client->id);
        $this->client->update(['email' => 'changed@example.test']);
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'grants');
        $this->connection->update(['processing_enabled' => false]);
        $this->getJson($url)->assertOk()->assertJsonPath('enabled', false)->assertJsonCount(0, 'grants');
    }

    public function test_video_links_cannot_satisfy_or_expand_an_interactive_tour_request(): void
    {
        $this->shoot->update(['tour_links' => ['video_branded' => 'https://vimeo.com/12345', 'video_mls' => 'https://vimeo.com/12345']]);
        $catalog = app(\App\Services\Aryeo\AryeoCatalog::class);
        $requirements = ['photos' => null, 'floorplans' => 0, 'videos' => 0, 'tours' => 1];
        $ready = $catalog->readiness($this->shoot->fresh(), null, $requirements);
        $this->assertSame(0, $ready['available']['tours']);
        $this->assertContains('missing_tours', $ready['blockers']);
        $this->shoot->update(['tour_links' => [...$this->shoot->tour_links, 'zillow_3d' => 'https://www.zillow.com/view-imx/test-tour']]);
        $ready = $catalog->readiness($this->shoot->fresh(), null, $requirements);
        $this->assertSame(1, $ready['available']['tours']);
        $this->assertSame(['zillow_3d'], array_column($ready['tours'], 'id'));
    }

    private function acceptanceJob(): array
    {
        $this->postJson($this->base().'/enable-delivery', ['confirm_access' => true])->assertOk();
        $this->connection->update(['capabilities' => ['processing_mode' => 'dashboard_jobs', 'shoot_executor' => true]]);
        ShootFile::create(['shoot_id' => $this->shoot->id, 'filename' => 'photo.jpg', 'stored_filename' => 'photo.jpg',
            'path' => 'shoots/test/photo.jpg', 'file_type' => 'image/jpeg', 'mime_type' => 'image/jpeg', 'file_size' => 100,
            'uploaded_by' => $this->admin->id, 'workflow_stage' => 'verified', 'scan_status' => 'clean']);
        $observation = ['origin' => 'aryeo_provider', 'request_id' => $this->order->request_id, 'listing_id' => null,
            'requester_email' => 'coordinator@example.test', 'delivery_status' => 'pending', 'checked_at' => now()->toIso8601String()];
        $this->order->update(['discovery' => [...$this->order->fresh()->discovery,
            'required' => ['photos' => 1, 'floorplans' => 0, 'videos' => 0, 'tours' => 0]],
            'inventory' => ['provider_status' => $observation]]);
        $job = $this->postJson($this->base().'/process')->assertOk()->json('id');
        $lease = 'test_lease_secret_for_pending_request_acceptance';
        $this->withHeader('Authorization', 'Bearer '.self::TOKEN)->postJson('/api/integrations/aryeo/v1/jobs/claim',
            ['claim_id' => (string) \Illuminate\Support\Str::uuid(), 'lease_token' => $lease])->assertOk()->assertJsonPath('job.id', $job);

        return ['/api/integrations/aryeo/v1/jobs/'.$job.'/authorize-request-acceptance', $lease, $observation, $job];
    }

    public function test_pending_acceptance_requires_a_running_lease_and_current_release(): void
    {
        [$url, $lease] = $this->acceptanceJob();
        $this->postJson($url, ['lease_token' => 'not-the-owner'])->assertConflict();
        $this->postJson($url, ['lease_token' => $lease])->assertOk()->assertJsonPath('eligible', true);
        $this->shoot->update(['payment_status' => 'unpaid']);
        $this->postJson($url, ['lease_token' => $lease])->assertConflict();
        $this->assertSame(1, AryeoJob::count());
        $this->assertNull($this->order->fresh()->listing_id);
    }

    public function test_pending_acceptance_rejects_stale_observations_existing_listings_and_identity_changes(): void
    {
        [$url, $lease, $observation] = $this->acceptanceJob();
        foreach ([['checked_at' => now()->subMinutes(3)->toIso8601String()], ['delivery_status' => 'delivered'],
            ['requester_email' => 'other@example.test'], ['request_id' => 'other-request'], ['origin' => 'unverified']] as $change) {
            $this->order->update(['inventory' => ['provider_status' => [...$observation, ...$change]]]);
            $this->postJson($url, ['lease_token' => $lease])->assertConflict();
        }
        $this->order->update(['inventory' => ['provider_status' => $observation], 'listing_id' => 'already-created']);
        $this->postJson($url, ['lease_token' => $lease])->assertConflict();
        $this->order->update(['listing_id' => null, 'request_id' => 'changed-request']);
        $this->postJson($url, ['lease_token' => $lease])->assertConflict();
    }

    public function test_reconciling_pending_job_requires_live_request_evidence_without_inventing_inventory(): void
    {
        [$url, $lease, $observation, $id] = $this->acceptanceJob();
        AryeoJob::findOrFail($id)->update(['status' => 'reconciling']);
        $renew = '/api/integrations/aryeo/v1/jobs/'.$id.'/renew';
        $this->postJson($url, ['lease_token' => $lease])->assertConflict();
        $this->order->update(['inventory' => ['provider_status' => [...$observation, 'checked_at' => now()->subMinutes(3)->toIso8601String()]]]);
        $this->postJson($renew, ['lease_token' => $lease, 'reconciliation' => 'not_delivered'])->assertConflict();
        $this->assertSame('reconciling', AryeoJob::findOrFail($id)->status);
        $this->order->update(['inventory' => ['provider_status' => $observation]]);
        $this->postJson($renew, ['lease_token' => $lease, 'reconciliation' => 'not_delivered'])->assertOk()->assertJsonPath('status', 'running');
        $this->postJson($url, ['lease_token' => $lease])->assertOk();
        $this->assertNull($this->order->fresh()->inventory_checked_at);
        $this->assertArrayNotHasKey('complete', $this->order->fresh()->inventory);
    }
}
