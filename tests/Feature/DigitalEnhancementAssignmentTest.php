<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Shoots\ShootDurationResolver;
use App\Services\Shoots\ShootEditingAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DigitalEnhancementAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $photographer;
    private User $editor;
    private Category $digitalCategory;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Notification::fake();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->photographer = User::factory()->photographer()->create();
        $this->editor = User::factory()->create(['role' => 'editor', 'metadata' => ['editing_capabilities' => ['photo']]]);
        $this->digitalCategory = Category::factory()->create(['name' => 'Digital Enhancements']);
        Sanctum::actingAs($this->admin);
    }

    private function digital(string $name = 'Green Grass Enhancement'): Service
    {
        return Service::factory()->noIntake()->create([
            'name' => $name, 'category_id' => $this->digitalCategory->id,
            'price' => 25, 'pricing_type' => 'fixed', 'allow_multiple' => true,
        ]);
    }

    private function mixedShoot(Service $digital, bool $legacyAssignment = false): array
    {
        $photo = Service::factory()->create(['photographer_required' => true, 'shoot_duration_minutes' => 30, 'price' => 100]);
        $this->photographer->update(['metadata' => array_replace($this->photographer->metadata ?? [], ['specialties' => [(string) $photo->id]])]);
        $shoot = Shoot::factory()->create([
            'status' => 'requested', 'workflow_status' => 'requested',
            'photographer_id' => $this->photographer->id, 'scheduled_at' => null,
            'scheduled_date' => null, 'time' => null, 'base_quote' => 125, 'total_quote' => 125,
        ]);
        $shoot->services()->attach($photo->id, ['price' => 100, 'quantity' => 1, 'photographer_id' => $this->photographer->id, 'duration_minutes' => 30]);
        $shoot->services()->attach($digital->id, ['price' => 25, 'quantity' => 1,
            'photographer_id' => $legacyAssignment ? $this->photographer->id : null, 'duration_minutes' => $legacyAssignment ? 5 : 0]);

        return [$shoot, $photo];
    }

    public function test_catalog_create_and_edit_cannot_restore_onsite_requirements_for_digital_work(): void
    {
        $id = $this->postJson('/api/admin/services', [
            'name' => 'Digital Twilight/Dusk', 'category_id' => $this->digitalCategory->id,
            'price' => 35, 'delivery_time' => 24, 'photographer_required' => true, 'shoot_duration_minutes' => 90,
        ])->assertOk()->assertJsonPath('service.photographer_required', false)
            ->assertJsonPath('service.shoot_duration_minutes', 0)->assertJsonPath('service.editing_lanes', ['photo'])->json('service.id');
        $this->putJson('/api/admin/services/'.$id, ['photographer_required' => true, 'shoot_duration_minutes' => 60])
            ->assertOk()->assertJsonPath('data.photographer_required', false)->assertJsonPath('data.shoot_duration_minutes', 0);
        $this->assertDatabaseHas('services', ['id' => $id, 'photographer_required' => 0, 'shoot_duration_minutes' => 0, 'requires_editing' => 1]);

        $legacy = Service::factory()->create(['name' => '25 HDR Photos + Virtual Staging', 'photographer_required' => true]);
        $this->assertTrue($legacy->requiresPhotographer());
        $this->assertFalse($legacy->isDigitalEnhancement());
    }

    public function test_new_digital_booking_discards_stale_photographer_and_duration_but_keeps_selected_editor(): void
    {
        $service = $this->digital('Virtual Staging (per image)');
        $client = User::factory()->create(['role' => 'client']);
        $id = $this->postJson('/api/shoots', [
            'client_id' => $client->id, 'photographer_id' => $this->photographer->id,
            'address' => '100 Main Street', 'city' => 'Baltimore', 'state' => 'MD', 'zip' => '21201',
            'services' => [['id' => $service->id, 'photographer_id' => $this->photographer->id,
                'duration_minutes' => 90, 'editor_id' => $this->editor->id]],
        ])->assertCreated()->json('data.id');
        $shoot = Shoot::findOrFail($id);
        $line = $shoot->serviceItems()->sole();
        $this->assertNull($shoot->photographer_id);
        $this->assertNull($line->photographer_id);
        $this->assertSame(0, $line->duration_minutes);
        $this->assertSame($this->editor->id, $line->editor_id);
        $this->assertSame($this->editor->id, $shoot->fresh()->editor_id);
        $this->assertSame(['photo'], app(ShootEditingAssignmentService::class)->getAssignedLanesForEditor($shoot, $this->editor));
    }

    public function test_editing_mixed_shoot_clears_digital_photographer_and_preserves_onsite_assignment_and_editor(): void
    {
        $service = $this->digital();
        [$shoot, $photo] = $this->mixedShoot($service, true);
        $lineId = $shoot->serviceItems()->where('service_id', $service->id)->value('id');
        $this->patchJson('/api/shoots/'.$shoot->id, [
            'service_items' => [['service_id' => $service->id, 'photographer_id' => null, 'editor_id' => $this->editor->id]],
            'notify_client' => false, 'notify_photographer' => false,
        ])->assertOk();
        $line = $shoot->serviceItems()->where('service_id', $service->id)->sole();
        $this->assertSame($lineId, $line->id);
        $this->assertNull($line->photographer_id);
        $this->assertSame(0, $line->duration_minutes);
        $this->assertSame($this->editor->id, $line->editor_id);
        $this->assertSame($this->photographer->id, $shoot->fresh()->photographer_id);
        $this->assertSame($this->photographer->id, $shoot->serviceItems()->where('service_id', $photo->id)->value('photographer_id'));
        $this->patchJson('/api/shoots/'.$shoot->id, ['services' => [['id' => $photo->id], ['id' => $service->id]], 'notify_client' => false, 'notify_photographer' => false])->assertOk();
        $this->assertSame($this->editor->id, $line->fresh()->editor_id);
        $this->patchJson('/api/shoots/'.$shoot->id, ['service_items' => [['service_id' => $service->id, 'editor_id' => null]], 'notify_client' => false, 'notify_photographer' => false])->assertOk();
        $this->assertNull($line->fresh()->editor_id);
        $this->assertNull($shoot->fresh()->editor_id);
    }

    public function test_digital_only_edit_cannot_restore_parent_photographer(): void
    {
        $service = $this->digital();
        $shoot = Shoot::factory()->create(['photographer_id' => $this->photographer->id, 'status' => 'requested', 'workflow_status' => 'requested', 'scheduled_at' => null, 'scheduled_date' => null, 'time' => null]);
        $shoot->services()->attach($service->id, ['price' => 25, 'quantity' => 1, 'photographer_id' => $this->photographer->id]);
        $this->patchJson('/api/shoots/'.$shoot->id, ['photographer_id' => $this->photographer->id,
            'service_items' => [['service_id' => $service->id, 'photographer_id' => $this->photographer->id, 'editor_id' => $this->editor->id]],
            'notify_client' => false, 'notify_photographer' => false])->assertOk();
        $this->assertNull($shoot->fresh()->photographer_id);
        $this->assertNull($shoot->serviceItems()->sole()->photographer_id);
    }

    public function test_alternate_schedule_endpoint_cannot_restore_digital_parent_photographer(): void
    {
        $service = $this->digital();
        $shoot = Shoot::factory()->create(['photographer_id' => null, 'status' => 'scheduled', 'workflow_status' => 'scheduled',
            'scheduled_at' => '2026-10-13 21:00:00', 'scheduled_date' => '2026-10-13', 'time' => '17:00:00', 'timezone' => 'America/New_York',
            'external_booking_payload' => ['legacy_migration' => ['notifications_suppressed' => true]]]);
        $shoot->services()->attach($service->id, ['price' => 25, 'quantity' => 1, 'photographer_id' => null, 'duration_minutes' => 0, 'scheduled_at' => $shoot->scheduled_at]);
        $this->postJson('/api/shoots/'.$shoot->id.'/schedule', ['photographer_id' => $this->photographer->id, 'scheduled_at' => '2026-10-13T17:00:00-04:00'])->assertOk();
        $this->assertNull($shoot->fresh()->photographer_id);
        $this->assertNull($shoot->serviceItems()->sole()->photographer_id);
        $this->assertSame('2026-10-13 21:00:00', $shoot->fresh()->getRawOriginal('scheduled_at'));
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_read_payload_never_inherits_a_photographer_for_digital_lines_even_before_repair(): void
    {
        $service = $this->digital();
        [$shoot] = $this->mixedShoot($service, true);
        DB::table('services')->where('id', $service->id)->update(['photographer_required' => 1, 'shoot_duration_minutes' => 60]);
        $data = $this->getJson('/api/shoots/'.$shoot->id)->assertOk()->json('data');
        foreach (['service_items', 'services'] as $field) {
            $row = collect($data[$field])->firstWhere($field === 'services' ? 'id' : 'service_id', $service->id);
            $this->assertNotNull($row);
            $this->assertNull($row['photographer_id']);
            $this->assertNull($row['resolved_photographer_id']);
            $this->assertNull($row['photographer']);
            $this->assertFalse($row['photographer_required']);
            $this->assertSame(['photo'], $row['editing_lanes']);
            $this->assertSame(0, $row['duration_minutes']);
        }
        $this->assertSame(0, app(ShootDurationResolver::class)->forServiceItem($shoot->serviceItems()->where('service_id', $service->id)->sole()));
    }

    public function test_dedicated_assignment_endpoint_can_clear_erroneous_digital_assignment_but_cannot_reassign(): void
    {
        $service = $this->digital('Virtual staging (per image)');
        [$shoot] = $this->mixedShoot($service, true);
        $endpoint = '/api/shoots/'.$shoot->id.'/assign-service-photographer';
        $this->postJson($endpoint, ['service_id' => $service->id, 'photographer_id' => null])->assertOk();
        $this->assertNull($shoot->serviceItems()->where('service_id', $service->id)->value('photographer_id'));
        $this->postJson($endpoint, ['service_id' => $service->id, 'photographer_id' => $this->photographer->id])
            ->assertUnprocessable()->assertJsonValidationErrors('service_photographers');
    }

    public function test_editor_selection_rejects_incompatible_lane_and_unrelated_roles(): void
    {
        $service = $this->digital();
        [$shoot] = $this->mixedShoot($service);
        $videoEditor = User::factory()->create(['role' => 'editor', 'metadata' => ['editing_capabilities' => ['video']]]);
        foreach ([$videoEditor, $this->photographer] as $assignee) {
            $this->patchJson('/api/shoots/'.$shoot->id, ['service_items' => [['service_id' => $service->id, 'editor_id' => $assignee->id]]])
                ->assertUnprocessable()->assertJsonValidationErrors('service_items');
        }
        $this->assertNull($shoot->serviceItems()->where('service_id', $service->id)->value('editor_id'));
        $video = $this->digital('Boundary Lines - Video');
        $this->assertSame(['video'], $video->editing_lanes);
        $both = $this->digital('Boundary Lines - Photos & Video');
        $this->assertSame(['photo', 'video'], $both->editing_lanes);
    }

    public function test_audited_correction_repairs_verified_rows_without_changing_dates_pricing_or_onsite_history(): void
    {
        $grass = $this->digital();
        $staging = $this->digital('Virtual Staging (per image)');
        DB::table('services')->where('id', $grass->id)->update(['id' => 51, 'photographer_required' => 1, 'shoot_duration_minutes' => 5]);
        DB::table('services')->where('id', $staging->id)->update(['id' => 18, 'photographer_required' => 1, 'shoot_duration_minutes' => 5]);
        $photographerA = User::factory()->photographer()->create(['id' => 1143]);
        $photographerB = User::factory()->photographer()->create(['id' => 2264]);
        $shootA = Shoot::factory()->create(['id' => 2407, 'photographer_id' => $photographerA->id, 'status' => 'delivered', 'workflow_status' => 'delivered', 'scheduled_at' => '2026-10-08 20:30:00']);
        $shootB = Shoot::factory()->create(['id' => 2460, 'photographer_id' => $photographerB->id, 'status' => 'scheduled', 'workflow_status' => 'scheduled', 'scheduled_at' => '2026-10-13 21:00:00']);
        $photo = Service::factory()->create(['id' => 6, 'photographer_required' => true]);
        $shootA->services()->attach($photo->id, ['price' => 100, 'photographer_id' => 1143, 'duration_minutes' => 15]);
        $shootA->services()->attach(51, ['id' => 2586, 'price' => 25, 'quantity' => 10, 'photographer_id' => 1143, 'duration_minutes' => 5, 'editor_id' => null]);
        $shootB->services()->attach(51, ['id' => 2579, 'price' => 25, 'quantity' => 1, 'photographer_id' => 2264, 'duration_minutes' => 5]);
        $shootB->services()->attach(18, ['id' => 2580, 'price' => 45, 'quantity' => 1, 'photographer_id' => 2264, 'duration_minutes' => 5]);
        $beforeA = $shootA->fresh()->getAttributes();
        $beforeB = $shootB->fresh()->getAttributes();
        $migration = require database_path('migrations/2026_10_13_180000_correct_digital_enhancement_assignments.php');
        $migration->up();
        $migration->up();
        $this->assertSame(3, DB::table('digital_assignment_correction_snapshots')->where('source_table', 'shoot_service')->count());
        $this->assertSame(1, DB::table('digital_assignment_correction_snapshots')->where('source_table', 'shoots')->count());
        foreach ([2586, 2579, 2580] as $id) {
            $this->assertDatabaseHas('shoot_service', ['id' => $id, 'photographer_id' => null, 'duration_minutes' => 0, 'editor_id' => null]);
        }
        $this->assertSame($beforeA, $shootA->fresh()->getAttributes());
        $beforeB['photographer_id'] = null;
        $this->assertSame($beforeB, $shootB->fresh()->getAttributes());
        $this->assertDatabaseHas('shoot_service', ['shoot_id' => 2407, 'service_id' => 6, 'photographer_id' => 1143, 'duration_minutes' => 15]);
        $this->assertDatabaseHas('shoot_service', ['id' => 2586, 'price' => 25, 'quantity' => 10]);
        Mail::assertNothingSent();
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
        $migration->down();
        $this->assertDatabaseHas('shoot_service', ['id' => 2579, 'photographer_id' => 2264, 'duration_minutes' => 5]);
        $this->assertSame(2264, $shootB->fresh()->photographer_id);
    }
}
