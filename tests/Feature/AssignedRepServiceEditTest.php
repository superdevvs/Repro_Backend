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
            'service_items' => [['service_id' => $service->id, 'quantity' => 2, 'price' => 1, 'duration_minutes' => 999]],
            'notify_client' => false, 'notify_photographer' => false,
        ])->assertOk();
        $item = $shoot->serviceItems()->sole();
        $this->assertSame(2, (int) $item->quantity);
        $this->assertSame(175.0, (float) $item->price);
        $this->assertNotSame(999, (int) $item->duration_minutes);
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
        return [['delivered'], ['cancelled'], ['declined'], ['import_draft']];
    }

    #[DataProvider('sealedStages')]
    public function test_sealed_shoots_remain_restricted(string $status): void
    {
        [$shoot, $service] = $this->fixture($status);
        $this->patchJson('/api/shoots/'.$shoot->id, ['services' => [['id' => $service->id]]])
            ->assertStatus($status === 'import_draft' ? 404 : 403);
    }

    public function test_other_reps_cannot_edit_the_uploaded_shoot(): void
    {
        [$shoot, $service] = $this->fixture();
        Sanctum::actingAs(User::factory()->create(['role' => 'salesRep']));
        $this->patchJson('/api/shoots/'.$shoot->id, ['services' => [['id' => $service->id]]])->assertForbidden();
        $this->assertSame(175.0, (float) $shoot->serviceItems()->sole()->price);
    }
}
