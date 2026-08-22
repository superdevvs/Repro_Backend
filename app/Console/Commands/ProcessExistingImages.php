<?php

namespace App\Console\Commands;

use App\Models\ShootFile;
use App\Jobs\ProcessImageJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ProcessExistingImages extends Command
{
    protected $signature = 'images:process-existing {--limit=100} {--force} {--after-id=0}';
    protected $description = 'Process existing images that haven\'t been processed yet';

    /**
     * Media types that carry a still image and therefore have renditions.
     *
     * This used to be `media_type IN ('image','raw')`, but no row uses 'image'.
     * The real values are edited, drone, twilight, green_grass, virtual_staging,
     * extra, floorplan, raw and video — so the command only ever matched the
     * handful of raw files and could never backfill the client-facing photos it
     * exists to fix. `video` stays out: image renditions are meaningless for it.
     */
    private const IMAGE_MEDIA_TYPES = [
        'image',
        'raw',
        'edited',
        'drone',
        'twilight',
        'green_grass',
        'virtual_staging',
        'extra',
        'floorplan',
    ];

    public function handle(): int
    {
        $limit = $this->option('limit');
        $force = $this->option('force');
        $afterId = (int) $this->option('after-id');

        // Cursor for batched backfills. --force intentionally ignores every
        // "already done" signal, so without a cursor each run re-selects the
        // same first --limit rows and a large library could never be walked.
        // Batch with: --after-id=<last id of the previous run>.
        $query = ShootFile::whereIn('media_type', self::IMAGE_MEDIA_TYPES)
            ->when($afterId > 0, fn ($q) => $q->where('id', '>', $afterId))
            ->orderBy('id');

        // --force means "re-render regardless of what is already on disk". That
        // is the only way to roll a retuned preset (e.g. the grid rendition
        // moving from 1000px to the tuned 600px Lanczos version) over media that
        // already has a complete, non-null set of renditions.
        if ($force) {
            $this->warn('Force mode: re-rendering every image rendition, including files already processed.');
        } else {
            $query->where(function ($query) {
                $query->whereNull('processed_at')
                    ->orWhereNull('thumbnail_path')
                    ->orWhereNull('web_path');

                // Pick up files that predate the `grid` rendition so a single run
                // of this command backfills the sharper derivative.
                if (Schema::hasColumn('shoot_files', 'grid_path')) {
                    $query->orWhereNull('grid_path');
                }
            })->whereNull('processing_failed_at');
        }

        $files = $query->limit($limit)->get();

        if ($files->isEmpty()) {
            $this->info('No images to process.');
            return 0;
        }

        $this->info("Found {$files->count()} images to process.");

        foreach ($files as $file) {
            $this->line("Processing: {$file->filename} (ID: {$file->id})");
            
            // Reset processing status if forcing
            if ($force) {
                $file->update([
                    'processed_at' => null,
                    'processing_failed_at' => null,
                    'processing_error' => null,
                ]);
            }
            
            ProcessImageJob::dispatch($file);
            $this->info("✓ Queued for processing");
        }

        // On a sync queue the jobs above have already run, so these counts are
        // the real outcome. On an async queue they report what was dispatched.
        $lastId = $files->last()->id;
        $ids = $files->pluck('id');
        $failed = ShootFile::whereIn('id', $ids)->whereNotNull('processing_failed_at')->count();

        $this->info('Processing complete.');
        $this->line("  dispatched: {$files->count()}");
        $this->line("  failed:     {$failed}");
        $this->line("  next batch: --after-id={$lastId}");

        return 0;
    }
}
