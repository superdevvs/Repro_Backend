<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('shoots')
            ->orderBy('id')
            ->chunkById(200, function ($shoots) use ($now) {
                $ids = $shoots->pluck('id')->all();

                $stageCounts = [];
                $rows = DB::table('shoot_files')
                    ->select('shoot_id', 'workflow_stage', DB::raw('COUNT(*) as c'))
                    ->whereIn('shoot_id', $ids)
                    ->groupBy('shoot_id', 'workflow_stage')
                    ->get();

                foreach ($rows as $row) {
                    $shootId = (string) $row->shoot_id;
                    $stage = strtolower((string) $row->workflow_stage);
                    if (!isset($stageCounts[$shootId])) {
                        $stageCounts[$shootId] = [];
                    }
                    $stageCounts[$shootId][$stage] = (int) $row->c;
                }

                $normalize = static function (?string $value): ?string {
                    if ($value === null) {
                        return null;
                    }

                    $v = strtolower(trim($value));
                    if ($v === '') {
                        return null;
                    }

                    $map = [
                        // scheduled
                        'booked' => 'scheduled',
                        'raw_upload_pending' => 'scheduled',

                        // completed (photographer finished upload)
                        'raw_uploaded' => 'completed',
                        'photos_uploaded' => 'completed',
                        'in_progress' => 'completed',

                        // uploaded (raw under review)
                        'raw_issue' => 'uploaded',

                        // review (edited under review)
                        'editing_uploaded' => 'review',
                        'editing_complete' => 'review',
                        'editing_issue' => 'review',
                        'pending_review' => 'review',
                        'ready_for_review' => 'review',
                        'qc' => 'review',

                        // delivered
                        'ready_for_client' => 'delivered',
                        'admin_verified' => 'delivered',
                        'ready' => 'delivered',

                        // on-hold
                        'hold' => 'on_hold',
                        'hold_on' => 'on_hold',

                        // cancelled
                        'canceled' => 'cancelled',
                    ];

                    return $map[$v] ?? $v;
                };

                foreach ($shoots as $shoot) {
                    $shootId = (string) $shoot->id;

                    $status = $normalize($shoot->status ?? null);
                    $workflow = $normalize($shoot->workflow_status ?? null);

                    $counts = $stageCounts[$shootId] ?? [];
                    $hasTodo = (($counts['todo'] ?? 0) > 0);
                    $hasCompletedFiles = (($counts['completed'] ?? 0) > 0);
                    $hasVerifiedFiles = (($counts['verified'] ?? 0) > 0);

                    $isFlagged = (bool) ($shoot->is_flagged ?? false);

                    $primary = $status ?? $workflow;

                    $target = null;

                    // Prefer hard signals
                    if ($hasVerifiedFiles || !empty($shoot->admin_verified_at) || !empty($shoot->completed_at)) {
                        $target = 'delivered';
                    } elseif (in_array($primary, ['cancelled'], true)) {
                        $target = 'cancelled';
                    } elseif ($isFlagged || in_array($primary, ['on_hold'], true)) {
                        $target = 'on_hold';
                    } elseif (in_array($primary, ['delivered'], true)) {
                        $target = 'delivered';
                    } elseif (in_array($primary, ['review'], true)) {
                        $target = 'review';
                    } elseif (in_array($primary, ['editing'], true)) {
                        $target = 'editing';
                    } elseif (in_array($primary, ['uploaded'], true)) {
                        $target = 'uploaded';
                    } elseif (in_array($primary, ['completed'], true)) {
                        $target = 'completed';
                    } elseif (in_array($primary, ['scheduled'], true)) {
                        $target = 'scheduled';
                    }

                    // Heuristics when primary is missing/unknown
                    if ($target === null) {
                        if ($hasVerifiedFiles) {
                            $target = 'delivered';
                        } elseif ($isFlagged) {
                            $target = 'on_hold';
                        } elseif ($hasCompletedFiles) {
                            // Edited files exist
                            $target = !empty($shoot->submitted_for_review_at) ? 'review' : 'editing';
                        } elseif ($hasTodo) {
                            // Raw files exist
                            $target = !empty($shoot->submitted_for_review_at) ? 'uploaded' : 'completed';
                        } else {
                            $target = 'scheduled';
                        }
                    }

                    DB::table('shoots')
                        ->where('id', $shoot->id)
                        ->update([
                            'status' => $target,
                            'workflow_status' => $target,
                            'updated_at' => $now,
                        ]);
                }
            });
    }

    public function down(): void
    {
        // No safe automatic rollback for status normalization.
    }
};
