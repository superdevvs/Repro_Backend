<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserPermissionOverridesTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_deny_accounting_for_a_single_admin(): void
    {
        $superadmin = User::factory()->superAdmin()->create();
        $restricted = User::factory()->admin()->create();

        Sanctum::actingAs($superadmin);

        $response = $this->putJson("/api/admin/permissions/users/{$restricted->id}", [
            'overrides' => [
                'allow' => [],
                'deny' => ['accounting-view'],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('overrides.deny.0', 'accounting-view');
        $this->assertContains('accounting-view', $response->json('roleBaseline'));
        $this->assertNotContains('accounting-view', $response->json('effective'));

        $this->assertDatabaseHas('users', [
            'id' => $restricted->id,
        ]);
        $this->assertSame(
            ['allow' => [], 'deny' => ['accounting-view']],
            $restricted->fresh()->permission_overrides,
        );
    }

    public function test_denied_admin_loses_accounting_in_me_permissions_and_at_the_api(): void
    {
        $restricted = User::factory()->admin()->create([
            'permission_overrides' => ['allow' => [], 'deny' => ['accounting-view']],
        ]);
        $regular = User::factory()->admin()->create();

        Sanctum::actingAs($restricted);

        $me = $this->getJson('/api/me/permissions')->assertOk();
        $this->assertNotContains('accounting-view', $me->json('permissionIds'));
        $this->assertContains('dashboard-view', $me->json('permissionIds'));

        $this->getJson('/api/admin/accounting-expenses')
            ->assertForbidden()
            ->assertJsonPath('resource', 'accounting');
        $this->getJson('/api/admin/invoices')->assertForbidden();
        $this->getJson('/api/reports/invoices/summary')->assertForbidden();

        Sanctum::actingAs($regular);

        $this->getJson('/api/admin/accounting-expenses')->assertOk();
        $this->assertContains('accounting-view', $this->getJson('/api/me/permissions')->json('permissionIds'));
    }

    public function test_allow_override_grants_a_permission_outside_the_role(): void
    {
        $photographer = User::factory()->photographer()->create([
            'permission_overrides' => ['allow' => ['accounts-view'], 'deny' => []],
        ]);

        Sanctum::actingAs($photographer);

        $response = $this->getJson('/api/me/permissions')->assertOk();
        $this->assertContains('accounts-view', $response->json('permissionIds'));
    }

    public function test_deny_wins_when_stored_overrides_overlap(): void
    {
        $user = User::factory()->admin()->create([
            'permission_overrides' => ['allow' => ['accounting-view'], 'deny' => ['accounting-view']],
        ]);

        Sanctum::actingAs($user);

        $this->assertNotContains('accounting-view', $this->getJson('/api/me/permissions')->json('permissionIds'));
    }

    public function test_superadmin_overrides_are_rejected_and_ignored(): void
    {
        $actor = User::factory()->admin()->create();
        $superadmin = User::factory()->superAdmin()->create([
            'permission_overrides' => ['allow' => [], 'deny' => ['accounting-view']],
        ]);

        Sanctum::actingAs($actor);

        $this->putJson("/api/admin/permissions/users/{$superadmin->id}", [
            'overrides' => ['allow' => [], 'deny' => ['dashboard-view']],
        ])->assertStatus(422);

        $show = $this->getJson("/api/admin/permissions/users/{$superadmin->id}")->assertOk();
        $this->assertTrue($show->json('user.locked'));
        $this->assertContains('accounting-view', $show->json('effective'));
    }

    public function test_invalid_override_payloads_are_rejected(): void
    {
        $actor = User::factory()->admin()->create();
        $target = User::factory()->admin()->create();

        Sanctum::actingAs($actor);

        $this->putJson("/api/admin/permissions/users/{$target->id}", [
            'overrides' => ['allow' => ['not-real-view'], 'deny' => []],
        ])->assertStatus(422);

        $this->putJson("/api/admin/permissions/users/{$target->id}", [
            'overrides' => ['allow' => ['accounting-view'], 'deny' => ['accounting-view']],
        ])->assertStatus(422);

        $this->putJson("/api/admin/permissions/users/{$target->id}", [
            'overrides' => 'nope',
        ])->assertStatus(422);
    }

    public function test_clearing_overrides_stores_null(): void
    {
        $actor = User::factory()->admin()->create();
        $target = User::factory()->admin()->create([
            'permission_overrides' => ['allow' => [], 'deny' => ['accounting-view']],
        ]);

        Sanctum::actingAs($actor);

        $this->putJson("/api/admin/permissions/users/{$target->id}", [
            'overrides' => ['allow' => [], 'deny' => []],
        ])->assertOk();

        $this->assertNull($target->fresh()->permission_overrides);
    }

    public function test_non_admins_cannot_manage_user_overrides(): void
    {
        $editingManager = User::factory()->create(['role' => 'editing_manager']);
        $target = User::factory()->admin()->create();

        Sanctum::actingAs($editingManager);

        $this->getJson('/api/admin/permissions/users')->assertForbidden();
        $this->getJson("/api/admin/permissions/users/{$target->id}")->assertForbidden();
        $this->putJson("/api/admin/permissions/users/{$target->id}", [
            'overrides' => ['allow' => [], 'deny' => ['accounting-view']],
        ])->assertForbidden();
    }

    public function test_users_overview_reports_override_counts(): void
    {
        $actor = User::factory()->admin()->create();
        $target = User::factory()->admin()->create([
            'name' => 'Restricted Admin',
            'permission_overrides' => ['allow' => ['watermark-settings-view'], 'deny' => ['accounting-view']],
        ]);

        Sanctum::actingAs($actor);

        $response = $this->getJson('/api/admin/permissions/users')->assertOk();
        $row = collect($response->json('users'))->firstWhere('id', $target->id);

        $this->assertNotNull($row);
        $this->assertSame(2, $row['overrideCount']);
        $this->assertSame('admin', $row['role']);
        $this->assertFalse($row['locked']);
    }
}
