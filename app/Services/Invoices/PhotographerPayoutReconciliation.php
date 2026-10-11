<?php

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Shoot;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class PhotographerPayoutReconciliation
{
    public function rows(Carbon $start, Carbon $end, bool $includeLate = true, ?int $recipientId = null): Collection
    {
        $basis = app(PhotographerPayoutBasis::class);
        $shoots = Shoot::with(['services', 'units', 'service', 'files' => fn ($q) => $q
            ->select(['shoot_id', 'shoot_service_id', 'media_type'])->distinct()->whereIn('media_type', ['raw', 'floorplan'])])
            ->excludeHistoricalImportsFromNewBilling()
            ->where(fn ($q) => $q->whereNull('shoot_type')->orWhereNotIn('shoot_type', [Shoot::SHOOT_TYPE_COMPLIMENTARY_RESHOOT, Shoot::SHOOT_TYPE_INTERNAL_TEST]))
            ->whereDate('scheduled_date', '<=', $end->toDateString())
            ->when(! $includeLate, fn ($q) => $q->whereDate('scheduled_date', '>=', $start->toDateString()))
            ->when($recipientId, fn ($q) => $q->where(fn ($assigned) => $assigned->where('photographer_id', $recipientId)
                ->orWhereHas('services', fn ($s) => $s->where('shoot_service.photographer_id', $recipientId))))
            ->whereNotIn('workflow_status', ['cancelled', 'declined', 'on_hold'])
            ->get();
        // Batch ownership lookups: review and recap must not query the entire
        // allocation history once for every booked line.
        $allocations = DB::table('payout_work_allocations')->where('role', Invoice::ROLE_PHOTOGRAPHER)
            ->when($recipientId, fn ($q) => $q->where('recipient_id', $recipientId))->get()
            ->keyBy(fn ($a) => $a->recipient_id.'|'.$a->work_key);
        $legacy = DB::table('legacy_shoot_imports')->whereIn('shoot_id', $shoots->pluck('id'))->get()->groupBy('shoot_id');
        $items = InvoiceItem::with('invoice:id,photographer_id')->whereIn('shoot_id', $shoots->pluck('id'))
            ->where('type', 'charge')->whereNull('shoot_compensation_id')
            ->whereHas('invoice', fn ($q) => $q->where('role', Invoice::ROLE_PHOTOGRAPHER)
                ->when($recipientId, fn ($i) => $i->where('photographer_id', $recipientId)))->get();
        $lineOwners = [];
        foreach ($items as $item) {
            $lineOwners[$item->invoice->photographer_id.'|'.$item->shoot_id.'|'.(data_get($item->meta, 'shoot_service_id') ?? 'legacy')] = $item->invoice_id;
        }
        $periodIds = Invoice::where('role', Invoice::ROLE_PHOTOGRAPHER)
            ->whereDate('billing_period_start', '>=', $start->toDateString())->whereDate('billing_period_end', '<=', $end->toDateString())->pluck('id')->all();
        $rows = collect();
        foreach ($shoots as $shoot) {
            $services = $shoot->services;
            // Legacy single-service shoots still need a deterministic identity.
            if ($services->isEmpty() && $shoot->service) {
                $service = $shoot->service;
                $pivot = new \App\Models\ShootServicePivot(['shoot_id'=>$shoot->id, 'service_id'=>$service->id, 'quantity'=>1]);
                $service->setRelation('pivot', $pivot);
                $services = collect([$service]);
            }
            foreach ($services as $service) {
                $line = $service->pivot;
                $recipient = $line->photographer_id ?? $shoot->photographer_id;
                if (! $recipient || ($recipientId && (int) $recipient !== $recipientId) || $line->cancelled_at || in_array($line->workflow_status, ['cancelled', 'declined'], true)) continue;
                $snapshot = $basis->forLine($line, $shoot, $service);
                $amount = $basis->amount($line, $shoot, $service);
                if (abs($amount) < 0.005) continue;
                $key = $line->id ? 'service:'.$line->id : 'shoot:'.$shoot->id.':service:'.$service->id;
                $owner = $allocations->get($recipient.'|'.$key)?->invoice_id
                    ?? $lineOwners[$recipient.'|'.$shoot->id.'|'.($line->id ?? 'legacy')]
                    ?? $lineOwners[$recipient.'|'.$shoot->id.'|legacy'] ?? null;
                foreach ($legacy->get($shoot->id, collect()) as $import) {
                    $owner ??= $allocations->get($recipient.'|'.PayoutInvoiceWorkflow::externalWorkKey('viewshoot:'.$import->source_id))?->invoice_id;
                }
                $late = $shoot->scheduled_date->toDateString() < $start->toDateString();
                if ($owner && ! in_array((int) $owner, $periodIds, true)) continue;
                if ($shoot->photographer_paid_at && ! $owner) continue;
                $completed = $basis->completion($shoot, $line);
                $unit = $shoot->units->firstWhere('id', $line->shoot_unit_id);
                $rows->push([
                    'shoot_id'=>$shoot->id, 'shoot'=>$shoot, 'service_id'=>$service->id,
                    'shoot_service_id'=>$line->id, 'shoot_unit_id'=>$line->shoot_unit_id, 'unit_label'=>$unit?->label,
                    'service_name'=>($unit?->label ? $unit->label.' · ' : '').($snapshot['service_name'] ?? $service->name),
                    'resolved_photographer_id'=>$recipient, 'photographer_pay'=>$amount,
                    'scheduled_date'=>$shoot->scheduled_date, 'address'=>$shoot->address,
                    'work_key'=>$key, 'owner_invoice_id'=>$owner, 'late_work'=>$late,
                    'eligible'=>$completed !== null, 'completed_date'=>$completed,
                    'pending_reason'=>$completed === null ? 'Awaiting photographer submission / upload or completed floorplan work' : null,
                    'payout_basis'=>$snapshot,
                ]);
            }
        }
        return $rows;
    }

    public function owner(int $recipient, string $key, int $shootId, ?int $lineId): ?int
    {
        $owner = DB::table('payout_work_allocations')->where('role', Invoice::ROLE_PHOTOGRAPHER)
            ->where('recipient_id', $recipient)->where('work_key', $key)->value('invoice_id');
        if ($owner) return (int) $owner;
        foreach (DB::table('legacy_shoot_imports')->where('shoot_id', $shootId)->pluck('source_id') as $sourceId) {
            $owner = DB::table('payout_work_allocations')->where('role', Invoice::ROLE_PHOTOGRAPHER)
                ->where('recipient_id', $recipient)->where('work_key', PayoutInvoiceWorkflow::externalWorkKey('viewshoot:'.$sourceId))->value('invoice_id');
            if ($owner) return (int) $owner;
        }
        return InvoiceItem::where('shoot_id', $shootId)->where('type', 'charge')
            ->whereHas('invoice', fn ($q) => $q->where('role', Invoice::ROLE_PHOTOGRAPHER)->where('photographer_id', $recipient))
            ->where(fn ($q) => $q->where('meta->shoot_service_id', $lineId)
                ->orWhere(fn ($q) => $q->whereNull('meta->shoot_service_id')->whereNull('shoot_compensation_id')))->value('invoice_id');
    }

    public function review(Invoice $invoice): array
    {
        if ($invoice->role !== Invoice::ROLE_PHOTOGRAPHER) return [];
        $rows = $this->rows($invoice->billing_period_start, $invoice->billing_period_end, true, $invoice->photographer_id)
            ->where('resolved_photographer_id', $invoice->photographer_id)
            ->filter(fn ($r) => ! $r['owner_invoice_id'] || (int)$r['owner_invoice_id'] === (int)$invoice->id);
        $pending = $rows->where('eligible', false)->whereNull('owner_invoice_id');
        $unallocated = $rows->where('eligible', true)->whereNull('owner_invoice_id');
        $public = fn ($r) => [
            'shoot_id'=>$r['shoot_id'], 'shoot_service_id'=>$r['shoot_service_id'], 'address'=>$r['address'],
            'service_name'=>$r['service_name'], 'work_date'=>$r['scheduled_date']->toDateString(),
            'amount'=>$r['photographer_pay'], 'reason'=>$r['pending_reason'], 'late_work'=>$r['late_work'],
        ];
        return [
            'pending_count'=>$pending->pluck('shoot_id')->unique()->count(), 'pending_total'=>round($pending->sum('photographer_pay'),2),
            'pending_lines'=>$pending->map($public)->values()->all(),
            'unallocated_count'=>$unallocated->count(), 'unallocated_total'=>round($unallocated->sum('photographer_pay'),2),
            'unallocated_lines'=>$unallocated->map($public)->values()->all(),
        ];
    }
}
