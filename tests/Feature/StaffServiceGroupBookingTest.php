<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceGroup;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Shoots\ShootMutationSupportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StaffServiceGroupBookingTest extends TestCase
{
    use RefreshDatabase;

    private User $client;
    private Service $visible;
    private Service $hidden;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->client = User::factory()->create(['role' => 'client']);
        $group = ServiceGroup::create(['name' => 'R/E Pro Photos', 'is_active' => true]);
        $this->client->serviceGroups()->attach($group);
        $attributes = ['price' => 100, 'pricing_type' => 'fixed', 'photographer_required' => false];
        $this->visible = Service::factory()->create($attributes);
        $this->hidden = Service::factory()->create($attributes);
        $group->services()->attach($this->visible);
    }

    public static function staffRoles(): array
    {
        return array_map(fn ($role) => [$role], ['admin', 'superadmin', 'salesrep', 'sales_rep']);
    }

    private function payload(Service $service, bool $multi = false): array
    {
        $base = ['client_id' => $this->client->id, 'address' => '100 Main Street',
            'city' => 'Arlington', 'state' => 'VA', 'zip' => '22201', 'send_notification' => false];
        return $base + ($multi ? [
            'units' => [['client_key' => 'unit-1', 'label' => 'Unit 1', 'kind' => 'unit', 'sqft' => 1000]],
            'service_lines' => [['client_key' => 'line-1', 'unit_client_key' => 'unit-1', 'service_id' => $service->id, 'quantity' => 1]],
        ] : ['services' => [['id' => $service->id, 'quantity' => 1]]]);
    }

    #[DataProvider('staffRoles')]
    public function test_staff_can_see_and_book_outside_client_groups_in_both_booking_shapes(string $role): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => $role]));
        $ids = $this->getJson('/api/services?client_id='.$this->client->id)->assertOk()->json('data.*.id');
        $this->assertContains($this->hidden->id, $ids);
        foreach ([false, true] as $multi) {
            $id = $this->postJson('/api/shoots', $this->payload($this->hidden, $multi))->assertCreated()->json('data.id');
            $this->assertTrue(Shoot::findOrFail($id)->services()->where('services.id', $this->hidden->id)->exists());
        }
    }

    #[DataProvider('staffRoles')]
    public function test_staff_can_add_hidden_services_to_a_client_request(string $role): void
    {
        Sanctum::actingAs($this->client);
        $id = $this->postJson('/api/shoots', $this->payload($this->visible))->assertCreated()->json('data.id');
        Sanctum::actingAs(User::factory()->create(['role' => $role]));
        $this->patchJson('/api/shoots/'.$id, ['services' => [
            ['id' => $this->visible->id], ['id' => $this->hidden->id],
        ]])->assertOk();
        $this->assertTrue(Shoot::findOrFail($id)->services()->where('services.id', $this->hidden->id)->exists());
    }

    public function test_client_catalog_and_direct_submissions_stay_restricted(): void
    {
        Sanctum::actingAs($this->client);
        $other = User::factory()->create(['role' => 'client']);
        $ids = $this->getJson('/api/services?client_id='.$other->id)->assertOk()->json('data.*.id');
        $this->assertContains($this->visible->id, $ids);
        $this->assertNotContains($this->hidden->id, $ids);
        foreach ([false, true] as $multi) {
            $this->postJson('/api/shoots', $this->payload($this->hidden, $multi) + ['role' => 'admin'])
                ->assertUnprocessable()->assertJsonValidationErrors('services');
        }
        $this->postJson('/api/shoots', $this->payload($this->visible))->assertCreated();
    }

    public function test_client_with_two_groups_sees_both_catalogs(): void
    {
        $group = ServiceGroup::create(['name' => 'VYBE', 'is_active' => true]);
        $group->services()->attach($this->hidden);
        $this->client->serviceGroups()->attach($group);
        Sanctum::actingAs($this->client);
        $ids = $this->getJson('/api/services')->assertOk()->json('data.*.id');
        $this->assertContains($this->visible->id, $ids);
        $this->assertContains($this->hidden->id, $ids);
        $this->postJson('/api/shoots', $this->payload($this->hidden))->assertCreated();
    }

    public function test_missing_actor_does_not_inherit_the_authenticated_admin_exception(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->expectException(ValidationException::class);
        app(ShootMutationSupportService::class)->ensureClientCanBookServices($this->client->id, [['id' => $this->hidden->id]]);
    }

    public function test_migration_only_services_remain_unbookable_for_staff(): void
    {
        $this->hidden->forceFill(['is_migration_only' => true])->save();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->assertNotContains($this->hidden->id, $this->getJson('/api/services')->assertOk()->json('data.*.id'));
        $this->postJson('/api/shoots', $this->payload($this->hidden))->assertUnprocessable()->assertJsonValidationErrors('services');
    }
}
