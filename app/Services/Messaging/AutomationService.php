<?php

namespace App\Services\Messaging;

use App\Services\MailService;
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
    private const SALES_REP_ROLES = ['salesRep', 'sales_rep', 'salesrep'];
    private const ADMIN_ROLES = ['admin', 'superadmin', 'super_admin', 'editing_manager'];

    public function __construct(
        private readonly MessagingService $messagingService,
        private readonly TemplateRenderer $templateRenderer,
        private readonly TemplateVariableResolver $variableResolver,
        private readonly AutomationWorkflowExecutor $workflowExecutor,
        private readonly ?MailService $mailService = null,
    ) {
    }

    public function hasActiveTrigger(string $triggerType): bool
    {
        return AutomationRule::active()
            ->forTrigger($triggerType)
            ->exists();
    }

    /**
     * Handle an automation trigger event
     */
    public function handleEvent(string $triggerType, array $context): array
    {
        try {
            return $this->workflowExecutor->executeEventTrigger($triggerType, $context);
        } catch (\Throwable $exception) {
            Log::error('Automation event dispatch failed before workflow execution completed', [
                'trigger_type' => $triggerType,
                'error' => $exception->getMessage(),
            ]);

            return $this->emptyDispatchSummary($triggerType, $exception->getMessage());
        }
    }

    public function shouldUseFallback(string $triggerType, ?array $dispatchResult = null): bool
    {
        if (!is_array($dispatchResult)) {
            return true;
        }

        if (($dispatchResult['active_rule_count'] ?? 0) === 0) {
            return true;
        }

        if (strtoupper($triggerType) === 'ACCOUNT_CREATED' && empty($dispatchResult['email_sent_to'] ?? [])) {
            return true;
        }

        return !($dispatchResult['handled'] ?? false);
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

        $resolvedContext = $this->variableResolver->resolve(array_merge($context, [
            'recipient_type' => $recipient['type'] ?? 'other',
            'recipient_name' => $recipient['name'] ?? 'Customer',
            'recipient_email' => $recipient['email'] ?? null,
            'recipient_phone' => $recipient['phone'] ?? null,
        ]));
        $rendered = $this->templateRenderer->render($rule->template, $resolvedContext);

        if (!empty($rendered['missing'])) {
            Log::warning('Automation email missing template variables', [
                'rule_id' => $rule->id,
                'template_id' => $rule->template_id,
                'missing' => $rendered['missing'],
            ]);
        }

        $payload = [
            'to' => $recipient['email'] ?? $recipient['phone'] ?? null,
            'cc' => $this->resolveRelatedShootCcEmails($recipient, $context),
            'subject' => $rendered['subject'] ?? $rule->template->subject,
            'body_html' => $rendered['body_html'] ?? null,
            'body_text' => $rendered['body_text'] ?? null,
            'send_source' => 'AUTOMATION',
            'template_id' => $rule->template_id,
            'related_shoot_id' => $resolvedContext['shoot_id'] ?? null,
            'related_account_id' => $resolvedContext['account_id'] ?? null,
            'related_invoice_id' => $resolvedContext['invoice_id'] ?? null,
            'contact_email' => $recipient['email'] ?? null,
            'contact_phone' => $recipient['phone'] ?? null,
            'contact_name' => $recipient['name'] ?? 'Customer',
            'contact_type' => $recipient['type'] ?? 'other',
        ];

        if (!empty($context['tags_json'])) {
            $payload['tags_json'] = $context['tags_json'];
        }

        if (!empty($context['attachments_json'])) {
            $payload['attachments_json'] = $context['attachments_json'];
        }

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

        if (in_array($rule->trigger_type, ['SHOOT_REQUESTED', 'SHOOT_REQUEST_APPROVED', 'SHOOT_REQUEST_MODIFIED', 'SHOOT_REQUEST_DECLINED'], true)) {
            $recipientTypes = array_values(array_filter($recipientTypes, fn ($type) => $type === 'client'));
        }

        foreach ($recipientTypes as $type) {
            switch ($type) {
                case 'client':
                    if (!$this->shouldIncludeClientRecipient($rule, $context)) {
                        break;
                    }
                    if (!empty($context['client'])) {
                        $client = $context['client'];
                        $recipients[] = [
                            'email' => $client['email'] ?? $client->email ?? null,
                            'phone' => $client['phonenumber'] ?? $client->phonenumber ?? $client['phone'] ?? $client->phone ?? null,
                            'name' => $client['name'] ?? $client->name ?? 'Client',
                            'type' => 'client',
                        ];
                    }
                    break;

                case 'photographer':
                    if (!$this->shouldIncludePhotographerRecipient($rule, $context)) {
                        break;
                    }

                    foreach ($this->resolvePhotographerRecipients($rule, $context) as $photographer) {
                        $recipients[] = [
                            'email' => $photographer['email'] ?? $photographer->email ?? null,
                            'name' => $photographer['name'] ?? $photographer->name ?? 'Photographer',
                            'type' => 'photographer',
                        ];
                    }
                    break;

                case 'admin':
                    // Send to all admins
                    $admins = User::query()
                        ->where(function ($query) {
                            $query->whereIn('role', self::ADMIN_ROLES);

                            foreach (self::ADMIN_ROLES as $role) {
                                $query->orWhereJsonContains('secondary_roles', $role);
                            }
                        })
                        ->get()
                        ->unique('id')
                        ->values();
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

        return collect($recipients)
            ->filter(fn ($recipient) => !empty($recipient['email']) || !empty($recipient['phone']))
            ->unique(fn ($recipient) => strtolower((string) ($recipient['email'] ?? $recipient['phone'] ?? '')))
            ->values()
            ->all();
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

        // Special handling for PROPERTY_CONTACT_REMINDER - only trigger if contact details are missing
        if ($rule->trigger_type === 'PROPERTY_CONTACT_REMINDER') {
            $hasContactDetails = data_get($context, 'has_contact_details', false);
            $hasLockboxDetails = data_get($context, 'has_lockbox_details', false);
            $presenceOption = data_get($context, 'presence_option');
            
            // If presence option is not set, or required details are missing, trigger reminder
            if (!$presenceOption) {
                return true; // No presence option set, trigger reminder
            }
            
            if ($presenceOption === 'other' && !$hasContactDetails) {
                return true; // Other contact selected but details missing
            }
            
            if ($presenceOption === 'lockbox' && !$hasLockboxDetails) {
                return true; // Lockbox selected but details missing
            }
            
            // If presence is 'self' or all required details are provided, don't trigger
            return false;
        }

        return true;
    }

    /**
     * Trigger shoot reminder automations
     */
    public function triggerShootReminders(): void
    {
        $targetTime = Carbon::now()->addHours(24);

        $shoots = Shoot::query()
            ->where(function ($query) use ($targetTime) {
                $query->whereBetween('scheduled_at', [
                    $targetTime->copy()->subMinutes(5),
                    $targetTime->copy()->addMinutes(5),
                ])->orWhere(function ($fallback) use ($targetTime) {
                    $fallback->whereNull('scheduled_at')
                        ->whereNotNull('scheduled_date')
                        ->whereBetween('scheduled_date', [
                            $targetTime->copy()->subDay()->toDateString(),
                            $targetTime->copy()->addDay()->toDateString(),
                        ]);
                });
            })
            ->with(['client', 'photographer', 'rep', 'service', 'services', 'notes'])
            ->get();

        foreach ($shoots as $shoot) {
            $scheduledAt = $this->resolveShootDateTime($shoot);
            if (!$scheduledAt) {
                continue;
            }

            if ($scheduledAt->lt($targetTime->copy()->subMinutes(5)) || $scheduledAt->gt($targetTime->copy()->addMinutes(5))) {
                continue;
            }

            $tag = sprintf(
                'SHOOT_REMINDER:24H:shoot:%d:%s',
                $shoot->id,
                $scheduledAt->toIso8601String()
            );

            if ($this->hasSentAutomationTag($tag)) {
                continue;
            }

            $context = $this->buildShootContext($shoot);
            $context['shoot_datetime'] = $scheduledAt;
            $context['tags_json'] = [$tag];
            $shouldUseFallback = true;
            $clientEmailSent = false;
            $photographerEmailSent = false;

            if ($this->hasActiveTrigger('SHOOT_REMINDER')) {
                $dispatchResult = $this->handleEvent('SHOOT_REMINDER', $context);
                $shouldUseFallback = $this->shouldUseFallback('SHOOT_REMINDER', $dispatchResult) !== false;
                $clientEmailSent = (bool) ($dispatchResult['client_email_sent'] ?? false);
                $photographerEmailSent = (bool) ($dispatchResult['photographer_email_sent'] ?? false);
            }

            if ($this->mailService && !empty($context['client']) && ($shouldUseFallback || !$clientEmailSent)) {
                $this->mailService->sendShootReminderEmail(
                    $context['client'],
                    $shoot,
                    $scheduledAt,
                    [$tag],
                    false
                );
            }

            if ($this->mailService && !empty($context['photographers']) && ($shouldUseFallback || !$photographerEmailSent)) {
                foreach ($context['photographers'] as $photographer) {
                    $this->mailService->sendShootReminderEmail(
                        $photographer,
                        $shoot,
                        $scheduledAt,
                        [$tag],
                        false
                    );
                }
            }
        }
    }

    public function buildUserContext(User $user): array
    {
        $context = [
            'account_id' => $user->id,
        ];

        $role = strtolower((string) $user->role);
        if ($role === 'client') {
            $context['client'] = $user;
        } elseif ($role === 'photographer') {
            $context['photographer'] = $user;
        } elseif (in_array($role, array_map('strtolower', self::SALES_REP_ROLES), true)) {
            $context['rep'] = $user;
        } else {
            $context['client'] = $user;
        }

        return $context;
    }

    /**
     * Build context array from a shoot model
     */
    public function buildShootContext(Shoot $shoot): array
    {
        $shoot->loadMissing(['client', 'photographer', 'rep', 'service', 'services', 'notes']);
        $propertyDetails = $shoot->property_details ?? [];
        $assignedPhotographers = $this->resolveAssignedPhotographers($shoot);

        return [
            'shoot' => $shoot,
            'shoot_id' => $shoot->id,
            'shoot_date' => $shoot->scheduled_date?->format('M j, Y')
                ?? $shoot->scheduled_at?->format('M j, Y'),
            'shoot_time' => $this->formatShootTime($shoot),
            'shoot_datetime' => $this->resolveShootDateTime($shoot),
            'shoot_address' => $shoot->address ?? 'N/A',
            'shoot_services' => $shoot->services->count() > 0
                ? $shoot->services->pluck('name')->implode(', ')
                : ($shoot->service?->name ?? 'Photography'),
            'shoot_notes' => $this->formatShootNotes($shoot),
            'client' => $shoot->client,
            'photographer' => $assignedPhotographers[0] ?? $shoot->photographer,
            'photographers' => $assignedPhotographers,
            'account_id' => $shoot->client_id,
            'property_details' => $propertyDetails,
            'presence_option' => $propertyDetails['presenceOption'] ?? null,
            'has_contact_details' => !empty($propertyDetails['accessContactName']) && !empty($propertyDetails['accessContactPhone']),
            'has_lockbox_details' => !empty($propertyDetails['lockboxCode']) && !empty($propertyDetails['lockboxLocation']),
        ];
    }

    private function formatShootTime(Shoot $shoot): string
    {
        $time = $shoot->time;
        if (!empty($time)) {
            try {
                return Carbon::parse($time)->format('g:i A');
            } catch (\Exception $e) {
                return $time;
            }
        }

        if ($shoot->scheduled_at) {
            return $shoot->scheduled_at->format('g:i A');
        }

        if ($shoot->scheduled_date && $shoot->scheduled_date->format('H:i') !== '00:00') {
            return $shoot->scheduled_date->format('g:i A');
        }

        return 'TBD';
    }

    private function resolveShootDateTime(Shoot $shoot): ?Carbon
    {
        if ($shoot->scheduled_at) {
            return $shoot->scheduled_at instanceof Carbon
                ? $shoot->scheduled_at->copy()
                : Carbon::parse($shoot->scheduled_at);
        }

        if (!$shoot->scheduled_date) {
            return null;
        }

        try {
            return Carbon::parse(
                trim(sprintf(
                    '%s %s',
                    $shoot->scheduled_date instanceof \DateTimeInterface
                        ? $shoot->scheduled_date->format('Y-m-d')
                        : (string) $shoot->scheduled_date,
                    $shoot->time ?: '00:00'
                ))
            );
        } catch (\Exception) {
            return null;
        }
    }

    private function formatShootNotes(Shoot $shoot): string
    {
        $notes = [];

        if (!empty($shoot->shoot_notes)) {
            $notes[] = $shoot->shoot_notes;
        }

        if (!$shoot->relationLoaded('notes')) {
            $shoot->load('notes');
        }

        foreach ($shoot->notes ?? [] as $note) {
            if (!empty($note->content) && $note->visibility === 'client_visible') {
                $notes[] = $note->content;
            }
        }

        $notes = array_filter($notes, fn($note) => trim((string) $note) !== '');

        return $notes ? implode("\n", $notes) : 'N/A';
    }

    private function shouldIncludeClientRecipient(AutomationRule $rule, array $context): bool
    {
        if (ShootEmailMatrix::hasEvent($rule->trigger_type) && !ShootEmailMatrix::includesClient($rule->trigger_type)) {
            return false;
        }

        if (
            ($context['notify_client'] ?? null) === false
            && in_array($rule->trigger_type, [
                ShootEmailMatrix::SHOOT_SCHEDULED,
                ShootEmailMatrix::SHOOT_UPDATED,
            ], true)
        ) {
            return false;
        }

        return true;
    }

    private function shouldIncludePhotographerRecipient(AutomationRule $rule, array $context): bool
    {
        if (ShootEmailMatrix::hasEvent($rule->trigger_type) && !ShootEmailMatrix::includesPhotographer($rule->trigger_type)) {
            return false;
        }

        if (
            ($context['notify_photographer'] ?? null) === false
            && in_array($rule->trigger_type, [
                ShootEmailMatrix::SHOOT_SCHEDULED,
                ShootEmailMatrix::SHOOT_UPDATED,
                ShootEmailMatrix::PHOTOGRAPHER_CHANGED,
            ], true)
        ) {
            return false;
        }

        if (
            $rule->trigger_type === ShootEmailMatrix::SHOOT_UPDATED
            && !empty($context['photographer_changed'])
        ) {
            return false;
        }

        return true;
    }

    /**
     * @return array<int, mixed>
     */
    private function resolvePhotographerRecipients(AutomationRule $rule, array $context): array
    {
        if ($rule->trigger_type === ShootEmailMatrix::PHOTOGRAPHER_CHANGED && !empty($context['affected_photographers'])) {
            return collect($context['affected_photographers'])->filter()->values()->all();
        }

        if (!empty($context['photographers'])) {
            return collect($context['photographers'])->filter()->values()->all();
        }

        if (!empty($context['photographer'])) {
            return [$context['photographer']];
        }

        return [];
    }

    /**
     * @return array<int, User>
     */
    private function resolveAssignedPhotographers(Shoot $shoot): array
    {
        $shoot->loadMissing(['photographer', 'services']);

        $photographerIds = collect([
            $shoot->photographer_id,
            $shoot->photographer?->id,
        ])
            ->merge(
                collect($shoot->services ?? [])
                    ->pluck('pivot.photographer_id')
                    ->filter()
            )
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($photographerIds->isEmpty()) {
            return [];
        }

        $photographers = User::query()
            ->whereIn('id', $photographerIds->all())
            ->get()
            ->keyBy('id');

        if ($shoot->photographer) {
            $photographers->put($shoot->photographer->id, $shoot->photographer);
        }

        return $photographerIds
            ->map(fn ($id) => $photographers->get((int) $id))
            ->filter(fn ($user) => $user instanceof User)
            ->unique('id')
            ->values()
            ->all();
    }

    private function hasSentAutomationTag(string $tag): bool
    {
        return Message::query()
            ->where('send_source', 'AUTOMATION')
            ->where('tags_json', 'like', '%' . $tag . '%')
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $recipient
     * @param  array<string, mixed>  $context
     * @return array<int, string>
     */
    private function resolveRelatedShootCcEmails(array $recipient, array $context): array
    {
        if (($recipient['type'] ?? null) !== 'client') {
            return [];
        }

        $client = null;

        if (!empty($context['client'])) {
            if ($context['client'] instanceof User) {
                $client = $context['client'];
            } elseif (is_array($context['client'])) {
                if (!empty($context['client']['shoot_cc_emails']) || !empty($context['client']['shootCcEmails'])) {
                    return $this->normalizeEmailAddresses(
                        $context['client']['shoot_cc_emails'] ?? $context['client']['shootCcEmails'] ?? [],
                        $recipient['email'] ?? $context['client']['email'] ?? null
                    );
                }

                $client = User::find($context['client']['id'] ?? null);
            }
        }

        if (!$client && !empty($context['account_id'])) {
            $account = User::find($context['account_id']);
            if ($account && $account->role === 'client') {
                $client = $account;
            }
        }

        if (!$client && !empty($context['shoot_id'])) {
            $client = Shoot::query()
                ->with('client')
                ->find($context['shoot_id'])
                ?->client;
        }

        if (!$client && !empty($context['invoice_id'])) {
            $invoice = Invoice::query()
                ->with(['client', 'shoot.client'])
                ->find($context['invoice_id']);
            $client = $invoice?->shoot?->client ?? $invoice?->client;
        }

        return $this->normalizeEmailAddresses($client?->shoot_cc_emails ?? [], $recipient['email'] ?? $client?->email);
    }

    /**
     * @param  mixed  $emails
     * @return array<int, string>
     */
    private function normalizeEmailAddresses(mixed $emails, ?string $exclude = null): array
    {
        $excluded = is_string($exclude) ? strtolower(trim($exclude)) : null;

        return collect(is_array($emails) ? $emails : [])
            ->filter(fn ($email) => is_string($email) && trim($email) !== '')
            ->map(fn ($email) => strtolower(trim($email)))
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
            ->reject(fn ($email) => $excluded !== null && $email === $excluded)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array{
     *   trigger_type: string,
     *   active_rule_count: int,
     *   run_count: int,
     *   completed_run_count: int,
     *   waiting_run_count: int,
     *   failed_run_count: int,
     *   handled: bool,
     *   errors: array<int, array{automation_id: int, message: string}>
     * }
     */
    private function emptyDispatchSummary(string $triggerType, ?string $errorMessage = null): array
    {
        return [
            'trigger_type' => $triggerType,
            'active_rule_count' => 0,
            'run_count' => 0,
            'completed_run_count' => 0,
            'waiting_run_count' => 0,
            'failed_run_count' => $errorMessage ? 1 : 0,
            'handled' => false,
            'errors' => $errorMessage ? [['automation_id' => 0, 'message' => $errorMessage]] : [],
            'email_sent_to' => [],
            'client_email_sent' => false,
            'photographer_email_sent' => false,
        ];
    }
}

