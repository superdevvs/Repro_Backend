<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\User;
use App\Services\Scheduling\TravelLocationResolver;
use App\Services\NominatimRequestThrottler;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\FreshDatabaseOutsideTransaction;
use Tests\TestCase;

class TravelLocationResolverTest extends TestCase
{
    use FreshDatabaseOutsideTransaction;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.nominatim.throttle_cache_store' => 'array', 'services.nominatim.min_interval_milliseconds' => 0]);
        Http::preventStrayRequests();
    }

    private function address(string $suffix = ''): array
    {
        return ['address' => '12800 Middlebrook Road'.$suffix, 'city' => 'Germantown', 'state' => 'MD', 'zip' => '20874'];
    }

    private function exactResult(): array
    {
        return [['lat' => '39.0', 'lon' => '-77.2', 'address' => ['house_number' => '12800', 'road' => 'Middlebrook Road', 'postcode' => '20874', 'country_code' => 'us']]];
    }

    public function test_exact_server_verification_groups_recognized_units_without_trusting_posted_coordinates(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response($this->exactResult())]);
        $resolver = app(TravelLocationResolver::class);
        $first = $resolver->forPayload($this->address(' Unit 333') + ['latitude' => 1, 'longitude' => 2, 'place_id' => 'fake-google-id']);
        $second = $resolver->forPayload($this->address(' Ste. 206'));
        $this->assertSame('exact', $first['precision']);
        $this->assertTrue($first['verified']);
        $this->assertSame(39.0, $first['latitude']);
        $this->assertSame($first['building_key'], $second['building_key']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['street'] === '12800 MIDDLEBROOK RD' && ! isset($request['q']));
    }

    public function test_locality_wrong_street_and_forged_metadata_are_not_location_proof(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([
            ['lat' => 39, 'lon' => -77, 'address' => ['city' => 'Germantown', 'postcode' => '20874', 'country_code' => 'us']],
            ['lat' => 39, 'lon' => -77, 'address' => ['house_number' => '12801', 'road' => 'Middlebrook Road', 'postcode' => '20874', 'country_code' => 'us']],
        ])]);
        $location = app(TravelLocationResolver::class)->forPayload($this->address() + ['property_details' => ['schedule_location' => [
            'verified' => true, 'precision' => 'exact', 'building_key' => 'forged', 'latitude' => 39, 'longitude' => -77,
        ]]]);
        $this->assertFalse($location['verified']);
        $this->assertNull($location['latitude']);
        $this->assertNull($location['building_key']);
        Http::assertSentCount(1);
    }

    public function test_signed_metadata_round_trips_and_address_changes_or_tampering_invalidate_it(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response($this->exactResult())]);
        $resolver = app(TravelLocationResolver::class);
        $location = $resolver->forPayload($this->address());
        $shoot = Shoot::factory()->create($this->address() + ['property_details' => ['schedule_location' => $resolver->persistedMetadata($location)]]);
        Http::swap(new Factory);
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([])]);
        $resolver->reset();
        $this->assertSame(39.0, $resolver->forShoot($shoot->fresh())['latitude']);
        Http::assertNothingSent();
        $details = $shoot->property_details;
        $details['schedule_location']['latitude'] = 40;
        $shoot->property_details = $details;
        $this->assertFalse($resolver->forShoot($shoot)['verified']);
        $this->assertFalse($resolver->forPayload(['address' => '12802 Middlebrook Road'], $shoot)['verified']);
    }

    public function test_staff_confirmation_proves_only_address_and_clients_cannot_confirm(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([])]);
        $resolver = app(TravelLocationResolver::class);
        $staff = User::factory()->create(['role' => 'rep']);
        $confirmed = $resolver->forPayload($this->address(' Apt 9') + ['travel_location_confirmed' => true, 'latitude' => 39, 'longitude' => -77], null, $staff);
        $this->assertTrue($confirmed['verified']);
        $this->assertSame('unknown', $confirmed['precision']);
        $this->assertNull($confirmed['latitude']);
        $this->assertSame($staff->id, $confirmed['verified_by']);
        $this->expectException(ValidationException::class);
        $resolver->forPayload($this->address() + ['travel_location_confirmed' => true], null, User::factory()->create(['role' => 'client']));
    }

    public function test_incomplete_addresses_and_open_transactions_never_geocode(): void
    {
        Http::fake();
        $resolver = app(TravelLocationResolver::class);
        $this->assertFalse($resolver->forPayload(['city' => 'Germantown', 'state' => 'MD', 'zip' => '20874'])['complete']);
        DB::transaction(fn () => $this->assertFalse($resolver->forPayload($this->address())['verified']));
        Http::assertNothingSent();
    }

    public function test_address_only_staff_confirmation_can_later_gain_exact_server_coordinates(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([])]);
        $resolver = app(TravelLocationResolver::class);
        $location = $resolver->forPayload($this->address() + ['travel_location_confirmed' => true], null,
            User::factory()->create(['role' => 'admin']));
        $this->assertSame('unknown', $location['precision']);
        $shoot = Shoot::factory()->create($this->address() + ['property_details' => ['schedule_location' => $resolver->persistedMetadata($location)]]);
        Http::swap(new Factory);
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response($this->exactResult())]);
        $resolver->reset();
        $verified = $resolver->forShoot($shoot);
        $this->assertSame('exact', $verified['precision']);
        $this->assertSame(39.0, $verified['latitude']);
        $this->assertSame($location['building_key'], $verified['building_key']);
        Http::assertSentCount(1);
    }

    public function test_busy_geocoder_does_not_hold_workers_or_supply_location_proof(): void
    {
        config(['services.nominatim.lock_wait_seconds' => 1]);
        Http::fake();
        $lock = Cache::store('array')->lock(NominatimRequestThrottler::LOCK_KEY, 20);
        $this->assertTrue($lock->get());
        $resolver = app(TravelLocationResolver::class);
        $started = microtime(true);
        try {
            foreach ([12800, 12801, 12802] as $number) {
                $location = $resolver->forPayload(array_replace($this->address(), ['address' => $number.' Middlebrook Road']));
                $this->assertFalse($location['verified']);
                $this->assertNull($location['latitude']);
                $this->assertSame('location_unverified', $location['reason_code']);
            }
            $this->assertLessThan(0.75, microtime(true) - $started);
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    public function test_geocodes_share_a_budget_and_reset_allows_a_new_evaluation(): void
    {
        $optionsSeen = [];
        Http::fake(function ($request, $options) use (&$optionsSeen) {
            $optionsSeen[] = $options;
            usleep(100000);
            return Http::response([]);
        });
        $resolver = app(TravelLocationResolver::class);
        $resolver->reset();
        foreach ([12800, 12801] as $number) {
            $resolver->forPayload(array_replace($this->address(), ['address' => $number.' Middlebrook Road']));
        }
        $this->assertCount(2, $optionsSeen);
        $this->assertLessThanOrEqual(3, $optionsSeen[0]['timeout']);
        $this->assertLessThanOrEqual(1, $optionsSeen[0]['connect_timeout']);
        $this->assertLessThan($optionsSeen[0]['timeout'], $optionsSeen[1]['timeout']);
        $deadline = new \ReflectionProperty($resolver, 'lookupDeadline');
        $deadline->setValue($resolver, microtime(true) - 1);
        $this->assertFalse($resolver->forPayload(array_replace($this->address(), ['address' => '12802 Middlebrook Road']))['verified']);
        Http::assertSentCount(2);
        $resolver->reset();
        $resolver->forPayload(array_replace($this->address(), ['address' => '12802 Middlebrook Road']));
        Http::assertSentCount(3);
    }

}
