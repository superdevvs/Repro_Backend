<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Provider latency must not hold up a property page's first response. */
class ResolvePublicVideoThumbnail implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 60;
    public int $tries = 2;
    public int $timeout = 15;

    public function __construct(public readonly string $vimeoId) {}

    public function uniqueId(): string { return $this->vimeoId; }

    public function handle(): void
    {
        if (! preg_match('/^[0-9]+$/D', $this->vimeoId)) return;
        $key = 'public_video_thumbnail:vimeo:'.$this->vimeoId;
        $response = Http::acceptJson()->connectTimeout(2)->timeout(5)
            ->get('https://vimeo.com/api/oembed.json', ['url' => 'https://vimeo.com/'.$this->vimeoId]);
        $url = $response->ok() ? trim((string) $response->json('thumbnail_url')) : '';
        if ($url !== '' && ! str_starts_with($url, 'https://')) $url = '';
        // Cache misses too; a removed/private video must not enqueue every view.
        Cache::put($key, $url, $url !== '' ? now()->addHours(12) : now()->addMinutes(5));
    }
}
