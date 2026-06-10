<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccountStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * HTTP-level coverage for the account-status endpoint (Req 16.4):
 * PATCH /api/admin/users/{user}/status delegating to AccountStatusService.
 */
class AccountStatusEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_lock_a_user_via_the_endpoint(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'photographer', 'account_status' => 'active']);

        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/admin/users/{$target->id}/status", [
            'status' => AccountStatusService::STATUS_LOCKED,
        ]);

        // AC 16.4 — returns the persisted, canonical status.
        $response->assertOk()
            ->assertJson([
                'id' => $target->id,
                'status' => AccountStatusService::STATUS_LOCKED,
            ]);

        $this->assertNotNull($target->fresh()->locked_at);
    }

    public function test_admin_can_delete_a_user_via_the_endpoint(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'photographer']);

        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/admin/users/{$target->id}/status", [
            'status' => AccountStatusService::STATUS_DELETED,
        ]);

        $response->assertOk()
            ->assertJson([
                'id' => $target->id,
                'status' => AccountStatusService::STATUS_DELETED,
            ]);

        $this->assertSoftDeleted('users', ['id' => $target->id]);
    }

    public function test_admin_can_restore_a_user_to_active_via_the_endpoint(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'photographer', 'locked_at' => now(), 'account_status' => 'locked']);

        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/admin/users/{$target->id}/status", [
            'status' => AccountStatusService::STATUS_ACTIVE,
        ]);

        $response->assertOk()
            ->assertJson([
                'id' => $target->id,
                'status' => AccountStatusService::STATUS_ACTIVE,
            ]);

        $this->assertNull($target->fresh()->locked_at);
    }

    public function test_admin_cannot_lock_their_own_account_returns_403(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Sanctum::actingAs($admin);

        // AC 16.5 — self lock is rejected; AuthorizationException maps to 403.
        $this->patchJson("/api/admin/users/{$admin->id}/status", [
            'status' => AccountStatusService::STATUS_LOCKED,
        ])->assertStatus(403);

        $this->assertNull($admin->fresh()->locked_at);
    }

    public function test_admin_cannot_delete_their_own_account_returns_403(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Sanctum::actingAs($admin);

        // AC 16.5 — self delete is rejected; AuthorizationException maps to 403.
        $this->patchJson("/api/admin/users/{$admin->id}/status", [
            'status' => AccountStatusService::STATUS_DELETED,
        ])->assertStatus(403);

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'deleted_at' => null]);
    }

    public function test_non_super_admin_deleting_an_admin_returns_403(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $targetAdmin = User::factory()->create(['role' => 'admin']);

        Sanctum::actingAs($admin);

        // AC 16.6 — only a Super_Admin may delete an admin account.
        $this->patchJson("/api/admin/users/{$targetAdmin->id}/status", [
            'status' => AccountStatusService::STATUS_DELETED,
        ])->assertStatus(403);

        $this->assertDatabaseHas('users', ['id' => $targetAdmin->id, 'deleted_at' => null]);
    }

    public function test_invalid_status_value_returns_422(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'photographer']);

        Sanctum::actingAs($admin);

        // AC 16.1 — status must be one of the three canonical states; ValidationException maps to 422.
        $this->patchJson("/api/admin/users/{$target->id}/status", [
            'status' => 'frozen',
        ])->assertStatus(422);
    }
}
