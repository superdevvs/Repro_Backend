<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$shoot = App\Models\Shoot::where('address', 'like', '%10 Monroe%')->orderByDesc('id')->first();
if (!$shoot) {
    echo "NO_SHOOT\n";
    exit;
}

echo "SHOOT_ID={$shoot->id} ADDRESS={$shoot->address}\n";

$files = App\Models\ShootFile::where('shoot_id', $shoot->id)
    ->orderByDesc('id')
    ->limit(5)
    ->get();

$disk = Illuminate\Support\Facades\Storage::disk('public');

foreach ($files as $file) {
    $thumb = $file->thumbnail_path;
    $web = $file->web_path;
    $exists = $thumb ? ($disk->exists($thumb) ? 'yes' : 'no') : 'no-thumb';
    $thumbUrl = $thumb ? $disk->url($thumb) : '';
    echo "FILE={$file->id} name={$file->filename} thumb={$thumb} exists={$exists} url={$thumbUrl}\n";
}
