<?php

namespace App\Console\Commands;

use App\Models\ShootFile;
use App\Jobs\ProcessImageJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ProcessExistingImages extends Command
{
    protected $signature = 'images:process-existing {--limit=100} {--force}';
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

        $query = ShootFile::whereIn('media_type', self::IMAGE_MEDIA_TYPES)
        ->where(function ($query) use ($force) {
            $query->whereNull('processed_at')
                ->orWhereNull('thumbnail_path')
                ->orWhereNull('web_path');

            // Pick up files that predate the `grid` rendition so a single run of
            // this command backfills the sharper desktop derivative.
            if (Schema::hasColumn('shoot_files', 'grid_path')) {
                $query->orWhereNull('grid_path');
            }
        });

        if (!$force) {
            $query->whereNull('processing_failed_at');
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

        $this->info('Processing complete.');

        return 0;
    }
}
