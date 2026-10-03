<?php
namespace App\Console\Commands;

use App\Jobs\OptimizeWebPreview;
use App\Jobs\OptimizeWebPreviewBatch;
use App\Models\ShootFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class OptimizeWebPreviews extends Command
{
    protected $signature = 'media:optimize-web {--after=0} {--all : Continue sequential batches} {--dispatch : Queue the batch; default is a dry run} {--rollback= : Journal basename to restore}';
    protected $description = 'Queue reversible 1500px quality-75 photo Web derivatives, preserving originals';
    public function handle(): int
    {
        if ($name = $this->option('rollback')) {
            if (! preg_match('/\A[0-9]+-[0-9]+-[A-Za-z0-9]+\.json\z/', $name)) { $this->error('Invalid journal name'); return self::FAILURE; }
            $journal = json_decode(Storage::disk('local')->get('performance/web-v2/'.$name), true, 512, JSON_THROW_ON_ERROR);
            $query = DB::table('shoot_files')->where('id', $journal['file_id'])->where('path', $journal['source_path']);
            foreach ($journal['new'] as $column => $value) $query->where($column, $value);
            $count = $query->update($journal['old'] + ['updated_at' => now()]);
            $this->info('Restored '.$count.' unchanged file record(s); all derivative files retained.');
            return $count ? self::SUCCESS : self::FAILURE;
        }
        $after = max(0, (int) $this->option('after'));
        $files = ShootFile::where('id', '>', $after)->orderBy('id')->limit(50)->get();
        $this->info(json_encode(['scanned' => $files->count(), 'eligible' => $files->filter(fn ($file) => OptimizeWebPreview::eligible($file))->count(), 'next_after' => $files->last()?->id ?? $after]));
        if ($this->option('dispatch')) {
            OptimizeWebPreviewBatch::dispatch($after, (bool) $this->option('all'))->onConnection('database')->onQueue('media-web-optimization');
            $this->info('Queued; run one database worker for media-web-optimization.');
        }
        return self::SUCCESS;
    }
}
