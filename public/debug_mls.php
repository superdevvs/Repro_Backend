<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$files = DB::table('shoot_files')->where('shoot_id', 81)->limit(2)->get(['id','path','web_path','storage_path','dropbox_path']);
$resolved = [];
foreach ($files as $f) {
    $resolved[] = [
        'id' => $f->id,
        'path' => $f->path,
        'web_path' => $f->web_path,
        'storage_path' => $f->storage_path,
        'dropbox_path' => $f->dropbox_path,
        'url_from_path' => $f->path ? url('storage/' . ltrim($f->path, '/')) : null,
        'url_from_web' => $f->web_path ? url('storage/' . ltrim($f->web_path, '/')) : null,
    ];
}
echo json_encode($resolved, JSON_PRETTY_PRINT);
