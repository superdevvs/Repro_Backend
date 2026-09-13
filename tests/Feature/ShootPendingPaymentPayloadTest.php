<?php

namespace Tests\Feature;

use App\Http\Resources\ShootResource;
use App\Models\Payment;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Shoots\ShootPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ShootPendingPaymentPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_detail_and_list_preserve_cash_and_cheque_review_without_counting_them_as_paid(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $shoot = Shoot::factory()->create(['total_quote' => 291.50]);
        foreach ([['cash', 100], ['check', 191.50], ['card', 291.50]] as [$method, $amount]) {
            $shoot->payments()->create([
                'amount' => $amount, 'currency' => 'USD', 'status' => Payment::STATUS_PENDING,
                'payment_method' => $method, 'payment_details' => ['notes' => 'QA receipt', 'check_number' => 'QA-123'],
            ]);
        }
        $presenter = app(ShootPresenter::class);
        $detail = $presenter->transformShoot($shoot->fresh())->toArray();
        $list = $presenter->transformOperationalShoot($shoot->fresh(), false);
        $request = Request::create('/api/shoots/'.$shoot->id);
        $request->setUserResolver(fn () => $admin);
        $resource = (new ShootResource($shoot->fresh()))->resolve($request);
        foreach ([$detail, $list, $resource] as $payload) {
            $this->assertCount(2, $payload['payment']['pendingPayments']);
            $this->assertSame(291.5, $payload['payment']['pendingTotal']);
            $this->assertSame('check', $payload['payment']['pendingPayments'][1]['paymentMethod']);
            $this->assertSame('QA-123', $payload['payment']['pendingPayments'][1]['checkNumber']);
            $this->assertSame('QA receipt', $payload['payment']['pendingPayments'][0]['notes']);
        }
        $this->assertSame(0.0, (float) $detail['total_paid']);
        $this->assertSame(291.5, (float) $detail['remaining_balance']);
        $shoot->payments()->where('payment_method', 'cash')->update(['status' => Payment::STATUS_COMPLETED]);
        $updated = $presenter->transformShoot($shoot->fresh())->toArray();
        $this->assertCount(1, $updated['payment']['pendingPayments']);
        $this->assertSame(100.0, (float) $updated['total_paid']);
        $this->assertSame(191.5, (float) $updated['remaining_balance']);
    }

    public function test_editor_does_not_receive_pending_payment_details(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $this->actingAs($editor);
        $shoot = Shoot::factory()->create(['editor_id' => $editor->id]);
        $shoot->payments()->create(['amount' => 100, 'status' => Payment::STATUS_PENDING, 'payment_method' => 'cash']);
        $payload = app(ShootPresenter::class)->transformShoot($shoot)->toArray();
        $this->assertSame([], $payload['payment']['pendingPayments']);
        $this->assertSame(0.0, $payload['payment']['pendingTotal']);
    }
}
