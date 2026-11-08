<?php

namespace App\Services\Messaging;

use App\Jobs\SendShootReadyEmailJob;
use App\Models\Shoot;
use App\Services\MailService;
use Illuminate\Support\Facades\{Bus, Cache, DB, Log};

/** Persisted in the delivery transaction; recovery can resume after any queue/process interruption. */
class ShootDeliveryNotificationOutbox
{
    private const TABLE = 'shoot_delivery_notification_outbox';

    public function record(Shoot $shoot, string $operationKey, bool $isFullOrderDelivery, array $serviceItemIds = []): int
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Record delivery notification intent inside the owning delivery transaction.');
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $serviceItemIds), fn ($id) => $id > 0)));
        sort($ids);
        if (! $isFullOrderDelivery && $ids === []) {
            throw new \InvalidArgumentException('A partial delivery notification requires the exact finalized service IDs.');
        }
        if ($operationKey === '' || strlen($operationKey) > 100) {
            throw new \InvalidArgumentException('A delivery notification requires a stable operation key of at most 100 characters.');
        }
        $cycle = app(ShootDeliveryCycle::class)->key($shoot);
        $identity = hash('sha256', json_encode([$shoot->id, $cycle, $operationKey, $isFullOrderDelivery, $ids], JSON_THROW_ON_ERROR));
        DB::table(self::TABLE)->insertOrIgnore([
            'shoot_id' => $shoot->id, 'deduplication_key' => $identity,
            'operation_key' => $operationKey, 'cycle_key' => $cycle,
            'is_full_order_delivery' => $isFullOrderDelivery,
            'service_item_ids' => json_encode($ids, JSON_THROW_ON_ERROR),
            'status' => 'pending', 'attempt_count' => 0, 'available_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $id = (int) DB::table(self::TABLE)->where('deduplication_key', $identity)->value('id');
        DB::afterCommit(function () use ($id): void {
            try {
                $this->dispatch($id);
            } catch (\Throwable $error) {
                // The release is already committed. A database/queue outage must
                // leave its durable intent for recovery, not report a false rollback.
                Log::error('Delivery notification handoff will be recovered.', ['outbox_id' => $id, 'error' => $error->getMessage()]);
            }
        });

        return $id;
    }

    public function dispatch(int $id): bool
    {
        $pending = DB::table(self::TABLE)->where('id', $id)->where('status', 'pending');
        $row = $pending->first();
        if (! $row) return false;
        $ids = json_decode($row->service_item_ids, true, 512, JSON_THROW_ON_ERROR);
        // A queue backlog or lost worker never removes the durable pending intent.
        $pending->update(['available_at' => now()->addMinutes(3), 'updated_at' => now()]);
        try {
            Bus::dispatch(new SendShootReadyEmailJob((int) $row->shoot_id,
                count($ids) === 1 ? (int) $ids[0] : null, (bool) $row->is_full_order_delivery,
                (bool) $row->is_full_order_delivery, $row->cycle_key, $ids, $id));

            return true;
        } catch (\Throwable $error) {
            DB::table(self::TABLE)->where('id', $id)->where('status', 'pending')->update([
                'last_error' => substr($error->getMessage(), 0, 2000), 'available_at' => now()->addMinute(), 'updated_at' => now(),
            ]);
            Log::error('Delivery notification handoff failed; the durable intent will be recovered.', ['outbox_id' => $id, 'error' => $error->getMessage()]);

            return false;
        }
    }

    public function dispatchPending(int $limit = 100): int
    {
        $ids = DB::table(self::TABLE)->where('status', 'pending')
            ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->orderBy('id')->limit(max(1, min(500, $limit)))->pluck('id');
        $count = 0;
        foreach ($ids as $id) if ($this->dispatch((int) $id)) $count++;

        return $count;
    }

    public function process(int $id): void
    {
        $lock = Cache::lock('shoot:delivery-notification-outbox:'.$id, 180);
        if (! $lock->get()) return;
        try {
            $row = DB::table(self::TABLE)->where('id', $id)->first();
            if (! $row || $row->status !== 'pending') return;
            $shoot = Shoot::find($row->shoot_id);
            $cycle = app(ShootDeliveryCycle::class);
            if (! $shoot || $row->cycle_key !== $cycle->key($shoot)
                || ($row->is_full_order_delivery && ! $cycle->isFullDelivery($shoot))) {
                DB::table(self::TABLE)->where('id', $id)->update([
                    'status' => 'obsolete', 'completed_at' => now(), 'updated_at' => now(),
                    'last_error' => 'The notification no longer matches the current delivery cycle/state.',
                ]);
                return;
            }
            DB::table(self::TABLE)->where('id', $id)->increment('attempt_count', 1, [
                'attempted_at' => now(), 'available_at' => now()->addMinutes(5), 'updated_at' => now(),
            ]);
            try {
                $job = new SendShootReadyEmailJob((int) $row->shoot_id, null, (bool) $row->is_full_order_delivery,
                    (bool) $row->is_full_order_delivery, $row->cycle_key, json_decode($row->service_item_ids, true, 512, JSON_THROW_ON_ERROR));
                $job->handle(app(MailService::class), app(AutomationService::class));
                DB::table(self::TABLE)->where('id', $id)->update([
                    'status' => 'completed', 'completed_at' => now(), 'last_error' => null, 'updated_at' => now(),
                ]);
            } catch (\Throwable $error) {
                DB::table(self::TABLE)->where('id', $id)->where('status', 'pending')->update([
                    'last_error' => substr($error->getMessage(), 0, 2000),
                    'available_at' => now()->addSeconds(min(1800, 30 * (2 ** min(6, (int) $row->attempt_count)))), 'updated_at' => now(),
                ]);
                throw $error;
            }
        } finally {
            $lock->release();
        }
    }
}
