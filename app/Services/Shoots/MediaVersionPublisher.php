<?php

namespace App\Services\Shoots;

use App\Jobs\ProcessMediaVersion;
use App\Models\ShootFile;
use App\Models\ShootFileVersion;
use App\Models\User;
use App\Services\Media\MediaStorage;
use App\Support\{EditingDispatchWriteLock, LockedWrite};
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** One publication boundary for AI, human returns and desktop saves. No old bytes are deleted. */
class MediaVersionPublisher
{
    public const CONTENT_FIELDS = ['filename', 'stored_filename', 'path', 'storage_path', 'file_type', 'mime_type', 'file_size',
        'thumbnail_path', 'grid_path', 'web_path', 'placeholder_path', 'watermarked_storage_path', 'watermarked_thumbnail_path',
        'watermarked_web_path', 'watermarked_placeholder_path', 'processed_at', 'processing_failed_at', 'processing_error',
        'scan_status', 'scan_result', 'scanned_at', 'is_ai_edited', 'ai_editing_metadata', 'large_path', 'medium_path'];

    public function __construct(private MediaStorage $storage) {}

    public function stage(ShootFile $source, string $localPath, string $filename, int $expectedVersion, User $user, string $requestKey, array $metadata = []): ShootFileVersion
    {
        $mime = app(UploadIntakeResolver::class)->detectedMimeType(new \Illuminate\Http\UploadedFile($localPath, $filename, null, null, true));
        $video = MediaVersionVideo::extension((string) $mime) !== null;
        $extension = $video ? MediaVersionVideo::extension($mime) : (['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/tiff' => 'tif'][$mime] ?? null);
        abort_unless($extension, 422, 'Export a JPEG, PNG, TIFF or supported video to upload.');
        $sourceVideo = app(ShootDownloadAssetClassifier::class)->isVideo($source);
        abort_unless($video === $sourceVideo, 422, 'A replacement must keep the original media type.');
        abort_unless(is_file($localPath) && filesize($localPath) > 0 && filesize($localPath) <= ($video ? 1073741824 : 268435456), 422,
            $video ? 'The video must be between 1 byte and 1 GB.' : 'The exported image must be between 1 byte and 256 MB.');
        $hash = hash_file('sha256', $localPath);
        $existing = ShootFileVersion::where('request_key', $requestKey)->first();
        if ($existing) {
            abort_unless($existing->sha256 === $hash && (int) ($existing->metadata['requested_version'] ?? $existing->expected_version) === $expectedVersion && (int) $existing->source_file_id === (int) $source->id && (int) $existing->created_by === (int) $user->id, 409, 'This upload request was already used for different bytes or media.');
            return $existing;
        }
        $id = (string) Str::uuid();
        $stored = $id.'.'.$extension;
        $path = "shoots/{$source->shoot_id}/completed/versions/{$stored}";
        $stream = fopen($localPath, 'rb');
        try {
            if (!$this->storage->put($path, $stream)) throw new \RuntimeException('The saved edit could not be stored.');
        } finally {
            if (is_resource($stream)) fclose($stream);
        }
        // Keep the export's extension honest while retaining the target's display name on publication.
        $snapshot = ['filename' => pathinfo(basename($filename), PATHINFO_FILENAME).'.'.$extension, 'stored_filename' => $stored,
            'path' => $path, 'storage_path' => $path, 'file_type' => $mime, 'mime_type' => $mime, 'file_size' => filesize($localPath)];
        return LockedWrite::run(fn () => DB::transaction(function () use ($source, $expectedVersion, $user, $requestKey, $metadata, $snapshot, $hash, $id, $video) {
            $existing = ShootFileVersion::where('request_key', $requestKey)->first();
            if ($existing) {
                abort_unless($existing->sha256 === $hash && (int) ($existing->metadata['requested_version'] ?? $existing->expected_version) === $expectedVersion && (int) $existing->source_file_id === (int) $source->id && (int) $existing->created_by === (int) $user->id, 409, 'This upload request was already used for different bytes or media.');
                return $existing;
            }
            $fresh = ShootFile::findOrFail($source->id);
            abort_unless((int) $fresh->shoot_service_id === (int) $source->shoot_service_id, 409, 'Source ownership changed. Review the save target again.');
            if (($metadata['origin'] ?? '') === 'intake') {
                $actor = $user->fresh();
                $shoot = $fresh->shoot()->firstOrFail();
                $target = $fresh->serviceItem;
                abort_unless($actor && app(ShootAuthorizationSupport::class)->canUploadShootMedia($shoot, $actor, 'edited', $fresh->shoot_service_id)
                    && (! $target || (app(ShootUploadTargetPolicy::class)->accepts($target, $video ? 'video' : 'photo', 'edited')
                        && app(ShootUploadTargetPolicy::class)->canUploadFamilies($shoot, $actor, $target, [$video ? 'video' : 'photo']))), 403, 'Your editing assignment changed before this replacement was saved.');
            }
            $raw = $fresh->workflow_stage === ShootFile::STAGE_TODO;
            $version = ShootFileVersion::create(['id' => $id, 'shoot_id' => $source->shoot_id, 'source_file_id' => $source->id,
                'target_file_id' => $raw ? null : $source->id, 'expected_version' => $expectedVersion, 'request_key' => $requestKey,
                'sha256' => $hash, 'status' => 'queued', 'snapshot' => $snapshot, 'metadata' => $metadata + ['source_was_raw' => $raw, 'requested_version' => $expectedVersion, 'source_service_id' => $source->shoot_service_id],
                'created_by' => $user->id, 'dispatch_item_id' => $metadata['dispatch_item_id'] ?? null]);
            if ($version->dispatch_item_id) {
                $items = \App\Models\ShootEditingDispatchItem::whereIn('id', $metadata['dispatch_item_ids'] ?? [$version->dispatch_item_id])->get();
                $hasPrimary = $items->contains(fn ($item) => $item->primary_version_id !== null
                    && !(($metadata['origin'] ?? '') === 'human' && ShootFileVersion::find($item->primary_version_id)?->status === 'failed'));
                abort_if(($metadata['origin'] ?? '') === 'human' && $hasPrimary, 409, 'This task already has a saved return. Review that upload first.');
                if ($hasPrimary) {
                    $version->update(['metadata' => array_merge($version->metadata, ['alternative' => true])]);
                } else {
                    \App\Models\ShootEditingDispatchItem::whereIn('id', $items->modelKeys())->update(['primary_version_id' => $version->id, 'status' => 'processing']);
                }
            }
            ProcessMediaVersion::dispatch($version->id)->beforeCommit();
            return $version;
        }), 'media-version.stage');
    }

