<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ListingStudioPaidAdmissionRaceTest extends TestCase
{
    public function test_two_independent_sqlite_upload_requests_cannot_both_take_the_last_source_slot(): void
    {
        $root = sys_get_temp_dir().'/studio-upload-race-'.bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $mediaRoot = $root.'/media';
        mkdir($mediaRoot, 0700);
        chmod($mediaRoot, 02770);
        $database = $root.'/database.sqlite';
        $databaseHandle = new \PDO('sqlite:'.$database);
        $databaseHandle->exec('PRAGMA journal_mode=WAL');
        $databaseHandle->exec('CREATE TABLE cache_locks (key VARCHAR(255) PRIMARY KEY, owner VARCHAR(255), expiration INTEGER)');
        $databaseHandle->exec('CREATE TABLE listing_studio_uploads (id VARCHAR(36) PRIMARY KEY, client_id INTEGER, status VARCHAR(24), request_key VARCHAR(64), request_hash VARCHAR(64), path TEXT, preview_path TEXT NULL, sha256 VARCHAR(64), mime VARCHAR(32), width INTEGER, height INTEGER, byte_size INTEGER, expires_at DATETIME, pinned_at DATETIME NULL, media_purged_at DATETIME NULL, created_at DATETIME, updated_at DATETIME, UNIQUE(client_id,request_key))');
        $databaseHandle->exec('CREATE TABLE listing_studio_order_items (id VARCHAR(36) PRIMARY KEY, order_id VARCHAR(36), client_id INTEGER, source_kind VARCHAR(32), source_id VARCHAR(36), status VARCHAR(32))');
        $databaseHandle->exec('CREATE TABLE listing_studio_orders (id VARCHAR(36) PRIMARY KEY, funding_method VARCHAR(16), funding_status VARCHAR(32), paid_amount_cents INTEGER DEFAULT 0)');
        $photoPath = $root.'/photo.jpg';
        $image = imagecreatetruecolor(32, 24);
        imagejpeg($image, $photoPath);
        imagedestroy($image);
        $script = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default'=>'sqlite','database.connections.sqlite.database'=>$argv[2],
    'database.connections.sqlite.busy_timeout'=>1000, 'listing-studio.paid_enabled'=>true,
    'listing-studio.max_active_sources_per_client'=>1,
    'cache.stores.database.lock_connection'=>'sqlite', 'cache.stores.database.connection'=>'sqlite',
    'cache.stores.database.lock_lottery'=>[0,100],
    'filesystems.disks.listing_studio_orders.root'=>$argv[3]]);
Illuminate\Support\Facades\DB::purge('sqlite');
Illuminate\Support\Facades\Storage::forgetDisk('listing_studio_orders');
// Warm model/query autoloading before the barrier so slow filesystem mounts do
// not consume the writer-hold window before the admission transaction begins.
App\Models\ListingStudioUpload::query()->whereRaw('0 = 1')->first();
// Both requests acquire their real, different idempotency locks before a third
// connection holds the writer. This forces admission to encounter contention.
$waiting = false;
Illuminate\Support\Facades\DB::listen(function ($query) use (&$waiting, $argv) {
    if ($waiting || !str_starts_with($query->sql, 'insert into "cache_locks"')) { return; }
    $waiting = true;
    touch($argv[6]);
    $deadline = microtime(true) + 30;
    while (!file_exists($argv[7])) {
        if (microtime(true) >= $deadline) { throw new RuntimeException('Writer barrier timed out.'); }
        usleep(1000);
        clearstatcache(true, $argv[7]);
    }
});
// Keep the first actual insert transaction open long enough for its independent competitor.
App\Models\ListingStudioUpload::created(function () { usleep(300000); });
$client = (new App\Models\User)->forceFill(['id'=>1,'role'=>'client','account_status'=>'active']);
$photo = new Illuminate\Http\UploadedFile($argv[4], 'property.jpg', 'image/jpeg', null, true);
try {
    app(App\Services\ListingStudio\ListingStudioPaidMedia::class)->upload($client,$photo,$argv[5]);
    echo 'created';
} catch (App\Exceptions\PublicApiException $error) {
    echo $error->errorCode;
}
PHP;
        try {
            $release = $root.'/release';
            $firstReady = $root.'/first-ready';
            $secondReady = $root.'/second-ready';
            $first = new Process([PHP_BINARY, '-r', $script, base_path(), $database, $mediaRoot, $photoPath, 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa', $firstReady, $release]);
            $second = new Process([PHP_BINARY, '-r', $script, base_path(), $database, $mediaRoot, $photoPath, 'bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb', $secondReady, $release]);
            $first->start();
            $second->start();
            $deadline = microtime(true) + 30;
            while (! is_file($firstReady) || ! is_file($secondReady)) {
                if (microtime(true) >= $deadline || ! $first->isRunning() || ! $second->isRunning()) {
                    $this->fail('Upload processes did not reach the admission barrier: '.$first->getErrorOutput().$second->getErrorOutput());
                }
                usleep(1000);
                clearstatcache();
            }
            $databaseHandle->beginTransaction();
            $databaseHandle->exec('UPDATE cache_locks SET expiration = expiration');
            touch($release);
            // Longer than LockedWrite's maximum combined retry sleeps (280ms),
            // shorter than busy_timeout. A read-first transaction fails here;
            // a write-first transaction waits before it establishes a snapshot.
            usleep(750000);
            $databaseHandle->commit();
            $first->wait();
            $second->wait();
            $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput());
            $this->assertSame(0, $second->getExitCode(), $second->getErrorOutput());
            $outcomes = [$first->getOutput(), $second->getOutput()];
            sort($outcomes);
            $this->assertSame(['created', 'upload_limit'], $outcomes);
            $this->assertSame(1, (int) $databaseHandle->query('SELECT COUNT(*) FROM listing_studio_uploads')->fetchColumn());
            $this->assertSame('ready', $databaseHandle->query('SELECT status FROM listing_studio_uploads')->fetchColumn());
        } finally {
            if ($databaseHandle?->inTransaction()) {
                $databaseHandle->rollBack();
            }
            foreach ([$first ?? null, $second ?? null] as $process) {
                if ($process?->isRunning()) {
                    $process->stop(1);
                }
            }
            $databaseHandle = null;
            Storage::build(['driver' => 'local', 'root' => $root])->deleteDirectory('');
            @rmdir($root);
        }
    }
}
