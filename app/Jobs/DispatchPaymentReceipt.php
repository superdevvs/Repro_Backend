<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Services\Messaging\AutomationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchPaymentReceipt implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [15, 60, 180, 300];

    public int $timeout = 120;

    public function __construct(public array $paymentIds)
    {
        $this->onQueue('default');
    }

    public function handle(AutomationService $automationService): void
    {
        $result = $automationService->sendAcceptedPaymentReceipt(Payment::whereIn('id', $this->paymentIds)->get());
        if (($result['failed_run_count'] ?? 0) > 0) {
            throw new \RuntimeException('Payment receipt automation failed; successful deliveries will be skipped on retry.');
        }
    }
}
