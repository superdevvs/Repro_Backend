<?php

namespace Tests\Feature;

use App\Jobs\{CreateCubiCasaOrderJob, MaybeSendShootSummary, SyncShootIguideJob};
use App\Models\{InvoiceItem, Payment, PaymentServiceAllocation, Service, Shoot, ShootFile, ShootService, User};
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Mail, Notification, Queue, Storage};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeliveredSalesServiceCorrectionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests(); Mail::fake(); Notification::fake(); Queue::fake(); Storage::fake('public');
    }

    private function fixture(): array
    {
        $rep = User::factory()->create(['role' => 'salesRep']);
        $photographer = User::factory()->photographer()->create();
        $shoot = Shoot::factory()->create(['status' => 'delivered', 'workflow_status' => 'delivered', 'delivery_status' => 'delivered',
            'rep_id' => $rep->id, 'photographer_id' => $photographer->id, 'scheduled_at' => '2025-01-02 15:00:00',
            'scheduled_date' => '2025-01-02', 'time' => '10:00', 'timezone' => 'America/New_York',
            'completed_at' => '2025-01-03 15:00:00', 'admin_verified_at' => '2025-01-03 15:00:00', 'photos_uploaded_at' => '2025-01-03 12:00:00',
            'state' => 'VA', 'base_quote' => 100, 'tax_percent' => 0, 'tax_amount' => 0, 'total_quote' => 100]);
        $service = Service::factory()->unbracketedPhoto()->create(['price' => 999, 'allow_multiple' => true, 'photographer_pay' => 30]);
        $item = ShootService::create(['shoot_id' => $shoot->id, 'service_id' => $service->id, 'price' => 100, 'quantity' => 1,
            'photographer_pay' => 20, 'duration_minutes' => 60, 'photographer_id' => $photographer->id,
            'scheduled_at' => $shoot->scheduled_at, 'workflow_status' => 'delivered', 'delivery_status' => 'delivered',
            'delivered_at' => '2025-01-03 15:00:00', 'is_deliverable' => true]);
        Sanctum::actingAs($rep);
        return [$shoot->fresh(), $item->fresh(), $service->fresh(), $rep];
    }

    private function save(Shoot $shoot, array $payload)
    {
        return $this->patchJson('/api/shoots/'.$shoot->id, $payload + ['notify_client' => false, 'notify_photographer' => false]);
    }

    public function test_rep_adds_a_catalog_priced_service_without_touching_completed_work_or_past_appointment(): void
    {
        [$shoot, $item] = $this->fixture();
        $before = $item->getRawOriginal();
        $rootBefore = $shoot->only(['status', 'workflow_status', 'delivery_status', 'scheduled_at', 'scheduled_date', 'time', 'photographer_id']);
        $addon = Service::factory()->videoIntake()->create(['price' => 35, 'photographer_pay' => 12]);
        $this->save($shoot, ['services' => [['id' => $item->service_id, 'price' => 1], ['id' => $addon->id, 'price' => 1, 'photographer_pay' => 1]]])->assertOk();
        $this->assertSame($before, $item->fresh()->getRawOriginal());
        $this->assertEquals($rootBefore, $shoot->fresh()->only(array_keys($rootBefore)));
        $new = $shoot->serviceItems()->where('service_id', $addon->id)->sole();
        $this->assertSame(35.0, (float) $new->price);
        $this->assertSame(12.0, (float) $new->photographer_pay);
        $this->assertNull($new->scheduled_at); $this->assertNull($new->photographer_id); $this->assertNull($new->editor_id);
        $basis = app(\App\Services\Invoices\PhotographerPayoutBasis::class);
        $this->assertNotNull($basis->completion($shoot->fresh(), $item->fresh()));
        $this->assertNull($basis->completion($shoot->fresh(), $new));
        $basis->capture($new, $shoot->fresh(), $addon);
        $this->assertTrue($new->payout_snapshot['billing_correction_only']);
        $new->ready_at = '2026-10-16 15:00:00';
        $this->assertNotNull($basis->completion($shoot->fresh(), $new));
        Queue::assertNotPushed(CreateCubiCasaOrderJob::class); Queue::assertNotPushed(SyncShootIguideJob::class); Queue::assertNotPushed(MaybeSendShootSummary::class);
        Mail::assertNothingSent(); Notification::assertNothingSent();
    }

    public function test_quantity_correction_preserves_paid_charge_and_allocation_identity_and_agreed_unit_pay(): void
    {
        [$shoot, $item, $service] = $this->fixture();
        $invoice = app(InvoiceService::class)->generateForShoot($shoot);
        $payment = Payment::factory()->create(['shoot_id' => $shoot->id, 'invoice_id' => $invoice->id, 'amount' => 100, 'status' => Payment::STATUS_COMPLETED]);
        $allocation = PaymentServiceAllocation::create(['payment_id' => $payment->id, 'shoot_service_id' => $item->id, 'amount' => 100]);
        $paymentBefore = $payment->fresh()->getRawOriginal();
        $charge = $invoice->items()->where('type', InvoiceItem::TYPE_CHARGE)->sole();
        $charge->update(['meta' => array_merge($charge->meta ?? [], ['keep_reference' => 'booked-reference'])]);
        $service->update(['photographer_pay' => 99]);
        $this->save($shoot, ['service_items' => [['service_id' => $service->id, 'quantity' => 2]]])->assertOk();
        $this->assertSame($paymentBefore, $payment->fresh()->getRawOriginal());
        $this->assertSame(100.0, (float) $allocation->fresh()->amount);
        $this->assertSame(200.0, (float) $invoice->fresh()->total);
        $this->assertSame($charge->id, $invoice->items()->where('type', InvoiceItem::TYPE_CHARGE)->sole()->id);
        $this->assertSame('booked-reference', $charge->fresh()->meta['keep_reference']);
        $this->assertSame(20.0, (float) $item->fresh()->photographer_pay);
        $this->assertSame('delivered', $item->fresh()->delivery_status);
        $this->assertSame('delivered', $shoot->fresh()->delivery_status);
        $this->assertSame('partial', $shoot->fresh()->payment_status);
    }

    public function test_removal_requires_current_confirmation_and_preserves_the_media_file_and_payment(): void
    {
        [$shoot, $item] = $this->fixture();
        $other = Service::factory()->noIntake()->create(['price' => 25, 'photographer_required' => false]);
        $shoot->services()->attach($other->id, ['price' => 25, 'quantity' => 1]);
        $payment = Payment::factory()->create(['shoot_id' => $shoot->id, 'invoice_id' => null, 'amount' => 100, 'status' => Payment::STATUS_COMPLETED]);
        $paymentBefore = $payment->fresh()->getRawOriginal();
        Storage::disk('public')->put('completed/protected.jpg', 'photo-bytes');
        $file = ShootFile::create(['shoot_id' => $shoot->id, 'shoot_service_id' => $item->id, 'filename' => 'protected.jpg',
            'stored_filename' => 'protected.jpg', 'path' => 'completed/protected.jpg', 'file_size' => 11, 'file_type' => 'image/jpeg',
            'media_type' => 'edited', 'workflow_stage' => 'completed', 'uploaded_by' => $shoot->photographer_id]);
        $payload = ['services' => [['id' => $other->id]]];
        $confirmation = $this->save($shoot, $payload)->assertConflict()->assertJsonPath('code', 'service_detach_confirmation_required');
        $this->assertSame(1, $confirmation->json('impact.files_detached'));
        $this->save($shoot, $payload + ['confirm_service_detach' => true, 'service_detach_confirmation_token' => $confirmation->json('confirmation_token')])->assertOk();
        $this->assertNull($file->fresh()->shoot_service_id);
        Storage::disk('public')->assertExists('completed/protected.jpg');
        $this->assertSame($paymentBefore, $payment->fresh()->getRawOriginal());
        $this->assertSame('delivered', $shoot->fresh()->workflow_status);
    }

    public function test_multi_unit_corrections_keep_execution_identity_and_unit_schedule_while_adding_per_unit_services(): void
    {
        [$shoot, $first, $service] = $this->fixture();
        $unitA = $shoot->units()->create(['client_key' => 'a', 'label' => 'A', 'kind' => 'unit', 'sqft' => 1000]);
        $unitB = $shoot->units()->create(['client_key' => 'b', 'label' => 'B', 'kind' => 'unit', 'sqft' => 1200]);
        $first->update(['shoot_unit_id' => $unitA->id, 'client_key' => 'line-a']);
        $second = $shoot->serviceItems()->create(['service_id' => $service->id, 'shoot_unit_id' => $unitB->id, 'client_key' => 'line-b',
            'price' => 110, 'quantity' => 1, 'workflow_status' => 'delivered', 'delivery_status' => 'delivered']);
        $unitsBefore = $shoot->units()->get()->map->getRawOriginal()->all();
        $secondBefore = $second->fresh()->getRawOriginal();
        $addon = Service::factory()->noIntake()->create(['price' => 35, 'photographer_required' => false]);
        $this->save($shoot, ['expected_units_revision' => $shoot->units_revision, 'service_lines' => [
            ['shoot_service_id' => $first->id, 'client_key' => 'line-a', 'unit_client_key' => 'a', 'service_id' => $service->id, 'quantity' => 2],
            ['shoot_service_id' => $second->id, 'client_key' => 'line-b', 'unit_client_key' => 'b', 'service_id' => $service->id],
            ['client_key' => 'new-fee', 'unit_client_key' => 'b', 'service_id' => $addon->id],
        ]])->assertOk();
        $this->assertSame($unitsBefore, $shoot->units()->get()->map->getRawOriginal()->all());
        $this->assertSame($secondBefore, $second->fresh()->getRawOriginal());
        $this->assertSame(2, $first->fresh()->quantity);
        $this->assertSame('2025-01-02', $shoot->fresh()->scheduled_date->toDateString());
        $this->assertNull($shoot->serviceItems()->where('service_id', $addon->id)->sole()->scheduled_at);
        $new = $shoot->serviceItems()->where('service_id', $addon->id)->sole();
        $remove = ['expected_units_revision' => $shoot->fresh()->units_revision, 'service_lines' => [
            ['shoot_service_id' => $second->id, 'client_key' => 'line-b', 'unit_client_key' => 'b', 'service_id' => $service->id],
            ['shoot_service_id' => $new->id, 'client_key' => 'new-fee', 'unit_client_key' => 'b', 'service_id' => $addon->id],
        ]];
        $confirmation = $this->save($shoot, $remove)->assertConflict();
        $this->assertSame($first->id, $confirmation->json('impact.removed_services.0.shoot_service_id'));
        $this->save($shoot, $remove + ['confirm_service_detach' => true, 'service_detach_confirmation_token' => $confirmation->json('confirmation_token')])->assertOk();
        $this->assertNotNull($second->fresh());
        $this->assertNull($first->fresh());
    }

    public function test_permissions_historical_imports_and_schedule_production_fields_remain_locked(): void
    {
        [$shoot, $item, $service, $rep] = $this->fixture();
        foreach ([['services' => [['id' => $service->id, 'scheduled_at' => '2027-01-01T10:00:00Z']]],
            ['services' => [['id' => $service->id]], 'workflow_status' => 'editing'],
            ['services' => [['id' => $service->id]], 'photographer_id' => null]] as $payload) $this->save($shoot, $payload)->assertForbidden();
        $addon = Service::factory()->noIntake()->create(['photographer_required' => false]);
        $payload = ['services' => [['id' => $service->id], ['id' => $addon->id]]];
        $rep->update(['permission_overrides' => ['allow' => [], 'deny' => ['shoots-update']]]);
        $this->save($shoot, $payload)->assertForbidden();
        $rep->update(['permission_overrides' => null]);
        $shoot->update(['external_booking_payload' => ['legacy_migration' => ['historical_payments_only' => true]]]);
        $this->save($shoot, $payload)->assertForbidden();
        $this->assertSame([$item->id], $shoot->serviceItems()->pluck('id')->all());
    }

    public function test_recorded_compensation_still_prevents_service_removal(): void
    {
        [$shoot, $item] = $this->fixture();
        \Illuminate\Support\Facades\DB::table('shoot_compensations')->insert([
            'shoot_id' => $shoot->id, 'shoot_service_id' => $item->id, 'scope_key' => 'booked-service',
            'recipient_type' => 'photographer', 'mode' => 'standard', 'reason_code' => 'fixture', 'policy_version' => 'fixture',
        ]);
        $other = Service::factory()->noIntake()->create(['price' => 25, 'photographer_required' => false]);
        $this->save($shoot, ['services' => [['id' => $other->id]]])->assertUnprocessable()
            ->assertJsonPath('message', 'This service has recorded payouts, compensation or provider work and needs an admin review before removal.');
        $this->assertNotNull($item->fresh());
    }

    public function test_complimentary_reshoot_unit_service_plan_stays_immutable(): void
    {
        [$shoot, $item, $service] = $this->fixture();
        $shoot->update(['shoot_type' => Shoot::SHOOT_TYPE_COMPLIMENTARY_RESHOOT]);
        $unit = $shoot->units()->create(['client_key' => 'a', 'label' => 'A', 'kind' => 'unit', 'sqft' => 1000]);
        $item->update(['shoot_unit_id' => $unit->id, 'client_key' => 'line-a']);
        $addon = Service::factory()->noIntake()->create(['photographer_required' => false]);
        $this->save($shoot, ['expected_units_revision' => $shoot->units_revision, 'service_lines' => [
            ['shoot_service_id' => $item->id, 'client_key' => 'line-a', 'unit_client_key' => 'a', 'service_id' => $service->id],
            ['client_key' => 'new-fee', 'unit_client_key' => 'a', 'service_id' => $addon->id],
        ]])->assertUnprocessable();
        $this->assertSame([$item->id], $shoot->serviceItems()->pluck('id')->all());
    }

    private function photoUpgradeFixture(): array
    {
        [$shoot, $item, $original, $rep] = $this->fixture();
        $shoot->update(['property_details' => ['sqft' => 2500]]);
        $original->update(['name' => '25 HDR Photos', 'photo_count' => 25, 'uses_hdr_brackets' => true, 'pricing_type' => 'variable']);
        $item->update(['contracted_photo_count' => 25, 'bracket_mode' => 5,
            'ready_at' => '2025-01-03 14:00:00', 'editing_completed_at' => '2025-01-03 14:00:00',
            'editor_id' => User::factory()->create(['role' => 'editor'])->id]);
        $upgrade = Service::factory()->bracketedPhoto(35)->create(['name' => '35 HDR Photos',
            'category_id' => $original->category_id, 'price' => 199, 'photographer_pay' => 89.55, 'shoot_duration_minutes' => 90, 'pricing_type' => 'variable']);
        $original->sqftRanges()->create(['sqft_from' => 1501, 'sqft_to' => 3000, 'price' => 175, 'photographer_pay' => 78.75, 'duration' => 60, 'photo_count' => 25]);
        $upgrade->sqftRanges()->create(['sqft_from' => 1501, 'sqft_to' => 3000, 'price' => 199, 'photographer_pay' => 89.55, 'duration' => 90, 'photo_count' => 35]);
        $shoot->photographer->update(['metadata' => ['specialties' => [(string) $original->id, (string) $upgrade->id]]]);
        return [$shoot, $item->fresh(), $original, $rep, $upgrade];
    }

    public static function packageCorrectionRoles(): array
    {
        return [['salesRep'], ['admin'], ['superadmin'], ['editing_manager']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('packageCorrectionRoles')]
    public function test_photo_count_upgrade_keeps_execution_media_payment_links_and_photographer_visibility(string $role): void
    {
        [$shoot, $item, $original, $rep, $upgrade] = $this->photoUpgradeFixture();
        if ($role !== 'salesRep') Sanctum::actingAs(User::factory()->create(['role' => $role]));
        $executionFields = ['id','created_at','scheduled_at','duration_minutes','photographer_id','editor_id','editing_completed_at',
            'bracket_mode','workflow_status','delivery_status','ready_at','delivered_at','is_deliverable'];
        $before = $item->only($executionFields);
        $invoice = app(InvoiceService::class)->generateForShoot($shoot);
        $chargeId = $invoice->items()->where('type', InvoiceItem::TYPE_CHARGE)->sole()->id;
        $payment = Payment::factory()->create(['shoot_id' => $shoot->id, 'invoice_id' => $invoice->id, 'amount' => 100, 'status' => Payment::STATUS_COMPLETED]);
        $allocation = PaymentServiceAllocation::create(['payment_id' => $payment->id, 'shoot_service_id' => $item->id, 'amount' => 100]);
        $paymentBefore = $payment->fresh()->getRawOriginal();
        $file = ShootFile::create(['shoot_id' => $shoot->id, 'shoot_service_id' => $item->id, 'filename' => 'delivered.jpg',
            'stored_filename' => 'delivered.jpg', 'path' => 'completed/delivered.jpg', 'file_size' => 11, 'file_type' => 'image/jpeg',
            'media_type' => 'edited', 'workflow_stage' => 'verified', 'uploaded_by' => $shoot->photographer_id]);

        $this->save($shoot, ['services' => [['id' => $upgrade->id]],
            'service_items' => [['service_id' => $upgrade->id, 'quantity' => 1, 'scheduled_at' => null]]])->assertOk();
        $updated = $item->fresh();
        $this->assertNotNull($updated);
        $this->assertEquals($before, $updated->only($executionFields));
        $this->assertSame($upgrade->id, $updated->service_id);
        $this->assertSame(35, $updated->contracted_photo_count);
        $this->assertSame(199.0, (float) $updated->price);
        $this->assertSame(89.55, (float) $updated->photographer_pay);
        $this->assertSame($original->id, $updated->payout_snapshot['previous_basis']['service_id']);
        $this->assertTrue($updated->payout_snapshot['review_required']);
        $this->assertSame($item->id, $file->fresh()->shoot_service_id);
        $this->assertSame($item->id, $allocation->fresh()->shoot_service_id);
        $this->assertSame($paymentBefore, $payment->fresh()->getRawOriginal());
        $this->assertSame($chargeId, $invoice->items()->where('type', InvoiceItem::TYPE_CHARGE)->sole()->id);
        $this->assertSame('delivered', $shoot->fresh()->workflow_status);
        $this->assertSame(1, $shoot->serviceItems()->count());
        Sanctum::actingAs(User::findOrFail($shoot->photographer_id));
        $this->getJson('/api/shoots/'.$shoot->id)->assertOk()->assertJsonFragment(['name' => '35 HDR Photos', 'photo_count' => 35]);
        Mail::assertNothingSent(); Notification::assertNothingSent();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('multiUnitCorrectionRoles')]
    public function test_multi_unit_photo_upgrade_keeps_only_the_replaced_apartments_history(string $role): void
    {
        [$shoot, $item, $original, $rep, $upgrade] = $this->photoUpgradeFixture();
        if ($role !== 'salesRep') Sanctum::actingAs(User::factory()->create(['role' => $role]));
        $unitA = $shoot->units()->create(['client_key' => 'a', 'label' => 'A', 'kind' => 'unit', 'sqft' => 2500]);
        $unitB = $shoot->units()->create(['client_key' => 'b', 'label' => 'B', 'kind' => 'unit', 'sqft' => 2500]);
        $item->update(['shoot_unit_id' => $unitA->id, 'client_key' => 'original-a']);
        $other = $shoot->serviceItems()->create(['service_id' => $original->id, 'shoot_unit_id' => $unitB->id,
            'client_key' => 'original-b', 'price' => 100, 'quantity' => 1, 'scheduled_at' => $shoot->scheduled_at->copy()->addHours(2),
            'duration_minutes' => 60, 'photographer_pay' => 20, 'contracted_photo_count' => 25, 'bracket_mode' => 5,
            'photographer_id' => $shoot->photographer_id, 'workflow_status' => 'delivered', 'delivery_status' => 'delivered']);
        $before = $other->fresh()->getRawOriginal();
        $this->save($shoot, ['expected_units_revision' => $shoot->units_revision, 'service_lines' => [
            ['client_key' => 'replacement-a', 'unit_client_key' => 'a', 'service_id' => $upgrade->id],
            ['shoot_service_id' => $other->id, 'client_key' => 'original-b', 'unit_client_key' => 'b', 'service_id' => $original->id],
        ]])->assertOk();
        $this->assertSame($upgrade->id, $item->fresh()->service_id);
        $this->assertSame('original-a', $item->fresh()->client_key);
        $this->assertSame('delivered', $item->fresh()->workflow_status);
        $this->assertSame($before, $other->fresh()->getRawOriginal());
    }

    public static function multiUnitCorrectionRoles(): array
    {
        return [['salesRep'], ['admin']];
    }

    public static function unfinishedProductionStates(): array
    {
        return [['uploaded'], ['editing'], ['review'], ['ready']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unfinishedProductionStates')]
    public function test_upgrade_preserves_in_progress_production_without_marking_it_delivered(string $stage): void
    {
        [$shoot, $item, $original, $rep, $upgrade] = $this->photoUpgradeFixture();
        $shoot->update(['status' => $stage, 'workflow_status' => $stage, 'delivery_status' => 'not_started', 'completed_at' => null, 'admin_verified_at' => null]);
        $item->update(['workflow_status' => 'in_progress', 'delivery_status' => 'not_started', 'ready_at' => null, 'delivered_at' => null, 'editing_completed_at' => null]);
        $this->save($shoot, ['services' => [['id' => $upgrade->id]]])->assertOk();
        $this->assertSame($upgrade->id, $item->fresh()->service_id);
        $this->assertSame('in_progress', $item->fresh()->workflow_status);
        $this->assertSame('not_started', $item->fresh()->delivery_status);
        $this->assertSame($stage, $shoot->fresh()->workflow_status);
    }

    public function test_adding_another_photo_package_does_not_inherit_completed_work(): void
    {
        [$shoot, $item, $original, $rep, $upgrade] = $this->photoUpgradeFixture();
        $this->save($shoot, ['services' => [['id' => $original->id], ['id' => $upgrade->id]]])->assertOk();
        $new = $shoot->serviceItems()->where('service_id', $upgrade->id)->sole();
        $this->assertNotSame($item->id, $new->id);
        $this->assertSame('pending', $new->workflow_status);
        $this->assertNull($new->scheduled_at);
        $this->assertSame('delivered', $item->fresh()->workflow_status);
    }

    public function test_ambiguous_photo_package_changes_still_require_service_removal_confirmation(): void
    {
        [$shoot, $item, $original, $rep, $upgrade] = $this->photoUpgradeFixture();
        $second = Service::factory()->bracketedPhoto(45)->create(['category_id' => $original->category_id]);
        $this->save($shoot, ['services' => [['id' => $upgrade->id], ['id' => $second->id]]])
            ->assertConflict()->assertJsonPath('impact.removed_services.0.shoot_service_id', $item->id);
        $this->assertSame($original->id, $item->fresh()->service_id);
    }

    public function test_different_capture_types_do_not_reuse_history_even_with_forged_replacement_metadata(): void
    {
        [$shoot, $item, $original, $rep, $upgrade] = $this->photoUpgradeFixture();
        $upgrade->update(['uses_hdr_brackets' => false]);
        $this->save($shoot, ['services' => [['id' => $upgrade->id, 'replaces_shoot_service_id' => $item->id]]])
            ->assertConflict()->assertJsonPath('impact.removed_services.0.shoot_service_id', $item->id);
    }

    public function test_package_upgrade_preserves_existing_studio_access_and_does_not_generate_again(): void
    {
        [$shoot, $item, $original, $rep, $upgrade] = $this->photoUpgradeFixture();
        $workspace = \App\Models\StudioWorkspace::create(['shoot_id' => $shoot->id, 'created_by' => $rep->id,
            'name' => 'Existing editing work', 'preset_id' => 'listing-ready', 'status' => 'completed', 'media' => [], 'config' => [],
            'shoot_service_ids' => [$original->id], 'shoot_service_item_ids' => null, 'outputs' => [['id' => 'saved-output']]]);
        $this->save($shoot, ['services' => [['id' => $upgrade->id]]])->assertOk();
        $this->assertSame([$item->id], \App\Services\Studio\WorkspaceServiceScope::lineIds($workspace->fresh()));
        $this->assertSame([['id' => 'saved-output']], $workspace->fresh()->outputs);
        $this->assertSame('completed', $workspace->fresh()->status);
    }

    public static function separateCaptureCases(): array
    {
        return [['future'], ['reassignment'], ['reschedule'], ['cancelled']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('separateCaptureCases')]
    public function test_other_visits_and_cancelled_work_cannot_inherit_delivery(string $case): void
    {
        [$shoot, $item, $original, $rep, $upgrade] = $this->photoUpgradeFixture();
        $row = ['id' => $upgrade->id, 'quantity' => 1];
        if ($case === 'future') $item->update(['scheduled_at' => now()->addWeek()]);
        if ($case === 'reassignment') $row['photographer_id'] = User::factory()->photographer()->create()->id;
        if ($case === 'reschedule') $row['scheduled_at'] = '2025-01-05 15:00:00';
        if ($case === 'cancelled') $item->update(['workflow_status' => 'cancelled']);
        $rows = app(\App\Services\Shoots\CompletedPhotoPackageReplacement::class)->resolve($shoot, [$row]);
        $this->assertArrayNotHasKey('replaces_shoot_service_id', $rows[0]);
        $this->assertSame($original->id, $item->fresh()->service_id);
    }

    public static function deliveredStates(): array
    {
        return [['delivered', 'delivered'], ['delivered', 'ready_for_client'], ['delivered', 'admin_verified'],
            ['delivered', 'scheduled'], ['scheduled', 'delivered'], ['ready_for_client', 'ready_for_client']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('deliveredStates')]
    public function test_delivered_aliases_and_mixed_active_states_use_the_same_guarded_service_correction(string $status, string $workflow): void
    {
        [$shoot, $item, $service] = $this->fixture();
        $shoot->update(['status' => $status, 'workflow_status' => $workflow]);
        $this->save($shoot, ['services' => [['id' => $service->id, 'duration_minutes' => 90]]])->assertForbidden();
        $this->assertSame(60, $item->fresh()->duration_minutes);
        $other = Service::factory()->noIntake()->create(['price' => 25, 'photographer_required' => false]);
        $payload = ['services' => [['id' => $other->id]]];
        $confirmation = $this->save($shoot, $payload)->assertConflict()->assertJsonPath('code', 'service_detach_confirmation_required');
        $this->save($shoot, $payload + ['confirm_service_detach' => true, 'service_detach_confirmation_token' => $confirmation->json('confirmation_token')])->assertOk();
        $this->assertSame($status, $shoot->fresh()->status);
        $this->assertSame($workflow, $shoot->fresh()->workflow_status);
        $this->assertFalse(app(\App\Services\Shoots\ShootServiceChangeGuard::class)->canRemoveAllServices($shoot->fresh(), auth()->user()));
        $this->assertNull($item->fresh());
    }
}
