<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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
}
