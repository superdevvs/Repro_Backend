<?php

namespace App\Services\ListingStudio;

use App\Contracts\ListingStudio\ListingStudioFulfillmentDispatcher;
use App\Contracts\ListingStudio\ListingStudioRefundDispatcher;
use App\Models\ListingStudioOutbox;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ListingStudioOutboxService
{
    public function dispatch(int $limit = 100): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException('Outbox dispatch cannot run inside a financial transaction.');
        }
        $result = ['dispatched' => 0, 'deferred' => 0];
        for ($i = 0; $i < min(500, max(1, $limit)); $i++) {
            $event = $this->write(function () {
                $row = ListingStudioOutbox::where(function ($query) {
                    $query->where(fn ($ready) => $ready->where('status', 'pending')->where('available_at', '<=', now()))
                        ->orWhere(fn ($stale) => $stale->where('status', 'dispatching')->where('reserved_at', '<=', now()->subMinutes(5)));
                })->orderBy('available_at')->orderBy('id')->first();
                if (! $row) {
                    return null;
                }
                $row->update(['status' => 'dispatching', 'attempts' => $row->attempts + 1,
                    'reserved_at' => now(), 'lease_token' => (string) Str::uuid()]);

                return $row;
            });
            if (! $event) {
                break;
            }
            try {
                if ($event->type === 'refund_order') {
                    app(ListingStudioRefundDispatcher::class)->dispatch($event);
                } elseif (in_array($event->type, ['process_item', 'create_revision_task', 'finalize_revision_delivery'], true)) {
                    app(ListingStudioFulfillmentDispatcher::class)->dispatch($event);
                } else {
                    $this->finish($event, ['status' => 'needs_attention', 'error_code' => 'unsupported_event_type']);
                    $result['deferred']++;

                    continue;
                }
                $finished = $this->finish($event, ['status' => 'dispatched', 'dispatched_at' => now(), 'error_code' => null]);
                $result[$finished ? 'dispatched' : 'deferred']++;
            } catch (\Throwable) {
                // A queue/Stripe response may have been lost. Reuse the same event identity.
                $this->finish($event, ['status' => 'pending', 'available_at' => now()->addSeconds(min(3600, 15 * (2 ** min(8, $event->attempts)))),
                    'error_code' => 'dispatch_unavailable']);
                $result['deferred']++;
                try {
                    Log::error('Listing Studio durable dispatch was deferred.');
                } catch (\Throwable) {
                }
            }
        }

        return $result;
    }

    private function finish(ListingStudioOutbox $event, array $changes): bool
    {
        return $this->write(fn () => ListingStudioOutbox::whereKey($event->id)->where('lease_token', $event->lease_token)
            ->update($changes + ['lease_token' => null, 'reserved_at' => null, 'updated_at' => now()])) === 1;
    }

    private function write(callable $callback): mixed
    {
        return LockedWrite::run(fn () => DB::transaction($callback), 'listing-studio-outbox');
    }
}
