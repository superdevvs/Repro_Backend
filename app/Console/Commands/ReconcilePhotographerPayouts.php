<?php

namespace App\Console\Commands;

use App\Services\InvoiceService;
use App\Services\Invoices\PhotographerPayoutReconciliation;
use App\Support\ReportingWeek;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ReconcilePhotographerPayouts extends Command
{
    protected $signature = 'payouts:reconcile-photographers {--start=} {--end=} {--photographer=} {--apply : Save draft invoices without emails, approval or payment}';
    protected $description = 'Preview or silently reconcile earned photographer work and unallocated earlier work';

    public function handle(): int
    {
        [$start,$end] = ReportingWeek::lastCompleted();
        if ($this->option('start')) $start=Carbon::parse($this->option('start'))->startOfDay();
        if ($this->option('end')) $end=Carbon::parse($this->option('end'))->endOfDay();
        if ($end->lt($start)) { $this->error('End must be on or after start.'); return self::FAILURE; }
        $recipient=$this->option('photographer') ? (int)$this->option('photographer') : null;
        $rows=app(PhotographerPayoutReconciliation::class)->rows($start,$end,true,$recipient)
            ->when($recipient,fn($rows)=>$rows->where('resolved_photographer_id',$recipient));
        $this->table(['Shoot','Work date','Recipient','Amount','State','Allocated invoice'], $rows->map(fn($r)=>[
            $r['shoot_id'],$r['scheduled_date']->toDateString(),$r['resolved_photographer_id'],number_format($r['photographer_pay'],2),
            $r['eligible'] ? ($r['late_work'] ? 'Completed, earlier week' : 'Photographer completed') : $r['pending_reason'],$r['owner_invoice_id']??'Unallocated',
        ]));
        if ($this->option('apply')) {
            $invoices=app(InvoiceService::class)->generateForPeriod($start,$end,false,$recipient);
            $this->line('Reconciled invoice IDs: '.$invoices->pluck('id')->unique()->implode(', '));
            $this->line('No emails, approvals or payments were created.');
        } else $this->line('Preview only. Use --apply to save draft invoices.');
        return self::SUCCESS;
    }
}
