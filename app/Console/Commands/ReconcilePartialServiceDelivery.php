<?php

namespace App\Console\Commands;

use App\Models\{Shoot, ShootActivityLog, ShootFile, ShootService, User};
use App\Services\Schedule\ScheduleDateScopeService;
use App\Services\ShootActivityLogger;
use App\Services\Shoots\{ShootActiveWorkLifecycle, ShootListingService};
use App\Support\LockedWrite;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{Cache, DB};
use PDO;
use RuntimeException;
use Throwable;

/** A reviewed, exact-file repair for a whole-order delivery across separate visits. */
class ReconcilePartialServiceDelivery extends Command
{
    protected $signature = 'shoots:reconcile-partial-delivery
        {shoot : Exact shoot ID}
        {--source-service= : Booked service incorrectly marked delivered}
        {--target-service= : Delivered booked service that owns the reviewed images}
        {--file-ids= : Complete, comma-separated reviewed file ID set on the source service}
        {--expected-source-scheduled-at= : Exact original source appointment, YYYY-MM-DD HH:MM:SS}
        {--finalization-event= : Exact whole-order shoot_finalized_delivered activity ID}
        {--actor= : Existing administrator recording the repair}
        {--backup-path= : Fresh SQLite safety backup, required for apply}
        {--apply : Apply the reviewed reconciliation; otherwise only print the proposed changes}';

    protected $description = 'Reconcile reviewed photo ownership and an incorrectly delivered future visit, preserving files and billing';

    private const ACTION = 'partial_service_delivery_reconciled';

