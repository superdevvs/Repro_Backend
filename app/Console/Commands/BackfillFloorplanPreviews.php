<?php

namespace App\Console\Commands;

use App\Models\ShootFile;
use App\Services\Shoots\FloorplanPreviewService;
use Illuminate\Console\Command;

/**
 * Generate renderable previews for existing floorplan files that have none.
 * Idempotent: files that already have a usable web_path are skipped unless --force.
 */
class BackfillFloorplanPreviews extends Command
{
    protected $signature = 'floorplans:backfill-previews
        {--dry-run : List what would be processed without writing anything}
        {--force : Regenerate previews even if one already exists}
        {--shoot= : Limit to a single shoot id}';

    protected $description = 'Backfill preview images (thumbnail_path/web_path) for floorplan files using pdftoppm.';

    public function handle(FloorplanPreviewService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $shootId = $this->option('shoot');

        $query = ShootFile::query()->where('media_type', 'floorplan');
        if ($shootId) {
            $query->where('shoot_id', (int) $shootId);
        }

        $files = $query->orderBy('shoot_id')->orderBy('id')->get();
        $this->info("Found {$files->count()} floorplan file(s)" . ($shootId ? " for shoot {$shootId}" : '') . '.');

        $counts = [];
        foreach ($files as $file) {
            $hasPreview = !empty($file->web_path);
            if ($dryRun) {
                $action = ($hasPreview && !$force) ? 'skip (has preview)' : 'would generate';
                $this->line(sprintf(
                    '  [#%d shoot %d] %s — %s (mime: %s)',
                    $file->id,
                    $file->shoot_id,
                    $file->filename,
                    $action,
                    $file->file_type ?: '?'
                ));
                $counts[$action] = ($counts[$action] ?? 0) + 1;
                continue;
            }

            $res = $service->ensurePreview($file, $force);
            $status = $res['status'];
            $counts[$status] = ($counts[$status] ?? 0) + 1;
            $this->line(sprintf(
                '  [#%d shoot %d] %s — %s%s',
                $file->id,
                $file->shoot_id,
                $file->filename,
                $status,
                !empty($res['preview_images']) ? ' (' . count($res['preview_images']) . ' page(s))' : ''
            ));
        }

        $this->newLine();
        $this->info($dryRun ? 'Dry run summary:' : 'Backfill summary:');
        foreach ($counts as $k => $v) {
            $this->line("  {$k}: {$v}");
        }

        return self::SUCCESS;
    }
}
