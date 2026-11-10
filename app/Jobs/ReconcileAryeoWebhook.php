<?php

namespace App\Jobs;

use App\Models\AryeoApiExport;
use App\Services\Aryeo\Api\Operations;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ReconcileAryeoWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(public string $eventId)
    {
        $this->onQueue(config('aryeo_api.queue'));
    }

    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(): void
    {
        $event = DB::table('aryeo_api_webhooks')->where('event_id', $this->eventId)->first();
        if (! $event || $event->processed_at) {
            return;
        }
        // Legacy resource payload is not authoritative. Re-read only RePro's
        // unresolved exports in this installation; never infer publication.
        foreach (AryeoApiExport::whereHas('destination', fn ($q) => $q->where('installation_id', $event->installation_id))
            ->whereHas('operations', fn ($q) => $q->whereIn('status', ['sent', 'unknown']))->limit(20)->get() as $export) {
            app(Operations::class)->reconcile($export);
        }
        DB::table('aryeo_api_webhooks')->where('event_id', $this->eventId)->update(['processed_at' => now()]);
    }
}
