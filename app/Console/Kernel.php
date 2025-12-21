<?php

namespace App\Console;

use App\Console\Commands\GenerateInvoices;
use App\Console\Commands\ImportShootHistory;
use App\Console\Commands\ProcessShootReminders;
use App\Console\Commands\ProcessPropertyContactReminders;
use App\Console\Commands\SendPayoutReports;
use App\Console\Commands\SeedPhotographerAvailability;
use App\Console\Commands\SendWeeklySalesReports;
use App\Jobs\DispatchScheduledMessages;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array<int, class-string>
     */
    protected $commands = [
        GenerateInvoices::class,
        ImportShootHistory::class,
        ProcessShootReminders::class,
        ProcessPropertyContactReminders::class,
        SendPayoutReports::class,
        SeedPhotographerAvailability::class,
        SendWeeklySalesReports::class,
    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Weekly automated invoicing - Monday at 1:00 AM
        $schedule->command('invoices:generate --weekly')->weeklyOn(1, '01:00');
        
        // Weekly sales reports - Monday at 2:00 AM (after invoices are generated)
        $schedule->command('reports:sales:weekly')->weeklyOn(1, '02:00');
        
        $schedule->job(new DispatchScheduledMessages())->everyMinute();
        $schedule->command('messaging:shoot-reminders')->everyFiveMinutes();
        $schedule->command('messaging:property-contact-reminders')->dailyAt('09:00');
        $schedule->command('payouts:send')->weeklyOn(0, '05:00');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
