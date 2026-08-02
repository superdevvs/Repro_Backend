<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make refunds first-class records instead of a status flag on the payment.
 *
 * Meeting 26 Jul 2026 [00:18:31]–[00:22:37]: partial refunds were impossible and
 * the balance was wrong after any refund. The cause was structural — a refund
 * only set `payments.status = 'refunded'`, and the paid total counts completed
 * payments, so refunding $50 of a $500 payment removed all $500 from the total.
 *
 * With refunds stored as their own rows the paid total becomes
 * `sum(completed payments) - sum(refunds)`, which is correct for partial and
 * full refunds alike.
 *
 * The backfill lifts refund data out of the `payment_details` JSON blob where
 * the Stripe path had been writing it, and is safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_refunds')) {
            Schema::create('payment_refunds', function (Blueprint $table) {
                $table->id();
                $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
                // Denormalised so a shoot's refunds can be summed without joining
                // through payments, mirroring how payments carry shoot_id.
                $table->foreignId('shoot_id')->nullable()->constrained('shoots')->nullOnDelete();
                $table->decimal('amount', 10, 2);
                $table->string('provider')->nullable();
                $table->string('provider_refund_id')->nullable();
                $table->text('reason')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index('payment_id');
                $table->index('shoot_id');
            });
        }

        $this->backfillFromPaymentDetails();
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_refunds');
    }

    /**
     * Recover refunds recorded before this table existed.
     *
     * Historical refunds live in `payments.payment_details` as `refunded_at` /
     * `refund_amount` / `refund_status`. Only payments already marked refunded
     * are considered, and a payment that already has a refund row is skipped so
     * a re-run cannot double-count.
     */
    private function backfillFromPaymentDetails(): void
    {
        if (! Schema::hasTable('payments') || ! Schema::hasColumn('payments', 'payment_details')) {
            return;
        }

        DB::table('payments')
            ->where('status', 'refunded')
            ->orderBy('id')
            ->chunkById(200, function ($payments) {
                foreach ($payments as $payment) {
                    $alreadyRecorded = DB::table('payment_refunds')
                        ->where('payment_id', $payment->id)
                        ->exists();

                    if ($alreadyRecorded) {
                        continue;
                    }

                    $details = $this->decodeDetails($payment->payment_details ?? null);

                    // Fall back to the full payment amount: the payment is flagged
                    // refunded, so absent a recorded partial amount the whole
                    // charge was returned.
                    $amount = $details['refund_amount']
                        ?? $details['refunded_amount']
                        ?? $payment->amount;

                    $amount = round((float) $amount, 2);
                    if ($amount <= 0) {
                        continue;
                    }

                    $refundedAt = $details['refunded_at'] ?? $payment->updated_at ?? now();

                    DB::table('payment_refunds')->insert([
                        'payment_id' => $payment->id,
                        'shoot_id' => $payment->shoot_id ?? null,
                        'amount' => $amount,
                        'provider' => $payment->payment_method ?? null,
                        'provider_refund_id' => $details['refund_id'] ?? null,
                        'reason' => 'Backfilled from payment_details',
                        'created_by' => null,
                        'created_at' => $refundedAt,
                        'updated_at' => $refundedAt,
                    ]);
                }
            });
    }

    private function decodeDetails(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
};
