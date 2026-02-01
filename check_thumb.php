<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$record = App\Models\ShootFile::whereNotNull('thumbnail_path')->orderByDesc('id')->first();
if (!$record) {
    echo "NO_RECORD\n";
    exit;
}

$thumbPath = $record->thumbnail_path;
$disk = Illuminate\Support\Facades\Storage::disk('public');
$abs = $disk->path($thumbPath);
$url = $disk->url($thumbPath);

$exists = file_exists($abs) ? 'yes' : 'no';

echo "ID={$record->id}\n";
echo "THUMB_PATH={$thumbPath}\n";
echo "ABS={$abs}\n";
echo "EXISTS={$exists}\n";
echo "URL={$url}\n";
