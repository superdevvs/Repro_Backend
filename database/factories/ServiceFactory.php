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
            'exclude_from_sales_commission' => false,
            // The column default is `none` so that an unclassified production
            // catalogue row is never silently selectable. Test fixtures overwhelmingly
            // model photo capture, so the factory opts into the photo lane and the
            // states below cover the other capabilities explicitly.
            'upload_intake_type' => Service::INTAKE_PHOTO,
        ];
    }

    /** Photo capture whose raws arrive as bracketed exposure stacks. */
    public function bracketedPhoto(?int $photoCount = null): static
    {
        return $this->state(fn () => array_filter([
            'upload_intake_type' => Service::INTAKE_PHOTO,
            'uses_hdr_brackets' => true,
            'photo_count' => $photoCount,
        ], fn ($value) => $value !== null));
    }

    /** Photo capture that is not exposure-stacked, such as drone or flash. */
    public function unbracketedPhoto(?int $photoCount = null): static
    {
        return $this->state(fn () => array_filter([
            'upload_intake_type' => Service::INTAKE_PHOTO,
            'uses_hdr_brackets' => false,
            'photo_count' => $photoCount,
        ], fn ($value) => $value !== null));
    }

    public function videoIntake(): static
    {
        return $this->state(fn () => [
            'upload_intake_type' => Service::INTAKE_VIDEO,
            'uses_hdr_brackets' => false,
        ]);
    }

    /** One execution row serving both raw lanes, as the bundled products do. */
    public function photoVideoIntake(bool $brackets = true, ?int $photoCount = null): static
    {
        return $this->state(fn () => array_filter([
            'upload_intake_type' => Service::INTAKE_PHOTO_VIDEO,
            'uses_hdr_brackets' => $brackets,
            'photo_count' => $photoCount,
        ], fn ($value) => $value !== null));
    }

    /** Fees, travel, enhancements and dedicated tour products. */
    public function noIntake(): static
    {
        return $this->state(fn () => [
            'upload_intake_type' => Service::INTAKE_NONE,
            'uses_hdr_brackets' => false,
        ]);
    }
}
