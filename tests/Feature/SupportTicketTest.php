<?php

namespace Tests\Feature;

use App\Models\SupportTicket;
use App\Models\User;
use App\Services\SupportTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupportTicketTest extends TestCase
{
    use RefreshDatabase;

    private function report(array $overrides = []): array
    {
        return [...['request_key' => (string) Str::uuid(), 'subject' => 'Download does not start', 'body' => 'I chose full resolution but my browser showed an error.', 'category' => 'delivery', 'page_path' => '/shoot-history'], ...$overrides];
    }

    public function test_create_is_authenticated_private_and_idempotent(): void
    {
        $data = $this->report();
        $this->postJson('/api/support/tickets', $data)->assertUnauthorized();
        $owner = User::factory()->create(['role' => 'client']);
        $this->actingAs($owner, 'sanctum');
        $id = $this->postJson('/api/support/tickets', $data)->assertCreated()->assertJsonPath('data.reference', 'SUP-000001')->json('data.id');
        $this->postJson('/api/support/tickets', $data)->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('support_tickets', 1);
        $this->assertDatabaseCount('support_ticket_messages', 1);
        $this->postJson('/api/support/tickets', [...$data, 'body' => 'A different request with the same key'])->assertConflict();
        $other = User::factory()->create(['role' => 'photographer']);
        $this->actingAs($other, 'sanctum')->getJson('/api/support/tickets/'.$id)->assertNotFound();
        $this->getJson('/api/support/tickets?query=Download')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/support/tickets/'.$id.'/replies', ['request_key' => (string) Str::uuid(), 'body' => 'Forged reply'])->assertNotFound();
    }

    public function test_admin_can_triage_and_reply_but_custom_permission_denial_is_authoritative(): void
    {
        $owner = User::factory()->create(['role' => 'salesRep']);
        $ticket = app(SupportTicketService::class)->create($owner, $this->report());
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'sanctum')->patchJson('/api/support/tickets/'.$ticket->id, [
            'version' => 1, 'status' => 'in_progress', 'assigned_to' => $admin->id, 'priority' => 'urgent',
        ])->assertOk()->assertJsonPath('data.assignee.id', $admin->id)->assertJsonPath('data.version', 2);
        $this->patchJson('/api/support/tickets/'.$ticket->id, ['version' => 1, 'status' => 'resolved'])->assertConflict();
        $this->getJson('/api/support/tickets/assignees')->assertOk()->assertJsonCount(1, 'data');
        $admin->update(['permission_overrides' => ['deny' => ['support-manage'], 'allow' => []]]);
        $this->actingAs($admin->fresh(), 'sanctum')->getJson('/api/support/tickets/'.$ticket->id)->assertNotFound();
        $this->getJson('/api/support/tickets/assignees')->assertForbidden();
    }

    public function test_internal_notes_stay_private_and_replies_reopen_resolved_requests_once(): void
    {
        $owner = User::factory()->create(['role' => 'photographer']);
        $admin = User::factory()->create(['role' => 'admin']);
        $service = app(SupportTicketService::class);
        $ticket = $service->create($owner, $this->report());
        $this->actingAs($admin, 'sanctum')->postJson('/api/support/tickets/'.$ticket->id.'/replies', [
            'request_key' => (string) Str::uuid(), 'body' => 'Private staff investigation', 'internal' => true,
        ])->assertOk();
        $this->actingAs($owner, 'sanctum')->getJson('/api/support/tickets/'.$ticket->id)->assertOk()
            ->assertJsonCount(1, 'messages')->assertDontSee('Private staff investigation');
        $this->assertCount(0, $service->notifications($owner));
        $this->postJson('/api/support/tickets/'.$ticket->id.'/replies', ['request_key' => (string) Str::uuid(), 'body' => 'Hide this', 'internal' => true])->assertForbidden();
        $this->patchJson('/api/support/tickets/'.$ticket->id, ['version' => 2, 'status' => 'resolved'])->assertOk();
        $reply = ['request_key' => (string) Str::uuid(), 'body' => 'The problem still happens.'];
        $this->postJson('/api/support/tickets/'.$ticket->id.'/replies', $reply)->assertOk()->assertJsonPath('data.status', 'open')->assertJsonPath('data.version', 4);
        $this->postJson('/api/support/tickets/'.$ticket->id.'/replies', $reply)->assertOk()->assertJsonPath('data.version', 4);
        $this->postJson('/api/support/tickets/'.$ticket->id.'/replies', [...$reply, 'body' => 'A changed message'])->assertConflict();
        $this->assertCount(3, $service->notifications($admin));
    }

    public function test_restricted_users_cannot_manage_other_cases_or_assign_staff(): void
    {
        $user = User::factory()->create(['role' => 'client', 'permission_overrides' => ['allow' => ['support-manage'], 'deny' => []]]);
        $ticket = app(SupportTicketService::class)->create($user, $this->report());
        $this->actingAs($user, 'sanctum');
        $this->patchJson('/api/support/tickets/'.$ticket->id, ['version' => 1, 'assigned_to' => $user->id])->assertForbidden();
        $this->patchJson('/api/support/tickets/'.$ticket->id, ['version' => 1, 'status' => 'in_progress'])->assertForbidden();
        $user->update(['permission_overrides' => ['deny' => ['support-view'], 'allow' => []]]);
        $this->actingAs($user->fresh(), 'sanctum')->getJson('/api/support/tickets')->assertForbidden();
        $this->assertCount(0, app(SupportTicketService::class)->notifications($user->fresh()));
    }

    public function test_lists_and_history_are_bounded_and_filter_before_pagination(): void
    {
        $user = User::factory()->create(['role' => 'editor']);
        $service = app(SupportTicketService::class);
        for ($i = 0; $i < 4; $i++) {
            $service->create($user, $this->report(['subject' => 'Upload issue '.$i]));
        }
        $ticket = SupportTicket::first();
        for ($i = 0; $i < 31; $i++) {
            $service->reply($user, $ticket->id, ['request_key' => (string) Str::uuid(), 'body' => 'Update '.$i]);
        }
        $this->actingAs($user, 'sanctum')->getJson('/api/support/tickets?per_page=2&page=2&query=Upload')->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('meta.pagination.total', 4);
        $this->getJson('/api/support/tickets/'.$ticket->id)->assertOk()->assertJsonCount(30, 'messages')->assertJsonPath('meta.total', 32);
        $this->getJson('/api/support/tickets/'.$ticket->id.'?page=2')->assertOk()->assertJsonCount(2, 'messages');
        $this->getJson('/api/support/tickets?per_page=999')->assertUnprocessable();
        $this->postJson('/api/support/tickets', $this->report(['page_path' => '//evil.example/x?token=secret']))->assertUnprocessable();
    }

    public function test_support_alerts_are_visible_only_to_current_authorized_recipients(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $admin = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create(['role' => 'client']);
        $service = app(SupportTicketService::class);
        $ticket = $service->create($user, $this->report());
        $service->reply($admin, $ticket->id, ['request_key' => (string) Str::uuid(), 'body' => 'Please try Download Center again.']);
        $this->assertCount(1, $service->notifications($user));
        $this->assertCount(1, $service->notifications($admin));
        $this->assertCount(0, $service->notifications($other));
        $this->actingAs($user, 'sanctum')->getJson('/api/notifications')->assertOk()
            ->assertJsonFragment(['actionUrl' => '/messaging/email/inbox?tab=support&ticket='.$ticket->id]);
    }

    public function test_search_treats_wildcards_literally_and_inactive_administrators_cannot_be_assigned(): void
    {
        $owner = User::factory()->create(['role' => 'client']);
        $admin = User::factory()->create(['role' => 'admin']);
        $locked = User::factory()->create(['role' => 'admin', 'locked_at' => now()]);
        $inactive = User::factory()->create(['role' => 'admin', 'account_status' => 'inactive']);
        $service = app(SupportTicketService::class);
        $ticket = $service->create($owner, $this->report(['subject' => 'Upload stuck at 50%_done']));
        $service->create($owner, $this->report(['subject' => 'Upload stuck at 50 percent']));
        $this->actingAs($admin, 'sanctum')->getJson('/api/support/tickets?query='.urlencode('50%_'))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ticket->id);
        $this->getJson('/api/support/tickets?query=SUP-'.str_pad((string) $ticket->id, 6, '0', STR_PAD_LEFT))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ticket->id);
        $this->getJson('/api/support/tickets/assignees')->assertOk()->assertJsonCount(1, 'data');
        foreach ([$locked, $inactive] as $unavailable) {
            $this->patchJson('/api/support/tickets/'.$ticket->id, ['version' => 1, 'assigned_to' => $unavailable->id])->assertUnprocessable();
        }
    }

    public function test_upgrade_preserves_restricted_admin_and_empty_role_permissions(): void
    {
        $migration = require database_path('migrations/2026_10_01_100000_create_support_tickets.php');
        $migration->down();
        \App\Models\Setting::updateOrCreate(['key' => 'permissions.role_map.v1'], ['type' => 'json', 'value' => json_encode([
            'version' => 1, 'roles' => ['admin' => ['dashboard-view'], 'client' => [], 'photographer' => ['shoots-view']],
        ])]);
        $migration->up();
        $roles = json_decode(\App\Models\Setting::where('key', 'permissions.role_map.v1')->value('value'), true)['roles'];
        $this->assertSame(['dashboard-view', 'support-view'], $roles['admin']);
        $this->assertSame([], $roles['client']);
        $this->assertSame(['shoots-view', 'support-view'], $roles['photographer']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->assertFalse(app(SupportTicketService::class)->canManage($admin));
    }

    public function test_upgrade_adds_triage_to_recognized_admin_defaults_but_keeps_user_denials(): void
    {
        $legacy = collect(config('permissions.groups'))->flatMap(fn ($group) => $group['items'])
            // Reconstruct the frozen pre-support default, excluding later permission additions.
            ->filter(fn ($item) => ! in_array($item['resource'], ['support', 'email-notifications', 'shoot-pricing'], true)
                && ! ($item['resource'] === 'shoots' && in_array($item['action'], ['update', 'manage'], true))
                && ! ($item['resource'] === 'payments' && $item['action'] === 'mark-paid')
                && in_array('admin', $item['default_roles'] ?? [], true))
            ->map(fn ($item) => $item['resource'].'-'.$item['action'])->values()->all();
        $migration = require database_path('migrations/2026_10_01_100000_create_support_tickets.php');
        $migration->down();
        \App\Models\Setting::updateOrCreate(['key' => 'permissions.role_map.v1'], ['type' => 'json', 'value' => json_encode(['admin' => $legacy])]);
        $migration->up();
        $roles = json_decode(\App\Models\Setting::where('key', 'permissions.role_map.v1')->value('value'), true);
        $this->assertContains('support-manage', $roles['admin']);
        $admin = User::factory()->create(['role' => 'admin', 'permission_overrides' => ['deny' => ['support-manage'], 'allow' => []]]);
        $this->assertFalse(app(SupportTicketService::class)->canManage($admin));
    }
}
