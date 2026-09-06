<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! isset($argv[1], $argv[2]) || ! is_file($argv[1]) || ! is_file($argv[2])) {
    fwrite(STDERR, "Provide two public fixture image paths.\n");
    exit(1);
}
$directory = storage_path('app/studio-smoke');
if (! is_dir($directory)) {
    mkdir($directory, 0755, true);
}
$statePath = $directory.'/walkthrough.json';
$state = is_file($statePath) ? json_decode(file_get_contents($statePath), true) : [];
$save = function () use (&$state, $statePath): void {
    file_put_contents($statePath, json_encode($state, JSON_PRETTY_PRINT));
};
$fal = app(App\Services\FalService::class);
try {
    if (empty($state['requestId'])) {
        $urls = [];
        foreach ([$argv[1], $argv[2]] as $index => $fixture) {
            $path = $directory.'/walkthrough-source-'.$index.'.jpg';
            (new Symfony\Component\Process\Process(['ffmpeg', '-y', '-i', $fixture, '-frames:v', '1', '-vf', 'scale=768:-2', $path]))->mustRun();
            $urls[] = $fal->uploadImage(file_get_contents($path), 'image/jpeg');
        }
        $state = ['requestId' => $fal->submitWalkthroughClip($urls[0], $urls[1], 'A slow, smooth drone-like approach toward this home. Move continuously from the starting photograph toward the ending photograph. Preserve architecture and natural perspective, no cuts, no text.'), 'submitted' => true, 'completed' => false];
        $save();
        echo "One start/end-conditioned clip submitted\n";
    }
    if (empty($state['completed'])) {
        $deadline = microtime(true) + 900;
        do {
            $status = $fal->modelStatus(config('services.fal.walkthrough_model'), $state['requestId']);
            if ($status === 'COMPLETED') {
                $url = $fal->modelVideoResult(config('services.fal.walkthrough_model'), $state['requestId']);
                $bytes = Illuminate\Support\Facades\Http::timeout(120)->get($url)->throw()->body();
                $path = $directory.'/walkthrough.mp4';
                file_put_contents($path, $bytes);
                $probe = new Symfony\Component\Process\Process(['ffprobe', '-v', 'error', '-show_entries', 'format=duration:stream=width,height', '-of', 'json', $path]);
                $probe->mustRun();
                $info = json_decode($probe->getOutput(), true);
                $state['completed'] = true;
                $state['duration'] = (float) $info['format']['duration'];
                $state['geometry'] = [$info['streams'][0]['width'], $info['streams'][0]['height']];
                $save();
                echo 'Live start/end clip verified: '.$state['duration']." seconds\n";
                break;
            }
            if (in_array($status, ['FAILED', 'ERROR', 'CANCELLED'], true)) {
                $state['providerFailed'] = true;
                $save();
                echo "Provider did not complete the test clip\n";
                break;
            }
            sleep(10);
        } while (microtime(true) < $deadline);
    }
    echo json_encode(['walkthroughVerified' => $state['completed'] ?? false, 'duration' => $state['duration'] ?? null]).PHP_EOL;
} catch (Throwable $exception) {
    $state['verified'] = false;
    $state['errorClass'] = get_class($exception);
    $save();
    echo "Live walkthrough verification unavailable (details withheld)\n";
}
