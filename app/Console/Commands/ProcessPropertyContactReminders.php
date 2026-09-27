<?php

namespace App\Console\Commands;

use App\Models\AutomationRule;
use App\Models\Shoot;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\ScheduledAutomationDispatcher;
use App\Services\Schedule\ScheduleInstantResolver;
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
            $dispatcher = app(ScheduledAutomationDispatcher::class);
            $rules = AutomationRule::active()->where('trigger_type', 'PROPERTY_CONTACT_REMINDER')->get();

            foreach ($rules as $rule) {
                if (! $dispatcher->dailyTimeReached($rule, now(), '09:00')) {
                    continue;
                }
                $daysBefore = max(0, (int) ($rule->schedule_json['days_before'] ?? $rule->condition_json['days_before'] ?? 2));
                $targetDate = Carbon::today()->addDays($daysBefore);

                $shoots = Shoot::whereDate('scheduled_date', $targetDate)
                    ->whereNotNull('scheduled_date')
                    ->whereIn('workflow_status', [
                        Shoot::WORKFLOW_BOOKED,
                        Shoot::WORKFLOW_RAW_UPLOAD_PENDING,
                    ])
                    ->whereNotIn('status', ['cancelled', 'canceled', 'completed', 'delivered', 'declined'])
                    ->with(['client', 'photographer'])
                    ->get();

                foreach ($shoots as $shoot) {
                    $scheduledAt = app(ScheduleInstantResolver::class)->forShoot($shoot);
                    if ($shoot->isInternalTestShoot() || ($scheduledAt && $scheduledAt->isPast())) {
                        continue;
                    }
                    // Check if property contact details are missing
                    if ($this->isPropertyContactMissing($shoot)) {
                        $context = $automationService->buildShootContext($shoot);
                        $context['shoot_datetime'] = $scheduledAt ?? $shoot->scheduled_date;
                        $context['days_before'] = $daysBefore;
                        $context['reminder_type'] = $daysBefore === 0 ? 'shoot_day' : ($daysBefore === 1 ? 'one_day_before' : 'two_days_before');
                        $context['access_warning'] = $daysBefore === 0
                            ? 'Access details are still missing for today’s shoot. Please update them now so the team can proceed.'
                            : ($daysBefore === 1
                                ? 'Your shoot is tomorrow and access details are still missing. Please update them today.'
                                : 'Please add the missing property contact or access details before your upcoming shoot.');

                        $this->info("Triggering reminder for shoot #{$shoot->id} ({$daysBefore} days before) - Client: ".($shoot->client->name ?? 'Unknown'));
                        $key = sprintf('shoot:%d:appointment:%s:day:%s', $shoot->id,
                            ($scheduledAt ?? $shoot->scheduled_date)->toIso8601String(), now()->toDateString());
                        $dispatcher->dispatch($rule, $context, $key);
                    }
                }
            }

            $this->info('Property contact reminders processed successfully.');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to process property contact reminders: '.$e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * Check if property contact details are missing
     */
    private function isPropertyContactMissing(Shoot $shoot): bool
    {
        $propertyDetails = $shoot->property_details ?? [];
        if (trim((string) ($propertyDetails['lockboxCode'] ?? $propertyDetails['lockbox_code'] ?? '')) !== '') {
            return false;
        }
        $presenceOption = trim((string) ($propertyDetails['presenceOption'] ?? ''));

        // If no presence option is set, details are missing
        if (! in_array($presenceOption, ['self', 'other', 'lockbox'], true)) {
            return true;
        }

        // If presence is "other", check for contact name and phone
        if ($presenceOption === 'other') {
            $hasContactName = trim((string) ($propertyDetails['accessContactName'] ?? '')) !== '';
            $hasContactPhone = trim((string) ($propertyDetails['accessContactPhone'] ?? '')) !== '';

            if (! $hasContactName || ! $hasContactPhone) {
                return true;
            }
        }

        // If presence is "lockbox", check for lockbox code and location
        if ($presenceOption === 'lockbox') {
            $hasLockboxCode = trim((string) ($propertyDetails['lockboxCode'] ?? '')) !== '';
            $hasLockboxLocation = trim((string) ($propertyDetails['lockboxLocation'] ?? '')) !== '';

            if (! $hasLockboxCode || ! $hasLockboxLocation) {
                return true;
            }
        }

        // If presence is "self", details are provided (client will be there)
        return false;
    }
}
