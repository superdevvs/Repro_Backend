<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('invoices')) {
            return;
        }

        DB::table('invoices')
            ->where('approval_status', 'rejected')
            ->whereNotNull('rejected_by')
            ->where(function ($query) {
                $query
                    ->whereColumn('rejected_by', 'photographer_id')
                    ->orWhereColumn('rejected_by', 'sales_rep_id');
            })
            ->select([
                'id',
                'rejected_by',
                'rejection_reason',
                'modification_notes',
                'rejected_at',
                'updated_at',
            ])
            ->orderBy('id')
            ->chunkById(100, function ($legacyInvoices) {
                foreach ($legacyInvoices as $invoice) {
                    DB::transaction(function () use ($invoice) {
                        $changedAt = $invoice->rejected_at ?? $invoice->updated_at ?? now();
                        $notes = $invoice->modification_notes
                            ?: $invoice->rejection_reason
                            ?: 'Payee submitted invoice changes.';

                        $updated = DB::table('invoices')
                            ->where('id', $invoice->id)
                            ->where('approval_status', 'rejected')
                            ->where('rejected_by', $invoice->rejected_by)
                            ->update([
                                'approval_status' => 'pending_approval',
                                'modified_by' => $invoice->rejected_by,
                                'modified_at' => $changedAt,
                                'modification_notes' => $notes,
                                'rejected_by' => null,
                                'rejected_at' => null,
                                'rejection_reason' => null,
                                'updated_at' => now(),
                            ]);

                        if ($updated !== 1 || !Schema::hasTable('invoice_audit_events')) {
                            return;
                        }

                        DB::table('invoice_audit_events')->insert([
                            'invoice_id' => $invoice->id,
                            'actor_id' => $invoice->rejected_by,
                            'event' => 'legacy_payee_changes_resubmitted',
                            'summary' => 'Legacy payee-rejected invoice moved to admin review.',
                            'metadata' => json_encode([
                                'previous_reason' => $invoice->rejection_reason,
                            ]),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    });
                }
            }, 'id', 'id');
    }

    public function down(): void
    {
        // This repairs workflow state. Reverting it could overwrite a later admin
        // decision, so the data transition is intentionally not reversed.
    }
};
