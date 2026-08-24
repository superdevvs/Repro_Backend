<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootService;
use App\Models\User;
use App\Services\Shoots\BracketModeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The bracket picker has to write to the execution row, not just to React state.
 *
 * Before this endpoint existed the 3x/5x control only held local state: a size the user
 * chose was never durable and vanished on reload, so the UI could disagree with what
 * stacking actually used. Persisting it is only half the requirement though — changing
 * the size once raws exist re-cuts that service's stacks, so that has to be a deliberate
 * "Change & Restack this service" rather than a silent reinterpretation of frames that
 * are already numbered.
 */
class ServiceBracketModePersistenceTest extends TestCase
{
    use RefreshDatabase;

    private Shoot $shoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shoot = Shoot::factory()->create([
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'bracket_mode' => 5,
        ]);
    }

    private function service(string $name, bool $brackets = true, string $intake = Service::INTAKE_PHOTO): Service
    {
        return Service::query()->create([
            'name' => $name,
            'description' => $name,
            'price' => 100,
            'delivery_time' => 24,
            'category_id' => Category::query()->firstOrCreate(['name' => 'Photos'])->id,
            'pricing_type' => 'fixed',
            'photo_count' => 10,
            'uses_hdr_brackets' => $brackets,
            'upload_intake_type' => $intake,
        ]);
    }

    private function item(Service $service, ?int $bracketMode = null, ?int $photographerId = null): ShootService
    {
        return ShootService::query()->create([
            'shoot_id' => $this->shoot->id,
            'service_id' => $service->id,
            'price' => 100,
            'quantity' => 1,
            'bracket_mode' => $bracketMode,
            'photographer_id' => $photographerId,
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

    private function endpoint(ShootService $item): string
    {
        return '/api/shoots/'.$this->shoot->id.'/service-items/'.$item->id.'/bracket-mode';
    }

    public function test_an_admin_can_persist_a_bracket_size_before_any_raws_exist(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $item = $this->item($this->service('Exterior HDR'), 5);

        $this->patchJson($this->endpoint($item), ['bracket_mode' => 3])
            ->assertOk()
            ->assertJsonPath('shoot_service_id', (int) $item->id)
            ->assertJsonPath('previous_bracket_mode', 5)
            ->assertJsonPath('bracket_mode', 3)
            ->assertJsonPath('effective_bracket_mode', 3)
            ->assertJsonPath('had_raw_files', false);

        // Durable on the execution row, which is the whole point.
        $this->assertSame(3, (int) $item->fresh()->bracket_mode);
    }

    public function test_changing_the_size_with_existing_raws_is_refused_until_restack_is_confirmed(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $item = $this->item($this->service('Exterior HDR'), 5);
        $this->rawFileFor($item);

        $this->patchJson($this->endpoint($item), ['bracket_mode' => 3])
            ->assertStatus(409)
            ->assertJsonPath('error_type', 'restack_required')
            ->assertJsonPath('had_raw_files', true);

        // Nothing moved: frames already numbered at 5x are not silently reinterpreted.
        $this->assertSame(5, (int) $item->fresh()->bracket_mode);
    }

    public function test_confirming_change_and_restack_moves_the_size_and_recuts_that_service_only(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $exterior = $this->item($this->service('Exterior HDR'), 5);
        $twilight = $this->item($this->service('Twilight HDR'), 3);
        $this->rawFileFor($exterior, 'ext-1.jpg');
        $this->rawFileFor($twilight, 'twi-1.jpg');

        $this->patchJson($this->endpoint($exterior), ['bracket_mode' => 3, 'restack' => true])
            ->assertOk()
            ->assertJsonPath('bracket_mode', 3)
            ->assertJsonPath('had_raw_files', true)
            ->assertJsonPath('restacked', true);

        $this->assertSame(3, (int) $exterior->fresh()->bracket_mode);
        // The other photographer's service kept its own size.
        $this->assertSame(3, (int) $twilight->fresh()->bracket_mode);
        $this->assertSame(
            3,
            app(BracketModeResolver::class)->effectiveBracketMode($twilight->fresh()->load('service')),
            'restacking one service must not touch another'
        );
    }

    public function test_a_service_that_does_not_bracket_cannot_be_given_a_size(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $drone = $this->item($this->service('10-12 Drone Photos Package', false), null);

        $this->patchJson($this->endpoint($drone), ['bracket_mode' => 5])
            ->assertStatus(422)
            ->assertJsonPath('error_type', 'invalid_bracket_mode');

        $this->assertNull($drone->fresh()->bracket_mode);
    }

    public function test_only_three_and_five_are_accepted(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $item = $this->item($this->service('Exterior HDR'), 5);

        $this->patchJson($this->endpoint($item), ['bracket_mode' => 7])->assertStatus(422);
        $this->assertSame(5, (int) $item->fresh()->bracket_mode);
    }

    public function test_a_photographer_can_set_their_own_assignment_but_not_someone_elses(): void
    {
        $mine = User::factory()->create(['role' => 'photographer']);
        $theirs = User::factory()->create(['role' => 'photographer']);
        $this->shoot->update(['photographer_id' => $mine->id]);

        $ownItem = $this->item($this->service('Exterior HDR'), 5, $mine->id);
        $otherItem = $this->item($this->service('Twilight HDR'), 5, $theirs->id);

        Sanctum::actingAs($mine);

        $this->patchJson($this->endpoint($ownItem), ['bracket_mode' => 3])->assertOk();
        $this->assertSame(3, (int) $ownItem->fresh()->bracket_mode);

        $this->patchJson($this->endpoint($otherItem), ['bracket_mode' => 3])
            ->assertStatus(403)
            ->assertJsonPath('error_type', 'forbidden');
        $this->assertSame(5, (int) $otherItem->fresh()->bracket_mode);
    }

    public function test_an_execution_row_from_another_shoot_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $otherShoot = Shoot::factory()->create([
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ]);
        $foreign = ShootService::query()->create([
            'shoot_id' => $otherShoot->id,
            'service_id' => $this->service('Exterior HDR')->id,
            'price' => 100,
            'quantity' => 1,
            'bracket_mode' => 5,
        ]);

        $this->patchJson($this->endpoint($foreign), ['bracket_mode' => 3])
            ->assertStatus(422)
            ->assertJsonPath('error_type', 'invalid_service_item');

        $this->assertSame(5, (int) $foreign->fresh()->bracket_mode);
    }

    public function test_a_client_cannot_change_a_bracket_size(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'client']));
        $item = $this->item($this->service('Exterior HDR'), 5);

        $this->patchJson($this->endpoint($item), ['bracket_mode' => 3])->assertStatus(403);
        $this->assertSame(5, (int) $item->fresh()->bracket_mode);
    }

    public function test_clearing_the_size_falls_back_to_the_photographer_preference(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $photographer = User::factory()->create([
            'role' => 'photographer',
            'default_bracket_mode' => 3,
        ]);
        $item = $this->item($this->service('Exterior HDR'), 5, $photographer->id);

        $this->patchJson($this->endpoint($item), ['bracket_mode' => null])
            ->assertOk()
            ->assertJsonPath('bracket_mode', null)
            // Unpinned resolves through the assigned photographer's preference.
            ->assertJsonPath('effective_bracket_mode', 3);

        $this->assertNull($item->fresh()->bracket_mode);
    }
}
