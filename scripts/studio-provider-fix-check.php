<?php

// Explicit paid check using only the existing public fixture, isolated from application records.
// Run inside the local Studio container; retained request IDs make reruns resume safely.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! app()->environment('local') || ! is_file(storage_path('app/studio-smoke/public-fixture.jpg'))) {
    throw new RuntimeException('This check requires the local runtime and the public fixture.');
}
$directory = sys_get_temp_dir().'/studio-provider-fix-check';
if (! is_dir($directory)) {
    mkdir($directory, 0700, true);
}
$database = $directory.'/fixtures.sqlite';
if (! is_file($database)) {
    touch($database);
}
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $database,
    'cache.default' => 'array', 'filesystems.disks.public.root' => $directory.'/public',
    'filesystems.disks.local.root' => $directory.'/private', 'studio_uploads.disk' => 'public']);
Illuminate\Support\Facades\DB::purge('sqlite');
Illuminate\Support\Facades\Storage::forgetDisk(['public', 'local']);
Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
$user = App\Models\User::firstOrCreate(['email' => 'public-fixture@studio.test'], ['username' => 'public-fixture', 'name' => 'Public fixture', 'role' => 'admin', 'password' => Illuminate\Support\Str::random(64), 'metadata' => ['team_id' => 100]]);
$image = Intervention\Image\ImageManager::gd()->read(storage_path('app/studio-smoke/public-fixture.jpg'))->cover(1600, 1067);
$ref = "studio/uploads/100/{$user->id}/public-fixture.jpg";
Illuminate\Support\Facades\Storage::disk('public')->put($ref, (string) $image->toJpeg(92));
$processor = app(App\Services\Studio\WorkspaceProcessor::class);
$result = [];
foreach (['extend' => 'prepare', 'small-region' => 'revision'] as $name => $type) {
    $workspace = App\Models\StudioWorkspace::firstOrCreate(['name' => $name], [
        'created_by' => $user->id, 'team_id' => 100, 'preset_id' => 'property-reel',
        'media' => [['id' => 'fixture', 'mediaRef' => $ref]],
        'config' => ['ratio' => '9:16', 'frames' => [['mediaId' => 'fixture', 'method' => 'extend']]],
        'status' => $type === 'prepare' ? 'preparing' : 'generating', 'version' => 1,
        'operation' => ['id' => (string) Illuminate\Support\Str::uuid(), 'type' => $type, 'completed' => [], 'requests' => [],
            'payload' => $type === 'prepare' ? [] : ['mediaId' => 'fixture', 'prompt' => 'Subtly balance exposure. Preserve the property and existing contents.', 'region' => ['x' => 0.1, 'y' => 0.1, 'width' => 90 / 1600, 'height' => 480 / 1067]]],
    ]);
    if ($workspace->isBusy()) {
        $processor->process($workspace, $workspace->operation['id']);
    }
    $workspace->refresh();
    $output = ($type === 'prepare' ? $workspace->prepared_frames : $workspace->outputs)[0] ?? null;
    if (! $output) {
        throw new RuntimeException('The public fixture operation did not produce an image.');
    }
    $bytes = Illuminate\Support\Facades\Storage::disk('public')->get($output['path']);
    $size = getimagesizefromstring($bytes);
    file_put_contents(storage_path('app/studio-smoke/'.$name.'-fixed.jpg'), $bytes);
    $result[$name] = ['status' => $workspace->status, 'dimensions' => [$size[0], $size[1]]];
    echo json_encode([$name => $result[$name]]).PHP_EOL;
}
file_put_contents(storage_path('app/studio-smoke/provider-fix-verification.json'), json_encode($result, JSON_PRETTY_PRINT));
