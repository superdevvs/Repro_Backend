<?php

namespace Tests\Unit;

use App\Services\Studio\ReelCompositionService;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ReelFramingCompositionTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/reel-framing-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public static function preservedFraming(): array
    {
        return [
            'landscape provider clip, explicit fit' => [320, 180, '9:16', ['frames' => [['method' => 'fit']]], [720, 1280], [25, 455], [695, 825], [360, 100]],
            'landscape provider clip, implicit Studio fit' => [320, 180, '9:16', ['studioWorkspace' => true], [720, 1280], [25, 455], [695, 825], [360, 100]],
            'extended photo, differently shaped provider clip' => [320, 180, '9:16', ['frames' => [['method' => 'extend']]], [720, 1280], [25, 455], [695, 825], [360, 100]],
            'portrait provider clip, landscape output' => [180, 320, '16:9', ['frames' => [['method' => 'fit']]], [1280, 720], [455, 25], [825, 695], [100, 360]],
        ];
    }

    #[DataProvider('preservedFraming')]
    public function test_fit_and_extend_keep_full_scene_edges_in_the_real_render(
        int $sourceWidth, int $sourceHeight, string $ratio, array $settings, array $dimensions,
        array $topLeft, array $bottomRight, array $padding
    ): void {
        $clip = $this->cornerClip($sourceWidth, $sourceHeight);
        $before = hash_file('sha256', $clip);
        $output = $this->directory.'/result.mp4';
        $metadata = (new ReelCompositionService)->compose([$clip], $output, $this->directory,
            $settings + ['resolution' => '720p', 'ratio' => $ratio, 'duration' => 5]);
        $this->assertSame($dimensions, [$metadata['width'], $metadata['height']]);
        $this->assertEqualsWithDelta(5, $metadata['durationSeconds'], 0.1);
        $this->assertSame($before, hash_file('sha256', $clip));
        $frame = $this->frame($output);
        try {
            // These corner landmarks disappear under the previous cover/crop.
            $this->assertPixel($frame, $topLeft, [255, 0, 0]);
            $this->assertPixel($frame, $bottomRight, [0, 255, 0]);
            $this->assertPixel($frame, $padding, [16, 24, 40]);
        } finally {
            imagedestroy($frame);
        }
    }

    public static function croppedFraming(): array
    {
        return ['explicit crop' => [['studioWorkspace' => true, 'frames' => [['method' => 'crop']]]], 'legacy default' => [[]]];
    }

    #[DataProvider('croppedFraming')]
    public function test_explicit_crop_and_legacy_default_keep_their_existing_fill_behavior(array $settings): void
    {
        $clip = $this->cornerClip(320, 180);
        $output = $this->directory.'/cropped.mp4';
        (new ReelCompositionService)->compose([$clip], $output, $this->directory,
            $settings + ['resolution' => '720p', 'ratio' => '9:16', 'duration' => 5]);
        $frame = $this->frame($output);
        try {
            // The central gray scene fills the canvas; no padded dark band.
            $this->assertPixel($frame, [360, 100], [128, 128, 128]);
            $this->assertPixel($frame, [25, 455], [128, 128, 128]);
            $this->assertPixel($frame, [695, 825], [128, 128, 128]);
        } finally {
            imagedestroy($frame);
        }
    }

    private function cornerClip(int $width, int $height): string
    {
        $path = $this->directory.'/source.mp4';
        $this->runMedia(['ffmpeg', '-v', 'error', '-f', 'lavfi', '-i', "color=c=gray:s={$width}x{$height}:r=30",
            '-vf', 'drawbox=x=0:y=0:w=40:h=40:color=red:t=fill,drawbox=x=iw-40:y=ih-40:w=40:h=40:color=lime:t=fill',
            '-t', '1', '-c:v', 'libx264', '-threads', '2', '-pix_fmt', 'yuv420p', $path]);

        return $path;
    }

    private function frame(string $video): \GdImage
    {
        $path = $this->directory.'/sample.png';
        $this->runMedia(['ffmpeg', '-v', 'error', '-ss', '1', '-i', $video, '-frames:v', '1', '-threads', '1', $path]);
        $image = imagecreatefrompng($path);
        $this->assertInstanceOf(\GdImage::class, $image);

        return $image;
    }

    private function assertPixel(\GdImage $image, array $point, array $expected): void
    {
        $pixel = imagecolorsforindex($image, imagecolorat($image, $point[0], $point[1]));
        foreach (['red', 'green', 'blue'] as $index => $channel) {
            $this->assertEqualsWithDelta($expected[$index], $pixel[$channel], 15,
                $channel.' at '.implode(',', $point).' must preserve the intended framing');
        }
    }

    private function runMedia(array $arguments): void
    {
        (new Process($arguments))->setTimeout(120)->mustRun();
    }
}
