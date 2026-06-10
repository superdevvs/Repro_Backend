<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Services\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): AuditLogService
    {
        return app(AuditLogService::class);
    }

    public function test_records_an_action_against_a_user_target(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'photographer']);

        $entry = $this->service()->record('account.locked', $admin, $target, [
            'reason' => 'policy_violation',
        ]);

        $this->assertInstanceOf(UserActivityLog::class, $entry);
        $this->assertTrue($entry->exists);
        $this->assertSame('account.locked', $entry->event_type);
        $this->assertSame($admin->id, $entry->actor_user_id);
        $this->assertSame(User::class, $entry->target_type);
        $this->assertSame($target->id, (int) $entry->target_id);
        // For a user target, user_id mirrors the target so per-user views surface the entry.
        $this->assertSame($target->id, $entry->user_id);
        $this->assertSame(['reason' => 'policy_violation'], $entry->metadata);
        $this->assertNotNull($entry->occurred_at);
        $this->assertNotNull($entry->created_at);

        $this->assertTrue($target->is($entry->target));
    }

    public function test_records_an_action_against_a_shoot_target(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create();

        $entry = $this->service()->record('notification.manual_send', $admin, $shoot, [
            'channel' => 'email',
            'recipient_type' => 'client',
            'status' => 'sent',
        ]);

        $this->assertSame('notification.manual_send', $entry->event_type);
        $this->assertSame($admin->id, $entry->actor_user_id);
        $this->assertSame(Shoot::class, $entry->target_type);
        $this->assertSame($shoot->id, (int) $entry->target_id);
        // Non-user targets are addressed only via the polymorphic columns.
        $this->assertNull($entry->user_id);
        $this->assertSame('email', $entry->metadata['channel']);
        $this->assertTrue($shoot->is($entry->target));
    }

    public function test_records_an_action_with_a_null_target_and_null_actor(): void
    {
        $entry = $this->service()->record('system.maintenance', null, null, [
            'note' => 'scheduled job',
        ]);

        $this->assertSame('system.maintenance', $entry->event_type);
        $this->assertNull($entry->actor_user_id);
        $this->assertNull($entry->target_type);
        $this->assertNull($entry->target_id);
        $this->assertNull($entry->user_id);
        $this->assertNull($entry->target);
        $this->assertSame(['note' => 'scheduled job'], $entry->metadata);
        $this->assertNotNull($entry->created_at);
    }
}
