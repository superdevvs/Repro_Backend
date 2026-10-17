<?php

namespace App\Jobs;

use App\Exceptions\OpenAiImageException;
use App\Services\Studio\Providers\OpenAiImageProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateEmailTemplateArtwork implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $artworkId) {}

    public function handle(OpenAiImageProvider $provider): void
    {
        // A redelivered job must never repeat a paid provider call.
        if (! DB::table('email_template_artworks')->where('id', $this->artworkId)->where('status', 'queued')->update(['status' => 'processing', 'updated_at' => now()])) {
            return;
        }
        $row = DB::table('email_template_artworks')->find($this->artworkId);
        try {
            $instructions = 'Create a new '.($row->kind === 'icon' ? 'simple isolated icon' : 'email hero illustration').' matching the visual style of this REPRO email artwork reference: restrained navy and cobalt blue, pale ice blue, warm white, soft editorial 3D forms, minimal composition, subtle shadows, premium real-estate photography brand. Use a clean white background, no lettering, labels, watermark or logo. Replace the reference subject entirely with: '.$row->prompt;
            $reference = file_get_contents(public_path('images/email-atelier/v6/editing.png'));
            if ($row->kind === 'icon') {
                $reference = (string) \Intervention\Image\ImageManager::gd()->read($reference)->cover(1024, 1024)->toPng();
            }
            $bytes = $provider->edit($reference, $instructions, ['model' => config('services.openai.email_image_model', 'gpt-image-2.5-sunburst')]);
            if (! str_starts_with($bytes, "\x89PNG\r\n\x1a\n") || (@getimagesizefromstring($bytes)['mime'] ?? null) !== 'image/png') {
                throw new \RuntimeException('Generated artwork must be a valid PNG image.');
            }
            $path = 'email-template-artwork/'.$this->artworkId.'.png';
            if (! Storage::disk('public')->put($path, $bytes)) {
                throw new \RuntimeException('Could not save artwork.');
            }
            DB::table('email_template_artworks')->where('id', $this->artworkId)->update(['status' => 'completed', 'path' => $path, 'updated_at' => now()]);
        } catch (Throwable $exception) {
            $ambiguous = $exception instanceof OpenAiImageException && $exception->ambiguous;
            DB::table('email_template_artworks')->where('id', $this->artworkId)->update([
                'status' => $ambiguous ? 'unknown' : 'failed', 'error' => $ambiguous
                    ? 'The image request could not be confirmed. Check its status before generating again.'
                    : 'Artwork could not be generated. Check the OpenAI connection and try again.', 'updated_at' => now(),
            ]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        DB::table('email_template_artworks')->where('id', $this->artworkId)->whereIn('status', ['queued', 'processing'])->update([
            'status' => 'unknown', 'error' => 'Generation stopped before its result could be confirmed.', 'updated_at' => now(),
        ]);
    }
}
