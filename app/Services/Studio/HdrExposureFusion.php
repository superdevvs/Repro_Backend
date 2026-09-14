<?php

namespace App\Services\Studio;

use Illuminate\Support\Facades\File;
use Intervention\Image\ImageManager;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class HdrExposureFusion
{
    public function merge(array $sources): string
    {
        $finder = new ExecutableFinder;
        $align = $finder->find('align_image_stack');
        $enfuse = $finder->find('enfuse');
        if (! $align || ! $enfuse) {
            throw new RuntimeException('HDR merging requires align_image_stack and enfuse on the Studio worker.');
        }
        $directory = sys_get_temp_dir().'/studio-hdr-'.bin2hex(random_bytes(16));
        File::makeDirectory($directory, 0700);
        try {
            $inputs = [];
            $images = ImageManager::gd();
            foreach ($sources as $index => $bytes) {
                $image = $images->read($bytes)->orient()->scaleDown(width: 4096, height: 4096);
                $path = $directory.'/source-'.$index.'.png';
                $image->toPng()->save($path);
                $inputs[] = $path;
            }
            $prefix = $directory.'/aligned-';
            $this->run([$align, '-a', $prefix, '-C', '-m', '--use-given-order', ...$inputs]);
            $aligned = glob($prefix.'*.tif') ?: [];
            sort($aligned);
            if (count($aligned) !== count($sources)) {
                throw new RuntimeException('HDR alignment did not produce all exposures.');
            }
            $output = $directory.'/merged.png';
            $this->run([$enfuse, '--exposure-weight=1', '--saturation-weight=0.2', '--contrast-weight=0.2', '--output='.$output, ...$aligned]);

            return (string) $images->read($output)->toJpeg(96);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    protected function run(array $command): void
    {
        (new Process($command, null, ['OMP_NUM_THREADS' => '2'], null, 240))->mustRun();
    }
}
