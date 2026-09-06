<?php

namespace App\Services\Studio;

use RuntimeException;
use Symfony\Component\Process\Process;

/** Deterministic finishing over real provider clips. Optional effects never imply camera continuity. */
class ReelCompositionService
{
    public const TRANSITIONS = ['none', 'fade', 'fadeblack', 'dissolve', 'slideleft', 'slideright', 'smoothleft', 'smoothright', 'wipeleft', 'wiperight'];

    public function compose(array $clips, string $output, string $directory, array $config): void
    {
        $count = count($clips);
        if (! $count) {
            throw new RuntimeException('No clips were available for assembly.');
        }
        [$width, $height] = match ($config['ratio'] ?? '9:16') {
            '16:9' => [1920, 1080], '1:1' => [1080, 1080], '4:5' => [1080, 1350], default => [1080, 1920],
        };
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
            $native = $this->duration($clip);
            $speed = $seconds / max(0.01, $native);
            $filter = "scale={$width}:{$height}:force_original_aspect_ratio=increase,crop={$width}:{$height},setsar=1,setpts=".$this->n($speed).'*(PTS-STARTPTS),fps=30,tpad=stop_mode=clone:stop_duration=1,trim=duration='.$this->n($seconds).',settb=AVTB,format=yuv420p';
            $this->run(['-y', '-i', $clip, '-vf', $filter, '-an', '-c:v', 'libx264', '-preset', 'veryfast', '-pix_fmt', 'yuv420p', $path]);
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
            }
            $previous = 'v0';
            $elapsed = 0;
            for ($index = 1; $index < $count; $index++) {
                $elapsed += $sceneDurations[$index - 1];
                $next = 'join'.$index;
                $filters[] = "[{$previous}][v{$index}]xfade=transition={$transition}:duration=".$this->n($overlap).':offset='.$this->n($elapsed - $overlap / 2)."[{$next}]";
                $previous = $next;
            }
            array_push($args, '-filter_complex', implode(';', $filters), '-map', '['.$previous.']', '-t', $this->n($duration), '-an', '-c:v', 'libx264', '-preset', 'veryfast', '-pix_fmt', 'yuv420p', $assembled);
            $this->run($args);
        } else {
            $list = $directory.'/assemble.txt';
            file_put_contents($list, implode("\n", array_map(fn ($path) => "file '".str_replace("'", "'\\''", str_replace('\\', '/', $path))."'", $normalized)));
            $this->run(['-y', '-f', 'concat', '-safe', '0', '-i', $list, '-c', 'copy', '-t', $this->n($duration), $assembled]);
        }
        $filters = $this->textFilters($config['text'] ?? [], $width, $height, $sceneDurations, $directory);
        if ($filters === []) {
            if (! copy($assembled, $output)) {
                throw new RuntimeException('The finished reel could not be saved.');
            }
        } else {
            $this->run(['-y', '-i', $assembled, '-vf', implode(',', $filters), '-an', '-c:v', 'libx264', '-preset', 'veryfast', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', $output]);
        }
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

    private function duration(string $path): float
    {
        $process = new Process(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=noprint_wrappers=1:nokey=1', $path]);
        $process->setTimeout(60);
        $process->mustRun();

        return max(0.01, (float) trim($process->getOutput()));
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
        $process = new Process(array_merge(['ffmpeg', '-threads', '2'], $args));
        $process->setTimeout(1200);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('Video composition failed: '.substr($process->getErrorOutput(), -800));
        }
    }
}
