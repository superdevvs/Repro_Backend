<?php

namespace Tests\Unit;

use App\Exceptions\OpenAiImageException;
use App\Services\Studio\Providers\OpenAiImageProvider;
use GdImage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OpenAiImageProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.openai.api_key' => 'fixture-key', 'services.openai.image_model' => 'gpt-image-2']);
        Http::preventStrayRequests();
    }

    public function test_edit_uses_high_quality_multipart_and_preserves_source_ratio(): void
    {
        Http::fake(function (Request $request) {
            $this->assertSame('https://api.openai.com/v1/images/edits', $request->url());
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer fixture-key'));
            $fields = collect($request->data())->keyBy('name');
            $this->assertSame('gpt-image-2', $fields['model']['contents']);
            $this->assertSame('high', $fields['quality']['contents']);
            $this->assertSame('png', $fields['output_format']['contents']);
            $this->assertFalse($fields->has('input_fidelity'));
            $this->assertFalse($fields->has('mask'));
            $this->assertTrue($request->hasFile('image[]', filename: 'property.png'));
            [$width, $height] = array_map('intval', explode('x', $fields['size']['contents']));
            $this->assertValidRequestSize($width, $height);

            return Http::response(['data' => [['b64_json' => base64_encode($this->png($width, $height, [0, 200, 0]))]]]);
        });
        $bytes = app(OpenAiImageProvider::class)->edit($this->png(900, 600, [200, 0, 0]), 'Recover the window view');
        $this->assertSame([900, 600], array_slice(getimagesizefromstring($bytes), 0, 2));
        Http::assertSentCount(1);
    }

    #[DataProvider('outpaintRatios')]
    public function test_outpaint_has_transparent_edges_and_restores_the_original_pixels(string $ratio, int $sw, int $sh, int $fw, int $fh): void
    {
        Http::fake(function (Request $request) {
            $fields = collect($request->data())->keyBy('name');
            [$width, $height] = array_map('intval', explode('x', $fields['size']['contents']));
            $this->assertValidRequestSize($width, $height);
            $source = imagecreatefromstring($fields['image[]']['contents']);
            $mask = imagecreatefromstring($fields['mask']['contents']);
            $this->assertSame([$width, $height], [imagesx($source), imagesy($source)]);
            $this->assertSame([$width, $height], [imagesx($mask), imagesy($mask)]);
            $this->assertSame(127, imagecolorsforindex($mask, imagecolorat($mask, 0, 0))['alpha']);
            $this->assertSame(127, imagecolorsforindex($source, imagecolorat($source, 0, 0))['alpha']);
            $this->assertSame(0, imagecolorsforindex($mask, imagecolorat($mask, intdiv($width, 2), intdiv($height, 2)))['alpha']);
            $this->assertPixel($source, intdiv($width, 2), intdiv($height, 2), [200, 0, 0]);
            imagedestroy($source);
            imagedestroy($mask);

            // Simulate a provider that redraws even the protected center: adapter must restore it.
            return Http::response(['data' => [['b64_json' => base64_encode($this->png($width, $height, [0, 200, 0]))]]]);
        });
        $bytes = app(OpenAiImageProvider::class)->outpaint($this->png($sw, $sh, [200, 0, 0]), $ratio);
        $result = imagecreatefromstring($bytes);
        $this->assertSame([$fw, $fh], [imagesx($result), imagesy($result)]);
        $this->assertPixel($result, intdiv($fw, 2), intdiv($fh, 2), [200, 0, 0]);
        $this->assertPixel($result, 0, 0, [0, 200, 0]);
        imagedestroy($result);
        Http::assertSentCount(1);
    }

    public static function outpaintRatios(): array
    {
        return [['9:16', 900, 600, 900, 1600], ['16:9', 600, 900, 1600, 900], ['1:1', 900, 600, 1600, 1600]];
    }

    public function test_narrow_revision_is_padded_and_unpadded_without_stretching_its_return_shape(): void
    {
        Http::fake(function (Request $request) {
            $fields = collect($request->data())->keyBy('name');
            [$width, $height] = array_map('intval', explode('x', $fields['size']['contents']));
            $this->assertValidRequestSize($width, $height);
            $this->assertSame([512, 1536], [$width, $height]);

            return Http::response(['data' => [['b64_json' => base64_encode($this->png($width, $height, [0, 200, 0]))]]]);
        });
        $bytes = app(OpenAiImageProvider::class)->edit($this->png(90, 480, [200, 0, 0]), 'Adjust the wall');
        $this->assertSame([90, 480], array_slice(getimagesizefromstring($bytes), 0, 2));
    }

    public function test_server_owned_credentials_model_and_reference_images_override_defaults(): void
    {
        config(['services.openai.api_key' => null]);
        Http::fake(function (Request $request) {
            $fields = collect($request->data())->keyBy('name');
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer override-fixture'));
            $this->assertSame('gpt-image-2-2026-04-21', $fields['model']['contents']);
            $images = collect($request->data())->where('name', 'image[]')->values();
            $this->assertCount(3, $images);
            $this->assertSame('property.png', $images[0]['filename']);
            $this->assertSame('reference-1.png', $images[1]['filename']);

            return Http::response(['data' => [['b64_json' => base64_encode($this->png(1536, 1536, [0, 200, 0]))]]]);
        });
        app(OpenAiImageProvider::class)->edit($this->png(600, 600, [200, 0, 0]), 'Match this style', [
            'api_key' => 'override-fixture', 'model' => 'gpt-image-2-2026-04-21',
            'reference_images' => [$this->png(100, 100, [0, 0, 200]), $this->png(100, 100, [200, 200, 0])],
        ]);
        $this->assertNull(config('services.openai.api_key'));
        $this->assertSame('gpt-image-2', config('services.openai.image_model'));
        Http::assertSentCount(1);
    }

    #[DataProvider('failureStatuses')]
    public function test_failure_classification_is_sanitized_and_never_retries(int $status, bool $ambiguous, bool $terminal): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'https://private.test/photo PRIVATE_PIXELS fixture-key']], $status)]);
        try {
            app(OpenAiImageProvider::class)->edit($this->png(600, 600, [0, 0, 0]), 'Edit');
            $this->fail('Expected a typed failure.');
        } catch (OpenAiImageException $exception) {
            $this->assertSame($status, $exception->httpStatus);
            $this->assertSame($ambiguous, $exception->ambiguous);
            $this->assertSame($terminal, $exception->isTerminal());
            $this->assertSafe($exception);
        }
        Http::assertSentCount(1);
    }

    public static function failureStatuses(): array
    {
        return [[400, false, true], [401, false, true], [403, false, true], [422, false, true], [429, false, false], [408, true, false], [409, true, false], [500, true, false], [503, true, false]];
    }

    public function test_network_timeout_stays_ambiguous_and_carries_no_transport_message(): void
    {
        Http::fake(fn () => throw new ConnectionException('fixture-key https://private.test/photo PRIVATE_PIXELS'));
        try {
            app(OpenAiImageProvider::class)->edit($this->png(600, 600, [0, 0, 0]), 'Edit');
            $this->fail('Expected a typed failure.');
        } catch (OpenAiImageException $exception) {
            $this->assertTrue($exception->ambiguous);
            $this->assertFalse($exception->isTerminal());
            $this->assertSafe($exception);
        }
    }

    public function test_moderation_rejection_does_not_become_a_retryable_provider_error(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 'moderation_blocked', 'message' => 'PRIVATE_PIXELS']], 400)]);
        try {
            app(OpenAiImageProvider::class)->edit($this->png(600, 600, [0, 0, 0]), 'Edit');
            $this->fail('Expected a typed failure.');
        } catch (OpenAiImageException $exception) {
            $this->assertSame('moderation_blocked', $exception->reason);
            $this->assertTrue($exception->isTerminal());
            $this->assertSafe($exception);
        }
        Http::assertSentCount(1);
    }

    public function test_corrupt_success_is_ambiguous_and_does_not_fetch_a_response_url(): void
    {
        Http::fake(['*' => Http::response(['data' => [['b64_json' => base64_encode('PRIVATE_PIXELS'), 'url' => 'https://private.test/photo']]])]);
        try {
            app(OpenAiImageProvider::class)->edit($this->png(600, 600, [0, 0, 0]), 'Edit');
            $this->fail('Expected a typed failure.');
        } catch (OpenAiImageException $exception) {
            $this->assertSame('invalid_result', $exception->reason);
            $this->assertTrue($exception->ambiguous);
            $this->assertSafe($exception);
        }
        Http::assertSentCount(1);
    }

    public function test_unallowlisted_model_and_invalid_sources_fail_before_http(): void
    {
        foreach ([['model' => 'unknown-model'], ['reference_images' => array_fill(0, 5, 'x')]] as $options) {
            try {
                app(OpenAiImageProvider::class)->edit($this->png(600, 600, [0, 0, 0]), 'Edit', $options);
                $this->fail('Expected local validation failure.');
            } catch (OpenAiImageException $exception) {
                $this->assertTrue($exception->isTerminal());
            }
        }
        try {
            app(OpenAiImageProvider::class)->edit('https://private.test/photo', 'Edit');
            $this->fail('Expected invalid image.');
        } catch (OpenAiImageException $exception) {
            $this->assertSame('invalid_image', $exception->reason);
        }
        Http::assertNothingSent();
    }

    private function assertValidRequestSize(int $width, int $height): void
    {
        $this->assertSame(0, $width % 16);
        $this->assertSame(0, $height % 16);
        $this->assertLessThanOrEqual(3840, max($width, $height));
        $this->assertLessThanOrEqual(3, max($width, $height) / min($width, $height));
        $this->assertGreaterThanOrEqual(655360, $width * $height);
        $this->assertLessThanOrEqual(3686400, $width * $height);
    }

    private function assertSafe(OpenAiImageException $exception): void
    {
        $this->assertNull($exception->getPrevious());
        $this->assertStringNotContainsString('PRIVATE_PIXELS', $exception->getMessage());
        $this->assertStringNotContainsString('fixture-key', $exception->getMessage());
        $this->assertStringNotContainsString('https://', $exception->getMessage());
    }

    private function png(int $width, int $height, array $rgb): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, ...$rgb));
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function assertPixel(GdImage $image, int $x, int $y, array $expected): void
    {
        $pixel = imagecolorsforindex($image, imagecolorat($image, $x, $y));
        $this->assertSame($expected, [$pixel['red'], $pixel['green'], $pixel['blue']]);
    }
}
