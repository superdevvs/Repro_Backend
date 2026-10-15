<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AssignedRepTourAppearanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigned_rep_can_change_appearance_without_replacing_links_or_billing(): void
    {
        Bus::fake();
        Http::preventStrayRequests();
        $rep = User::factory()->create(['role' => 'salesRep']);
        $shoot = Shoot::factory()->create([
            'rep_id' => $rep->id, 'status' => 'delivered', 'workflow_status' => 'delivered',
            'tour_links' => ['branded' => 'https://tour.example/original', 'video_link' => 'https://video.example/original'],
        ]);
        $originalQuote = $shoot->total_quote;
        $this->actingAs($rep)->patchJson('/api/shoots/'.$shoot->id, ['tour_links' => [
            'tour_style' => 'landor', 'tour_palette' => 'repro', 'header_position' => 'left',
            'tour_version' => 'standard', 'autoplay' => true, 'show_garage' => false,
        ]])->assertOk();
        $shoot->refresh();
        $this->assertSame('landor', $shoot->tour_links['tour_style']);
        $this->assertSame('https://tour.example/original', $shoot->tour_links['branded']);
        $this->assertSame('https://video.example/original', $shoot->tour_links['video_link']);
        $this->assertEquals($originalQuote, $shoot->total_quote);
    }

    public function test_other_reps_and_clients_cannot_change_appearance(): void
    {
        Bus::fake();
        $rep = User::factory()->create(['role' => 'salesRep']);
        $other = User::factory()->create(['role' => 'salesRep']);
        $client = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create(['rep_id' => $rep->id, 'client_id' => $client->id, 'status' => 'delivered', 'workflow_status' => 'delivered']);
        foreach ([$other, $client] as $actor) {
            $this->actingAs($actor)->patchJson('/api/shoots/'.$shoot->id, ['tour_links' => ['tour_style' => 'landor']])->assertForbidden();
        }
        $this->actingAs($rep)->patchJson('/api/shoots/'.$shoot->id, ['tour_links' => ['video_link' => 'https://video.example/changed']])->assertForbidden();
    }

    public function test_assigned_rep_appearance_save_ignores_computed_totals_without_financial_mutations(): void
    {
        Bus::fake();
        Http::preventStrayRequests();
        $rep = User::factory()->create(['role' => 'salesRep']);
        $shoot = Shoot::factory()->create([
            'rep_id' => $rep->id, 'status' => 'delivered', 'workflow_status' => 'delivered',
            'base_quote' => 100, 'tax_amount' => 6, 'total_quote' => 106, 'payment_status' => 'partial',
            'tour_links' => ['branded' => 'https://tour.example/original', 'video_link' => 'https://video.example/original'],
        ]);
        $invoice = Invoice::factory()->create([
            'shoot_id' => $shoot->id, 'client_id' => $shoot->client_id, 'user_id' => $shoot->client_id, 'role' => Invoice::ROLE_CLIENT,
            'subtotal' => 100, 'tax' => 6, 'total' => 106, 'total_amount' => 106, 'amount_paid' => 25,
        ]);
        $payment = Payment::create([
            'shoot_id' => $shoot->id, 'invoice_id' => $invoice->id, 'amount' => 25,
            'currency' => 'USD', 'status' => Payment::STATUS_COMPLETED, 'processed_at' => now(),
        ]);
        $financialFields = ['base_quote', 'tax_amount', 'tax_percent', 'tax_region', 'total_quote',
            'discount_type', 'discount_value', 'discount_amount', 'payment_status', 'payment_type', 'bypass_paywall'];
        $financialBefore = $shoot->fresh()->only($financialFields);
        $invoiceBefore = $invoice->fresh()->getRawOriginal();
        $paymentBefore = $payment->fresh()->getRawOriginal();

        $this->actingAs($rep)->patchJson('/api/shoots/'.$shoot->id, [
            'tour_links' => ['tour_style' => 'landor'],
            'base_quote' => 1, 'total_quote' => 1, 'tax_amount' => 1, 'discount_amount' => 1,
            'notify_client' => false, 'notify_photographer' => false,
        ])->assertOk();

        $this->assertSame('landor', $shoot->fresh()->tour_links['tour_style']);
        $this->assertSame('https://tour.example/original', $shoot->fresh()->tour_links['branded']);
        $this->assertSame('https://video.example/original', $shoot->fresh()->tour_links['video_link']);
        $this->assertSame($financialBefore, $shoot->fresh()->only($financialFields));
        $this->assertSame($invoiceBefore, $invoice->fresh()->getRawOriginal());
        $this->assertSame($paymentBefore, $payment->fresh()->getRawOriginal());
        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, Payment::count());
    }

    public function test_legacy_client_rep_can_change_only_appearance_without_overriding_an_explicit_assignment(): void
    {
        Bus::fake();
        $rep = User::factory()->create(['role' => 'salesRep']);
        $other = User::factory()->create(['role' => 'salesRep']);
        $client = User::factory()->create(['role' => 'client', 'metadata' => ['accountRepId' => $rep->id]]);
        $shoot = Shoot::factory()->create(['rep_id' => null, 'client_id' => $client->id, 'status' => 'delivered', 'workflow_status' => 'delivered']);
        $this->actingAs($rep)->patchJson('/api/shoots/'.$shoot->id, ['tour_links' => ['tour_style' => 'landor']])->assertOk();
        $this->assertSame('landor', $shoot->fresh()->tour_links['tour_style']);
        $this->actingAs($rep)->patchJson('/api/shoots/'.$shoot->id, ['tour_links' => ['video_link' => 'https://video.example/changed']])->assertForbidden();
        $this->actingAs($rep)->patchJson('/api/shoots/'.$shoot->id, ['tour_links' => ['tour_style' => 'default'], 'total_quote' => 1])->assertForbidden();
        $this->actingAs($other)->patchJson('/api/shoots/'.$shoot->id, ['tour_links' => ['tour_style' => 'default']])->assertForbidden();
        Shoot::withoutEvents(fn () => $shoot->update(['rep_id' => $other->id]));
        $this->actingAs($rep)->patchJson('/api/shoots/'.$shoot->id, ['tour_links' => ['tour_style' => 'default']])->assertForbidden();
    }
}
