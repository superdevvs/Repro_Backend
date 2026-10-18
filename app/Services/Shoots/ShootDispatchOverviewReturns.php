<?php

namespace App\Services\Shoots;

use App\Models\{Shoot, ShootEditingDispatchItem, ShootFile, ShootFileVersion, User};
use Illuminate\Support\Collection;

/** Bridge normal shoot uploads to human requests without changing permanent editor assignments. */
class ShootDispatchOverviewReturns
{
    public function items(Shoot $shoot, User $editor, bool $open = true): Collection
    {
        $query = ShootEditingDispatchItem::with('dispatch')->where('editor_id', $editor->id)
            ->where('destination', 'human')->where('status', '!=', 'cancelled')
            ->whereHas('dispatch', fn ($query) => $query->where('shoot_id', $shoot->id)->where('status', '!=', 'cancelled'));
        if ($open) $query->where('status', '!=', 'completed');
        return $query->get();
    }

    public function visibleFileIds(Shoot $shoot, User $editor): array
    {
        $items = $this->items($shoot, $editor);
        return $this->outputs($shoot, $editor)->filter(fn ($file) => $items->contains(fn ($item) => $this->matches($file, $item)))->modelKeys();
    }

    private function outputs(Shoot $shoot, User $editor): Collection
    {
        $versions = ShootFileVersion::where('shoot_id', $shoot->id)->where('created_by', $editor->id)
            ->where('status', 'published')->whereNotNull('published_file_id')->get()->keyBy('published_file_id');
        return $shoot->files()->whereIn('workflow_stage', [ShootFile::STAGE_COMPLETED, ShootFile::STAGE_VERIFIED])->get()
            ->filter(function (ShootFile $file) use ($editor, $versions) {
                if ($file->is_hidden || ! $file->isClearedForProcessing() || filled($file->flag_reason)) return false;
                $version = $versions->get($file->id);
                $currentOwnVersion = $version && (int) $version->version === (int) $file->content_version;
                if ($currentOwnVersion) $file->setRelation('overview_own_version', $version);
                return (int) $file->uploaded_by === (int) $editor->id || $currentOwnVersion;
            });
    }

    private function matches(ShootFile $file, ShootEditingDispatchItem $item): bool
    {
        if (app(ShootEditingAssignmentService::class)->getFileLane($file) !== $item->lane) return false;
        if ($item->shoot_service_id && (int) $file->shoot_service_id !== (int) $item->shoot_service_id) return false;
        $savedAt = $file->getRelationValue('overview_own_version')?->created_at ?? $file->created_at;
        return $savedAt && $savedAt->greaterThanOrEqualTo($item->dispatch->created_at);
    }

    /** Validate every group first; callers hold the shoot write transaction for all changes. */
    public function submit(Shoot $shoot, User $editor): array
    {
        $items = $this->items($shoot, $editor);
        if ($items->isEmpty()) return ['changed_count' => 0];
        $outputs = $this->outputs($shoot, $editor);
        $plans = [];
        foreach ($items->groupBy(fn ($item) => $item->dispatch_id.':'.$item->lane.':'.($item->shoot_service_id ?? 'unscoped')) as $group) {
            $ordinary = $group->filter(fn ($item) => ! $item->primary_version_id);
            $files = $outputs->filter(fn ($file) => $this->matches($file, $group->first()));
            if ($files->count() < $ordinary->count()) return $this->pending('Upload all assigned returns before submitting.');
            foreach ($group as $item) {
                foreach ($item->sources as $source) {
                    $current = ShootFile::find($source['id']);
                    $ownReturn = $current && ShootFileVersion::where('shoot_id', $shoot->id)->where('created_by', $editor->id)
                        ->where('published_file_id', $current->id)->where('status', 'published')
                        ->where('version', $current->content_version)->where('expected_version', $source['version'])
                        ->where('created_at', '>=', $item->dispatch->created_at)->exists();
                    if (! $current || ((int) $current->content_version !== (int) $source['version'] && ! $ownReturn)) {
                        return $this->pending('An assigned source changed. Ask the editing manager to review this request.');
                    }
                }
                if ($item->primary_version_id) {
                    $version = ShootFileVersion::find($item->primary_version_id);
                    $file = $version?->published_file_id ? ShootFile::find($version->published_file_id) : null;
                    if ($item->status !== 'returned' || ! $file || $version->status !== 'published'
                        || (int) $file->content_version !== (int) $version->version
                        || ! $file->isClearedForProcessing() || $file->is_hidden || filled($file->flag_reason)) {
                        return $this->pending('Wait for saved task returns to finish processing before submitting.');
                    }
                    $plans[] = [$item, null];
                } else {
                    // This is a batch return. Preserve its scope and files without inventing source/output pairs.
                    $plans[] = [$item, $files->map(fn ($file) => ['id' => $file->id, 'version' => $file->content_version])->values()->all()];
                }
            }
        }
        foreach ($plans as [$item, $files]) $item->update(['status' => 'completed', 'error' => null, 'overview_return_files' => $files]);
        foreach ($items->pluck('dispatch_id')->unique() as $id) app(ScopedEditingDispatch::class)->reconcile($id);
        request()->attributes->remove('editor_dispatch_file_ids');
        return ['changed_count' => count($plans)];
    }

    private function pending(string $message): array
    {
        return ['status' => 409, 'payload' => ['error_type' => 'dispatch_edits_pending', 'message' => $message,
            'retryable' => true, 'workflow_status_changed' => false]];
    }
}
