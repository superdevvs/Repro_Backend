<?php

namespace Tests\Unit;

use App\Services\Studio\ReelCompositionService;
use App\Services\Studio\WorkspaceVideoOptions;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ReelAudioCompositionTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/reel-audio-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_legacy_output_remains_1080_silent_and_source_is_unchanged(): void
    {
        $source = $this->clip('source', true);
        $hash = hash_file('sha256', $source);
        $output = $this->directory.'/output.mp4';
        $metadata = (new ReelCompositionService)->compose([$source], $output, $this->directory, ['duration' => 5]);
        $this->assertSame(1080, $metadata['width']);
        $this->assertSame(1920, $metadata['height']);
        $this->assertSame('1080p', $metadata['resolution']);
        $this->assertFalse($metadata['hasAudio']);
        $this->assertFalse($metadata['originalAudio']);
        $this->assertEqualsWithDelta(5, $metadata['durationSeconds'], 0.1);
        $this->assertSame($hash, hash_file('sha256', $source));
    }

    public function test_original_audio_survives_crossfade_timing_and_graphic_text_at_720p(): void
    {
        $clips = [$this->clip('first', true), $this->clip('second', false)];
        $hashes = array_map(fn ($path) => hash_file('sha256', $path), $clips);
        $metadata = (new ReelCompositionService)->compose($clips, $this->directory.'/output.mp4', $this->directory, [
            'resolution' => '720p', 'ratio' => '16:9', 'duration' => 5, 'transition' => 'fade',
            'audio' => ['original' => true, 'fade' => false], 'frames' => [['duration' => 1], ['duration' => 2]],
            'text' => ['style' => 'graphic', 'title' => 'Original audio', 'timing' => 'all'],
        ]);
        $this->assertSame(1280, $metadata['width']);
        $this->assertSame(720, $metadata['height']);
        $this->assertTrue($metadata['hasAudio']);
        $this->assertTrue($metadata['originalAudio']);
        $this->assertFalse($metadata['musicAudio']);
        $this->assertEqualsWithDelta(5, $metadata['durationSeconds'], 0.15);
        $this->assertSame($hashes, array_map(fn ($path) => hash_file('sha256', $path), $clips));
        $this->assertGreaterThan(0.005, $this->rms($this->directory.'/output.mp4', 0.7));
        $this->assertLessThan(0.0001, $this->rms($this->directory.'/output.mp4', 4), json_encode([
            'normalizedSilent' => $this->rms($this->directory.'/normalized-1.mp4', 1),
            'assembledLate' => $this->rms($this->directory.'/assembled.mp4', 4),
        ]));
    }

    public function test_music_is_looped_scaled_faded_and_zero_volume_is_silent_without_invented_original_audio(): void
    {
        $source = $this->clip('silent', false);
        $music = $this->directory.'/music.wav';
        $this->mediaProcess(['ffmpeg', '-v', 'error', '-f', 'lavfi', '-i', 'sine=frequency=880:sample_rate=48000', '-t', '1', $music]);
        $config = ['resolution' => '720p', 'ratio' => '1:1', 'duration' => 5,
            'audio' => ['original' => true, 'musicId' => 'test-private-id', 'musicVolume' => 0.25, 'fade' => true]];
        $output = $this->directory.'/output.mp4';
        $metadata = (new ReelCompositionService)->compose([$source], $output, $this->directory, $config, $music);
        $this->assertTrue($metadata['hasAudio']);
        $this->assertTrue($metadata['musicAudio']);
        $this->assertFalse($metadata['originalAudio']);
        $this->assertSame(720, $metadata['width']);
        $this->assertSame(720, $metadata['height']);
        $middle = $this->rms($output, 2.2);
        $this->assertGreaterThan(0.01, $middle);
        $this->assertLessThan(0.03, $middle);
        $this->assertLessThan($middle * 0.5, $this->rms($output, 0.01, 0.1));
        $this->assertLessThan($middle * 0.5, $this->rms($output, 4.85, 0.1));
        $config['audio']['musicVolume'] = 0;
        $metadata = (new ReelCompositionService)->compose([$source], $this->directory.'/muted.mp4', $this->directory, $config, $music);
        $this->assertFalse($metadata['hasAudio']);
        $this->assertFalse($metadata['musicAudio']);
    }

    public function test_original_and_uploaded_audio_mix_without_transitions_and_preserve_portrait_framing(): void
    {
        $clips = [$this->clip('audible', true), $this->clip('quiet', false)];
        $music = $this->directory.'/background.wav';
        $this->mediaProcess(['ffmpeg', '-v', 'error', '-f', 'lavfi', '-i', 'sine=frequency=880:sample_rate=48000', '-t', '1', $music]);
        $output = $this->directory.'/mixed.mp4';
        $metadata = (new ReelCompositionService)->compose($clips, $output, $this->directory, [
            'resolution' => '720p', 'ratio' => '4:5', 'duration' => 5,
            'audio' => ['original' => true, 'musicId' => 'private-test-track', 'musicVolume' => 0.2, 'fade' => false],
        ], $music);
        $this->assertTrue($metadata['hasAudio']);
        $this->assertTrue($metadata['originalAudio']);
        $this->assertTrue($metadata['musicAudio']);
        $this->assertSame(720, $metadata['width']);
        $this->assertSame(900, $metadata['height']);
        $this->assertEqualsWithDelta(5, $metadata['durationSeconds'], 0.15);
        $this->assertGreaterThan($this->rms($output, 4) * 2, $this->rms($output, 1));
    }

    public function test_all_supported_framings_have_even_encoder_dimensions(): void
    {
        foreach (['720p' => [[720, 1280], [1280, 720], [720, 720], [720, 900]], '1080p' => [[1080, 1920], [1920, 1080], [1080, 1080], [1080, 1350]]] as $resolution => $expected) {
            foreach (['9:16', '16:9', '1:1', '4:5'] as $index => $ratio) {
                $this->assertSame($expected[$index], WorkspaceVideoOptions::dimensions(compact('resolution', 'ratio')));
            }
        }
    }

    private function clip(string $name, bool $audio): string
    {
        $path = $this->directory.'/'.$name.'.mp4';
        $args = ['ffmpeg', '-v', 'error', '-f', 'lavfi', '-i', 'color=c=blue:s=160x90:r=30'];
        if ($audio) {
            array_push($args, '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000', '-c:a', 'aac');
        }
        array_push($args, '-t', '1', '-c:v', 'libx264', '-threads', '2', '-pix_fmt', 'yuv420p', $path);
        $this->mediaProcess($args);

        return $path;
    }

    private function rms(string $path, float $start, float $seconds = 0.2): float
    {
        $process = $this->mediaProcess(['ffmpeg', '-v', 'error', '-ss', (string) $start, '-i', $path, '-t', (string) $seconds, '-map', '0:a:0', '-ac', '1', '-ar', '48000', '-f', 'f32le', 'pipe:1']);
        $values = unpack('g*', $process->getOutput());

        return sqrt(array_sum(array_map(fn ($value) => $value * $value, $values)) / max(1, count($values)));
    }

    private function mediaProcess(array $args): Process
    {
        $process = new Process($args);
        $process->setTimeout(180);
        $process->mustRun();

        return $process;
    }
}
