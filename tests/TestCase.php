<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
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

        $this->traitsUsedByTest = array_flip(class_uses_recursive(static::class));

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
