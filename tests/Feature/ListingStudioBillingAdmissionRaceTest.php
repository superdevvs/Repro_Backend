<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ListingStudioBillingAdmissionRaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_independent_writers_preserve_request_cap_exact_replay_and_email_cooldown(): void
    {
        $client = User::factory()->create(['role' => 'client', 'account_status' => 'active']);
        $actor = User::factory()->create(['role' => 'salesRep', 'account_status' => 'active']);
        $root = sys_get_temp_dir().'/studio-billing-race-'.bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $database = $root.'/database.sqlite';
        $pdo = new \PDO('sqlite:'.$database);
        $pdo->exec('PRAGMA journal_mode=WAL');
        // Use the real migrated schema, including all identity and unique-key constraints.
        foreach (DB::select("SELECT sql FROM sqlite_master WHERE type='table' AND sql IS NOT NULL AND name NOT LIKE 'sqlite_%'") as $row) {
            $pdo->exec($row->sql);
        }
        foreach (DB::select("SELECT sql FROM sqlite_master WHERE type='index' AND sql IS NOT NULL") as $row) {
            $pdo->exec($row->sql);
        }
        foreach (DB::table('users')->whereIn('id', [$client->id, $actor->id])->get() as $row) {
            $data = (array) $row;
            $columns = implode(',', array_map(fn ($key) => '"'.$key.'"', array_keys($data)));
            $pdo->prepare('INSERT INTO users ('.$columns.') VALUES ('.implode(',', array_fill(0, count($data), '?')).')')->execute(array_values($data));
        }
        $script = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default'=>'sqlite','database.connections.sqlite.database'=>$argv[2],
'database.connections.sqlite.busy_timeout'=>1000,'listing-studio.delegated_enabled'=>true,
'listing-studio.paid_enabled'=>true,'listing-studio.paid_checkout_enabled'=>true,'listing-studio.stripe_enabled'=>true,
'listing-studio.billing_request_limit'=>1,'listing-studio.billing_email_daily_limit'=>1,
'cache.default'=>'array','queue.default'=>'sync']);
Illuminate\Support\Facades\DB::purge('sqlite');
Illuminate\Support\Facades\Queue::fake();
Illuminate\Support\Facades\Http::preventStrayRequests();
$client=App\Models\User::findOrFail($argv[3]);
$actor=App\Models\User::findOrFail($argv[4]);
App\Models\ListingStudioBillingRequest::query()->whereRaw('0=1')->first();
App\Models\ListingStudioBillingEmail::query()->whereRaw('0=1')->first();
App\Models\ListingStudioBillingRequest::created(fn()=>usleep(300000));
App\Models\ListingStudioBillingEmail::created(fn()=>usleep(300000));
touch($argv[7]);
$deadline=microtime(true)+30;
while(!is_file($argv[8])) { if(microtime(true)>$deadline) { throw new RuntimeException('Barrier timed out.'); } usleep(1000);clearstatcache(); }
try {
    if($argv[5]==='email') {
        $result=app(App\Services\ListingStudio\ListingStudioBillingEmailService::class)->request($actor,$client,App\Models\ListingStudioBillingRequest::firstOrFail(),$argv[6]);
    } else {
        $result=app(App\Services\ListingStudio\ListingStudioBillingRequestService::class)->create($actor,$client,['kind'=>'subscription_purchase','planCode'=>'starter'],$argv[6]);
    }
    echo $result['created']?'created':'replayed';
} catch(App\Exceptions\PublicApiException $error) { echo $error->errorCode; }
PHP;
        try {
            $run = function (string $mode, array $keys) use ($root, $database, $pdo, $script, $client, $actor): array {
                $nonce = bin2hex(random_bytes(4));
                $release = $root.'/'.$nonce.'-release';
                $ready = [$root.'/'.$nonce.'-a', $root.'/'.$nonce.'-b'];
                $children = [];
                try {
                    foreach ($keys as $index => $key) {
                        $children[] = $child = new Process([PHP_BINARY, '-r', $script, base_path(), $database,
                            (string) $client->id, (string) $actor->id, $mode, $key, $ready[$index], $release]);
                        $child->setTimeout(90);
                        $child->start();
                    }
                    $deadline = microtime(true) + 45;
                    while (! is_file($ready[0]) || ! is_file($ready[1])) {
                        if (microtime(true) > $deadline || ! $children[0]->isRunning() || ! $children[1]->isRunning()) {
                            $this->fail('Billing children did not reach barrier: '.$children[0]->getErrorOutput().$children[1]->getErrorOutput());
                        }
                        usleep(1000);
                        clearstatcache();
                    }
                    $pdo->beginTransaction();
                    $pdo->exec('UPDATE users SET updated_at=updated_at');
                    touch($release);
                    // Real contention exceeds LockedWrite's combined sleep budget; write-first waits safely.
                    usleep(750000);
                    $pdo->commit();
                    $outcomes = [];
                    foreach ($children as $child) {
                        $child->wait();
                        $this->assertSame(0, $child->getExitCode(), $child->getErrorOutput());
                        $outcomes[] = $child->getOutput();
                    }
                    sort($outcomes);

                    return $outcomes;
                } finally {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    foreach ($children as $child) {
                        if ($child->isRunning()) {
                            $child->stop(1);
                        }
                    }
                }
            };
            $keys = ['aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa', 'bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb'];
            $this->assertSame(['billing_request_limit', 'created'], $run('request', $keys));
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM listing_studio_billing_requests')->fetchColumn());
            $key = $pdo->query('SELECT request_key FROM listing_studio_billing_requests')->fetchColumn();
            $this->assertSame(['replayed', 'replayed'], $run('request', [$key, $key]));
            $this->assertSame(['created', 'email_rate_limited'], $run('email', $keys));
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM listing_studio_billing_emails')->fetchColumn());
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM listing_studio_checkout_attempts')->fetchColumn());
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM listing_studio_orders')->fetchColumn());
        } finally {
            $pdo = null;
            Storage::build(['driver' => 'local', 'root' => $root])->deleteDirectory('');
            @rmdir($root);
        }
    }
}
