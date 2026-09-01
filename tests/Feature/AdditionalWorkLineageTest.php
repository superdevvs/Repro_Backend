<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdditionalWorkLineageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_standard_booking_persists_validated_parent_and_root_lineage(): void
    {
        Queue::fake();
        Mail::fake();
        Http::fake();

        $admin = User::factory()->admin()->create();
        $client = User::factory()->create(['role' => 'client']);
        $service = Service::factory()->create(['price' => 150]);
        $root = Shoot::factory()->create([
            'client_id' => $client->id,
            'service_id' => $service->id,
            'address' => '100 Main Street',
            'city' => 'Arlington',
            'state' => 'VA',
            'zip' => '22201',
        ]);
        $parent = Shoot::factory()->create([
            'client_id' => $client->id,
            'service_id' => $service->id,
            'address' => $root->address,
            'city' => $root->city,
            'state' => $root->state,
            'zip' => $root->zip,
            'shoot_type' => Shoot::SHOOT_TYPE_COMPLIMENTARY_RESHOOT,
            'reshoot_of_shoot_id' => $root->id,
            'root_shoot_id' => $root->id,
        ]);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/shoots', $this->payload($client, $service, $parent, [
            'address' => '  100 MAIN street. ',
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.shoot_type', Shoot::SHOOT_TYPE_STANDARD)
            ->assertJsonPath('data.reshoot_of_shoot_id', $parent->id)
            ->assertJsonPath('data.root_shoot_id', $root->id)
            ->assertJsonPath('data.reshoot_classification', 'additional_work');

        $child = Shoot::query()->latest('id')->firstOrFail();
        $this->assertSame(Shoot::SHOOT_TYPE_STANDARD, $child->shoot_type);
        $this->assertSame($parent->id, $child->reshoot_of_shoot_id);
        $this->assertSame($root->id, $child->root_shoot_id);
        $this->assertSame($parent->address, $child->address);
    }

    public function test_additional_work_rejects_client_property_mismatch_and_non_admin(): void
    {
        Queue::fake();
        Mail::fake();
        Http::fake();

        $admin = User::factory()->admin()->create();
        $client = User::factory()->create(['role' => 'client']);
        $otherClient = User::factory()->create(['role' => 'client']);
        $service = Service::factory()->create(['price' => 150]);
        $parent = Shoot::factory()->create([
            'client_id' => $client->id,
            'service_id' => $service->id,
            'address' => '100 Main Street',
            'city' => 'Arlington',
            'state' => 'VA',
            'zip' => '22201',
        ]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/shoots', $this->payload($otherClient, $service, $parent))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('client_id');

        $this->postJson('/api/shoots', $this->payload($client, $service, $parent, [
            'address' => '200 Different Street',
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors('address');

        $salesRep = User::factory()->create(['role' => 'salesRep']);
        Sanctum::actingAs($salesRep);
        $this->postJson('/api/shoots', $this->payload($client, $service, $parent))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reshoot_parent_shoot_id', 'reshoot_classification']);
    }

    public function test_additional_work_rejects_a_corrupt_cyclic_parent_chain(): void
    {
        Queue::fake();
        Mail::fake();
        Http::fake();

        $admin = User::factory()->admin()->create();
        $client = User::factory()->create(['role' => 'client']);
        $service = Service::factory()->create(['price' => 150]);
        $first = Shoot::factory()->create([
            'client_id' => $client->id,
            'service_id' => $service->id,
            'address' => '100 Main Street',
            'city' => 'Arlington',
            'state' => 'VA',
            'zip' => '22201',
        ]);
        $second = Shoot::factory()->create([
            'client_id' => $client->id,
            'service_id' => $service->id,
            'address' => $first->address,
            'city' => $first->city,
            'state' => $first->state,
            'zip' => $first->zip,
            'reshoot_of_shoot_id' => $first->id,
            'root_shoot_id' => $first->id,
        ]);
        $first->update([
            'reshoot_of_shoot_id' => $second->id,
            'root_shoot_id' => $first->id,
        ]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/shoots', $this->payload($client, $service, $first))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reshoot_parent_shoot_id');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(User $client, Service $service, Shoot $parent, array $overrides = []): array
    {
        return array_merge([
            'client_id' => $client->id,
            'address' => $parent->address,
            'city' => $parent->city,
            'state' => $parent->state,
            'zip' => $parent->zip,
            'shoot_type' => Shoot::SHOOT_TYPE_STANDARD,
            'services' => [[
                'id' => $service->id,
                'quantity' => 1,
                'price' => 150,
            ]],
            'reshoot_parent_shoot_id' => $parent->id,
            'reshoot_classification' => 'additional_work',
        ], $overrides);
    }
}