    public function publish(ShootFileVersion $version): ShootFileVersion
    {
        $result = LockedWrite::run(fn () => DB::transaction(function () use ($version) {
            EditingDispatchWriteLock::acquire((int) $version->shoot_id);
            $version = ShootFileVersion::lockForUpdate()->findOrFail($version->id);
            if ($version->status !== 'ready') return $version;
            $source = ShootFile::find($version->source_file_id);
            if (!$source || (int) $source->shoot_id !== (int) $version->shoot_id) {
                $version->update(['status' => 'failed', 'error_code' => 'source_deleted', 'error' => 'The source image was deleted. The returned edit is preserved for staff recovery.']);
                return $version;
            }
            $target = $version->target_file_id ? ShootFile::lockForUpdate()->find($version->target_file_id) : null;
            if ($version->target_file_id && !$target) {
                $version->update(['status' => 'failed', 'error_code' => 'target_deleted', 'error' => 'The image being replaced was deleted. The returned edit is preserved.']);
                return $version;
            }
            if (($target && ($target->workflow_stage === ShootFile::STAGE_TODO || $target->is_hidden)) || $source->is_hidden) {
                $version->update(['status' => 'failed', 'error_code' => 'source_unavailable', 'error' => 'The source is no longer available for this edit. The returned bytes are preserved.']);
                return $version;
            }
            $sourcesChanged = collect($version->metadata['source_versions'] ?? [])->contains(function ($expected, $id) {
                $file = ShootFile::find($id);
                return !$file || (int) $file->content_version !== (int) $expected;
            });
            if (($target && (int) $target->content_version !== $version->expected_version)
                || (!$target && empty($version->metadata['save_as_copy']) && ((int) $source->content_version !== $version->expected_version || $sourcesChanged))) {
                $version->update(['status' => 'conflict', 'error_code' => 'newer_version', 'error' => 'A newer edit is already current. Choose Replace latest or Save as copy.']);
                return $version;
            }
            if (array_key_exists('source_service_id', $version->metadata ?? []) && (int) $source->shoot_service_id !== (int) $version->metadata['source_service_id']) {
                $version->update(['status' => 'conflict', 'error_code' => 'source_changed', 'error' => 'Media ownership changed. Review the replacement again.']);
                return $version;
            }
            $snapshot = $version->snapshot;
            $video = MediaVersionVideo::extension((string) ($snapshot['mime_type'] ?? '')) !== null;
            abort_unless(($snapshot['scan_status'] ?? '') === 'clean' && !empty($snapshot['processed_at'])
                && ($video ? !empty($snapshot['video_validated_at'])
                    : collect(['thumbnail_path', 'grid_path', 'web_path', 'placeholder_path'])->every(fn ($key) => !empty($snapshot[$key]))), 409, 'The returned media is not ready to publish.');
            $planner = app(DeliveryPlanService::class);
            $shoot = $source->shoot()->firstOrFail();
            $planner->forgetReleasedFiles($shoot);
            $reviewedId = data_get($version->metadata, 'delivery_review.file_id');
            $approvalCandidate = $target ?? ($reviewedId ? ShootFile::find($reviewedId) : $source);
            if ($reviewedId && (! $approvalCandidate || (int) $approvalCandidate->shoot_id !== (int) $shoot->id
                || ! $planner->releasedFile($approvalCandidate))) return $this->holdForDeliveryReview($version);
            $approvedBefore = $approvalCandidate && $planner->releasedFile($approvalCandidate) ? clone $approvalCandidate : null;
            $reviewer = $approvedBefore ? $this->deliveryReviewer($version, $approvedBefore) : null;
            if ($approvedBefore && ! $reviewer) return $this->holdForDeliveryReview($version);

            // A staff replacement and its exact component approval commit together.
            // Ineligible historical/execution evidence rolls back the publication,
            // while preserving the staged export for an explicit staff decision.
            $reviewRequired = new \RuntimeException('This replacement requires delivery review.');
            try {
                $target = DB::transaction(function () use ($version, $source, $target, $snapshot, $approvedBefore, $reviewer, $planner, $shoot, $reviewRequired) {
                    if ($target) {
                        $this->archiveCurrent($target, $version->created_by);
                        $snapshot['filename'] = pathinfo($target->filename, PATHINFO_FILENAME).'.'.pathinfo($snapshot['stored_filename'], PATHINFO_EXTENSION);
                        $target->fill(Arr::only($snapshot, self::CONTENT_FIELDS));
                        if (MediaVersionVideo::extension((string) ($snapshot['mime_type'] ?? ''))) {
                            // Missing-local fallbacks must not route back to the
                            // superseded remote video after publication.
                            $target->forceFill(['dropbox_path' => null, 'dropbox_file_id' => null]);
                            $target->metadata = array_merge(Arr::except($target->metadata ?? [], ['width', 'height', 'duration']),
                                Arr::only($snapshot['video_validation'] ?? [], ['width', 'height', 'duration']));
                        }
                        $target->content_version++;
                        $target->save();
                    } else {
                        if (($version->metadata['origin'] ?? '') === 'ai') $snapshot['filename'] = app(\App\Services\Studio\AiEditedFilename::class)->next($source->shoot_id)['filename'];
                        $treatment = ($version->metadata['origin'] ?? '') === 'ai'
                            ? ShootFile::normalizeTreatment(str_replace('-', '_', $version->metadata['ai_editing_metadata']['workflow'] ?? '')) : $source->treatment;
                        $target = ShootFile::create(Arr::only($snapshot, self::CONTENT_FIELDS) + [
                            'shoot_id' => $source->shoot_id, 'shoot_service_id' => $source->shoot_service_id, 'source_file_id' => $source->id,
                            'media_type' => $treatment ?? ($source->media_type === 'drone' ? 'drone' : (str_starts_with((string) $snapshot['mime_type'], 'video/') ? 'video' : 'edited')), 'treatment' => $treatment,
                            'workflow_stage' => ShootFile::STAGE_VERIFIED, 'verified_at' => now(), 'moved_to_completed_at' => now(),
                            'uploaded_by' => $version->created_by, 'uploaded_at' => now(), 'required_for_editing' => false, 'content_version' => 1,
                        ]);
                    }
                    if ($approvedBefore && ! $planner->recordReviewedVersion($shoot, $reviewer, $target, 'media-version:'.$version->id, $approvedBefore)) {
                        throw $reviewRequired;
                    }
                    return $target;
                });
            } catch (\RuntimeException $exception) {
                if ($exception !== $reviewRequired) throw $exception;
                $planner->forgetReleasedFiles($shoot);
                return $this->holdForDeliveryReview($version);
            }
            $version->update(['status' => 'published', 'published_file_id' => $target->id, 'version' => $target->content_version,
                'snapshot' => Arr::only($target->attributesToArray(), self::CONTENT_FIELDS), 'error_code' => null, 'error' => null]);
            if ($version->dispatch_item_id) {
                foreach (\App\Models\ShootEditingDispatchItem::where('primary_version_id', $version->id)->get() as $item) {
                    $item->update(['status' => $item->destination === 'human' ? 'returned' : 'completed', 'error' => null]);
                }
            }
            return $version;
        }), 'media-version.publish');
        if ($result->status === 'published') {
            try {
                app(ShootMediaMutationSupportService::class)->clearShootFilesCache(\App\Models\Shoot::findOrFail($result->shoot_id));
                LockedWrite::run(fn () => DB::transaction(fn () => app(ShootMediaMutationSupportService::class)->refreshMediaCounters(\App\Models\Shoot::findOrFail($result->shoot_id))), 'media-version.counters');
            } catch (\Throwable $exception) {
                Log::error('Published media cache invalidation failed', ['version_id' => $result->id, 'exception' => $exception]);
            }
        }
        $this->syncDispatch($result);
        return $result;
    }

