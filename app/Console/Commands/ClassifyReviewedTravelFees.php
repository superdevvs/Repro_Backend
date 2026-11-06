<?php

namespace App\Console\Commands;

use App\Models\{ClientDeliveryNotification, Shoot, ShootActivityLog, User};
use App\Services\Messaging\{ShootDeliveryCycle, ShootDeliveryNotificationOutbox};
use App\Services\Shoots\{DeliveryPlanService, ReviewedTravelFeeCatalog, ShootActiveWorkLifecycle, ShootListingService};
use App\Support\{EditingDispatchWriteLock, LockedWrite};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB, Schema};
use PDO;
use RuntimeException;
use Throwable;

/** Exact reviewed financial correction; only the current incident may complete an order. */
class ClassifyReviewedTravelFees extends Command
{
    protected $signature = 'shoots:classify-travel-fees
        {--actor= : Existing administrator recording this correction}
        {--expected-plan= : Exact plan_hash from the reviewed dry run}
        {--backup-path= : Fresh independent SQLite safety backup, required for apply}
        {--apply : Apply the reviewed correction; otherwise only show its plan}';

    protected $description = 'Classify reviewed travel charges without changing compensation or historical delivery';

    private const ACTION = 'reviewed_travel_fees_classified';
    private const COMPLETION_SHOOT = 5946;

