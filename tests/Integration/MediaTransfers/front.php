<?php

// Isolated integration harness: production routes/signatures and MediaStorage,
// temporary fixture roots, plus synthetic API probes for worker occupancy.
require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$request = PHP_SAPI === 'cli'
    ? Illuminate\Http\Request::create('http://127.0.0.1:8080')
    : Illuminate\Http\Request::capture();
$app->instance('request', $request);
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
config()->set([
    'app.url' => 'http://127.0.0.1:8080',
    'media.local_disk' => 'local',
    'media.legacy_public_disk' => 'public',
    'media.tiered_storage_enabled' => false,
    'media.download_offload' => true,
    'media.performance_shoot_ids' => [],
    'media.read_from_r2' => false,
    'media.r2_only' => false,
    'filesystems.disks.local.root' => '/tmp/repro-media-integration/private',
    'filesystems.disks.public.root' => '/tmp/repro-media-integration/legacy',
]);
$key = 'shoots/991/web/été photo #1%.jpg';
if (PHP_SAPI === 'cli') {
    Illuminate\Support\Facades\URL::forceRootUrl('http://127.0.0.1:8080');
    echo json_encode([
        'valid' => Illuminate\Support\Facades\URL::temporarySignedRoute('api.public.shoot-media.file', now()->addHour(), ['path' => $key]),
        'expired' => Illuminate\Support\Facades\URL::temporarySignedRoute('api.public.shoot-media.file', now()->subMinute(), ['path' => $key]),
    ]);
    exit;
}
if (str_starts_with($_SERVER['REQUEST_URI'], '/__integration/')) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'received_bytes' => (int) ($_SERVER['CONTENT_LENGTH'] ?? 0)]);
    exit;
}
$response = $kernel->handle($request);
$response->headers->set('X-Integration-Url', $request->url());
$response->send();
$kernel->terminate($request, $response);
