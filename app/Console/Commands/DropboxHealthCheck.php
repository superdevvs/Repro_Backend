<?php

namespace App\Console\Commands;

use App\Services\DropboxWorkflowService;
use Illuminate\Console\Command;

class DropboxHealthCheck extends Command
{
    protected $signature = 'dropbox:health
        {--path= : Optional Dropbox file path to probe (temporary link + streamed download).}
        {--folder= : Optional Dropbox folder path to probe via list_folder.}
        {--json : Output the full structured report as JSON for scripting.}';

    protected $description = 'Probe Dropbox token, account, folder listing, and per-file download latency. Use before debugging slow/failing shoot finalize.';

    public function handle(DropboxWorkflowService $dropbox): int
    {
        $probePath = $this->option('path') ?: null;
        $probeFolder = $this->option('folder') ?: null;

        $this->info('Running Dropbox health check…');
        if ($probePath) {
            $this->line("  • probe path:   {$probePath}");
        }
        if ($probeFolder) {
            $this->line("  • probe folder: {$probeFolder}");
        }
        $this->newLine();

        $report = $dropbox->healthCheck($probePath, $probeFolder);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $report['overall_success'] ? Command::SUCCESS : Command::FAILURE;
        }

        $this->line(sprintf(
            'enabled=%s  env=%s  verify_ssl=%s  http_timeout=%ss',
            $report['enabled'] ? 'yes' : 'no',
            $report['env'] ?? 'unknown',
            var_export($report['verify_ssl'] ?? null, true),
            $report['http_timeout_seconds'] ?? 'n/a'
        ));
        $this->newLine();

        $rows = [];
        foreach ($report['steps'] as $step) {
            $success = ($step['success'] ?? false) ? 'OK' : 'FAIL';
            $duration = isset($step['duration_ms']) ? $step['duration_ms'] . ' ms' : '—';
            $detail = $this->formatStepDetail($step);
            $rows[] = [$step['name'] ?? 'step', $success, $duration, $detail];
        }
        $this->table(['Step', 'Status', 'Duration', 'Detail'], $rows);

        $this->newLine();
        if ($report['overall_success']) {
            $this->info('✓ Dropbox is healthy.');
            return Command::SUCCESS;
        }

        $this->error('✗ Dropbox health check failed. See step detail above.');
        return Command::FAILURE;
    }

    private function formatStepDetail(array $step): string
    {
        if (!empty($step['error'])) {
            return 'error: ' . substr((string) $step['error'], 0, 200);
        }
        if ($step['name'] === 'resolve_access_token') {
            return 'token=' . ($step['token_preview'] ?? '—');
        }
        if ($step['name'] === 'account_info') {
            return sprintf(
                '%s <%s>',
                $step['account_name'] ?? 'unknown',
                $step['account_email'] ?? 'unknown'
            );
        }
        if ($step['name'] === 'list_folder') {
            return sprintf(
                'path=%s entries=%d',
                $step['path'] ?? '—',
                $step['entry_count'] ?? 0
            );
        }
        if ($step['name'] === 'get_temporary_link') {
            return sprintf(
                'path=%s link=%s',
                $step['path'] ?? '—',
                !empty($step['link_present']) ? 'yes' : 'no'
            );
        }
        if ($step['name'] === 'download_probe') {
            return sprintf(
                '%.2f MB @ %.2f MB/s',
                $step['megabytes'] ?? 0,
                $step['throughput_mb_per_sec'] ?? 0
            );
        }
        return '—';
    }
}
