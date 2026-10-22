<?php

namespace App\Console\Commands;

use App\Services\Media\EditorArchiveRetention;
use Illuminate\Console\Command;

class PruneEditorArchives extends Command
{
    protected $signature = 'media:prune-editor-archives {--apply : Delete eligible ZIP caches after the one-time existing-link grace} {--json : Emit the complete decision manifest as JSON}';

    protected $description = 'Preview or prune inactive editor ZIP caches, preserving originals, active shoots, signed links and running downloads';

    public function handle(EditorArchiveRetention $retention): int
    {
        try {
            $report = $retention->run((bool) $this->option('apply'));
        } catch (\Throwable $exception) {
            $this->error('Cleanup did not finish: '.$exception->getMessage());

            return self::FAILURE;
        }
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->info(sprintf('%s: %d eligible ZIPs (%d bytes); %d deleted (%d bytes).',
                $report['mode'], $report['eligible_count'], $report['eligible_bytes'], $report['deleted_count'], $report['deleted_bytes']));
            $this->line('Existing-link grace until: '.($report['grace_until'] ?? 'not initialized; first --apply starts it'));
            $this->table(['Decision', 'Files', 'Bytes'], collect($report['totals'])->map(fn ($total, $reason) => [$reason, $total['count'], $total['bytes']])->values()->all());
            $this->line('Use --json for the full file-by-file manifest.');
        }

        return array_intersect(['inspection_failed', 'delete_failed', 'parent_not_writable', 'unsafe_or_unreadable_directory'], array_keys($report['totals']))
            ? self::FAILURE : self::SUCCESS;
    }
}
