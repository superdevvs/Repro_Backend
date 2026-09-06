<?php

namespace Tests\Support;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Tests\TestCase;

/** Security regression tests bootstrap without reading a workstation environment file. */
abstract class IsolatedSecurityTestCase extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        // Test configuration comes from PHPUnit's process environment. Skip
        // dotenv completely: a nonexistent path still emits suppressed PHP
        // warnings, and a workstation .env must never enter these tests.
        $app->instance(LoadEnvironmentVariables::class, new class extends LoadEnvironmentVariables {
            public function bootstrap($app)
            {
            }
        });
        $this->traitsUsedByTest = array_flip(class_uses_recursive(static::class));
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
