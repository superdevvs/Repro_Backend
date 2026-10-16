<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AssignedRepServiceEditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        Queue::fake();
    }

    private function fixture(string $status = 'uploaded'): array
    {
        $rep = User::factory()->create(['role' => 'salesRep']);
        Sanctum::actingAs($rep);
        $shoot = Shoot::factory()->create([
            'rep_id' => $rep->id, 'status' => $status, 'workflow_status' => $status,
            'client_id' => User::factory()->create(['role' => 'client'])->id,
            'scheduled_at' => '2026-10-01 15:00:00', 'scheduled_date' => '2026-10-01',
            'time' => '11:00:00', 'timezone' => 'America/New_York',
            'state' => 'MD', 'tax_region' => 'MD', 'tax_percent' => 6,
            'base_quote' => 175, 'tax_amount' => 10.5, 'total_quote' => 185.5,
            'payment_status' => 'unpaid', 'photos_uploaded_at' => now(),
        ]);
        $old = Service::factory()->create(['name' => '25 HDR', 'price' => 175]);
        $shoot->services()->attach($old->id, [
            'price' => 175, 'quantity' => 1, 'photographer_id' => $shoot->photographer_id,
            'scheduled_at' => '2026-10-01 15:00:00', 'workflow_status' => 'in_progress',
        ]);
        return [$shoot, $old, $rep];
    }

    public static function editableStages(): array
    {
        return array_map(fn ($status) => [$status], ['scheduled', 'uploaded', 'editing', 'review', 'ready']);
    }

    #[DataProvider('editableStages')]
    public function test_assigned_rep_can_add_services_through_pre_delivery_stages(string $status): void
    {
        [$shoot, $old] = $this->fixture($status);
        $staging = Service::factory()->noIntake()->create([
            'name' => 'Virtual Staging', 'price' => 45, 'allow_multiple' => true, 'photographer_required' => false,
        ]);
        $this->patchJson('/api/shoots/'.$shoot->id, [
            'services' => [['id' => $old->id, 'price' => 1], ['id' => $staging->id, 'price' => 1, 'quantity' => 2]],
            'notify_client' => false, 'notify_photographer' => false,
        ])->assertOk();
        $this->assertSame($status, $shoot->fresh()->status);
        $this->assertSame($status, $shoot->fresh()->workflow_status);
        $this->assertSame(175.0, (float) $shoot->serviceItems()->where('service_id', $old->id)->value('price'));
        $this->assertSame(45.0, (float) $shoot->serviceItems()->where('service_id', $staging->id)->value('price'));
        $this->assertSame(2, (int) $shoot->serviceItems()->where('service_id', $staging->id)->value('quantity'));
    }

    public function test_uploaded_service_replacement_requires_confirmation_and_syncs_invoice(): void
    {
        [$shoot, $old] = $this->fixture();
        app(InvoiceService::class)->generateForShoot($shoot);
        $invoice = Invoice::where('shoot_id', $shoot->id)->firstOrFail();
        $file = ShootFile::create([
            'shoot_id' => $shoot->id, 'shoot_service_id' => $shoot->serviceItems()->sole()->id,
            'filename' => 'original.jpg', 'stored_filename' => 'original.jpg',
            'path' => 'shoots/original.jpg', 'file_type' => 'image/jpeg', 'file_size' => 123,
            'uploaded_by' => $shoot->photographer_id, 'workflow_stage' => ShootFile::STAGE_TODO,
        ]);
        $combo = Service::factory()->create(['name' => '30 HDR Photos + Floor plans', 'price' => 270]);
        $staging = Service::factory()->noIntake()->create([
            'name' => 'Virtual Staging', 'price' => 45, 'allow_multiple' => true, 'photographer_required' => false,
        ]);
        $payload = [
            'services' => [['id' => $combo->id, 'quantity' => 1], ['id' => $staging->id, 'quantity' => 2]],
            'notify_client' => false, 'notify_photographer' => false,
        ];
        $confirmation = $this->patchJson('/api/shoots/'.$shoot->id, $payload)
            ->assertStatus(409)->assertJsonPath('code', 'service_detach_confirmation_required');
        $this->assertSame($old->id, (int) $shoot->serviceItems()->sole()->service_id);
        $this->patchJson('/api/shoots/'.$shoot->id, $payload + [
            'confirm_service_detach' => true,
            'service_detach_confirmation_token' => $confirmation->json('confirmation_token'),
        ])->assertOk();
        $this->assertEqualsCanonicalizing([$combo->id, $staging->id], $shoot->serviceItems()->pluck('service_id')->all());
        $this->assertSame(360.0, (float) $shoot->fresh()->base_quote);
        $this->assertSame(381.6, (float) $shoot->fresh()->total_quote);
        $this->assertSame(381.6, (float) $invoice->fresh()->total);
        $this->assertSame(381.6, (float) $invoice->fresh()->balance_due);
        $this->assertNotNull($file->fresh());
        $this->assertNull($file->fresh()->shoot_service_id);
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_existing_staging_quantity_changes_without_overriding_booked_price(): void
    {
        [$shoot, $service] = $this->fixture();
        $service->update(['allow_multiple' => true, 'price' => 999]);
        $this->patchJson('/api/shoots/'.$shoot->id, [
            'service_items' => [['service_id' => $service->id, 'quantity' => 2, 'price' => 1, 'duration_minutes' => 5]],
            'notify_client' => false, 'notify_photographer' => false,
        ])->assertOk();
        $item = $shoot->serviceItems()->sole();
        $this->assertSame(2, (int) $item->quantity);
        $this->assertSame(175.0, (float) $item->price);
        $this->assertSame(5, (int) $item->duration_minutes);
    }

    public function test_catalog_quantity_limit_is_still_enforced(): void
    {
        [$shoot, $service] = $this->fixture();
        $service->update(['allow_multiple' => false]);
        $this->patchJson('/api/shoots/'.$shoot->id, [
            'services' => [['id' => $service->id, 'quantity' => 2]],
        ])->assertUnprocessable();
        $this->assertSame(1, (int) $shoot->serviceItems()->sole()->quantity);
    }

    public static function sealedStages(): array
    {
        return [['cancelled'], ['declined'], ['import_draft']];
    }

    #[DataProvider('sealedStages')]
    public function test_sealed_shoots_remain_restricted(string $status): void
    {
        [$shoot, $service] = $this->fixture($status);
        $additionalService = Service::factory()->noIntake()->create(['photographer_required' => false]);
        $this->patchJson('/api/shoots/'.$shoot->id, ['services' => [['id' => $service->id], ['id' => $additionalService->id]]])
            ->assertStatus($status === 'import_draft' ? 404 : 403);
        $this->assertSame([$service->id], $shoot->serviceItems()->pluck('service_id')->all());
    }

    public function test_delivered_service_context_is_accepted_without_changing_the_booked_plan_or_financials(): void
    {
        [$shoot, $service] = $this->fixture('delivered');
        $serviceBefore = $shoot->serviceItems()->sole()->getRawOriginal();
        $protectedFields = ['status', 'workflow_status', 'scheduled_at', 'scheduled_date', 'time',
            'photographer_id', 'rep_id', 'base_quote', 'tax_amount', 'total_quote', 'payment_status'];
        $shootBefore = $shoot->fresh()->only($protectedFields);

        $this->patchJson('/api/shoots/'.$shoot->id, ['services' => [['id' => $service->id]]])->assertOk();

        $this->assertSame($serviceBefore, $shoot->serviceItems()->sole()->getRawOriginal());
        $this->assertEquals($shootBefore, $shoot->fresh()->only($protectedFields));
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_other_reps_can_edit_the_uploaded_shoot(): void
    {
        [$shoot, $service] = $this->fixture();
        Sanctum::actingAs(User::factory()->create(['role' => 'salesRep']));
        $this->patchJson('/api/shoots/'.$shoot->id, ['services' => [['id' => $service->id]]])->assertOk();
        $this->assertSame(175.0, (float) $shoot->serviceItems()->sole()->price);
    }

    public static function repAliases(): array
    {
        return array_map(fn ($role) => [$role], ['salesRep', 'sales_rep', 'sales-rep', 'rep', 'representative']);
    }

    #[DataProvider('repAliases')]
    public function test_global_rep_aliases_keep_duration_and_reject_locked_assignment(string $role): void
    {
        [$shoot, $service] = $this->fixture('scheduled');
        $shoot->update(['rep_id' => null]);
        Sanctum::actingAs(User::factory()->create(['role' => $role]));
        $this->patchJson('/api/shoots/'.$shoot->id, [
            'service_items' => [['service_id' => $service->id, 'duration_minutes' => 5, 'price' => 999]],
            'notify_client' => false, 'notify_photographer' => false,
        ])->assertOk();
        $this->assertSame(5, (int) $shoot->serviceItems()->sole()->duration_minutes);
        $this->assertSame(175.0, (float) $shoot->serviceItems()->sole()->price);
        $shoot->update(['status' => 'delivered', 'workflow_status' => 'delivered']);
        $assignmentBefore = $shoot->photographer_id;
        $serviceBefore = $shoot->serviceItems()->sole()->getRawOriginal();
        $this->patchJson('/api/shoots/'.$shoot->id, ['photographer_id' => $assignmentBefore])->assertOk();
        $this->assertSame($assignmentBefore, $shoot->fresh()->photographer_id);
        $this->assertSame($serviceBefore, $shoot->serviceItems()->sole()->getRawOriginal());
        $differentPhotographer = User::factory()->photographer()->create();
        $this->patchJson('/api/shoots/'.$shoot->id, ['photographer_id' => $differentPhotographer->id])->assertForbidden();
        $this->patchJson('/api/shoots/'.$shoot->id, ['scheduled_at' => '2026-10-02T11:00:00-04:00'])->assertForbidden();
        $this->patchJson('/api/shoots/'.$shoot->id, ['workflow_status' => 'editing'])->assertForbidden();
        $this->postJson('/api/shoots/'.$shoot->id.'/assign-service-photographer', [
            'service_id' => $service->id, 'photographer_id' => $shoot->photographer_id,
        ])->assertForbidden();
        $this->assertSame($assignmentBefore, $shoot->fresh()->photographer_id);
        $this->assertSame($serviceBefore, $shoot->serviceItems()->sole()->getRawOriginal());
    }

    public function test_offsite_staging_cannot_acquire_a_photographer_when_rep_edits_assignments(): void
    {
        [$shoot, $photo] = $this->fixture('scheduled');
        $staging = Service::factory()->noIntake()->create(['name' => 'Virtual staging (per image)', 'photographer_required' => false, 'shoot_duration_minutes' => 0]);
        $shoot->services()->attach($staging->id, ['duration_minutes' => 0, 'price' => 45, 'quantity' => 1]);
        $artist = User::factory()->photographer()->create();
        Sanctum::actingAs(User::factory()->create(['role' => 'sales_rep']));
        $this->patchJson('/api/shoots/'.$shoot->id, [
            'service_photographers' => [['service_id' => $staging->id, 'photographer_id' => $artist->id]],
            'notify_client' => false, 'notify_photographer' => false,
        ])->assertOk();
        $this->assertNull($shoot->serviceItems()->where('service_id', $staging->id)->value('photographer_id'));
        $this->assertSame(0, (int) $shoot->serviceItems()->where('service_id', $staging->id)->value('duration_minutes'));
        $this->assertSame($shoot->photographer_id, $shoot->fresh()->photographer_id);
        $this->assertSame($shoot->photographer_id, (int) $shoot->serviceItems()->where('service_id', $photo->id)->value('photographer_id'));
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public static function travelFeeAssignmentPaths(): array
    {
        $cases = [];
        foreach (['admin', 'salesRep'] as $role) {
            foreach (['services', 'service_items', 'service_photographers', 'assignment_endpoint'] as $path) {
                $cases["$role $path"] = [$role, $path];
            }
        }

        return $cases;
    }

    #[DataProvider('travelFeeAssignmentPaths')]
    public function test_travel_fee_recipient_saves_and_survives_later_service_edits(string $role, string $path): void
    {
        [$shoot, $photo, $rep] = $this->fixture('scheduled');
        Sanctum::actingAs($role === 'salesRep' ? $rep : User::factory()->create(['role' => $role]));
        $recipient = User::factory()->photographer()->create(['name' => 'Travel Fee Recipient']);
        $fee = Service::factory()->noIntake()->create([
            'name' => 'Travel fee - 90 Miles', 'photographer_required' => false,
            'requires_editing' => false, 'shoot_duration_minutes' => 0, 'price' => 75,
            'photographer_pay_type' => 'percent', 'photographer_pay_percent' => 100,
        ]);
        $shoot->services()->attach($fee->id, [
            'price' => 75, 'quantity' => 1, 'duration_minutes' => 0,
            'photographer_id' => null, 'scheduled_at' => $shoot->scheduled_at,
        ]);
        $endpoint = '/api/shoots/'.$shoot->id;
        $assignment = ['service_id' => $fee->id, 'photographer_id' => $recipient->id];
        if ($path === 'assignment_endpoint') {
            $this->postJson($endpoint.'/assign-service-photographer', $assignment)->assertOk();
        } else {
            $rows = $path === 'services'
                ? [['id' => $photo->id], ['id' => $fee->id, 'photographer_id' => $recipient->id]]
                : [$assignment];
            $this->patchJson($endpoint, [
                $path => $rows, 'notify_client' => false, 'notify_photographer' => false,
            ])->assertOk();
        }

        $this->assertSame($recipient->id, (int) $shoot->serviceItems()->where('service_id', $fee->id)->value('photographer_id'));
        // A later service save used to clear optional-service assignments again.
        $this->patchJson($endpoint, [
            'services' => [['id' => $photo->id], ['id' => $fee->id]],
            'notify_client' => false, 'notify_photographer' => false,
        ])->assertOk();

        $item = $shoot->serviceItems()->where('service_id', $fee->id)->firstOrFail();
        $this->assertSame($recipient->id, (int) $item->photographer_id);
        $this->assertSame(0, (int) $item->duration_minutes);
        $this->assertSame(75.0, (float) $item->price);
        $this->assertSame($shoot->photographer_id, $shoot->fresh()->photographer_id);
        $this->assertSame($shoot->photographer_id, (int) $shoot->serviceItems()->where('service_id', $photo->id)->value('photographer_id'));
        $this->assertSame([], app(\App\Services\Shoots\ShootDurationResolver::class)->windowsForShoot($shoot->fresh(), $recipient->id));

        $serialized = collect($this->getJson($endpoint)->assertOk()->json('data.service_items'))->firstWhere('service_id', $fee->id);
        $this->assertSame($recipient->id, $serialized['photographer_id']);
        $this->assertSame($recipient->id, $serialized['resolved_photographer_id']);
        $this->assertSame('Travel Fee Recipient', $serialized['resolved_photographer']['name']);
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_other_optional_services_still_reject_photographer_assignment(): void
    {
        [$shoot] = $this->fixture('scheduled');
        $addon = Service::factory()->noIntake()->create([
            'name' => 'Image enhancements', 'photographer_required' => false,
            'shoot_duration_minutes' => 0,
        ]);
        $shoot->services()->attach($addon->id, ['price' => 20, 'quantity' => 1, 'duration_minutes' => 0]);
        $recipient = User::factory()->photographer()->create();
        $this->postJson('/api/shoots/'.$shoot->id.'/assign-service-photographer', [
            'service_id' => $addon->id, 'photographer_id' => $recipient->id,
        ])->assertUnprocessable();
        $this->assertNull($shoot->serviceItems()->where('service_id', $addon->id)->value('photographer_id'));
    }

    public static function invalidTravelFeeRecipients(): array
    {
        $cases = [];
        foreach (['client', 'editor'] as $role) {
            foreach (['services', 'service_items', 'service_photographers'] as $path) {
                $cases["$role $path"] = [$role, $path];
            }
        }

        return $cases;
    }

    #[DataProvider('invalidTravelFeeRecipients')]
    public function test_travel_fee_rejects_non_photographer_recipients_on_edit_paths(string $role, string $path): void
    {
        [$shoot, $photo] = $this->fixture('scheduled');
        $fee = Service::factory()->noIntake()->create([
            'name' => 'Travel Fee', 'photographer_required' => false, 'shoot_duration_minutes' => 0,
        ]);
        $shoot->services()->attach($fee->id, ['price' => 50, 'quantity' => 1, 'duration_minutes' => 0]);
        $recipient = User::factory()->create(['role' => $role]);
        $rows = $path === 'services'
            ? [['id' => $photo->id], ['id' => $fee->id, 'photographer_id' => $recipient->id]]
            : [['service_id' => $fee->id, 'photographer_id' => $recipient->id]];
        $this->patchJson('/api/shoots/'.$shoot->id, [
            $path => $rows, 'notify_client' => false, 'notify_photographer' => false,
        ])->assertUnprocessable();
        $this->assertNull($shoot->serviceItems()->where('service_id', $fee->id)->value('photographer_id'));
    }

    public function test_global_rep_cannot_save_a_genuine_availability_conflict(): void
    {
        [$shoot, $service] = $this->fixture('scheduled');
        Sanctum::actingAs(User::factory()->create(['role' => 'representative']));
        $conflict = Shoot::factory()->create([
            'photographer_id' => $shoot->photographer_id, 'scheduled_at' => $shoot->scheduled_at,
            'scheduled_date' => $shoot->scheduled_date, 'time' => $shoot->time,
            'timezone' => $shoot->timezone, 'status' => 'scheduled', 'workflow_status' => 'scheduled',
        ]);
        $conflict->services()->attach($service->id, [
            'photographer_id' => $shoot->photographer_id, 'scheduled_at' => $shoot->scheduled_at,
            'duration_minutes' => 60,
        ]);
        $this->patchJson('/api/shoots/'.$shoot->id, [
            'service_items' => [['service_id' => $service->id, 'duration_minutes' => 5]],
            'notify_client' => false, 'notify_photographer' => false,
        ])->assertUnprocessable();
        $this->assertSame(175.0, (float) $shoot->serviceItems()->sole()->price);
    }
}
