<?php

// Explicit, bounded integration check against a public fixture. Never point this at customer media.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$fixture = $argv[1] ?? '';
if (! is_file($fixture)) {
    fwrite(STDERR, "Provide a public fixture image path.\n");
    exit(1);
}
$directory = storage_path('app/studio-smoke');
if (! is_dir($directory)) {
    mkdir($directory, 0755, true);
}
$statePath = $directory.'/providers.json';
$state = is_file($statePath) ? json_decode(file_get_contents($statePath), true) : [];
$state ??= [];
$save = function () use (&$state, $statePath): void {
    file_put_contents($statePath, json_encode($state, JSON_PRETTY_PRINT));
};
// FFmpeg also decodes formats that an older local GD build may not support.
$decoded = $directory.'/public-fixture.jpg';
(new Symfony\Component\Process\Process(['ffmpeg', '-y', '-i', $fixture, '-frames:v', '1', $decoded]))->mustRun();
$image = Intervention\Image\ImageManager::gd()->read($decoded)->scaleDown(width: 512, height: 512);
$jpeg = (string) $image->toJpeg(90);
$fal = app(App\Services\FalService::class);
foreach (['photo', 'outpaint'] as $kind) {
    if (($state[$kind]['completed'] ?? false) === true) {
        echo "$kind already verified\n";

        continue;
    }
    try {
        if (empty($state[$kind]['requestId'])) {
            $id = $kind === 'photo'
                ? $fal->submitImageEditFromBuffer($jpeg, 'public-studio-fixture.jpg', 'image/jpeg', 'enhance', ['prompt' => 'Subtly balance exposure in this real estate photograph. Preserve the architecture, framing and existing contents.'])['request_id']
                : $fal->submitModel(config('services.fal.outpaint_model'), ['image_url' => 'data:image/jpeg;base64,'.base64_encode($jpeg), 'expand_top' => 64, 'expand_bottom' => 64, 'expand_left' => 0, 'expand_right' => 0, 'auto_crop' => false, 'output_format' => 'jpeg']);
            $state[$kind] = ['requestId' => $id, 'submitted' => true, 'completed' => false];
            $save();
        }
        $id = $state[$kind]['requestId'];
        $deadline = microtime(true) + 240;
        do {
            $status = $kind === 'photo' ? strtoupper($fal->imageEditStatus($id)['status'] ?? 'PROCESSING') : $fal->modelStatus(config('services.fal.outpaint_model'), $id);
            if ($status === 'COMPLETED') {
                $url = $kind === 'photo' ? $fal->imageEditResult($id)['edited_image_url'] : $fal->modelImageResult(config('services.fal.outpaint_model'), $id);
                $bytes = Illuminate\Support\Facades\Http::timeout(60)->get($url)->throw()->body();
                $size = getimagesizefromstring($bytes);
                if (! $size) {
                    throw new RuntimeException('Invalid image result');
                }
                file_put_contents($directory.'/'.$kind.'.jpg', $bytes);
                $state[$kind]['completed'] = true;
                $state[$kind]['dimensions'] = [$size[0], $size[1]];
                $save();
                echo "$kind completed and valid\n";
                break;
            }
            if (in_array($status, ['FAILED', 'ERROR', 'CANCELLED'], true)) {
                $state[$kind]['providerFailed'] = true;
                $save();
                echo "$kind provider failed\n";
                break;
            }
            sleep(5);
        } while (microtime(true) < $deadline);
        if (! $state[$kind]['completed']) {
            echo "$kind not completed within bounded poll\n";
        }
    } catch (Throwable $exception) {
        $state[$kind]['verified'] = false;
        $state[$kind]['errorClass'] = get_class($exception);
        $save();
        echo "$kind integration unavailable (details withheld)\n";
    }
}
if (! isset($state['openai']['completed'])) {
    try {
        Illuminate\Support\Facades\Storage::disk('public')->put('studio-smoke/public-fixture.jpg', $jpeg);
        $w = new App\Models\StudioWorkspace(['media' => [['id' => 'fixture']], 'outputs' => [['mediaId' => 'fixture', 'kind' => 'image', 'version' => 1, 'path' => 'studio-smoke/public-fixture.jpg']]]);
        $regions = app(App\Services\Studio\WorkspaceProcessor::class)->segments($w, 'fixture');
        $state['openai'] = ['completed' => true, 'regionCount' => count($regions), 'regions' => $regions];
        $save();
        echo 'OpenAI returned '.count($regions)." validated regions\n";
    } catch (Throwable $exception) {
        $state['openai'] = ['completed' => false, 'errorClass' => get_class($exception)];
        $save();
        echo "OpenAI integration unavailable (details withheld)\n";
    }
}
echo json_encode(['photoVerified' => $state['photo']['completed'] ?? false, 'outpaintVerified' => $state['outpaint']['completed'] ?? false, 'openaiVerified' => $state['openai']['completed'] ?? false]).PHP_EOL;
