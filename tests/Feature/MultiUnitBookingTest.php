<?php

namespace Tests\Feature;

use App\Http\Resources\ShootResource;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\Shoots\MultiUnitBookingService;
use App\Services\Shoots\ShootEditablePayloadService;
use App\Services\Shoots\ShootMutationSupportService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MultiUnitBookingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $client;

    private Service $service;

    private Shoot $shoot;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->client = User::factory()->create(['role' => 'client']);
        $this->service = Service::factory()->create(['price' => 100, 'pricing_type' => 'fixed', 'photographer_pay' => 50]);
        $this->shoot = Shoot::factory()->create(['client_id' => $this->client->id, 'photographer_id' => null, 'scheduled_at' => null, 'scheduled_date' => null, 'time' => null, 'base_quote' => 200, 'total_quote' => 200]);
        Sanctum::actingAs($this->admin);
    }

    private function payload(int $count = 2): array
    {
        return [
            'units' => array_map(fn ($i) => ['client_key' => "unit-$i", 'label' => "Unit $i", 'kind' => 'unit', 'sqft' => $i % 2 ? 900 : 1900, 'beds' => 2, 'baths' => 1.5, 'access_notes' => "Access $i"], range(1, $count)),
            'service_lines' => array_map(fn ($i) => ['client_key' => "line-$i", 'unit_client_key' => "unit-$i", 'service_id' => $this->service->id, 'quantity' => 1], range(1, $count)),
        ];
    }

    private function persist(array $payload): void
    {
        $service = app(MultiUnitBookingService::class);
        DB::transaction(fn () => $service->persist($this->shoot, $service->prepare($this->shoot, $payload, $this->admin)));
        $this->shoot->refresh();
    }

    private function existingPayload(): array
    {
        return [
            'expected_units_revision' => $this->shoot->units_revision,
            'units' => $this->shoot->units->toArray(),
            'service_lines' => $this->shoot->serviceItems->map(fn ($line) => ['shoot_service_id' => $line->id, 'client_key' => $line->client_key, 'shoot_unit_id' => $line->shoot_unit_id, 'service_id' => $line->service_id])->all(),
        ];
    }

    public function test_one_hundred_units_keep_distinct_service_lines_prices_and_resource_identity(): void
    {
        $this->service->update(['pricing_type' => 'variable']);
        $this->service->sqftRanges()->create(['sqft_from' => 1, 'sqft_to' => 1000, 'price' => 150, 'photographer_pay' => 60, 'duration' => 60]);
        $this->service->sqftRanges()->create(['sqft_from' => 1001, 'sqft_to' => 3000, 'price' => 250, 'photographer_pay' => 90, 'duration' => 120]);
        $this->persist($this->payload(100));
        $this->assertSame(100, $this->shoot->units()->count());
        $this->assertSame(100, $this->shoot->serviceItems()->count());
        $this->assertSame(20000.0, (float) $this->shoot->serviceItems()->sum('price'));
        $this->assertSame(7500.0, (float) $this->shoot->serviceItems()->sum('photographer_pay'));
        $data = (new ShootResource($this->shoot))->resolve(request());
        $this->assertCount(100, $data['units']);
        $this->assertCount(100, $data['service_lines']);
        $this->assertCount(100, collect($data['services'])->pluck('shoot_service_id')->unique());
        $this->assertSame('Unit 1', $data['service_lines'][0]['unit_label']);
        $this->assertSame(60, $data['service_lines'][0]['duration_minutes']);
    }

    public function test_create_and_edit_api_round_trips_one_hundred_units_without_flat_catalog_rows(): void
    {
        Sanctum::actingAs($this->client);
        $payload = array_merge($this->payload(100), ['address' => '400 Main Street', 'city' => 'Arlington', 'state' => 'VA', 'zip' => '22201']);
        $response = $this->postJson('/api/shoots', $payload)->assertCreated();
        $id = $response->json('data.id');
        $this->assertNotNull($id);
        $created = Shoot::findOrFail($id);
        $this->assertSame(100, $created->serviceItems()->count());
        $this->assertSame(10000.0, (float) $created->base_quote);
        $this->assertSame(1, $created->units_revision);
        $ids = $created->serviceItems()->pluck('id')->all();
        $this->shoot = $created;
        $edit = $this->existingPayload();
        $edit['units'][99]['label'] = 'Top floor';
        Sanctum::actingAs($this->admin);
        $this->patchJson('/api/shoots/'.$id, $edit)->assertOk()->assertJsonCount(100, 'data.units')->assertJsonCount(100, 'data.service_lines');
        $this->assertSame($ids, $created->serviceItems()->pluck('id')->all());
        $this->assertSame(2, $created->fresh()->units_revision);
        $this->assertSame('Top floor', $created->units()->where('client_key', 'unit-100')->value('label'));
        $detail = $this->getJson('/api/shoots/'.$id)->assertOk()->assertJsonCount(100, 'data.units')->assertJsonCount(100, 'data.service_lines')->assertJsonPath('data.units_revision', 2)->json('data');
        $this->assertCount(100, collect($detail['services'])->pluck('shoot_service_id')->unique());
        $this->assertSame('Unit 1', $detail['services'][0]['unit_label']);
        $listing = $this->getJson('/api/shoots?tab=requested&skip_cache=1')->assertOk()->json('data');
        $listed = collect($listing)->firstWhere('id', (int) $id);
        $this->assertNotNull($listed);
        $this->assertCount(100, $listed['units']);
        $this->assertCount(100, $listed['service_lines']);
    }

    public function test_approval_rejects_unassigned_unit_lines_before_mutation(): void
    {
        $this->persist($this->payload());
        $this->shoot->update(['status' => 'requested', 'workflow_status' => 'requested']);
        $this->postJson('/api/shoots/'.$this->shoot->id.'/approve', ['notify_client' => false, 'notify_photographer' => false])
            ->assertUnprocessable()->assertJsonValidationErrors('service_lines.0');
        $this->assertSame('requested', $this->shoot->fresh()->status);
    }

    public function test_unit_readiness_requires_delivered_service_state_and_payment_unlock(): void
    {
        $this->persist($this->payload());
        $this->shoot->update(['payment_status' => 'unpaid', 'bypass_paywall' => false]);
        $line = $this->shoot->serviceItems->first();
        $line->update(['delivery_status' => 'ready', 'workflow_status' => 'ready']);
        $data = (new ShootResource($this->shoot->fresh()))->resolve(request());
        $this->assertFalse($data['units'][0]['is_ready_for_delivery']);
        $line->update(['force_unlock_delivery' => true, 'unlock_reason' => 'Reviewed release']);
        $data = (new ShootResource($this->shoot->fresh()))->resolve(request());
        $this->assertTrue($data['units'][0]['is_ready_for_delivery']);
        $this->assertFalse($data['units'][1]['is_ready_for_delivery']);
    }

    public function test_rename_reorder_and_retry_keep_lines_files_prices_and_other_unit_fields(): void
    {
        $this->persist($this->payload());
        $ids = $this->shoot->serviceItems->pluck('id')->all();
        $file = ShootFile::create(['shoot_id' => $this->shoot->id, 'shoot_service_id' => $ids[0], 'filename' => 'room.jpg', 'stored_filename' => 'room.jpg', 'path' => 'room.jpg', 'file_type' => 'image', 'mime_type' => 'image/jpeg', 'file_size' => 123, 'uploaded_by' => $this->admin->id]);
        $payload = $this->existingPayload();
        $payload['units'][0]['label'] = 'Penthouse';
        $payload['units'][0]['sort_order'] = 20;
        $payload['units'][0]['sqft'] = 2500;
        $this->service->update(['price' => 999]);
        $this->persist($payload);
        $this->assertSame($ids, $this->shoot->serviceItems->pluck('id')->all());
        $this->assertSame($ids[0], $file->fresh()->shoot_service_id);
        $this->assertSame(100.0, (float) $this->shoot->serviceItems->first()->price);
        $this->assertSame('Access 2', $this->shoot->units->firstWhere('client_key', 'unit-2')->access_notes);
        $this->persist($this->existingPayload());
        $this->assertSame(2, $this->shoot->serviceItems()->count());
        $this->assertSame($ids, $this->shoot->serviceItems->pluck('id')->all());
    }

    public function test_stale_full_array_edit_is_rejected_without_removing_newer_units(): void
    {
        $this->persist($this->payload());
        $stale = $this->existingPayload();
        $next = $this->payload(3);
        $next['expected_units_revision'] = 1;
        $this->persist($next);
        try {
            $this->persist($stale);
            $this->fail('Stale edit was accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('expected_units_revision', $e->errors());
        }
        $this->assertSame(3, $this->shoot->units()->count());
    }

    public function test_units_only_edit_preserves_explicitly_unassigned_and_unscheduled_existing_lines(): void
    {
        $this->persist($this->payload());
        $photographer = User::factory()->create(['role' => 'photographer']);
        $this->shoot->update(['photographer_id' => $photographer->id, 'scheduled_at' => '2026-10-05 10:00:00']);
        $units = $this->shoot->units->toArray();
        $units[0]['label'] = 'Renamed without assigning';
        $this->patchJson('/api/shoots/'.$this->shoot->id, ['expected_units_revision' => 1, 'units' => $units])->assertOk();
        foreach ($this->shoot->serviceItems()->get() as $line) {
            $this->assertNull($line->photographer_id);
            $this->assertNull($line->scheduled_at);
        }
    }

    public function test_foreign_unit_and_line_ids_are_rejected(): void
    {
        $this->persist($this->payload());
        $foreign = Shoot::factory()->create();
        $unit = $foreign->units()->create(['client_key' => 'foreign', 'label' => 'Private', 'kind' => 'unit']);
        $payload = $this->existingPayload();
        $payload['units'][0]['id'] = $unit->id;
        $this->patchJson('/api/shoots/'.$this->shoot->id, $payload)->assertUnprocessable()->assertJsonValidationErrors('units.0.id');
        $line = $foreign->serviceItems()->create(['service_id' => $this->service->id, 'price' => 100, 'quantity' => 1]);
        $payload = $this->existingPayload();
        $payload['service_lines'][0]['shoot_service_id'] = $line->id;
        $this->patchJson('/api/shoots/'.$this->shoot->id, $payload)->assertUnprocessable()->assertJsonValidationErrors('service_lines.0.shoot_service_id');
        $this->assertSame(2, $this->shoot->serviceItems()->count());
    }

    public function test_removal_with_media_is_rejected_atomically(): void
    {
        $this->persist($this->payload());
        $line = $this->shoot->serviceItems->first();
        $file = ShootFile::create(['shoot_id' => $this->shoot->id, 'shoot_service_id' => $line->id, 'filename' => 'room.jpg', 'stored_filename' => 'room.jpg', 'path' => 'room.jpg', 'file_type' => 'image', 'mime_type' => 'image/jpeg', 'file_size' => 123, 'uploaded_by' => $this->admin->id]);
        $payload = $this->existingPayload();
        array_shift($payload['service_lines']);
        array_shift($payload['units']);
        $this->patchJson('/api/shoots/'.$this->shoot->id, $payload)->assertUnprocessable()->assertJsonValidationErrors('service_lines');
        $this->assertSame(2, $this->shoot->serviceItems()->count());
        $this->assertSame($line->id, $file->fresh()->shoot_service_id);
    }

    public function test_flat_service_update_cannot_collapse_multiunit_and_notes_only_preserve_every_line(): void
    {
        $this->persist($this->payload());
        $ids = $this->shoot->serviceItems->pluck('id')->all();
        $this->patchJson('/api/shoots/'.$this->shoot->id, ['services' => [['id' => $this->service->id]]])->assertUnprocessable();
        app(ShootEditablePayloadService::class)->apply($this->shoot, ['shoot_notes' => 'New instructions'], $this->admin);
        $this->assertSame($ids, $this->shoot->serviceItems->pluck('id')->all());
        $this->assertSame('New instructions', $this->shoot->shoot_notes);
        $this->assertSame(1, $this->shoot->units_revision);
    }

    public function test_replacing_a_booked_line_identity_is_rejected_without_sql_failure(): void
    {
        $this->persist($this->payload());
        $ids = $this->shoot->serviceItems->pluck('id')->all();
        $payload = $this->existingPayload();
        unset($payload['service_lines'][0]['shoot_service_id']);
        $payload['service_lines'][0]['client_key'] = 'replacement';
        $this->patchJson('/api/shoots/'.$this->shoot->id, $payload)->assertUnprocessable()->assertJsonValidationErrors('service_lines.0.shoot_service_id');
        $this->assertSame($ids, $this->shoot->serviceItems()->pluck('id')->all());
    }

    public function test_removing_unit_with_provider_history_is_rejected_without_detaching_data(): void
    {
        $this->persist($this->payload());
        $unit = $this->shoot->units->first();
        $unit->update(['provider_data' => ['cubicasa_order_id' => 'unit-order']]);
        $payload = $this->existingPayload();
        array_shift($payload['units']);
        array_shift($payload['service_lines']);
        $this->patchJson('/api/shoots/'.$this->shoot->id, $payload)->assertUnprocessable()->assertJsonValidationErrors('units');
        $this->assertSame('unit-order', $unit->fresh()->provider_data['cubicasa_order_id']);
        $this->assertSame(2, $this->shoot->serviceItems()->count());
    }

    public function test_other_client_cannot_mutate_units(): void
    {
        $this->persist($this->payload());
        Sanctum::actingAs(User::factory()->create(['role' => 'client']));
        $this->patchJson('/api/shoots/'.$this->shoot->id, $this->existingPayload())->assertForbidden();
    }

    public function test_assignment_role_resource_filters_repeated_catalog_by_execution_not_catalog(): void
    {
        $this->persist($this->payload());
        $a = User::factory()->create(['role' => 'photographer']);
        $b = User::factory()->create(['role' => 'photographer']);
        $editorA = User::factory()->create(['role' => 'editor']);
        $editorB = User::factory()->create(['role' => 'editor']);
        $lines = $this->shoot->serviceItems;
        $lines[0]->update(['photographer_id' => $a->id, 'editor_id' => $editorA->id]);
        $lines[1]->update(['photographer_id' => $b->id, 'editor_id' => $editorB->id]);
        foreach ([$a, $editorA] as $viewer) {
            Sanctum::actingAs($viewer);
            $request = \Illuminate\Http\Request::create('/api/shoots/'.$this->shoot->id);
            $request->setUserResolver(fn () => $viewer);
            $data = (new ShootResource($this->shoot->fresh()))->resolve($request);
            $this->assertCount(1, $data['service_items']);
            $this->assertSame($lines[0]->id, $data['service_items'][0]['shoot_service_id']);
            $this->assertCount(1, $data['units']);
            $this->assertSame('unit-1', $data['units'][0]['client_key']);
            if ($viewer->role === 'editor') {
                $this->assertNull($data['units'][0]['access_notes']);
            }
            $detail = $this->getJson('/api/shoots/'.$this->shoot->id)->assertOk()->json('data');
            $this->assertCount(1, $detail['units']);
            $this->assertCount(1, $detail['service_lines']);
            $this->assertSame($lines[0]->id, $detail['service_lines'][0]['shoot_service_id']);
        }
    }

    public function test_duplicate_catalog_within_unit_and_overlap_across_units_are_rejected(): void
    {
        $payload = $this->payload();
        $payload['service_lines'][1]['unit_client_key'] = 'unit-1';
        try {
            $this->persist($payload);
            $this->fail('Duplicate service was accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('service_lines.1', $e->errors());
        }
        $payload = $this->payload();
        $photographer = User::factory()->create(['role' => 'photographer']);
        foreach ($payload['service_lines'] as &$line) {
            $line['photographer_id'] = $photographer->id;
            $line['scheduled_at'] = '2026-10-05T09:00:00-04:00';
        }
        unset($line);
        $this->expectException(ValidationException::class);
        $this->persist($payload);
    }

    public function test_non_photographer_assignment_is_rejected_and_admin_creation_enforces_each_unit_hours(): void
    {
        $payload = array_merge($this->payload(1), ['client_id' => $this->client->id, 'address' => '400 Main Street', 'city' => 'Arlington', 'state' => 'VA', 'zip' => '22201']);
        $payload['service_lines'][0]['photographer_id'] = $this->client->id;
        $this->postJson('/api/shoots', $payload)->assertUnprocessable()->assertJsonValidationErrors('service_lines.0.photographer_id');
        $photographer = User::factory()->create(['role' => 'photographer']);
        $payload['service_lines'][0]['photographer_id'] = $photographer->id;
        $payload['service_lines'][0]['scheduled_at'] = now()->addDays(10)->setTime(23, 30)->format('Y-m-d H:i:s');
        $this->postJson('/api/shoots', $payload)->assertUnprocessable()->assertJsonValidationErrors('start_time');
        $this->assertSame(0, $this->shoot->units()->count());
    }

    public function test_photographer_assignment_endpoint_uses_line_identity_and_rejects_stale_or_ambiguous_updates(): void
    {
        $this->persist($this->payload());
        $photographer = User::factory()->create(['role' => 'photographer']);
        $line = $this->shoot->serviceItems->first();
        $endpoint = '/api/shoots/'.$this->shoot->id.'/assign-service-photographer';
        $this->postJson($endpoint, ['service_id' => $this->service->id, 'photographer_id' => $photographer->id, 'expected_units_revision' => 1])->assertUnprocessable();
        $assignment = ['shoot_service_id' => $line->id, 'photographer_id' => $photographer->id, 'expected_units_revision' => 1];
        $this->postJson($endpoint, $assignment)->assertOk()->assertJsonPath('data.units_revision', 2);
        $this->assertSame($photographer->id, $line->fresh()->photographer_id);
        $this->assertNull($this->shoot->serviceItems()->whereKeyNot($line->id)->first()->photographer_id);
        $assignment['photographer_id'] = null;
        $this->postJson($endpoint, $assignment)->assertUnprocessable()->assertJsonValidationErrors('expected_units_revision');
        $this->assertSame($photographer->id, $line->fresh()->photographer_id);
        $this->postJson('/api/shoots/'.$this->shoot->id.'/assign-service-photographers', ['expected_units_revision' => 2, 'service_photographers' => [['shoot_service_id' => $line->id, 'photographer_id' => null]]])->assertOk();
        $this->assertNull($line->fresh()->photographer_id);
    }

    public function test_client_reschedule_request_preserves_unit_offsets_on_approval_and_rejects_stale_requests(): void
    {
        $this->persist($this->payload());
        $photographer = User::factory()->create(['role' => 'photographer']);
        $this->shoot->update(['scheduled_at' => '2026-10-05 10:00:00', 'scheduled_date' => '2026-10-05', 'time' => '10:00', 'status' => 'scheduled', 'workflow_status' => 'scheduled', 'timezone' => null]);
        $lines = $this->shoot->serviceItems;
        $lines[0]->update(['scheduled_at' => '2026-10-05 10:00:00', 'photographer_id' => $photographer->id, 'duration_minutes' => 60]);
        $lines[1]->update(['scheduled_at' => '2026-10-06 12:00:00', 'photographer_id' => $photographer->id, 'duration_minutes' => 60]);
        Sanctum::actingAs($this->client);
        $requestId = $this->postJson('/api/shoots/'.$this->shoot->id.'/reschedule', ['requested_date' => '2026-10-12', 'requested_time' => '11:00'])
            ->assertCreated()->assertJsonPath('applied', false)->assertJsonPath('data.units_revision', 1)->json('data.id');
        $this->assertSame('2026-10-05 10:00:00', $lines[0]->fresh()->scheduled_at->format('Y-m-d H:i:s'));
        Sanctum::actingAs($this->admin);
        $review = '/api/shoots/reschedule-requests/'.$requestId;
        $this->patchJson($review, ['status' => 'approved'])->assertOk()->assertJsonPath('applied', true);
        $this->assertSame('2026-10-12 11:00:00', $lines[0]->fresh()->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-13 13:00:00', $lines[1]->fresh()->scheduled_at->format('Y-m-d H:i:s'));
        $this->patchJson($review, ['status' => 'approved'])->assertOk()->assertJsonPath('already_applied', true);
        $this->assertSame(2, $this->shoot->fresh()->units_revision);
        Sanctum::actingAs($this->client);
        $staleId = $this->postJson('/api/shoots/'.$this->shoot->id.'/reschedule', ['requested_date' => '2026-10-19', 'requested_time' => '11:00'])->assertCreated()->json('data.id');
        $this->shoot->refresh();
        $this->persist($this->existingPayload());
        Sanctum::actingAs($this->admin);
        $this->patchJson('/api/shoots/reschedule-requests/'.$staleId, ['status' => 'approved'])->assertUnprocessable()->assertJsonValidationErrors('expected_units_revision');
        $this->assertSame('pending', \App\Models\ShootRescheduleRequest::findOrFail($staleId)->status);
        $this->assertSame('2026-10-12 11:00:00', $lines[0]->fresh()->scheduled_at->format('Y-m-d H:i:s'));
    }

    public function test_resume_from_hold_preserves_unit_staff_and_unassigned_offsite_services(): void
    {
        $this->service->update(['photographer_required' => false]);
        $this->persist($this->payload());
        $photographer = User::factory()->create(['role' => 'photographer']);
        $replacement = User::factory()->create(['role' => 'photographer']);
        $this->shoot->update(['scheduled_at' => '2026-10-05 10:00:00', 'scheduled_date' => '2026-10-05', 'time' => '10:00', 'status' => 'hold_on', 'workflow_status' => 'on_hold', 'timezone' => null]);
        $lines = $this->shoot->serviceItems;
        $lines[0]->update(['scheduled_at' => '2026-10-05 10:00:00', 'photographer_id' => $photographer->id, 'duration_minutes' => 60]);
        $endpoint = '/api/shoots/'.$this->shoot->id.'/schedule';
        $payload = ['scheduled_at' => '2026-10-05 10:00:00', 'photographer_id' => $replacement->id];
        $this->postJson($endpoint, $payload)->assertUnprocessable()->assertJsonValidationErrors('expected_units_revision');
        $this->assertSame('on_hold', $this->shoot->fresh()->workflow_status);
        $this->postJson($endpoint, $payload + ['expected_units_revision' => 1])->assertOk();
        $this->assertSame('scheduled', $this->shoot->fresh()->workflow_status);
        $this->assertSame($photographer->id, $lines[0]->fresh()->photographer_id);
        $this->assertNull($lines[1]->fresh()->photographer_id);
        $this->assertNull($lines[1]->fresh()->scheduled_at);
        $this->postJson($endpoint, ['scheduled_at' => '2026-10-12 11:00:00', 'expected_units_revision' => 1])->assertOk();
        $this->assertSame('2026-10-12 11:00:00', $lines[0]->fresh()->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertNull($lines[1]->fresh()->scheduled_at);
        $this->assertSame(2, $this->shoot->fresh()->units_revision);
    }

    public function test_alternate_date_moves_all_unit_offsets_and_date_only_retains_local_start(): void
    {
        $this->persist($this->payload());
        $photographer = User::factory()->create(['role' => 'photographer']);
        $this->shoot->update(['scheduled_at' => '2026-10-05 10:00:00', 'scheduled_date' => '2026-10-05', 'time' => '10:00', 'alternate_scheduled_date' => '2026-10-12', 'alternate_time' => '11:00', 'timezone' => null]);
        $lines = $this->shoot->serviceItems;
        $lines[0]->update(['scheduled_at' => '2026-10-05 10:00:00', 'photographer_id' => $photographer->id, 'duration_minutes' => 60]);
        $lines[1]->update(['scheduled_at' => '2026-10-06 12:00:00', 'photographer_id' => $photographer->id, 'duration_minutes' => 60]);
        $endpoint = '/api/shoots/'.$this->shoot->id.'/apply-alternate-date';
        $this->postJson($endpoint, ['scope' => 'main'])->assertUnprocessable()->assertJsonValidationErrors('expected_units_revision');
        $this->postJson($endpoint, ['scope' => 'main', 'expected_units_revision' => 1])->assertOk();
        $this->assertSame('2026-10-12 11:00:00', $lines[0]->fresh()->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-13 13:00:00', $lines[1]->fresh()->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame(100.0, (float) $lines[0]->fresh()->price);
        $this->shoot->refresh()->update(['alternate_scheduled_date' => '2026-10-19', 'alternate_time' => null]);
        $this->postJson($endpoint, ['scope' => 'all_services', 'expected_units_revision' => 1])->assertUnprocessable()->assertJsonValidationErrors('expected_units_revision');
        $this->postJson($endpoint, ['scope' => 'all_services', 'expected_units_revision' => 2])->assertOk();
        $this->assertSame('2026-10-19 11:00:00', $lines[0]->fresh()->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-20 13:00:00', $lines[1]->fresh()->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame($photographer->id, $lines[1]->fresh()->photographer_id);
    }

    public function test_invoice_fees_stay_out_of_canonical_unit_lines_and_survive_round_trip_approval(): void
    {
        $this->service->update(['photographer_required' => false, 'requires_editing' => false]);
        $this->persist($this->payload());
        $this->shoot->update(['status' => 'requested', 'workflow_status' => 'requested']);
        $invoice = app(InvoiceService::class)->generateForShoot($this->shoot);
        $fee = $invoice->items()->create(['shoot_id' => $this->shoot->id, 'type' => 'expense', 'description' => 'Manual unit fee', 'quantity' => 1, 'unit_amount' => 25, 'total_amount' => 25, 'meta' => ['source' => 'admin_misc', 'bills_client' => true]]);
        $data = $this->getJson('/api/shoots/'.$this->shoot->id)->assertOk()->assertJsonCount(2, 'data.service_lines')->assertJsonCount(3, 'data.service_items')->json('data');
        $this->assertFalse($data['service_lines'][0]['photographer_required']);
        $this->assertFalse($data['service_lines'][0]['requires_editing']);
        $this->postJson('/api/shoots/'.$this->shoot->id.'/approve', ['expected_units_revision' => $data['units_revision'], 'service_lines' => $data['service_lines'], 'scheduled_at' => '2026-10-05 10:00:00', 'notify_client' => false, 'notify_photographer' => false])->assertOk()->assertJsonCount(2, 'data.service_lines');
        $this->assertNotNull($fee->fresh());
        $this->assertSame(2, $this->shoot->serviceItems()->count());
        $edit = $this->getJson('/api/shoots/'.$this->shoot->id)->assertOk()->json('data');
        $this->patchJson('/api/shoots/'.$this->shoot->id, ['expected_units_revision' => $edit['units_revision'], 'service_lines' => $edit['service_lines']])->assertOk();
        $this->assertNotNull($fee->fresh());
    }

    public function test_invoice_contains_every_unit_line_and_stable_execution_identity(): void
    {
        $this->persist($this->payload());
        $invoice = app(InvoiceService::class)->generateForShoot($this->shoot);
        $items = $invoice->items()->where('type', 'charge')->get();
        $this->assertCount(2, $items);
        $this->assertCount(2, $items->pluck('meta.shoot_service_id')->unique());
        $this->assertStringContainsString('Unit 1', $items[0]->description);
        $this->assertStringContainsString('Unit 2', $items[1]->description);
    }

    public function test_editor_payout_sync_keeps_repeated_catalog_lines_separate_and_paid_snapshots_immutable(): void
    {
        $this->persist($this->payload());
        $editor = User::factory()->create(['role' => 'editor', 'metadata' => ['editing_rates' => [['service_id' => $this->service->id, 'rate' => 7]]]]);
        $this->shoot->serviceItems()->update(['editor_id' => $editor->id, 'editing_completed_at' => now()]);
        app(\App\Services\EditorPayoutService::class)->syncPayouts();
        $rows = \App\Models\EditorPayout::query()->where('shoot_id', $this->shoot->id)->get();
        $this->assertCount(2, $rows);
        $this->assertCount(2, $rows->pluck('shoot_service_id')->unique());
        $this->assertStringContainsString('Unit 1', $rows[0]->service_name);
        $rows[0]->update(['is_paid' => true]);
        $this->shoot->units()->where('client_key', 'unit-1')->update(['label' => 'Renamed']);
        app(\App\Services\EditorPayoutService::class)->syncPayouts();
        $this->assertSame(2, \App\Models\EditorPayout::query()->where('shoot_id', $this->shoot->id)->count());
        $this->assertSame($rows[0]->service_name, $rows[0]->fresh()->service_name);
    }

    public function test_calendar_and_archive_copies_carry_unit_identity_without_renaming_masters(): void
    {
        $this->persist($this->payload());
        $line = $this->shoot->serviceItems->first();
        $line->update(['scheduled_at' => '2026-10-05 09:00:00', 'duration_minutes' => 180]);
        $event = app(\App\Services\GoogleCalendar\GoogleCalendarEventPayloadBuilder::class)->buildForServiceItem($this->shoot, $line);
        $this->assertStringContainsString('Unit 1', $event['summary']);
        $this->assertStringContainsString('Access 1', $event['description']);
        $this->assertSame(180, (int) \Carbon\Carbon::parse($event['start']['dateTime'])->diffInMinutes(\Carbon\Carbon::parse($event['end']['dateTime'])));
        $file = new ShootFile(['filename' => 'living.jpg', 'shoot_service_id' => $line->id]);
        $name = app(\App\Services\Shoots\DeliveryFilenameFormatter::class)->archivePathForFile($file, 1, 5);
        $this->assertStringStartsWith('unit-'.$line->shoot_unit_id.'-unit-1/line-'.$line->id.'-', $name);
        $this->assertStringContainsString('001_unit-', $name);
        $this->assertSame('living.jpg', $file->filename);
    }

    public function test_whole_property_provider_jobs_do_not_link_multi_unit_shoots(): void
    {
        $this->persist($this->payload());
        $cubi = \Mockery::mock(\App\Services\CubiCasaService::class);
        $cubi->shouldNotReceive('createOrder');
        (new \App\Jobs\CreateCubiCasaOrderJob($this->shoot->id))->handle($cubi);
        $iguide = \Mockery::mock(\App\Services\IguideService::class);
        $iguide->shouldNotReceive('syncShoot');
        (new \App\Jobs\SyncShootIguideJob($this->shoot->id))->handle($iguide);
        $this->assertNull($this->shoot->fresh()->cubicasa_order_id);
    }

    public function test_hundred_unit_edit_completes_with_a_concurrent_sqlite_wal_writer(): void
    {
        $this->persist($this->payload(100));
        $directory = storage_path('framework/testing/unit-contention-'.bin2hex(random_bytes(6)));
        mkdir($directory, 0700, true);
        $database = $directory.'/isolated.sqlite';
        $barrier = $directory.'/start';
        $pdo = new \PDO('sqlite:'.$database);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $processes = [];
        try {
            foreach (DB::select("SELECT sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'") as $table) {
                $pdo->exec($table->sql);
            }
            foreach (['users', 'categories', 'services', 'shoots', 'shoot_units', 'shoot_service'] as $table) {
                foreach (DB::table($table)->get() as $row) {
                    $values = (array) $row;
                    $columns = implode(',', array_map(fn ($column) => '"'.$column.'"', array_keys($values)));
                    $statement = $pdo->prepare('INSERT INTO '.$table.' ('.$columns.') VALUES ('.implode(',', array_fill(0, count($values), '?')).')');
                    $statement->execute(array_values($values));
                }
            }
            foreach (['telemetry', 'editor'] as $mode) {
                $process = proc_open([PHP_BINARY, base_path('tests/Fixtures/multi-unit-contention-worker.php'), $database, (string) $this->shoot->id, (string) $this->admin->id, $barrier, $mode], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                fclose($pipes[0]);
                $processes[] = [$process, $pipes];
            }
            $deadline = microtime(true) + 15;
            while ((! is_file($barrier.'.ready.telemetry') || ! is_file($barrier.'.ready.editor')) && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($barrier.'.ready.telemetry');
            $this->assertFileExists($barrier.'.ready.editor');
            file_put_contents($barrier, 'start');
            $outputs = [];
            foreach ($processes as [$process, $pipes]) {
                $outputs[] = trim(stream_get_contents($pipes[1]));
                $errors = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $errors);
            }
            $processes = [];
            $this->assertStringStartsWith('telemetry:', $outputs[0]);
            $this->assertSame('edited', $outputs[1]);
            $this->assertSame(100, (int) $pdo->query('SELECT COUNT(*) FROM shoot_service')->fetchColumn());
            $this->assertSame('Saved under concurrent writes', $pdo->query("SELECT label FROM shoot_units WHERE client_key='unit-100'")->fetchColumn());
        } finally {
            foreach ($processes as [$process, $pipes]) {
                if (is_resource($process)) {
                    proc_terminate($process);
                }
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
            }
            $pdo = null;
            unset($statement);
            foreach (glob($directory.'/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    public function test_legacy_single_property_sync_preserves_identity_and_null_scope_uniqueness(): void
    {
        app(ShootMutationSupportService::class)->attachServices($this->shoot, [['id' => $this->service->id, 'price' => 100, 'quantity' => 1]]);
        $id = $this->shoot->serviceItems->first()->id;
        app(ShootMutationSupportService::class)->attachServices($this->shoot, [['id' => $this->service->id, 'price' => 150, 'quantity' => 1]]);
        $this->assertSame($id, $this->shoot->serviceItems->first()->id);
        $this->assertSame(0, $this->shoot->units()->count());
        $this->expectException(QueryException::class);
        $this->shoot->serviceItems()->create(['service_id' => $this->service->id, 'price' => 999]);
    }
}
