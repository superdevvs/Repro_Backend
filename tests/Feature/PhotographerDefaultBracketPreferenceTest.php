<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootService;
use App\Models\User;
use App\Services\Shoots\BracketModeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A photographer's default bracket size is a preference, not a rule.
 *
 * It seeds the execution value when a bracket-capable service is newly assigned to them,
 * and after that the assignment owns its own size. Changing the preference later must not
 * reach back and rewrite work that has already been pinned — otherwise editing a profile
 * would silently redefine how existing frames are read.
 */
class PhotographerDefaultBracketPreferenceTest extends TestCase
{
    use RefreshDatabase;

    private function bracketedService(string $name = 'Exterior HDR'): Service
    {
        return Service::query()->create([
            'name' => $name,
            'description' => $name,
            'price' => 100,
            'delivery_time' => 24,
            'category_id' => Category::query()->firstOrCreate(['name' => 'Photos'])->id,
            'pricing_type' => 'fixed',
            'photo_count' => 10,
            'uses_hdr_brackets' => true,
            'upload_intake_type' => Service::INTAKE_PHOTO,
        ]);
    }

    public function test_the_preference_defaults_to_unset_and_resolves_to_five(): void
    {
        $photographer = User::factory()->create(['role' => 'photographer']);

        $this->assertNull($photographer->default_bracket_mode);

        $shoot = Shoot::factory()->create([
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'bracket_mode' => null,
        ]);
        $item = ShootService::query()->create([
            'shoot_id' => $shoot->id,
            'service_id' => $this->bracketedService()->id,
            'price' => 100,
            'quantity' => 1,
            'photographer_id' => $photographer->id,
        ]);

        // 5x remains the product default when nothing states otherwise.
        $this->assertSame(
            BracketModeResolver::DEFAULT_BRACKET_MODE,
            app(BracketModeResolver::class)->effectiveBracketMode($item->load('service'))
        );
    }

    public function test_a_photographer_can_save_their_own_default_through_the_profile_endpoint(): void
    {
        $photographer = User::factory()->create(['role' => 'photographer']);
        Sanctum::actingAs($photographer);

        $this->putJson('/api/profile', [
            'name' => $photographer->name,
            'default_bracket_mode' => 3,
        ])->assertOk();

        $this->assertSame(3, (int) $photographer->fresh()->default_bracket_mode);
    }

    public function test_only_three_and_five_are_accepted_by_the_profile_endpoint(): void
    {
        $photographer = User::factory()->create(['role' => 'photographer', 'default_bracket_mode' => 5]);
        Sanctum::actingAs($photographer);

        $this->putJson('/api/profile', [
            'name' => $photographer->name,
            'default_bracket_mode' => 4,
        ])->assertStatus(422);

        $this->assertSame(5, (int) $photographer->fresh()->default_bracket_mode);
    }

    public function test_an_admin_can_set_a_photographer_default_through_the_admin_endpoint(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $photographer = User::factory()->create(['role' => 'photographer']);

        $this->putJson('/api/admin/users/'.$photographer->id, [
            'name' => $photographer->name,
            'email' => $photographer->email,
            'default_bracket_mode' => 3,
        ])->assertOk();

        $this->assertSame(3, (int) $photographer->fresh()->default_bracket_mode);
    }

    public function test_the_preference_seeds_a_new_assignment_but_never_rewrites_an_existing_one(): void
    {
        $photographer = User::factory()->create(['role' => 'photographer', 'default_bracket_mode' => 3]);
        $shoot = Shoot::factory()->create([
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'bracket_mode' => null,
        ]);

        $item = ShootService::query()->create([
            'shoot_id' => $shoot->id,
            'service_id' => $this->bracketedService()->id,
            'price' => 100,
            'quantity' => 1,
        ]);

        $resolver = app(BracketModeResolver::class);

        // Assigning them seeds the execution row from the preference.
        $shoot->assignPhotographerToService($item->service_id, $photographer->id);
        $resolver->snapshotOnAssignment($item->refresh()->load('service'), $photographer->id);
        $this->assertSame(3, (int) $item->fresh()->bracket_mode);

        // Later profile change: the recorded execution value must not move.
        Sanctum::actingAs($photographer);
        $this->putJson('/api/profile', [
            'name' => $photographer->name,
            'default_bracket_mode' => 5,
        ])->assertOk();

        $this->assertSame(5, (int) $photographer->fresh()->default_bracket_mode);
        $this->assertSame(
            3,
            (int) $item->fresh()->bracket_mode,
            'an existing assignment keeps the size it was pinned at'
        );
        $this->assertSame(
            3,
            $resolver->effectiveBracketMode($item->fresh()->load('service')),
            'and stacking still resolves to that pinned size'
        );
    }

    public function test_the_preference_is_ignored_for_work_that_does_not_bracket(): void
    {
        $photographer = User::factory()->create(['role' => 'photographer', 'default_bracket_mode' => 3]);
        $shoot = Shoot::factory()->create([
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ]);

        $drone = Service::query()->create([
            'name' => '10-12 Drone Photos Package',
            'description' => 'Drone',
            'price' => 199,
            'delivery_time' => 24,
            'category_id' => Category::query()->firstOrCreate(['name' => 'Drone'])->id,
            'pricing_type' => 'fixed',
            'photo_count' => 10,
            'uses_hdr_brackets' => false,
            'upload_intake_type' => Service::INTAKE_PHOTO,
        ]);

        $item = ShootService::query()->create([
            'shoot_id' => $shoot->id,
            'service_id' => $drone->id,
            'price' => 100,
            'quantity' => 1,
            'photographer_id' => $photographer->id,
        ]);

        $this->assertNull(app(BracketModeResolver::class)->effectiveBracketMode($item->load('service')));
        $this->assertNull($item->fresh()->bracket_mode);
    }
}
