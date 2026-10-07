<?php

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Shoot;
use App\Models\User;
use App\Services\MailService;
use App\Support\LockedWrite;
use App\Support\ReportingWeek;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PayoutInvoiceWorkflow
{
    public function admin(User $actor): bool
    {
        return in_array($actor->role, ['admin', 'superadmin', 'editing_manager'], true);
    }

    public function editable(Invoice $invoice, User $actor): void
    {
        $allowed = $this->admin($actor)
            ? $invoice->isPayoutInvoice() && in_array($invoice->approval_status, ['pending', 'pending_approval', 'rejected'], true)
                && ! $invoice->is_paid && ! $invoice->paid_at && $invoice->status !== Invoice::STATUS_PAID
                && (float) $invoice->amount_paid === 0.0
            : $invoice->canBeModifiedByPayee() && (float) $invoice->amount_paid === 0.0;
        if (! $allowed) {
            throw ValidationException::withMessages(['invoice' => $invoice->editLockedReason() ?? 'This payout invoice is locked.']);
        }
    }

    public function mutate(Invoice $invoice, User $actor, array $data, ?InvoiceItem $item = null): Invoice
    {
        return $this->write($invoice, function (Invoice $locked) use ($actor, $data, $item) {
            $this->editable($locked, $actor);
            $this->checkRevision($locked, $data['expected_revision'] ?? null);
            $reason = trim($data['reason'] ?? '');
            if ($this->admin($actor) && $reason === '') {
                throw ValidationException::withMessages(['reason' => 'Explain the accounts correction before saving.']);
            }
            if (! $locked->payout_edit_baseline) {
                $locked->update(['payout_edit_baseline' => $locked->buildApprovalSnapshot()]);
            }
            $before = $locked->buildApprovalSnapshot();
            $action = $data['action'];
            $itemData = null;
            if ($item) {
                $item = $locked->items()->whereKey($item->id)->first();
                if (! $item || ! in_array($item->type, ['charge', 'expense'], true)) {
                    throw ValidationException::withMessages(['item' => 'The item does not belong to this payout invoice.']);
                }
                $itemData = $item->toArray();
            }
            if ($action === 'remove') {
                if (! $item) {
                    throw ValidationException::withMessages(['item' => 'Choose an invoice line to remove.']);
                }
                // A removed service remains allocated to this invoice, so moving
                // its shoot date cannot silently resurrect it next week.
                $workKey = data_get($item->meta, 'work_key')
                    ?? ($item->shoot_compensation_id ? 'compensation:'.$item->shoot_compensation_id : null)
                    ?? (data_get($item->meta, 'shoot_service_id') ? 'service:'.data_get($item->meta, 'shoot_service_id') : null);
                if ($workKey) {
                    DB::table('payout_work_allocations')->insertOrIgnore([
                        'role' => $locked->role, 'recipient_id' => $locked->photographer_id ?? $locked->sales_rep_id,
                        'work_key' => $workKey, 'invoice_id' => $locked->id, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                $item->delete();
            } elseif ($action === 'update') {
                if (! $item) {
                    throw ValidationException::withMessages(['item' => 'Choose an invoice line to update.']);
                }
                $quantity = $data['quantity'] ?? $item->quantity;
                $amount = $data['amount'] ?? $item->unit_amount;
                $meta = $item->meta ?? [];
                if ($this->admin($actor) && array_key_exists('verified', $data)) {
                    $meta['verified'] = (bool) $data['verified'];
                }
                $item->update([
                    'description' => $data['description'] ?? $item->description,
                    'quantity' => $quantity, 'unit_amount' => $amount,
                    'total_amount' => round($quantity * $amount, 2), 'meta' => $meta,
                ]);
            } else {
                $payload = $action === 'add_shoot'
                    ? $this->shootPayload($locked, $data)
                    : $this->manualPayload($locked, $actor, $data);
                $item = $locked->items()->create($payload);
                $itemData = $item->toArray();
            }
            $locked->unsetRelation('items');
            $locked->shoots()->sync($locked->items()->whereNotNull('shoot_id')->pluck('shoot_id')->unique()->all());
            $locked->unsetRelation('shoots');
            $locked->refreshTotals();
            $warnings = collect($locked->unresolved_warnings ?? [])->reject(fn ($w) => in_array(($w['code'] ?? ''), ['external_work_verification', 'historical_edit_recovery'], true))->values()->all();
            foreach ($locked->items()->get() as $line) {
                if (data_get($line->meta, 'source') === 'external_work' && ! data_get($line->meta, 'verified', false)) {
                    $warnings[] = ['code' => 'external_work_verification', 'message' => 'Verify external work: '.$line->description];
                }
            }
            $locked->update(['modified_by' => $actor->id, 'modified_at' => now(), 'unresolved_warnings' => $warnings]);
            $locked->recordAuditEvent($this->admin($actor) ? 'admin_edit' : 'payee_edit', $actor,
                ucfirst($this->admin($actor) ? 'Accounts' : 'Payee').' '.$action.' on payout invoice.', [
                    'action' => $action, 'reason' => $reason ?: null, 'item' => $itemData,
                    'revision' => $locked->payout_revision + 1,
                    'reconciled_historical_edits' => $this->admin($actor) && ($data['reconcile_history'] ?? false),
                    'before' => $before, 'after' => $locked->buildApprovalSnapshot(),
                ]);

            return $locked->fresh(['items', 'shoots', 'photographer', 'salesRep', 'auditEvents.actor']);
        });
    }

    public function submit(Invoice $invoice, User $actor, ?string $notes, bool $explicitChanges = false, ?int $revision = null): Invoice
    {
        $result = $this->write($invoice, function (Invoice $locked) use ($actor, $notes, $explicitChanges, $revision) {
            $this->editable($locked, $actor);
            $this->checkRevision($locked, $revision);
            $changed = $explicitChanges || $locked->payout_review['has_changes'] || $locked->approval_status === 'rejected';
            $notes = trim($notes ?? '');
            if ($changed && $notes === '') {
                throw ValidationException::withMessages(['notes' => 'Explain your changes before submitting this invoice.']);
            }
            $locked->unsetRelation('items');
            $locked->update([
                'approval_status' => Invoice::APPROVAL_STATUS_PENDING_APPROVAL,
                'modified_by' => $actor->id, 'modified_at' => now(),
                'modification_notes' => $notes ?: null,
                'payout_submission' => $changed ? 'changes' : 'unchanged',
                'payout_submission_snapshot' => $locked->buildApprovalSnapshot(),
                'rejection_reason' => null, 'rejected_by' => null, 'rejected_at' => null,
            ]);
            $locked->recordAuditEvent($explicitChanges ? 'submitted_with_changes' : 'submitted_for_approval', $actor,
                $changed ? 'Payee submitted changes for accounts review.' : 'Payee confirmed invoice unchanged.',
                ['notes' => $notes ?: null, 'revision' => $locked->payout_revision, 'snapshot' => $locked->payout_submission_snapshot]);

            return $locked->fresh(['items', 'shoots', 'photographer', 'salesRep', 'auditEvents.actor']);
        });
        app(MailService::class)->sendInvoicePendingApprovalEmail($result);

        return $result;
    }

    public function checkRevision(Invoice $invoice, ?int $revision): void
    {
        if ($revision !== null && $revision !== (int) $invoice->payout_revision) {
            throw ValidationException::withMessages(['expected_revision' => 'This invoice changed. Reopen it before saving or approving.']);
        }
    }

    private function write(Invoice $invoice, callable $callback): Invoice
    {
        return LockedWrite::run(fn () => DB::transaction(function () use ($invoice, $callback) {
            // Acquire SQLite's writer lock before reading the revision or allocations.
            DB::table('invoices')->where('id', $invoice->id)->update(['payout_revision' => DB::raw('payout_revision')]);

            return $callback(Invoice::query()->lockForUpdate()->findOrFail($invoice->id));
        }), 'payout-invoice-edit');
    }

    public function candidates(Invoice $invoice, string $search = '', ?string $date = null): array
    {
        $recipient = $invoice->photographer_id;
        $shoots = Shoot::query()->with(['services', 'units'])
            ->excludeHistoricalImportsFromNewBilling()
            ->where(fn ($q) => $q->where('photographer_id', $recipient)
                ->orWhereHas('services', fn ($s) => $s->where('shoot_service.photographer_id', $recipient)))
            ->where(fn ($q) => $q->whereNull('shoot_type')->orWhere('shoot_type', '!=', Shoot::SHOOT_TYPE_COMPLIMENTARY_RESHOOT))
            ->when($search !== '', fn ($q) => $q->where(fn ($s) => $s->where('address', 'like', '%'.$search.'%')->orWhere('id', ctype_digit($search) ? (int) $search : -1)))
            ->when($date, fn ($q) => $q->whereDate('scheduled_date', $date))
            ->orderByDesc('scheduled_date')->limit(50)->get();
        $rows = [];
        foreach ($shoots as $shoot) {
            foreach ($shoot->services as $service) {
                if ((int) ($service->pivot->photographer_id ?? $shoot->photographer_id) !== (int) $recipient) {
                    continue;
                }
                $amount = $this->servicePay($shoot, $service);
                $earned = $shoot->completed_at ?? $shoot->admin_verified_at;
                $workDate = $shoot->scheduled_date;
                $existing = $this->allocatedInvoice($invoice, 'service:'.$service->pivot->id, $shoot->id, $service->pivot->id);
                $reason = $existing ? 'Already allocated to invoice W-'.$existing
                    : ($shoot->photographer_paid_at ? 'Payout already recorded'
                    : (! in_array($shoot->workflow_status, [Shoot::WORKFLOW_COMPLETED, Shoot::WORKFLOW_ADMIN_VERIFIED], true) ? 'Not completed / verified'
                    : (! $earned ? 'Missing completion / verification date'
                    : (! $workDate ? 'Missing shoot date'
                    : ($amount <= 0 ? 'No photographer payout configured' : null)))));
                [$weekStart, $weekEnd] = $workDate ? ReportingWeek::containing($workDate) : [null, null];
                $rows[] = [
                    'shoot_id' => $shoot->id, 'shoot_service_id' => $service->pivot->id,
                    'address' => $shoot->address, 'service_name' => $this->serviceName($shoot, $service),
                    'scheduled_date' => $shoot->scheduled_date?->toDateString(),
                    'completed_date' => $earned?->toDateString(), 'amount' => $amount,
                    'eligible' => $reason === null, 'unavailable_reason' => $reason,
                    'earning_week' => $weekStart ? $weekStart->toDateString().' – '.$weekEnd->toDateString() : null,
                    'outside_period' => (bool) ($workDate && ($workDate->lt($invoice->billing_period_start) || $workDate->gt($invoice->billing_period_end->copy()->endOfDay()))),
                ];
            }
        }

        return $rows;
    }

    private function shootPayload(Invoice $invoice, array $data): array
    {
        $shoot = Shoot::with(['services', 'units'])->excludeHistoricalImportsFromNewBilling()->find($data['shoot_id']);
        $service = $shoot?->services->first(fn ($s) => (int) $s->pivot->id === (int) $data['shoot_service_id']);
        if (! $shoot || ! $service || (int) ($service->pivot->photographer_id ?? $shoot->photographer_id) !== (int) $invoice->photographer_id
            || $shoot->isComplimentaryReshoot() || $shoot->photographer_paid_at
            || ! in_array($shoot->workflow_status, [Shoot::WORKFLOW_COMPLETED, Shoot::WORKFLOW_ADMIN_VERIFIED], true)) {
            throw ValidationException::withMessages(['shoot_id' => 'Choose a completed, unpaid service assigned to this photographer.']);
        }
        $earned = $shoot->completed_at ?? $shoot->admin_verified_at;
        if (! $earned) {
            throw ValidationException::withMessages(['shoot_id' => 'Accounts must verify the completion date before adding this service.']);
        }
        $workDate = $shoot->scheduled_date;
        if (! $workDate) {
            throw ValidationException::withMessages(['shoot_id' => 'Accounts must verify the shoot date before adding this service.']);
        }
        if ($workDate->lt($invoice->billing_period_start) || $workDate->gt($invoice->billing_period_end->copy()->endOfDay())) {
            if (trim($data['reason'] ?? '') === '') {
                throw ValidationException::withMessages(['reason' => 'Explain why work shot outside this invoice week belongs here.']);
            }
        }
        $amount = $this->servicePay($shoot, $service);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['shoot_id' => 'This service has no photographer payout.']);
        }
        $key = 'service:'.$service->pivot->id;
        $this->reserve($invoice, $key, $shoot->id, $service->pivot->id);

        return [
            'shoot_id' => $shoot->id, 'type' => 'charge',
            'description' => 'Shoot #'.$shoot->id.' - '.$shoot->address.' - '.$this->serviceName($shoot, $service),
            'quantity' => 1, 'unit_amount' => $amount, 'total_amount' => $amount,
            'recorded_at' => $workDate,
            'meta' => ['source' => 'linked_work', 'service_id' => $service->id,
                'shoot_unit_id' => $service->pivot->shoot_unit_id,
                'unit_label' => $shoot->units->firstWhere('id', $service->pivot->shoot_unit_id)?->label,
                'shoot_service_id' => $service->pivot->id, 'work_key' => $key, 'reason' => $data['reason'] ?? null],
        ];
    }

    private function manualPayload(Invoice $invoice, User $actor, array $data): array
    {
        $meta = ['source' => $this->admin($actor) ? 'admin_added' : 'photographer_added'];
        if ($data['action'] === 'add_external') {
            $reference = self::normalizeExternalReference($data['reference']);
            $key = self::externalWorkKey($reference);
            if (str_starts_with($reference, 'viewshoot:')) {
                $importedIds = DB::table('legacy_shoot_imports')->where('source_id', substr($reference, 10))->pluck('shoot_id');
                if ($importedIds->isNotEmpty()) {
                    throw ValidationException::withMessages(['reference' => 'This legacy job already exists in the dashboard. Add its shoot service instead, after accounts verifies any historical payout.']);
                }
            }
            $this->reserve($invoice, $key);
            $meta = ['source' => 'external_work', 'work_key' => $key, 'reference' => $reference,
                'work_date' => $data['work_date'], 'address' => $data['address'],
                'reason' => $data['reason'], 'verified' => $this->admin($actor) && ($data['verified'] ?? false)];
        }
        $quantity = $data['quantity'] ?? 1;

        return [
            'type' => $data['action'] === 'add_expense' ? 'expense' : 'charge',
            'description' => $data['description'], 'quantity' => $quantity,
            'unit_amount' => $data['amount'], 'total_amount' => round($quantity * $data['amount'], 2),
            'recorded_at' => $data['work_date'] ?? now(), 'meta' => $meta,
        ];
    }

    private function servicePay(Shoot $shoot, $service): float
    {
        $pivot = $service->pivot;
        $sqft = $shoot->units->firstWhere('id', $pivot->shoot_unit_id)?->sqft
            ?? data_get($shoot->property_details, 'sqft') ?? data_get($shoot->property_details, 'squareFeet')
            ?? data_get($shoot->property_details, 'square_feet');
        $pay = $pivot->photographer_pay ?? $service->getPhotographerPayForSqft($sqft) ?? $service->photographer_pay ?? 0;

        return round((float) $pay * (int) ($pivot->quantity ?? 1), 2);
    }

    private function serviceName(Shoot $shoot, $service): string
    {
        $unit = $shoot->units->firstWhere('id', $service->pivot->shoot_unit_id);

        return ($unit?->label ? $unit->label.' · ' : '').$service->name;
    }

    private function allocatedInvoice(Invoice $invoice, string $key, ?int $shootId = null, ?int $serviceId = null): ?int
    {
        $owner = DB::table('payout_work_allocations')->where('role', $invoice->role)
            ->where('recipient_id', $invoice->photographer_id ?? $invoice->sales_rep_id)->where('work_key', $key)->value('invoice_id');
        if ($owner) {
            return (int) $owner;
        }
        if (! $shootId) {
            return null;
        }
        foreach (DB::table('legacy_shoot_imports')->where('shoot_id', $shootId)->pluck('source_id') as $sourceId) {
            $externalOwner = DB::table('payout_work_allocations')->where('role', $invoice->role)
                ->where('recipient_id', $invoice->photographer_id)->where('work_key', self::externalWorkKey('viewshoot:'.$sourceId))->value('invoice_id');
            if ($externalOwner) {
                return (int) $externalOwner;
            }
        }

        return InvoiceItem::query()->where('shoot_id', $shootId)->where('type', 'charge')
            ->whereHas('invoice', fn ($q) => $q->where('role', $invoice->role)->where('photographer_id', $invoice->photographer_id))
            ->where(fn ($q) => $q->where('meta->shoot_service_id', $serviceId)
                ->orWhere(fn ($s) => $s->whereNull('meta->shoot_service_id')->whereNull('shoot_compensation_id')))
            ->value('invoice_id');
    }

    private function reserve(Invoice $invoice, string $key, ?int $shootId = null, ?int $serviceId = null): void
    {
        $owner = $this->allocatedInvoice($invoice, $key, $shootId, $serviceId);
        if ($owner === (int) $invoice->id && ! $invoice->items()->where('meta->work_key', $key)->exists()
            && ! ($serviceId && $invoice->items()->where('meta->shoot_service_id', $serviceId)->exists())) {
            return;
        }
        if ($owner) {
            throw ValidationException::withMessages(['shoot_id' => 'This work is already allocated to invoice W-'.$owner.'.']);
        }
        $inserted = DB::table('payout_work_allocations')->insertOrIgnore([
            'role' => $invoice->role, 'recipient_id' => $invoice->photographer_id ?? $invoice->sales_rep_id,
            'work_key' => $key, 'invoice_id' => $invoice->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        if (! $inserted) {
            throw ValidationException::withMessages(['shoot_id' => 'This work was just allocated to another invoice. Refresh before adding it.']);
        }
    }

    public static function normalizeExternalReference(string $reference): string
    {
        $reference = strtolower(trim($reference));
        if (preg_match('~(?:viewshoot[:/][0-9]+[:/]|pro\.reprophotos\.com/(?:download/))([0-9]+)~', $reference, $matches)) {
            return 'viewshoot:'.$matches[1];
        }
        if (preg_match('~^viewshoot:([0-9]+)$~', $reference) || ctype_digit($reference)) {
            return 'viewshoot:'.str_replace('viewshoot:', '', $reference);
        }

        return $reference;
    }

    public static function externalWorkKey(string $reference): string
    {
        return 'external:'.hash('sha256', self::normalizeExternalReference($reference));
    }
}
