<?php

namespace App\Console\Commands;

use App\Services\Media\MediaStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Backfill existing local public media to Cloudflare R2 under identical keys.
 *
 * Idempotent (skips objects already on R2 with a matching size), resumable
 * (just re-run; completed objects are skipped), and supports --dry-run. Walks
 * the local public disk under the given prefix (default "shoots"), which covers
 * originals plus all derived/watermark subdirectories.
 */
class MediaBackfillR2 extends Command
{
    protected $signature = 'media:backfill-r2
        {--prefix=shoots : Local public-disk prefix to walk}
        {--shoot= : Limit to a single shoot id (shoots/{id})}
        {--dry-run : Report what would be copied without writing to R2}
        {--report= : Write a JSON summary report to this path}';

    protected $description = 'Backfill local public media to Cloudflare R2 under identical keys (idempotent, resumable).';

    public function handle(MediaStorage $media): int
    {
        $local = Storage::disk(config('media.local_disk', 'public'));
        $dryRun = (bool) $this->option('dry-run');
        $prefix = trim((string) $this->option('prefix'), '/');

        if ($shoot = $this->option('shoot')) {
            $dirs = ["{$prefix}/{$shoot}"];
        } else {
            // Iterate per-shoot directory to bound memory on large datasets.
            $dirs = $local->directories($prefix);
            if (empty($dirs)) {
                $dirs = [$prefix];
            }
        }

        $totals = ['copied' => 0, 'skipped' => 0, 'failed' => 0, 'missing' => 0];
        $failures = [];

        $this->info(($dryRun ? '[DRY RUN] ' : '') . 'Backfilling media to R2 from prefix: ' . $prefix);

        foreach ($dirs as $dir) {
            $files = $local->allFiles($dir);
            if (empty($files)) {
                continue;
            }

            foreach ($files as $key) {
                if ($dryRun) {
                    $localSize = $media->localSize($key);
                    $remoteSize = $media->remoteSize($key);
                    $status = $localSize === null
                        ? 'missing'
                        : ($remoteSize !== null && $remoteSize === $localSize ? 'skipped' : 'copied');
                } else {
                    $status = $media->mirrorToR2($key);
                }

                $totals[$status] = ($totals[$status] ?? 0) + 1;

                if ($status === 'failed') {
                    $failures[] = $key;
                }
            }

            $this->line(sprintf(
                '  %s -> copied:%d skipped:%d failed:%d',
                $dir,
                $totals['copied'],
                $totals['skipped'],
                $totals['failed']
            ));
        }

        $this->newLine();
        $this->info(sprintf(
            'Done. copied=%d skipped=%d failed=%d missing=%d',
            $totals['copied'],
            $totals['skipped'],
            $totals['failed'],
            $totals['missing']
        ));

        if ($report = $this->option('report')) {
            file_put_contents($report, json_encode([
                'generated_at' => now()->toIso8601String(),
                'dry_run' => $dryRun,
                'prefix' => $prefix,
                'totals' => $totals,
                'failures' => $failures,
            ], JSON_PRETTY_PRINT));
            $this->info('Report written to ' . $report);
        }

        return $totals['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