    public function handle(DeliveryPlanService $delivery): int
    {
        try {
            if (! $this->option('apply')) {
                $state = $this->inspect($delivery);
                $this->line(json_encode($this->plan($state), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                return self::SUCCESS;
            }
            $actor = User::find($this->option('actor'));
            $this->require($actor && in_array($actor->role, ['admin', 'superadmin', 'super_admin'], true)
                && $delivery->canFinalize($actor), 'Apply requires an existing authorized administrator.');
            $hash = (string) $this->option('expected-plan');
            $this->require((bool) preg_match('/^[a-f0-9]{64}$/D', $hash), 'Apply requires the reviewed --expected-plan hash.');
            $operation = 'travel-fees:'.$hash;
            if ($this->applied($operation)) {
                $this->info('Already classified; no changes.');
                return self::SUCCESS;
            }
            $state = $this->inspect($delivery);
            $this->require(hash_equals($this->fingerprint($state['snapshot']), $hash), 'The reviewed plan changed; run and review a new dry run.');
            $backup = $this->backup($state['snapshot']);
            $changed = LockedWrite::run(fn () => DB::transaction(function () use ($delivery, $actor, $hash, $operation, $backup): bool {
                // Acquire the writer before reading the fresh guarded snapshot.
                EditingDispatchWriteLock::acquire(self::COMPLETION_SHOOT);
                if ($this->applied($operation)) return false;
                $freshActor = User::find($actor->id);
                $this->require($freshActor && in_array($freshActor->role, ['admin', 'superadmin', 'super_admin'], true)
                    && $delivery->canFinalize($freshActor), 'The administrator is no longer authorized.');
                $state = $this->inspect($delivery);
                $this->require(hash_equals($this->fingerprint($state['snapshot']), $hash), 'The reviewed plan changed during correction.');
                $this->matchBackup($backup, $state['snapshot']);
                if ($state['change_count'] === 0 && ! $state['complete']) return false;
                foreach ($state['fees'] as $fee) {
                    $basis = json_decode($fee->payout_snapshot ?: '{}', true, 512, JSON_THROW_ON_ERROR);
                    $basis['delivery_requirement'] = 'financial_only';
                    if (! $fee->is_deliverable && $fee->delivery_requirements === '[]'
                        && data_get(json_decode($fee->payout_snapshot ?: '{}', true), 'delivery_requirement') === 'financial_only') continue;
                    // Bypass model capture/repricing and execution observers. All
                    // amounts, payees, statuses and timestamps stay byte-exact.
                    DB::table('shoot_service')->where('id', $fee->id)->update(['is_deliverable' => false,
                        'payout_snapshot' => json_encode($basis, JSON_THROW_ON_ERROR), 'delivery_requirements' => '[]']);
                }
                $delivery->forgetReleasedFiles();
                if ($state['complete']) {
                    $shoot = Shoot::findOrFail(self::COMPLETION_SHOOT);
                    $this->assertCompletionScope($shoot, $delivery);
                    Shoot::withoutEvents(function () use ($shoot, $actor): void {
                        $rollup = $shoot->syncServiceItemRollups();
                        $this->require($rollup['delivery_status'] === 'delivered', 'Required work is still unresolved.');
                        $shoot->updateWorkflowStatus(Shoot::STATUS_DELIVERED, $actor->id);
                    });
                    $shoot->refresh();
                    $this->require(app(ShootDeliveryCycle::class)->isFullDelivery($shoot), 'The reviewed order did not reach full delivery.');
                    if ($shoot->client_id) ClientDeliveryNotification::firstOrCreate(
                        ['user_id' => $shoot->client_id, 'delivery_event_key' => $operation],
                        ['shoot_id' => $shoot->id, 'delivered_at' => now()]);
                    app(ShootDeliveryNotificationOutbox::class)->record($shoot, $operation, true);
                }
                $this->assertPreserved($state['snapshot'], $this->snapshot($state['shoot_ids']), $state['complete']);
                foreach ($state['fees']->groupBy('shoot_id') as $shootId => $fees) {
                    ShootActivityLog::create(['shoot_id' => $shootId, 'user_id' => $actor->id, 'action' => self::ACTION,
                        'description' => 'Reviewed travel charges classified as financial items; prices and compensation preserved.',
                        'metadata' => ['operation_key' => $operation, 'fee_item_ids' => $fees->pluck('id')->all(),
                            'completed_current_order' => $state['complete'] && (int) $shootId === self::COMPLETION_SHOOT]]);
                }
                return true;
            }), 'classify-reviewed-travel-fees');
            ShootListingService::flushCachedListings();
            $this->info($changed ? 'Reviewed travel charges classified; only the eligible current order was reconciled.' : 'Already classified; no changes.');
            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error('Travel fee correction refused: '.$error->getMessage());
            return self::FAILURE;
        }
    }

    private function applied(string $operation): bool
    {
        return ShootActivityLog::where('action', self::ACTION)->where('metadata->operation_key', $operation)->exists();
    }

    private function inspect(DeliveryPlanService $delivery): array
    {
        $catalog = DB::table('services')->whereIn('id', array_keys(ReviewedTravelFeeCatalog::PRODUCTS))->get();
        foreach ($catalog as $product) {
            ReviewedTravelFeeCatalog::assertIdentity($product);
            $this->require((bool) $product->financial_only, 'Apply the reviewed catalog migration before classifying bookings.');
        }
        $fees = DB::table('shoot_service')->whereIn('service_id', $catalog->pluck('id'))->orderBy('id')->get();
        $this->require($fees->isNotEmpty(), 'No reviewed travel charges were found.');
        foreach ($fees as $fee) {
            $requirements = json_decode($fee->delivery_requirements ?: '[]', true, 512, JSON_THROW_ON_ERROR);
            $this->require(is_array($requirements) && collect($requirements)->every(fn ($part) => in_array($part['kind'] ?? '', ['legacy', 'external'], true)), 'A travel item contains actual work requirements; review it separately.');
            $this->require(! $fee->editor_id && ! $fee->video_editor_id, 'A travel item has an editing assignment; review it separately.');
            foreach (['shoot_files', 'shoot_file_versions', 'shoot_delivery_receipts'] as $table) {
                if (Schema::hasColumn($table, 'shoot_service_id')) $this->require(! DB::table($table)->where('shoot_service_id', $fee->id)->exists(), 'A travel item owns media or a delivery receipt; review it separately.');
            }
        }
        $ids = $fees->pluck('shoot_id')->unique()->sort()->values()->all();
        $shoot = in_array(self::COMPLETION_SHOOT, $ids, true) ? Shoot::findOrFail(self::COMPLETION_SHOOT) : null;
        $complete = $shoot && ! app(ShootDeliveryCycle::class)->isFullDelivery($shoot);
        if ($complete) $this->assertCompletionScope($shoot, $delivery, $fees->pluck('id')->all());
        $changes = $fees->filter(fn ($fee) => $fee->is_deliverable || $fee->delivery_requirements !== '[]'
            || data_get(json_decode($fee->payout_snapshot ?: '{}', true, 512, JSON_THROW_ON_ERROR), 'delivery_requirement') !== 'financial_only')->count();
        return ['fees' => $fees, 'shoot_ids' => $ids, 'change_count' => $changes, 'complete' => (bool) $complete, 'snapshot' => $this->snapshot($ids)];
    }

    private function assertCompletionScope(Shoot $shoot, DeliveryPlanService $delivery, array $excludedFeeIds = []): void
    {
        $delivery->forgetReleasedFiles($shoot);
        $this->require((int) $shoot->id === self::COMPLETION_SHOOT && ! $shoot->isImportDraft() && ! $shoot->isInternalTestShoot()
            && ! $shoot->isComplimentaryReshoot() && ! $shoot->reshoot_of_shoot_id && ! $shoot->root_shoot_id
            && ! $shoot->admin_verified_at && ! $shoot->completed_at && ! $shoot->active_work_started_at
            && ! $shoot->active_work_cycle_key && ! $shoot->active_work_completed_at
            && in_array($shoot->status, ['ready', 'editing', 'review'], true) && in_array($shoot->workflow_status, ['ready', 'editing', 'review'], true)
            && in_array($shoot->delivery_status, ['partially_delivered', 'delivered'], true), 'The current shoot is outside the reviewed initial partial-delivery scope.');
        $this->require(! DB::table('shoot_delivery_notification_outbox')->where('shoot_id', $shoot->id)->where('is_full_order_delivery', true)->exists()
            && ! ClientDeliveryNotification::where('shoot_id', $shoot->id)->exists(), 'The current shoot already has full-delivery notification history; review it separately.');
        $parts = collect($delivery->plan($shoot, null)['components'])->reject(fn ($part) => in_array($part['shoot_service_id'], $excludedFeeIds, true));
        $this->require($parts->isNotEmpty() && $parts->every(fn ($part) => $part['resolved'])
            && app(DeliveryPlanService::class)->hasReleasedComponents($shoot), 'Real required work remains pending or its approval changed.');
    }

    private function snapshot(array $ids): array
    {
        $snapshot = [];
        foreach (['services', 'shoots', 'shoot_service', 'shoot_files', 'shoot_file_versions', 'shoot_delivery_receipts', 'invoices', 'payments'] as $table) {
            if (! Schema::hasTable($table)) continue;
            if ($table === 'services') $query = DB::table($table)->whereIn('id', array_keys(ReviewedTravelFeeCatalog::PRODUCTS));
            elseif ($table === 'shoots') $query = DB::table($table)->whereIn('id', $ids);
            elseif (Schema::hasColumn($table, 'shoot_id')) $query = DB::table($table)->whereIn('shoot_id', $ids);
            else continue;
            $snapshot[$table] = $query->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }
        $snapshot['invoice_items'] = DB::table('invoice_items')->whereIn('invoice_id', array_column($snapshot['invoices'] ?? [], 'id'))->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        return $snapshot;
    }

    private function plan(array $state): array
    {
        return ['apply' => false, 'plan_hash' => $this->fingerprint($state['snapshot']), 'change_count' => $state['change_count'], 'fee_item_ids' => $state['fees']->pluck('id')->all(),
            'items' => $state['fees']->map(fn ($fee) => ['id' => $fee->id, 'shoot_id' => $fee->shoot_id, 'service_id' => $fee->service_id,
                'workflow_status' => $fee->workflow_status, 'delivery_status' => $fee->delivery_status, 'is_deliverable' => (bool) $fee->is_deliverable,
                'proposed_delivery_requirement' => 'financial_only'])->all(),
            'completion_shoot_id' => $state['complete'] ? self::COMPLETION_SHOOT : null,
            'notification' => $state['complete'] ? 'One normal full-order notification intent for the reviewed current shoot; no historical notices.' : 'No delivery notification.',
            'preserve' => ['charges, tax, invoices, payments and compensation', 'photographer/payee assignments', 'all existing item progress and delivery timestamps', 'original appointments, media and receipts']];
    }

    private function fingerprint(array $snapshot): string { return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)); }

