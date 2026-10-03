<?php

namespace App\Services\Shoots;

use App\Exceptions\PublicApiException;
use App\Models\{Shoot, ShootFile, ShootUploadAttempt, ShootRawUploadBatch, User};
use App\Support\LockedWrite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, DB};
use Symfony\Component\HttpKernel\Exception\HttpException;

class ShootRawUploadBatchService
{
    public function __construct(
        private ShootAuthorizationSupport $authorization,
        private UploadIntakeResolver $intake,
        private BracketModeResolver $brackets,
    ) {}

    public function prepare(Shoot $shoot, User $actor, array $data): ShootRawUploadBatch
    {
        $lane = $data['upload_lane'] ?? 'photo';
        $scope = (int) ($data['service_id'] ?? 0);
        abort_unless($this->authorization->canUploadShootMedia($shoot, $actor, 'raw', $scope ?: null), 403);
        $items = $shoot->serviceItems()->with(['service', 'shoot'])->get();
        if (!$scope && $items->isNotEmpty()) {
            $eligible = $items->filter(fn ($item) => $this->intake->supportsLane($item, $lane)
                && $this->authorization->canUploadShootMedia($shoot, $actor, 'raw', $item->id));
            abort_unless($eligible->count() === 1, 422, 'Select one assigned service item for this batch.');
            $scope = (int) $eligible->first()->id;
        }
        $item = $scope ? $items->firstWhere('id', $scope) : null;
        abort_if($scope && !$item, 422, 'Selected service item does not belong to this shoot.');
        abort_if($item && !$this->intake->supportsLane($item, $lane), 422, 'Service does not accept this upload lane.');
        $mode = $lane === 'photo' ? (int) ($item ? $this->brackets->effectiveBracketMode($item) : $this->brackets->normalize($shoot->bracket_mode)) : 0;

        return $this->reserve($shoot, $actor, $data['batch_id'], $data['total_files'], $scope, $lane, $mode, true);
    }

    public function parallelUploads(Shoot $shoot, ShootRawUploadBatch $batch): int
    {
        if (! $batch->prepared) {
            return 1;
        }
        $canaries = array_map('intval', config('media.raw_parallel_upload_shoot_ids', []));

        return config('media.raw_parallel_uploads_enabled', false)
            && ($canaries === [] || in_array((int) $shoot->id, $canaries, true)) ? 2 : 1;
    }

    public function reserve(Shoot $shoot, User $actor, string $id, int $total, int $scope, string $lane, int $mode, bool $prepared = false): ShootRawUploadBatch
    {
        return LockedWrite::run(fn () => DB::transaction(function () use ($shoot, $actor, $id, $total, $scope, $lane, $mode, $prepared) {
            // The first statement obtains SQLite's writer lock before reading a
            // snapshot. No scanning, hashing or filesystem work belongs in here.
            DB::table('shoots')->where('id', $shoot->id)->update(['updated_at' => DB::raw('updated_at')]);
            $existing = ShootRawUploadBatch::where('shoot_id', $shoot->id)->where('batch_id', $id)->first();
            if ($existing) {
                $this->assertMetadata($existing, $actor->id, $total, $scope, $lane, $mode);
                if ($prepared && ! $existing->prepared) {
                    // An identity already used by a serial tab keeps its original
                    // semantics. Only check whether existing parallel ranges make
                    // a legacy layout conflict unsafe even for serial recovery.
                    $this->canPrepareScope($shoot, $scope, $lane, $mode);
                }
                return $existing;
            }
            if ($prepared) {
                $prepared = $this->canPrepareScope($shoot, $scope, $lane, $mode);
            }
            $query = $shoot->files()->where('media_type', 'raw')->where('workflow_stage', ShootFile::STAGE_TODO)
                ->when($scope, fn ($q) => $q->where('shoot_service_id', $scope), fn ($q) => $q->whereNull('shoot_service_id'));
            if ($lane === 'photo') {
                $query->where(fn ($q) => $q->whereNull('file_type')->orWhere('file_type', 'not like', 'video/%'));
            } else {
                $query->where('file_type', 'like', 'video/%');
            }
            $files = $query->get(['bracket_group', 'sequence', 'raw_upload_position']);
            $position = max($files->count(), (int) $files->max(fn ($f) => max(
                $f->raw_upload_position !== null ? (int) $f->raw_upload_position + 1 : 0,
                $mode > 1 && $f->bracket_group ? ((int) $f->bracket_group - 1) * $mode + (int) $f->sequence : 0,
            )));
            $reservations = ShootRawUploadBatch::where('shoot_id', $shoot->id)->where('service_scope', $scope)->where('upload_lane', $lane);
            $position = max($position, (int) $reservations->max(DB::raw('start_position + total_files')));

            // Carry a still-running old tab's cached range into the durable ledger.
            // Also leave room for recent legacy batches before allocating a new one.
            $legacy = DB::table('shoot_upload_attempts')->where('shoot_id', $shoot->id)->where('upload_type', 'raw')
                ->when($scope, fn ($q) => $q->where('shoot_service_id', $scope), fn ($q) => $q->whereNull('shoot_service_id'))
                ->whereNotNull('upload_batch_id')->where('upload_batch_total', '>', 0)
                ->where('created_at', '>=', now()->subDay())->get(['upload_batch_id', 'upload_batch_total']);
            foreach ($legacy->unique('upload_batch_id') as $old) {
                $offset = Cache::get("shoot:{$shoot->id}:raw_upload_batch:{$old->upload_batch_id}:offset");
                if ($offset !== null) {
                    $position = max($position, (int) $offset + (int) $old->upload_batch_total);
                }
            }
            $cached = Cache::get("shoot:{$shoot->id}:raw_upload_batch:{$id}:offset");
            if ($cached !== null) {
                $position = (int) $cached;
                $collision = (clone $reservations)->where('start_position', '<', $position + $total)
                    ->whereRaw('start_position + total_files > ?', [$position])->exists();
                abort_if($collision, 409, 'Legacy batch range conflicts with a reserved upload.');
            }
            return ShootRawUploadBatch::create(['shoot_id' => $shoot->id, 'actor_id' => $actor->id, 'batch_id' => $id,
                'service_scope' => $scope, 'upload_lane' => $lane, 'bracket_mode' => $mode,
                'total_files' => $total, 'start_position' => $position, 'prepared' => $prepared]);
        }), "shoot.{$shoot->id}.reserve-upload-batch", 6);
    }

