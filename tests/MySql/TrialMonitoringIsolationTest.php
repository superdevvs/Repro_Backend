<?php

namespace Tests\MySql;

use App\Services\ListingStudio\ListingStudioTrialMonitoring;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\FreshDatabaseOutsideTransaction;
use Tests\TestCase;

final class TrialMonitoringIsolationTest extends TestCase
{
    use FreshDatabaseOutsideTransaction;

    public function test_rejection_counter_survives_business_rollback_without_changing_its_session(): void
    {
        $primary = DB::connection();
        $timeout = (int) $primary->scalar('SELECT @@SESSION.innodb_lock_wait_timeout');
        $primary->beginTransaction();
        try {
            app(ListingStudioTrialMonitoring::class)->count('trial_daily_limit', 'grass');
        } finally {
            $primary->rollBack();
        }

        $this->assertDatabaseHas('listing_studio_trial_counters', ['event' => 'trial_daily_limit', 'service' => 'grass', 'count' => 1]);
        $this->assertSame($timeout, (int) $primary->scalar('SELECT @@SESSION.innodb_lock_wait_timeout'));
        $this->assertSame(0, $primary->transactionLevel());
    }

    public function test_contended_telemetry_returns_with_a_generic_log_and_preserves_the_business_connection(): void
    {
        $monitor = app(ListingStudioTrialMonitoring::class);
        $monitor->count('trial_ip_limit', 'grass');
        $primary = DB::connection();
        $timeout = (int) $primary->scalar('SELECT @@SESSION.innodb_lock_wait_timeout');
        Log::spy();
        $primary->beginTransaction();
        try {
            $primary->table('listing_studio_trial_counters')->where('event', 'trial_ip_limit')->lockForUpdate()->first();
            $started = microtime(true);
            $monitor->count('trial_ip_limit', 'grass');
            $elapsed = microtime(true) - $started;
            $this->assertSame($timeout, (int) $primary->scalar('SELECT @@SESSION.innodb_lock_wait_timeout'));
        } finally {
            $primary->rollBack();
        }

        $this->assertLessThan(4, $elapsed, 'Best-effort telemetry must not inherit the business connection lock timeout.');
        $this->assertDatabaseHas('listing_studio_trial_counters', ['event' => 'trial_ip_limit', 'service' => 'grass', 'count' => 1]);
        Log::shouldHaveReceived('error')->once()->with('Listing Studio operational telemetry could not be stored.');
        $this->assertSame(0, $primary->transactionLevel());
    }
}
