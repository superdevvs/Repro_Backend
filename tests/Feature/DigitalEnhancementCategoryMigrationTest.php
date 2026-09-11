<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DigitalEnhancementCategoryMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_qualifying_digital_services_move_into_digital_enhancements(): void
    {
        $addons = Category::factory()->create(['name' => 'Addons']);
        $virtualStaging = Category::factory()->create(['name' => 'Virtual Staging']);
        $digital = Category::factory()->create(['name' => 'Digital Enhancements']);

        $staging = Service::factory()->noIntake()->create([
            'id' => 18,
            'name' => 'Virtual Staging (per image)',
            'category_id' => $virtualStaging->id,
            'photographer_required' => false,
            'requires_editing' => false,
        ]);
        $grass = Service::factory()->noIntake()->create([
            'id' => 51,
            'name' => 'Green Grass Enhancement',
            'category_id' => $addons->id,
            'photographer_required' => false,
            'requires_editing' => true,
        ]);
        $tv = Service::factory()->noIntake()->create([
            'id' => 65,
            'name' => 'TV imaging',
            'category_id' => $digital->id,
            'photographer_required' => false,
            'requires_editing' => true,
        ]);

        $migration = require database_path('migrations/2026_09_11_180000_move_digital_enhancements_into_category.php');
        $migration->up();

        $this->assertSame($digital->id, $staging->fresh()->category_id);
        $this->assertTrue((bool) $staging->fresh()->requires_editing);
        $this->assertSame($digital->id, $grass->fresh()->category_id);
        $this->assertSame($digital->id, $tv->fresh()->category_id);
        $this->assertFalse((bool) $staging->fresh()->photographer_required);
    }
}
