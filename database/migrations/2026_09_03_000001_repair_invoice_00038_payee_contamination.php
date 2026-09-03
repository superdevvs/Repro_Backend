<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INVOICE_ID = 112;

    private const SHOOT_ID = 82;

    private const CLIENT_ID = 1102;

    private const PAYMENT_ID = 28;

    private const ROGUE_ITEM_IDS = [231, 232, 233];

    public function up(): void
    {
        if (! Schema::hasTable('invoices')
            || ! Schema::hasTable('invoice_items')
            || ! Schema::hasTable('payments')
            || ! Schema::hasTable('shoots')) {
            return;
        }

        $candidate = DB::table('invoices')
            ->where('id', self::INVOICE_ID)
            ->where('invoice_number', 'Invoice 00038')
            ->where('role', 'client')
            ->where('client_id', self::CLIENT_ID)
            ->where('shoot_id', self::SHOOT_ID)
            ->exists();

        // This is a one-record production repair. Other installations and test
        // databases should remain untouched even if they also have row 112.
        if (! $candidate) {
            return;
        }

        DB::transaction(function (): void {
            $invoice = DB::table('invoices')->where('id', self::INVOICE_ID)->lockForUpdate()->first();
            $shoot = DB::table('shoots')->where('id', self::SHOOT_ID)->lockForUpdate()->first();
            $payment = DB::table('payments')->where('id', self::PAYMENT_ID)->lockForUpdate()->first();

            $this->assertInvariant($invoice !== null, 'Invoice 00038 no longer exists.');
            $this->assertInvariant($shoot !== null && (int) $shoot->client_id === self::CLIENT_ID, 'Shoot 82 does not match the expected client.');
            $this->assertInvariant(abs((float) $shoot->total_quote - 105.30) < 0.005, 'Shoot 82 total is no longer $105.30.');
            $this->assertInvariant($payment !== null && (int) $payment->shoot_id === self::SHOOT_ID, 'Payment 28 no longer belongs to shoot 82.');
            $this->assertInvariant((int) $payment->invoice_id === self::INVOICE_ID, 'Payment 28 no longer belongs to invoice 00038.');
            $this->assertInvariant($payment->status === 'completed' && abs((float) $payment->amount - 105.30) < 0.005, 'Payment 28 is not the expected completed $105.30 payment.');

            $rogueItems = DB::table('invoice_items')
                ->where('invoice_id', self::INVOICE_ID)
                ->whereIn('id', self::ROGUE_ITEM_IDS)
                ->lockForUpdate()
                ->orderBy('id')
                ->get();

            if ($rogueItems->isNotEmpty()) {
                $this->assertInvariant($rogueItems->count() === 3, 'Invoice 00038 is only partially contaminated; refusing an unsafe repair.');

                $expected = [
                    231 => ['type' => 'charge', 'description' => 'Misc', 'amount' => 1000.00],
                    232 => ['type' => 'expense', 'description' => 'Travel', 'amount' => 50.00],
                    233 => ['type' => 'charge', 'description' => 'People pleasing', 'amount' => 5.00],
                ];

                foreach ($rogueItems as $item) {
                    $match = $expected[(int) $item->id] ?? null;
                    $this->assertInvariant(
                        $match !== null
                        && $item->type === $match['type']
                        && $item->description === $match['description']
                        && abs((float) $item->total_amount - $match['amount']) < 0.005,
                        "Invoice item {$item->id} no longer matches the verified rogue line."
                    );
                }

                $this->assertInvariant(
                    abs((float) $rogueItems->sum('total_amount') - 1055.00) < 0.005,
                    'The verified rogue invoice lines no longer total $1,055.'
                );

                DB::table('invoice_items')->whereIn('id', self::ROGUE_ITEM_IDS)->delete();
            }

            $remaining = DB::table('invoice_items')
                ->where('invoice_id', self::INVOICE_ID)
                ->lockForUpdate()
                ->get();
            $this->assertInvariant(
                $remaining->count() === 1
                && (int) $remaining->first()->id === 228
                && (int) $remaining->first()->shoot_id === self::SHOOT_ID
                && $remaining->first()->type === 'charge'
                && abs((float) $remaining->first()->total_amount - 100.00) < 0.005,
                'Invoice 00038 does not contain only its verified $100 service line after cleanup.'
            );

            $paidAt = $payment->processed_at ?? $payment->updated_at ?? now();
            DB::table('invoices')->where('id', self::INVOICE_ID)->update([
                'charges_total' => 100.00,
                'payments_total' => 105.30,
                'balance_due' => 0,
                'subtotal' => 100.00,
                'tax' => 5.30,
                'total' => 105.30,
                'total_amount' => 105.30,
                'amount_paid' => 105.30,
                'status' => 'paid',
                'is_paid' => true,
                'paid_at' => $paidAt,
                'approval_status' => 'pending',
                'modified_by' => null,
                'modified_at' => null,
                'modification_notes' => null,
                'rejected_by' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
                'approved_by' => null,
                'approved_at' => null,
                'approval_snapshot' => null,
                'unresolved_warnings' => null,
                'warning_override_reason' => null,
                'warning_override_by' => null,
                'warning_override_at' => null,
                'updated_at' => now(),
            ]);

            if (Schema::hasTable('invoice_audit_events')) {
                DB::table('invoice_audit_events')->updateOrInsert(
                    [
                        'invoice_id' => self::INVOICE_ID,
                        'event' => 'client_invoice_payee_contamination_repaired',
                    ],
                    [
                        'actor_id' => null,
                        'summary' => 'Removed payout-workflow lines that were incorrectly added to a client invoice.',
                        'metadata' => json_encode([
                            'removed_item_ids' => self::ROGUE_ITEM_IDS,
                            'removed_total' => 1055.00,
                            'restored_total' => 105.30,
                            'payment_id' => self::PAYMENT_ID,
                        ]),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        });
    }

    public function down(): void
    {
        // Restoring invalid client charges would recreate debt that was never
        // owed. The audit event preserves what was removed and why.
    }

    private function assertInvariant(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new \RuntimeException($message);
        }
    }
};
