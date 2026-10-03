<?php

use App\Models\Shoot;
use App\Models\User;
use App\Services\Shoots\ShootRawUploadBatchService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $database, $shootId, $actorId, $scope, $worker, $barrier] = $argv;
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $database,
    'database.connections.sqlite.busy_timeout' => 5, 'cache.default' => 'array']);
DB::purge('sqlite');
$shoot = Shoot::findOrFail($shootId);
$actor = User::findOrFail($actorId);
file_put_contents($barrier.'.ready-'.$worker, 'ready');
$deadline = microtime(true) + 90;
while (!is_file($barrier.'.go')) {
    if (microtime(true) > $deadline) { throw new RuntimeException('Barrier timeout'); }
    usleep(10000);
}
$service = app(ShootRawUploadBatchService::class);
for ($i = 0; $i < 5; $i++) {
    $service->reserve($shoot, $actor, 'parallel-'.$worker.'-'.$i, 7, (int) $scope, 'photo', 5);
}
$service->reserve($shoot, $actor, 'shared-identity', 7, (int) $scope, 'photo', 5);
echo "RESERVATIONS_OK\n";
