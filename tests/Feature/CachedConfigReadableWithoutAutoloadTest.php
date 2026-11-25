<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The deploy preflight (scripts/deploy/deployment-storage.py, database_driver)
 * reads the engine from bootstrap/cache/config.php with a bare `php -r`, without
 * the Composer autoloader. A config value that var_export writes as an object or
 * enum would need autoloading there and stop every backend deploy at preflight.
 * This catches such a value in CI instead.
 */
class CachedConfigReadableWithoutAutoloadTest extends TestCase
{
    public function test_the_cached_config_can_be_read_without_the_autoloader(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'repro-config-cache-');
        try {
            // The same export config:cache writes.
            file_put_contents($path, '<?php return '.var_export(config()->all(), true).';'.PHP_EOL);
            $read = new Process([PHP_BINARY, '-d', 'display_errors=stderr', '-r',
                '$c = require $argv[1]; $n = $c["database"]["default"]; echo $c["database"]["connections"][$n]["driver"];',
                '--', $path]);
            $read->setTimeout(60);
            $read->run();

            $this->assertSame(0, $read->getExitCode(), $read->getErrorOutput());
            $this->assertSame(config('database.connections.'.config('database.default').'.driver'), $read->getOutput());
        } finally {
            @unlink($path);
        }
    }
}
