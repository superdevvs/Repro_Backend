<?php

use App\Services\Recruiting\RecruitApplicationIntake;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'mysql'
    || getenv('DB_DATABASE') !== 'repro_mysql_test' || getenv('DB_USERNAME') !== 'repro_test') {
    throw new RuntimeException('The recruiting worker requires the isolated MySQL test database.');
}

[$directory, $slot] = array_slice($argv, 1);
if (! in_array($slot, ['a', 'b'], true)) {
    throw new RuntimeException('Unknown recruiting replay worker.');
}

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config([
    'crm.enabled' => true, 'cache.default' => 'array', 'mail.default' => 'array', 'queue.default' => 'sync',
    'logging.default' => 'null', 'filesystems.disks.local.root' => $directory.'/disk',
]);
Http::preventStrayRequests();
Mail::fake();
Notification::fake();
Queue::fake();

$db = DB::connection();
if ($db->getDriverName() !== 'mysql' || $db->getDatabaseName() !== 'repro_mysql_test' || $db->getConfig('username') !== 'repro_test') {
    throw new RuntimeException('The actual recruiting worker connection differs from the isolated test database.');
}

if ($db->getConfig('repro_writer_policy') !== true) {
    // Without the writer token B waits on the receipt row itself, for longer.
    $db->statement('SET SESSION innodb_lock_wait_timeout=12');
}
$fields = json_decode(file_get_contents($directory.'/fields.json'), true, flags: JSON_THROW_ON_ERROR);
$reference = $fields['external_reference'];
$await = static function (string $path, int $seconds): void {
    $deadline = microtime(true) + $seconds;
    while (! is_file($path) && microtime(true) < $deadline) {
        usleep(10_000);
    }
    if (! is_file($path)) {
        throw new RuntimeException('Recruiting replay synchronization timed out.');
    }
};
$selected = false;
$db->listen(static function (QueryExecuted $query) use ($await, $directory, $slot, $reference, &$selected): void {
    if ($selected || ! str_starts_with(strtolower($query->sql), 'select ')
        || ! str_contains($query->sql, '`recruit_application_receipts`') || ! in_array($reference, $query->bindings, true)) {
        return;
    }
    // LockedWrite takes the writer token in its own transaction first, so the
    // receipt's DB::transaction may be one level deeper with the policy on.
    if ($query->connection->transactionLevel() < 1 || ! str_contains(strtolower($query->sql), 'for update')) {
        throw new RuntimeException('The recruiting receipt failed to take a transactional row lock.');
    }
    // A pauses inside the application transaction holding the receipt lock;
    // the test releases it once it sees B issue the same locking query.
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

$resume = new UploadedFile($directory.'/resume.pdf', 'resume.pdf', 'application/pdf', null, true);
$applicant = app(RecruitApplicationIntake::class)->intake($fields + ['resume' => $resume], null);
if (! $selected || $db->transactionLevel() !== 0) {
    throw new RuntimeException('The recruiting replay did not take its normal receipt path.');
}
Http::assertNothingSent();
Mail::assertNothingSent();
Queue::assertNothingPushed();
echo 'applicant:'.$applicant->id;
