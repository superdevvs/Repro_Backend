<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AddressLookupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AvailabilityMapLocationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        Queue::fake();
        $geocoding = Mockery::mock(AddressLookupService::class);
        $geocoding->shouldNotReceive('geocodeAddress');
        $geocoding->shouldNotReceive('getDistance');
        $this->app->instance(AddressLookupService::class, $geocoding);
    }

    public static function fullVisibilityRoles(): array
    {
        return [['admin'], ['superadmin'], ['photographer']];
    }

    #[DataProvider('fullVisibilityRoles')]
    public function test_admin_and_self_receive_only_explicit_stored_base_fields(string $role): void
    {
        $person = $this->photographer();
        Sanctum::actingAs($role === 'photographer' ? $person : User::factory()->create(['role' => $role]));

        $this->postJson('/api/photographer/availability/map-locations', [
            'photographer_ids' => [$person->id, $person->id],
        ])->assertOk()->assertExactJson(['data' => [[
            'photographer_id' => $person->id,
            'latitude' => 38.912345,
            'longitude' => -77.123456,
            'precision' => 'base',
            'label' => 'Base',
        ]]])->assertDontSee('Private Home', false)->assertDontSee('synthetic-private', false);

        Http::assertNothingSent();
        Mail::assertNothingSent();
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public static function regionVisibilityRoles(): array
    {
        return array_map(fn ($role) => [$role], [
            'salesRep', 'sales_rep', 'sales-rep', 'rep', 'representative', 'editing_manager',
        ]);
    }

    #[DataProvider('regionVisibilityRoles')]
    public function test_team_viewers_without_home_permission_never_receive_raw_or_rounded_home_coordinates(string $role): void
    {
        $person = $this->photographer();
        // A preceding administrator lookup must not warm a viewer-independent cache.
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson('/api/photographer/availability/map-locations', [
            'photographer_ids' => [$person->id],
        ])->assertOk()->assertJsonPath('data.0.precision', 'base');

        Sanctum::actingAs(User::factory()->create(['role' => $role]));
        $response = $this->postJson('/api/photographer/availability/map-locations', [
            'photographer_ids' => [$person->id],
        ])->assertOk()->assertExactJson(['data' => [$this->unavailable($person)]]);

        foreach (['38.912345', '-77.123456', 'Private Home', 'metadata', 'synthetic-private'] as $private) {
            $response->assertDontSee($private, false);
        }
        Http::assertNothingSent();
    }

    public static function deniedRoles(): array
    {
        return [['photographer'], ['client'], ['editor']];
    }

    #[DataProvider('deniedRoles')]
    public function test_other_photographers_and_nonstaff_cannot_read_foreign_base_locations(string $role): void
    {
        $person = $this->photographer();
        Sanctum::actingAs(User::factory()->create(['role' => $role]));
        $this->postJson('/api/photographer/availability/map-locations', [
            'photographer_ids' => [$person->id],
        ])->assertForbidden()->assertDontSee('38.912345', false)->assertDontSee('Private Home', false);
    }

    public function test_a_mixed_self_and_foreign_request_fails_without_partial_locations(): void
    {
        $person = $this->photographer();
        $other = $this->photographer();
        Sanctum::actingAs($person);
        $this->postJson('/api/photographer/availability/map-locations', [
            'photographer_ids' => [$person->id, $other->id],
        ])->assertForbidden()->assertJsonMissingPath('data')->assertDontSee('38.912345', false);
    }

    public function test_guests_require_authentication_and_request_ids_are_validated(): void
    {
        $this->postJson('/api/photographer/availability/map-locations', [
            'photographer_ids' => [1],
        ])->assertUnauthorized();
        Sanctum::actingAs(User::factory()->admin()->create());
        foreach ([[], ['photographer_ids' => []], ['photographer_ids' => ['all']], ['photographer_ids' => [999999]]] as $input) {
            $this->postJson('/api/photographer/availability/map-locations', $input)->assertUnprocessable();
        }
    }

    public function test_missing_invalid_and_untrusted_region_locations_stay_explicitly_unavailable_without_geocoding(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $metadata = [
            null,
            ['latitude' => 91, 'longitude' => -77],
            ['latitude' => 38, 'longitude' => -181],
            ['latitude' => 'not-a-number', 'longitude' => -77],
            ['latitude' => 'INF', 'longitude' => -77],
            ['latitude' => true, 'longitude' => -77],
            ['latitude' => [38.9], 'longitude' => -77],
            ['latitude' => 38.9],
            ['region_latitude' => 38.9, 'region_longitude' => -77.1],
        ];
        $people = collect($metadata)->map(fn ($coordinates) => $this->photographer(['metadata' => $coordinates]));
        $this->postJson('/api/photographer/availability/map-locations', [
            'photographer_ids' => $people->pluck('id')->all(),
        ])->assertOk()->assertExactJson(['data' => $people->map(fn ($person) => $this->unavailable($person))->all()]);
        Http::assertNothingSent();
    }

    public function test_legacy_coordinate_aliases_and_secondary_photographers_use_the_same_narrow_contract(): void
    {
        $secondary = $this->photographer([
            'role' => 'editor', 'secondary_roles' => ['photographer'],
            'metadata' => ['lat' => '38.9', 'lng' => '-77.1'],
        ]);
        $client = User::factory()->create([
            'role' => 'client', 'metadata' => ['latitude' => 38.8, 'longitude' => -77.2],
        ]);
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson('/api/photographer/availability/map-locations', [
            'photographer_ids' => [$secondary->id, $client->id],
        ])->assertOk()->assertExactJson(['data' => [[
            'photographer_id' => $secondary->id,
            'latitude' => 38.9, 'longitude' => -77.1, 'precision' => 'base', 'label' => 'Base',
        ], $this->unavailable($client)]]);
    }

    private function photographer(array $overrides = []): User
    {
        return User::factory()->photographer()->create(array_replace([
            'name' => 'Synthetic Photographer',
            'address' => '99 Private Home Lane',
            'city' => 'Synthetic City', 'state' => 'VA', 'zip' => '00000',
            'metadata' => ['latitude' => 38.912345, 'longitude' => -77.123456,
                'homeAddress' => '99 Private Home Lane', 'private_note' => 'synthetic-private'],
        ], $overrides));
    }

    private function unavailable(User $person): array
    {
        return ['photographer_id' => $person->id, 'latitude' => null, 'longitude' => null,
            'precision' => null, 'label' => null];
    }
}
