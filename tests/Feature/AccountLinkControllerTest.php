<?php

namespace Tests\Feature;

use App\Models\AccountLink;
use App\Models\Setting;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountLinkControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_account_link(): void
    {
        $admin = User::factory()->admin()->create();
        $client = User::factory()->create();

        $this->grantAdminPermissions(['account-linking-view', 'account-linking-update']);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/account-links', [
            'mainAccountId' => $admin->id,
            'clientAccountId' => $client->id,
            'sharedDetails' => [
                'shoots' => true,
                'profile' => true,
            ],
            'notes' => 'Primary relationship',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('result', 'created');
        $response->assertJsonPath('link.mainAccountId', (string) $admin->id);
        $response->assertJsonPath('link.accountId', (string) $client->id);
        $response->assertJsonPath('link.sharedDetails.shoots', true);
        $response->assertJsonPath('link.sharedDetails.documents', false);

        $this->assertDatabaseHas('account_links', [
            'main_account_id' => $admin->id,
            'linked_account_id' => $client->id,
            'status' => 'active',
        ]);
    }

    public function test_inactive_link_is_reactivated_instead_of_creating_duplicate(): void
    {
        $admin = User::factory()->admin()->create();
        $client = User::factory()->create();

        $this->grantAdminPermissions(['account-linking-view', 'account-linking-update']);

        $link = AccountLink::create([
            'main_account_id' => $admin->id,
            'linked_account_id' => $client->id,
            'shared_details' => ['shoots' => false, 'documents' => false],
            'status' => 'inactive',
            'linked_at' => now()->subDay(),
            'unlinked_at' => now()->subHour(),
            'created_by' => $admin->id,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/account-links', [
            'mainAccountId' => $admin->id,
            'clientAccountId' => $client->id,
            'sharedDetails' => [
                'shoots' => true,
                'documents' => true,
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('result', 'reactivated');
        $response->assertJsonPath('link.id', (string) $link->id);
        $response->assertJsonPath('link.sharedDetails.documents', true);

        $this->assertDatabaseCount('account_links', 1);
        $this->assertDatabaseHas('account_links', [
            'id' => $link->id,
            'status' => 'active',
        ]);
    }

    public function test_invalid_role_combinations_and_self_linking_are_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $photographer = User::factory()->photographer()->create();
        $client = User::factory()->create();

        $this->grantAdminPermissions(['account-linking-view', 'account-linking-update']);
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/account-links', [
            'mainAccountId' => $admin->id,
            'clientAccountId' => $photographer->id,
            'sharedDetails' => ['shoots' => true],
        ])->assertStatus(422);

        $this->postJson('/api/admin/account-links', [
            'mainAccountId' => $client->id,
            'clientAccountId' => $client->id,
            'sharedDetails' => ['shoots' => true],
        ])->assertStatus(422);
    }

    public function test_batch_linking_skips_active_duplicates(): void
    {
        $admin = User::factory()->admin()->create();
        $linkedClient = User::factory()->create();
        $newClient = User::factory()->create();

        $this->grantAdminPermissions(['account-linking-view', 'account-linking-update']);

        AccountLink::create([
            'main_account_id' => $admin->id,
            'linked_account_id' => $linkedClient->id,
            'shared_details' => ['shoots' => true],
            'status' => 'active',
            'linked_at' => now(),
            'created_by' => $admin->id,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/account-links/batch', [
            'mainAccountId' => $admin->id,
            'clientAccountIds' => [$linkedClient->id, $newClient->id],
            'sharedDetails' => ['shoots' => true, 'documents' => true],
        ]);

        $response->assertOk();
        $response->assertJsonPath('summary.created', 1);
        $response->assertJsonPath('summary.skipped', 1);
        $response->assertJsonPath('summary.reactivated', 0);
        $response->assertJsonCount(1, 'created');
        $response->assertJsonCount(1, 'skipped');
    }

    public function test_admin_can_update_documents_permission_and_unlink(): void
    {
        $admin = User::factory()->admin()->create();
        $client = User::factory()->create();

        $this->grantAdminPermissions(['account-linking-view', 'account-linking-update']);

        $link = AccountLink::create([
            'main_account_id' => $admin->id,
            'linked_account_id' => $client->id,
            'shared_details' => ['shoots' => true, 'documents' => false],
            'status' => 'active',
            'linked_at' => now(),
            'created_by' => $admin->id,
        ]);

        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/account-links/{$link->id}", [
            'sharedDetails' => [
                'shoots' => true,
                'documents' => true,
            ],
            'status' => 'active',
            'notes' => 'Updated notes',
        ])
            ->assertOk()
            ->assertJsonPath('link.sharedDetails.documents', true)
            ->assertJsonPath('link.notes', 'Updated notes');

        $this->deleteJson("/api/admin/account-links/{$link->id}")
            ->assertOk()
            ->assertJsonPath('link.status', 'inactive');

        $this->assertDatabaseHas('account_links', [
            'id' => $link->id,
            'status' => 'inactive',
        ]);
    }

    public function test_available_accounts_excludes_active_linked_clients_but_keeps_relinkable_inactive_ones(): void
    {
        $admin = User::factory()->admin()->create();
        $activeClient = User::factory()->create(['name' => 'Active Client']);
        $inactiveClient = User::factory()->create(['name' => 'Inactive Client']);
        $freeClient = User::factory()->create(['name' => 'Free Client']);
        $nonClientOwner = User::factory()->create(['role' => 'editor']);

        $this->grantAdminPermissions(['account-linking-view', 'account-linking-update']);

        AccountLink::create([
            'main_account_id' => $admin->id,
            'linked_account_id' => $activeClient->id,
            'shared_details' => ['shoots' => true],
            'status' => 'active',
            'linked_at' => now(),
            'created_by' => $admin->id,
        ]);

        AccountLink::create([
            'main_account_id' => $admin->id,
            'linked_account_id' => $inactiveClient->id,
            'shared_details' => ['shoots' => true],
            'status' => 'inactive',
            'linked_at' => now()->subDay(),
            'created_by' => $admin->id,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/admin/account-links/available-accounts?ownerId={$admin->id}");

        $response->assertOk();
        $response->assertJsonFragment(['name' => 'Inactive Client']);
        $response->assertJsonFragment(['name' => 'Free Client']);
        $this->assertNotContains(
            'Active Client',
            collect($response->json('clientAccounts'))->pluck('name')->all(),
        );
        $this->assertNotContains(
            (string) $nonClientOwner->id,
            collect($response->json('owners'))->pluck('id')->all(),
        );
    }

    public function test_admin_without_account_link_permissions_is_forbidden(): void
    {
        $admin = User::factory()->admin()->create();
        $client = User::factory()->create();

        $this->grantAdminPermissions(['dashboard-view']);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/account-links')->assertForbidden();
        $this->postJson('/api/admin/account-links', [
            'mainAccountId' => $admin->id,
            'clientAccountId' => $client->id,
            'sharedDetails' => ['shoots' => true],
        ])->assertForbidden();
    }

    public function test_shared_data_aggregation_honors_permissions_per_link(): void
    {
        $admin = User::factory()->admin()->create();
        $shootsClient = User::factory()->create(['name' => 'Shoots Client']);
        $invoicesClient = User::factory()->create(['name' => 'Invoices Client']);

        $this->grantAdminPermissions(['account-linking-view', 'account-linking-update']);

        AccountLink::create([
            'main_account_id' => $admin->id,
            'linked_account_id' => $shootsClient->id,
            'shared_details' => ['shoots' => true, 'invoices' => false],
            'status' => 'active',
            'linked_at' => now(),
            'created_by' => $admin->id,
        ]);

        AccountLink::create([
            'main_account_id' => $admin->id,
            'linked_account_id' => $invoicesClient->id,
            'shared_details' => ['shoots' => false, 'invoices' => true],
            'status' => 'active',
            'linked_at' => now(),
            'created_by' => $admin->id,
        ]);

        Shoot::factory()->create([
            'client_id' => $shootsClient->id,
            'total_quote' => 225.00,
        ]);

        Shoot::factory()->create([
            'client_id' => $invoicesClient->id,
            'total_quote' => 510.00,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/admin/account-links/shared-data/{$admin->id}");

        $response->assertOk();
        $response->assertJsonPath('sharedData.totalShoots', 1);
        $response->assertJsonPath('sharedData.totalSpent', 510);
        $response->assertJsonCount(2, 'sharedData.linkedAccounts');
    }

    private function grantAdminPermissions(array $adminPermissions): void
    {
        Setting::query()->updateOrCreate(
            ['key' => 'permissions.role_map.v1'],
            [
                'value' => json_encode([
                    'version' => 1,
                    'roles' => [
                        'superadmin' => ['account-linking-view', 'account-linking-update'],
                        'admin' => $adminPermissions,
                        'editing_manager' => ['account-linking-view'],
                        'salesRep' => ['dashboard-view'],
                        'photographer' => ['dashboard-view'],
                        'editor' => ['dashboard-view'],
                        'client' => ['dashboard-view'],
                    ],
                ]),
                'type' => 'json',
            ],
        );
    }
}
