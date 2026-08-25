<?php

namespace Tests\Feature;

use App\Jobs\CreateCubiCasaOrderJob;
use App\Jobs\IngestIguideAssetsJob;
use App\Jobs\SyncShootIguideJob;
use App\Models\Category;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootService;
use App\Services\IguideService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A Floor Plan booking and its iGUIDE do not arrive together.
 *
 * The photographer shoots the iGUIDE hours or days after the booking is made,
 * so the deliverable shows up long after the shoot row exists. When it does,
 * the provider webhook carries an address but no identifier we have seen
 * before, which means the whole automation hinges on matching a provider
 * address to a stored one.
 *
 * That matcher used to compare with a bidirectional substring test against an
 * un-canonicalized string, which failed on the most ordinary difference there
 * is (the provider writes "Ct", we stored "Court") while simultaneously
 * accepting "509 Amesbury Ct" as a match for "7509 Amesbury Ct". These tests
 * pin both halves: the variations that must resolve, and the near-misses that
 * must never resolve.
 *
 * The trigger tests pin that a floor-plan booking becomes iGUIDE-eligible
 * immediately rather than waiting on a raw upload, that repeating any trigger
 * is free, and that CubiCasa ordering is untouched by all of it.
 *
 * Every provider boundary is faked. No test here can create billable work.
 */
class IguideFloorPlanDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Addresses taken from the production shoots that carry an
     * iguide_property_id, plus the two historical properties supplied for
     * verification. The abbreviated form is how a provider commonly writes it.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function realAddressVariants(): array
    {
        return [
            'Court -> Ct (historical property 1)' => [
                '7509 Amesbury Court, Alexandria, VA 22315',
                '7509 Amesbury Ct, Alexandria, VA 22315',
            ],
            'Road -> Rd (historical property 2)' => [
                '196 Richards Ferry Road, Fredericksburg, VA 22406',
                '196 Richards Ferry Rd, Fredericksburg, VA 22406',
            ],
            'Street -> St with directional' => [
                '315 South Calhoun Street, Baltimore, MD 21223',
                '315 S Calhoun St, Baltimore, MD 21223',
            ],
            'Road -> Rd' => [
                '11700 Old Georgetown Road, Rockville, MD 20852',
                '11700 Old Georgetown Rd, Rockville, MD 20852',
            ],
            'Drive -> Dr' => [
                '12031 Angora Drive, King George, VA 22485',
                '12031 Angora Dr, King George, VA 22485',
            ],
            'Drive -> Dr (short house number)' => [
                '1013 Aquia Drive, Stafford, VA 22554',
                '1013 Aquia Dr, Stafford, VA 22554',
            ],
            'lowercase and no punctuation' => [
                '6275 Kerrydale Drive, Springfield, VA 22152',
                '6275 kerrydale dr springfield va 22152',
            ],
            'ZIP+4 from the provider' => [
                '14500 Kylewood Way, Gainesville, VA 20155',
                '14500 Kylewood Way, Gainesville, VA 20155-4321',
            ],
            'collapsed whitespace' => [
                '16527 Hampton Road, Hamilton, VA 20158',
                '16527   Hampton   Rd ,  Hamilton ,  VA   20158',
            ],
        ];
    }

    /**
     * Pairs that look similar but are different properties.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function nearMissAddresses(): array
    {
        return [
            'house number missing a leading digit' => [
                '7509 Amesbury Court, Alexandria, VA 22315',
                '509 Amesbury Court, Alexandria, VA 22315',
            ],
            'adjacent house number' => [
                '7509 Amesbury Court, Alexandria, VA 22315',
                '7511 Amesbury Court, Alexandria, VA 22315',
            ],
            'five-digit vs four-digit on the same street' => [
                '11700 Old Georgetown Road, Rockville, MD 20852',
                '1700 Old Georgetown Road, Rockville, MD 20852',
            ],
            'street with no house number at all' => [
                '7509 Amesbury Court, Alexandria, VA 22315',
                'Amesbury Court, Alexandria, VA 22315',
            ],
            'same street and number, different city' => [
                '7509 Amesbury Court, Alexandria, VA 22315',
                '7509 Amesbury Court, Springfield, VA 22152',
            ],
            'same street and number, different ZIP' => [
                '1013 Aquia Drive, Stafford, VA 22554',
                '1013 Aquia Drive, Stafford, VA 22555',
            ],
            'different street, same number and ZIP' => [
                '1013 Aquia Drive, Stafford, VA 22554',
                '1013 Brooke Drive, Stafford, VA 22554',
            ],
            'different declared unit' => [
                '100 Main Street Unit 4B, Reston, VA 20190',
                '100 Main Street Unit 9C, Reston, VA 20190',
            ],
        ];
    }

    // ------------------------------------------------------- address matching

    #[DataProvider('realAddressVariants')]
    public function test_a_provider_spelling_resolves_to_the_same_property(string $stored, string $fromProvider): void
    {
        $this->assertTrue(
            app(IguideService::class)->addressesMatch($fromProvider, $stored),
            "provider spelling must resolve:\n  stored:   {$stored}\n  provider: {$fromProvider}"
        );
    }

    #[DataProvider('nearMissAddresses')]
    public function test_a_different_property_never_resolves(string $stored, string $fromProvider): void
    {
        $this->assertFalse(
            app(IguideService::class)->addressesMatch($fromProvider, $stored),
            "these are different properties and must not match:\n  stored:   {$stored}\n  provider: {$fromProvider}"
        );
    }

    public function test_a_unit_declared_on_only_one_side_still_resolves(): void
    {
        // Providers routinely omit the unit. Treating that as a mismatch would
        // strand every condo booking.
        $service = app(IguideService::class);

        $this->assertTrue($service->addressesMatch(
            '100 Main St, Reston, VA 20190',
            '100 Main Street Unit 4B, Reston, VA 20190'
        ));
        $this->assertTrue($service->addressesMatch(
            '100 Main Street Apt 4B, Reston, VA 20190',
            '100 Main Street Unit 4B, Reston, VA 20190'
        ));
    }

    public function test_matching_requires_a_locality_and_not_just_a_street(): void
    {
        // The same street number exists in many towns, so a street line with no
        // city, state or ZIP on either side is not enough to attach anything.
        $this->assertFalse(
            app(IguideService::class)->addressesMatch('7509 Amesbury Ct', '7509 Amesbury Court')
        );
    }

    public function test_find_shoot_by_address_picks_the_right_shoot_among_neighbours(): void
    {
        $target = $this->shootWithFloorPlan([
            'address' => '7509 Amesbury Court',
            'city' => 'Alexandria',
            'state' => 'VA',
            'zip' => '22315',
        ]);

        // Neighbours that previously collided through substring matching.
        foreach (['509 Amesbury Court', '7511 Amesbury Court', '75091 Amesbury Court'] as $neighbour) {
            $this->shootWithFloorPlan([
                'address' => $neighbour,
                'city' => 'Alexandria',
                'state' => 'VA',
                'zip' => '22315',
            ]);
        }

        $found = app(IguideService::class)
            ->findShootByAddress('7509 Amesbury Ct, Alexandria, VA 22315');

        $this->assertNotNull($found, 'the abbreviated provider address must find a shoot');
        $this->assertSame($target->id, $found->id, 'must be the exact property, not a neighbour');
    }

    public function test_find_shoot_by_address_returns_nothing_for_an_unknown_property(): void
    {
        $this->shootWithFloorPlan([
            'address' => '7509 Amesbury Court',
            'city' => 'Alexandria',
            'state' => 'VA',
            'zip' => '22315',
        ]);

        $this->assertNull(
            app(IguideService::class)->findShootByAddress('8800 Braddock Rd, Annandale, VA 22003')
        );
    }

    // ------------------------------------------------ delayed provider arrival

    public function test_an_iguide_delivered_days_after_booking_attaches_by_abbreviated_address(): void
    {
        Queue::fake();

        // Booked with a plain floor-plan service and no provider identifiers:
        // exactly the state a new booking sits in while it waits for the shoot.
        $shoot = $this->shootWithFloorPlan([
            'address' => '7509 Amesbury Court',
            'city' => 'Alexandria',
            'state' => 'VA',
            'zip' => '22315',
            'iguide_property_id' => null,
            'iguide_work_order_id' => null,
            'iguide_tour_url' => null,
        ]);

        $this->travel(3)->days();

        // The provider writes the street type short and knows nothing about our ids.
        $response = $this->postJson('/iguide_webhook.php', [
            'type' => 'ready',
            'iguideId' => 'igDELAYED001',
            'workOrderId' => 'WO-NOT-OURS',
            'urls' => [
                'publicUrl' => 'https://youriguide.com/iguide-delayed/',
                'mediaUrls' => [
                    'en' => [
                        'pdfImperial' => 'https://youriguide.com/iguide-delayed/doc/floorplan_imperial.pdf',
                    ],
                ],
            ],
            'property' => [
                'fullAddress' => '7509 Amesbury Ct, Alexandria, VA 22315',
            ],
        ]);

        $response->assertOk()->assertJsonPath('success', true)->assertJsonPath('shoot_id', $shoot->id);

        $shoot->refresh();
        $this->assertSame('igDELAYED001', $shoot->iguide_property_id);
        $this->assertNotNull($shoot->iguide_tour_url);

        Queue::assertPushed(
            IngestIguideAssetsJob::class,
            fn ($job) => $job->shootId === $shoot->id && !empty($job->floorplans)
        );
    }

    public function test_an_iguide_for_a_neighbouring_property_is_not_attached(): void
    {
        Queue::fake();

        $shoot = $this->shootWithFloorPlan([
            'address' => '7509 Amesbury Court',
            'city' => 'Alexandria',
            'state' => 'VA',
            'zip' => '22315',
            'iguide_property_id' => null,
            'iguide_tour_url' => null,
        ]);

        $response = $this->postJson('/iguide_webhook.php', [
            'type' => 'ready',
            'iguideId' => 'igNEIGHBOUR001',
            'urls' => ['publicUrl' => 'https://youriguide.com/iguide-neighbour/'],
            'property' => ['fullAddress' => '7511 Amesbury Ct, Alexandria, VA 22315'],
        ]);

        // Accepted so the provider stops retrying, but attached to nothing.
        $response->assertOk();

        $shoot->refresh();
        $this->assertNull($shoot->iguide_property_id, 'a neighbour must never be attached');
        $this->assertNull($shoot->iguide_tour_url);
        Queue::assertNotPushed(IngestIguideAssetsJob::class);
    }

    // -------------------------------------------------------------- triggering

    public function test_a_floor_plan_booking_triggers_iguide_discovery_and_still_orders_cubicasa(): void
    {
        // Both providers stay in play. We cannot prove at booking time that no
        // iGUIDE exists for the address, so CubiCasa is never suppressed on the
        // strength of a negative lookup.
        Queue::fake();

        $shoot = $this->shootWithFloorPlan([
            'scheduled_at' => now()->addDays(3),
            'cubicasa_order_id' => null,
            'cubicasa_external_id' => null,
            'iguide_tour_url' => null,
        ]);

        $shoot->fresh()->forceFill(['scheduled_at' => now()->addDays(4)])->save();

        Queue::assertPushed(SyncShootIguideJob::class, fn ($job) => $job->shootId === $shoot->id);
        Queue::assertPushed(CreateCubiCasaOrderJob::class);
    }

    public function test_discovery_does_not_wait_for_a_raw_upload(): void
    {
        // An iGUIDE-only shoot has no raw camera intake at all, so gating
        // discovery on a raw submit would mean it never ran.
        Queue::fake();

        $shoot = $this->shoot(['scheduled_at' => now()->addDays(2), 'iguide_tour_url' => null]);
        $this->attach($shoot, $this->service([
            'name' => 'Premium iGuide',
            'category_id' => $this->category('3D/360 Tours')->id,
            'upload_intake_type' => Service::INTAKE_NONE,
            'requires_editing' => false,
        ]));

        $this->assertSame(0, (int) $shoot->fresh()->raw_photo_count);

        $shoot->fresh()->forceFill(['scheduled_at' => now()->addDays(3)])->save();

        Queue::assertPushed(SyncShootIguideJob::class, fn ($job) => $job->shootId === $shoot->id);
    }

    public function test_an_hdr_and_iguide_package_triggers_discovery_without_disturbing_the_raw_lane(): void
    {
        Queue::fake();

        $shoot = $this->shoot(['scheduled_at' => now()->addDays(2), 'iguide_tour_url' => null]);
        $this->attach($shoot, $this->service([
            'name' => 'HDR Photos & Premium iGuide',
            'category_id' => $this->category('Packages')->id,
            'upload_intake_type' => Service::INTAKE_PHOTO,
            'uses_hdr_brackets' => true,
            'photo_count' => 30,
            'requires_editing' => true,
        ]), 5);

        $shoot->fresh()->forceFill(['scheduled_at' => now()->addDays(3)])->save();

        Queue::assertPushed(SyncShootIguideJob::class);
    }

    public function test_a_photo_only_shoot_never_triggers_discovery(): void
    {
        Queue::fake();

        $shoot = $this->shoot(['scheduled_at' => now()->addDays(2)]);
        $this->attach($shoot, $this->service([
            'name' => '10 Exterior HDR Photos',
            'category_id' => $this->category('Photos')->id,
            'upload_intake_type' => Service::INTAKE_PHOTO,
            'uses_hdr_brackets' => true,
            'photo_count' => 10,
            'requires_editing' => true,
        ]), 5);

        $shoot->fresh()->forceFill(['scheduled_at' => now()->addDays(3)])->save();

        Queue::assertNotPushed(SyncShootIguideJob::class);
    }

    public function test_discovery_stops_once_a_tour_url_is_attached(): void
    {
        Queue::fake();

        $shoot = $this->shootWithFloorPlan([
            'scheduled_at' => now()->addDays(2),
            'iguide_tour_url' => 'https://youriguide.com/already-there/',
        ]);

        $shoot->fresh()->forceFill(['scheduled_at' => now()->addDays(3)])->save();

        Queue::assertNotPushed(SyncShootIguideJob::class);
    }

    public function test_a_cancelled_shoot_never_triggers_discovery(): void
    {
        Queue::fake();

        $shoot = $this->shootWithFloorPlan([
            'scheduled_at' => now()->addDays(2),
            'iguide_tour_url' => null,
        ]);

        $shoot->fresh()->forceFill([
            'status' => Shoot::STATUS_CANCELLED,
            'workflow_status' => Shoot::STATUS_CANCELLED,
        ])->save();

        Queue::assertNotPushed(SyncShootIguideJob::class);
    }

    // ------------------------------------------------------------ idempotency

    public function test_repeating_the_trigger_does_not_queue_duplicate_discovery(): void
    {
        // Booking, a lifecycle transition, the scheduler and a manual resync can
        // all fire for one shoot. Only one job may be in flight.
        Queue::fake();

        $shoot = $this->shootWithFloorPlan([
            'scheduled_at' => now()->addDays(2),
            'iguide_tour_url' => null,
        ]);

        $shoot->fresh()->forceFill(['scheduled_at' => now()->addDays(3)])->save();
        $shoot->fresh()->forceFill(['scheduled_at' => now()->addDays(4)])->save();
        SyncShootIguideJob::dispatch($shoot->id);

        Queue::assertPushed(SyncShootIguideJob::class, 1);
    }

    public function test_the_reconciliation_command_still_covers_a_floor_plan_shoot_in_window(): void
    {
        // The safety net has to reach a shoot booked days before the iGUIDE lands.
        Queue::fake();

        $shoot = $this->shootWithFloorPlan([
            'scheduled_date' => now()->subDays(3)->toDateString(),
            'iguide_tour_url' => null,
        ]);

        $this->artisan('iguide:resync-pending')->assertSuccessful();

        Queue::assertPushed(SyncShootIguideJob::class, fn ($job) => $job->shootId === $shoot->id);
    }

    public function test_the_reconciliation_command_skips_a_shoot_that_already_has_its_tour(): void
    {
        Queue::fake();

        $this->shootWithFloorPlan([
            'scheduled_date' => now()->subDays(3)->toDateString(),
            'iguide_tour_url' => 'https://youriguide.com/done/',
        ]);

        $this->artisan('iguide:resync-pending')->assertSuccessful();

        Queue::assertNotPushed(SyncShootIguideJob::class);
    }

    // ----------------------------------------------------------------- helpers

    private function category(string $name): Category
    {
        return Category::query()->firstOrCreate(['name' => $name]);
    }

    private function service(array $attributes): Service
    {
        return Service::query()->create(array_merge([
            'description' => 'Fixture',
            'price' => 100,
            'delivery_time' => 24,
            'pricing_type' => 'fixed',
        ], $attributes));
    }

    private function attach(Shoot $shoot, Service $service, ?int $bracketMode = null): ShootService
    {
        return ShootService::query()->create([
            'shoot_id' => $shoot->id,
            'service_id' => $service->id,
            'price' => 100,
            'quantity' => 1,
            'bracket_mode' => $bracketMode,
        ]);
    }

    private function shoot(array $attributes = []): Shoot
    {
        return Shoot::factory()->create(array_merge([
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ], $attributes));
    }

    private function floorPlanService(): Service
    {
        return $this->service([
            'name' => '2D Floor plans',
            'category_id' => $this->category('Floor Plans')->id,
            'upload_intake_type' => Service::INTAKE_NONE,
            'uses_hdr_brackets' => false,
            'photo_count' => null,
            'requires_editing' => false,
        ]);
    }

    /** A shoot booked with a plain floor-plan requirement and nothing iGuide-specific. */
    private function shootWithFloorPlan(array $attributes = []): Shoot
    {
        $shoot = $this->shoot($attributes);
        $this->attach($shoot, $this->floorPlanService());

        return $shoot->fresh();
    }
}
