<?php
namespace App\Jobs;

use App\Models\ShootFile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;

/** Each chain runs sequentially; only fifty file IDs are retained at a time. */
class OptimizeWebPreviewBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public $timeout = 60;
    public function __construct(public int $after = 0, public bool $continue = false) {}
    public function handle(): void
    {
        $files = ShootFile::where('id', '>', $this->after)->orderBy('id')->limit(50)->get();
        if ($files->isEmpty()) return;
        $jobs = $files->filter(fn ($file) => OptimizeWebPreview::eligible($file))->map(fn ($file) => new OptimizeWebPreview($file->id))->values()->all();
        if ($this->continue) $jobs[] = new self($files->last()->id, true);
        if ($jobs) Bus::chain($jobs)->onConnection('database')->onQueue('media-web-optimization')->dispatch();
    }
}
