<?php

namespace App\Services\Shoots;

use App\Models\{AiEditingJob, Shoot, ShootFile, User};
use App\Support\{EditingDispatchWriteLock, LockedWrite};
use Illuminate\Support\Facades\DB;

/** Old AI consumers share the same booked ownership and retry boundary. */
final class LegacyAiOutputPublisher
{
    public function finishPath(ShootFile $published, string $attemptPath): string
    {
        if ($published->path !== $attemptPath) {
            // A concurrent consumer won publication. Only this attempt's unique
            // unreferenced object is removed, after its DB transaction commits.
            DB::afterCommit(fn () => app(\App\Services\Media\MediaStorage::class)->delete($attemptPath));
        }
        return $published->path;
    }

    public function publish(AiEditingJob $job, array $attributes): ShootFile
    {
        return LockedWrite::run(fn () => DB::transaction(function () use ($job, $attributes) {
            EditingDispatchWriteLock::acquire((int) $job->shoot_id);
            $shoot = Shoot::findOrFail($job->shoot_id);
            $actor = User::findOrFail($job->user_id);
            $source = $job->shoot_file_id ? $shoot->files()->find($job->shoot_file_id) : null;
            abort_if($job->shoot_file_id && ! $source, 409, 'The AI source no longer belongs to this shoot.');
            $policy = app(ShootUploadTargetPolicy::class);
            $target = $source?->shoot_service_id ? $shoot->serviceItems()->with('service.category')->find($source->shoot_service_id) : null;
            if ($target) abort_unless($policy->accepts($target, 'photo', 'edited'), 409, 'The source service is no longer eligible for this AI output.');
            if ($target) abort_unless($policy->canUploadFamilies($shoot, $actor, $target, ['photo']), 403, 'Your photo editing assignment changed.');
            if (! $target && $shoot->serviceItems()->exists()) {
                $candidates = $shoot->serviceItems()->with('service.category')->get()->filter(fn ($item) => $policy->isOutstanding($item)
                    && $policy->accepts($item, 'photo', 'edited') && $policy->canUploadFamilies($shoot, $actor, $item, ['photo'])
                    && app(ShootAuthorizationSupport::class)->canUploadShootMedia($shoot, $actor, 'edited', $item->id));
                abort_unless($candidates->count() === 1, 409, 'Assign the AI source to its booked service before publishing the result.');
                $target = $candidates->first();
            }
            abort_unless(app(ShootAuthorizationSupport::class)->canUploadShootMedia($shoot, $actor, 'edited', $target?->id),
                403, 'The AI output assignment changed. Staff review is required.');
            $existing = $shoot->files()->where('ai_editing_job_id', $job->id)->first();
            if ($existing) {
                abort_unless((int) $existing->shoot_service_id === (int) $target?->id, 409, 'The saved AI output has a different service owner.');
                return $existing;
            }
            return ShootFile::create(array_merge($attributes, [
                'shoot_id' => $shoot->id, 'shoot_service_id' => $target?->id, 'source_file_id' => $source?->id,
            ]));
        }), 'shoot.ai-output.publish');
    }
}