    public function handle(ShootActiveWorkLifecycle $lifecycle, ShootActivityLogger $logger): int
    {
        try {
            $input = $this->parseInput();
            $alreadyApplied = $this->alreadyApplied($input);
            if ($alreadyApplied) {
                return $this->finishAlreadyApplied($input);
            }
            $state = $this->inspect($input);
            $this->line(json_encode([
                'apply' => (bool) $this->option('apply'),
                'shoot_id' => $input['shoot'],
                'source_service_id' => $input['source'],
                'target_service_id' => $input['target'],
                'file_ids' => $input['files'],
                'source_appointment' => $input['scheduled_at'],
                'proposed_shoot_status' => 'active',
                'proposed_source_status' => 'scheduled',
                'preserve' => ['original appointment and delivery dates', 'file bytes and visibility', 'pricing, invoices and payment state'],
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            if (! $this->option('apply')) return self::SUCCESS;

            $backup = $this->validatedBackup($state);
            $result = LockedWrite::run(fn () => DB::transaction(function () use ($input, $backup, $lifecycle, $logger): array {
                // Every retry starts with a fresh snapshot; an intervening upload or edit refuses the repair.
                if ($this->alreadyApplied($input)) return ['changed' => false];
                $state = $this->inspect($input);
                $this->assertBackupMatches($backup, $state['snapshot']);
                /** @var Shoot $shoot */
                $shoot = $state['shoot'];
                /** @var ShootService $source */
                $source = $state['source'];
                /** @var ShootService $target */
                $target = $state['target'];
                $before = $state['snapshot'];

                // Use a query builder so even the files' updated_at/metadata remain byte-for-byte unchanged.
                $moved = DB::table('shoot_files')->where('shoot_id', $shoot->id)
                    ->where('shoot_service_id', $source->id)->whereIn('id', $input['files'])
                    ->update(['shoot_service_id' => $target->id]);
                $this->require($moved === count($input['files']), 'The reviewed file set changed during reconciliation.');
                foreach (['editor_id', 'video_editor_id', 'editing_completed_at', 'video_editing_completed_at'] as $field) {
                    if ($target->{$field} === null && $source->{$field} !== null) $target->{$field} = $source->{$field};
                }
                if ($target->isDirty()) $target->saveQuietly();
                $source->forceFill([
                    'workflow_status' => ShootService::WORKFLOW_SCHEDULED,
                    'delivery_status' => ShootService::DELIVERY_NOT_STARTED,
                    'ready_at' => null,
                    'delivered_at' => null,
                    'editing_completed_at' => null,
                    'video_editing_completed_at' => null,
                ]);
                // A migrated aggregate snapshot cannot represent the new photo
                // execution. Promote only this reviewed reset after its wrongly
                // owned historical files have moved to the delivered target.
                app(\App\Services\Shoots\DeliveryComponentRequirements::class)->reopenIfRequested($source, $shoot);
                $source->saveQuietly();

                // An operational repair must not invoke provider, payout, archive, or notification observers.
                $began = Shoot::withoutEvents(fn () => $lifecycle->begin($shoot->fresh()));
                $this->require($began, 'The active-work lifecycle did not accept the outstanding service.');
                $after = $this->snapshot($input);
                $this->assertPreserved($before, $after, $input);
                ShootActivityLog::withoutEvents(fn () => $logger->log($shoot->fresh(), self::ACTION, [
                    'operation_key' => $input['operation_key'],
                    'finalization_event_id' => $input['event'],
                    'source_service_id' => $source->id,
                    'target_service_id' => $target->id,
                    'file_ids' => $input['files'],
                    'expected_source_scheduled_at' => $input['scheduled_at'],
                    'backup_file' => basename($backup),
                    'before' => $this->auditSnapshot($before),
                    'after' => $this->auditSnapshot($after),
                    'suppress_notifications' => true,
                ], $state['actor']));
                return ['changed' => true, 'shoot' => $shoot->fresh(), 'source' => $source->fresh(), 'target' => $target->fresh()];
            }), 'reconcile-partial-service-delivery');

            if ($result['changed']) {
                try {
                    $this->invalidateCaches($result['shoot'], $result['source'], $result['target']);
                } catch (Throwable $exception) {
                    $this->error('Reconciliation committed, but cache invalidation failed: '.$exception->getMessage());
                    return self::FAILURE;
                }
                $this->info('Reconciled: reviewed images retained, future service scheduled, shoot Active.');
            } else {
                return $this->finishAlreadyApplied($input);
            }
            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Reconciliation refused: '.$exception->getMessage());
            return self::FAILURE;
        }
    }

    private function parseInput(): array
    {
        $input = [
            'shoot' => $this->positiveId($this->argument('shoot'), 'shoot'),
            'source' => $this->positiveId($this->option('source-service'), 'source-service'),
            'target' => $this->positiveId($this->option('target-service'), 'target-service'),
            'event' => $this->positiveId($this->option('finalization-event'), 'finalization-event'),
            'actor' => $this->positiveId($this->option('actor'), 'actor'),
        ];
        $this->require($input['source'] !== $input['target'], 'Source and target services must differ.');
        $ids = explode(',', (string) $this->option('file-ids'));
        $input['files'] = array_map(fn ($id) => $this->positiveId(trim($id), 'file-ids'), $ids);
        $this->require(count($input['files']) <= 500 && count(array_unique($input['files'])) === count($input['files']), 'Provide a unique, complete file set of at most 500 IDs.');
        sort($input['files'], SORT_NUMERIC);
        $at = trim((string) $this->option('expected-source-scheduled-at'));
        $this->require((bool) preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $at), 'Provide the exact expected source appointment as YYYY-MM-DD HH:MM:SS.');
        $this->require(Carbon::parse($at)->format('Y-m-d H:i:s') === $at, 'The expected source appointment is invalid.');
        $input['scheduled_at'] = $at;
        $input['operation_key'] = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
        return $input;
    }

    private function positiveId(mixed $value, string $field): int
    {
        $this->require(is_scalar($value) && preg_match('/^[1-9]\d*$/', (string) $value) && (int) $value > 0, "Provide an exact positive $field ID.");
        return (int) $value;
    }

    private function alreadyApplied(array $input): bool
    {
        $existing = ShootActivityLog::where('shoot_id', $input['shoot'])->where('action', self::ACTION)->get()
            ->first(fn ($event) => ($event->metadata['operation_key'] ?? null) === $input['operation_key']);
        if (! $existing) return false;
        $files = DB::table('shoot_files')->whereIn('id', $input['files'])->orderBy('id')->get();
        $this->require($files->count() === count($input['files']) && $files->every(fn ($file) => (int) $file->shoot_id === $input['shoot'] && (int) $file->shoot_service_id === $input['target']), 'A previously reconciled file was changed; review it manually.');
        return true;
    }

    private function inspect(array $input): array
    {
        $shoot = Shoot::findOrFail($input['shoot']);
        $source = $shoot->serviceItems()->with('service')->findOrFail($input['source']);
        $target = $shoot->serviceItems()->with('service')->findOrFail($input['target']);
        $actor = User::findOrFail($input['actor']);
        $this->require(in_array(strtolower((string) $actor->role), ['admin', 'superadmin', 'super_admin'], true), 'The actor must be an existing administrator.');
        $this->require($shoot->status === 'delivered' && $shoot->workflow_status === 'delivered' && $shoot->admin_verified_at, 'The shoot is no longer in the expected delivered state.');
        $this->require(! $shoot->photographer_paid_at && ! $shoot->sales_rep_paid_at, 'Paid work requires separate accounting reconciliation.');
        $this->require((int) $source->shoot_unit_id === (int) $target->shoot_unit_id, 'Source and target must belong to the same unit.');
        $this->require($source->service?->supportsPhotoIntake() && ! $source->service->supportsVideoIntake()
            && $target->service?->supportsPhotoIntake() && ! $target->service->supportsVideoIntake(), 'This repair supports reviewed photo-only services.');
        foreach ([$source, $target] as $item) {
            $this->require($item->is_deliverable && $item->workflow_status === 'delivered' && $item->delivery_status === 'delivered' && $item->delivered_at && ! $item->cancelled_at, 'Both service lines must still match the expected delivered state.');
        }
        $event = ShootActivityLog::where('shoot_id', $shoot->id)->findOrFail($input['event']);
        $this->require($event->action === 'shoot_finalized_delivered' && ($event->metadata['full_order_delivery'] ?? false) === true && array_key_exists('shoot_service_id', $event->metadata) && $event->metadata['shoot_service_id'] === null, 'The specified activity is not a whole-order delivery event.');
        $this->require($source->scheduled_at?->format('Y-m-d H:i:s') === $input['scheduled_at'] && $source->scheduled_at->gt($event->created_at), 'The source appointment changed or was not after the incorrect delivery.');
        $this->require($target->scheduled_at && $target->scheduled_at->lte($event->created_at), 'The target must be the earlier delivered visit.');
        $this->require(abs($shoot->admin_verified_at->diffInSeconds($event->created_at, false)) <= 300 && $source->delivered_at->eq($shoot->admin_verified_at) && $target->delivered_at->eq($shoot->admin_verified_at), 'Delivery timestamps no longer match the reviewed whole-order event.');
        $this->require(! DB::table('shoot_compensations')->where('shoot_service_id', $source->id)->exists(), 'The source has compensation records; review accounting before changing completion.');
        $this->require(! DB::table('shoot_editing_dispatch_items')->where('shoot_service_id', $source->id)->exists(), 'The source has scoped editing work; reconcile that work separately.');
        $files = $shoot->files()->where('shoot_service_id', $source->id)->orderBy('id')->get();
        $this->require($files->pluck('id')->map(fn ($id) => (int) $id)->all() === $input['files'], 'The complete source file set does not match the reviewed IDs.');
        $this->require($files->every(fn (ShootFile $file) => $file->workflow_stage === 'verified' && $file->verified_at && $file->scan_status === 'clean' && $file->media_type === 'edited' && str_starts_with((string) ($file->mime_type ?: $file->file_type), 'image/') && ! $file->is_hidden && ! $file->is_extra), 'Every reviewed file must remain a visible, clean, verified edited image.');
        return compact('shoot', 'source', 'target', 'actor') + ['snapshot' => $this->snapshot($input)];
    }

    private function snapshot(array $input): array
    {
        return [
            'shoot' => (array) DB::table('shoots')->where('id', $input['shoot'])->first(),
            'services' => DB::table('shoot_service')->whereIn('id', [$input['source'], $input['target']])->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'files' => DB::table('shoot_files')->whereIn('id', $input['files'])->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'invoices' => DB::table('invoices')->where('shoot_id', $input['shoot'])->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'invoice_items' => DB::table('invoice_items')->where('shoot_id', $input['shoot'])->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        ];
    }

    private function validatedBackup(array $state): string
    {
        $path = realpath((string) $this->option('backup-path'));
        $this->require($path !== false && is_file($path) && is_readable($path), 'Apply requires a readable --backup-path SQLite safety backup.');
        $database = config('database.connections.sqlite.database');
        $this->require($path !== realpath((string) $database), 'The backup must not be the live database.');
        $age = time() - (int) filemtime($path);
        $this->require($age >= -5 && $age <= 900 && filesize($path) >= 4096, 'The safety backup must be complete and no more than 15 minutes old.');
        $this->require(file_get_contents($path, false, null, 0, 16) === "SQLite format 3\0", 'The safety backup is not a SQLite database.');
        $this->assertBackupMatches($path, $state['snapshot'], true);
        return $path;
    }

    private function assertBackupMatches(string $path, array $snapshot, bool $checkIntegrity = false): void
    {
        $pdo = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
        $pdo->exec('PRAGMA query_only=ON');
        if ($checkIntegrity) {
            $this->require($pdo->query('PRAGMA quick_check')->fetchColumn() === 'ok', 'The safety backup failed its SQLite integrity check.');
        }
        foreach (['shoot' => 'shoots', 'services' => 'shoot_service', 'files' => 'shoot_files', 'invoices' => 'invoices', 'invoice_items' => 'invoice_items'] as $key => $table) {
            $rows = $key === 'shoot' ? [$snapshot[$key]] : $snapshot[$key];
            foreach ($rows as $row) {
                $statement = $pdo->prepare("SELECT * FROM $table WHERE id = ?");
                $statement->execute([$row['id']]);
                $this->require($statement->fetch(PDO::FETCH_ASSOC) == $row, "The safety backup does not match the current $key records.");
            }
        }
    }

    private function assertPreserved(array $before, array $after, array $input): void
    {
        $allowedRoot = ['status', 'workflow_status', 'delivery_status', 'active_work_is_reopened', 'active_work_started_at', 'active_work_completed_at', 'active_work_at', 'active_work_date', 'active_work_time', 'updated_at'];
        $this->require(array_diff_key($before['shoot'], array_flip($allowedRoot)) === array_diff_key($after['shoot'], array_flip($allowedRoot)), 'The repair changed historical or financial shoot fields.');
        $this->require($after['shoot']['status'] === 'active' && $after['shoot']['workflow_status'] === 'active' && $after['shoot']['delivery_status'] === 'partially_delivered', 'The repaired shoot did not become Active with a retained delivery.');
        $this->require((bool) $after['shoot']['active_work_is_reopened'], 'The repaired cycle did not retain its prior delivery classification.');
        foreach ($before['files'] as $index => $file) {
            $expected = $file;
            $expected['shoot_service_id'] = $input['target'];
            $this->require($expected === $after['files'][$index], 'A reviewed file changed beyond its service association.');
        }
        foreach ($before['services'] as $index => $service) {
            $allowed = (int) $service['id'] === $input['source']
                ? ['workflow_status', 'delivery_status', 'ready_at', 'delivered_at', 'editing_completed_at', 'video_editing_completed_at', 'delivery_requirements', 'updated_at']
                : ['editor_id', 'video_editor_id', 'editing_completed_at', 'video_editing_completed_at', 'updated_at'];
            $this->require(array_diff_key($service, array_flip($allowed)) === array_diff_key($after['services'][$index], array_flip($allowed)), 'A service changed beyond the reviewed completion and editing fields.');
            if ((int) $service['id'] === $input['source']) {
                $requirements = json_decode($after['services'][$index]['delivery_requirements'] ?? 'null', true);
                $this->require(is_array($requirements) && count($requirements) === 1 && ($requirements[0]['key'] ?? null) === 'photo'
                    && ($requirements[0]['kind'] ?? null) === 'photo'
                    && str_starts_with($requirements[0]['execution_key'] ?? '', 'line:'.$input['source'].':')
                    && ! empty($requirements[0]['execution_started_at']), 'The reviewed photo service did not receive a separate execution requirement.');
            }
        }
        $this->require($before['invoices'] === $after['invoices'] && $before['invoice_items'] === $after['invoice_items'], 'Invoice records changed during reconciliation.');
    }

    /** Activity metadata can reach clients; never embed private payout snapshots or raw invoice records. */
    private function auditSnapshot(array $snapshot): array
    {
        $shootFields = ['id', 'status', 'workflow_status', 'delivery_status', 'scheduled_at', 'scheduled_date', 'admin_verified_at', 'completed_at', 'active_work_started_at', 'active_work_at', 'active_work_date', 'active_work_time'];
        $serviceFields = ['id', 'service_id', 'workflow_status', 'delivery_status', 'scheduled_at', 'ready_at', 'delivered_at', 'editing_completed_at', 'video_editing_completed_at', 'editor_id', 'video_editor_id'];
        return [
            'shoot' => array_intersect_key($snapshot['shoot'], array_flip($shootFields)),
            'services' => array_map(fn ($row) => array_intersect_key($row, array_flip($serviceFields)), $snapshot['services']),
            'files' => array_map(fn ($row) => array_intersect_key($row, array_flip(['id', 'shoot_service_id', 'workflow_stage', 'verified_at'])), $snapshot['files']),
            'billing_hash' => hash('sha256', json_encode([
                array_intersect_key($snapshot['shoot'], array_flip(['base_quote', 'total_quote', 'payment_status', 'bypass_paywall'])),
                array_map(fn ($row) => array_intersect_key($row, array_flip(['id', 'price', 'quantity', 'photographer_pay', 'payout_snapshot'])), $snapshot['services']),
                $snapshot['invoices'], $snapshot['invoice_items'],
            ], JSON_THROW_ON_ERROR)),
        ];
    }

    private function invalidateCaches(Shoot $shoot, ShootService $source, ShootService $target): void
    {
        ShootListingService::flushCachedListings();
        foreach (['raw', 'edited', 'all'] as $kind) Cache::forget("shoot_files_{$shoot->id}_{$kind}");
        $scope = app(ScheduleDateScopeService::class);
        $scope->invalidateDates([
            now()->toDateString(),
            $scope->localDateForShoot($shoot),
            $scope->localDateForScheduledAt($source->scheduled_at, $shoot->timezone),
            $scope->localDateForScheduledAt($target->scheduled_at, $shoot->timezone),
            $shoot->active_work_date,
        ]);
    }

    private function finishAlreadyApplied(array $input): int
    {
        try {
            if ($this->option('apply')) {
                $shoot = Shoot::findOrFail($input['shoot']);
                $this->invalidateCaches($shoot, $shoot->serviceItems()->findOrFail($input['source']), $shoot->serviceItems()->findOrFail($input['target']));
            }
        } catch (Throwable $exception) {
            $this->error('Already reconciled, but cache invalidation failed: '.$exception->getMessage());
            return self::FAILURE;
        }
        $this->info('Already reconciled; no changes.');
        return self::SUCCESS;
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) throw new RuntimeException($message);
    }
}
