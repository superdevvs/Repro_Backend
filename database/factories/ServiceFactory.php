<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->words(3, true),
            'description' => $this->faker->sentence(),
            'price' => $this->faker->randomFloat(2, 100, 1000),
            'delivery_time' => $this->faker->numberBetween(24, 168),
            'category_id' => Category::factory(),
        ];
    }
}
