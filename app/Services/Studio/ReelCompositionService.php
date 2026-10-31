<?php

namespace App\Services\Studio;

use RuntimeException;
use Symfony\Component\Process\Process;

/** Deterministic finishing over real provider clips. Optional effects never imply camera continuity. */
class ReelCompositionService
{
    public const TRANSITIONS = ['none', 'fade', 'fadeblack', 'dissolve', 'slideleft', 'slideright', 'smoothleft', 'smoothright', 'wipeleft', 'wiperight'];

    public function compose(array $clips, string $output, string $directory, array $config, ?string $musicPath = null): array
    {
        $count = count($clips);
        if (! $count) {
            throw new RuntimeException('No clips were available for assembly.');
        }
        if ($count > 12) {
            throw new RuntimeException('A reel supports at most 12 selected source photos.');
        }
        $config = WorkspaceVideoOptions::normalize($config);
        if (! in_array($config['resolution'], WorkspaceVideoOptions::RESOLUTIONS, true)) {
            throw new RuntimeException('Unsupported video resolution.');
        }
        if ($config['audio']['musicId'] && (! $musicPath || ! is_file($musicPath))) {
            throw new RuntimeException('The selected workspace audio is unavailable.');
        }
        [$width, $height] = WorkspaceVideoOptions::dimensions($config);
        $probes = array_map(fn ($clip) => $this->probe($clip), $clips);
        $original = $config['audio']['original'] && collect($probes)->contains(fn ($info) => $info['hasAudio']);
        $music = $config['audio']['musicId'] && $musicPath && $config['audio']['musicVolume'] > 0;
        $duration = max(5, min(120, (float) ($config['duration'] ?? 30)));
        $transition = $config['transition'] ?? 'none';
        if (! in_array($transition, self::TRANSITIONS, true)) {
            throw new RuntimeException('Unsupported transition.');
        }
        $sceneDurations = $this->durations($count, $duration, $config['frames'] ?? []);
        $overlap = $transition === 'none' || $count === 1 ? 0.0 : min(max(0.1, (float) ($config['transitionDuration'] ?? 0.4)), min($sceneDurations) / 2);
        $normalized = [];
        foreach ($clips as $index => $clip) {
            // Extra half-transition handles preserve the requested total duration and cut positions.
            $handles = ($index > 0 ? $overlap / 2 : 0) + ($index < $count - 1 ? $overlap / 2 : 0);
            $seconds = $sceneDurations[$index] + $handles;
            $path = $directory.'/normalized-'.$index.'.mp4';
            $native = $probes[$index]['durationSeconds'];
            $speed = $seconds / max(0.01, $native);
            // A provider can return a different aspect ratio from the prepared
            // photo. Only an explicit crop may discard that clip's edge content.
            // Studio's implicit preparation is fit; legacy callers retain cover.
            $method = $config['frames'][$index]['method'] ?? (! empty($config['studioWorkspace']) ? 'fit' : 'crop');
            $framing = in_array($method, ['fit', 'extend'], true)
                ? "scale={$width}:{$height}:force_original_aspect_ratio=decrease:force_divisible_by=2,pad={$width}:{$height}:(ow-iw)/2:(oh-ih)/2:color=0x101828"
                : "scale={$width}:{$height}:force_original_aspect_ratio=increase,crop={$width}:{$height}";
            $filter = $framing.',setsar=1,setpts='.$this->n($speed).'*(PTS-STARTPTS),fps=30,tpad=stop_mode=clone:stop_duration=1,trim=duration='.$this->n($seconds).',settb=AVTB,format=yuv420p';
            $args = ['-y', '-i', $clip];
            if ($original && ! $probes[$index]['hasAudio']) {
                array_push($args, '-f', 'lavfi', '-i', 'anullsrc=r=48000:cl=stereo');
            }
            array_push($args, '-map', '0:v:0', '-vf', $filter);
            if ($original) {
                $tempo = $probes[$index]['hasAudio'] ? ','.$this->tempo($native / $seconds) : '';
                array_push($args, '-map', $probes[$index]['hasAudio'] ? '0:a:0' : '1:a:0', '-af',
                    'aformat=sample_fmts=flt:sample_rates=48000:channel_layouts=stereo'.$tempo.',apad,atrim=duration='.$this->n($seconds).',asetpts=PTS-STARTPTS',
                    '-c:a', 'aac', '-ac', '2', '-ar', '48000', '-b:a', '192k');
            } else {
                $args[] = '-an';
            }
            array_push($args, '-t', $this->n($seconds), '-c:v', 'libx264', '-preset', 'veryfast', '-pix_fmt', 'yuv420p', $path);
            $this->run($args);
            $normalized[] = $path;
        }
        $assembled = $directory.'/assembled.mp4';
        if ($overlap > 0) {
            $args = ['-y', '-filter_complex_threads', '1'];
            foreach ($normalized as $path) {
                array_push($args, '-i', $path);
            }
            $filters = [];
            foreach ($normalized as $index => $path) {
                $filters[] = "[{$index}:v]settb=AVTB,setpts=PTS-STARTPTS,fps=30[v{$index}]";
                if ($original) {
                    $filters[] = "[{$index}:a]asetpts=PTS-STARTPTS[a{$index}]";
                }
            }
            $previous = 'v0';
            $previousAudio = 'a0';
            $elapsed = 0;
            for ($index = 1; $index < $count; $index++) {
                $elapsed += $sceneDurations[$index - 1];
                $next = 'join'.$index;
                $filters[] = "[{$previous}][v{$index}]xfade=transition={$transition}:duration=".$this->n($overlap).':offset='.$this->n($elapsed - $overlap / 2)."[{$next}]";
                $previous = $next;
                if ($original) {
                    $nextAudio = 'audiojoin'.$index;
                    $filters[] = "[{$previousAudio}][a{$index}]acrossfade=d=".$this->n($overlap).":c1=tri:c2=tri[{$nextAudio}]";
                    $previousAudio = $nextAudio;
                }
            }
            array_push($args, '-filter_complex', implode(';', $filters), '-map', '['.$previous.']', '-t', $this->n($duration));
            if ($original) {
                array_push($args, '-map', '['.$previousAudio.']', '-c:a', 'aac', '-ac', '2', '-ar', '48000', '-b:a', '192k');
            } else {
                $args[] = '-an';
            }
            array_push($args, '-c:v', 'libx264', '-preset', 'veryfast', '-pix_fmt', 'yuv420p', $assembled);
            $this->run($args);
        } else {
            $list = $directory.'/assemble.txt';
            file_put_contents($list, implode("\n", array_map(fn ($path) => "file '".str_replace("'", "'\\''", str_replace('\\', '/', $path))."'", $normalized)));
            $this->run(['-y', '-f', 'concat', '-safe', '0', '-i', $list, '-c', 'copy', '-t', $this->n($duration), $assembled]);
        }
        $text = $this->textFilters($config['text'] ?? [], $width, $height, $sceneDurations, $directory);
        $args = ['-y', '-i', $assembled];
        if ($music) {
            array_push($args, '-stream_loop', '-1', '-i', $musicPath);
        }
        array_push($args, '-map', '0:v:0');
        if ($text === []) {
            array_push($args, '-c:v', 'copy');
        } else {
            array_push($args, '-vf', implode(',', $text), '-c:v', 'libx264', '-preset', 'veryfast', '-pix_fmt', 'yuv420p');
        }
        if ($original || $music) {
            $filters = [];
            if ($music) {
                $filters[] = '[1:a:0]aresample=48000,volume='.$this->n($config['audio']['musicVolume']).',apad,atrim=duration='.$this->n($duration).',asetpts=PTS-STARTPTS[music]';
            }
            if ($original && $music) {
                $filters[] = '[0:a:0][music]amix=inputs=2:duration=first:normalize=0,alimiter=limit=0.95[mixed]';
                $source = 'mixed';
            } else {
                $source = $music ? 'music' : '0:a:0';
            }
            $fade = $config['audio']['fade'] ? ',afade=t=in:st=0:d=0.5,afade=t=out:st='.$this->n(max(0, $duration - 0.5)).':d=0.5' : '';
            $filters[] = '['.$source.']apad,atrim=duration='.$this->n($duration).$fade.'[finished_audio]';
            array_push($args, '-filter_complex', implode(';', $filters), '-map', '[finished_audio]', '-c:a', 'aac', '-ac', '2', '-ar', '48000', '-b:a', '192k');
        } else {
            $args[] = '-an';
        }
        array_push($args, '-t', $this->n($duration), '-movflags', '+faststart', $output);
        $this->run($args);

        return $this->probe($output) + ['resolution' => $config['resolution'], 'originalAudio' => (bool) $original, 'musicAudio' => (bool) $music];
    }

