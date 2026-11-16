<?php
namespace App\Services\IguideLab;

use App\Models\IguideLabTourAnalysis;
use Illuminate\Support\Facades\DB;

/**
 * recordRevision (spec 10.6, D12). Called at most once per lab write transaction, in the same transaction as the
 * change it records. It is the only write that moves the record's updatedAt; every other non-content write is a
 * bookkeeping write (Model::whereKey($id)->toBase()->update()), which never touches updated_at.
 */
final class LabTourRevisions
{
    public const MAX_REVISION = 2147483647;

    public static function bump(string $analysisId): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('A tour record revision is bumped only inside the lab write transaction that made the change.');
        }
        IguideLabTourAnalysis::whereKey($analysisId)->where('record_revision', '<', self::MAX_REVISION)
            ->update(['record_revision' => DB::raw('record_revision + 1'), 'updated_at' => now()]);
    }
}
