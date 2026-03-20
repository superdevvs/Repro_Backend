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
        'shoot_updated',
        'shoot_deleted',
        'shoot_put_on_hold',
        'shoot_resumed_from_hold',
        'hold_requested',
        'hold_approved',
        'hold_rejected',
        'shoot_editing_started',
        'shoot_submitted_for_review',
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
            if (in_array($action, $this->broadcastableActions)) {
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

    /**
     * Generate human-readable description from action and metadata
     */
    protected function generateDescription(string $action, array $metadata): string
    {
        $by = isset($metadata['by']) && $metadata['by'] ? " by {$metadata['by']}" : '';

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
            'shoot_completed' => 'Shoot completed' . $by,
            'shoot_delivered' => 'Shoot delivered to client' . $by,
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
            'media_uploaded' => 'Media uploaded' . (isset($metadata['file_count']) && $metadata['file_count'] ? ": {$metadata['file_count']} files" : ''),
            'media_upload_initiated' => 'Media upload started' . (isset($metadata['file_count']) && $metadata['file_count'] ? ": {$metadata['file_count']} files" : '') . (isset($metadata['type']) && $metadata['type'] ? " ({$metadata['type']})" : ''),
            'album_created' => 'Album created' . (isset($metadata['album_name']) && $metadata['album_name'] ? ": {$metadata['album_name']}" : ''),
            'raw_downloaded_by_editor' => 'Raw files downloaded by editor' . (isset($metadata['editor_name']) && $metadata['editor_name'] ? " ({$metadata['editor_name']})" : '') . (isset($metadata['file_count']) && $metadata['file_count'] ? ": {$metadata['file_count']} files" : ''),

            // Sharing
            'share_link_generated' => 'Share link generated' . (isset($metadata['editor_name']) && $metadata['editor_name'] ? " by {$metadata['editor_name']}" : '') . (isset($metadata['file_count']) && $metadata['file_count'] ? " for {$metadata['file_count']} files" : ''),
            'share_link_revoked' => 'Share link revoked' . $by,

            // Notes
            'note_added' => 'Note added' . $by . (isset($metadata['note_type']) && $metadata['note_type'] ? " ({$metadata['note_type']})" : ''),

            // Emails
            'shoot_scheduled_email' => 'Scheduled email sent' . (isset($metadata['to']) && $metadata['to'] ? " to {$metadata['to']}" : ''),
            'reminder_sent' => 'Reminder sent' . (isset($metadata['type']) && $metadata['type'] ? " ({$metadata['type']})" : ''),

            // Private listing
            'private_listing_marked' => 'Marked as Private Exclusive' . $by,
            'private_listing_unmarked' => 'Removed Private Exclusive status' . $by,
        ];

        return $descriptions[$action] ?? ucfirst(str_replace('_', ' ', $action));
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

