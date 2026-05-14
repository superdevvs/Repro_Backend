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

class ShootPaymentIntentTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_can_create_cash_intent_without_marking_shoot_paid(): void
    {
        [$shoot, $client] = $this->createShootForClient();

        Sanctum::actingAs($client);

        $response = $this->postJson("/api/shoots/{$shoot->id}/payment-intents", [
            'payment_method' => 'cash',
            'amount' => 50.00,
            'payment_details' => ['notes' => 'Will pay at shoot'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', Payment::STATUS_PENDING)
            ->assertJsonPath('data.provider', 'cash')
            ->assertJsonPath('data.amount', 50);

        $shoot->refresh();
        $this->assertSame('unpaid', $shoot->payment_status);
        $this->assertSame(0.0, (float) $shoot->total_paid);

        $this->assertDatabaseHas('payments', [
            'shoot_id' => $shoot->id,
            'amount' => 50.00,
            'payment_method' => 'cash',
            'status' => Payment::STATUS_PENDING,
        ]);
    }

    public function test_client_cheque_intent_requires_check_number_and_date(): void
    {
        [$shoot, $client] = $this->createShootForClient();
        Sanctum::actingAs($client);

        $missingNumber = $this->postJson("/api/shoots/{$shoot->id}/payment-intents", [
            'payment_method' => 'check',
            'amount' => 50.00,
            'payment_date' => now()->toDateString(),
        ]);
        $missingNumber->assertStatus(422);

        $missingDate = $this->postJson("/api/shoots/{$shoot->id}/payment-intents", [
            'payment_method' => 'check',
            'amount' => 50.00,
            'payment_details' => ['check_number' => '12345'],
        ]);
        $missingDate->assertStatus(422);

        $ok = $this->postJson("/api/shoots/{$shoot->id}/payment-intents", [
            'payment_method' => 'check',
            'amount' => 50.00,
            'payment_date' => now()->toDateString(),
            'payment_details' => ['check_number' => '12345'],
        ]);
        $ok->assertCreated();
    }

    public function test_client_cannot_create_intent_for_other_clients_shoot(): void
    {
        [$shoot] = $this->createShootForClient();
        $otherClient = User::factory()->create(['role' => 'client']);
        Sanctum::actingAs($otherClient);

        $response = $this->postJson("/api/shoots/{$shoot->id}/payment-intents", [
            'payment_method' => 'cash',
            'amount' => 50.00,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_admin_can_confirm_intent_and_apply_payment(): void
    {
        [$shoot, $client] = $this->createShootForClient();
        Sanctum::actingAs($client);
        $intentResponse = $this->postJson("/api/shoots/{$shoot->id}/payment-intents", [
            'payment_method' => 'cash',
            'amount' => 100.00,
        ]);
        $paymentId = $intentResponse->json('data.id');

        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);
        $confirmResponse = $this->postJson("/api/shoots/{$shoot->id}/payment-intents/{$paymentId}/confirm");

        $confirmResponse->assertOk()
            ->assertJsonPath('data.payment.status', Payment::STATUS_COMPLETED)
            ->assertJsonPath('data.total_paid', 100);

        $shoot->refresh();
        $this->assertGreaterThan(0, (float) $shoot->total_paid);
    }

    public function test_admin_can_decline_intent_and_notify_client(): void
    {
        [$shoot, $client] = $this->createShootForClient();
        Sanctum::actingAs($client);
        $intentResponse = $this->postJson("/api/shoots/{$shoot->id}/payment-intents", [
            'payment_method' => 'cash',
            'amount' => 100.00,
        ]);
        $paymentId = $intentResponse->json('data.id');

        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);
        $declineResponse = $this->postJson(
            "/api/shoots/{$shoot->id}/payment-intents/{$paymentId}/decline",
            ['reason' => 'Funds were not received']
        );

        $declineResponse->assertOk()
            ->assertJsonPath('data.status', Payment::STATUS_FAILED);

        $shoot->refresh();
        $this->assertSame('unpaid', $shoot->payment_status);
        $this->assertSame(0.0, (float) $shoot->total_paid);
    }

    public function test_pending_intent_total_excluded_from_outstanding_balance(): void
    {
        [$shoot, $client] = $this->createShootForClient();
        Sanctum::actingAs($client);

        $this->postJson("/api/shoots/{$shoot->id}/payment-intents", [
            'payment_method' => 'cash',
            'amount' => 80.00,
        ])->assertCreated();

        $detailsResponse = $this->getJson("/api/shoots/{$shoot->id}/payment-details");
        $detailsResponse->assertOk()
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.amount_due', 200)
            ->assertJsonPath('data.pending_total', 80);

        $this->assertCount(1, $detailsResponse->json('data.pending_payments'));
    }

    public function test_partial_cash_intent_then_mark_paid_settles_full_balance(): void
    {
        [$shoot, $client] = $this->createShootForClient();
        Sanctum::actingAs($client);
        $intentResponse = $this->postJson("/api/shoots/{$shoot->id}/payment-intents", [
            'payment_method' => 'cash',
            'amount' => 80.00,
        ]);
        $paymentId = $intentResponse->json('data.id');

        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);
        $this->postJson("/api/shoots/{$shoot->id}/payment-intents/{$paymentId}/confirm")
            ->assertOk();

        $remaining = $this->postJson("/api/shoots/{$shoot->id}/mark-paid", [
            'payment_type' => 'cash',
            'amount' => 120.00,
        ]);

        $remaining->assertOk()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.total_paid', 200);
    }

    /**
     * @return array{0: Shoot, 1: User}
     */
    private function createShootForClient(): array
    {
        $client = User::factory()->create(['role' => 'client']);
        $service = Service::factory()->create(['name' => 'Photo Package', 'price' => 200.00]);

        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'service_id' => $service->id,
            'base_quote' => 200.00,
            'tax_amount' => 0.00,
            'total_quote' => 200.00,
            'payment_status' => 'unpaid',
            'status' => 'scheduled',
            'workflow_status' => 'scheduled',
        ]);

        ShootService::create([
            'shoot_id' => $shoot->id,
            'service_id' => $service->id,
            'price' => 200.00,
            'quantity' => 1,
            'workflow_status' => ShootService::WORKFLOW_SCHEDULED,
            'delivery_status' => ShootService::DELIVERY_NOT_STARTED,
            'is_deliverable' => true,
        ]);

        return [$shoot->fresh(), $client];
    }
}
