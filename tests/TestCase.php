<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (filter_var(getenv('MYSQL_TEST_FACTORY_DEFAULTS'), FILTER_VALIDATE_BOOLEAN)) {
            \Tests\Support\MySqlFactoryDefaults::seed();
        }
    }

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

        if (filter_var(getenv('MYSQL_TEST_FACTORY_DEFAULTS'), FILTER_VALIDATE_BOOLEAN)) {
            $connection = $app['db']->connection();
            if ($connection->getDriverName() !== 'mysql' || $connection->getDatabaseName() !== 'repro_mysql_test'
                || $connection->getConfig('username') !== 'repro_test') {
                throw new \RuntimeException('MySQL regression tests require the actual application connection to use the isolated test database and account.');
            }
        }

        return $app;
    }
}
