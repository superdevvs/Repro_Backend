<?php

namespace Tests\Feature;

use App\Jobs\GenerateEmailTemplateArtwork;
use App\Models\User;
use App\Services\Studio\Providers\OpenAiImageProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmailTemplateArtworkFormatsTest extends TestCase
{
    use RefreshDatabase;

    public static function artworkKinds(): array
    {
        return ['square icon' => ['icon', 1024, 1024], 'landscape illustration' => ['illustration', 1536, 1024], 'wide header' => ['header', 1536, 640]];
    }

    #[DataProvider('artworkKinds')]
    public function test_generation_preserves_real_transparency_and_requested_composition(string $kind, int $width, int $height): void
    {
        Queue::fake(); Storage::fake('public');
        config(['services.openai.api_key' => 'fixture-key']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $id = (string) Str::uuid();
        $payload = ['id' => $id, 'kind' => $kind, 'prompt' => 'A blue glass gift box and camera'];
        $this->postJson('/api/messaging/templates/artwork', $payload)->assertStatus(202);
        $this->postJson('/api/messaging/templates/artwork', $payload)->assertStatus(202);
        Queue::assertPushed(GenerateEmailTemplateArtwork::class, 1);
        Http::fake(function (Request $request) use ($width, $height) {
            $fields = collect($request->data())->keyBy('name');
            $this->assertSame('transparent', $fields['background']['contents']);
            $this->assertSame('png', $fields['output_format']['contents']);
            $this->assertSame($width.'x'.$height, $fields['size']['contents']);
            $this->assertSame('gpt-image-2.5-sunburst', $fields['model']['contents']);
            $this->assertStringNotContainsString('Use a clean white background', $fields['prompt']['contents']);
            return Http::response(['data' => [['b64_json' => base64_encode($this->png($width, $height))]]]);
        });
        $job = new GenerateEmailTemplateArtwork($id);
        $job->handle(app(OpenAiImageProvider::class));
        $job->handle(app(OpenAiImageProvider::class));
        Http::assertSentCount(1);
        $this->getJson('/api/messaging/templates/artwork/'.$id)->assertOk()->assertJsonPath('status', 'completed');
        $bytes = Storage::disk('public')->get('email-template-artwork/'.$id.'.png');
        $info = getimagesizefromstring($bytes);
        $this->assertSame([$width, $height, 'image/png'], [$info[0], $info[1], $info['mime']]);
        $image = imagecreatefromstring($bytes);
        $this->assertSame(127, imagecolorsforindex($image, imagecolorat($image, 0, 0))['alpha']);
        $this->assertSame(0, imagecolorsforindex($image, imagecolorat($image, intdiv($width, 2), intdiv($height, 2)))['alpha']);
        imagedestroy($image);
    }

    #[DataProvider('invalidAlpha')]
    public function test_a_png_extension_cannot_hide_an_opaque_or_empty_image(bool $opaque, bool $empty): void
    {
        Storage::fake('public');
        config(['services.openai.api_key' => 'fixture-key']);
        $id = (string) Str::uuid();
        DB::table('email_template_artworks')->insert(['id' => $id, 'user_id' => User::factory()->create()->id, 'kind' => 'icon', 'prompt' => 'Gift', 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
        Http::fake(fn () => Http::response(['data' => [['b64_json' => base64_encode($this->png(1024, 1024, $opaque, $empty))]]]));
        $job = new GenerateEmailTemplateArtwork($id);
        $job->handle(app(OpenAiImageProvider::class));
        $job->handle(app(OpenAiImageProvider::class));
        Http::assertSentCount(1);
        $this->assertDatabaseHas('email_template_artworks', ['id' => $id, 'status' => 'failed', 'path' => null]);
        Storage::disk('public')->assertMissing('email-template-artwork/'.$id.'.png');
    }

    public static function invalidAlpha(): array { return ['opaque white box' => [true, false], 'empty transparent image' => [false, true]]; }

    private function png(int $width, int $height, bool $opaque = false, bool $empty = false): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false); imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 255, 255, 255, $opaque ? 0 : 127));
        if (! $empty) imagefilledrectangle($image, intdiv($width, 4), intdiv($height, 4), intdiv($width * 3, 4), intdiv($height * 3, 4), imagecolorallocatealpha($image, 10, 80, 220, 0));
        ob_start(); imagepng($image); $bytes = ob_get_clean(); imagedestroy($image);
        return $bytes;
    }
}
