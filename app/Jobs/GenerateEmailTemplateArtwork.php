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
        $validationError = null;
        try {
            $composition = match ($row->kind) {
                'header' => 'wide email header, composed natively across a 1536 by 640 landscape canvas (12:5). Arrange related elements as one balanced horizontal scene, with breathing room. Do not place a square icon in the middle of a wide blank image',
                'icon' => 'simple isolated square icon, with the complete object visible and clear margins',
                default => 'landscape email illustration, with a balanced composition and clear margins',
            };
            $instructions = 'Create a new '.$composition.' matching only the visual style of this REPRO artwork reference: restrained navy and cobalt blue, pale ice blue, premium translucent glass 3D forms and subtle shadows for a real-estate photography brand. The background MUST be fully transparent with a real alpha channel. No white or colored background, rectangular panel, backplate, checkerboard, lettering, labels, watermark or logo. Preserve transparent space around the objects and any soft shadows. Use the reference for visual style only, not its subject, composition or proportions. Requested subject: '.$row->prompt;
            $reference = file_get_contents(public_path('images/email-atelier/v6/editing.png'));
            $bytes = $provider->artwork($reference, $instructions, $row->kind, ['model' => config('services.openai.email_image_model', 'gpt-image-2.5-sunburst')]);
            if (! str_starts_with($bytes, "\x89PNG\r\n\x1a\n") || (@getimagesizefromstring($bytes)['mime'] ?? null) !== 'image/png') {
                throw new \RuntimeException('Generated artwork must be a valid PNG image.');
            }
            $info = getimagesizefromstring($bytes);
            if ([$info[0], $info[1]] !== OpenAiImageProvider::ARTWORK_SIZES[$row->kind] || ! $this->hasTransparentBackground($bytes)) {
                $validationError = 'The image did not include the requested transparent background or dimensions. Your current image is unchanged. Generate another transparent PNG.';
                throw new \RuntimeException($validationError);
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
                    : ($validationError ?? 'Artwork could not be generated. Check the OpenAI connection and try again.'), 'updated_at' => now(),
            ]);
        }
    }

    private function hasTransparentBackground(string $bytes): bool
    {
        $image = imagecreatefromstring($bytes);
        if (! $image) return false;
        $transparent = 0; $visible = 0; $samples = 0;
        try {
            for ($y = 0; $y < imagesy($image); $y += max(1, (int) floor(imagesy($image) / 64))) {
                for ($x = 0; $x < imagesx($image); $x += max(1, (int) floor(imagesx($image) / 64))) {
                    $color = imagecolorat($image, $x, $y);
                    $alpha = imageistruecolor($image) ? ($color >> 24) & 127 : imagecolorsforindex($image, $color)['alpha'];
                    $samples++;
                    if ($alpha >= 120) $transparent++;
                    if ($alpha < 120) $visible++;
                }
            }
            return $transparent / max(1, $samples) >= 0.02 && $visible > 0;
        } finally {
            imagedestroy($image);
        }
    }

    public function failed(?Throwable $exception): void
    {
        DB::table('email_template_artworks')->where('id', $this->artworkId)->whereIn('status', ['queued', 'processing'])->update([
            'status' => 'unknown', 'error' => 'Generation stopped before its result could be confirmed.', 'updated_at' => now(),
        ]);
    }
}