    public function syncDispatch(ShootFileVersion $version): void
    {
        if (!$version->dispatch_item_id) return;
        if (in_array($version->status, ['conflict', 'failed'], true)) \App\Models\ShootEditingDispatchItem::where('primary_version_id', $version->id)
            ->update(['status' => $version->status, 'error' => $version->error]);
        $item = \App\Models\ShootEditingDispatchItem::find($version->dispatch_item_id);
        if ($item) app(ScopedEditingDispatch::class)->reconcile($item->dispatch_id);
    }

    public function archiveCurrent(ShootFile $file, int $userId): ShootFileVersion
    {
        $prior = ShootFileVersion::where('published_file_id', $file->id)->where('version', $file->content_version)->first();
        if ($prior) {
            $prior->update(['status' => 'archived']);
            return $prior;
        }
        return ShootFileVersion::create(['shoot_id' => $file->shoot_id, 'source_file_id' => $file->id, 'target_file_id' => $file->id,
            'published_file_id' => $file->id, 'expected_version' => $file->content_version, 'version' => $file->content_version,
            'request_key' => 'baseline:'.$file->id.':'.$file->content_version, 'status' => 'archived', 'created_by' => $userId,
            'snapshot' => Arr::only($file->attributesToArray(), self::CONTENT_FIELDS)]);
    }

