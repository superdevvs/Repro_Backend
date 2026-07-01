<?php

namespace App\Console;

use App\Console\Commands\AuditTransactionalEmailReliability;
use App\Console\Commands\EmailOpsSummaryCommand;
use App\Console\Commands\GenerateInvoices;
use App\Console\Commands\ImportShootHistory;
use App\Console\Commands\PaymentRemindersSweep;
use App\Console\Commands\ProcessInvoiceReminders;use App\Console\Commands\ProcessPropertyContactReminders;
use App\Console\Commands\ProcessShootReminders;
use App\Console\Commands\PurgeDeletedAccounts;
use App\Console\Commands\RetryStuckQueuedMessages;
use App\Console\Commands\RunSystemAutomations;
use App\Console\Commands\SetupBrightMlsTest;
use App\Console\Commands\SeedPhotographerAvailability;
use App\Console\Commands\SendPayoutReports;
use App\Console\Commands\SendWeeklyInvoiceSummaries;
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
        AuditTransactionalEmailReliability::class,
        EmailOpsSummaryCommand::class,
        GenerateInvoices::class,
        ImportShootHistory::class,
        RunSystemAutomations::class,
        ProcessShootReminders::class,
        ProcessPropertyContactReminders::class,
        ProcessInvoiceReminders::class,
        PaymentRemindersSweep::class,
        PurgeDeletedAccounts::class,
        RetryStuckQueuedMessages::class,
        SendWeeklyInvoiceSummaries::class,
        SetupBrightMlsTest::class,
        SendPayoutReports::class,
        SeedPhotographerAvailability::class,
        SendWeeklySalesReports::class,
    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Database-backed weekly system automations run through a tracked executor.
        $schedule->command('automations:run-system')->everyFifteenMinutes();
        
        
        $schedule->job(new DispatchScheduledMessages())->everyMinute();
        $schedule->command('messages:retry-stuck')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('messaging:shoot-reminders')->everyFiveMinutes();
        $schedule->command('messaging:property-contact-reminders')->dailyAt('09:00');
        $schedule->command('messaging:invoice-reminders')->dailyAt('09:30');
        // Continuous payment-reminder cadence (Req 4.6): roll the rolling-horizon window forward.
        // Run weekly — shorter than the look-ahead window in AutomationService — so the next
        // last-Sunday reminder is always materialized before it is due.
        $schedule->command('messaging:payment-reminders-sweep')->weeklyOn(1, '04:30');
        // Generate the last completed week's photographer + sales-rep invoice rows FIRST,
        // so the summary/payout/sales-report jobs below have invoices to work with. Runs with
        // --no-email (those downstream jobs own the notifications); idempotent (whereDate guard)
        // so it is safe alongside any manual run.
        $schedule->command('invoices:generate --weekly --no-email')->weeklyOn(1, '02:00')->withoutOverlapping();
        $schedule->command('messaging:invoice-summaries')->weeklyOn(1, '03:00');
        $schedule->command('payouts:send')->weeklyOn(1, '05:00');
        $schedule->command('reports:sales:weekly')->weeklyOn(1, '05:30');

        // Account lifecycle stage 3: purge/anonymize soft-deleted accounts whose 14-day
        // restore window has elapsed. NOT scheduled yet — purge/anonymization is irreversible,
        // so it is run manually (php artisan users:purge-deleted [--dry-run]) and will only be
        // enabled here after the first production dry-run is reviewed and approved.
        // $schedule->command('users:purge-deleted')->dailyAt('02:00')->withoutOverlapping();

        // Re-attempt iGUIDE sync for shoots whose iguide may have been
        // created on youriguide.com after raw upload (no webhook reached us).
        $schedule->command('iguide:resync-pending')->everyThirtyMinutes()->withoutOverlapping();

        // Re-fetch CubiCasa orders still in non-Ready states in case a webhook
        // delivery was missed.
        $schedule->command('cubicasa:resync-pending')->everyThirtyMinutes()->withoutOverlapping();
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
