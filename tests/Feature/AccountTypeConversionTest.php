<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountTypeConversionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_convert_a_user_to_a_defined_role(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'photographer']);

        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/admin/users/{$target->id}/convert-type", [
            'account_type' => 'editor',
        ]);

        $response->assertOk()
            ->assertJson([
                'id' => $target->id,
                'role' => 'editor',
                'previous_type' => 'photographer',
                'new_type' => 'editor',
            ]);

        // AC 18.1 — role is persisted as the selected value.
        $this->assertSame('editor', $target->fresh()->role);
    }

    public function test_conversion_writes_an_audit_log_entry_with_previous_and_new_type(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'client']);

        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/users/{$target->id}/convert-type", [
            'account_type' => 'photographer',
        ])->assertOk();

        // AC 18.4 — exactly one audit entry recording actor, target, previous + new type.
        $entries = UserActivityLog::where('event_type', 'account.type_converted')->get();
        $this->assertCount(1, $entries);

        $entry = $entries->first();
        $this->assertSame($admin->id, $entry->actor_user_id);
        $this->assertSame(User::class, $entry->target_type);
        $this->assertSame($target->id, (int) $entry->target_id);
        $this->assertSame('client', $entry->metadata['previous_type']);
        $this->assertSame('photographer', $entry->metadata['new_type']);
        $this->assertNotNull($entry->occurred_at);
    }

    public function test_conversion_to_an_undefined_role_is_rejected_with_422(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'photographer']);

        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/admin/users/{$target->id}/convert-type", [
            'account_type' => 'not_a_real_role',
        ]);

        // AC 18.3 — invalid roles rejected with a validation error (HTTP 422).
        $response->assertStatus(422);

        // Role is unchanged.
        $this->assertSame('photographer', $target->fresh()->role);

        // No audit entry is written for a rejected conversion.
        $this->assertDatabaseMissing('user_activity_logs', [
            'event_type' => 'account.type_converted',
        ]);
    }
}
