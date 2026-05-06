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