    public function resolve(ShootFileVersion $version, string $choice, int $expectedLatest, ?User $actor = null): ShootFileVersion
    {
        LockedWrite::run(fn () => DB::transaction(function () use ($version, $choice, $expectedLatest, $actor) {
            EditingDispatchWriteLock::acquire((int) $version->shoot_id);
            $locked = ShootFileVersion::lockForUpdate()->findOrFail($version->id);
            abort_unless(in_array($locked->status, ['conflict', 'alternative'], true), 409, 'This returned edit is no longer waiting for a decision.');
            $targetId = $locked->target_file_id;
            if (!$targetId && $locked->dispatch_item_id) {
                $primaryId = \App\Models\ShootEditingDispatchItem::find($locked->dispatch_item_id)?->primary_version_id;
                $targetId = $primaryId ? ShootFileVersion::find($primaryId)?->published_file_id : null;
            }
            $reviewed = ShootFile::findOrFail($targetId ?: $locked->source_file_id);
            abort_unless((int) $reviewed->shoot_id === (int) $locked->shoot_id, 409, 'The current image no longer belongs to this shoot.');
            $planner = app(DeliveryPlanService::class);
            $planner->forgetReleasedFiles($reviewed->shoot);
            if ($planner->releasedFile($reviewed) || $locked->error_code === 'delivery_review_required') {
                $actor = $actor?->fresh();
                abort_unless($actor && $planner->canFinalize($actor), 403, 'You do not have permission to approve changes to delivered content.');
                abort_unless((int) $reviewed->content_version === $expectedLatest, 409, 'The current image changed again. Refresh before reviewing it.');
                $locked->metadata = array_merge($locked->metadata ?? [], ['delivery_review' => [
                    'actor_id' => $actor->id, 'file_id' => $reviewed->id, 'content_version' => $expectedLatest,
                    'shoot_service_id' => $reviewed->shoot_service_id, 'reviewed_at' => now()->toIso8601String(),
                ]]);
            }
            if ($choice === 'save_copy') {
                $locked->target_file_id = null;
                $locked->metadata = array_merge($locked->metadata ?? [], ['save_as_copy' => true]);
            } else {
                abort_unless($choice === 'replace_latest', 422, 'Choose Replace latest or Save as copy.');
                $target = $reviewed;
                abort_unless($target->workflow_stage !== ShootFile::STAGE_TODO, 422, 'Raw originals cannot be replaced by an edit.');
                abort_unless((int) $target->content_version === $expectedLatest, 409, 'The current image changed again. Refresh before replacing it.');
                $locked->target_file_id = $target->id;
                $locked->expected_version = $expectedLatest;
            }
            $locked->status = 'ready';
            $locked->save();
        }), 'media-version.resolve');
        try {
            return $this->publish($version->fresh());
        } catch (\Throwable $exception) {
            Log::error('Reviewed media publication failed', ['version_id' => $version->id, 'exception' => $exception]);
            $saved = LockedWrite::run(fn () => DB::transaction(function () use ($version) {
                EditingDispatchWriteLock::acquire((int) $version->shoot_id);
                $locked = ShootFileVersion::lockForUpdate()->findOrFail($version->id);
                // Publication is atomic, but reconciliation can throw after it
                // commits. Never downgrade published or concurrently changed work.
                if ($locked->status === 'ready' && !$locked->published_file_id) {
                    $locked->update(['status' => 'failed', 'error_code' => 'processing_failed',
                        'error' => 'The saved edit could not be published. Retry the saved upload; the previous media is unchanged.']);
                }
                return $locked;
            }), 'media-version.resolve-recovery');
            if ($saved->status === 'published') return $saved;
            abort(503, $saved->status === 'failed' && $saved->error_code === 'processing_failed'
                ? $saved->error : 'The saved edit changed while publishing. Refresh to see its current status.');
        }
    }

