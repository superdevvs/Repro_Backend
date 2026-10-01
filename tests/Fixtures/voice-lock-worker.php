<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';

// A separate PHP process with its own connection/cache manager. No app boot,
// credentials or network calls; only the test's temporary SQLite database.
$app = new Illuminate\Container\Container;
Illuminate\Container\Container::setInstance($app);
$app->instance('config', new Illuminate\Config\Repository([
    'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => [
        'driver' => 'sqlite', 'database' => $argv[1], 'prefix' => '', 'busy_timeout' => 5000,
    ]]],
    'cache' => ['default' => 'array', 'prefix' => 'voice-lock-test:', 'stores' => [
        'voice_test' => ['driver' => 'database', 'connection' => 'sqlite', 'table' => 'cache', 'lock_table' => 'cache_locks', 'lock_lottery' => [0, 100]],
    ]],
    'voice_calls' => ['lock_store' => 'voice_test', 'cache_store' => 'voice_test'],
]));
$app->instance('db', new Illuminate\Database\DatabaseManager($app, new Illuminate\Database\Connectors\ConnectionFactory($app)));
$app->instance('cache', new Illuminate\Cache\CacheManager($app));
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
$locks = [];
echo "ready\n";
fflush(STDOUT);
while (($line = fgets(STDIN)) !== false) {
    $command = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    if ($command['action'] === 'exit') {
        break;
    }
    $key = $command['key'];
    if ($command['action'] === 'cache_get') {
        $result = App\Support\VoiceCache::store()->get($key);
    } elseif ($command['action'] === 'cache_put') {
        $result = App\Support\VoiceCache::store()->put($key, $command['value'], 30);
    } elseif ($command['action'] === 'cache_forget') {
        $result = App\Support\VoiceCache::store()->forget($key);
    } elseif ($command['action'] === 'acquire') {
        $lock = App\Support\VoiceLocks::lock($key, 30);
        $result = $lock->get();
        if ($result) {
            $locks[$key] = $lock;
        }
    } else {
        $result = isset($locks[$key]) && $locks[$key]->release();
        unset($locks[$key]);
    }
    echo json_encode(['result' => $result])."\n";
    fflush(STDOUT);
}