    /** Called only after obtaining the SQLite writer lock in reserve(). */
    private function canPrepareScope(Shoot $shoot, int $scope, string $lane, int $mode): bool
    {
        try {
            $this->assertCanPrepare($shoot, $scope, $lane, $mode);
            return true;
        } catch (PublicApiException $exception) {
            // A cancelled legacy tab may never finish its missing slots. Negotiate
            // one-file-at-a-time uploads while retaining legacy EXIF regrouping,
            // but never enable that regrouping across an existing parallel range.
            $hasPreparedRange = ShootRawUploadBatch::where('shoot_id', $shoot->id)->where('service_scope', $scope)
                ->where('upload_lane', $lane)->where('prepared', true)->exists();
            if ($hasPreparedRange) {
                throw $exception;
            }
            return false;
        }
    }

    private function assertCanPrepare(Shoot $shoot, int $scope, string $lane, int $mode): void
    {
        if ($lane !== 'photo' || $mode <= 1) {
            return;
        }

        // Legacy serial uploads keep EXIF regrouping. Freezing their service for
        // parallel uploads is safe only if any outstanding positions still agree
        // with that layout. Existing serial identities are never upgraded.
        $batches = ShootRawUploadBatch::where('shoot_id', $shoot->id)->where('service_scope', $scope)
            ->where('upload_lane', $lane)->get();
        $files = ShootFile::whereIn('raw_upload_batch_id', $batches->pluck('id'))
            ->get(['id', 'raw_upload_batch_id', 'raw_upload_position', 'bracket_group', 'sequence'])
            ->groupBy('raw_upload_batch_id');
        foreach ($batches->where('prepared', false) as $batch) {
            $owned = $files->get($batch->id, collect());
            if ($owned->count() >= $batch->total_files) {
                continue;
            }
            $conflicting = $owned->contains(fn ($file) => $file->raw_upload_position === null
                || (int) $file->bracket_group < 1 || (int) $file->sequence < 1
                || ((int) $file->bracket_group - 1) * $mode + (int) $file->sequence - 1 !== (int) $file->raw_upload_position);
            if ($conflicting) {
                throw new PublicApiException('Legacy upload layout conflicts with reserved positions. Finish the existing serial batch before preparing a new batch.', 'legacy_upload_layout_conflict', 409);
            }
        }

        // A tab opened before durable reservations were introduced has no owned
        // rows to prove its layout. Do not freeze its service while its cached
        // batch or a pending request can still complete missing positions.
        $attempts = ShootUploadAttempt::where('shoot_id', $shoot->id)->where('upload_type', 'raw')
            ->when($scope, fn ($q) => $q->where('shoot_service_id', $scope), fn ($q) => $q->whereNull('shoot_service_id'))
            ->whereNotNull('upload_batch_id')->where('upload_batch_total', '>', 0)
            ->where(fn ($q) => $q->where('updated_at', '>=', now()->subDay())->orWhere('status', ShootUploadAttempt::STATUS_PENDING))
            ->get(['upload_batch_id', 'upload_batch_index', 'upload_batch_total', 'status', 'result_file_ids']);
        foreach ($attempts->groupBy('upload_batch_id') as $id => $group) {
            $batch = $batches->firstWhere('batch_id', (string) $id);
            if ($batch?->prepared) {
                continue;
            }
            $pending = $group->contains('status', ShootUploadAttempt::STATUS_PENDING);
            $resultIds = $group->flatMap(fn ($attempt) => (array) $attempt->result_file_ids)->unique();
            $ownedIds = $batch ? $files->get($batch->id, collect())->pluck('id') : collect();
            if ($batch && ! $pending && $resultIds->diff($ownedIds)->isEmpty()) {
                continue;
            }
            $cached = Cache::get("shoot:{$shoot->id}:raw_upload_batch:{$id}:offset") !== null;
            if (! $pending && ! $cached) {
                continue;
            }
            $total = (int) $group->max('upload_batch_total');
            $completed = $group->filter(fn ($attempt) => $attempt->status === ShootUploadAttempt::STATUS_COMPLETED
                && ! empty($attempt->result_file_ids) && $attempt->upload_batch_index !== null
                && $attempt->upload_batch_index >= 0 && $attempt->upload_batch_index < $total)
                ->pluck('upload_batch_index')->unique()->count();
            if ($pending || $completed < $total) {
                throw new PublicApiException('An earlier serial upload batch is still active. Finish it before preparing parallel uploads for this service.', 'legacy_upload_batch_active', 409);
            }
        }
    }