    private function deliveryReviewer(ShootFileVersion $version, ShootFile $approved): ?User
    {
        $review = $version->metadata['delivery_review'] ?? null;
        if (is_array($review)) {
            if ((int) ($review['file_id'] ?? 0) !== (int) $approved->id
                || (int) ($review['content_version'] ?? 0) !== (int) $approved->content_version
                || (int) ($review['shoot_service_id'] ?? 0) !== (int) $approved->shoot_service_id) return null;
            $actor = User::find($review['actor_id'] ?? null);
        } elseif (in_array($version->metadata['origin'] ?? '', ['manual', 'restore', 'photoshop'], true)) {
            // Uploading a new video is not a review of its playback/audio. Staff
            // inspect the scanned saved version before replacing delivered bytes.
            if (($version->metadata['origin'] ?? '') === 'manual'
                && MediaVersionVideo::extension((string) ($version->snapshot['mime_type'] ?? ''))) return null;
            $actor = User::find($version->created_by);
        } else return null;
        return app(DeliveryPlanService::class)->canFinalize($actor) ? $actor : null;
    }

    private function holdForDeliveryReview(ShootFileVersion $version): ShootFileVersion
    {
        $version->update(['status' => 'conflict', 'error_code' => 'delivery_review_required',
            'error' => 'This changes delivered content. Review returned edit and choose Replace latest or Save as copy.']);
        return $version;
    }
}
