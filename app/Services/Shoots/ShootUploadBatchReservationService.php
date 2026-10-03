<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootUploadBatch;
use App\Models\User;
use App\Support\LockedWrite;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ShootUploadBatchReservationService
{
    public const RAW_PARALLEL = 2;

    public const EDITED_PARALLEL = 3;

    /**
     * Idempotently reserve a durable batch offset and advertise parallel upload slots.
     *
     * @param  array{upload_type:string,upload_batch_id:string,upload_batch_total:int,shoot_service_id:?int,bracket_mode:?int}  $input
     * @return array{batch:ShootUploadBatch,created:bool}
     */
    public function prepare(Shoot $shoot, User $actor, array $input): array
    {
        $uploadType = strtolower(trim((string) $input['upload_type']));
        if (! in_array($uploadType, ['raw', 'edited'], true)) {
            throw new HttpException(422, 'upload_type must be raw or edited.');
        }

        $batchId = mb_substr(trim((string) $input['upload_batch_id']), 0, 191);
        if ($batchId === '') {
            throw new HttpException(422, 'upload_batch_id is required.');
        }

        $total = (int) $input['upload_batch_total'];
        if ($total < 1) {
            throw new HttpException(422, 'upload_batch_total must be at least 1.');
        }

        $shootServiceId = $input['shoot_service_id'] ?? null;
        $shootServiceId = $shootServiceId !== null && $shootServiceId !== '' ? (int) $shootServiceId : null;
        $bracketMode = $input['bracket_mode'] ?? null;
        $bracketMode = $bracketMode !== null && $bracketMode !== '' ? (int) $bracketMode : null;

        $parallel = $this->parallelUploadsFor($shoot, $uploadType);

        $existing = ShootUploadBatch::query()
            ->where('shoot_id', $shoot->id)
            ->where('actor_id', $actor->id)
            ->where('upload_batch_id', $batchId)
            ->first();

        if ($existing) {
            if (! $this->metadataMatches($existing, $uploadType, $total, $shootServiceId, $bracketMode)) {
                throw new HttpException(409, 'This upload batch was already prepared with different settings.');
            }

            return ['batch' => $existing, 'created' => false];
        }

        $created = LockedWrite::run(function () use (
            $shoot,
            $actor,
            $batchId,
            $uploadType,
            $total,
            $shootServiceId,
            $bracketMode,
            $parallel
        ) {
            $again = ShootUploadBatch::query()
                ->where('shoot_id', $shoot->id)
                ->where('actor_id', $actor->id)
                ->where('upload_batch_id', $batchId)
                ->first();

            if ($again) {
                return ['batch' => $again, 'created' => false, 'conflict' => ! $this->metadataMatches(
                    $again,
                    $uploadType,
                    $total,
                    $shootServiceId,
                    $bracketMode
                )];
            }

            $offset = $this->nextOffset($shoot, $uploadType, $shootServiceId);

            $batch = ShootUploadBatch::query()->create([
                'shoot_id' => $shoot->id,
                'actor_id' => $actor->id,
                'upload_batch_id' => $batchId,
                'upload_type' => $uploadType,
                'shoot_service_id' => $shootServiceId,
                'bracket_mode' => $bracketMode,
                'upload_batch_total' => $total,
                'reserved_offset' => $offset,
                'parallel_uploads' => $parallel,
            ]);

            return ['batch' => $batch, 'created' => true, 'conflict' => false];
        }, "shoot.{$shoot->id}.upload-batch.{$batchId}");

        if (! empty($created['conflict'])) {
            throw new HttpException(409, 'This upload batch was already prepared with different settings.');
        }

        return ['batch' => $created['batch'], 'created' => (bool) $created['created']];
    }

    public function findOffset(Shoot $shoot, ?User $actor, string $batchId): ?int
    {
        $batchId = mb_substr(trim($batchId), 0, 191);
        if ($batchId === '' || ! $actor) {
            return null;
        }

        $batch = ShootUploadBatch::query()
            ->where('shoot_id', $shoot->id)
            ->where('actor_id', $actor->id)
            ->where('upload_batch_id', $batchId)
            ->first();

        return $batch ? (int) $batch->reserved_offset : null;
    }

    public function parallelUploadsFor(Shoot $shoot, string $uploadType): int
    {
        $uploadType = strtolower(trim($uploadType));

        if ($uploadType === 'edited') {
            return self::EDITED_PARALLEL;
        }

        if ($uploadType !== 'raw') {
            return 1;
        }

        if (! $this->rawParallelEnabled((int) $shoot->id)) {
            return 1;
        }

        return self::RAW_PARALLEL;
    }

    public function rawParallelEnabled(int $shootId): bool
    {
        if (! (bool) config('media.raw_parallel_uploads_enabled', false)) {
            return false;
        }

        $canaries = config('media.raw_parallel_upload_shoot_ids', []);
        if ($canaries === []) {
            return true;
        }

        return in_array($shootId, array_map('intval', $canaries), true);
    }

    private function metadataMatches(
        ShootUploadBatch $batch,
        string $uploadType,
        int $total,
        ?int $shootServiceId,
        ?int $bracketMode
    ): bool {
        return strtolower((string) $batch->upload_type) === $uploadType
            && (int) $batch->upload_batch_total === $total
            && $batch->shoot_service_id === $shootServiceId
            && $batch->bracket_mode === $bracketMode;
    }

    private function nextOffset(Shoot $shoot, string $uploadType, ?int $shootServiceId): int
    {
        if ($uploadType !== 'raw') {
            return 0;
        }

        $query = $shoot->files()
            ->where('workflow_stage', ShootFile::STAGE_TODO)
            ->where('media_type', 'raw');

        if ($shootServiceId) {
            $query->where('shoot_service_id', $shootServiceId);
        } else {
            $query->whereNull('shoot_service_id');
        }

        // Also advance past any still-open reservations for the same scope so
        // concurrent prepares do not claim overlapping bracket ranges.
        $fileCount = (int) $query->count();
        $reservedEnd = (int) ShootUploadBatch::query()
            ->where('shoot_id', $shoot->id)
            ->where('upload_type', 'raw')
            ->when(
                $shootServiceId,
                fn ($q) => $q->where('shoot_service_id', $shootServiceId),
                fn ($q) => $q->whereNull('shoot_service_id')
            )
            ->selectRaw('COALESCE(MAX(reserved_offset + upload_batch_total), 0) as reserved_end')
            ->value('reserved_end');

        return max($fileCount, $reservedEnd);
    }
}
