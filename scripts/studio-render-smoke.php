<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$source = isset($argv[1]) && is_file($argv[1]) ? $argv[1] : null;
$total = $source ? 10 : 6;
$directory = storage_path('app/studio-smoke/'.($source ? 'real-render' : 'render'));
if (! is_dir($directory)) {
    mkdir($directory, 0755, true);
}
foreach (['red', 'blue'] as $index => $color) {
    if ($source) {
        copy($source, $directory.'/clip'.$index.'.mp4');

        continue;
    }
    (new Symfony\Component\Process\Process(['ffmpeg', '-y', '-f', 'lavfi', '-i', "color=c={$color}:s=320x180:r=30:d=3", '-c:v', 'libx264', '-pix_fmt', 'yuv420p', $directory.'/clip'.$index.'.mp4']))->mustRun();
}
$service = app(App\Services\Studio\ReelCompositionService::class);
$service->compose([$directory.'/clip0.mp4', $directory.'/clip1.mp4'], $directory.'/finished.mp4', $directory, ['ratio' => '16:9', 'duration' => $total, 'transition' => $source ? 'none' : 'fade', 'transitionDuration' => 0.4, 'text' => ['style' => 'graphic', 'title' => "123 Test Street: 50% 'ready'", 'subtitle' => 'Public fixture render test', 'timing' => 'last-scene', 'position' => 'bottom']]);
$probe = new Symfony\Component\Process\Process(['ffprobe', '-v', 'error', '-show_entries', 'format=duration:stream=width,height', '-of', 'json', $directory.'/finished.mp4']);
$probe->mustRun();
$info = json_decode($probe->getOutput(), true);
$duration = (float) $info['format']['duration'];
if (abs($duration - $total) > 0.1 || $info['streams'][0]['width'] !== 1920 || $info['streams'][0]['height'] !== 1080) {
    throw new RuntimeException('Unexpected output geometry/duration.');
}
file_put_contents($directory.'/verification.json', json_encode($info, JSON_PRETTY_PRINT));
echo "Real FFmpeg transition + graphic text render passed: 1920x1080, {$duration}s\n";
