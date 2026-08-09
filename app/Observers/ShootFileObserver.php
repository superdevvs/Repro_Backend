<?php

namespace App\Observers;

use App\Jobs\GenerateShootMediaArchiveJob;
use App\Models\Shoot;
use App\Models\ShootFile;

class ShootFileObserver
{
    /**
     * Append newly created files to the end of the shoot's delivery order.
     *
     * Without this a new upload lands on the column default of 0, which
     * scopeInDeliveryOrder() treats as "never placed" — so it would arrive at
     * the very end of an already-curated shoot only by accident, and any code
     * comparing raw sort_order values would see it tie with every other 0.
     * Taking max(sort_order) + 1 makes the append explicit and keeps positions
     * unique, so a later reorder starts from a well-defined sequence.
     *
     * A caller that sets sort_order itself is respected verbatim — including an
     * explicit 0, which is the documented way to say "unplaced". Only an
     * omitted attribute is auto-assigned, so seeders/tests/imports that pin
     * positions keep working.
     */
    public function creating(ShootFile $shootFile): void
    {
        if (array_key_exists('sort_order', $shootFile->getAttributes())) {
            return;
        }

        if (!$shootFile->shoot_id) {
            return;
        }

        $shootFile->sort_order = ((int) ShootFile::query()
            ->where('shoot_id', $shootFile->shoot_id)
            ->max('sort_order')) + 1;
    }

    public function saved(ShootFile $shootFile): void
    {
        if (
            !$shootFile->wasRecentlyCreated
            && !$shootFile->wasChanged([
                'workflow_stage',
                'path',
                'storage_path',
                'dropbox_path',
                'thumbnail_path',
                'web_path',
                'placeholder_path',
                'file_size',
                'sort_order',
                'media_type',
                'is_extra',
                'required_for_editing',
                'filename',
                'stored_filename',
            ])
        ) {
            return;
        }

        $this->dispatchRelevantArchives(
            $shootFile->shoot,
            $this->mapStageToArchiveType($shootFile->getOriginal('workflow_stage')),
            $this->mapStageToArchiveType($shootFile->workflow_stage)
        );
    }

    public function deleted(ShootFile $shootFile): void
    {
        $this->dispatchRelevantArchives(
            $shootFile->shoot,
            $this->mapStageToArchiveType($shootFile->workflow_stage),
            null
        );
    }

    protected function dispatchRelevantArchives($shoot, ?string $previousType, ?string $currentType): void
    {
        if (!$shoot) {
            return;
        }

        $status = strtolower((string) ($shoot->workflow_status ?: $shoot->status));
        $types = array_values(array_unique(array_filter([$previousType, $currentType])));
        foreach ($types as $type) {
            if ($type === 'raw' && $status !== Shoot::STATUS_EDITING) {
                continue;
            }

            if ($type === 'edited' && !in_array($status, [Shoot::STATUS_READY, Shoot::STATUS_DELIVERED], true)) {
                continue;
            }

            GenerateShootMediaArchiveJob::dispatch($shoot->id, $type, 'small');
        }
    }

    protected function mapStageToArchiveType(?string $stage): ?string
    {
        return match ($stage) {
            ShootFile::STAGE_COMPLETED, ShootFile::STAGE_VERIFIED => 'edited',
            ShootFile::STAGE_TODO, null => 'raw',
            default => null,
        };
    }
}
