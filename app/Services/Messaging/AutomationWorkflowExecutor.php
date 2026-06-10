<?php

namespace App\Services\Messaging;

use App\Models\Payment;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\AutomationRunStep;
use App\Models\MessageTemplate;
use App\Models\Shoot;
use App\Models\User;
use App\Services\MailService;
use App\Services\SystemEmails\ProtectedAutomationEmailMap;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AutomationWorkflowExecutor
{
    private const SALES_REP_ROLES = ['salesRep', 'sales_rep', 'salesrep'];
    private const ADMIN_ROLES = ['admin', 'superadmin', 'super_admin', 'editing_manager'];

    public function __construct(
        private readonly MessagingService $messagingService,
        private readonly TemplateRenderer $templateRenderer,
        private readonly TemplateVariableResolver $variableResolver,
        private readonly AutomationWorkflowConverter $workflowConverter,
        private readonly AutomationWorkflowValidator $workflowValidator,
        private readonly MailService $mailService,
        private readonly ProtectedAutomationEmailMap $protectedAutomationEmailMap,
    ) {
    }

    public function executeEventTrigger(string $triggerType, array $context): array
    {
        $rules = AutomationRule::query()
            ->active()
            ->where('trigger_type', $triggerType)
            ->with(['template', 'channel'])
            ->get();

        $runs = [];
        $errors = [];

        foreach ($rules as $rule) {
            try {
                $runs[] = $this->executeAutomation($rule, $context);
            } catch (\Throwable $exception) {
                $errors[] = [
                    'automation_id' => $rule->id,
                    'message' => $exception->getMessage(),
                ];

                Log::error('Automation workflow event dispatch failed', [
                    'trigger_type' => $triggerType,
                    'automation_id' => $rule->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $this->summarizeEventDispatch($triggerType, $rules, $runs, $errors);
    }

    public function executeAutomation(AutomationRule $automation, array $context, bool $simulate = false): array|AutomationRun
    {
        $workflow = $this->workflowConverter->getWorkflowDefinition($automation);
        $validation = $this->workflowValidator->validate($workflow);

        if (!$validation['valid']) {
            if ($simulate) {
                return [
                    'validation' => $validation,
                    'trace' => [],
                ];
            }

            return AutomationRun::create([
                'automation_rule_id' => $automation->id,
                'trigger_type' => $automation->trigger_type,
                'status' => 'failed',
                'context_json' => $context,
                'related_shoot_id' => $context['shoot_id'] ?? null,
                'related_account_id' => $context['account_id'] ?? null,
                'related_invoice_id' => $context['invoice_id'] ?? null,
                'started_at' => now(),
                'completed_at' => now(),
                'error_message' => implode("\n", $validation['errors']),
            ]);
        }

        $resolvedContext = $this->variableResolver->resolve($context);
        $triggerNode = collect($workflow['nodes'])->first(fn (array $node) => str_starts_with((string) ($node['type'] ?? ''), 'trigger.'));
        $queue = $this->nextNodeIds($workflow, $triggerNode['id'] ?? null);

        if ($simulate) {
            $trace = $this->simulateQueue($automation, $workflow, $resolvedContext, $queue);
            return [
                'validation' => $validation,
                'trace' => $trace,
            ];
        }

        $run = AutomationRun::create([
            'automation_rule_id' => $automation->id,
            'trigger_type' => $automation->trigger_type,
            'status' => 'running',
            'context_json' => $resolvedContext,
            'related_shoot_id' => $resolvedContext['shoot_id'] ?? null,
            'related_account_id' => $resolvedContext['account_id'] ?? null,
            'related_invoice_id' => $resolvedContext['invoice_id'] ?? null,
            'started_at' => now(),
        ]);

        try {
            $this->processQueue($automation, $workflow, $run, $resolvedContext, $queue);
        } catch (\Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_message' => $exception->getMessage(),
            ]);

            Log::error('Automation workflow execution failed', [
                'automation_id' => $automation->id,
                'run_id' => $run->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return $run->fresh('steps');
    }

    public function resumeDueSteps(): void
    {
        AutomationRunStep::query()
            ->where('status', 'waiting')
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', now())
            ->with(['run.automationRule.template', 'run.automationRule.channel'])
            ->orderBy('scheduled_for')
            ->chunkById(50, function ($steps): void {
                foreach ($steps as $step) {
                    $run = $step->run;
                    $automation = $run?->automationRule;

                    if (!$run || !$automation) {
                        continue;
                    }

                    $workflow = $this->workflowConverter->getWorkflowDefinition($automation);
                    $context = is_array($run->context_json) ? $run->context_json : [];
                    $nextNodeIds = Arr::wrap($step->output_json['next_node_ids'] ?? []);

                    $step->update([
                        'status' => 'completed',
                        'completed_at' => now(),
                    ]);

                    $run->update([
                        'status' => 'running',
                        'error_message' => null,
                    ]);

                    $this->processQueue($automation, $workflow, $run, $context, $nextNodeIds);
                }
            });
    }

    public function createSystemRun(AutomationRule $automation, array $context, string $command, Carbon $scheduledFor, callable $callback): AutomationRun
    {
        $run = AutomationRun::create([
            'automation_rule_id' => $automation->id,
            'trigger_type' => $automation->trigger_type,
            'status' => 'running',
            'context_json' => $context,
            'scheduled_for' => $scheduledFor,
            'started_at' => now(),
        ]);

        $step = AutomationRunStep::create([
            'automation_run_id' => $run->id,
            'automation_rule_id' => $automation->id,
            'node_id' => 'system_command',
            'node_type' => 'trigger.schedule',
            'status' => 'running',
            'attempt_count' => 1,
            'scheduled_for' => $scheduledFor,
            'started_at' => now(),
            'input_json' => [
                'command' => $command,
            ],
        ]);

        try {
            $output = $callback();

            $step->update([
                'status' => 'completed',
                'completed_at' => now(),
                'output_json' => [
                    'command' => $command,
                    'output' => $output,
                ],
            ]);

            $run->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            $step->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_message' => $exception->getMessage(),
            ]);

            $run->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_message' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        return $run;
    }

    private function processQueue(AutomationRule $automation, array $workflow, AutomationRun $run, array $context, array $queue): void
    {
        $nodeMap = collect($workflow['nodes'])->keyBy('id');

        while ($queue !== []) {
            $nodeId = array_shift($queue);
            $node = $nodeMap->get($nodeId);

            if (!$node) {
                continue;
            }

            $step = AutomationRunStep::create([
                'automation_run_id' => $run->id,
                'automation_rule_id' => $automation->id,
                'node_id' => $nodeId,
                'node_type' => $node['type'],
                'status' => 'running',
                'attempt_count' => 1,
                'started_at' => now(),
                'input_json' => [
                    'config' => $node['config'] ?? [],
                ],
            ]);

            $nextNodeIds = [];
            $output = [];

            try {
                switch ($node['type']) {
                    case 'condition.if':
                        $branch = $this->evaluateConditionNode($node, $context) ? 'true' : 'false';
                        $nextNodeIds = $this->nextNodeIds($workflow, $nodeId, $branch);
                        $output = ['branch' => $branch];
                        break;

                    case 'wait.duration':
                    case 'wait.datetime_offset':
                        $scheduledFor = $this->resolveWaitSchedule($node, $context);
                        if ($scheduledFor && $scheduledFor->gt(now())) {
                            $step->update([
                                'status' => 'waiting',
                                'scheduled_for' => $scheduledFor,
                                'output_json' => [
                                    'next_node_ids' => $this->nextNodeIds($workflow, $nodeId),
                                ],
                            ]);
                            $run->update([
                                'status' => 'waiting',
                                'scheduled_for' => $scheduledFor,
                            ]);
                            return;
                        }

                        $nextNodeIds = $this->nextNodeIds($workflow, $nodeId);
                        $output = ['resumed_immediately' => true];
                        break;

                    case 'action.email':
                        $output = $this->executeEmailAction($automation, $node, $context);
                        $nextNodeIds = $this->nextNodeIds($workflow, $nodeId);
                        break;

                    case 'action.sms':
                        $output = $this->executeSmsAction($automation, $node, $context);
                        $nextNodeIds = $this->nextNodeIds($workflow, $nodeId);
                        break;

                    case 'action.internal_notification':
                        $output = $this->executeInternalNotificationAction($automation, $node, $context);
                        $nextNodeIds = $this->nextNodeIds($workflow, $nodeId);
                        break;

                    case 'end':
                        $output = ['ended' => true];
                        $nextNodeIds = [];
                        break;

                    default:
                        $nextNodeIds = $this->nextNodeIds($workflow, $nodeId);
                        $output = ['passthrough' => true];
                        break;
                }

                $step->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                    'output_json' => $output,
                ]);
            } catch (\Throwable $exception) {
                $step->update([
                    'status' => 'failed',
                    'completed_at' => now(),
                    'error_message' => $exception->getMessage(),
                ]);

                throw $exception;
            }

            foreach ($nextNodeIds as $nextNodeId) {
                $queue[] = $nextNodeId;
            }
        }

        $run->update([
            'status' => 'completed',
            'completed_at' => now(),
            'scheduled_for' => null,
        ]);
    }

    private function evaluateConditionNode(array $node, array $context): bool
    {
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $rules = Arr::wrap($config['rules'] ?? []);
        $match = $config['match'] ?? 'all';

        $results = collect($rules)->map(function (array $rule) use ($context): bool {
            $actual = data_get($context, $rule['field'] ?? '');
            $expected = $rule['value'] ?? null;
            $operator = $rule['operator'] ?? 'eq';

            return match ($operator) {
                'neq' => $actual != $expected,
                'gt' => $actual > $expected,
                'gte' => $actual >= $expected,
                'lt' => $actual < $expected,
                'lte' => $actual <= $expected,
                'contains' => Str::contains((string) $actual, (string) $expected),
                'exists' => !empty($actual),
                'in' => in_array($actual, Arr::wrap($expected), true),
                default => $actual == $expected,
            };
        })->all();

        return $match === 'any'
            ? in_array(true, $results, true)
            : !in_array(false, $results, true);
    }

    private function resolveWaitSchedule(array $node, array $context): ?Carbon
    {
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];

        if ($node['type'] === 'wait.duration') {
            $amount = (int) ($config['amount'] ?? 0);
            $unit = $config['unit'] ?? 'minutes';
            if ($amount <= 0) {
                return null;
            }

            return match ($unit) {
                'days' => now()->addDays($amount),
                'hours' => now()->addHours($amount),
                default => now()->addMinutes($amount),
            };
        }

        $referenceField = $config['referenceField'] ?? 'shoot_datetime';
        $reference = data_get($context, $referenceField) ?? data_get($context, 'shoot_datetime') ?? data_get($context, 'shoot_date');
        if (!$reference) {
            return null;
        }

        $amount = (int) ($config['amount'] ?? 0);
        $unit = $config['unit'] ?? 'hours';
        $direction = $config['direction'] ?? 'before';

        $time = Carbon::parse($reference);
        $multiplier = $direction === 'before' ? -1 : 1;

        return match ($unit) {
            'days' => $time->addDays($amount * $multiplier),
            'minutes' => $time->addMinutes($amount * $multiplier),
            default => $time->addHours($amount * $multiplier),
        };
    }

    private function shouldSkipCoreSystemEmailAutomation(AutomationRule $automation, array $context): bool
    {
        if (empty($context['system_email_already_sent'])) {
            return false;
        }

        return in_array($automation->trigger_type, ['SHOOT_COMPLETED', 'SHOOT_REMOVED', 'SHOOT_CANCELED', 'SHOOT_CANCELLED', 'SHOOT_PAID'], true);
    }

    private function executeEmailAction(AutomationRule $automation, array $node, array $context): array
    {
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $template = !empty($config['templateId'])
            ? MessageTemplate::find($config['templateId'])
            : null;

        $protectedResult = $this->executeProtectedEmailAction($automation, $config, $context, $template);

        if ($protectedResult !== null) {
            return $protectedResult;
        }

        if ($this->shouldSkipCoreSystemEmailAutomation($automation, $context)) {
            return [
                'channel' => 'email',
                'sent_to' => [],
                'skipped' => true,
            ];
        }

        $rendered = $template
            ? $this->templateRenderer->render($template, $context)
            : $this->renderInlineMessage($config, $context);

        $recipients = $this->resolveActionRecipients($automation, $config, $context, 'email');
        $sentTo = [];

        foreach ($recipients as $recipient) {
            if (empty($recipient['email'])) {
                continue;
            }

            $this->messagingService->sendEmail([
                'to' => $recipient['email'],
                'subject' => $rendered['subject'] ?? '',
                'body_html' => $rendered['body_html'] ?? '',
                'body_text' => $rendered['body_text'] ?? '',
                'channel_id' => $config['channelId'] ?? $automation->channel_id,
                'template_id' => $template?->id,
                'related_shoot_id' => $context['shoot_id'] ?? null,
                'related_account_id' => $context['account_id'] ?? null,
                'related_invoice_id' => $context['invoice_id'] ?? null,
                'send_source' => 'AUTOMATION',
                'contact_email' => $recipient['email'],
                'contact_name' => $recipient['name'] ?? 'Recipient',
                'contact_type' => $recipient['type'] ?? 'other',
                'tags_json' => $context['tags_json'] ?? null,
                'attachments_json' => $context['attachments_json'] ?? null,
                'metadata' => $context['metadata'] ?? null,
            ]);

            $sentTo[] = $recipient['email'];
        }

        return [
            'channel' => 'email',
            'sent_to' => $sentTo,
        ];
    }

    private function executeProtectedEmailAction(
        AutomationRule $automation,
        array $config,
        array $context,
        ?MessageTemplate $template
    ): ?array {
        $triggerType = strtoupper((string) $automation->trigger_type);

        if (!$this->protectedAutomationEmailMap->isProtectedTrigger($triggerType)) {
            return null;
        }

        if ($this->shouldSkipCoreSystemEmailAutomation($automation, $context)) {
            return [
                'channel' => 'email',
                'sent_to' => [],
                'skipped' => true,
                'protected' => true,
            ];
        }

        if ($template !== null || !empty($config['bodyHtml']) || !empty($config['bodyText']) || !empty($config['subject'])) {
            Log::warning('Ignoring legacy automation email HTML for protected trigger.', [
                'automation_id' => $automation->id,
                'trigger_type' => $automation->trigger_type,
                'template_id' => $template?->id,
            ]);
        }

        $recipientTypes = collect($this->resolveActionRecipients($automation, $config, $context, 'email'))
            ->pluck('type')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $sentTo = $this->dispatchProtectedTrigger($triggerType, $recipientTypes, $context);

        return [
            'channel' => 'email',
            'sent_to' => $sentTo,
            'protected' => true,
        ];
    }

    /**
     * @param  array<int, string>  $recipientTypes
     * @return array<int, string>
     */
    private function dispatchProtectedTrigger(string $triggerType, array $recipientTypes, array $context): array
    {
        $shoot = $this->contextShoot($context);
        $client = $this->contextUser($context, 'client');
        $rep = $this->contextUser($context, 'rep');
        $payment = $this->contextPayment($context);
        $sentTo = [];

        switch ($triggerType) {
            case 'ACCOUNT_CREATED':
                $user = $this->contextUser($context, 'account') ?? $client ?? $rep ?? $this->contextUser($context, 'photographer');
                $resetLink = (string) ($context['password_reset_link'] ?? '');
                $verificationLink = isset($context['verification_link']) ? (string) $context['verification_link'] : null;
                $equipmentVerificationLink = isset($context['equipment_verification_link'])
                    ? (string) $context['equipment_verification_link']
                    : null;
                $pendingEquipmentCount = (int) ($context['pending_equipment_count'] ?? 0);
                $includePasswordCreationLink = (bool) ($context['include_password_creation_link'] ?? false);
                if ($user && $resetLink !== '' && $this->mailService->sendAccountCreatedEmail(
                    $user,
                    $resetLink,
                    $verificationLink,
                    $equipmentVerificationLink,
                    $pendingEquipmentCount,
                    $includePasswordCreationLink
                )) {
                    $sentTo[] = $user->email;
                }
                break;

            case 'PASSWORD_RESET':
                $user = $client ?? $rep ?? $this->contextUser($context, 'photographer');
                $resetLink = (string) ($context['password_reset_link'] ?? '');
                if ($user && $resetLink !== '' && $this->mailService->sendPasswordResetEmail($user, $resetLink)) {
                    $sentTo[] = $user->email;
                }
                break;

            case 'SHOOT_BOOKED':
            case 'PHOTOGRAPHER_ASSIGNED':
                if ($shoot && in_array('photographer', $recipientTypes, true) && $this->mailService->sendAssignedPhotographerShootScheduledEmails($shoot)) {
                    $sentTo = array_merge($sentTo, $this->recipientEmails($this->assignedPhotographers($shoot)));
                }
                break;

            case 'SHOOT_SCHEDULED':
                if ($shoot && $client && in_array('client', $recipientTypes, true)) {
                    $paymentLink = (string) ($context['payment_link'] ?? ($context['paymentLink'] ?? $this->mailService->generatePaymentLink($shoot)));
                    if ($this->mailService->sendShootScheduledEmail($client, $shoot, $paymentLink, in_array('photographer', $recipientTypes, true))) {
                        $sentTo[] = $client->email;
                        if (in_array('photographer', $recipientTypes, true)) {
                            $sentTo = array_merge($sentTo, $this->recipientEmails($this->assignedPhotographers($shoot)));
                        }
                    }
                } elseif ($shoot && in_array('photographer', $recipientTypes, true) && $this->mailService->sendAssignedPhotographerShootScheduledEmails($shoot)) {
                    $sentTo = array_merge($sentTo, $this->recipientEmails($this->assignedPhotographers($shoot)));
                }
                break;

            case 'SHOOT_REMINDER':
                if ($shoot && $client && in_array('client', $recipientTypes, true)) {
                    $scheduledAt = $this->contextSchedule($context);
                    if ($this->mailService->sendShootReminderEmail($client, $shoot, $scheduledAt, (array) ($context['tags_json'] ?? []), in_array('photographer', $recipientTypes, true))) {
                        $sentTo[] = $client->email;
                        if (in_array('photographer', $recipientTypes, true)) {
                            $sentTo = array_merge($sentTo, $this->recipientEmails($this->assignedPhotographers($shoot)));
                        }
                    }
                }
                break;

            case 'SHOOT_UPDATED':
                if ($shoot && $client && in_array('client', $recipientTypes, true)) {
                    $changesSummary = (string) ($context['changes_summary'] ?? $context['changesSummary'] ?? '');
                    if ($this->mailService->sendShootUpdatedEmail($client, $shoot, $changesSummary, true, in_array('photographer', $recipientTypes, true))) {
                        $sentTo[] = $client->email;
                        if (in_array('photographer', $recipientTypes, true)) {
                            $sentTo = array_merge($sentTo, $this->recipientEmails($this->assignedPhotographers($shoot)));
                        }
                    }
                }
                break;

            case 'SHOOT_REQUESTED':
                if ($shoot && $client && in_array('client', $recipientTypes, true) && $this->mailService->sendShootRequestedEmail($client, $shoot)) {
                    $sentTo[] = $client->email;
                }
                if ($shoot && in_array('admin', $recipientTypes, true) && $this->mailService->sendShootRequestedAdminNotificationEmails($shoot)) {
                    $sentTo = array_merge($sentTo, $this->recipientEmails($this->adminRecipients()));
                }
                break;

            case 'SHOOT_REQUEST_DECLINED':
                if ($shoot && $client && in_array('client', $recipientTypes, true) && $this->mailService->sendShootRequestDeclinedEmail($client, $shoot)) {
                    $sentTo[] = $client->email;
                }
                break;

            case 'SHOOT_COMPLETED':
                if ($shoot && $client && in_array('client', $recipientTypes, true) && $this->mailService->sendShootReadyEmail($client, $shoot)) {
                    $sentTo[] = $client->email;
                }
                break;

            case 'SHOOT_CANCELED':
            case 'SHOOT_CANCELLED':
                if ($shoot && $client && in_array('client', $recipientTypes, true) && $this->mailService->sendShootCancelledEmail($client, $shoot)) {
                    $sentTo[] = $client->email;
                    if (in_array('photographer', $recipientTypes, true)) {
                        $sentTo = array_merge($sentTo, $this->recipientEmails($this->assignedPhotographers($shoot)));
                    }
                }
                break;

            case 'SHOOT_REMOVED':
                if ($shoot && $client && in_array('client', $recipientTypes, true) && $this->mailService->sendShootRemovedEmail($client, $shoot)) {
                    $sentTo[] = $client->email;
                    if (in_array('photographer', $recipientTypes, true)) {
                        $sentTo = array_merge($sentTo, $this->recipientEmails($this->assignedPhotographers($shoot)));
                    }
                }
                break;

            case 'PAYMENT_COMPLETED':
                if ($shoot && $client && $payment && in_array('client', $recipientTypes, true) && $this->mailService->sendPaymentConfirmationEmail($client, $shoot, $payment)) {
                    $sentTo[] = $client->email;
                }
                break;

            case 'PHOTOGRAPHER_CHANGED':
                if ($shoot && in_array('photographer', $recipientTypes, true)) {
                    $changesSummary = (string) ($context['changes_summary'] ?? $context['changesSummary'] ?? '');
                    $previousPhotographer = $this->contextUser($context, 'previous_photographer');
                    foreach ($this->affectedPhotographers($context, $shoot) as $photographer) {
                        if ($this->mailService->sendPhotographerChangedEmail($photographer, $shoot, $previousPhotographer, $changesSummary)) {
                            $sentTo[] = $photographer->email;
                        }
                    }
                }
                break;
        }

        return collect($sentTo)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function contextUser(array $context, string $key): ?User
    {
        $candidate = data_get($context, $key);

        if ($candidate instanceof User) {
            return $candidate;
        }

        $id = $candidate['id'] ?? $candidate->id ?? null;

        return is_numeric($id) ? User::query()->find((int) $id) : null;
    }

    private function contextShoot(array $context): ?Shoot
    {
        $candidate = $context['shoot'] ?? null;

        if ($candidate instanceof Shoot) {
            return $candidate;
        }

        $shootId = $context['shoot_id'] ?? ($candidate['id'] ?? $candidate->id ?? null);

        return is_numeric($shootId)
            ? Shoot::query()->with(['client', 'photographer', 'rep', 'services.category', 'payments'])->find((int) $shootId)
            : null;
    }

    private function contextPayment(array $context): ?Payment
    {
        $candidate = $context['payment'] ?? null;

        if ($candidate instanceof Payment) {
            return $candidate;
        }

        $paymentId = $context['payment_id'] ?? ($candidate['id'] ?? $candidate->id ?? null);

        return is_numeric($paymentId) ? Payment::query()->find((int) $paymentId) : null;
    }

    private function contextSchedule(array $context): ?Carbon
    {
        $value = $context['shoot_datetime'] ?? $context['scheduled_at'] ?? null;

        if (!$value) {
            return null;
        }

        return $value instanceof Carbon ? $value : Carbon::parse($value);
    }

    /**
     * @return Collection<int, User>
     */
    private function assignedPhotographers(Shoot $shoot): Collection
    {
        $shoot->loadMissing(['photographer', 'services']);

        $ids = collect([$shoot->photographer_id, $shoot->photographer?->id])
            ->merge(collect($shoot->services ?? [])->pluck('pivot.photographer_id'))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $ids->all())
            ->whereNotNull('email')
            ->get()
            ->unique('id')
            ->values();
    }

    /**
     * @return Collection<int, User>
     */
    private function affectedPhotographers(array $context, Shoot $shoot): Collection
    {
        $affected = collect($context['affected_photographers'] ?? [])
            ->map(function ($candidate) {
                if ($candidate instanceof User) {
                    return $candidate;
                }

                $id = $candidate['id'] ?? $candidate->id ?? null;

                return is_numeric($id) ? User::query()->find((int) $id) : null;
            })
            ->filter(fn ($user) => $user instanceof User)
            ->values();

        return $affected->isNotEmpty() ? $affected : $this->assignedPhotographers($shoot);
    }

    /**
     * @return Collection<int, User>
     */
    private function adminRecipients(): Collection
    {
        return User::query()
            ->where(function ($query): void {
                $query->whereIn('role', self::ADMIN_ROLES);
                foreach (self::ADMIN_ROLES as $adminRole) {
                    $query->orWhereJsonContains('secondary_roles', $adminRole);
                }
            })
            ->whereNotNull('email')
            ->get()
            ->unique('id')
            ->values();
    }

    /**
     * @param  iterable<int, User>  $users
     * @return array<int, string>
     */
    private function recipientEmails(iterable $users): array
    {
        return collect($users)
            ->map(fn (User $user) => $user->email)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function executeSmsAction(AutomationRule $automation, array $node, array $context): array
    {
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $template = !empty($config['templateId'])
            ? MessageTemplate::find($config['templateId'])
            : null;
        $rendered = $template
            ? $this->templateRenderer->render($template, $context)
            : $this->renderInlineMessage($config, $context);

        $recipients = $this->resolveActionRecipients($automation, $config, $context, 'sms');
        $sentTo = [];

        foreach ($recipients as $recipient) {
            if (empty($recipient['phone'])) {
                continue;
            }

            $this->messagingService->sendSms([
                'to' => $recipient['phone'],
                'body_text' => $rendered['body_text'] ?? '',
                'sender_display_name' => $automation->name,
                'send_source' => 'AUTOMATION',
                'related_shoot_id' => $context['shoot_id'] ?? null,
                'related_account_id' => $context['account_id'] ?? null,
                'related_invoice_id' => $context['invoice_id'] ?? null,
                'contact_phone' => $recipient['phone'],
                'contact_name' => $recipient['name'] ?? 'Recipient',
                'contact_type' => $recipient['type'] ?? 'other',
                'tags_json' => $context['tags_json'] ?? null,
                'attachments_json' => $context['attachments_json'] ?? null,
                'metadata' => $context['metadata'] ?? null,
            ]);

            $sentTo[] = $recipient['phone'];
        }

        return [
            'channel' => 'sms',
            'sent_to' => $sentTo,
        ];
    }

    private function executeInternalNotificationAction(AutomationRule $automation, array $node, array $context): array
    {
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $recipients = $this->resolveActionRecipients($automation, $config, $context, 'internal');
        $deliveredTo = [];

        foreach ($recipients as $recipient) {
            if (empty($recipient['email'])) {
                continue;
            }

            $body = $this->replaceInlinePlaceholders((string) ($config['body'] ?? ''), $context);
            $destinationUrl = $this->replaceInlinePlaceholders((string) ($config['destinationUrl'] ?? ''), $context);
            $title = $this->replaceInlinePlaceholders((string) ($config['title'] ?? $automation->name), $context);

            $this->messagingService->storeInternalEmail([
                'to' => $recipient['email'],
                'subject' => $title,
                'body_text' => trim($body . "\n\nOpen: " . $destinationUrl),
                'body_html' => '<p>' . e($body) . '</p><p><a href="' . e($destinationUrl) . '">Open workflow item</a></p>',
                'send_source' => 'AUTOMATION',
                'sender_display_name' => $automation->name,
                'contact_email' => $recipient['email'],
                'contact_name' => $recipient['name'] ?? 'Team member',
                'contact_type' => $recipient['type'] ?? 'internal',
                'related_shoot_id' => $context['shoot_id'] ?? null,
                'related_account_id' => $context['account_id'] ?? null,
                'related_invoice_id' => $context['invoice_id'] ?? null,
                'tags_json' => $context['tags_json'] ?? null,
                'attachments_json' => $context['attachments_json'] ?? null,
                'metadata' => $context['metadata'] ?? null,
            ], 'INBOUND');

            $deliveredTo[] = $recipient['email'];
        }

        return [
            'channel' => 'internal',
            'delivered_to' => $deliveredTo,
        ];
    }

    private function resolveActionRecipients(AutomationRule $automation, array $config, array $context, string $mode): array
    {
        $recipientMode = $config['recipientMode'] ?? 'automation_default';

        if ($recipientMode === 'context' && !empty($config['contextKey'])) {
            $contextKey = (string) $config['contextKey'];

            if (!$this->shouldIncludeRoleRecipient($contextKey, $automation, $context)) {
                return [];
            }

            return $this->contextRecipient($contextKey, $context);
        }

        $roles = match ($recipientMode) {
            'roles' => Arr::wrap($config['recipientRoles'] ?? []),
            default => $this->normalizeRoles($automation->recipients_json),
        };

        return $this->resolveRecipientsByRoles($automation, $roles, $context, $mode);
    }

    private function resolveRecipientsByRoles(AutomationRule $automation, array $roles, array $context, string $mode): array
    {
        $recipients = [];

        foreach ($roles as $role) {
            if (!$this->shouldIncludeRoleRecipient((string) $role, $automation, $context)) {
                continue;
            }

            switch ($role) {
                case 'client':
                    $recipients = array_merge($recipients, $this->contextRecipient('client', $context));
                    break;

                case 'photographer':
                    $recipients = array_merge($recipients, $this->contextRecipient('photographer', $context));
                    break;

                case 'rep':
                    $recipients = array_merge($recipients, $this->contextRecipient('rep', $context));
                    break;

                case 'admin':
                    $users = User::query()
                        ->where(function ($query): void {
                            $query->whereIn('role', self::ADMIN_ROLES);
                            foreach (self::ADMIN_ROLES as $adminRole) {
                                $query->orWhereJsonContains('secondary_roles', $adminRole);
                            }
                        })
                        ->get();
                    foreach ($users as $user) {
                        $recipients[] = [
                            'email' => $user->email,
                            'phone' => $user->phonenumber ?? null,
                            'name' => $user->name ?? 'Admin',
                            'type' => 'admin',
                        ];
                    }
                    break;
            }
        }

        return collect($recipients)
            ->filter(fn (array $recipient) => !empty($recipient['email']) || ($mode === 'sms' && !empty($recipient['phone'])))
            ->unique(fn (array $recipient) => $recipient['email'] ?? $recipient['phone'] ?? spl_object_hash((object) $recipient))
            ->values()
            ->all();
    }

    private function shouldIncludeRoleRecipient(string $role, AutomationRule $automation, array $context): bool
    {
        if (
            $role === 'client'
            && ($context['notify_client'] ?? null) === false
            && in_array($automation->trigger_type, ['SHOOT_SCHEDULED', 'SHOOT_UPDATED'], true)
        ) {
            return false;
        }

        if (
            $role === 'photographer'
            && ($context['notify_photographer'] ?? null) === false
            && in_array($automation->trigger_type, ['SHOOT_SCHEDULED', 'SHOOT_UPDATED', 'PHOTOGRAPHER_CHANGED'], true)
        ) {
            return false;
        }

        if (
            $role === 'photographer'
            && $automation->trigger_type === 'SHOOT_UPDATED'
            && !empty($context['photographer_changed'])
        ) {
            return false;
        }

        return true;
    }

    private function contextRecipient(string $key, array $context): array
    {
        $item = data_get($context, $key);
        if (!$item) {
            return [];
        }

        return [[
            'email' => $item['email'] ?? $item->email ?? null,
            'phone' => $item['phonenumber'] ?? $item->phonenumber ?? $item['phone'] ?? $item->phone ?? null,
            'name' => $item['name'] ?? $item->name ?? Str::headline($key),
            'type' => $key,
        ]];
    }

    private function normalizeRoles(mixed $recipientsJson): array
    {
        if (is_array($recipientsJson) && array_is_list($recipientsJson)) {
            return array_values(array_map('strval', $recipientsJson));
        }

        if (is_array($recipientsJson) && isset($recipientsJson['roles']) && is_array($recipientsJson['roles'])) {
            return array_values(array_map('strval', $recipientsJson['roles']));
        }

        return ['client'];
    }

    private function nextNodeIds(array $workflow, ?string $sourceId, ?string $branchKey = null): array
    {
        if (!$sourceId) {
            return [];
        }

        return collect($workflow['edges'] ?? [])
            ->filter(function (array $edge) use ($sourceId, $branchKey): bool {
                if (($edge['source'] ?? null) !== $sourceId) {
                    return false;
                }

                if ($branchKey === null) {
                    return true;
                }

                return ($edge['branchKey'] ?? null) === $branchKey;
            })
            ->pluck('target')
            ->filter()
            ->values()
            ->all();
    }

    private function renderInlineMessage(array $config, array $context): array
    {
        return [
            'subject' => $this->replaceInlinePlaceholders((string) ($config['subject'] ?? ''), $context),
            'body_html' => $this->replaceInlinePlaceholders((string) ($config['bodyHtml'] ?? ''), $context),
            'body_text' => $this->replaceInlinePlaceholders((string) ($config['bodyText'] ?? ''), $context),
        ];
    }

    private function replaceInlinePlaceholders(string $value, array $context): string
    {
        return preg_replace_callback('/{{\s*([a-zA-Z0-9_.]+)\s*}}/', function (array $matches) use ($context): string {
            return (string) data_get($context, $matches[1], '');
        }, $value) ?? $value;
    }

    private function simulateQueue(AutomationRule $automation, array $workflow, array $context, array $queue): array
    {
        $nodeMap = collect($workflow['nodes'])->keyBy('id');
        $trace = [];

        while ($queue !== []) {
            $nodeId = array_shift($queue);
            $node = $nodeMap->get($nodeId);
            if (!$node) {
                continue;
            }

            $entry = [
                'node_id' => $nodeId,
                'node_type' => $node['type'],
                'status' => 'simulated',
            ];

            switch ($node['type']) {
                case 'condition.if':
                    $branch = $this->evaluateConditionNode($node, $context) ? 'true' : 'false';
                    $entry['branch'] = $branch;
                    $queue = array_merge($this->nextNodeIds($workflow, $nodeId, $branch), $queue);
                    break;

                case 'wait.duration':
                case 'wait.datetime_offset':
                    $entry['scheduled_for'] = optional($this->resolveWaitSchedule($node, $context))?->toIso8601String();
                    $queue = array_merge($this->nextNodeIds($workflow, $nodeId), $queue);
                    break;

                case 'action.email':
                case 'action.sms':
                case 'action.internal_notification':
                    $entry['preview_recipients'] = $this->resolveActionRecipients($automation, $node['config'] ?? [], $context, 'email');
                    $queue = array_merge($this->nextNodeIds($workflow, $nodeId), $queue);
                    break;

                default:
                    $queue = array_merge($this->nextNodeIds($workflow, $nodeId), $queue);
                    break;
            }

            $trace[] = $entry;
        }

        return $trace;
    }

    /**
     * @param  Collection<int, AutomationRule>  $rules
     * @param  array<int, AutomationRun>  $runs
     * @param  array<int, array{automation_id: int, message: string}>  $errors
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
    private function summarizeEventDispatch(string $triggerType, Collection $rules, array $runs, array $errors): array
    {
        $completedRunCount = collect($runs)
            ->filter(fn ($run) => $run instanceof AutomationRun && $run->status === 'completed')
            ->count();
        $waitingRunCount = collect($runs)
            ->filter(fn ($run) => $run instanceof AutomationRun && $run->status === 'waiting')
            ->count();
        $failedRunCount = collect($runs)
            ->filter(fn ($run) => !($run instanceof AutomationRun) || $run->status === 'failed')
            ->count() + count($errors);
        $activeRuleCount = $rules->count();
        $emailDeliverySummary = $this->summarizeEmailDeliveryByRole($runs);

        return array_merge([
            'trigger_type' => $triggerType,
            'active_rule_count' => $activeRuleCount,
            'run_count' => count($runs),
            'completed_run_count' => $completedRunCount,
            'waiting_run_count' => $waitingRunCount,
            'failed_run_count' => $failedRunCount,
            'handled' => $activeRuleCount > 0
                && $failedRunCount === 0
                && ($completedRunCount + $waitingRunCount) === $activeRuleCount,
            'errors' => $errors,
        ], $emailDeliverySummary);
    }

    private function summarizeEmailDeliveryByRole(array $runs): array
    {
        $sentEmails = [];
        $clientEmails = [];
        $photographerEmails = [];

        foreach ($runs as $run) {
            if (!$run instanceof AutomationRun) {
                continue;
            }

            $context = is_array($run->context_json) ? $run->context_json : [];
            $clientEmails = array_merge(
                $clientEmails,
                $this->extractContextEmails($context['account'] ?? null),
                $this->extractContextEmails($context['client'] ?? null)
            );
            $photographerEmails = array_merge(
                $photographerEmails,
                $this->extractContextEmails($context['photographer'] ?? null),
                $this->extractContextEmails($context['photographers'] ?? null)
            );

            foreach ($run->steps ?? [] as $step) {
                $output = is_array($step->output_json ?? null) ? $step->output_json : [];
                if (($output['channel'] ?? null) !== 'email') {
                    continue;
                }

                foreach (Arr::wrap($output['sent_to'] ?? []) as $email) {
                    $normalizedEmail = $this->normalizeEmailAddress($email);
                    if ($normalizedEmail !== null) {
                        $sentEmails[] = $normalizedEmail;
                    }
                }
            }
        }

        $sentEmails = array_values(array_unique($sentEmails));
        $clientEmails = array_values(array_unique($clientEmails));
        $photographerEmails = array_values(array_unique($photographerEmails));

        return [
            'email_sent_to' => $sentEmails,
            'client_email_sent' => $this->emailListsIntersect($sentEmails, $clientEmails),
            'photographer_email_sent' => $this->emailListsIntersect($sentEmails, $photographerEmails),
        ];
    }

    private function extractContextEmails(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_array($value) && array_is_list($value)) {
            return collect($value)
                ->flatMap(fn ($item) => $this->extractContextEmails($item))
                ->values()
                ->all();
        }

        $email = $this->normalizeEmailAddress(data_get($value, 'email'));

        return $email !== null ? [$email] : [];
    }

    private function normalizeEmailAddress(mixed $email): ?string
    {
        if (!is_string($email)) {
            return null;
        }

        $normalizedEmail = strtolower(trim($email));

        if ($normalizedEmail === '' || filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $normalizedEmail;
    }

    private function emailListsIntersect(array $left, array $right): bool
    {
        if ($left === [] || $right === []) {
            return false;
        }

        return array_intersect($left, $right) !== [];
    }
}
