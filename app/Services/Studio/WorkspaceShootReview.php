<?php

namespace App\Services\Studio;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\StudioWorkspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** Connect full-shoot AI output approval to the existing shoot review workflow. */
class WorkspaceShootReview
{
    public function pendingFiles(Shoot $shoot): Builder
    {
        return $shoot->files()->getQuery()->where('is_ai_edited', true)
            ->where('ai_editing_metadata->requires_review', true)
            ->where('workflow_stage', ShootFile::STAGE_COMPLETED);
    }

    public function isPending(Shoot $shoot): bool
    {
        return $this->pendingFiles($shoot)->exists();
    }

    public function isProcessing(Shoot $shoot): bool
    {
        return StudioWorkspace::where('shoot_id', $shoot->id)
            ->where(fn ($query) => $query->where('preset_id', 'full-shoot')->orWhereNotNull('parent_workspace_id'))
            ->whereIn('status', ['generating', 'preparing'])->exists();
    }

    /** Called inside the shoot approval transaction, after its existing role/state checks. */
    public function approve(Shoot $shoot, User $reviewer): void
    {
        $files = $this->pendingFiles($shoot)->get();
        foreach ($files as $file) {
            $file->update(['workflow_stage' => ShootFile::STAGE_VERIFIED, 'verified_at' => now(), 'verified_by' => $reviewer->id,
                'ai_editing_metadata' => array_merge($file->ai_editing_metadata, ['reviewed_by' => $reviewer->id, 'reviewed_at' => now()->toIso8601String()])]);
        }
        foreach ($files->groupBy(fn ($file) => $file->ai_editing_metadata['workspace_id']) as $id => $outputs) {
            $workspace = StudioWorkspace::find($id);
            if (! $workspace) {
                continue;
            }
            $reviewedIds = $outputs->map(fn ($file) => substr($file->ai_editing_metadata['output_key'], strlen($id) + 1))->all();
            $config = $workspace->config;
            $config['reviewedOutputIds'] = array_values(array_unique(array_merge($config['reviewedOutputIds'] ?? [], $reviewedIds)));
            $workspace->update(['config' => $config, 'version' => $workspace->version + 1]);
        }
    }
}
