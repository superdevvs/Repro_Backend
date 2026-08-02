<?php

namespace Tests\Unit;

use App\Models\Payment;
use App\Models\PaymentRefund;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;

/**
 * Refund arithmetic on a payment.
 *
 * A partial refund must leave the payment contributing its remainder. The old
 * behaviour flipped `status` to `refunded`, which removed the payment from the
 * paid total entirely, so refunding $50 of $500 lost the whole $500.
 */
class PaymentRefundAmountTest extends TestCase
{
    private function paymentWithRefunds(float $amount, array $refundAmounts): Payment
    {
        $payment = new Payment();
        $payment->forceFill(['amount' => $amount]);

        $refunds = new Collection(array_map(function (float $refundAmount) {
            $refund = new PaymentRefund();
            $refund->forceFill(['amount' => $refundAmount]);

            return $refund;
        }, $refundAmounts));

        // Seed the relation so no database access is needed.
        $payment->setRelation('refunds', $refunds);

        return $payment;
    }

    public function test_no_refunds_means_full_contribution(): void
    {
        $payment = $this->paymentWithRefunds(500.00, []);

        $this->assertSame(0.0, $payment->refundedAmount());
        $this->assertSame(500.00, $payment->netAmount());
        $this->assertFalse($payment->isFullyRefunded());
    }

    public function test_partial_refund_leaves_the_remainder(): void
    {
        $payment = $this->paymentWithRefunds(500.00, [50.00]);

        $this->assertSame(50.00, $payment->refundedAmount());
        $this->assertSame(450.00, $payment->netAmount());
        $this->assertSame(450.00, $payment->refundableRemainder());
        $this->assertFalse($payment->isFullyRefunded());
    }

    public function test_multiple_partial_refunds_accumulate(): void
    {
        $payment = $this->paymentWithRefunds(500.00, [100.00, 75.50, 24.50]);

        $this->assertSame(200.00, $payment->refundedAmount());
        $this->assertSame(300.00, $payment->netAmount());
        $this->assertFalse($payment->isFullyRefunded());
    }

    public function test_refunds_summing_to_the_amount_are_full(): void
    {
        $payment = $this->paymentWithRefunds(500.00, [200.00, 300.00]);

        $this->assertSame(0.0, $payment->netAmount());
        $this->assertTrue($payment->isFullyRefunded());
    }

    public function test_net_amount_never_goes_negative(): void
    {
        // Defensive: a data error must not let one payment subtract from others.
        $payment = $this->paymentWithRefunds(100.00, [250.00]);

        $this->assertSame(0.0, $payment->netAmount());
        $this->assertSame(0.0, $payment->refundableRemainder());
        $this->assertTrue($payment->isFullyRefunded());
    }

    public function test_cent_rounding_is_stable(): void
    {
        $payment = $this->paymentWithRefunds(100.00, [33.33, 33.33, 33.34]);

        $this->assertSame(100.00, $payment->refundedAmount());
        $this->assertSame(0.0, $payment->netAmount());
        $this->assertTrue($payment->isFullyRefunded());
    }

    public function test_remainder_shrinks_as_refunds_are_added(): void
    {
        $amount = 500.00;
        $refunds = [];
        $previousRemainder = $amount;

        foreach ([50.00, 100.00, 25.00] as $refund) {
            $refunds[] = $refund;
            $payment = $this->paymentWithRefunds($amount, $refunds);
            $remainder = $payment->refundableRemainder();

            $this->assertLessThan($previousRemainder, $remainder);
            $this->assertGreaterThanOrEqual(0.0, $remainder);
            $previousRemainder = $remainder;
        }

        $this->assertSame(325.00, $previousRemainder);
    }
}
