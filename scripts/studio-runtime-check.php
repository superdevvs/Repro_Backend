<?php

// Configuration presence only: never emit credentials, provider responses or environment values.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$ffmpeg = new Symfony\Component\Process\Process(['ffmpeg', '-version']);
$ffprobe = new Symfony\Component\Process\Process(['ffprobe', '-version']);
try {
    $ffmpeg->run();
} catch (Throwable) {
}
try {
    $ffprobe->run();
} catch (Throwable) {
}
echo json_encode(['falConfigured' => filled(config('services.fal.key')), 'openaiConfigured' => filled(config('services.openai.api_key')), 'falTestMode' => (bool) config('services.fal.test_mode'), 'ffmpegAvailable' => $ffmpeg->isSuccessful(), 'ffprobeAvailable' => $ffprobe->isSuccessful(), 'gdAvailable' => extension_loaded('gd'), 'asyncQueueConfigured' => config('services.fal.workspace_queue_connection') !== 'sync', 'databaseLocal' => config('database.default') === 'sqlite' || in_array(config('database.connections.'.config('database.default').'.host'), ['127.0.0.1', 'localhost', 'host.docker.internal', '::1'], true)], JSON_PRETTY_PRINT).PHP_EOL;
