<?php

namespace App\Services;

use App\Events\ShootActivityBroadcast;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ShootActivityLogger
{
    /**
     * Actions that should trigger real-time broadcast notifications
     */
    protected array $broadcastableActions = [
        'shoot_requested',
        'shoot_created',
        'shoot_scheduled',
        'shoot_approved',
        'shoot_declined',
        'shoot_started',
        'shoot_completed',
        'shoot_delivered',
        'shoot_cancelled',
        'cancellation_requested',
        'cancellation_rejected',
        'shoot_updated',
        'shoot_deleted',
        'shoot_put_on_hold',
        'shoot_resumed_from_hold',
        'hold_requested',
        'hold_approved',
        'hold_rejected',
        'shoot_editing_started',
        'shoot_submitted_for_review',
        'shoot_submitted_edited',
        'shoot_submitted_raw',
        'photographer_assigned',
        'editor_assigned',
        'payment_done',
        'payment_received',
        'payment_completed',
        'payment_failed',
        'payment_refunded',
        'payment_marked_paid',
        'media_uploaded',
        'media_upload_initiated',
        'shoot_finalized_delivered',
        'bright_mls_synced',
        'tour_links_generated',
        'hero_image_updated',
        'raw_downloaded_by_editor',
        'share_link_generated',
        'share_link_revoked',
        'rescheduled',
    ];

    /**
     * Log an activity for a shoot
     *
     * @param Shoot $shoot
     * @param string $action Action identifier (e.g., 'shoot_scheduled_email', 'payment_done', 'media_uploaded')
     * @param array $metadata Additional context data
     * @param User|null $user User who performed the action (defaults to authenticated user)
     * @return \App\Models\ShootActivityLog
     */
    public function log(Shoot $shoot, string $action, array $metadata = [], ?User $user = null): \App\Models\ShootActivityLog
    {
        return DB::transaction(function () use ($shoot, $action, $metadata, $user) {
            $description = $this->generateDescription($action, $metadata);

            $activityLog = $shoot->activityLogs()->create([
                'user_id' => $user?->id ?? auth()->id(),
                'action' => $action,
                'description' => $description,
                'metadata' => $metadata,
            ]);

            // Fire broadcast event for real-time notifications
            $shouldSuppressNotifications = (bool) ($metadata['suppress_notifications'] ?? false);
            if (!$shouldSuppressNotifications && in_array($action, $this->broadcastableActions)) {
                try {
                    event(new ShootActivityBroadcast(
                        $shoot,
                        $action,
                        $description,
                        $metadata,
                        $user?->id ?? auth()->id()
                    ));
                } catch (\Exception $e) {
                    // Log but don't fail if broadcast fails
                    \Log::warning('Failed to broadcast shoot activity: ' . $e->getMessage());
                }
            }

            return $activityLog;
        });
    }

    public function logMediaUploaded(Shoot $shoot, array $metadata = [], ?User $user = null): \App\Models\ShootActivityLog
    {
        $batchId = trim((string) ($metadata['upload_batch_id'] ?? ''));

        if ($batchId === '') {
            return $this->log($shoot, 'media_uploaded', $metadata, $user);
        }

        return DB::transaction(function () use ($shoot, $metadata, $user, $batchId) {
            $userId = $user?->id ?? auth()->id();
            $existingLog = $shoot->activityLogs()
                ->where('action', 'media_uploaded')
                ->where('user_id', $userId)
                ->where('created_at', '>=', now()->subHours(2))
                ->latest('id')
                ->get()
                ->first(function ($log) use ($batchId) {
                    $existingMetadata = is_array($log->metadata) ? $log->metadata : [];

                    return (string) ($existingMetadata['upload_batch_id'] ?? '') === $batchId;
                });

            if (!$existingLog) {
                return $this->log($shoot, 'media_uploaded', $metadata, $user);
            }

            $mergedMetadata = $this->mergeMediaUploadMetadata(
                is_array($existingLog->metadata) ? $existingLog->metadata : [],
                $metadata
            );

            $existingLog->forceFill([
                'description' => $this->generateDescription('media_uploaded', $mergedMetadata),
                'metadata' => $mergedMetadata,
            ])->save();

            return $existingLog;
        });
    }

    protected function mergeMediaUploadMetadata(array $existingMetadata, array $incomingMetadata): array
    {
        $existingFileIds = $this->normalizeMetadataList($existingMetadata['file_ids'] ?? []);
        $incomingFileIds = $this->normalizeMetadataList($incomingMetadata['file_ids'] ?? []);
        $fileIds = array_values(array_unique(array_merge($existingFileIds, $incomingFileIds)));

        $existingFilenames = $this->normalizeMetadataList($existingMetadata['filenames'] ?? []);
        $incomingFilenames = $this->normalizeMetadataList($incomingMetadata['filenames'] ?? []);
        $filenames = array_values(array_unique(array_merge($existingFilenames, $incomingFilenames)));

        $existingCount = (int) ($existingMetadata['file_count'] ?? count($existingFileIds));
        $incomingCount = (int) ($incomingMetadata['file_count'] ?? count($incomingFileIds));
        $fileCount = count($fileIds) > 0 ? count($fileIds) : $existingCount + $incomingCount;

        return array_merge($existingMetadata, $incomingMetadata, [
            'file_count' => $fileCount,
            'file_ids' => $fileIds,
            'filenames' => $filenames,
        ]);
    }

    protected function normalizeMetadataList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, fn ($item) => $item !== null && $item !== ''));
    }

    /**
     * Generate human-readable description from action and metadata
     */
    protected function generateDescription(string $action, array $metadata): string
    {
        $by = isset($metadata['by']) && $metadata['by'] ? " by {$metadata['by']}" : '';
        $uploadedByRole = $metadata['uploaded_by_role'] ?? $metadata['role'] ?? null;
        $uploadType = $metadata['type'] ?? $metadata['media_type'] ?? null;
        $fileCount = $metadata['file_count'] ?? (isset($metadata['file_id']) ? 1 : null);
        $finalizedByRole = $metadata['finalized_by_role'] ?? $metadata['role'] ?? null;

        $descriptions = [
            // Shoot lifecycle
            'shoot_requested' => 'Shoot requested' . $by,
            'shoot_created' => 'Shoot created' . $by,
            'shoot_scheduled' => 'Shoot scheduled' . (isset($metadata['scheduled_at']) ? " for {$metadata['scheduled_at']}" : '') . $by,
            'shoot_approved' => 'Shoot approved' . $by . (isset($metadata['scheduled_at']) ? " — scheduled for {$metadata['scheduled_at']}" : ''),
            'shoot_declined' => 'Shoot declined' . $by . (isset($metadata['reason']) && $metadata['reason'] ? ": {$metadata['reason']}" : ''),
            'shoot_started' => 'Shoot started' . $by,
            'shoot_editing_started' => 'Editing started' . $by,
            'shoot_submitted_for_review' => 'Submitted for review' . $by,
            'shoot_submitted_edited' => 'Edited files submitted to editing manager'
                . $by
                . (isset($metadata['edited_photo_count']) && $metadata['edited_photo_count']
                    ? " ({$metadata['edited_photo_count']} files)"
                    : ''),
            'shoot_submitted_raw' => 'Raw files submitted for editing'
                . $by
                . (isset($metadata['raw_photo_count']) && $metadata['raw_photo_count']
                    ? " ({$metadata['raw_photo_count']} files)"
                    : ''),
            'shoot_completed' => 'Shoot completed' . $by,
            'shoot_delivered' => 'Shoot delivered to client' . $by,
            'shoot_finalized_delivered' => 'Shoot has been finalized and delivered'
                . ($finalizedByRole ? " by {$finalizedByRole}" : $by),
            'shoot_resumed_from_hold' => 'Shoot resumed from hold' . $by,
            'shoot_updated' => 'Shoot updated' . $by . (isset($metadata['changes']) && is_array($metadata['changes']) ? ': ' . implode(', ', array_keys($metadata['changes'])) : ''),
            'shoot_deleted' => 'Shoot deleted' . $by,
            'rescheduled' => 'Shoot rescheduled' . $by . (isset($metadata['new_date']) ? " to {$metadata['new_date']}" : ''),

            // Hold
            'shoot_put_on_hold' => 'Shoot put on hold' . $by . (isset($metadata['reason']) && $metadata['reason'] ? ": {$metadata['reason']}" : ''),
            'hold_requested' => 'Hold requested' . $by . (isset($metadata['reason']) && $metadata['reason'] ? ": {$metadata['reason']}" : ''),
            'hold_approved' => 'Hold approved' . $by . (isset($metadata['reason']) && $metadata['reason'] ? ": {$metadata['reason']}" : ''),
            'hold_rejected' => 'Hold request rejected' . $by . (isset($metadata['rejection_reason']) && $metadata['rejection_reason'] ? ": {$metadata['rejection_reason']}" : ''),

            // Cancellation
            'shoot_cancelled' => 'Shoot cancelled' . $by . (isset($metadata['reason']) && $metadata['reason'] ? ": {$metadata['reason']}" : ''),
            'cancellation_requested' => 'Cancellation requested' . $by . (isset($metadata['reason']) && $metadata['reason'] ? ": {$metadata['reason']}" : ''),
            'cancellation_rejected' => 'Cancellation request rejected' . $by . (isset($metadata['rejection_reason']) && $metadata['rejection_reason'] ? ": {$metadata['rejection_reason']}" : ''),

            // Assignments
            'photographer_assigned' => 'Photographer assigned' . (isset($metadata['photographer_name']) && $metadata['photographer_name'] ? ": {$metadata['photographer_name']}" : '') . $by,
            'editor_assigned' => 'Editor assigned' . (isset($metadata['editor_name']) && $metadata['editor_name'] ? ": {$metadata['editor_name']}" : '') . $by,

            // Payments
            'payment_done' => 'Payment received' . (isset($metadata['amount']) && $metadata['amount'] ? ": $" . number_format($metadata['amount'], 2) : ''),
            'payment_received' => 'Payment received' . (isset($metadata['amount']) && $metadata['amount'] ? ": $" . number_format($metadata['amount'], 2) : '') . (isset($metadata['method']) && $metadata['method'] ? " via {$metadata['method']}" : ''),
            'payment_completed' => 'Payment completed — shoot fully paid' . (isset($metadata['total']) && $metadata['total'] ? " ($" . number_format($metadata['total'], 2) . ")" : ''),
            'payment_failed' => 'Payment failed' . (isset($metadata['error']) && $metadata['error'] ? ": {$metadata['error']}" : ''),
            'payment_refunded' => 'Payment refunded' . (isset($metadata['amount']) && $metadata['amount'] ? ": $" . number_format($metadata['amount'], 2) : ''),
            'payment_marked_paid' => 'Shoot marked as paid' . $by . (isset($metadata['amount']) && $metadata['amount'] ? " ($" . number_format($metadata['amount'], 2) . ")" : ''),
            'payment_completion_email' => 'Payment completion email sent',
            'payment_completion_email_sent' => 'Payment confirmation email sent' . (isset($metadata['recipient']) && $metadata['recipient'] ? " to {$metadata['recipient']}" : ''),

            // Media & files
            'media_uploaded' => $uploadedByRole
                ? 'Media uploaded by ' . $uploadedByRole
                    . ($fileCount ? ": {$fileCount} " . ((int) $fileCount === 1 ? 'file' : 'files') : '')
                    . ($uploadType ? " ({$uploadType})" : '')
                : 'Media uploaded' . ($fileCount ? ": {$fileCount} " . ((int) $fileCount === 1 ? 'file' : 'files') : ''),
            'media_upload_initiated' => 'Media upload started' . (isset($metadata['file_count']) && $metadata['file_count'] ? ": {$metadata['file_count']} files" : '') . (isset($metadata['type']) && $metadata['type'] ? " ({$metadata['type']})" : ''),
            'hero_image_updated' => 'Hero image updated' . $by . (isset($metadata['filename']) && $metadata['filename'] ? ": {$metadata['filename']}" : ''),
            'album_created' => 'Album created' . (isset($metadata['album_name']) && $metadata['album_name'] ? ": {$metadata['album_name']}" : ''),
            'raw_downloaded_by_editor' => 'Raw files downloaded by editor' . (isset($metadata['editor_name']) && $metadata['editor_name'] ? " ({$metadata['editor_name']})" : '') . (isset($metadata['file_count']) && $metadata['file_count'] ? ": {$metadata['file_count']} files" : ''),

            // Sharing
            'share_link_generated' => 'Share link generated' . (isset($metadata['editor_name']) && $metadata['editor_name'] ? " by {$metadata['editor_name']}" : '') . (isset($metadata['file_count']) && $metadata['file_count'] ? " for {$metadata['file_count']} files" : ''),
            'share_link_revoked' => 'Share link revoked' . $by,
            'bright_mls_synced' => 'Media has been synced to Bright MLS',
            'tour_links_generated' => 'Tour links have been generated',

            // Notes
            'note_added' => 'Note added' . $by . (isset($metadata['note_type']) && $metadata['note_type'] ? " ({$metadata['note_type']})" : ''),

            // Emails
            'shoot_scheduled_email' => 'Scheduled email sent' . (isset($metadata['to']) && $metadata['to'] ? " to {$metadata['to']}" : ''),
            'reminder_sent' => 'Reminder sent' . (isset($metadata['type']) && $metadata['type'] ? " ({$metadata['type']})" : ''),

            // Private listing
            'private_listing_marked' => 'Marked as Private Exclusive' . $by,
            'private_listing_unmarked' => 'Removed Private Exclusive status' . $by,
            'featured_shoot_marked' => 'Marked as Featured Shoot' . $by,
            'featured_shoot_unmarked' => 'Removed Featured Shoot status' . $by,
        ];

        return $descriptions[$action] ?? ucfirst(str_replace('_', ' ', $action));
    }

    public function describe(string $action, array $metadata = []): string
    {
        return $this->generateDescription($action, $metadata);
    }

    /**
     * Get activity logs for a shoot, optionally filtered
     */
    public function getLogs(Shoot $shoot, array $filters = []): \Illuminate\Database\Eloquent\Collection
    {
        $query = $shoot->activityLogs()->with('user')->orderBy('created_at', 'desc');

        if (isset($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        if (isset($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }

        if (isset($filters['to'])) {
            $query->where('created_at', '<=', $filters['to']);
        }

        return $query->get();
    }
}

