<?php

namespace Tests\Feature;

use App\Models\PhotographerEquipment;
use App\Models\User;
use App\Services\MailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class PhotographerEquipmentControllerTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    public function test_admin_equipment_date_range_uses_issue_date_with_created_date_fallback_and_preserves_other_filters(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $photographer = User::factory()->photographer()->create();
        $otherPhotographer = User::factory()->photographer()->create();
        $equipment = function (array $attributes) use ($photographer) {
            return PhotographerEquipment::query()->create(array_merge([
                'photographer_id' => $photographer->id,
                'name' => 'Camera',
                'status' => PhotographerEquipment::STATUS_PENDING,
                'issue_date' => '2026-10-09',
            ], $attributes));
        };
        $start = $equipment(['name' => 'Camera start', 'issue_date' => '2026-10-01']);
        $end = $equipment(['name' => 'Camera end']);
        $fallback = $equipment(['name' => 'Camera fallback', 'issue_date' => null]);
        $fallback->forceFill(['created_at' => '2026-10-09 23:59:59'])->save();
        $older = $equipment(['name' => 'Camera older', 'issue_date' => '2026-09-30']);
        $equipment(['name' => 'Camera later', 'issue_date' => '2026-10-10']);
        $oldFallback = $equipment(['name' => 'Camera old fallback', 'issue_date' => null]);
        $oldFallback->forceFill(['created_at' => '2026-09-30 23:59:59'])->save();
        $equipment(['name' => 'Camera verified', 'status' => PhotographerEquipment::STATUS_VERIFIED]);
        $equipment(['name' => 'Camera other photographer', 'photographer_id' => $otherPhotographer->id]);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/admin/photographer-equipments?'.http_build_query([
            'start_date' => '2026-10-01', 'end_date' => '2026-10-09',
            'photographer_id' => $photographer->id, 'status' => PhotographerEquipment::STATUS_PENDING,
            'search' => 'Camera',
        ]))->assertOk()->assertJsonCount(3, 'data');
        $this->assertEqualsCanonicalizing([$start->id, $end->id, $fallback->id], array_column($response->json('data'), 'id'));
        $this->getJson('/api/admin/photographer-equipments')->assertOk()->assertJsonCount(8, 'data');
        $this->assertContains($older->id, array_column($this->getJson('/api/admin/photographer-equipments')->json('data'), 'id'));
    }

    public function test_admin_equipment_date_range_requires_valid_complete_dates(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/admin/photographer-equipments?start_date=2026-10-09&end_date=2026-10-01')
            ->assertUnprocessable()->assertJsonValidationErrors('end_date');
        $this->getJson('/api/admin/photographer-equipments?start_date=2026-10-01')
            ->assertUnprocessable()->assertJsonValidationErrors('end_date');
        $this->getJson('/api/admin/photographer-equipments?start_date=invalid&end_date=2026-10-09')
            ->assertUnprocessable()->assertJsonValidationErrors('start_date');
    }

    public function test_approving_photographer_equipment_sends_approval_email_to_photographer(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $photographer = User::factory()->photographer()->create([
            'name' => 'Equipment Photographer',
            'email' => 'equipment-photographer@test.com',
        ]);
        $equipment = PhotographerEquipment::query()->create([
            'photographer_id' => $photographer->id,
            'name' => 'Sony A7 IV',
            'serial_number' => 'SN-12345',
            'status' => PhotographerEquipment::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);

        $mailService = Mockery::mock(MailService::class);
        $mailService->shouldReceive('sendPhotographerEquipmentApprovedEmail')
            ->once()
            ->withArgs(function (User $recipient, PhotographerEquipment $approvedEquipment) use ($photographer, $equipment) {
                return (int) $recipient->id === (int) $photographer->id
                    && (int) $approvedEquipment->id === (int) $equipment->id
                    && $approvedEquipment->status === PhotographerEquipment::STATUS_VERIFIED
                    && $approvedEquipment->verified_at !== null;
            })
            ->andReturnTrue();
        $this->app->instance(MailService::class, $mailService);

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/admin/photographer-equipments/{$equipment->id}/approve");

        $response->assertOk()
            ->assertJsonPath('message', 'Equipment verified successfully.')
            ->assertJsonPath('data.status', PhotographerEquipment::STATUS_VERIFIED);
    }

    public function test_rejecting_photographer_equipment_sends_rejection_email_with_notes(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $photographer = User::factory()->photographer()->create([
            'name' => 'Rejected Equipment Photographer',
            'email' => 'rejected-equipment-photographer@test.com',
        ]);
        $equipment = PhotographerEquipment::query()->create([
            'photographer_id' => $photographer->id,
            'name' => 'Canon R5',
            'serial_number' => 'CANON-987',
            'status' => PhotographerEquipment::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);
        $rejectionReason = 'Please upload a clearer serial number photo.';

        $mailService = Mockery::mock(MailService::class);
        $mailService->shouldReceive('sendPhotographerEquipmentRejectedEmail')
            ->once()
            ->withArgs(function (User $recipient, PhotographerEquipment $rejectedEquipment) use ($photographer, $equipment, $rejectionReason) {
                return (int) $recipient->id === (int) $photographer->id
                    && (int) $rejectedEquipment->id === (int) $equipment->id
                    && $rejectedEquipment->status === PhotographerEquipment::STATUS_REJECTED
                    && $rejectedEquipment->rejected_at !== null
                    && $rejectedEquipment->rejection_reason === $rejectionReason;
            })
            ->andReturnTrue();
        $this->app->instance(MailService::class, $mailService);

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/admin/photographer-equipments/{$equipment->id}/reject", [
            'rejection_reason' => $rejectionReason,
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Equipment verification rejected.')
            ->assertJsonPath('data.status', PhotographerEquipment::STATUS_REJECTED)
            ->assertJsonPath('data.rejection_reason', $rejectionReason);
    }
}