    private function backup(array $snapshot): string
    {
        $path = realpath((string) $this->option('backup-path'));
        $this->require($path && is_file($path) && is_readable($path) && $path !== realpath((string) config('database.connections.sqlite.database')), 'Apply requires an independent readable --backup-path SQLite backup.');
        $livePath = (string) config('database.connections.sqlite.database');
        if (is_file($livePath)) {
            $live = stat($livePath); $copy = stat($path);
            $this->require($live['dev'] !== $copy['dev'] || $live['ino'] !== $copy['ino'], 'The backup must not be a hardlink to the live database.');
        }
        $age = time() - filemtime($path);
        $this->require($age >= -5 && $age <= 900 && filesize($path) >= 4096 && file_get_contents($path, false, null, 0, 16) === "SQLite format 3\0", 'The SQLite backup must be complete and no more than 15 minutes old.');
        $this->matchBackup($path, $snapshot, true);
        return $path;
    }

    private function matchBackup(string $path, array $snapshot, bool $integrity = false): void
    {
        $pdo = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
        $pdo->exec('PRAGMA query_only=ON');
        if ($integrity) $this->require($pdo->query('PRAGMA quick_check')->fetchColumn() === 'ok', 'The backup failed its SQLite integrity check.');
        foreach ($snapshot as $table => $rows) foreach ($rows as $row) {
            $statement = $pdo->prepare('SELECT * FROM "'.$table.'" WHERE id = ?'); $statement->execute([$row['id']]);
            $this->require($statement->fetch(PDO::FETCH_ASSOC) == $row, 'The backup does not match the reviewed '.$table.' records.');
        }
    }

