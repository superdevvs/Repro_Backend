<?php

namespace Tests\Feature;

use App\Models\{Service, Shoot, ShootFile, ShootService, User};
use App\Services\InvoiceService;
use App\Services\Shoots\PostCaptureServiceCorrection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Mail, Notification, Queue};
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PostUploadPackageChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-11-01 12:00:00', 'UTC'));
        Http::preventStrayRequests(); Mail::fake(); Notification::fake(); Queue::fake();
    }

    public static function stagesAndRoles(): array
    {
        $cases = [];
        foreach (['admin', 'superadmin', 'salesRep'] as $role) {
            foreach (['scheduled', 'uploaded', 'editing', 'review', 'ready', 'delivered'] as $stage) {
                $cases["$role $stage"] = [$role, $stage];
            }
        }
        return $cases;
    }

    private function fixture(string $role, string $stage): array
    {
        $actor = User::factory()->create(['role' => $role]);
        Sanctum::actingAs($actor);
        $original = Service::factory()->photoVideoIntake()->create(['name' => 'HDR Photos, Video & Premium iGuide', 'price' => 600]);
        $target = Service::factory()->photoVideoIntake()->create(['category_id' => $original->category_id, 'name' => 'Updated HDR Package', 'price' => 500]);
        $shoot = Shoot::factory()->create(['status' => $stage, 'workflow_status' => $stage,
            'rep_id' => null, 'state' => 'MD', 'timezone' => 'America/New_York',
            'scheduled_at' => '2026-10-31 15:00:00', 'scheduled_date' => '2026-10-31', 'time' => '11:00:00',
            'photos_uploaded_at' => '2026-10-31 18:00:00', 'base_quote' => 600, 'tax_amount' => 36, 'total_quote' => 636]);
        $item = ShootService::create(['shoot_id' => $shoot->id, 'service_id' => $original->id,
            'photographer_id' => $shoot->photographer_id, 'editor_id' => User::factory()->create(['role' => 'editor'])->id,
            'scheduled_at' => $shoot->scheduled_at, 'duration_minutes' => 165,
            'workflow_status' => $stage === 'delivered' ? 'delivered' : 'in_progress',
            'delivery_status' => $stage === 'delivered' ? 'delivered' : 'not_started', 'price' => 600, 'quantity' => 1]);
        foreach (['todo', 'verified'] as $fileStage) {
            ShootFile::create(['shoot_id' => $shoot->id, 'shoot_service_id' => $item->id,
                'filename' => "$fileStage.jpg", 'stored_filename' => "$fileStage.jpg", 'path' => "$fileStage.jpg",
                'file_size' => 100, 'file_type' => 'image/jpeg', 'media_type' => $fileStage === 'todo' ? 'raw' : 'edited',
                'workflow_stage' => $fileStage, 'uploaded_by' => $shoot->photographer_id]);
        }
        return [$shoot->fresh(), $item->fresh(), $target, $actor];
    }

    #[DataProvider('stagesAndRoles')]
    public function test_mixed_package_correction_preserves_uploaded_work(string $role, string $stage): void
    {
        [$shoot, $item, $target] = $this->fixture($role, $stage);
        $invoice = app(InvoiceService::class)->generateForShoot($shoot);
        $before = $item->only(['id', 'scheduled_at', 'duration_minutes', 'photographer_id', 'editor_id', 'workflow_status', 'delivery_status']);
        $this->patchJson('/api/shoots/'.$shoot->id, ['services' => [['id' => $target->id]],
            'notify_client' => false, 'notify_photographer' => false])->assertOk();
        $this->assertEquals($before, $item->fresh()?->only(array_keys($before)));
        $this->assertSame($target->id, $item->fresh()->service_id);
        $this->assertSame(2, ShootFile::where('shoot_service_id', $item->id)->count());
        $this->assertSame($stage, $shoot->fresh()->workflow_status);
        $this->assertSame(500.0, (float) $invoice->fresh()->subtotal);
        Mail::assertNothingSent(); Notification::assertNothingSent();
    }

    #[DataProvider('stagesAndRoles')]
    public function test_different_capture_package_requires_confirmation_and_saves_without_rebooking_the_old_visit(string $role, string $stage): void
    {
        [$shoot, $item, $target] = $this->fixture($role, $stage);
        $target->update(['upload_intake_type' => Service::INTAKE_PHOTO]);
        $invoice = app(InvoiceService::class)->generateForShoot($shoot);
        $visitBefore = $shoot->only(['status', 'workflow_status', 'scheduled_at', 'scheduled_date', 'time', 'photographer_id']);
        $payload = ['services' => [['id' => $target->id]], 'notify_client' => false, 'notify_photographer' => false];
        $confirmation = $this->patchJson('/api/shoots/'.$shoot->id, $payload)->assertConflict()
            ->assertJsonPath('code', 'service_detach_confirmation_required')->assertJsonPath('impact.files_detached', 2);
        $this->patchJson('/api/shoots/'.$shoot->id, $payload + [
            'confirm_service_detach' => true, 'service_detach_confirmation_token' => $confirmation->json('confirmation_token'),
        ])->assertOk();
        $this->assertEquals($visitBefore, $shoot->fresh()->only(array_keys($visitBefore)));
        $this->assertNull($item->fresh());
        $this->assertSame(2, $shoot->files()->whereNull('shoot_service_id')->count());
        $new = $shoot->serviceItems()->sole();
        $this->assertSame($target->id, $new->service_id);
        $this->assertNull($new->scheduled_at); $this->assertNull($new->photographer_id);
        $this->assertSame('pending', $new->workflow_status);
        $this->assertSame(500.0, (float) $invoice->fresh()->subtotal);
        Mail::assertNothingSent(); Notification::assertNothingSent();
    }

    public function test_overview_schedule_echoes_do_not_trigger_travel_or_capability_checks_for_a_correction(): void
    {
        config(['availability.hybrid_travel_enabled' => true]);
        [$shoot, $item, $target] = $this->fixture('admin', 'delivered');
        $payload = ['scheduled_at' => $shoot->scheduled_at->toIso8601String(),
            'services' => [['id' => $target->id, 'duration_minutes' => 120, 'scheduled_at' => $shoot->scheduled_at->toIso8601String()]],
            'service_photographers' => [['service_id' => $target->id, 'photographer_id' => $shoot->photographer_id]]];
        $this->postJson('/api/photographer/availability/feasibility', $payload + ['shoot_id' => $shoot->id, 'action_mode' => 'update'])
            ->assertOk()->assertJsonPath('data.status', 'available');
        $this->patchJson('/api/shoots/'.$shoot->id, $payload + ['notify_client' => false, 'notify_photographer' => false])->assertOk();
        $this->assertSame(165, $item->fresh()->duration_minutes);
        $this->assertSame($target->id, $item->fresh()->service_id);
    }

    public function test_new_visits_reassignments_and_missing_capture_evidence_do_not_qualify_as_corrections(): void
    {
        [$shoot, $item, $target, $actor] = $this->fixture('admin', 'uploaded');
        $base = ['services' => [['id' => $target->id]]];
        $guard = app(PostCaptureServiceCorrection::class);
        $this->assertFalse($guard->applies($shoot, $actor, $base + ['scheduled_at' => '2026-11-02T15:00:00Z']));
        $this->assertFalse($guard->applies($shoot, $actor, $base + ['photographer_id' => User::factory()->photographer()->create()->id]));
        $this->assertFalse($guard->applies($shoot, $actor, ['services' => [['id' => $item->service_id, 'duration_minutes' => 170]]]));
        $this->assertFalse($guard->applies($shoot, $actor, $base + ['action_mode' => 'reschedule']));
        $item->update(['scheduled_at' => '2026-11-02 15:00:00']);
        $this->assertFalse($guard->applies($shoot->fresh(), $actor, $base));
        $item->update(['scheduled_at' => '2026-10-31 15:00:00']);
        $shoot->files()->delete(); $shoot->update(['photos_uploaded_at' => null]);
        $this->assertFalse($guard->applies($shoot->fresh(), $actor, $base));
    }

    public static function partialEditRoles(): array { return [['admin', false], ['salesRep', false], ['admin', true], ['salesRep', true]]; }

    #[DataProvider('partialEditRoles')]
    public function test_partial_item_edit_keeps_other_booked_services(string $role, bool $withSelection): void
    {
        [$shoot, $item] = $this->fixture($role, 'uploaded');
        $item->service->update(['allow_multiple' => true]);
        $addon = Service::factory()->noIntake()->create(['price' => 45, 'photographer_required' => false]);
        $other = $shoot->serviceItems()->create(['service_id' => $addon->id, 'price' => 45, 'quantity' => 1, 'duration_minutes' => 0])->fresh();
        $before = $other->getRawOriginal();
        $payload = [
            'service_items' => [['service_id' => $item->service_id, 'quantity' => 2]],
            'notify_client' => false, 'notify_photographer' => false,
        ];
        if ($withSelection) $payload['services'] = [['id' => $item->service_id], ['id' => $addon->id]];
        $this->patchJson('/api/shoots/'.$shoot->id, $payload)->assertOk();
        $this->assertSame(2, $shoot->serviceItems()->count());
        $this->assertSame(2, $item->fresh()->quantity);
        $this->assertSame($before, $other->fresh()->getRawOriginal());
    }
}
