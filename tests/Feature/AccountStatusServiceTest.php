<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserActivityLog;
use App\Services\AccountStatusService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AccountStatusServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): AccountStatusService
    {
        return app(AccountStatusService::class);
    }

    public function test_locking_sets_locked_at_persists_status_and_revokes_tokens(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'photographer', 'account_status' => 'active']);
        $target->createToken('mobile');

        $this->assertSame(1, $target->tokens()->count());

        $result = $this->service()->setStatus($target, AccountStatusService::STATUS_LOCKED, $admin);

        $this->assertNotNull($result->locked_at);
        $this->assertSame('locked', $result->account_status);
        $this->assertSame('locked', $this->service()->currentStatus($result));
        $this->assertSame(0, $result->tokens()->count(), 'Locking must revoke authentication tokens.');

        $this->assertDatabaseHas('user_activity_logs', [
            'event_type' => 'account.locked',
            'actor_user_id' => $admin->id,
            'target_id' => $target->id,
        ]);
    }

    public function test_deleting_soft_deletes_and_audits(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'photographer']);
        $target->createToken('mobile');

        $this->service()->setStatus($target, AccountStatusService::STATUS_DELETED, $admin);

        $this->assertSoftDeleted('users', ['id' => $target->id]);
        $reloaded = User::withTrashed()->find($target->id);
        $this->assertSame('deleted', $this->service()->currentStatus($reloaded));
        $this->assertSame(0, $reloaded->tokens()->count());

        $this->assertDatabaseHas('user_activity_logs', [
            'event_type' => 'account.deleted',
            'actor_user_id' => $admin->id,
            'target_id' => $target->id,
        ]);
    }

    public function test_restoring_to_active_clears_flags_and_forces_credential_refresh(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'photographer']);

        $this->service()->setStatus($target, AccountStatusService::STATUS_DELETED, $admin);
        $deleted = User::withTrashed()->find($target->id);

        $restored = $this->service()->setStatus($deleted, AccountStatusService::STATUS_ACTIVE, $admin);

        $this->assertFalse($restored->trashed());
        $this->assertNull($restored->locked_at);
        $this->assertSame('active', $restored->account_status);
        $this->assertTrue((bool) $restored->password_reset_required, 'Restore must force a credential refresh.');
        $this->assertSame('active', $this->service()->currentStatus($restored));
    }

    public function test_admin_cannot_lock_or_delete_their_own_account(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->expectException(AuthorizationException::class);
        $this->service()->setStatus($admin, AccountStatusService::STATUS_LOCKED, $admin);
    }

    public function test_admin_cannot_delete_their_own_account(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->expectException(AuthorizationException::class);
        $this->service()->setStatus($admin, AccountStatusService::STATUS_DELETED, $admin);
    }

    public function test_non_super_admin_cannot_delete_an_admin_account(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $targetAdmin = User::factory()->create(['role' => 'admin']);

        $this->expectException(AuthorizationException::class);
        $this->service()->setStatus($targetAdmin, AccountStatusService::STATUS_DELETED, $admin);
    }

    public function test_super_admin_can_delete_an_admin_account(): void
    {
        $superAdmin = User::factory()->create(['role' => 'superadmin']);
        $targetAdmin = User::factory()->create(['role' => 'admin']);

        $this->service()->setStatus($targetAdmin, AccountStatusService::STATUS_DELETED, $superAdmin);

        $this->assertSoftDeleted('users', ['id' => $targetAdmin->id]);
    }

    public function test_unsupported_status_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'photographer']);

        $this->expectException(ValidationException::class);
        $this->service()->setStatus($target, 'frozen', $admin);
    }

    public function test_invalidate_sessions_revokes_tokens_and_clears_authz_cache(): void
    {
        $target = User::factory()->create(['role' => 'photographer']);
        $target->createToken('mobile');
        \Illuminate\Support\Facades\Cache::put("authz:user:{$target->id}", ['cached'], 60);

        $this->service()->invalidateSessions($target);

        $this->assertSame(0, $target->tokens()->count());
        $this->assertNull(\Illuminate\Support\Facades\Cache::get("authz:user:{$target->id}"));
    }
}
