<?php

namespace App\Services\Shoots;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ShootStatusFilter
{
    private const STATUSES = [
        'active' => ['Active', ['active']],
        'requested' => ['Requested', ['requested']],
        'scheduled' => ['Scheduled', ['scheduled', 'booked', 'raw_upload_pending']],
        'confirmed' => ['Confirmed', ['confirmed']],
        'in_field' => ['In Field', ['in_field']],
        'uploading' => ['Uploading', ['uploading']],
        'uploaded' => ['Uploaded', ['uploaded', 'completed', 'raw_uploaded', 'raw_issue', 'in_progress', 'photos_uploaded']],
        'editing' => ['Editing', ['editing', 'editing_uploaded', 'editing_issue']],
        'review' => ['Pending Review', ['review', 'pending_review', 'ready_for_review', 'editing_complete', 'qc']],
        'ready' => ['Ready', ['ready']],
        'delivered' => ['Delivered', ['delivered', 'ready_for_client', 'admin_verified', 'workflow_completed', 'client_delivered']],
        'on_hold' => ['On Hold', ['on_hold', 'hold_on']],
        'cancelled' => ['Cancelled', ['cancelled', 'canceled']],
        'declined' => ['Declined', ['declined']],
    ];

    public function options(): array
    {
        $options = [];
        foreach (self::STATUSES as $value => [$label]) {
            $options[] = compact('value', 'label');
        }

        return $options;
    }

    public function values(): array
    {
        return array_keys(self::STATUSES);
    }

    public function apply(Builder $query, array $statuses): void
    {
        if ($statuses === []) {
            return;
        }

        $aliases = [];
        foreach ($statuses as $status) {
            $aliases = array_merge($aliases, self::STATUSES[$status][1]);
        }

        // The active workflow takes precedence over legacy status. Filtering
        // the SQL query keeps pagination and counts consistent with the cards.
        $query->where(function (Builder $scope) use ($aliases, $statuses) {
            $scope->whereIn(DB::raw("LOWER(COALESCE(NULLIF(TRIM(workflow_status), ''), status, ''))"), array_unique($aliases));
            if (in_array('active', $statuses, true)) {
                $scope->orWhere(fn (Builder $active) => $active->whereNotNull('active_work_started_at')->whereNull('active_work_completed_at')
                    ->whereNotIn(DB::raw("LOWER(COALESCE(NULLIF(workflow_status, ''), status, ''))"), ['on_hold', 'hold_on', 'cancelled', 'canceled', 'declined']));
            }
        });
    }
}
