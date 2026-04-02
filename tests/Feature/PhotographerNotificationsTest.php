<?php

namespace Tests\Feature;

use App\Events\ShootActivityBroadcast;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PhotographerNotificationsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function photographer_notifications_only_include_shoots_assigned_to_them(): void
    {
        Cache::flush();

        $admin = User::factory()->admin()->create();
        $targetPhotographer = User::factory()->photographer()->create();
        $otherPhotographer = User::factory()->photographer()->create();
        $client = User::factory()->create();
        $service = Service::factory()->create();

        $serviceAssignedShoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'photographer_id' => $otherPhotographer->id,
        ]);
        $serviceAssignedShoot->services()->attach($service->id, [
            'price' => 150.00,
            'quantity' => 1,
            'photographer_id' => $targetPhotographer->id,
        ]);

        $otherShoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'photographer_id' => $otherPhotographer->id,
        ]);
        $otherShoot->services()->attach($service->id, [
            'price' => 175.00,
            'quantity' => 1,
            'photographer_id' => $otherPhotographer->id,
        ]);

        ShootActivityLog::create([
            'shoot_id' => $serviceAssignedShoot->id,
            'user_id' => $admin->id,
            'action' => 'shoot_created',
            'description' => 'Shoot created for the target photographer',
        ]);

        ShootActivityLog::create([
            'shoot_id' => $otherShoot->id,
            'user_id' => $admin->id,
            'action' => 'shoot_created',
            'description' => 'Shoot created for another photographer',
        ]);

        Sanctum::actingAs($targetPhotographer);

        $response = $this->getJson('/api/notifications');

        $response->assertOk();

        $shootIds = collect($response->json('data.activity_log', []))
            ->pluck('shootId')
            ->filter()
            ->values()
            ->all();

        $this->assertContains($serviceAssignedShoot->id, $shootIds);
        $this->assertNotContains($otherShoot->id, $shootIds);
    }

    #[Test]
    public function shoot_activity_broadcast_targets_service_level_photographers(): void
    {
        $targetPhotographer = User::factory()->photographer()->create();
        $otherPhotographer = User::factory()->photographer()->create();
        $client = User::factory()->create();
        $serviceA = Service::factory()->create();
        $serviceB = Service::factory()->create();

        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'photographer_id' => $otherPhotographer->id,
        ]);

        $shoot->services()->attach($serviceA->id, [
            'price' => 150.00,
            'quantity' => 1,
            'photographer_id' => $targetPhotographer->id,
        ]);

        $shoot->services()->attach($serviceB->id, [
            'price' => 200.00,
            'quantity' => 1,
            'photographer_id' => $targetPhotographer->id,
        ]);

        $event = new ShootActivityBroadcast(
            $shoot->fresh(),
            'shoot_created',
            'Shoot created for assigned photographers'
        );

        $channelNames = collect($event->broadcastOn())
            ->map(fn ($channel) => $channel->name)
            ->values()
            ->all();

        $targetChannel = 'private-photographer.' . $targetPhotographer->id . '.notifications';
        $otherChannel = 'private-photographer.' . $otherPhotographer->id . '.notifications';

        $this->assertContains($targetChannel, $channelNames);
        $this->assertContains($otherChannel, $channelNames);
        $this->assertSame(
            1,
            collect($channelNames)->filter(
                fn (string $channelName) => $channelName === $targetChannel
            )->count()
        );
    }
}
