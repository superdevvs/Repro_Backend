<?php

if (getenv('DB_CONNECTION') !== 'mysql' || getenv('DB_DATABASE') !== 'repro_mysql_test' || getenv('DB_USERNAME') !== 'repro_test') {
    throw new RuntimeException('Order workers only accept the isolated MySQL test account.');
}
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__.'/studio-financial-fixtures.php';
Tests\MySql\Support\configureStudioFinancialRace();
[$directory, $id] = array_slice($argv, 1);
$fixture = json_decode(file_get_contents($directory.'/fixture.json'), true, 512, JSON_THROW_ON_ERROR);
file_put_contents($directory.'/ready-'.$id, '1');
$deadline = microtime(true) + 15;
while (! is_file($directory.'/start') && microtime(true) < $deadline) {
    usleep(10_000);
}
if (! is_file($directory.'/start')) {
    throw new RuntimeException('Order race barrier timed out.');
}
App\Models\ListingStudioOrder::creating(fn () => usleep(150_000));
try {
    $index = (int) $id - 1;
    $result = app(App\Services\ListingStudio\ListingStudioOrderService::class)->acceptQuote(
        App\Models\User::findOrFail($fixture['client']), $fixture['quotes'][$index], $fixture['funding'], $fixture['keys'][$index]);
    echo $result['created'] ? 'created' : 'replayed';
} catch (App\Exceptions\PublicApiException $error) {
    echo $error->errorCode;
}
