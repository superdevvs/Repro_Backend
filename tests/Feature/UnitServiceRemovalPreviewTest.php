<?php

namespace Tests\Feature;

use App\Models\{Payment, PaymentServiceAllocation, Service, Shoot, ShootFile, ShootService, User};
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Mail, Notification, Queue, Storage};
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UnitServiceRemovalPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['availability.hybrid_travel_enabled' => true]);
        Http::preventStrayRequests();
        Mail::fake(); Notification::fake(); Queue::fake(); Storage::fake('public');
    }

    public static function staffRoles(): array
    {
        return [['admin'], ['superadmin'], ['salesRep']];
    }

    private function fixture(string $role = 'admin'): array
    {
        $actor = User::factory()->create(['role' => $role]);
        $photographer = User::factory()->photographer()->create();
        $shoot = Shoot::factory()->create([
            'status' => 'delivered', 'workflow_status' => 'delivered', 'delivery_status' => 'delivered',
            'rep_id' => $actor->id, 'photographer_id' => $photographer->id,
            'scheduled_at' => '2026-11-06 15:00:00', 'scheduled_date' => '2026-11-06', 'time' => '10:00',
            'timezone' => 'America/New_York', 'completed_at' => '2026-11-07 10:29:28',
            'admin_verified_at' => '2026-11-07 10:29:28', 'photos_uploaded_at' => '2026-11-06 16:40:32',
            'state' => 'NJ', 'base_quote' => 185, 'total_quote' => 185, 'tax_amount' => 0, 'tax_percent' => 0,
        ]);
        $unit = $shoot->units()->create(['client_key' => 'unit-a', 'label' => '1', 'kind' => 'unit', 'sqft' => 2004]);
        $photo = Service::factory()->unbracketedPhoto()->create(['price' => 125]);
        $fee = Service::factory()->noIntake()->create([
            'name' => 'Onsite Cancellation/hold fee', 'price' => 60, 'financial_only' => true,
            'photographer_required' => false, 'photographer_pay' => 50,
        ]);
        $photoLine = ShootService::create([
            'shoot_id' => $shoot->id, 'service_id' => $photo->id, 'shoot_unit_id' => $unit->id, 'client_key' => 'photo-a',
            'price' => 125, 'quantity' => 1, 'photographer_id' => $photographer->id, 'photographer_pay' => 56.25,
            'scheduled_at' => $shoot->scheduled_at, 'duration_minutes' => 60,
            'workflow_status' => 'delivered', 'delivery_status' => 'delivered', 'delivered_at' => $shoot->completed_at,
        ]);
        $feeLine = ShootService::create([
            'shoot_id' => $shoot->id, 'service_id' => $fee->id, 'shoot_unit_id' => $unit->id, 'client_key' => 'fee-a',
            'price' => 60, 'quantity' => 1, 'photographer_id' => $photographer->id, 'photographer_pay' => 50,
            'duration_minutes' => 0, 'workflow_status' => 'pending', 'delivery_status' => 'not_started',
        ]);
        Storage::disk('public')->put('completed/exterior.jpg', 'exterior-photo');
        $file = ShootFile::create([
            'shoot_id' => $shoot->id, 'shoot_service_id' => $photoLine->id, 'filename' => 'exterior.jpg',
            'stored_filename' => 'exterior.jpg', 'path' => 'completed/exterior.jpg', 'file_size' => 14,
            'file_type' => 'image/jpeg', 'media_type' => 'edited', 'workflow_stage' => 'verified',
            'uploaded_by' => $photographer->id,
        ]);
        Sanctum::actingAs($actor);

        return [$shoot->fresh(), $photoLine, $feeLine->fresh(), $file];
    }

    private function removal(Shoot $shoot, ShootService $fee): array
    {
        return ['expected_units_revision' => $shoot->units_revision, 'service_lines' => [[
            'shoot_service_id' => $fee->id, 'client_key' => $fee->client_key,
            'shoot_unit_id' => $fee->shoot_unit_id, 'service_id' => $fee->service_id,
        ]], 'notify_client' => false, 'notify_photographer' => false];
    }

    #[DataProvider('staffRoles')]
    public function test_fee_only_correction_previews_then_requires_confirmation_and_preserves_payment_and_media(string $role): void
    {
        [$shoot, $photo, $fee, $file] = $this->fixture($role);
        $invoice = app(InvoiceService::class)->generateForShoot($shoot);
        $payment = Payment::factory()->create(['shoot_id' => $shoot->id, 'invoice_id' => $invoice->id,
            'amount' => 60, 'status' => Payment::STATUS_COMPLETED]);
        PaymentServiceAllocation::create(['payment_id' => $payment->id, 'shoot_service_id' => $fee->id, 'amount' => 60]);
        $shoot->syncPaymentStatusFromRecords(null, false);
        $shoot->refresh();
        $before = $shoot->getRawOriginal();
        $feeBefore = $fee->fresh()->getRawOriginal();
        $paymentBefore = $payment->fresh()->getRawOriginal();
        $payload = $this->removal($shoot, $fee);

        $this->postJson('/api/photographer/availability/feasibility', $payload + [
            'shoot_id' => $shoot->id, 'action_mode' => 'update', 'timezone' => $shoot->timezone,
            'scheduled_at' => $shoot->scheduled_at->toIso8601String(), 'photographer_id' => $shoot->photographer_id,
        ])->assertOk()->assertJsonPath('data.available', true)->assertJsonCount(0, 'data.visits');
        $this->assertSame($before, $shoot->fresh()->getRawOriginal());
        $this->assertSame($photo->id, $file->fresh()->shoot_service_id);

        $confirmation = $this->patchJson('/api/shoots/'.$shoot->id, $payload)->assertConflict()
            ->assertJsonPath('code', 'service_detach_confirmation_required')
            ->assertJsonPath('impact.files_detached', 1);
        $this->assertEquals(60, $confirmation->json('impact.new_total'));
        $this->assertEquals(0, $confirmation->json('impact.new_balance'));
        $this->assertNotNull($photo->fresh());
        $this->patchJson('/api/shoots/'.$shoot->id, $payload + [
            'confirm_service_detach' => true, 'service_detach_confirmation_token' => $confirmation->json('confirmation_token'),
        ])->assertOk();

        $this->assertNull($photo->fresh());
        $this->assertNull($file->fresh()->shoot_service_id);
        Storage::disk('public')->assertExists('completed/exterior.jpg');
        $this->assertSame($feeBefore, $fee->fresh()->getRawOriginal());
        $this->assertSame($paymentBefore, $payment->fresh()->getRawOriginal());
        $this->assertDatabaseHas('payment_service_allocations', ['payment_id' => $payment->id, 'shoot_service_id' => $fee->id, 'amount' => 60]);
        $this->assertEquals(60, $shoot->fresh()->total_quote);
        $this->assertSame('paid', $shoot->fresh()->payment_status);
        $this->assertSame('delivered', $shoot->fresh()->workflow_status);
        $this->assertEquals(60, $invoice->fresh()->total);
        $this->assertEquals(0, $invoice->fresh()->balance_due);
        $this->assertDatabaseHas('shoot_activity_logs', ['shoot_id' => $shoot->id, 'action' => 'shoot_services_detached']);
        Mail::assertNothingSent(); Notification::assertNothingSent();
    }

    public function test_preview_keeps_the_history_guard_for_an_ordinary_unit_booking(): void
    {
        [$shoot, $photo, $fee] = $this->fixture();
        $shoot->forceFill(['status' => 'scheduled', 'workflow_status' => 'scheduled', 'delivery_status' => 'not_started',
            'completed_at' => null, 'admin_verified_at' => null, 'photos_uploaded_at' => null,
            'scheduled_at' => now()->addWeek(), 'scheduled_date' => now()->addWeek()->toDateString()])->saveQuietly();
        $photo->forceFill(['workflow_status' => 'scheduled', 'delivery_status' => 'not_started', 'delivered_at' => null,
            'scheduled_at' => $shoot->scheduled_at])->saveQuietly();
        $payload = $this->removal($shoot, $fee);
        $this->postJson('/api/photographer/availability/feasibility', $payload + [
            'shoot_id' => $shoot->id, 'action_mode' => 'update',
        ])->assertUnprocessable()->assertJsonValidationErrors('service_lines');
        $this->assertNotNull($photo->fresh());
        $this->assertEquals(185, $shoot->fresh()->total_quote);
    }
}
