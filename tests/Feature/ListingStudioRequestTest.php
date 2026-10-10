<?php

namespace Tests\Feature;

use App\Models\ListingStudioRequest;
use App\Models\Shoot;
use App\Models\ShootActivityLog;
use App\Models\User;
use App\Services\ListingStudioAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ListingStudioRequestTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/listing-studio/requests';

    public function test_client_signup_is_persisted_for_review_and_scoped_to_self(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $other = User::factory()->create(['role' => 'client']);
        Sanctum::actingAs($client);
        $created = $this->postJson(self::URL, $this->signup())
            ->assertCreated()->assertJsonPath('data.client_id', $client->id)
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.contact.email', $client->email);
        $this->assertDatabaseHas('listing_studio_requests', ['id' => $created->json('data.id'), 'submitted_by_id' => $client->id]);
        $this->getJson(self::URL)->assertOk()->assertJsonPath('meta.total', 1);
        Sanctum::actingAs($other);
        $this->getJson(self::URL)->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/listing-studio/clients')->assertForbidden();
    }

    public function test_clients_cannot_spoof_client_identity_or_submit_prospect_contacts(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $other = User::factory()->create(['role' => 'client']);
        Sanctum::actingAs($client);
        $this->postJson(self::URL, $this->signup(['client_id' => $other->id]))->assertForbidden();
        $this->postJson(self::URL, $this->signup(['custom_client' => ['name' => 'Other', 'email' => $other->email]]))->assertForbidden();
        $this->assertDatabaseCount('listing_studio_requests', 0);
    }

    public function test_rep_client_picker_and_requests_follow_assignments_and_do_not_leak_peer_prospects(): void
    {
        $rep = User::factory()->create(['role' => 'salesRep']);
        $peer = User::factory()->create(['role' => 'salesRep']);
        $owned = User::factory()->create(['role' => 'client', 'created_by_id' => $rep->id]);
        $metadata = User::factory()->create(['role' => 'client', 'metadata' => ['accountRepId' => (string) $rep->id]]);
        $shootClient = User::factory()->create(['role' => 'client']);
        Shoot::factory()->create(['client_id' => $shootClient->id, 'rep_id' => $rep->id]);
        $unrelated = User::factory()->create(['role' => 'client', 'created_by_id' => $peer->id]);
        Sanctum::actingAs($rep);
        $picker = $this->getJson('/api/listing-studio/clients')->assertOk();
        $this->assertEqualsCanonicalizing([$owned->id, $metadata->id, $shootClient->id], array_column($picker->json('data'), 'id'));
        $this->postJson(self::URL, $this->signup(['client_id' => $owned->id]))->assertCreated();
        $this->postJson(self::URL, $this->signup(['client_id' => $unrelated->id]))->assertNotFound();
        $this->postJson(self::URL, $this->signup(['custom_client' => ['name' => 'Prospect', 'email' => 'prospect@example.com']]))->assertCreated();
        Sanctum::actingAs($peer);
        $this->getJson(self::URL)->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_custom_signup_keeps_contact_snapshot_without_creating_or_linking_an_account(): void
    {
        $rep = User::factory()->create(['role' => 'salesRep']);
        $existing = User::factory()->create(['role' => 'client']);
        $before = User::count();
        Sanctum::actingAs($rep);
        $this->postJson(self::URL, $this->signup(['custom_client' => [
            'name' => 'Custom contact', 'email' => $existing->email, 'phone' => '2025550123', 'company_name' => 'Studio Realty',
        ]]))->assertCreated()->assertJsonPath('data.client_id', null)->assertJsonPath('data.client', null)
            ->assertJsonPath('data.contact.name', 'Custom contact');
        $this->assertSame($before, User::count());
        Sanctum::actingAs($existing);
        $this->getJson(self::URL)->assertJsonPath('meta.total', 0);
    }

    public function test_retries_are_idempotent_and_duplicate_pending_signups_are_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'client']));
        $payload = $this->signup();
        $first = $this->postJson(self::URL, $payload)->assertCreated();
        $this->postJson(self::URL, $payload)->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        $this->postJson(self::URL, array_replace($payload, ['plan_code' => 'plan_200']))->assertConflict();
        $this->postJson(self::URL, $this->signup())->assertConflict();
        $this->assertDatabaseCount('listing_studio_requests', 1);
    }

    public function test_staff_requires_one_client_identity_and_valid_catalog_input(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $client = User::factory()->create(['role' => 'client']);
        $this->postJson(self::URL, $this->signup())->assertUnprocessable()->assertJsonValidationErrors('client_id');
        $this->postJson(self::URL, $this->signup(['client_id' => $client->id, 'custom_client' => ['name' => 'X', 'email' => 'x@example.com']]))
            ->assertUnprocessable();
        $this->postJson(self::URL, $this->signup(['client_id' => $client->id, 'plan_code' => 'free', 'services' => ['unknown']]))
            ->assertUnprocessable()->assertJsonValidationErrors(['plan_code', 'services.0']);
        $this->postJson(self::URL, $this->signup(['client_id' => $client->id, 'idempotency_key' => 'anything']))
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
    }

    public function test_call_and_change_requests_validate_required_details_and_final_dispositions(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        Sanctum::actingAs($client);
        $this->postJson(self::URL, ['type' => 'call', 'idempotency_key' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->postJson(self::URL, ['type' => 'change', 'idempotency_key' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors('details');
        $call = $this->postJson(self::URL, ['type' => 'call', 'phone' => '+12025550123',
            'preferred_time' => 'Weekdays 2-4 PM Eastern', 'idempotency_key' => (string) Str::uuid()])->assertCreated();
        $change = $this->postJson(self::URL, ['type' => 'change', 'details' => 'Please review my plan.',
            'idempotency_key' => (string) Str::uuid()])->assertCreated();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->patchJson(self::URL.'/'.$call->json('data.id'), ['status' => 'approved'])->assertUnprocessable();
        $this->patchJson(self::URL.'/'.$call->json('data.id'), ['status' => 'completed'])->assertOk();
        $this->patchJson(self::URL.'/'.$change->json('data.id'), ['status' => 'approved'])->assertOk();
    }

    public function test_only_admins_review_and_a_second_reviewer_cannot_overwrite_a_decision(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        Sanctum::actingAs($client);
        $id = $this->postJson(self::URL, $this->signup())->assertCreated()->json('data.id');
        foreach (['client', 'salesRep', 'editing_manager', 'photographer', 'editor'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->patchJson(self::URL.'/'.$id, ['status' => 'approved'])->assertForbidden();
        }
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);
        $this->patchJson(self::URL.'/'.$id, ['status' => 'approved', 'review_note' => 'Terms discussed; follow up to arrange service.'])
            ->assertOk()->assertJsonPath('data.reviewed_by.id', $admin->id);
        Sanctum::actingAs(User::factory()->create(['role' => 'superadmin']));
        $this->patchJson(self::URL.'/'.$id, ['status' => 'declined', 'review_note' => 'Overwrite'])->assertConflict();
        $this->assertDatabaseHas('listing_studio_requests', ['id' => $id, 'status' => 'approved', 'reviewed_by_id' => $admin->id]);
    }

    public function test_admin_queue_and_notifications_exclude_other_operational_roles(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-09-27T09:15:00Z'));
        $client = User::factory()->create(['role' => 'client']);
        $admin = User::factory()->create(['role' => 'admin']);
        $editor = User::factory()->create(['role' => 'editing_manager']);
        Sanctum::actingAs($client);
        $id = $this->postJson(self::URL, $this->signup())->assertCreated()->json('data.id');
        $access = app(ListingStudioAccess::class);
        $this->assertCount(1, $access->notifications($admin));
        $this->assertCount(0, $access->notifications($editor));
        $this->assertCount(0, $access->notifications($client));
        Sanctum::actingAs($admin);
        $this->getJson(self::URL.'?status=pending')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/notifications')->assertOk()->assertJsonFragment([
            'action' => 'listing_studio_request', 'actionUrl' => '/dashboard?listingStudio=1&listingStudioTab=requests',
            'timestamp' => '2026-09-27T09:15:00+00:00',
        ]);
        $this->patchJson(self::URL.'/'.$id, ['status' => 'declined', 'review_note' => 'Please request a call.'])->assertOk();
        $this->assertCount(0, $access->notifications($admin));
        $this->assertSame('/dashboard?listingStudio=1&listingStudioTab=requests', $access->notifications($client)->first()['actionUrl']);
        Cache::flush();
        Sanctum::actingAs($client);
        $this->getJson('/api/notifications')->assertOk()->assertJsonFragment([
            'id' => 'listing-studio-'.$id.'-declined', 'timestamp' => '2026-09-27T09:15:00+00:00',
        ]);
    }

    public function test_supported_role_aliases_and_secondary_roles_can_access_catalog(): void
    {
        foreach (['salesRep', 'sales_rep', 'rep', 'super_admin', 'client', 'admin'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->getJson('/api/listing-studio/catalog')->assertOk()->assertJsonCount(4, 'data.plans')->assertJsonCount(6, 'data.services');
        }
        Sanctum::actingAs(User::factory()->create(['role' => 'photographer', 'secondary_roles' => ['admin']]));
        $this->getJson('/api/listing-studio/catalog')->assertOk();
        foreach (['photographer', 'editor', 'editing_manager'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->getJson(self::URL)->assertForbidden();
            $this->getJson('/api/listing-studio/catalog')->assertForbidden();
        }
    }

    public function test_iso_and_legacy_notification_timestamps_sort_in_chronological_order(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-09-27T09:15:00Z'));
        $client = User::factory()->create(['role' => 'client']);
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($client);
        $id = $this->postJson(self::URL, $this->signup())->assertCreated()->json('data.id');

        $this->travelTo(\Carbon\Carbon::parse('2026-09-27T11:15:00Z'));
        $shoot = Shoot::factory()->create(['client_id' => $client->id]);
        $newer = ShootActivityLog::create(['shoot_id' => $shoot->id, 'user_id' => $admin->id,
            'action' => 'shoot_created', 'description' => 'A later legacy notification']);
        $this->travelTo(\Carbon\Carbon::parse('2026-09-27T07:15:00Z'));
        $older = ShootActivityLog::create(['shoot_id' => $shoot->id, 'user_id' => $admin->id,
            'action' => 'shoot_created', 'description' => 'An earlier legacy notification']);
        Cache::flush();
        Sanctum::actingAs($admin);
        $notifications = collect($this->getJson('/api/notifications')->assertOk()->json('data.activity_log'));
        $expectedIds = ['sa-'.$newer->id, 'listing-studio-'.$id.'-pending', 'sa-'.$older->id];
        $this->assertSame($expectedIds, $notifications->whereIn('id', $expectedIds)->pluck('id')->values()->all());
        $this->assertSame('2026-09-27T11:15:00+00:00', $notifications->firstWhere('id', 'sa-'.$newer->id)['timestamp']);
        $this->assertSame('2026-09-27T09:15:00+00:00', $notifications->firstWhere('id', 'listing-studio-'.$id.'-pending')['timestamp']);
    }

    public function test_staff_attribution_survives_soft_deletion_and_client_payload_cannot_approve_itself(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $rep = User::factory()->create(['role' => 'salesRep']);
        $client->update(['created_by_id' => $rep->id]);
        Sanctum::actingAs($rep);
        $id = $this->postJson(self::URL, $this->signup(['client_id' => $client->id, 'status' => 'approved']))
            ->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $rep->delete();
        Sanctum::actingAs($client);
        $this->getJson(self::URL)->assertOk()->assertJsonPath('data.0.submitted_by.name', $rep->name);
        $this->assertSame('pending', ListingStudioRequest::findOrFail($id)->status);
    }

    public function test_unauthenticated_visitors_cannot_use_listing_studio_requests(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        $this->postJson(self::URL, $this->signup())->assertUnauthorized();
    }

    private function signup(array $overrides = []): array
    {
        return array_replace(['type' => 'signup', 'plan_code' => 'plan_100',
            'services' => ['reel_generation', 'virtual_staging'], 'details' => 'Interested in these services.',
            'idempotency_key' => (string) Str::uuid()], $overrides);
    }
}
