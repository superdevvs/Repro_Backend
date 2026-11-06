<?php

namespace App\Console\Commands;

use App\Jobs\ProcessProviderAutoDelivery;
use App\Models\Shoot;
use App\Services\Shoots\DeliveryPlanService;
use App\Services\Shoots\ProviderAutoDeliveryService;
use App\Services\Shoots\ProviderAutoDeliverySettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/** Recover missed webhook/scan handoffs without performing network work in the scheduler. */
class QueueProviderAutoDelivery extends Command
{
    public const CURSOR_KEY = 'provider:auto-delivery:recovery-cursor';

    protected $signature = 'provider:auto-deliver {--limit=100} {--shoot=} {--dry-run}';
    protected $description = 'Queue verification of outstanding automatic iGuide and CubiCasa delivery work';

    public function handle(ProviderAutoDeliverySettings $settings, ProviderAutoDeliveryService $provider, DeliveryPlanService $planner): int
    {
        if (! Schema::hasColumn('shoots', 'auto_delivery_enabled') || ! Schema::hasTable('shoot_provider_delivery_verifications')) {
            $this->info('Provider auto delivery is waiting for its database migration.');
            return self::SUCCESS;
        }
        $target = $this->option('shoot');
        if ($target !== null && (! ctype_digit((string) $target) || (int) $target < 1)) {
            $this->error('The shoot option must be a positive shoot ID.');
            return self::INVALID;
        }
        $limit = max(1, min(500, (int) $this->option('limit')));
        $suspended = ['on_hold', 'hold_on', 'cancelled', 'canceled', 'declined', 'requested', 'import_draft'];
        // Finish the read before queue writes; an open SQLite snapshot cannot be upgraded safely.
        $ids = Shoot::query()
            ->whereNotIn('status', $suspended)->whereNotIn('workflow_status', $suspended)
            ->where(fn ($query) => $query->where('auto_delivery_enabled', true)->orWhereNull('auto_delivery_enabled'))
            ->where(function ($query) {
                $query->where('status', '!=', 'delivered')->orWhere('workflow_status', '!=', 'delivered')
                    ->orWhere('delivery_status', '!=', 'delivered')
                    ->orWhereHas('serviceItems', fn ($items) => $items->where('is_deliverable', true)->whereNull('cancelled_at')
                        ->where('workflow_status', '!=', 'cancelled')->where('delivery_status', '!=', 'cancelled')
                        ->where(fn ($pending) => $pending->where('workflow_status', '!=', 'delivered')->orWhere('delivery_status', '!=', 'delivered')));
            })
            ->when($target !== null, fn ($query) => $query->whereKey((int) $target))
            ->orderBy('id')->pluck('id');

        // Unready older orders must not indefinitely hide newer missed handoffs.
        if ($target === null) {
            $cursor = max(0, (int) Cache::get(self::CURSOR_KEY, 0));
            $ids = $ids->filter(fn ($id) => $id > $cursor)
                ->concat($ids->filter(fn ($id) => $id <= $cursor))->values();
        }

        $eligible = [];
        foreach ($ids as $id) {
            $shoot = Shoot::find($id);
            if (! $shoot || $provider->blocked($shoot)) continue;
            $items = $settings->supportedItems($shoot)->filter(fn ($item) => ! $provider->blocked($shoot, $item));
            if ($items->isEmpty()) continue;
            $planner->forgetReleasedFiles($shoot);
            $components = collect($planner->plan($shoot, null)['components'])->where('resolved', false);
            $hasWork = $items->contains(function ($item) use ($components, $provider, $shoot) {
                foreach ($provider->parts($item) as $part) {
                    if ($components->contains('id', $item->id.':'.$part['key']) && $provider->scope($shoot, $item, $part['provider'])) return true;
                }
                return false;
            });
            if ($hasWork) $eligible[] = (int) $id;
            if (count($eligible) >= $limit) break;
        }
        if (! $this->option('dry-run')) {
            foreach ($eligible as $id) {
                try {
                    ProcessProviderAutoDelivery::dispatch($id);
                } finally {
                    // Advance after each attempt, including a failed queue handoff;
                    // that order is retried when the next rotation reaches it.
                    if ($target === null) Cache::forever(self::CURSOR_KEY, $id);
                }
            }
        }
        $this->info(($this->option('dry-run') ? 'Eligible' : 'Queued').' provider auto delivery shoot IDs: '.($eligible === [] ? 'none' : implode(', ', $eligible)).'.');
        return self::SUCCESS;
    }
}
