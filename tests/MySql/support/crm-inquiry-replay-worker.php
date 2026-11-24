<?php

use App\Services\Crm\CrmWebsiteIntakeReceipt;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'mysql'
    || getenv('DB_DATABASE') !== 'repro_mysql_test' || getenv('DB_USERNAME') !== 'repro_test') {
    throw new RuntimeException('The CRM worker requires the isolated MySQL test database.');
}

[$directory, $slot] = array_slice($argv, 1);
if (! in_array($slot, ['a', 'b'], true)) {
    throw new RuntimeException('Unknown CRM replay worker.');
}

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config([
    'crm.enabled' => true, 'crm.email.transport' => 'log', 'crm.google_sync.enabled' => false, 'crm.sms.enabled' => false,
    'cache.default' => 'array', 'mail.default' => 'array', 'queue.default' => 'sync', 'logging.default' => 'null',
]);
Http::preventStrayRequests();
Mail::fake();
Notification::fake();
Queue::fake();

$db = DB::connection();
if ($db->getDriverName() !== 'mysql' || $db->getDatabaseName() !== 'repro_mysql_test' || $db->getConfig('username') !== 'repro_test') {
    throw new RuntimeException('The actual CRM worker connection differs from the isolated test database.');
}

$db->statement('SET SESSION innodb_lock_wait_timeout=12');
$reference = file_get_contents($directory.'/reference');
$await = static function (string $path, int $seconds): void {
    $deadline = microtime(true) + $seconds;
    while (! is_file($path) && microtime(true) < $deadline) {
        usleep(10_000);
    }
    if (! is_file($path)) {
        throw new RuntimeException('CRM replay synchronization timed out.');
    }
};
$selected = false;
$db->listen(static function (QueryExecuted $query) use ($await, $directory, $slot, $reference, &$selected): void {
    if ($selected || ! str_starts_with(strtolower($query->sql), 'select ')
        || ! str_contains($query->sql, '`crm_website_intake_receipts`') || ! in_array($reference, $query->bindings, true)) {
        return;
    }
    if ($query->connection->transactionLevel() !== 1 || ! str_contains(strtolower($query->sql), 'for update')) {
        throw new RuntimeException('The CRM receipt failed to acquire a transactional row lock.');
    }
    // Pause inside the normal application transaction after taking its lock.
    // The test controller releases A once it observes B issuing the same query.
    $selected = true;
    file_put_contents($directory.'/selected-'.$slot, '1');
    if ($slot === 'a') {
        $await($directory.'/release-a', 15);
    }
});
$connectionId = $db->selectOne('SELECT CONNECTION_ID() AS connection_id')->connection_id;
file_put_contents($directory.'/ready-'.$slot.'.tmp', (string) $connectionId);
rename($directory.'/ready-'.$slot.'.tmp', $directory.'/ready-'.$slot);
$await($directory.'/start-'.$slot, 25);

app(CrmWebsiteIntakeReceipt::class)->process('inquiry', strtoupper($reference));
if (! $selected || $db->transactionLevel() !== 0) {
    throw new RuntimeException('The CRM replay did not finish its normal receipt processing path.');
}
Http::assertNothingSent();
Mail::assertNothingSent();
Notification::assertNothingSent();
Queue::assertNothingPushed();
echo 'receipt-processed-without-provider-effects';
