<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\PublicPaymentAccessToken;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaidPaymentLinkReceiptTest extends TestCase
{
    use RefreshDatabase;

    private function paidLink(): array
    {
        $client = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->for($client, 'client')->create(['total_quote' => 100, 'payment_status' => 'paid']);
        $payment = Payment::factory()->create([
            'shoot_id' => $shoot->id, 'amount' => 100, 'status' => Payment::STATUS_COMPLETED,
            'payment_method' => 'cash', 'stripe_payment_id' => null, 'stripe_session_id' => null,
        ]);
        $token = PublicPaymentAccessToken::create([
            'shoot_id' => $shoot->id, 'expires_at' => now()->addDay(), 'revoked_at' => now(),
        ]);
        return [$client, $shoot, $payment, $token];
    }

    public function test_signed_in_owner_can_reopen_a_paid_link_without_reopening_checkout(): void
    {
        [$client, $shoot, $payment, $token] = $this->paidLink();
        $before = $payment->fresh()->getRawOriginal();
        Sanctum::actingAs($client);

        $this->getJson("/api/payments/receipt/{$token->token}")->assertOk()
            ->assertJsonPath('data.id', $shoot->id)
            ->assertJsonPath('data.amount_due', 0);
        $this->assertSame($before, $payment->fresh()->getRawOriginal());
        $this->assertNotNull($token->fresh()->revoked_at);
        $this->assertDatabaseCount('payments', 1);
        $this->postJson("/api/public/payments/{$token->token}/checkout", ['amount' => 100])->assertGone();
    }

    public function test_closed_link_stays_private_for_anonymous_visitors(): void
    {
        [, , , $token] = $this->paidLink();
        $this->getJson("/api/payments/receipt/{$token->token}")->assertUnauthorized();
        $this->getJson("/api/public/payments/{$token->token}")->assertGone();
    }

    public function test_another_client_cannot_recover_the_receipt(): void
    {
        [, , , $token] = $this->paidLink();
        Sanctum::actingAs(User::factory()->create(['role' => 'client']));
        $this->getJson("/api/payments/receipt/{$token->token}")->assertNotFound()->assertJsonMissingPath('data');
    }

    public function test_expired_link_cannot_recover_a_receipt(): void
    {
        [$client, , , $token] = $this->paidLink();
        $token->update(['expires_at' => now()->subMinute()]);
        Sanctum::actingAs($client);
        $this->getJson("/api/payments/receipt/{$token->token}")->assertNotFound();
    }

    public function test_partial_payment_is_not_reported_as_settled(): void
    {
        [$client, , $payment, $token] = $this->paidLink();
        $payment->update(['amount' => 40]);
        Sanctum::actingAs($client);
        $this->getJson("/api/payments/receipt/{$token->token}")->assertConflict()->assertJsonMissingPath('data');
    }
}
