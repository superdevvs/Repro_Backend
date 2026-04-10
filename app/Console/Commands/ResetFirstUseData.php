<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ResetFirstUseData extends Command
{
    protected $signature = 'app:reset-first-use-data
                            {--force : Run without confirmation}
                            {--keep-messaging : Preserve contacts, messaging, AI chat history, and contact submissions}
                            {--no-cache-clear : Skip clearing application cache after reset}';

    protected $description = 'Delete transactional shoot and invoice data while preserving accounts, scheduling, and system configuration';

    public function handle(): int
    {
        $coreTables = [
            'editor_payouts',
            'google_calendar_event_mappings',
            'shoot_ghost_users',
            'shoot_share_links',
            'shoot_messages',
            'shoot_reschedule_requests',
            'shoot_activity_logs',
            'shoot_media_albums',
            'shoot_notes',
            'shoot_slideshows',
            'dropbox_folders',
            'workflow_logs',
            'ai_video_generation_jobs',
            'ai_editing_jobs',
            'shoot_files',
            'invoice_items',
            'invoice_shoot',
            'payments',
            'editing_requests',
            'mmm_punchout_sessions',
            'shoot_service',
            'shoots',
            'invoices',
            'automation_run_steps',
            'automation_runs',
            'automation_dispatches',
            'system_overview_error_events',
            'system_overview_request_traces',
            'system_overview_route_events',
            'system_overview_sessions',
        ];

        $messagingTables = [
            'contact_submissions',
            'messages',
            'message_threads',
            'contacts',
            'ai_messages',
            'ai_chat_sessions',
        ];

        $preservedTables = [
            'users',
            'user_branding',
            'account_links',
            'oauth_tokens',
            'google_calendar_connections',
            'photographer_availabilities',
            'services',
            'service_groups',
            'service_sqft_ranges',
            'categories',
            'settings',
            'automation_rules',
            'message_templates',
            'ai_video_presets',
            'ai_video_vertical_variants',
            'coupons',
            'sms_numbers',
        ];

        $tablesToWipe = $this->option('keep-messaging')
            ? $coreTables
            : array_merge($coreTables, $messagingTables);

        $existingTablesToWipe = array_values(array_filter($tablesToWipe, fn (string $table) => Schema::hasTable($table)));
        $existingPreservedTables = array_values(array_filter($preservedTables, fn (string $table) => Schema::hasTable($table)));

        if (empty($existingTablesToWipe)) {
            $this->warn('No matching transactional tables were found to reset.');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn('This will permanently delete transactional business data.');
        $this->line('Tables to wipe: ' . implode(', ', $existingTablesToWipe));
        $this->line('Tables to preserve: ' . implode(', ', $existingPreservedTables));
        $this->newLine();

        $rowSummary = [];
        foreach ($existingTablesToWipe as $table) {
            $rowSummary[$table] = DB::table($table)->count();
        }

        foreach ($rowSummary as $table => $count) {
            $this->line(sprintf('%s: %d row(s)', $table, $count));
        }

        if (!$this->option('force') && !$this->confirm('Continue with first-use data reset?', false)) {
            $this->warn('Reset cancelled.');
            return self::INVALID;
        }

        try {
            Schema::disableForeignKeyConstraints();

            foreach ($existingTablesToWipe as $table) {
                DB::table($table)->truncate();
            }
        } catch (\Throwable $e) {
            Schema::enableForeignKeyConstraints();
            $this->error('Reset failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        Schema::enableForeignKeyConstraints();

        if (!$this->option('no-cache-clear')) {
            Artisan::call('cache:clear');
            $this->line(trim(Artisan::output()));
        }

        $this->info('First-use transactional data reset completed successfully.');
        $this->line('Preserved accounts, scheduling, and system configuration tables remain intact.');

        return self::SUCCESS;
    }
}
