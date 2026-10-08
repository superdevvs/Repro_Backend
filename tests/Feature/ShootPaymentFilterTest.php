<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Shoots\ShootPaymentFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShootPaymentFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_filters_combine_with_rep_service_address_and_dates_in_every_tab(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $rep = User::factory()->create(['role' => 'salesRep']);
        $service = Service::factory()->create();
        foreach (['scheduled' => 'scheduled', 'completed' => 'uploaded', 'delivered' => 'delivered'] as $tab => $status) {
            foreach (['paid', 'unpaid', 'partial'] as $payment) {
                $shoot = Shoot::factory()->create(['rep_id' => $rep->id, 'status' => $status, 'workflow_status' => $status, 'payment_status' => $payment, 'total_quote' => 200, 'address' => '123 Filter Lane', 'scheduled_date' => '2026-10-08', 'admin_verified_at' => '2026-10-08']);
                $shoot->services()->attach($service->id, ['price' => 200]);
                if ($payment === 'partial') {
                    Payment::create(['shoot_id' => $shoot->id, 'amount' => 50, 'status' => 'completed']);
                }
            }
            Shoot::factory()->create(['status' => $status, 'workflow_status' => $status, 'payment_status' => 'paid']);
            $params = http_build_query(['tab' => $tab, 'sales_rep_id' => $rep->id, 'services' => [$service->id], 'address' => 'Filter Lane', 'scheduled_start' => '2026-10-08', 'scheduled_end' => '2026-10-08']);
            $this->getJson('/api/shoots?'.$params.'&payment_status=paid')->assertOk()->assertJsonPath('meta.count', 1);
            $this->getJson('/api/shoots?'.$params.'&payment_status=unpaid')->assertOk()->assertJsonPath('meta.count', 2);
        }
        $params = http_build_query(['sales_rep_id' => $rep->id, 'services' => [$service->id], 'scheduled_start' => '2026-10-08', 'scheduled_end' => '2026-10-08']);
        $this->getJson('/api/shoots/history?'.$params.'&payment_status=paid')->assertOk()->assertJsonPath('meta.total', 3);
        $this->getJson('/api/shoots/history?'.$params.'&payment_status=unpaid')->assertOk()->assertJsonPath('meta.total', 6);
    }

    public function test_payment_filter_handles_legacy_status_zero_charge_duplicates_and_refunds(): void
    {
        $paid = Shoot::factory()->create(['payment_status' => 'pending', 'total_quote' => 200]);
        Payment::create(['shoot_id' => $paid->id, 'amount' => 200, 'status' => 'completed']);
        $zero = Shoot::factory()->create(['payment_status' => 'unpaid', 'total_quote' => 0]);
        $partial = Shoot::factory()->create(['payment_status' => 'pending', 'total_quote' => 200]);
        $payment = Payment::create(['shoot_id' => $partial->id, 'amount' => 200, 'status' => 'completed', 'stripe_payment_id' => 'pi_duplicate']);
        Payment::create(['shoot_id' => $partial->id, 'amount' => 200, 'status' => 'completed', 'stripe_payment_id' => 'pi_duplicate']);
        PaymentRefund::create(['payment_id' => $payment->id, 'shoot_id' => $partial->id, 'amount' => 50, 'status' => 'succeeded', 'provider' => 'stripe', 'operation_key' => 'filter-refund']);
        $failed = Shoot::factory()->create(['payment_status' => 'unpaid', 'total_quote' => 200]);
        Payment::create(['shoot_id' => $failed->id, 'amount' => 200, 'status' => 'failed']);
        $query = Shoot::query();
        app(ShootPaymentFilter::class)->apply($query, 'paid');
        $this->assertEqualsCanonicalizing([$paid->id, $zero->id], $query->pluck('id')->all());
        $query = Shoot::query();
        app(ShootPaymentFilter::class)->apply($query, 'unpaid');
        $this->assertEqualsCanonicalizing([$partial->id, $failed->id], $query->pluck('id')->all());
    }
}
