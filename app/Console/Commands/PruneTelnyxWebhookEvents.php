<?php

namespace App\Console\Commands;

use App\Models\TelnyxWebhookEvent;
use Illuminate\Console\Command;

class PruneTelnyxWebhookEvents extends Command
{
    protected $signature = 'telnyx:prune-webhook-events';

    protected $description = 'Prune retained raw Telnyx webhook event payloads after the configured retention window.';

    public function handle(): int
    {
        $days = (int) config('services.telnyx.webhook_events.raw_retention_days', 30);
        $cutoff = now()->subDays(max(1, $days));

        $count = TelnyxWebhookEvent::query()
            ->where('created_at', '<', $cutoff)
            ->update(['raw_event_json' => null]);

        $this->info("Pruned raw payloads for {$count} Telnyx webhook events.");

        return self::SUCCESS;
    }
}
