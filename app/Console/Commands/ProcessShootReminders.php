<?php

namespace App\Console\Commands;

use App\Services\Messaging\AutomationService;
use Illuminate\Console\Command;

class ProcessShootReminders extends Command
{
    protected $signature = 'messaging:shoot-reminders';
    protected $description = 'Process shoot reminder automations';

    public function handle(AutomationService $automationService): int
    {
        $this->info('Processing shoot reminders...');

        try {
            $automationService->triggerShootReminders();
            $this->info('Shoot reminders processed successfully.');
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to process shoot reminders: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}

