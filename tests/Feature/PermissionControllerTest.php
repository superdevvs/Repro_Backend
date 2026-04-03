<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PermissionControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_fetch_permissions_catalog_and_all_roles(): void
    {
        $admin = User::factory()->admin()->create();

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/permissions');

        $response->assertOk();
        $response->assertJsonPath('roles.0.id', 'superadmin');
        $response->assertJsonFragment(['id' => 'salesRep', 'label' => 'Sales Rep']);
        $response->assertJsonFragment(['id' => 'editing_manager', 'label' => 'Editing Manager']);
        $response->assertJsonFragment(['resource' => 'messaging-overview', 'action' => 'view']);
        $response->assertJsonFragment(['resource' => 'ai-editing', 'action' => 'view']);
        $response->assertJsonFragment(['resource' => 'robbie', 'action' => 'view']);
        $this->assertDatabaseHas('settings', ['key' => 'permissions.role_map.v1']);
    }

    public function test_non_admin_cannot_manage_permissions(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);

        Sanctum::actingAs($editor);

        $this->getJson('/api/admin/permissions')->assertForbidden();
        $this->putJson('/api/admin/permissions', [
            'permissions' => [
                'editor' => ['dashboard-view'],
            ],
        ])->assertForbidden();
    }

    public function test_admin_can_save_permissions_and_superadmin_remains_fully_enabled(): void
    {
        $admin = User::factory()->admin()->create();

        Sanctum::actingAs($admin);

        $initial = $this->getJson('/api/admin/permissions')->assertOk()->json();
        $catalogIds = collect($initial['catalog'])
            ->flatMap(fn (array $group) => $group['permissions'])
            ->pluck('id')
            ->values()
            ->all();

        $response = $this->putJson('/api/admin/permissions', [
            'permissions' => [
                'admin' => ['dashboard-view', 'reports-view'],
                'editing_manager' => ['dashboard-view', 'dashboard-admin-view'],
                'salesRep' => ['dashboard-view', 'dashboard-sales-view', 'messaging-overview-view'],
                'photographer' => ['dashboard-view', 'shoots-view', 'availability-view'],
                'editor' => ['dashboard-view', 'dashboard-editor-view', 'accounting-view'],
                'client' => ['dashboard-view', 'dashboard-client-view', 'accounting-view'],
                'superadmin' => [],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('permissions.admin.0', 'dashboard-view');
        $response->assertJsonPath('permissions.salesRep.2', 'messaging-overview-view');
        $response->assertJsonPath('permissions.superadmin', $catalogIds);
    }

    public function test_unknown_permissions_are_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        Sanctum::actingAs($admin);

        $this->putJson('/api/admin/permissions', [
            'permissions' => [
                'admin' => ['dashboard-view', 'not-real-view'],
            ],
        ])->assertStatus(422);
    }

    public function test_me_permissions_returns_effective_union_for_secondary_roles(): void
    {
        Setting::query()->updateOrCreate(
            ['key' => 'permissions.role_map.v1'],
            [
                'value' => json_encode([
                    'version' => 1,
                    'roles' => [
                        'superadmin' => ['dashboard-view'],
                        'admin' => ['dashboard-view', 'accounts-view'],
                        'editing_manager' => ['dashboard-view', 'dashboard-admin-view'],
                        'salesRep' => ['dashboard-view', 'dashboard-sales-view', 'messaging-overview-view'],
                        'photographer' => ['dashboard-view', 'availability-view'],
                        'editor' => ['dashboard-view', 'dashboard-editor-view'],
                        'client' => ['dashboard-view', 'dashboard-client-view'],
                    ],
                ]),
                'type' => 'json',
            ],
        );

        $user = User::factory()->create([
            'role' => 'admin',
            'secondary_roles' => ['salesRep'],
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/me/permissions');

        $response->assertOk();
        $response->assertJsonPath('role', 'admin');
        $response->assertJsonPath('secondaryRoles.0', 'salesRep');
        $response->assertJsonFragment(['id' => 'accounts-view', 'resource' => 'accounts', 'action' => 'view']);
        $response->assertJsonFragment(['id' => 'dashboard-sales-view', 'resource' => 'dashboard-sales', 'action' => 'view']);
        $response->assertJsonFragment(['id' => 'messaging-overview-view', 'resource' => 'messaging-overview', 'action' => 'view']);
    }

    public function test_sales_rep_permissions_self_heal_robbie_access_for_legacy_maps(): void
    {
        Setting::query()->updateOrCreate(
            ['key' => 'permissions.role_map.v1'],
            [
                'value' => json_encode([
                    'version' => 1,
                    'roles' => [
                        'superadmin' => ['dashboard-view'],
                        'admin' => ['dashboard-view', 'accounts-view'],
                        'editing_manager' => ['dashboard-view', 'dashboard-admin-view'],
                        'salesRep' => ['dashboard-view', 'dashboard-sales-view'],
                        'photographer' => ['dashboard-view', 'availability-view'],
                        'editor' => ['dashboard-view', 'dashboard-editor-view'],
                        'client' => ['dashboard-view', 'dashboard-client-view'],
                    ],
                ]),
                'type' => 'json',
            ],
        );

        $salesRep = User::factory()->create([
            'role' => 'salesRep',
        ]);

        Sanctum::actingAs($salesRep);

        $response = $this->getJson('/api/me/permissions');

        $response->assertOk();
        $response->assertJsonFragment([
            'id' => 'robbie-view',
            'resource' => 'robbie',
            'action' => 'view',
        ]);
    }
}