    public function durations(int $count, float $total, array $frames): array
    {
        $weights = count($frames) === $count ? array_map(fn ($f) => max(0.5, (float) ($f['duration'] ?? 5)), $frames) : array_fill(0, $count, 5.0);
        $sum = array_sum($weights);

        return array_map(fn ($weight) => $total * $weight / $sum, $weights);
    }

    public function textFilters(array $text, int $width, int $height, array $durations, string $directory): array
    {
        $style = $text['style'] ?? 'none';
        if ($style === 'none' || (trim((string) ($text['title'] ?? '')) === '' && trim((string) ($text['subtitle'] ?? '')) === '')) {
            return [];
        }
        if (! in_array($style, ['minimal', 'editorial', 'lower-third', 'graphic'], true)) {
            throw new RuntimeException('Unsupported text style.');
        }
        $font = $this->font($style === 'editorial');
        $safe = (int) round(min($width, $height) * 0.07);
        $fontSize = max(24, (int) round(min($width, $height) * 0.047));
        $subSize = (int) round($fontSize * 0.58);
        $lineWidth = max(14, (int) floor(($width - $safe * 2) / ($fontSize * 0.57)));
        $title = $this->wrap((string) ($text['title'] ?? ''), $lineWidth);
        $subtitle = $this->wrap((string) ($text['subtitle'] ?? ''), (int) ($lineWidth * 1.6));
        file_put_contents($directory.'/title.txt', $title);
        file_put_contents($directory.'/subtitle.txt', $subtitle);
        $titleHeight = (substr_count($title, "\n") + 1) * ($fontSize + 8);
        $totalHeight = $titleHeight + ($subtitle !== '' ? (substr_count($subtitle, "\n") + 1) * ($subSize + 6) : 0) + 12;
        $y = match ($text['position'] ?? 'bottom') {
            'top' => $safe, 'center' => (int) (($height - $totalHeight) / 2), default => $height - $safe - $totalHeight
        };
        $x = $style === 'lower-third' || $style === 'graphic' ? (string) $safe : '(w-text_w)/2';
        $windows = [];
        $elapsed = 0;
        $last = count($durations) - 1;
        foreach ($durations as $index => $seconds) {
            if (($text['timing'] ?? 'last-scene') === 'all' || $index === $last) {
                $windows[] = 'between(t,'.$this->n($elapsed).','.$this->n($elapsed + min(3, $seconds)).')';
            }
            $elapsed += $seconds;
        }
        $enable = "enable='".implode('+', $windows)."'";
        $filters = [];
        if (in_array($style, ['lower-third', 'graphic'], true)) {
            $boxX = $safe - 24;
            $boxY = $y - 24;
            $boxW = $width - 2 * $safe + 48;
            $boxH = $totalHeight + 48;
            $color = $style === 'graphic' ? '0x142D50@0.94' : 'black@0.62';
            $filters[] = "drawbox=x={$boxX}:y={$boxY}:w={$boxW}:h={$boxH}:color={$color}:t=fill:{$enable}";
            if ($style === 'graphic') {
                $filters[] = "drawbox=x={$boxX}:y={$boxY}:w=7:h={$boxH}:color=0x4BA7FF:t=fill:{$enable}";
            }
        }
        $base = "fontfile='".$this->filterPath($font)."':fontcolor=white:shadowcolor=black@0.6:shadowx=2:shadowy=2:expansion=none:{$enable}";
        if ($title !== '') {
            $filters[] = "drawtext={$base}:textfile='".$this->filterPath($directory.'/title.txt')."':fontsize={$fontSize}:line_spacing=8:x={$x}:y={$y}";
        }
        if ($subtitle !== '') {
            $subY = $y + ($title !== '' ? $titleHeight + 12 : 0);
            $filters[] = "drawtext={$base}:textfile='".$this->filterPath($directory.'/subtitle.txt')."':fontsize={$subSize}:line_spacing=6:x={$x}:y={$subY}";
        }

        return $filters;
    }

