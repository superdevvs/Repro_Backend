<?php

if (getenv('DB_CONNECTION') !== 'mysql' || getenv('DB_DATABASE') !== 'repro_mysql_test' || getenv('DB_USERNAME') !== 'repro_test') {
    throw new RuntimeException('This worker only accepts the isolated MySQL test database.');
}
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['app.env' => 'testing', 'cache.default' => 'array', 'mail.default' => 'array', 'queue.default' => 'sync', 'hashing.bcrypt.rounds' => 4]);
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\DB::connection()->getPdo();
[$directory, $id] = array_slice($argv, 1);
$fixture = json_decode(file_get_contents($directory.'/fixture.json'), true, 512, JSON_THROW_ON_ERROR);
file_put_contents($directory.'/ready-'.$id, '1');
$deadline = microtime(true) + 15;
while (! is_file($directory.'/start') && microtime(true) < $deadline) {
    usleep(10_000);
}
if (! is_file($directory.'/start')) {
    throw new RuntimeException('Reset race barrier timed out.');
}
$user = app(App\Services\Users\PasswordRecoveryService::class)->consume($fixture['email'], $fixture['token'], 'NewPassword'.$id.'!');
echo $user ? 'consumed' : 'invalid';
