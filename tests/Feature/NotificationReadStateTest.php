<?php

namespace Tests\Feature;

use App\Events\ShootActivityBroadcast;
use App\Models\Shoot;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Services\ShootActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationReadStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipts_merge_across_devices_and_cannot_move_the_watermark_backwards(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);
        $first = (int) now()->subMinutes(5)->getTimestampMs();
        $second = $first + 1000;
        $this->postJson('/api/notifications/read-state', ['ids' => ['sa-1'], 'lastSeenAt' => $second])->assertOk();
        $this->postJson('/api/notifications/read-state', ['ids' => ['sa-2'], 'lastSeenAt' => $first])
            ->assertOk()->assertJsonPath('data.lastSeenAt', $second)->assertJsonStructure(['data' => ['readIds' => ['sa-1', 'sa-2']]]);
        $this->getJson('/api/notifications')->assertOk()
            ->assertJsonPath('data.read_state.lastSeenAt', $second)
            ->assertJsonStructure(['data' => ['read_state' => ['readIds' => ['sa-1', 'sa-2']]]]);
    }

    public function test_read_receipts_are_scoped_to_the_authenticated_account(): void
    {
        $first = User::factory()->admin()->create();
        $second = User::factory()->admin()->create();
        Sanctum::actingAs($first);
        $this->postJson('/api/notifications/read-state', ['ids' => ['sa-1']])->assertOk();
        Sanctum::actingAs($second);
        $this->getJson('/api/notifications')->assertOk()->assertJsonPath('data.read_state.lastSeenAt', null)
            ->assertJsonMissingPath('data.read_state.readIds.sa-1');
    }

    public function test_impersonation_does_not_mark_the_real_targets_notifications_read(): void
    {
        $admin = User::factory()->admin()->create();
        $client = User::factory()->create(['role' => 'client']);
        Sanctum::actingAs($admin);
        $this->withHeader('X-Impersonate-User-Id', (string) $client->id)
            ->postJson('/api/notifications/read-state', ['ids' => ['sa-9']])->assertOk();
        $this->assertDatabaseHas('notification_read_states', ['user_id' => $client->id, 'context' => 'client:imp:'.$admin->id]);
        $this->flushHeaders();
        Sanctum::actingAs($client);
        $this->getJson('/api/notifications')->assertOk()->assertJsonMissingPath('data.read_state.readIds.sa-9');
    }

    public function test_future_watermarks_are_clamped_and_invalid_receipts_are_rejected(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $response = $this->postJson('/api/notifications/read-state', ['ids' => [], 'lastSeenAt' => now()->addYear()->getTimestampMs()])->assertOk();
        $this->assertLessThanOrEqual(now()->getTimestampMs(), $response->json('data.lastSeenAt'));
        $this->postJson('/api/notifications/read-state', ['ids' => ['<script>']])->assertUnprocessable();
        $this->postJson('/api/notifications/read-state', ['ids' => array_fill(0, 1501, 'sa-1')])->assertUnprocessable();
    }

    public function test_live_event_and_feed_share_the_persisted_activity_id(): void
    {
        Cache::flush();
        Event::fake([ShootActivityBroadcast::class]);
        $admin = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create();
        Sanctum::actingAs($admin);
        $log = app(ShootActivityLogger::class)->log($shoot, 'shoot_editing_started', ['by' => 'Office'], $admin);
        Event::assertDispatched(ShootActivityBroadcast::class, function ($event) use ($log) {
            $payload = $event->broadcastWith();

            return $payload['id'] === 'sa-'.$log->id && $payload['timestamp'] === $log->created_at->toIso8601String();
        });
        $this->getJson('/api/notifications')->assertOk()->assertJsonPath('data.activity_log.0.id', 'sa-'.$log->id)
            ->assertJsonPath('data.activity_log.0.timestamp', $log->created_at->toIso8601String())
            ->assertJsonPath('data.activity_log.0.isOwnAction', true);
    }

    public function test_unauthenticated_users_cannot_write_receipts(): void
    {
        $this->postJson('/api/notifications/read-state', ['ids' => ['sa-1']])->assertUnauthorized();
    }

    public function test_account_feed_includes_scoped_verification_resolutions_and_timezone(): void
    {
        Cache::flush();
        $client = User::factory()->create(['role' => 'client']);
        $other = User::factory()->create(['role' => 'client']);
        UserActivityLog::record($client, 'email_verification_requested', 'Verify email');
        $verified = UserActivityLog::record($client, 'email_verified', 'Email verified');
        UserActivityLog::record($other, 'email_verified', 'Other account verified');
        Sanctum::actingAs($client);
        $response = $this->getJson('/api/notifications')->assertOk();
        $events = collect($response->json('data.activity_log'));
        $this->assertCount(2, $events);
        $event = $events->firstWhere('id', 'email-issue-'.$verified->id);
        $this->assertSame('email_verified', $event['action']);
        $this->assertSame($client->id, $event['metadata']['user_id']);
        $this->assertSame($verified->occurred_at->toIso8601String(), $event['timestamp']);
    }
}
