<?php

namespace Tests\Feature\Crm;

use App\Models\Setting;
use App\Services\RolePermissionService;

class CrmPermissionGuardsTest extends CrmTestCase
{
    public function test_crm_defaults_are_seeded_once_into_an_existing_role_map(): void
    {
        Setting::query()->updateOrCreate(['key' => 'permissions.role_map.v1'], ['value' => json_encode(['version' => 1, 'roles' => [
            'superadmin' => [], 'admin' => ['dashboard-view'], 'editing_manager' => [], 'salesRep' => ['dashboard-view'], 'photographer' => [], 'editor' => [], 'client' => [],
        ]]), 'type' => 'json']);

        $map = app(RolePermissionService::class)->storedRoleMap();
        $this->assertContains('crm-contacts-view', $map['salesRep']);
        $this->assertContains('crm-contacts-assign', $map['admin']);
        $this->assertNotContains('crm-contacts-assign', $map['salesRep']);
        $this->assertNotContains('crm-recruiting-view', $map['admin']);

        // An admin later removes CRM from sales reps; it is not re-added.
        $map['salesRep'] = array_values(array_diff($map['salesRep'], ['crm-contacts-view']));
        app(RolePermissionService::class)->updatePermissions($map);
        $this->assertNotContains('crm-contacts-view', app(RolePermissionService::class)->storedRoleMap()['salesRep']);
    }

    public function test_signature_approval_can_never_be_granted(): void
    {
        $super = $this->actAs($this->superAdmin());
        $admin = $this->admin();

        $this->putJson("/api/admin/permissions/users/{$admin->id}", ['overrides' => ['allow' => ['crm-signatures-approve'], 'deny' => []]])->assertUnprocessable();

        $map = app(RolePermissionService::class)->storedRoleMap();
        $map['admin'][] = 'crm-signatures-approve';
        $this->putJson('/api/admin/permissions', ['permissions' => $map])->assertUnprocessable();
        $this->assertFalse(app(RolePermissionService::class)->userCan($admin, 'crm-signatures', 'approve'));
        $this->assertTrue(app(RolePermissionService::class)->userCan($super, 'crm-signatures', 'approve'));
    }

    public function test_only_super_admin_grants_recruiting_access(): void
    {
        $admin = $this->admin();
        $otherAdmin = $this->admin();

        $this->actAs($admin);
        $this->putJson("/api/admin/permissions/users/{$admin->id}", ['overrides' => ['allow' => ['crm-recruiting-view'], 'deny' => []]])->assertUnprocessable();
        $this->assertFalse(app(RolePermissionService::class)->userCan($admin->fresh(), 'crm-recruiting', 'view'));

        $this->actAs($this->superAdmin());
        $this->putJson("/api/admin/permissions/users/{$otherAdmin->id}", ['overrides' => ['allow' => ['crm-recruiting-view'], 'deny' => []]])->assertOk();
        $this->assertTrue(app(RolePermissionService::class)->userCan($otherAdmin->fresh(), 'crm-recruiting', 'view'));
    }
}
