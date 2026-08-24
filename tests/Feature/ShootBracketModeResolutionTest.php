<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootService;
use App\Models\User;
use App\Services\Shoots\Actions\ChangeServiceBracketModeAction;
use App\Services\Shoots\BracketModeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Bracket size is execution state, not a catalogue property and not a property of
 * the whole shoot.
 *
 * One shoot can be Exterior HDR by photographer A at 5x and Interior HDR by
 * photographer B at 3x, so the size lives on the shoot-service assignment. What a
 * photographer prefers only seeds a new assignment; changing that preference later
 * must not reach back into shoots already assigned. Whether a deliverable brackets
 * at all is catalogue data, so drone photography does not bracket even though it
 * sits in the Photography category with a photo count of its own.
 */
class ShootBracketModeResolutionTest extends TestCase
{
    use RefreshDatabase;

    private BracketModeResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        BracketModeResolver::flushColumnCache();
        $this->resolver = app(BracketModeResolver::class);
    }

    private function shoot(?int $legacyBracketMode = null): Shoot
    {
        return Shoot::factory()->create([
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'bracket_mode' => $legacyBracketMode,
        ]);
    }

    /**
     * Capability is declared, never inferred. Photo intake is the default because most
     * fixtures model photo capture; work that is not an upload target at all (floor
     * plans, tours) passes Service::INTAKE_NONE explicitly.
     */
    private function service(
        string $name,
        int $photoCount,
        bool $usesHdrBrackets,
        string $intake = Service::INTAKE_PHOTO,
    ): Service {
        $category = Category::query()->firstOrCreate(['name' => 'Photography']);

        return Service::query()->create([
            'name' => $name,
            'description' => $name,
            'price' => 100,
            'delivery_time' => 24,
            'category_id' => $category->id,
            'photo_count' => $photoCount,
            'uses_hdr_brackets' => $usesHdrBrackets,
            'upload_intake_type' => $intake,
            'pricing_type' => 'fixed',
        ]);
    }

    private function item(
        Shoot $shoot,
        Service $service,
        ?int $bracketMode = null,
        ?int $photographerId = null,
    ): ShootService {
        return ShootService::query()->create([
            'shoot_id' => $shoot->id,
            'service_id' => $service->id,
            'price' => 100,
            'quantity' => 1,
            'bracket_mode' => $bracketMode,
            'photographer_id' => $photographerId,
        ]);
    }

    private function photographer(?int $preference): User
    {
        return User::factory()->create([
            'role' => 'photographer',
            'default_bracket_mode' => $preference,
        ]);
    }

    private function rawFileFor(ShootService $item, string $filename = 'raw-1.jpg'): ShootFile
    {
        return ShootFile::create([
            'shoot_id' => $item->shoot_id,
            'shoot_service_id' => $item->id,
            'filename' => $filename,
            'stored_filename' => $filename,
            'path' => 'shoots/'.$item->shoot_id.'/todo/'.$filename,
            'file_type' => 'image/jpeg',
            'file_size' => 1024,
            'media_type' => 'raw',
            'uploaded_by' => User::factory()->create(['role' => 'admin'])->id,
            'workflow_stage' => ShootFile::STAGE_TODO,
        ]);
    }

    public function test_the_schema_carries_bracket_state_at_the_three_intended_levels(): void
    {
        // Catalogue says whether, the photographer states a preference, and the
        // assignment records what was actually shot.
        $this->assertTrue(Schema::hasColumn('services', 'uses_hdr_brackets'));
        $this->assertTrue(Schema::hasColumn('users', 'default_bracket_mode'));
        $this->assertTrue(Schema::hasColumn('shoot_service', 'bracket_mode'));
    }

    public function test_a_service_that_does_not_bracket_has_no_size_at_all(): void
    {
        $shoot = $this->shoot(5);
        // Drone: Photography category, a real photo count, and no exposure stacking.
        $drone = $this->item($shoot, $this->service('Aerial Drone Photos', 10, false), 5);

        // Null rather than 5, even though the item carries a value and the legacy
        // shoot says 5. Nothing about a non-bracket deliverable has a stack size.
        $this->assertNull($this->resolver->effectiveBracketMode($drone));
        // And it owes one raw per final photo, never multiplied.
        $this->assertSame(10, $this->resolver->expectedRawForService($drone));
    }

    public function test_the_assignment_value_wins_over_the_photographers_preference(): void
    {
        $shoot = $this->shoot();
        $photographer = $this->photographer(3);
        $item = $this->item($shoot, $this->service('Exterior HDR', 30, true), 5, $photographer->id);

        $this->assertSame(5, $this->resolver->effectiveBracketMode($item));
    }

    public function test_an_unpinned_assignment_falls_back_through_preference_then_legacy_then_five(): void
    {
        $preferring3 = $this->photographer(3);
        $noPreference = $this->photographer(null);

        // Preference decides when the assignment has no recorded size.
        $withPreference = $this->item($this->shoot(), $this->service('A', 30, true), null, $preferring3->id);
        $this->assertSame(3, $this->resolver->effectiveBracketMode($withPreference));

        // A legacy shoot with only shoots.bracket_mode = 3 still resolves 3.
        $legacyThree = $this->item($this->shoot(3), $this->service('B', 30, true), null, $noPreference->id);
        $this->assertSame(3, $this->resolver->effectiveBracketMode($legacyThree));

        // Legacy NULL resolves to the product default.
        $legacyNull = $this->item($this->shoot(null), $this->service('C', 30, true), null, $noPreference->id);
        $this->assertSame(5, $this->resolver->effectiveBracketMode($legacyNull));

        // No photographer at all still resolves.
        $unassigned = $this->item($this->shoot(null), $this->service('D', 30, true));
        $this->assertSame(5, $this->resolver->effectiveBracketMode($unassigned));
    }

    public function test_only_three_and_five_are_accepted_as_sizes(): void
    {
        $this->assertSame(3, $this->resolver->normalize(3));
        $this->assertSame(5, $this->resolver->normalize('5'));
        $this->assertNull($this->resolver->normalize(7));
        $this->assertNull($this->resolver->normalize(1));
        $this->assertNull($this->resolver->normalize(0));
        $this->assertNull($this->resolver->normalize(null));

        // A stored value outside the allowed set is not a size, so resolution falls
        // through to the default rather than stacking in sevens.
        $item = $this->item($this->shoot(7), $this->service('A', 30, true), null);
        $this->assertSame(5, $this->resolver->effectiveBracketMode($item));
    }

    public function test_changing_a_photographer_preference_does_not_move_an_existing_snapshot(): void
    {
        $shoot = $this->shoot();
        $photographer = $this->photographer(5);
        $service = $this->service('Exterior HDR', 30, true);

        // Assignment snapshots the preference of the day.
        $item = $this->item($shoot, $service);
        $shoot->assignPhotographerToService($service->id, $photographer->id);
        $item->refresh();
        $this->assertSame(5, $item->bracket_mode);

        // The photographer later changes how they like to shoot.
        $photographer->update(['default_bracket_mode' => 3]);

        // The shoot they were already assigned to is untouched: it records what was
        // agreed for that execution, not a live pointer at a profile setting.
        $this->assertSame(5, $item->refresh()->bracket_mode);
        $this->assertSame(5, $this->resolver->effectiveBracketMode($item->fresh()));
    }

    public function test_reassigning_before_any_raws_initialises_a_new_snapshot(): void
    {
        $shoot = $this->shoot();
        $service = $this->service('Exterior HDR', 30, true);
        $item = $this->item($shoot, $service);

        $shootsAt5 = $this->photographer(5);
        $shootsAt3 = $this->photographer(3);

        $shoot->assignPhotographerToService($service->id, $shootsAt5->id);
        $this->assertSame(5, $item->refresh()->bracket_mode);

        // Nothing has been shot yet, so handing the service to someone who works at
        // 3x can safely adopt their size.
        $shoot->assignPhotographerToService($service->id, $shootsAt3->id);
        $this->assertSame(3, $item->refresh()->bracket_mode);
    }

    public function test_reassigning_after_raws_exist_preserves_the_existing_divisor(): void
    {
        $shoot = $this->shoot();
        $service = $this->service('Exterior HDR', 30, true);
        $item = $this->item($shoot, $service);

        $shootsAt5 = $this->photographer(5);
        $shootsAt3 = $this->photographer(3);

        $shoot->assignPhotographerToService($service->id, $shootsAt5->id);
        $this->assertSame(5, $item->refresh()->bracket_mode);

        // Frames exist and are already stacked in fives.
        $this->rawFileFor($item->refresh());

        // Reassigning must not silently re-cut them into threes. That has to be a
        // deliberate Change & Restack.
        $shoot->assignPhotographerToService($service->id, $shootsAt3->id);
        $item->refresh();
        $this->assertSame(5, $item->bracket_mode);
        $this->assertSame((string) $shootsAt3->id, (string) $item->photographer_id);
    }

    public function test_expected_raw_count_sums_each_service_at_its_own_size(): void
    {
        $shoot = $this->shoot();
        // The requirement's arithmetic: 30 finals at 5x plus 12 finals at 3x.
        $this->item($shoot, $this->service('HDR Photography', 30, true), 5);
        $this->item($shoot, $this->service('Twilight Photography', 12, true), 3);
        // Non-intake deliverables contribute nothing: they are not upload targets, so
        // they owe no raws regardless of any count on the catalogue row.
        $this->item($shoot, $this->service('2D Floor Plan', 0, false, Service::INTAKE_NONE));
        $this->item($shoot, $this->service('Virtual Tour (3D)', 0, false, Service::INTAKE_NONE));

        $this->assertSame(186, $this->resolver->expectedRawForShoot($shoot->fresh()));

        // And it is not any single shoot-wide multiplication.
        $this->assertNotSame(42 * 5, $this->resolver->expectedRawForShoot($shoot->fresh()));
        $this->assertNotSame(42 * 3, $this->resolver->expectedRawForShoot($shoot->fresh()));
    }

    public function test_expected_raw_count_does_not_multiply_non_bracket_photo_work(): void
    {
        $shoot = $this->shoot();
        $this->item($shoot, $this->service('HDR Photography', 30, true), 5);
        // Drone has 10 photos and does not bracket, so it adds 10, not 50.
        $this->item($shoot, $this->service('Aerial Drone Photos', 10, false));

        $this->assertSame(160, $this->resolver->expectedRawForShoot($shoot->fresh()));
    }

    public function test_change_and_restack_moves_one_service_and_leaves_the_others_alone(): void
    {
        $shoot = $this->shoot();
        $exteriorService = $this->service('Exterior HDR', 30, true);
        $interiorService = $this->service('Interior HDR', 12, true);
        $exterior = $this->item($shoot, $exteriorService, 5);
        $interior = $this->item($shoot, $interiorService, 3);

        $result = app(ChangeServiceBracketModeAction::class)->execute($exterior->fresh(), 3);

        $this->assertSame(5, $result['previous_bracket_mode']);
        $this->assertSame(3, $result['bracket_mode']);
        $this->assertSame(3, $result['effective_bracket_mode']);
        $this->assertTrue($result['restacked']);
        $this->assertSame((int) $exterior->id, $result['shoot_service_id']);

        // The other photographer's service is untouched.
        $this->assertSame(3, $interior->refresh()->bracket_mode);
        $this->assertSame(3, $exterior->refresh()->bracket_mode);
    }

    public function test_change_and_restack_refuses_a_service_that_does_not_bracket(): void
    {
        $shoot = $this->shoot();
        $floorPlan = $this->item($shoot, $this->service('2D Floor Plan', 0, false));

        $this->expectException(ValidationException::class);
        app(ChangeServiceBracketModeAction::class)->execute($floorPlan, 5);
    }

    public function test_change_and_restack_refuses_a_size_the_product_does_not_offer(): void
    {
        $shoot = $this->shoot();
        $exterior = $this->item($shoot, $this->service('Exterior HDR', 30, true), 5);

        $this->expectException(ValidationException::class);
        app(ChangeServiceBracketModeAction::class)->execute($exterior, 7);
    }

    public function test_a_mixed_mode_shoot_matches_both_bracket_filters(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $mixed = $this->shoot();
        $this->item($mixed, $this->service('Exterior HDR', 30, true), 5);
        $this->item($mixed, $this->service('Interior HDR', 12, true), 3);

        $onlyFive = $this->shoot();
        $this->item($onlyFive, $this->service('Exterior Only', 30, true), 5);

        $noBrackets = $this->shoot();
        $this->item($noBrackets, $this->service('Floor Plan Only', 0, false));

        // A shoot holding both sizes is honestly described by both filters, because
        // the question is "does this shoot have work shot at Nx", and it has both.
        $atFive = $this->actingAs($admin)->getJson('/api/shoots?bracket=5&per_page=100');
        $atFive->assertOk();
        $fiveIds = collect($atFive->json('data'))->pluck('id')->map(fn ($id) => (int) $id);
        $this->assertTrue($fiveIds->contains($mixed->id), 'mixed shoot should match bracket=5');
        $this->assertTrue($fiveIds->contains($onlyFive->id));
        $this->assertFalse($fiveIds->contains($noBrackets->id));

        $atThree = $this->actingAs($admin)->getJson('/api/shoots?bracket=3&per_page=100');
        $atThree->assertOk();
        $threeIds = collect($atThree->json('data'))->pluck('id')->map(fn ($id) => (int) $id);
        $this->assertTrue($threeIds->contains($mixed->id), 'mixed shoot should match bracket=3');
        $this->assertFalse($threeIds->contains($onlyFive->id));
        $this->assertFalse($threeIds->contains($noBrackets->id));

        // "none" now means no bracket-enabled services, not a null shoot column.
        $none = $this->actingAs($admin)->getJson('/api/shoots?bracket=none&per_page=100');
        $none->assertOk();
        $noneIds = collect($none->json('data'))->pluck('id')->map(fn ($id) => (int) $id);
        $this->assertTrue($noneIds->contains($noBrackets->id));
        $this->assertFalse($noneIds->contains($mixed->id));
        $this->assertFalse($noneIds->contains($onlyFive->id));
    }

    public function test_the_bracket_filter_resolves_an_unpinned_service_the_same_way_the_resolver_does(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // No recorded size; the assigned photographer works at 3x.
        $byPreference = $this->shoot();
        $this->item($byPreference, $this->service('Pref HDR', 30, true), null, $this->photographer(3)->id);

        // No recorded size, no preference, legacy shoot says 3.
        $byLegacy = $this->shoot(3);
        $this->item($byLegacy, $this->service('Legacy HDR', 30, true), null, $this->photographer(null)->id);

        // Nothing states anything, so the default applies.
        $byDefault = $this->shoot();
        $this->item($byDefault, $this->service('Default HDR', 30, true));

        $threeIds = collect($this->actingAs($admin)->getJson('/api/shoots?bracket=3&per_page=100')->json('data'))
            ->pluck('id')->map(fn ($id) => (int) $id);
        $this->assertTrue($threeIds->contains($byPreference->id));
        $this->assertTrue($threeIds->contains($byLegacy->id));
        $this->assertFalse($threeIds->contains($byDefault->id));

        $fiveIds = collect($this->actingAs($admin)->getJson('/api/shoots?bracket=5&per_page=100')->json('data'))
            ->pluck('id')->map(fn ($id) => (int) $id);
        $this->assertTrue($fiveIds->contains($byDefault->id));
        $this->assertFalse($fiveIds->contains($byPreference->id));
        $this->assertFalse($fiveIds->contains($byLegacy->id));
    }

    public function test_the_service_item_payload_carries_per_service_bracket_state(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = $this->shoot();
        $this->item($shoot, $this->service('HDR Photography', 30, true), 5);
        $this->item($shoot, $this->service('Twilight Photography', 12, true), 3);
        $this->item($shoot, $this->service('2D Floor Plan', 0, false, Service::INTAKE_NONE));

        $response = $this->actingAs($admin)->getJson('/api/shoots/'.$shoot->id);
        $response->assertOk();

        $services = collect($response->json('data.services') ?? $response->json('services') ?? [])
            ->keyBy('name');

        $this->assertTrue((bool) $services['HDR Photography']['uses_hdr_brackets']);
        $this->assertSame(5, (int) $services['HDR Photography']['effective_bracket_mode']);
        $this->assertSame(150, (int) $services['HDR Photography']['expected_raw_count']);

        $this->assertTrue((bool) $services['Twilight Photography']['uses_hdr_brackets']);
        $this->assertSame(3, (int) $services['Twilight Photography']['effective_bracket_mode']);
        $this->assertSame(36, (int) $services['Twilight Photography']['expected_raw_count']);

        // The one that does not bracket says so, and offers no size.
        $this->assertFalse((bool) $services['2D Floor Plan']['uses_hdr_brackets']);
        $this->assertNull($services['2D Floor Plan']['effective_bracket_mode']);

        // It is also not an upload target, so it owes nothing rather than being
        // reported as an unknown quantity.
        $this->assertSame(Service::INTAKE_NONE, $services['2D Floor Plan']['upload_intake_type']);
        $this->assertFalse((bool) $services['2D Floor Plan']['supports_photo_intake']);
        $this->assertSame(0, (int) $services['2D Floor Plan']['expected_raw_count']);
        $this->assertFalse((bool) $services['2D Floor Plan']['expected_raw_unspecified']);
    }
}
