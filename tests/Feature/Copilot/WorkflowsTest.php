<?php

namespace Tests\Feature\Copilot;

use App\Models\Payment;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\StudioWorkspace;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

final class WorkflowsTest extends CopilotTestCase
{
    public function test_editor_can_read_assigned_notes_but_not_company_or_other_shoots(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $connection = $this->connection($editor);
        $own = Shoot::factory()->create(['editor_id' => $editor->id, 'editor_notes' => 'Straighten verticals', 'company_notes' => 'Private company']);
        $peer = Shoot::factory()->create();
        $this->tool($connection['access_token'], 'fetch', ['id' => 'shoot:'.$own->id])->assertJsonMissingPath('result.isError')
            ->assertJsonPath('result.structuredContent.shoot.notes.editor_notes', 'Straighten verticals')->assertDontSee('Private company');
        $this->tool($connection['access_token'], 'fetch', ['id' => 'shoot:'.$peer->id])->assertJsonPath('result.structuredContent.error.status', 404);
    }

    public function test_all_management_roles_use_effective_permissions(): void
    {
        $shoot = Shoot::factory()->create();
        foreach (['admin', 'superadmin', 'editing_manager', 'salesRep'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $connection = $this->connection($user);
            $this->tool($connection['access_token'], 'fetch', ['id' => 'shoot:'.$shoot->id])->assertJsonMissingPath('result.isError');
            $this->tool($connection['access_token'], 'client_insights', ['from' => '2026-10-01', 'to' => '2026-10-08'])->assertJsonMissingPath('result.isError');
            $user->forceFill(['permission_overrides' => ['deny' => ['shoots-view'], 'allow' => []]])->save();
            if ($role === 'superadmin') {
                $this->tool($connection['access_token'], 'fetch', ['id' => 'shoot:'.$shoot->id])->assertJsonMissingPath('result.isError');
            } else {
                $this->tool($connection['access_token'], 'fetch', ['id' => 'shoot:'.$shoot->id])->assertJsonPath('result.structuredContent.error.status', 403);
            }
        }
    }

    public function test_finance_report_includes_all_payout_categories_and_separates_currencies(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $connection = $this->connection($admin, 'repro.read repro.finance');
        Payment::factory()->create(['amount' => 120, 'currency' => 'USD', 'processed_at' => '2026-10-05 12:00:00']);
        Payment::factory()->create(['amount' => 50, 'currency' => 'CAD', 'processed_at' => '2026-10-05 12:00:00']);
        Payment::factory()->create(['amount' => 999, 'currency' => 'USD', 'status' => 'pending', 'processed_at' => '2026-10-05 12:00:00']);
        $result = $this->tool($connection['access_token'], 'finance_report', ['from' => '2026-10-01', 'to' => '2026-10-08'])
            ->assertJsonMissingPath('result.isError')->assertJsonPath('result.structuredContent.collections_by_currency.USD.net_recorded', 120)
            ->assertJsonPath('result.structuredContent.collections_by_currency.CAD.net_recorded', 50);
        $this->assertSame(['photographers', 'sales_reps', 'editors'], array_keys($result->json('result.structuredContent.earned_payouts')));
        $this->assertNotEmpty($result->json('result.structuredContent.limitations'));
    }

    public function test_studio_status_uses_existing_access_and_does_not_submit_jobs(): void
    {
        $admin = User::factory()->create(['role' => 'superadmin', 'metadata' => ['team_id' => 1]]);
        $connection = $this->connection($admin);
        $workspace = StudioWorkspace::create(['team_id' => 1, 'created_by' => $admin->id, 'name' => 'Oak Lane', 'preset_id' => 'listing-ready',
            'media' => [], 'config' => [], 'outputs' => [], 'status' => 'draft', 'version' => 1]);
        $this->tool($connection['access_token'], 'studio_status', ['workspace_id' => $workspace->id])
            ->assertJsonMissingPath('result.isError')->assertJsonPath('result.structuredContent.workspace.status', 'draft')->assertJsonMissingPath('result.structuredContent.workspace.media');
        $client = $this->connection(User::factory()->create(['role' => 'client']));
        $this->tool($client['access_token'], 'studio_status', ['workspace_id' => $workspace->id])->assertJsonPath('result.isError', true);
        Queue::assertNothingPushed();
    }

    public function test_listing_context_and_help_do_not_fabricate_property_facts(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $connection = $this->connection($user);
        $shoot = Shoot::factory()->create(['client_id' => $user->id, 'property_details' => ['bedrooms' => 3, 'internal_password' => 'HIDDEN']]);
        $this->tool($connection['access_token'], 'listing_pack', ['shoot_id' => $shoot->id])
            ->assertJsonMissingPath('result.isError')->assertJsonPath('result.structuredContent.property_facts.bedrooms', 3)->assertDontSee('HIDDEN');
        $this->tool($connection['access_token'], 'support_guide', ['query' => 'booking'])->assertJsonMissingPath('result.isError')->assertJsonStructure(['result' => ['structuredContent' => ['knowledge_version', 'articles']]]);
    }

    public function test_availability_routes_to_canonical_lookup_without_reservation(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $connection = $this->connection($user);
        $service = Service::factory()->create();
        $this->tool($connection['access_token'], 'find_availability', ['date' => now()->addWeek()->toDateString(), 'time' => '10:00', 'duration_minutes' => 60,
            'shoot_address' => '24 Oak Lane', 'shoot_city' => 'Baltimore', 'shoot_state' => 'MD', 'shoot_zip' => '21201', 'service_ids' => [$service->id]])
            ->assertJsonMissingPath('result.isError')->assertJsonPath('result.structuredContent.reservation', false);
        $this->assertDatabaseCount('shoots', 0);
    }

    public function test_staff_can_resolve_client_names_but_clients_cannot_enumerate_accounts(): void
    {
        $client = User::factory()->create(['role' => 'client', 'name' => 'Oak Realty', 'email' => 'oak@example.test']);
        User::factory()->create(['role' => 'client', 'name' => 'Oak Suspended', 'account_status' => 'suspended']);
        $staff = $this->connection(User::factory()->create(['role' => 'admin']));
        $this->tool($staff['access_token'], 'get_services', ['client_query' => 'Oak'])
            ->assertJsonMissingPath('result.isError')->assertJsonCount(1, 'result.structuredContent.clients')
            ->assertJsonPath('result.structuredContent.clients.0.id', $client->id);
        $own = $this->connection($client);
        $this->tool($own['access_token'], 'get_services', ['client_query' => 'Oak'])
            ->assertJsonPath('result.structuredContent.error.status', 403)->assertDontSee('oak@example.test');
    }

    public function test_booking_permission_override_is_enforced_before_preparation(): void
    {
        $user = User::factory()->create(['role' => 'client', 'permission_overrides' => ['deny' => ['book-shoot-create'], 'allow' => []]]);
        $connection = $this->connection($user);
        $service = Service::factory()->create();
        $this->tool($connection['access_token'], 'prepare_booking', ['address' => '24 Oak Lane', 'city' => 'Baltimore', 'state' => 'MD', 'zip' => '21201',
            'services' => [['id' => $service->id]], 'scheduled_at' => now()->addWeek()->toIso8601String(), 'timezone' => 'UTC', 'notify_client' => false, 'notify_photographer' => false])
            ->assertJsonPath('result.structuredContent.error.status', 403);
        $this->assertDatabaseCount('copilot_drafts', 0);
    }

    public function test_changed_preview_cannot_be_confirmed_from_get_draft(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $connection = $this->connection($user);
        $shoot = Shoot::factory()->create(['client_id' => $user->id]);
        $draft = $this->tool($connection['access_token'], 'prepare_note', ['shoot_id' => $shoot->id, 'field' => 'shoot_notes', 'text' => 'Next'])->json('result.structuredContent');
        $shoot->update(['shoot_notes' => 'Edited elsewhere']);
        $this->tool($connection['access_token'], 'get_draft', ['draft_id' => $draft['draft_id']])->assertJsonPath('result.structuredContent.review_changed', true);
        $this->assertSame('prepared', DB::table('copilot_drafts')->find($draft['draft_id'])->status);
    }
}
