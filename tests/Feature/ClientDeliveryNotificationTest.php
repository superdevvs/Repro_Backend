<?php

namespace Tests\Feature;

use App\Jobs\FinalizeShootJob;
use App\Models\ClientDeliveryNotification;
use App\Models\Shoot;
use App\Models\User;
use App\Services\ShootActivityLogger;
use App\Services\Shoots\FinalizeProgressTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientDeliveryNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_delivery_creates_one_primary_owner_notification_for_the_stable_event_key(): void
    {
        Queue::fake();
        $client = User::factory()->create(['role' => 'client']);
        $admin = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'base_quote' => 0,
            'tax_amount' => 0,
            'total_quote' => 0,
            'status' => Shoot::STATUS_UPLOADED,
            'workflow_status' => Shoot::STATUS_UPLOADED,
        ]);

        $job = new FinalizeShootJob(
            $shoot->id,
            $admin->id,
            'completed',
            null,
            true,
            'delivery-event-1'
        );
        $job->handle(
            app(ShootActivityLogger::class),
            app(FinalizeProgressTracker::class)
        );

        $this->assertDatabaseHas('client_delivery_notifications', [
            'user_id' => $client->id,
            'shoot_id' => $shoot->id,
            'delivery_event_key' => 'delivery-event-1',
        ]);
        $this->assertSame(1, ClientDeliveryNotification::count());
    }

    public function test_seen_mutation_is_owner_scoped_and_idempotent(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $other = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id]);
        $notification = ClientDeliveryNotification::create([
            'user_id' => $client->id,
            'shoot_id' => $shoot->id,
            'delivery_event_key' => 'delivery-event-2',
            'delivered_at' => now(),
        ]);

        Sanctum::actingAs($other);
        $this->postJson("/api/client/delivery-notifications/{$notification->id}/seen")
            ->assertForbidden();
        $this->assertNull($notification->fresh()->seen_at);

        Sanctum::actingAs($client);
        $this->getJson('/api/client/delivery-notifications')
            ->assertOk()
            ->assertJsonPath('data.unseen_count', 1)
            ->assertJsonPath('data.entries.0.shoot_id', $shoot->id);

        $this->postJson("/api/client/delivery-notifications/{$notification->id}/seen")
            ->assertOk();
        $firstSeenAt = $notification->fresh()->seen_at?->toIso8601String();
        $this->postJson("/api/client/delivery-notifications/{$notification->id}/seen")
            ->assertOk();
        $this->assertSame($firstSeenAt, $notification->fresh()->seen_at?->toIso8601String());
    }
}
