<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CubiCasaShootTrackerTest extends TestCase
{
    use RefreshDatabase;

    private function eligibleService(): Service
    {
        return Service::factory()->create(['name' => '2D Floor Plan']);
    }

    private function shoot(array $attributes = [], bool $eligible = true): Shoot
    {
        $service = $eligible
            ? $this->eligibleService()
            : Service::factory()->create(['name' => 'HDR Photography']);

        $shoot = Shoot::factory()->create(array_merge([
            'service_id' => $service->id,
            'service_category' => $eligible ? 'Floor Plan' : 'Photography',
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'scheduled_at' => now()->addDays(2),
            'cubicasa_order_id' => null,
            'cubicasa_external_id' => null,
            'address' => '22154 Del Valle St',
            'city' => 'Woodland Hills',
            'state' => 'CA',
            'zip' => '91364',
        ], $attributes));

        DB::table('shoot_service')->insert([
            'shoot_id' => $shoot->id,
            'service_id' => $service->id,
            'price' => 195,
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $shoot->fresh();
    }

    public function test_missing_and_linked_floorplan_shoots_are_split_and_ineligible_shoots_are_hidden(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $missing = $this->shoot(['address' => '10 Missing Lane']);
        $linked = $this->shoot([
            'address' => '20 Linked Lane',
            'cubicasa_order_id' => 'order-20',
            'cubicasa_status' => 'Pending',
        ]);
        $this->shoot(['address' => '30 Photo Only'], false);
        $this->shoot([
            'address' => '40 Cancelled',
            'status' => Shoot::STATUS_CANCELLED,
            'workflow_status' => Shoot::STATUS_CANCELLED,
        ]);
        $this->shoot([
            'address' => '50 Internal',
            'shoot_type' => Shoot::SHOOT_TYPE_INTERNAL_TEST,
        ]);

        $missingResponse = $this->getJson('/api/cubicasa/shoots');
        $missingResponse->assertOk();
        $missingResponse->assertJsonPath('counts.missing', 1);
        $missingResponse->assertJsonPath('counts.linked', 1);
        $missingIds = collect($missingResponse->json('data'))->pluck('id');
        $this->assertTrue($missingIds->contains($missing->id));
        $this->assertFalse($missingIds->contains($linked->id));

        $linkedResponse = $this->getJson('/api/cubicasa/shoots?status=linked');
        $linkedResponse->assertOk();
        $linkedIds = collect($linkedResponse->json('data'))->pluck('id');
        $this->assertTrue($linkedIds->contains($linked->id));
        $this->assertFalse($linkedIds->contains($missing->id));
        $this->assertSame('order-20', $linkedResponse->json('data.0.cubicasa_order_id'));
    }

    public function test_a_photographer_only_sees_assigned_shoots(): void
    {
        $photographer = User::factory()->photographer()->create();
        $other = User::factory()->photographer()->create();
        Sanctum::actingAs($photographer);

        $mine = $this->shoot(['photographer_id' => $photographer->id, 'address' => 'Mine']);
        $this->shoot(['photographer_id' => $other->id, 'address' => 'Theirs']);

        $response = $this->getJson('/api/cubicasa/shoots?status=all');
        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($mine->id));
        $this->assertCount(1, $ids);
    }
}
