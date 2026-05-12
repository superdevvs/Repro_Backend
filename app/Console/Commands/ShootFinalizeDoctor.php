<?php

namespace App\Console\Commands;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\WorkflowLog;
use App\Services\DropboxWorkflowService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ShootFinalizeDoctor extends Command
{
    protected $signature = 'shoot:finalize-doctor
        {shoot : The shoot ID to diagnose.}
        {--logs=15 : How many recent workflow log entries to show.}
        {--probe : Also probe Dropbox temporary link + streamed download for the first completed file.}
        {--json : Output the full structured report as JSON.}';

    protected $description = 'Diagnose a shoot finalize run: show status, file stage counts, recent workflow logs, and optional Dropbox probe.';

    public function handle(DropboxWorkflowService $dropbox): int
    {
        $shootId = (int) $this->argument('shoot');
        $logLimit = max(1, (int) $this->option('logs'));

        /** @var Shoot|null $shoot */
        $shoot = Shoot::query()->find($shootId);
        if (!$shoot) {
            $this->error("Shoot {$shootId} not found.");
            return Command::FAILURE;
        }

        $report = [
            'shoot_id' => $shoot->id,
            'workflow_status' => $shoot->workflow_status,
            'status' => $shoot->status,
            'delivery_status' => $shoot->delivery_status ?? null,
            'photos_uploaded_at' => optional($shoot->photos_uploaded_at)->toIso8601String(),
            'editing_completed_at' => optional($shoot->editing_completed_at)->toIso8601String(),
            'admin_verified_at' => optional($shoot->admin_verified_at)->toIso8601String(),
            'completed_at' => optional($shoot->completed_at)->toIso8601String(),
            'file_counts' => $this->buildFileCounts($shoot),
            'local_cache' => $this->buildLocalCacheReport($shoot),
            'recent_workflow_logs' => $this->buildRecentLogs($shoot, $logLimit),
        ];

        if ($this->option('probe')) {
            $report['dropbox_probe'] = $this->probeFirstCompletedFile($shoot, $dropbox);
        }

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        $this->renderHuman($report);
        return Command::SUCCESS;
    }

    private function buildFileCounts(Shoot $shoot): array
    {
        $rows = ShootFile::query()
            ->where('shoot_id', $shoot->id)
            ->selectRaw('workflow_stage, count(*) as c')
            ->groupBy('workflow_stage')
            ->pluck('c', 'workflow_stage')
            ->all();

        return [
            'todo' => (int) ($rows[ShootFile::STAGE_TODO] ?? 0),
            'completed' => (int) ($rows[ShootFile::STAGE_COMPLETED] ?? 0),
            'verified' => (int) ($rows[ShootFile::STAGE_VERIFIED] ?? 0),
            'archived' => (int) ($rows[ShootFile::STAGE_ARCHIVED] ?? 0),
            'flagged' => (int) ($rows[ShootFile::STAGE_FLAGGED] ?? 0),
            'null_stage' => ShootFile::query()->where('shoot_id', $shoot->id)->whereNull('workflow_stage')->count(),
            'total' => ShootFile::query()->where('shoot_id', $shoot->id)->count(),
        ];
    }

    private function buildLocalCacheReport(Shoot $shoot): array
    {
        $relevantFiles = ShootFile::query()
            ->where('shoot_id', $shoot->id)
            ->whereIn('workflow_stage', [ShootFile::STAGE_COMPLETED, ShootFile::STAGE_VERIFIED])
            ->get(['id', 'filename', 'stored_filename', 'workflow_stage', 'path', 'dropbox_path']);

        $hasDropbox = 0;
        $hasLocalFinal = 0;
        $localMissing = [];
        $disk = Storage::disk('public');

        foreach ($relevantFiles as $file) {
            if (!empty($file->dropbox_path)) {
                $hasDropbox++;
            }
            $isFinalPath = is_string($file->path) && str_starts_with($file->path, "shoots/{$shoot->id}/final/");
            if ($isFinalPath) {
                if ($disk->exists($file->path)) {
                    $hasLocalFinal++;
                } else {
                    $localMissing[] = [
                        'id' => $file->id,
                        'filename' => $file->filename,
                        'path' => $file->path,
                        'dropbox_path' => $file->dropbox_path,
                    ];
                }
            }
        }

        return [
            'relevant_file_count' => $relevantFiles->count(),
            'with_dropbox_path' => $hasDropbox,
            'with_local_final_and_exists' => $hasLocalFinal,
            'local_final_missing_count' => count($localMissing),
            'local_final_missing_sample' => array_slice($localMissing, 0, 10),
        ];
    }

    private function buildRecentLogs(Shoot $shoot, int $limit): array
    {
        return WorkflowLog::query()
            ->where('shoot_id', $shoot->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'action', 'details', 'metadata', 'user_id', 'created_at'])
            ->map(function (WorkflowLog $log) {
                return [
                    'id' => $log->id,
                    'action' => $log->action,
                    'details' => $log->details,
                    'user_id' => $log->user_id,
                    'created_at' => optional($log->created_at)->toIso8601String(),
                    'metadata' => $log->metadata,
                ];
            })
            ->all();
    }

    private function probeFirstCompletedFile(Shoot $shoot, DropboxWorkflowService $dropbox): array
    {
        /** @var ShootFile|null $file */
        $file = ShootFile::query()
            ->where('shoot_id', $shoot->id)
            ->whereIn('workflow_stage', [ShootFile::STAGE_COMPLETED, ShootFile::STAGE_VERIFIED])
            ->whereNotNull('dropbox_path')
            ->orderBy('id')
            ->first();

        if (!$file) {
            return [
                'probed' => false,
                'reason' => 'No completed/verified file with dropbox_path found on this shoot.',
            ];
        }

        $report = $dropbox->healthCheck($file->dropbox_path);
        return [
            'probed' => true,
            'file_id' => $file->id,
            'filename' => $file->filename,
            'dropbox_path' => $file->dropbox_path,
            'result' => $report,
        ];
    }

    private function renderHuman(array $report): void
    {
        $this->info("Shoot #{$report['shoot_id']} finalize diagnostics");
        $this->line(sprintf(
            'status=%s  workflow_status=%s  delivery_status=%s',
            $report['status'] ?? '—',
            $report['workflow_status'] ?? '—',
            $report['delivery_status'] ?? '—'
        ));
        $this->line(sprintf(
            'photos_uploaded_at=%s  editing_completed_at=%s  admin_verified_at=%s  completed_at=%s',
            $report['photos_uploaded_at'] ?? '—',
            $report['editing_completed_at'] ?? '—',
            $report['admin_verified_at'] ?? '—',
            $report['completed_at'] ?? '—'
        ));
        $this->newLine();

        $this->info('File stage counts:');
        $fc = $report['file_counts'];
        $this->table(
            ['todo', 'completed', 'verified', 'archived', 'flagged', 'null_stage', 'total'],
            [[$fc['todo'], $fc['completed'], $fc['verified'], $fc['archived'], $fc['flagged'], $fc['null_stage'], $fc['total']]]
        );

        $this->info('Local final cache (completed + verified files):');
        $lc = $report['local_cache'];
        $this->table(
            ['relevant_files', 'with_dropbox_path', 'local_final_present', 'local_final_missing'],
            [[$lc['relevant_file_count'], $lc['with_dropbox_path'], $lc['with_local_final_and_exists'], $lc['local_final_missing_count']]]
        );

        if (!empty($lc['local_final_missing_sample'])) {
            $this->warn('Local final files missing on disk (first 10):');
            $rows = array_map(
                fn ($f) => [$f['id'], $f['filename'], $f['path'], $f['dropbox_path'] ?? '—'],
                $lc['local_final_missing_sample']
            );
            $this->table(['id', 'filename', 'path', 'dropbox_path'], $rows);
        }

        $this->info("Recent workflow logs (most recent first):");
        $logRows = array_map(function ($log) {
            $action = $log['action'] ?? '';
            $meta = $log['metadata'] ?? [];
            $detailExtra = '';
            if (is_array($meta)) {
                $keep = array_intersect_key($meta, array_flip([
                    'error', 'processed_files', 'total_files', 'final_status', 'current_status',
                    'shoot_service_id', 'full_order_delivery', 'failed_at', 'completed_at', 'started_at',
                ]));
                if (!empty($keep)) {
                    $detailExtra = ' | ' . json_encode($keep, JSON_UNESCAPED_SLASHES);
                }
            }
            return [
                $log['id'] ?? '—',
                $log['created_at'] ?? '—',
                $action,
                substr(($log['details'] ?? '') . $detailExtra, 0, 160),
            ];
        }, $report['recent_workflow_logs']);
        if (empty($logRows)) {
            $this->line('  (no workflow logs yet for this shoot)');
        } else {
            $this->table(['id', 'created_at', 'action', 'details'], $logRows);
        }

        if (isset($report['dropbox_probe'])) {
            $this->info('Dropbox probe on first completed file:');
            $probe = $report['dropbox_probe'];
            if (!($probe['probed'] ?? false)) {
                $this->warn('  ' . ($probe['reason'] ?? 'probe skipped'));
                return;
            }
            $this->line(sprintf('  file_id=%d  filename=%s', $probe['file_id'], $probe['filename']));
            $this->line('  dropbox_path=' . $probe['dropbox_path']);
            $result = $probe['result'] ?? [];
            foreach (($result['steps'] ?? []) as $step) {
                $this->line(sprintf(
                    '    [%s] %s  %s ms  %s',
                    ($step['success'] ?? false) ? 'OK  ' : 'FAIL',
                    str_pad((string) ($step['name'] ?? '—'), 22),
                    $step['duration_ms'] ?? '—',
                    isset($step['error']) ? ('error: ' . substr((string) $step['error'], 0, 200)) : $this->shortProbeDetail($step)
                ));
            }
            $this->line('  overall_success=' . (!empty($result['overall_success']) ? 'yes' : 'no'));
        }
    }

    private function shortProbeDetail(array $step): string
    {
        return match ($step['name'] ?? '') {
            'download_probe' => sprintf('%.2f MB @ %.2f MB/s', $step['megabytes'] ?? 0, $step['throughput_mb_per_sec'] ?? 0),
            'get_temporary_link' => 'link=' . (!empty($step['link_present']) ? 'yes' : 'no'),
            'account_info' => ($step['account_email'] ?? '—'),
            default => '',
        };
    }
}