    public function claimUpload(Request $request, Shoot $shoot, User $actor, ?int $scope, array $lanes, int $mode, int $fileCount, string $correlationId): ?ShootRawUploadBatch
    {
        $id = trim((string) $request->input('upload_batch_id', ''));
        $batch = $id !== '' ? ShootRawUploadBatch::where('shoot_id', $shoot->id)->where('batch_id', $id)->first() : null;
        // Reserve complete legacy batches too, before any bytes are stored. A
        // new prepare request may race this request even if no reservation yet exists.
        $total = filter_var($request->input('upload_batch_total'), FILTER_VALIDATE_INT);
        $index = filter_var($request->input('upload_batch_index'), FILTER_VALIDATE_INT);
        $key = trim((string) ($request->input('idempotency_key') ?: $request->header('Idempotency-Key', '')));
        if (!$batch && ($id === '' || !$total || $index === false || $key === '' || count($lanes) !== 1 || $fileCount !== 1)) {
            if ($mode <= 1) { return null; }
            // Older multipart clients may omit all batch metadata. Their request
            // still needs a disjoint range when a prepared batch is unfinished.
            $legacyId = 'legacy-'.hash('sha256', $actor->id.':'.($key ?: $correlationId));
            return $this->reserve($shoot, $actor, $legacyId, $fileCount, (int) $scope, 'photo', $mode);
        }
        abort_unless($total && $total <= 10000 && $index !== false && $index >= 0 && $index < $total
            && $fileCount === 1 && $key !== '' && strlen($key) <= 191 && count($lanes) === 1, 422, 'Invalid reserved upload metadata.');
        $batch ??= $this->reserve($shoot, $actor, $id, $total, (int) $scope, $lanes[0], $mode);
        $this->assertMetadata($batch, $actor->id, $total, (int) $scope, $lanes[0], $mode);
        LockedWrite::run(fn () => DB::transaction(function () use ($batch, $index, $key) {
            DB::table('shoot_raw_upload_batch_slots')->insertOrIgnore(['batch_id' => $batch->id, 'file_index' => $index, 'idempotency_key' => $key]);
            $slot = DB::table('shoot_raw_upload_batch_slots')->where('batch_id', $batch->id)->where('file_index', $index)->first();
            abort_unless($slot && hash_equals($slot->idempotency_key, $key), 409, 'This batch position belongs to another upload identity.');
        }), "shoot.{$shoot->id}.claim-upload-slot", 6);
        return $batch;
    }

    private function assertMetadata(ShootRawUploadBatch $batch, int $actor, int $total, int $scope, string $lane, int $mode): void
    {
        if ($batch->actor_id !== $actor || $batch->total_files !== $total || $batch->service_scope !== $scope
            || $batch->upload_lane !== $lane || $batch->bracket_mode !== $mode) {
            throw new HttpException(409, 'This batch identity was already reserved with different upload settings.');
        }
    }
}
