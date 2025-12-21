<?php

namespace App\Services;

use App\Models\Shoot;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ShootActivityLogger
{
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

            return $shoot->activityLogs()->create([
                'user_id' => $user?->id ?? auth()->id(),
                'action' => $action,
                'description' => $description,
                'metadata' => $metadata,
            ]);
        });
    }

    /**
     * Generate human-readable description from action and metadata
     */
    protected function generateDescription(string $action, array $metadata): string
    {
        $descriptions = [
            'shoot_scheduled' => 'Shoot scheduled' . (isset($metadata['scheduled_at']) ? $metadata['scheduled_at'] : ''),
            'shoot_scheduled_email' => 'Scheduled email sent' . (isset($metadata['to']) && $metadata['to'] ? " to {$metadata['to']}" : ''),
            'shoot_started' => 'Shoot started',
            'shoot_editing_started' => 'Editing started',
            'shoot_submitted_for_review' => 'Submitted for review',
            'shoot_completed' => 'Shoot completed',
            'shoot_put_on_hold' => 'Shoot put on hold' . (isset($metadata['reason']) && $metadata['reason'] ? ": {$metadata['reason']}" : ''),
            'shoot_cancelled' => 'Shoot cancelled' . (isset($metadata['reason']) && $metadata['reason'] ? ": {$metadata['reason']}" : ''),
            'payment_done' => 'Payment received' . (isset($metadata['amount']) && $metadata['amount'] ? ": $" . number_format($metadata['amount'], 2) : ''),
            'payment_completion_email' => 'Payment completion email sent',
            'media_uploaded' => 'Media uploaded' . (isset($metadata['file_count']) && $metadata['file_count'] ? ": {$metadata['file_count']} files" : ''),
            'reminder_sent' => 'Reminder sent' . (isset($metadata['type']) && $metadata['type'] ? " ({$metadata['type']})" : ''),
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

