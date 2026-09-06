<?php

namespace Tests\Support;

use Illuminate\Contracts\Console\Kernel;
use Tests\TestCase;

/** Security regression tests bootstrap without reading a workstation environment file. */
abstract class IsolatedSecurityTestCase extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->useEnvironmentPath(__DIR__.'/fixtures/no-environment-file');
        $this->traitsUsedByTest = array_flip(class_uses_recursive(static::class));
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
