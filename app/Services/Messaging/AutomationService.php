<?php

namespace App\Services\Messaging;

use App\Models\AutomationRule;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\Shoot;
use App\Models\User;
use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class AutomationService
{
    public function __construct(
        private readonly MessagingService $messagingService,
        private readonly TemplateRenderer $templateRenderer
    ) {
    }

    /**
     * Handle an automation trigger event
     */
    public function handleEvent(string $triggerType, array $context): void
    {
        $rules = AutomationRule::active()
            ->forTrigger($triggerType)
            ->with(['template', 'channel'])
            ->get();

        foreach ($rules as $rule) {
            if ($this->evaluateCondition($rule, $context)) {
                $this->executeRule($rule, $context);
            }
        }
    }

    /**
     * Execute an automation rule
     */
    private function executeRule(AutomationRule $rule, array $context): void
    {
        $recipients = $this->resolveRecipients($rule, $context);

        foreach ($recipients as $recipient) {
            try {
                $this->sendMessage($rule, $recipient, $context);
            } catch (\Exception $e) {
                Log::error('Automation rule execution failed', [
                    'rule_id' => $rule->id,
                    'recipient' => $recipient['email'] ?? $recipient['phone'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Send a message from an automation rule
     */
    private function sendMessage(AutomationRule $rule, array $recipient, array $context): void
    {
        if (!$rule->template) {
            Log::warning('Automation rule has no template', ['rule_id' => $rule->id]);
            return;
        }

        // Render template with context
        $rendered = $this->templateRenderer->render($rule->template, $context);

        $payload = [
            'to' => $recipient['email'] ?? $recipient['phone'] ?? null,
            'subject' => $rendered['subject'] ?? $rule->template->subject,
            'body_html' => $rendered['body_html'] ?? null,
            'body_text' => $rendered['body_text'] ?? null,
            'send_source' => 'AUTOMATION',
            'template_id' => $rule->template_id,
            'related_shoot_id' => $context['shoot_id'] ?? null,
            'related_account_id' => $context['account_id'] ?? null,
            'related_invoice_id' => $context['invoice_id'] ?? null,
            'contact_email' => $recipient['email'] ?? null,
            'contact_phone' => $recipient['phone'] ?? null,
            'contact_name' => $recipient['name'] ?? 'Customer',
            'contact_type' => $recipient['type'] ?? 'other',
        ];

        if ($rule->channel_id) {
            $payload['channel_id'] = $rule->channel_id;
        }

        // Calculate schedule time if needed
        $scheduledAt = $this->calculateScheduleTime($rule, $context);

        if ($rule->template->channel === 'EMAIL') {
            if ($scheduledAt) {
                $this->messagingService->scheduleEmail($payload, $scheduledAt);
            } else {
                $this->messagingService->sendEmail($payload);
            }
        } elseif ($rule->template->channel === 'SMS') {
            // SMS doesn't support scheduling in our current setup
            $this->messagingService->sendSms($payload);
        }
    }

    /**
     * Calculate when to send the message based on schedule_json
     */
    private function calculateScheduleTime(AutomationRule $rule, array $context): ?Carbon
    {
        if (empty($rule->schedule_json)) {
            return null;
        }

        $schedule = $rule->schedule_json;

        // Handle offset-based scheduling (e.g., "-24h" before shoot)
        if (!empty($schedule['offset'])) {
            $referenceTime = null;

            // Get reference time from context (shoot date, etc.)
            if (!empty($context['shoot_datetime'])) {
                $referenceTime = Carbon::parse($context['shoot_datetime']);
            } elseif (!empty($context['shoot_date'])) {
                $referenceTime = Carbon::parse($context['shoot_date']);
            }

            if ($referenceTime) {
                $offset = $schedule['offset'];
                if (preg_match('/^([+-]?\d+)(h|d|m)$/', $offset, $matches)) {
                    $amount = (int) $matches[1];
                    $unit = $matches[2];

                    switch ($unit) {
                        case 'h':
                            return $referenceTime->addHours($amount);
                        case 'd':
                            return $referenceTime->addDays($amount);
                        case 'm':
                            return $referenceTime->addMinutes($amount);
                    }
                }
            }
        }

        // Handle cron-like scheduling (e.g., "monday 9:00" for weekly reports)
        if (!empty($schedule['cron'])) {
            // This would need proper cron parsing; for now, just return null to send immediately
            // In production, you'd use a package like cron-expression
            return null;
        }

        return null;
    }

    /**
     * Resolve recipients based on rule configuration
     */
    private function resolveRecipients(AutomationRule $rule, array $context): array
    {
        $recipients = [];
        $recipientTypes = $rule->recipients_json ?? [];

        foreach ($recipientTypes as $type) {
            switch ($type) {
                case 'client':
                    if (!empty($context['client'])) {
                        $client = $context['client'];
                        $recipients[] = [
                            'email' => $client['email'] ?? $client->email ?? null,
                            'name' => $client['name'] ?? $client->name ?? 'Client',
                            'type' => 'client',
                        ];
                    }
                    break;

                case 'photographer':
                    if (!empty($context['photographer'])) {
                        $photographer = $context['photographer'];
                        $recipients[] = [
                            'email' => $photographer['email'] ?? $photographer->email ?? null,
                            'name' => $photographer['name'] ?? $photographer->name ?? 'Photographer',
                            'type' => 'photographer',
                        ];
                    }
                    break;

                case 'admin':
                    // Send to all admins
                    $admins = User::whereIn('role', ['admin', 'superadmin'])->get();
                    foreach ($admins as $admin) {
                        $recipients[] = [
                            'email' => $admin->email,
                            'name' => $admin->name ?? 'Admin',
                            'type' => 'admin',
                        ];
                    }
                    break;

                case 'rep':
                    if (!empty($context['rep'])) {
                        $rep = $context['rep'];
                        $recipients[] = [
                            'email' => $rep['email'] ?? $rep->email ?? null,
                            'name' => $rep['name'] ?? $rep->name ?? 'Rep',
                            'type' => 'rep',
                        ];
                    }
                    break;
            }
        }

        return array_filter($recipients, fn($r) => !empty($r['email']) || !empty($r['phone']));
    }

    /**
     * Evaluate automation rule conditions
     */
    private function evaluateCondition(AutomationRule $rule, array $context): bool
    {
        if (empty($rule->condition_json)) {
            return true;
        }

        // Simple condition evaluation
        // In production, you'd want a proper expression evaluator
        $conditions = $rule->condition_json;

        foreach ($conditions as $field => $expected) {
            $actual = data_get($context, $field);

            if (is_array($expected)) {
                // Handle operators like gt, lt, in, etc.
                if (isset($expected['gt']) && $actual <= $expected['gt']) {
                    return false;
                }
                if (isset($expected['lt']) && $actual >= $expected['lt']) {
                    return false;
                }
                if (isset($expected['in']) && !in_array($actual, $expected['in'])) {
                    return false;
                }
            } else {
                // Simple equality check
                if ($actual != $expected) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Trigger shoot reminder automations
     */
    public function triggerShootReminders(): void
    {
        // Find shoots happening in the next 24-48 hours
        $upcomingShootsDates = [
            Carbon::now()->addHours(24),
            Carbon::now()->addHours(2),
        ];

        foreach ($upcomingShootsDates as $targetTime) {
            $shoots = Shoot::whereBetween('scheduled_date', [
                $targetTime->copy()->subMinutes(5),
                $targetTime->copy()->addMinutes(5),
            ])->get();

            foreach ($shoots as $shoot) {
                $context = $this->buildShootContext($shoot);
                $context['shoot_datetime'] = $shoot->scheduled_date;

                $this->handleEvent('SHOOT_REMINDER', $context);
            }
        }
    }

    /**
     * Build context array from a shoot model
     */
    private function buildShootContext(Shoot $shoot): array
    {
        return [
            'shoot_id' => $shoot->id,
            'shoot_date' => $shoot->scheduled_date?->format('Y-m-d'),
            'shoot_time' => $shoot->scheduled_date?->format('H:i'),
            'shoot_datetime' => $shoot->scheduled_date,
            'shoot_address' => $shoot->property_address ?? 'N/A',
            'shoot_services' => $shoot->service?->name ?? 'Photography',
            'shoot_notes' => $shoot->notes ?? '',
            'client' => $shoot->client,
            'photographer' => $shoot->photographer,
            'account_id' => $shoot->client_id,
        ];
    }
}

