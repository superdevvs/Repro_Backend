<?php

namespace Tests\Feature;

use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/** The deploy check passes on both engines when the configuration is right. */
class WriterPolicyCheckCommandTest extends TestCase
{
    public function test_deploy_check_reports_the_engine_and_passes(): void
    {
        $code = Artisan::call('db:writer-policy-check');
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $code, json_encode($report));
        $this->assertSame(DB::getDriverName(), $report['engine']);
        $this->assertSame([], $report['problems']);
        if (DB::getDriverName() === 'sqlite') {
            $this->assertSame('not applicable', $report['writer_policy']);
        } else {
            $this->assertTrue($report['writer_policy']);
            $this->assertTrue($report['token']['acquired']);
        }
    }

    public function test_a_second_mysql_connection_without_a_table_limit_fails_the_check_on_mysql(): void
    {
        config(['database.connections.mysql_extra' => ['driver' => 'mysql', 'repro_writer_policy' => false]]);
        $code = Artisan::call('db:writer-policy-check');
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        if (DB::getDriverName() === 'sqlite') {
            $this->assertSame(0, $code);
        } else {
            $this->assertSame(1, $code);
            $this->assertStringContainsString('mysql_extra', implode(' ', $report['problems']));
        }
    }

    public function test_the_check_connects_to_the_database_url_target_as_the_application_does(): void
    {
        config([
            'database.default' => 'mysql_url_probe',
            'database.connections.mysql_url_probe' => [
                'driver' => 'mysql', 'url' => 'mysql://url-user@url-host:3307/url_database',
                'host' => 'fallback-host', 'port' => '3306', 'database' => 'fallback_database',
                'username' => 'fallback-user', 'repro_writer_policy' => true,
            ],
        ]);
        // Record the settings the check would connect with; never connect anywhere.
        $factory = new class(app()) extends ConnectionFactory
        {
            public ?array $config = null;

            public function make(array $config, $name = null)
            {
                $this->config = $config;

                throw new RuntimeException('No connection in this test.');
            }
        };
        $this->app->instance(ConnectionFactory::class, $factory);

        $this->assertSame(1, Artisan::call('db:writer-policy-check'));
        $this->assertSame(['url-host', 3307, 'url_database', 'url-user'], [
            $factory->config['host'] ?? null, $factory->config['port'] ?? null,
            $factory->config['database'] ?? null, $factory->config['username'] ?? null,
        ]);
        $this->assertArrayNotHasKey('url', $factory->config);
    }
}