    private function wrap(string $value, int $width): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value);

        return wordwrap($value, $width, "\n", true);
    }

    private function font(bool $serif): string
    {
        foreach (array_filter([config('services.fal.reel_font'), $serif ? '/usr/share/fonts/truetype/dejavu/DejaVuSerif.ttf' : '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', '/usr/share/fonts/truetype/liberation2/LiberationSans-Regular.ttf', 'C:/Windows/Fonts/arial.ttf']) as $path) {
            if (is_file($path)) {
                return $path;
            }
        }
        throw new RuntimeException('A render font is required. Configure FAL_REEL_FONT with an installed font path.');
    }

    private function probe(string $path): array
    {
        $process = new Process([config('studio.ffprobe_path', 'ffprobe'), '-v', 'error', '-show_entries', 'format=duration:stream=codec_type,width,height', '-of', 'json', $path]);
        $process->setTimeout(60);
        $process->mustRun();

        $info = json_decode($process->getOutput(), true, 64, JSON_THROW_ON_ERROR);
        $video = collect($info['streams'] ?? [])->firstWhere('codec_type', 'video');

        return ['width' => (int) ($video['width'] ?? 0), 'height' => (int) ($video['height'] ?? 0),
            'durationSeconds' => max(0.01, (float) ($info['format']['duration'] ?? 0)),
            'hasAudio' => collect($info['streams'] ?? [])->contains('codec_type', 'audio')];
    }

    private function tempo(float $value): string
    {
        $parts = [];
        while ($value < 0.5) {
            $parts[] = 'atempo=0.5';
            $value /= 0.5;
        }
        while ($value > 2) {
            $parts[] = 'atempo=2';
            $value /= 2;
        }
        $parts[] = 'atempo='.$this->n($value);

        return implode(',', $parts);
    }

    private function filterPath(string $path): string
    {
        return str_replace(['\\', ':', "'"], ['/', '\\:', "\\'"], $path);
    }

    private function n(float $value): string
    {
        return number_format($value, 6, '.', '');
    }

    private function run(array $args): void
    {
        // Input-side -threads does not constrain libx264's output encoder.
        array_splice($args, count($args) - 1, 0, ['-threads:v', '2', '-threads:a', '2']);
        $process = new Process(array_merge([config('studio.ffmpeg_path', 'ffmpeg'), '-nostdin', '-threads', '2', '-filter_threads', '1', '-filter_complex_threads', '1'], $args));
        $process->setTimeout(1200);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('Video composition failed: '.substr($process->getErrorOutput(), -800));
        }
    }
}
