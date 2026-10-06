<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\ShootActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationFeedLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_feed_reaches_999_and_keeps_the_newest_notifications(): void
    {
        Cache::flush();
        $admin = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create();
        $this->insertActivity($shoot, $admin, 1005);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/notifications')->assertOk();
        $feed = collect($response->json('data.activity_log'));
        $this->assertCount(999, $feed);
        $this->assertSame('Activity 1004', $feed->first()['message']);
        $this->assertSame('Activity 6', $feed->last()['message']);
    }

    public function test_client_feed_exceeds_200_without_exposing_another_clients_shoot(): void
    {
        Cache::flush();
        $client = User::factory()->create(['role' => 'client']);
        $ownShoot = Shoot::factory()->create(['client_id' => $client->id]);
        $otherShoot = Shoot::factory()->create();
        $this->insertActivity($ownShoot, $client, 250);
        $this->insertActivity($otherShoot, $client, 10);
        Sanctum::actingAs($client);

        $response = $this->getJson('/api/notifications')->assertOk();
        $feed = collect($response->json('data.activity_log'));
        $this->assertCount(250, $feed);
        $this->assertSame([$ownShoot->id], $feed->pluck('shootId')->unique()->values()->all());
    }

    private function insertActivity(Shoot $shoot, User $actor, int $count): void
    {
        $rows = [];
        for ($index = 0; $index < $count; $index++) {
            $timestamp = now()->subSeconds($count - $index)->toDateTimeString();
            $rows[] = [
                'shoot_id' => $shoot->id,
                'user_id' => $actor->id,
                'action' => 'shoot_created',
                'description' => 'Activity '.$index,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            ShootActivityLog::insert($chunk);
        }
    }
}