    private function assertPreserved(array $before, array $after, bool $complete): void
    {
        foreach ($before as $table => $rows) foreach ($rows as $index => $row) {
            $changed = $after[$table][$index] ?? [];
            if ($table === 'shoot_service' && isset(ReviewedTravelFeeCatalog::PRODUCTS[(int) $row['service_id']])) {
                $basis = json_decode($row['payout_snapshot'] ?: '{}', true, 512, JSON_THROW_ON_ERROR);
                if ($row['is_deliverable'] || $row['delivery_requirements'] !== '[]' || ($basis['delivery_requirement'] ?? null) !== 'financial_only') {
                    $basis['delivery_requirement'] = 'financial_only';
                    $row['payout_snapshot'] = json_encode($basis, JSON_THROW_ON_ERROR); $row['is_deliverable'] = 0; $row['delivery_requirements'] = '[]';
                }
            }
            if ($complete && $table === 'shoots' && (int) $row['id'] === self::COMPLETION_SHOOT) {
                $allowed = array_flip(['status', 'workflow_status', 'delivery_status', 'admin_verified_at', 'completed_at', 'editing_completed_at', 'verified_by', 'updated_at']);
                $row = array_diff_key($row, $allowed); $changed = array_diff_key($changed, $allowed);
            }
            $this->require($row === $changed, 'The correction changed preserved '.$table.' fields.');
        }
    }

    private function require(bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); }
}
