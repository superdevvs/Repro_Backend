<?php

namespace Tests\Feature;

use App\Models\{Category, Invoice, Service, Shoot, ShootFile, User};
use App\Services\Invoices\{PhotographerPayoutReconciliation, PayoutInvoiceWorkflow};
use Carbon\Carbon;
use App\Services\Photographers\PhotographerServiceEligibility;
use App\Services\Shoots\{MultiUnitBookingService, ShootDurationResolver, ShootEditingAssignmentService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Mail, Notification, Queue};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinancialFeeBookingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 6)->setTime(20, 0));
        config(['availability.hybrid_travel_enabled' => true]);
        Queue::fake(); Mail::fake(); Notification::fake(); Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function fixture(): array
    {
        $rep = User::factory()->create(['role' => 'salesRep']);
        $category = Category::factory()->create(['name' => 'Flash Photos']);
        $photographer = User::factory()->photographer()->create(['metadata' => ['specialties' => ['category:'.$category->id]]]);
        $photo = Service::factory()->unbracketedPhoto(25)->create(['category_id' => $category->id,
            'price' => 125, 'photographer_pay' => 56.25, 'shoot_duration_minutes' => 60]);
        // Match the real product's inconsistent legacy work flags. Its explicit
        // financial classification must win without editing this catalog row.
        $fee = Service::factory()->noIntake()->create(['name' => 'Onsite Cancellation/hold fee',
            'category_id' => Category::factory()->create(['name' => 'Cancellation/Hold'])->id,
            'price' => 60, 'photographer_pay' => 50, 'photographer_required' => true,
            'shoot_duration_minutes' => 60, 'requires_editing' => true, 'financial_only' => true]);
        $shoot = Shoot::factory()->create(['rep_id' => $rep->id, 'photographer_id' => $photographer->id,
            'service_id' => $photo->id, 'status' => 'uploaded', 'workflow_status' => 'uploaded',
            'scheduled_date' => '2026-11-06', 'scheduled_at' => '2026-11-06 15:00:00', 'time' => '10:00',
            'timezone' => 'America/New_York', 'photos_uploaded_at' => now()->subHour(),
            'base_quote' => 125, 'total_quote' => 125, 'tax_amount' => 0, 'tax_percent' => 0, 'state' => 'NJ',
            'payment_status' => 'unpaid', 'property_details' => ['sqft' => 2004]]);
        $unit = $shoot->units()->create(['client_key' => 'bidwell-unit', 'label' => '1', 'kind' => 'unit', 'sqft' => 2004]);
        $line = $shoot->serviceItems()->create(['service_id' => $photo->id, 'shoot_unit_id' => $unit->id,
            'client_key' => 'bidwell-photo', 'price' => 125, 'quantity' => 1, 'photographer_pay' => 56.25,
            'photographer_id' => $photographer->id, 'scheduled_at' => '2026-11-06 15:00:00',
            'duration_minutes' => 60, 'workflow_status' => 'in_progress', 'delivery_status' => 'not_started']);
        $file = ShootFile::create(['shoot_id' => $shoot->id, 'shoot_service_id' => $line->id, 'filename' => 'exterior.arw',
            'stored_filename' => 'exterior.arw', 'path' => 'exterior.arw', 'file_type' => 'image/x-sony-arw',
            'mime_type' => 'image/x-sony-arw', 'file_size' => 10, 'media_type' => 'raw', 'workflow_stage' => 'todo',
            'uploaded_by' => $photographer->id, 'scan_status' => 'clean']);
        Sanctum::actingAs($rep);

        return [$shoot->fresh(), $line->fresh(), $fee, $photographer, $file->fresh()];
    }

    private function payload(Shoot $shoot, Service $fee, ?int $recipient): array
    {
        $unit = $shoot->units()->sole();
        return ['expected_units_revision' => $shoot->units_revision, 'notify_client' => false, 'notify_photographer' => false,
            'service_lines' => [...$shoot->serviceItems()->get()->map(fn ($line) => [
                'shoot_service_id' => $line->id, 'client_key' => $line->client_key,
                'shoot_unit_id' => $line->shoot_unit_id, 'service_id' => $line->service_id,
            ])->all(), ['client_key' => 'bidwell-fee', 'shoot_unit_id' => $unit->id, 'service_id' => $fee->id,
                'quantity' => 1, 'photographer_id' => $recipient, 'scheduled_at' => null,
                'duration_minutes' => 60, 'price' => 999, 'photographer_pay' => 999]]];
    }

    public function test_rep_adds_cancellation_fee_after_upload_without_a_skill_or_another_visit_and_reload_preserves_the_payee(): void
    {
        [$shoot, $photo, $catalog, $photographer, $file] = $this->fixture();
        $before = $shoot->getRawOriginal(); $photoBefore = $photo->getRawOriginal(); $fileBefore = $file->getRawOriginal();
        $capabilities = $photographer->getServiceCapabilities();
        $prepared = app(MultiUnitBookingService::class)->prepare($shoot, $this->payload($shoot, $catalog, $photographer->id), auth()->user());
        $this->assertSame($photographer->id, $prepared['services'][1]['photographer_id']);
        $this->patchJson('/api/shoots/'.$shoot->id, $this->payload($shoot, $catalog, $photographer->id))->assertOk();
        $fee = $shoot->serviceItems()->where('service_id', $catalog->id)->sole();
        $this->assertSame($photographer->id, $fee->photographer_id);
        $this->assertEquals(60, $fee->price); $this->assertEquals(50, $fee->photographer_pay);
        $this->assertSame(0, $fee->duration_minutes); $this->assertNull($fee->scheduled_at);
        $this->assertFalse($fee->is_deliverable); $this->assertSame([], $fee->delivery_requirements);
        $this->assertFalse(app(ShootEditingAssignmentService::class)->serviceRequiresEditing($fee));
        $this->assertSame('financial_only', data_get($fee->payout_snapshot, 'delivery_requirement'));
        $this->assertFalse(data_get($fee->payout_snapshot, 'additional_work', false));
        $this->assertNotNull(app(\App\Services\Invoices\PhotographerPayoutBasis::class)->completion($shoot->fresh(), $fee, $catalog));
        $payout = app(PhotographerPayoutReconciliation::class)->rows(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-07'))
            ->firstWhere('shoot_service_id', $fee->id);
        $this->assertSame($photographer->id, $payout['resolved_photographer_id']);
        $this->assertSame(50.0, $payout['photographer_pay']); $this->assertTrue($payout['eligible']);
        $this->assertSame($photoBefore, $photo->fresh()->getRawOriginal());
        $this->assertSame($fileBefore, $file->fresh()->getRawOriginal());
        $this->assertSame($capabilities, $photographer->fresh()->getServiceCapabilities());
        foreach (['status', 'workflow_status', 'scheduled_at', 'scheduled_date', 'time', 'photographer_id'] as $key)
            $this->assertSame($before[$key], $shoot->fresh()->getRawOriginal($key));
        $windows = app(ShootDurationResolver::class)->windowsForShoot($shoot->fresh());
        $this->assertCount(1, $windows); $this->assertSame(60, $windows[0]['minutes']);
        $this->assertEquals(185, $shoot->fresh()->base_quote);
        $data = $this->getJson('/api/shoots/'.$shoot->id)->assertOk()->json('data');
        $reloadedFee = collect($data['service_items'])->firstWhere('service_id', $catalog->id);
        $this->assertTrue($reloadedFee['financial_only']); $this->assertTrue($reloadedFee['allows_photographer_assignment']);
        $this->assertFalse($reloadedFee['photographer_required']); $this->assertSame(0, $reloadedFee['duration_minutes']);
        $this->assertSame($photographer->id, $reloadedFee['photographer_id']);
        $service = collect($data['services'])->firstWhere('id', (string) $catalog->id);
        $this->assertTrue($service['financial_only']); $this->assertTrue($service['allows_photographer_assignment']);
        $resave = $this->payload($shoot->fresh(), $catalog, $photographer->id);
        array_pop($resave['service_lines']); // Reopen and save the two persisted identities.
        $this->patchJson('/api/shoots/'.$shoot->id, $resave)->assertOk();
        $this->assertSame(2, $shoot->serviceItems()->count());
        $this->assertSame($photographer->id, $fee->fresh()->photographer_id);
        $this->assertSame(0, $fee->fresh()->duration_minutes);
        $this->assertCount(1, app(ShootDurationResolver::class)->windowsForShoot($shoot->fresh()));
        Mail::assertNothingSent(); Notification::assertNothingSent();
    }

    public function test_fee_payee_remains_optional_and_cannot_be_a_client(): void
    {
        [$shoot, , $fee] = $this->fixture();
        $client = User::factory()->create(['role' => 'client']);
        $this->patchJson('/api/shoots/'.$shoot->id, $this->payload($shoot, $fee, $client->id))
            ->assertUnprocessable()->assertJsonValidationErrors('service_lines.1.photographer_id');
        $this->assertSame(1, $shoot->serviceItems()->count());
        $this->patchJson('/api/shoots/'.$shoot->id, $this->payload($shoot->fresh(), $fee, null))->assertOk();
        $booked = $shoot->serviceItems()->where('service_id', $fee->id)->sole();
        $this->assertNull($booked->photographer_id); $this->assertSame(0, $booked->duration_minutes);
        $data = $this->getJson('/api/shoots/'.$shoot->id)->assertOk()->json('data');
        foreach (['service_items', 'services'] as $key) {
            $row = collect($data[$key])->firstWhere($key === 'services' ? 'id' : 'service_id', $key === 'services' ? (string) $fee->id : $fee->id);
            $this->assertNull($row['photographer_id']); $this->assertNull($row['resolved_photographer_id']);
            $this->assertNull($row['photographer']);
        }
        $rows = app(PhotographerPayoutReconciliation::class)->rows(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-07'));
        $this->assertFalse($rows->contains('shoot_service_id', $booked->id));
        $invoice = Invoice::factory()->create(['user_id' => $shoot->photographer_id, 'photographer_id' => $shoot->photographer_id,
            'role' => Invoice::ROLE_PHOTOGRAPHER, 'approval_status' => 'pending', 'status' => 'draft', 'is_paid' => false,
            'amount_paid' => 0, 'billing_period_start' => '2026-11-01', 'billing_period_end' => '2026-11-07']);
        $workflow = app(PayoutInvoiceWorkflow::class);
        $this->assertFalse(collect($workflow->candidates($invoice))->contains('shoot_service_id', $booked->id));
        try {
            $workflow->mutate($invoice, User::factory()->admin()->create(), ['action' => 'add_shoot',
                'shoot_id' => $shoot->id, 'shoot_service_id' => $booked->id, 'reason' => 'Must not inherit header payee']);
            $this->fail('An unassigned fee must not be linked to the header photographer payout.');
        } catch (\Illuminate\Validation\ValidationException $error) {
            $this->assertArrayHasKey('shoot_id', $error->errors());
        }
    }

    public function test_flat_shoot_edit_also_keeps_financial_payee_without_a_capture_duration(): void
    {
        [$shoot, $photo, $fee, $photographer] = $this->fixture();
        $photo->update(['shoot_unit_id' => null, 'client_key' => null]);
        $shoot->units()->delete();
        $this->patchJson('/api/shoots/'.$shoot->id, [
            'services' => [['id' => $photo->service_id], ['id' => $fee->id, 'quantity' => 1, 'duration_minutes' => 60]],
            'service_photographers' => [['service_id' => $fee->id, 'photographer_id' => $photographer->id]],
            'notify_client' => false, 'notify_photographer' => false,
        ])->assertOk();
        $booked = $shoot->serviceItems()->where('service_id', $fee->id)->sole();
        $this->assertSame($photographer->id, $booked->photographer_id);
        $this->assertSame(0, $booked->duration_minutes);
        $this->assertEquals(60, $booked->price); $this->assertEquals(50, $booked->photographer_pay);
        $this->assertCount(1, app(ShootDurationResolver::class)->windowsForShoot($shoot->fresh()));
    }

    public function test_actual_work_still_requires_a_verified_capability(): void
    {
        [$shoot, , , $photographer] = $this->fixture();
        $capture = Service::factory()->videoIntake()->create(['name' => 'Video capture', 'financial_only' => false]);
        $this->assertFalse(app(PhotographerServiceEligibility::class)->canPerform($photographer, $capture));
        try {
            app(MultiUnitBookingService::class)->prepare($shoot, $this->payload($shoot, $capture, $photographer->id), auth()->user());
            $this->fail('Actual capture without a verified specialty must be rejected.');
        } catch (\Illuminate\Validation\ValidationException $error) {
            $this->assertArrayHasKey('services.1.photographer_id', $error->errors());
        }
        $this->assertSame(1, $shoot->serviceItems()->count());
    }

    public function test_delivered_flat_rep_fee_addition_keeps_the_separate_payee_mapping_without_reopening_work(): void
    {
        [$shoot, $photo, $fee, $photographer] = $this->fixture();
        $photo->update(['shoot_unit_id' => null, 'client_key' => null, 'workflow_status' => 'delivered', 'delivery_status' => 'delivered']);
        $shoot->units()->delete();
        $shoot->forceFill(['status' => 'delivered', 'workflow_status' => 'delivered', 'delivery_status' => 'delivered',
            'admin_verified_at' => now()->subHour(), 'completed_at' => now()->subHour()])->saveQuietly();
        $this->patchJson('/api/shoots/'.$shoot->id, [
            'services' => [['id' => $photo->service_id], ['id' => $fee->id, 'quantity' => 1, 'duration_minutes' => 0]],
            'service_photographers' => [['service_id' => $fee->id, 'photographer_id' => $photographer->id]],
            'notify_client' => false, 'notify_photographer' => false,
        ])->assertOk();
        $booked = $shoot->serviceItems()->where('service_id', $fee->id)->sole();
        $this->assertSame($photographer->id, $booked->photographer_id); $this->assertSame(0, $booked->duration_minutes);
        $this->assertNull($booked->scheduled_at); $this->assertFalse($booked->is_deliverable);
        $this->assertSame('delivered', $shoot->fresh()->workflow_status);
        $this->assertFalse(app(\App\Services\Shoots\ShootActiveWorkLifecycle::class)->hasActiveWork($shoot->fresh()));
    }

    public function test_catalog_classification_does_not_shorten_existing_work_or_change_its_price_or_pay(): void
    {
        [$shoot, $work] = $this->fixture();
        $before = $work->getRawOriginal();
        $work->service->forceFill(['financial_only' => true])->saveQuietly();
        $this->assertSame($before, $work->fresh()->getRawOriginal());
        $windows = app(ShootDurationResolver::class)->windowsForShoot($shoot->fresh());
        $this->assertCount(1, $windows); $this->assertSame(60, $windows[0]['minutes']);
        $summary = collect($this->getJson('/api/shoots/'.$shoot->id)->assertOk()->json('data.service_items'))->firstWhere('id', $work->id);
        $this->assertFalse($summary['financial_only']); $this->assertTrue($summary['photographer_required']);
        $unqualified = User::factory()->photographer()->create(['metadata' => ['specialties' => ['category:unrelated']]]);
        $edit = $this->payload($shoot->fresh(), $work->service->fresh(), $unqualified->id);
        array_pop($edit['service_lines']);
        $edit['service_lines'][0]['photographer_id'] = $unqualified->id;
        $edit['service_lines'][0]['is_deliverable'] = false;
        $edit['service_lines'][0]['_booked_financial_only'] = true;
        try {
            app(MultiUnitBookingService::class)->prepare($shoot->fresh(), $edit, auth()->user());
            $this->fail('A catalog reclassification must not waive the booked work capability.');
        } catch (\Illuminate\Validation\ValidationException $error) {
            $this->assertArrayHasKey('services.0.photographer_id', $error->errors());
        }
    }

    public function test_financial_payout_reuses_its_recipients_capture_on_multi_photographer_and_reopened_orders(): void
    {
        [$shoot, $photo, $catalog, $photographer] = $this->fixture();
        $other = User::factory()->photographer()->create();
        $otherService = Service::factory()->videoIntake()->create();
        $shoot->serviceItems()->create(['service_id' => $otherService->id, 'shoot_unit_id' => $photo->shoot_unit_id,
            'client_key' => 'other-video', 'price' => 100, 'quantity' => 1, 'photographer_id' => $other->id,
            'workflow_status' => 'scheduled', 'delivery_status' => 'not_started', 'duration_minutes' => 60]);
        $fee = $shoot->serviceItems()->create(['service_id' => $catalog->id, 'shoot_unit_id' => $photo->shoot_unit_id,
            'client_key' => 'recipient-fee', 'price' => 60, 'quantity' => 1, 'photographer_pay' => 50,
            'photographer_id' => $photographer->id, 'duration_minutes' => 0,
            'workflow_status' => 'pending', 'delivery_status' => 'not_started']);
        $basis = app(\App\Services\Invoices\PhotographerPayoutBasis::class);
        $this->assertNotNull($basis->completion($shoot->fresh(), $fee));
        $this->assertSame(0, $fee->files()->count());
        $photo->forceFill(['workflow_status' => 'delivered', 'delivery_status' => 'delivered', 'delivered_at' => now()->subHours(2)])->saveQuietly();
        $deliveredAt = $photo->fresh()->getRawOriginal('delivered_at');
        $shoot->forceFill(['active_work_started_at' => now()->subMinutes(30), 'active_work_is_reopened' => true,
            'active_work_cycle_key' => 'reopened-evidence'])->saveQuietly();
        $this->assertSame($deliveredAt, $basis->completion($shoot->fresh(), $fee));
        $fee->photographer_id = User::factory()->photographer()->create()->id;
        $this->assertNull($basis->completion($shoot->fresh(), $fee));
    }
}
