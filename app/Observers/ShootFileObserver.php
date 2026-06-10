<?php

namespace App\Observers;

use App\Jobs\GenerateShootMediaArchiveJob;
use App\Models\Shoot;
use App\Models\ShootFile;

class ShootFileObserver
{
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
