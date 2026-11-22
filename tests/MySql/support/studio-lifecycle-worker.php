<?php

if (getenv('DB_CONNECTION') !== 'mysql' || getenv('DB_DATABASE') !== 'repro_mysql_test' || getenv('DB_USERNAME') !== 'repro_test') {
    throw new RuntimeException('Lifecycle workers only accept the isolated MySQL test account.');
}
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__.'/studio-financial-fixtures.php';
Tests\MySql\Support\configureStudioFinancialRace();
[$directory, $id] = array_slice($argv, 1);
$fixture = json_decode(file_get_contents($directory.'/fixture.json'), true, 512, JSON_THROW_ON_ERROR);
$action = $fixture['actions'][(int) $id - 1];
if ($action === 'revision') {
    Tests\MySql\Support\configureStudioRevisionRace();
}
App\Models\ListingStudioOrderItem::updating(fn () => usleep(150_000));
App\Models\ListingStudioCreditReservation::updating(fn () => usleep(150_000));
App\Models\ListingStudioOrderFundingEffect::creating(fn () => usleep(150_000));
file_put_contents($directory.'/ready-'.$id, '1');
$deadline = microtime(true) + 15;
while (! is_file($directory.'/start') && microtime(true) < $deadline) {
    usleep(10_000);
}
if (! is_file($directory.'/start')) {
    throw new RuntimeException('Lifecycle race barrier timed out.');
}
try {
    $service = app(App\Services\ListingStudio\ListingStudioOrderService::class);
    if ($action === 'claim') {
        echo $service->beginItem($fixture['item'], $fixture['attempt']) ? 'claimed' : 'busy';
    } elseif ($action === 'complete') {
        $service->completeItem($fixture['item'], $fixture['descriptor']);
        echo 'completed';
    } elseif ($action === 'cancel') {
        $service->cancelItem($fixture['item'], 'order_cancelled');
        echo 'cancelled';
    } elseif ($action === 'cancel_unpaid') {
        $service->cancelUnpaidOrder(App\Models\User::findOrFail($fixture['client']), $fixture['order']);
        echo 'cancelled';
    } elseif ($action === 'pay') {
        $service->confirmPayment($fixture['order'], $fixture['evidence']);
        echo 'settled';
    } elseif ($action === 'refund') {
        $service->confirmRefund($fixture['order'], $fixture['refunds'][(int) $id - 1]);
        echo 'refunded';
    } elseif ($action === 'revision') {
        $revision = app(App\Services\ListingStudio\ListingStudioAiRevisionService::class)->request(
            App\Models\User::findOrFail($fixture['client']), $fixture['order'],
            $fixture['instructions'][(int) $id - 1], $fixture['keys'][(int) $id - 1]);
        echo 'revision:'.$revision->id;
    } else {
        throw new RuntimeException('Unknown lifecycle action.');
    }
} catch (App\Exceptions\PublicApiException $error) {
    echo $error->errorCode;
}
