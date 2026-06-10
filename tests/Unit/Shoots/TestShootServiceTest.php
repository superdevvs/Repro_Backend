<?php

namespace Tests\Unit\Shoots;

use App\Models\ServiceArea;
use App\Models\Shoot;
use App\Models\User;
use App\Services\ServiceAreaMatcher;
use App\Services\Shoots\ShootDateService;
use App\Services\Shoots\TestShoot\TestShootService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Focused unit coverage for {@see TestShootService} (task 10.4).
 *
 * The three scenarios verified mirror the design's promises for the simulator:
 *   - create() persists a `shoot_type = internal_test` shoot with the right
 *     region scope and a timezone-correct local calendar day.
 *   - eligiblePhotographers() delegates filtering to ServiceAreaMatcher.
 *   - assign() persists the photographer link on the Test_Shoot.
 */
class TestShootServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeService(): TestShootService
    {
        return new TestShootService(new ServiceAreaMatcher(), new ShootDateService());
    }

    /**
     * Build an in-memory photographer with its serviceAreas relation pre-set
     * (no DB round-trip needed for the matcher's pure filter logic).
     *
     * @param  list<array{kind: string, value: string}>  $areas
     */
    private function photographerWithAreas(int $id, array $areas): User
    {
        $user = new User();
        $user->id = $id;

        $serviceAreas = collect($areas)->map(function (array $area) {
            $serviceArea = new ServiceArea();
            $serviceArea->kind = $area['kind'];
            $serviceArea->value = $area['value'];

            return $serviceArea;
        });

        $user->setRelation('serviceAreas', $serviceAreas);

        return $user;
    }

    #[Test]
    public function create_persists_an_internal_test_shoot_with_the_region_scope_and_timezone_correct_local_day(): void
    {
        // 2026-03-16 03:30 UTC is 2026-03-15 23:30 in America/New_York (EDT,
        // UTC-4). The Test_Shoot's local calendar day must be the 15th —
        // never the UTC day (the 16th).
        $when = CarbonImmutable::parse('2026-03-16 03:30:00', 'UTC');

        $shoot = $this->makeService()->create(
            ['kind' => 'state', 'value' => 'NY'],
            $when,
            'America/New_York',
        );

        $this->assertTrue($shoot->exists, 'Test_Shoot should be persisted');

        $shoot->refresh();
        $this->assertSame(Shoot::SHOOT_TYPE_INTERNAL_TEST, $shoot->shoot_type);
        $this->assertSame('state', $shoot->service_area_kind);
        $this->assertSame('NY', $shoot->service_area_value);
        $this->assertSame('America/New_York', $shoot->timezone);
        $this->assertSame(Shoot::STATUS_SCHEDULED, $shoot->status);

        // scheduled_date is the local calendar day in the region timezone.
        $this->assertSame('2026-03-15', $shoot->scheduled_date->format('Y-m-d'));

        // scheduled_at is the absolute instant (still 03:30 UTC).
        $this->assertSame(
            '2026-03-16 03:30:00',
            $shoot->scheduled_at->copy()->setTimezone('UTC')->format('Y-m-d H:i:s'),
        );
    }

    #[Test]
    public function eligible_photographers_delegates_to_service_area_matcher(): void
    {
        $matching = $this->photographerWithAreas(1, [['kind' => 'state', 'value' => 'NY']]);
        $other    = $this->photographerWithAreas(2, [['kind' => 'state', 'value' => 'NJ']]);
        $alsoNY   = $this->photographerWithAreas(3, [
            ['kind' => 'state', 'value' => 'NY'],
            ['kind' => 'region', 'value' => 'Northeast'],
        ]);

        // Use an in-memory Test_Shoot so this test does not depend on
        // create() persistence — it isolates the eligibility delegation.
        $testShoot = new Shoot();
        $testShoot->service_area_kind = 'state';
        $testShoot->service_area_value = 'NY';

        $eligible = $this->makeService()->eligiblePhotographers(
            $testShoot,
            collect([$matching, $other, $alsoNY]),
        );

        $this->assertSame([1, 3], $eligible->pluck('id')->all());
    }

    #[Test]
    public function assign_sets_and_persists_the_photographer_id(): void
    {
        $shoot = $this->makeService()->create(
            ['kind' => 'region', 'value' => 'Northeast'],
            CarbonImmutable::parse('2026-04-01 14:00:00', 'America/New_York'),
            'America/New_York',
        );

        $photographer = User::factory()->photographer()->create();

        $this->makeService()->assign($shoot, $photographer);

        $shoot->refresh();
        $this->assertSame($photographer->id, (int) $shoot->photographer_id);
    }
}
