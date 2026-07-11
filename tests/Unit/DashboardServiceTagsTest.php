<?php

namespace Tests\Unit;

use App\Http\Controllers\API\DashboardController;
use App\Models\Service;
use App\Models\Shoot;
use Illuminate\Support\Collection;
use ReflectionMethod;
use Tests\TestCase;

class DashboardServiceTagsTest extends TestCase
{
    private function tagsFor(Shoot $shoot): array
    {
        $method = new ReflectionMethod(DashboardController::class, 'buildServiceTags');
        $method->setAccessible(true);

        return $method->invoke(new DashboardController(), $shoot);
    }

    public function test_it_returns_all_booked_services_and_deduplicates_them(): void
    {
        $photos = new Service(['name' => 'HDR Photos', 'icon' => 'camera']);
        $photos->id = 10;
        $video = new Service(['name' => 'Property Video', 'icon' => 'film']);
        $video->id = 20;

        $shoot = new Shoot();
        $shoot->setRelation('services', new Collection([$photos, $video, $photos]));

        $this->assertSame([
            ['label' => 'HDR Photos', 'type' => 'service_10', 'icon' => 'camera'],
            ['label' => 'Property Video', 'type' => 'service_20', 'icon' => 'film'],
        ], $this->tagsFor($shoot));
    }

    public function test_it_falls_back_to_the_legacy_service(): void
    {
        $legacy = new Service(['name' => 'Legacy Photos', 'icon' => 'camera']);
        $legacy->id = 30;

        $shoot = new Shoot();
        $shoot->setRelation('services', new Collection());
        $shoot->setRelation('service', $legacy);

        $this->assertSame([
            ['label' => 'Legacy Photos', 'type' => 'service_30', 'icon' => 'camera'],
        ], $this->tagsFor($shoot));
    }
}
