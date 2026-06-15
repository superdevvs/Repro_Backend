<?php

namespace App\Console\Commands;

use App\Jobs\SyncShootFileToR2Job;
use App\Models\ShootFile;
use App\Services\Media\MediaStorage;
use Illuminate\Console\Command;

/**
 * Verify that every DB-referenced media key is present on Cloudflare R2.
 *
 * Compares each ShootFile path column (path, storage_path, thumbnail_path,
 * web_path, placeholder_path, watermarked_*) against R2 and reports gaps. This
 * is the Phase 2 cutover gate: it must report 0 missing before reads are
 * flipped to R2. Exits non-zero when gaps remain (CI/gate friendly).
 */
class MediaVerifyR2 extends Command
{
    protected $signature = 'media:verify-r2
        {--limit=0 : Only check the first N shoot files (0 = all)}
        {--report= : Write a JSON gap report to this path}';

    protected $description = 'Verify all DB-referenced media keys are present on Cloudflare R2.';

    public function handle(MediaStorage $media): int
    {
        $limit = (int) $this->option('limit');

        $checked = 0;
        $present = 0;
        $missing = [];
        $filesScanned = 0;

        $query = ShootFile::query()->orderBy('id');

        $query->chunkById($limit > 0 ? min($limit, 500) : 500, function ($files) use (
            $media, &$checked, &$present, &$missing, &$filesScanned, $limit
        ) {
            foreach ($files as $file) {
                if ($limit > 0 && $filesScanned >= $limit) {
                    return false;
                }
                $filesScanned++;

                $seen = [];
                foreach (SyncShootFileToR2Job::KEY_ATTRIBUTES as $attribute) {
                    $key = $media->normalizeKey(is_string($file->{$attribute} ?? null) ? $file->{$attribute} : null);
                    if ($key === null || str_starts_with($key, 'http://') || str_starts_with($key, 'https://')) {
                        continue;
                    }
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;

                    $checked++;
                    if ($media->existsOnR2($key)) {
                        $present++;
                    } else {
                        $missing[] = ['shoot_file_id' => $file->id, 'attribute' => $attribute, 'key' => $key];
                    }
                }
            }

            return true;
        });

        $missingCount = count($missing);

        $this->info(sprintf(
            'Verified %d key(s) across %d shoot file(s): present=%d missing=%d',
            $checked,
            $filesScanned,
            $present,
            $missingCount
        ));

        if ($missingCount > 0) {
            $this->warn('Missing keys (showing up to 25):');
            foreach (array_slice($missing, 0, 25) as $gap) {
                $this->line("  [#{$gap['shoot_file_id']}] {$gap['attribute']}: {$gap['key']}");
            }
        }

        if ($report = $this->option('report')) {
            file_put_contents($report, json_encode([
                'generated_at' => now()->toIso8601String(),
                'checked' => $checked,
                'present' => $present,
                'missing_count' => $missingCount,
                'missing' => $missing,
            ], JSON_PRETTY_PRINT));
            $this->info('Report written to ' . $report);
        }

        return $missingCount > 0 ? self::FAILURE : self::SUCCESS;
    }
}
