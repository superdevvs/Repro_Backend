<?php

namespace Tests\MySqlWriters;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\MySqlWriters\Concerns\WriterFixture;
use Tests\TestCase;

/** The deploy check: the token connection, its session settings and the token row. */
final class WriterPolicyCheckTest extends TestCase
{
    use WriterFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWriterFixture();
    }

    protected function tearDown(): void
    {
        $this->tearDownWriterFixture();
        parent::tearDown();
    }

    private function check(): array
    {
        $code = Artisan::call('db:writer-policy-check');

        return [$code, json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)];
    }

    public function test_passes_on_a_correctly_provisioned_server(): void
    {
        [$code, $report] = $this->check();

        $this->assertSame(0, $code, json_encode($report));
        $this->assertSame('mysql', $report['engine']);
        $this->assertTrue($report['writer_policy']);
        $this->assertSame(['READ-COMMITTED', 5, 5], [$report['session']['isolation'], $report['session']['row_lock_wait'], $report['session']['metadata_lock_wait']]);
        $this->assertTrue($report['token']['acquired']);
        $this->assertSame([], $report['problems']);
        $this->assertFalse($this->tokenIsHeld());
    }

    public function test_fails_when_the_token_row_is_missing(): void
    {
        $this->admin->exec('DELETE FROM repro_mysql_control.writer_token');
        [$code, $report] = $this->check();

        $this->assertSame(1, $code);
        $this->assertFalse($report['token']['acquired']);
        $this->assertStringContainsString('row is missing', implode(' ', $report['problems']));
    }

    public function test_fails_when_the_policy_is_switched_off(): void
    {
        config(['database.connections.mysql.repro_writer_policy' => false]);
        DB::purge('mysql');
        [$code, $report] = $this->check();

        $this->assertSame(1, $code);
        $this->assertFalse($report['writer_policy']);
    }

    public function test_fails_when_another_mysql_connection_carries_the_token_or_writes_freely(): void
    {
        config(['database.connections.mysql_extra' => config('database.connections.mysql')]);
        [$code, $report] = $this->check();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('mysql_extra', implode(' ', $report['problems']));
    }

    public function test_checks_the_database_url_target_as_the_application_does(): void
    {
        // DB_URL wins over the separate settings in Laravel; the check must use the same target.
        config(['database.connections.mysql.url' => 'mysql://localhost/repro_mysql_test',
            'database.connections.mysql.database' => 'repro_not_the_target']);
        DB::purge('mysql');
        [$code, $report] = $this->check();

        $this->assertSame(0, $code, json_encode($report));
        $this->assertTrue($report['token']['acquired']);
        $this->assertSame([], $report['problems']);
    }
}
