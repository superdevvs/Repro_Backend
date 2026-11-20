<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Parallel workers whose private storage directory was reset in this process. */
    private static array $preparedParallelStorage = [];

    /**
     * Pin the application base path to this checkout.
     *
     * vendor/ is often symlinked to /var/www/backend/vendor on Maverick. Laravel's
     * default base-path inference walks up from vendor and would otherwise load the
     * live app (and its cached config / production sqlite) during PHPUnit.
     */
    public function createApplication()
    {
        $app = require dirname(__DIR__).'/bootstrap/app.php';

        if ($storage = self::parallelStoragePath()) {
            // Must precede bootstrap: config (view.compiled, logging, disks)
            // resolves storage_path() while it loads.
            $app->useStoragePath($storage);
        }

        $this->traitsUsedByTest = array_flip(class_uses_recursive(static::class));

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    /**
     * scripts/ci/run_phpunit_shard.py runs several PHPUnit processes at once and
     * gives each a TEST_TOKEN. Each process then gets its own storage/ tree, so
     * one worker's files never appear in, or vanish from, another's tests.
     * Serial runs (no TEST_TOKEN) keep the normal storage/ directory.
     */
    private static function parallelStoragePath(): ?string
    {
        $token = $_SERVER['TEST_TOKEN'] ?? getenv('TEST_TOKEN');
        if (! is_string($token) || ! preg_match('/\A[0-9]+\z/', $token)) {
            return null;
        }

        $path = dirname(__DIR__).'/storage/framework/testing/parallel/worker-'.$token;
        if (! isset(self::$preparedParallelStorage[$token])) {
            $files = new Filesystem;
            $files->deleteDirectory($path);
            // logs/scheduling is provisioned on servers; SchedulingDiagnostics
            // cannot create it itself here (it needs the www-data group).
            foreach (['app/public', 'app/private', 'framework/cache/data', 'framework/sessions', 'framework/testing', 'framework/views', 'logs/scheduling'] as $directory) {
                $files->ensureDirectoryExists($path.'/'.$directory);
            }
            self::$preparedParallelStorage[$token] = true;
        }

        return $path;
    }
}
