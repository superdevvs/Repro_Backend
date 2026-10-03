<?php
namespace App\Jobs;

use App\Models\ShootFile;
use App\Services\ImageProcessingService;
use App\Services\Media\MediaStorage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class OptimizeWebPreview implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public $timeout = 120;
    public $tries = 3;
    public $backoff = [30, 60, 120];
    public function __construct(public int $fileId) {}

    public static function eligible(ShootFile $file): bool
    {
        return $file->isClearedForProcessing()
            && ! in_array($file->media_type, ['raw', 'video', 'floorplan'], true)
            && preg_match('/\.(jpe?g|png|webp)$/i', $file->filename ?? '')
            && (! str_contains($file->web_path ?? '', '/web-v2/')
                || ($file->watermarked_web_path && ! str_contains($file->watermarked_web_path, '/watermarked-web-v2/')));
    }

    public function handle(ImageProcessingService $images, MediaStorage $media): void
    {
        $file = ShootFile::find($this->fileId);
        if (! $file || ! self::eligible($file)) return;
        $temporary = null;
        $source = $media->absolutePath($file->path ?? '');
        if (! $source) $source = $temporary = $media->downloadToTemp($file->path ?? '', '.'.pathinfo($file->filename, PATHINFO_EXTENSION));
        if (! $source || ! is_file($source)) throw new \RuntimeException('Photo source unavailable for '.$file->id);
        $old = $file->only(['web_path', 'watermarked_web_path']);
        try {
            $new = ['web_path' => $images->generatePhotographicWeb($file, $source)];
            if (! $new['web_path']) throw new \RuntimeException('Web derivative generation failed.');
            if ($file->watermarked_web_path) $new['watermarked_web_path'] = (new GenerateWatermarkedImageJob($file))->generateWebOnly($source);
            $journal = ['file_id' => $file->id, 'source_path' => $file->path, 'source_version' => $file->content_version,
                'old' => $old, 'new' => $new, 'created_at' => now()->toIso8601String()];
            $journalPath = 'performance/web-v2/'.$file->id.'-'.now()->format('YmdHis').'-'.\Illuminate\Support\Str::random(6).'.json';
            if (! Storage::disk('local')->put($journalPath, json_encode($journal, JSON_THROW_ON_ERROR))) throw new \RuntimeException('Unable to save rollback journal.');
            // Optimistic publication: never replace derivatives if editing, watermarking or another job won the race.
            $query = DB::table('shoot_files')->where('id', $file->id)->where('path', $file->path)->where('content_version', $file->getRawOriginal('content_version'))->where('updated_at', $file->getRawOriginal('updated_at'));
            foreach ($old as $column => $value) $query->where($column, $value);
            $updated = $query->update($new + ['processed_at' => now(), 'updated_at' => now()]);
            $journal['published'] = $updated === 1;
            Storage::disk('local')->put($journalPath, json_encode($journal, JSON_THROW_ON_ERROR));
        } finally { if ($temporary && is_file($temporary)) unlink($temporary); }
    }
}
