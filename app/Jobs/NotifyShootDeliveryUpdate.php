<?php

namespace App\Jobs;

use App\Models\Shoot;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Coalesce a batch of newly released files into one current delivery update. */
class NotifyShootDeliveryUpdate implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 120;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public int $timeout = 120;

    public function __construct(public int $shootId, public ?int $shootServiceId = null)
    {
        $this->onQueue('default')->delay(now()->addSeconds(15))->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->shootId;
    }

    public function handle(MailService $mail, AutomationService $automation): void
    {
        $shoot = Shoot::find($this->shootId);
        if (! $shoot || $shoot->suppressesExternalNotifications()) {
            return;
        }
        $full = $shoot->workflow_status === Shoot::STATUS_DELIVERED && $shoot->delivery_status === 'delivered';
        if (! $full && ! $shoot->serviceItems()->when($this->shootServiceId, fn ($query) => $query->whereKey($this->shootServiceId))
            ->where('delivery_status', 'delivered')->where('workflow_status', 'delivered')->exists()) {
            return;
        }

        // The same protected sender enforces notification preferences, quiet hours,
        // payment gates and content-revision idempotency. An update is not a new
        // completion automation or a new payment-reminder cadence anchor.
        (new SendShootReadyEmailJob($this->shootId, $full ? null : $this->shootServiceId, $full, false))
            ->handle($mail, $automation);
    }
}
