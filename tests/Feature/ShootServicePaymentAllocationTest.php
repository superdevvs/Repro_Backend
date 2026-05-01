<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShootServicePaymentAllocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_mark_selected_service_items_paid_without_unlocking_the_rest(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$shoot, $photoItem, $videoItem, $droneItem] = $this->createMultiServiceShoot();

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/shoots/{$shoot->id}/mark-paid", [
            'payment_type' => 'cash',
            'amount' => 600.00,
            'shoot_service_ids' => [$photoItem->id, $videoItem->id],
            'allocation_strategy' => 'selected_services',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.payment_status', 'partial')
            ->assertJsonPath('data.total_paid', 600);

        $payment = Payment::query()->where('shoot_id', $shoot->id)->firstOrFail();

        $this->assertDatabaseHas('payment_service_allocations', [
            'payment_id' => $payment->id,
            'shoot_service_id' => $photoItem->id,
            'amount' => 175.00,
        ]);
        $this->assertDatabaseHas('payment_service_allocations', [
            'payment_id' => $payment->id,
            'shoot_service_id' => $videoItem->id,
            'amount' => 425.00,
        ]);
        $this->assertDatabaseMissing('payment_service_allocations', [
            'payment_id' => $payment->id,
            'shoot_service_id' => $droneItem->id,
        ]);

        $serviceItems = collect($response->json('data.service_items'));

        $this->assertSame('paid', $serviceItems->firstWhere('shoot_service_id', $photoItem->id)['payment_status']);
        $this->assertSame('paid', $serviceItems->firstWhere('shoot_service_id', $videoItem->id)['payment_status']);
        $this->assertSame('unpaid', $serviceItems->firstWhere('shoot_service_id', $droneItem->id)['payment_status']);
        $this->assertSame('locked', $serviceItems->firstWhere('shoot_service_id', $droneItem->id)['unlock_state']);
    }

    public function test_custom_partial_payment_requires_explicit_service_allocation(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$shoot] = $this->createMultiServiceShoot();

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/shoots/{$shoot->id}/mark-paid", [
            'payment_type' => 'cash',
            'amount' => 100.00,
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('message', 'Custom partial payments must target selected services, explicit allocations, or an allocation strategy.');

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_service_allocations', 0);
    }

    public function test_full_payment_allocates_across_all_service_items(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$shoot, $photoItem, $videoItem, $droneItem] = $this->createMultiServiceShoot();

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/shoots/{$shoot->id}/mark-paid", [
            'payment_type' => 'cash',
            'amount' => 875.00,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.total_paid', 875);

        $payment = Payment::query()->where('shoot_id', $shoot->id)->firstOrFail();

        foreach ([$photoItem, $videoItem, $droneItem] as $item) {
            $this->assertDatabaseHas('payment_service_allocations', [
                'payment_id' => $payment->id,
                'shoot_service_id' => $item->id,
                'amount' => (float) $item->price,
            ]);
        }

        $serviceItems = collect($response->json('data.service_items'));

        foreach ([$photoItem, $videoItem, $droneItem] as $item) {
            $this->assertSame('paid', $serviceItems->firstWhere('shoot_service_id', $item->id)['payment_status']);
            $this->assertSame('unlocked', $serviceItems->firstWhere('shoot_service_id', $item->id)['unlock_state']);
        }
    }

    private function createMultiServiceShoot(): array
    {
        $client = User::factory()->create(['role' => 'client']);
        $photographer = User::factory()->create(['role' => 'photographer']);

        $photoService = Service::factory()->create(['name' => '25 HDR Photos', 'price' => 175.00]);
        $videoService = Service::factory()->create(['name' => '40 HDR + 1 Min Vertical Video', 'price' => 425.00]);
        $droneService = Service::factory()->create(['name' => 'Drone Gold Package', 'price' => 275.00]);

        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'service_id' => $photoService->id,
            'base_quote' => 875.00,
            'tax_amount' => 0.00,
            'total_quote' => 875.00,
            'payment_status' => 'unpaid',
            'payment_type' => null,
            'status' => 'scheduled',
            'workflow_status' => 'scheduled',
        ]);

        $photoItem = ShootService::create([
            'shoot_id' => $shoot->id,
            'service_id' => $photoService->id,
            'photographer_id' => $photographer->id,
            'price' => 175.00,
            'quantity' => 1,
            'workflow_status' => ShootService::WORKFLOW_SCHEDULED,
            'delivery_status' => ShootService::DELIVERY_NOT_STARTED,
            'is_deliverable' => true,
        ]);
        $videoItem = ShootService::create([
            'shoot_id' => $shoot->id,
            'service_id' => $videoService->id,
            'photographer_id' => $photographer->id,
            'price' => 425.00,
            'quantity' => 1,
            'workflow_status' => ShootService::WORKFLOW_SCHEDULED,
            'delivery_status' => ShootService::DELIVERY_NOT_STARTED,
            'is_deliverable' => true,
        ]);
        $droneItem = ShootService::create([
            'shoot_id' => $shoot->id,
            'service_id' => $droneService->id,
            'photographer_id' => $photographer->id,
            'price' => 275.00,
            'quantity' => 1,
            'workflow_status' => ShootService::WORKFLOW_SCHEDULED,
            'delivery_status' => ShootService::DELIVERY_NOT_STARTED,
            'is_deliverable' => true,
        ]);

        return [$shoot, $photoItem, $videoItem, $droneItem];
    }
}
