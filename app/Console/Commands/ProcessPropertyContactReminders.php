<?php

namespace App\Console\Commands;

use App\Models\Shoot;
use App\Services\Messaging\AutomationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ProcessPropertyContactReminders extends Command
{
    protected $signature = 'messaging:property-contact-reminders';
    protected $description = 'Send reminders for missing property contact/lockbox details';

    public function handle(AutomationService $automationService): int
    {
        $this->info('Processing property contact reminders...');

        try {
            // Check for shoots 2 days before, 1 day before, and on shoot day
            $reminderDays = [2, 1, 0];
            
            foreach ($reminderDays as $daysBefore) {
                $targetDate = Carbon::today()->addDays($daysBefore);
                
                $shoots = Shoot::whereDate('scheduled_date', $targetDate)
                    ->whereNotNull('scheduled_date')
                    ->whereIn('workflow_status', [
                        Shoot::WORKFLOW_BOOKED,
                        Shoot::WORKFLOW_RAW_UPLOAD_PENDING,
                    ])
                    ->with(['client'])
                    ->get();

                foreach ($shoots as $shoot) {
                    // Check if property contact details are missing
                    if ($this->isPropertyContactMissing($shoot)) {
                        $context = $automationService->buildShootContext($shoot);
                        $context['shoot_datetime'] = $shoot->scheduled_date;
                        $context['days_before'] = $daysBefore;
                        $context['reminder_type'] = $daysBefore === 0 ? 'shoot_day' : ($daysBefore === 1 ? 'one_day_before' : 'two_days_before');
                        
                        $this->info("Triggering reminder for shoot #{$shoot->id} ({$daysBefore} days before) - Client: " . ($shoot->client->name ?? 'Unknown'));
                        $automationService->handleEvent('PROPERTY_CONTACT_REMINDER', $context);
                    }
                }
            }

            $this->info('Property contact reminders processed successfully.');
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to process property contact reminders: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Check if property contact details are missing
     */
    private function isPropertyContactMissing(Shoot $shoot): bool
    {
        $propertyDetails = $shoot->property_details ?? [];
        $presenceOption = $propertyDetails['presenceOption'] ?? null;

        // If no presence option is set, details are missing
        if (!$presenceOption) {
            return true;
        }

        // If presence is "other", check for contact name and phone
        if ($presenceOption === 'other') {
            $hasContactName = !empty($propertyDetails['accessContactName']);
            $hasContactPhone = !empty($propertyDetails['accessContactPhone']);
            
            if (!$hasContactName || !$hasContactPhone) {
                return true;
            }
        }

        // If presence is "lockbox", check for lockbox code and location
        if ($presenceOption === 'lockbox') {
            $hasLockboxCode = !empty($propertyDetails['lockboxCode']);
            $hasLockboxLocation = !empty($propertyDetails['lockboxLocation']);
            
            if (!$hasLockboxCode || !$hasLockboxLocation) {
                return true;
            }
        }

        // If presence is "self", details are provided (client will be there)
        return false;
    }
}
