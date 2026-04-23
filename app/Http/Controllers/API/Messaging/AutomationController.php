<?php

namespace App\Http\Controllers\API\Messaging;

use App\Http\Controllers\Controller;
use App\Models\AutomationRule;
use App\Models\MessageTemplate;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\AutomationWorkflowConverter;
use App\Services\Messaging\AutomationWorkflowExecutor;
use App\Services\Messaging\AutomationWorkflowValidator;
use App\Services\Messaging\TemplateRenderer;
use App\Services\Messaging\TemplateVariableResolver;
use App\Services\SystemEmails\ProtectedAutomationEmailMap;
use Database\Seeders\MessagingSystemSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AutomationController extends Controller
{
    private const REQUIRED_SYSTEM_TRIGGER_TYPES = [
        'WEEKLY_SALES_REPORT',
        'WEEKLY_AUTOMATED_INVOICING',
    ];

    private const PROPERTY_CONTACT_REMINDER_NAMES = [
        'Property Contact Reminder - 2 Days Before',
        'Property Contact Reminder - 1 Day Before',
        'Property Contact Reminder - Shoot Day',
        'Property Contact Reminder SMS - 2 Days Before',
        'Property Contact Reminder SMS - 1 Day Before',
        'Property Contact Reminder SMS - Shoot Day',
    ];

    public function __construct(
        private readonly AutomationService $automationService,
        private readonly TemplateRenderer $templateRenderer,
        private readonly AutomationWorkflowConverter $workflowConverter,
        private readonly AutomationWorkflowValidator $workflowValidator,
        private readonly AutomationWorkflowExecutor $workflowExecutor,
        private readonly ProtectedAutomationEmailMap $protectedAutomationEmailMap,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureRequiredSystemAutomations();

        $query = AutomationRule::query()
            ->with(['template', 'channel', 'creator', 'updater', 'latestDispatch']);

        if ($request->has('trigger_type')) {
            $query->where('trigger_type', $request->query('trigger_type'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', (bool) $request->query('is_active'));
        }

        $automations = $query->orderBy('scope')->orderBy('trigger_type')->orderBy('name')->get();

        return response()->json(
            $automations->map(fn (AutomationRule $automation) => $this->serializeAutomation($automation, includeRuns: false))->values()
        );
    }

    public function show(AutomationRule $automation): JsonResponse
    {
        $automation->load(['template', 'channel', 'creator', 'updater', 'latestDispatch']);

        return response()->json($this->serializeAutomation($automation, includeRuns: true));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request);

        $automation = AutomationRule::create(array_merge($data, [
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]));

        $automation = $this->persistResolvedWorkflow($automation, $data);

        return response()->json($this->serializeAutomation($automation->load(['template', 'channel', 'latestDispatch']), true), 201);
    }

    public function update(Request $request, AutomationRule $automation): JsonResponse
    {
        $data = $this->validatePayload($request, $automation);

        if ($automation->is_system_locked && isset($data['workflow_definition_json'])) {
            $this->assertLockedWorkflowShape($automation, $data['workflow_definition_json']);
        }

        $automation->update(array_merge($data, [
            'updated_by' => $request->user()->id,
        ]));

        $automation = $this->persistResolvedWorkflow($automation, $data);

        return response()->json($this->serializeAutomation($automation->fresh()->load(['template', 'channel', 'latestDispatch']), true));
    }

    public function destroy(AutomationRule $automation): JsonResponse
    {
        if ($automation->scope === 'SYSTEM') {
            return response()->json(['error' => 'Cannot delete system automation'], 403);
        }

        $automation->delete();

        return response()->json(['status' => 'deleted']);
    }

    public function validateWorkflow(Request $request): JsonResponse
    {
        $request->validate([
            'workflow_definition_json' => ['required', 'array'],
        ]);

        return response()->json(
            $this->workflowValidator->validate($request->input('workflow_definition_json'))
        );
    }

    public function runs(AutomationRule $automation): JsonResponse
    {
        return response()->json([
            'data' => $automation->recentRuns()
                ->with('steps')
                ->limit(20)
                ->get()
                ->toArray(),
        ]);
    }

    public function simulate(Request $request, AutomationRule $automation): JsonResponse
    {
        $data = $request->validate([
            'test_context' => ['array'],
        ]);

        return response()->json(
            $this->workflowExecutor->executeAutomation($automation->loadMissing(['template', 'channel']), $data['test_context'] ?? [], true)
        );
    }

    public function test(Request $request, AutomationRule $automation): JsonResponse
    {
        $data = $request->validate([
            'test_email' => ['required', 'email'],
            'test_context' => ['array'],
        ]);

        if (!$automation->template) {
            return response()->json(['error' => 'Automation has no template'], 400);
        }

        $resolver = app(TemplateVariableResolver::class);
        $variables = $resolver->resolve($data['test_context'] ?? []);

        $rendered = $this->templateRenderer->render(
            $automation->template,
            $variables
        );

        if (!empty($rendered['missing'])) {
            Log::warning('Automation test email missing template variables', [
                'automation_id' => $automation->id,
                'template_id' => $automation->template_id,
                'missing' => $rendered['missing'],
            ]);
        }

        $messagingService = app(\App\Services\Messaging\MessagingService::class);
        $messagingService->sendEmail([
            'to' => $data['test_email'],
            'subject' => '[TEST] ' . ($rendered['subject'] ?? $automation->template->subject),
            'body_html' => $rendered['body_html'] ?? null,
            'body_text' => $rendered['body_text'] ?? null,
            'channel_id' => $automation->channel_id,
            'user_id' => $request->user()->id,
            'send_source' => 'MANUAL',
        ]);

        return response()->json([
            'status' => 'sent',
            'preview' => [
                'subject' => $rendered['subject'] ?? null,
                'body_html' => $rendered['body_html'] ?? null,
                'body_text' => $rendered['body_text'] ?? null,
            ],
        ]);
    }

    public function toggleActive(AutomationRule $automation): JsonResponse
    {
        $automation->update(['is_active' => !$automation->is_active]);

        return response()->json($this->serializeAutomation($automation->fresh()->load(['template', 'channel', 'latestDispatch']), false));
    }

    public function runNow(AutomationRule $automation): JsonResponse
    {
        if ($automation->scope !== 'SYSTEM') {
            return response()->json(['error' => 'Manual run is only supported for system automations'], 422);
        }

        Artisan::call('automations:run-system', [
            '--trigger' => $automation->trigger_type,
            '--force' => true,
        ]);

        return response()->json([
            'status' => 'queued',
            'output' => trim(Artisan::output()),
            'automation' => $this->serializeAutomation($automation->fresh()->load(['template', 'channel', 'latestDispatch']), true),
        ]);
    }

    protected function validatePayload(Request $request, ?AutomationRule $automation = null): array
    {
        $systemTriggerTypes = self::REQUIRED_SYSTEM_TRIGGER_TYPES;

        $triggerTypes = [
            'ACCOUNT_CREATED',
            'ACCOUNT_VERIFIED',
            'PASSWORD_RESET',
            'TERMS_ACCEPTED',
            'SHOOT_BOOKED',
            'SHOOT_SCHEDULED',
            'SHOOT_UPDATED',
            'SHOOT_REMINDER',
            'SHOOT_COMPLETED',
            'SHOOT_CANCELED',
            'SHOOT_REMOVED',
            'PAYMENT_COMPLETED',
            'PAYMENT_FAILED',
            'PAYMENT_REFUNDED',
            'INVOICE_DUE',
            'INVOICE_OVERDUE',
            'INVOICE_SUMMARY',
            'INVOICE_PAID',
            'WEEKLY_PHOTOGRAPHER_INVOICE',
            'WEEKLY_REP_INVOICE',
            'WEEKLY_SALES_REPORT',
            'WEEKLY_AUTOMATED_INVOICING',
            'PHOTO_UPLOADED',
            'MEDIA_UPLOAD_COMPLETE',
            'PHOTOGRAPHER_ASSIGNED',
            'PHOTOGRAPHER_CHANGED',
            'SHOOT_REQUESTED',
            'SHOOT_REQUEST_APPROVED',
            'SHOOT_REQUEST_MODIFIED',
            'EDITING_COMPLETE',
            'PROPERTY_CONTACT_REMINDER',
        ];

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'trigger_type' => ['required', Rule::in($triggerTypes)],
            'editor_mode' => ['nullable', Rule::in(['visual', 'simple'])],
            'engine_version' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['boolean'],
            'scope' => ['required', Rule::in(['SYSTEM', 'GLOBAL', 'ACCOUNT', 'USER'])],
            'owner_id' => ['nullable', 'integer'],
            'template_id' => ['nullable', 'exists:message_templates,id'],
            'channel_id' => ['nullable', 'exists:message_channels,id'],
            'condition_json' => ['nullable', 'array'],
            'schedule_json' => ['nullable', 'array'],
            'workflow_definition_json' => ['nullable', 'array'],
            'entry_trigger_json' => ['nullable', 'array'],
            'is_system_locked' => ['nullable', 'boolean'],
            'recipients_json' => ['nullable', 'array'],
        ]);

        if (($data['scope'] ?? $automation?->scope) !== 'SYSTEM' && in_array($data['trigger_type'] ?? $automation?->trigger_type, $systemTriggerTypes, true)) {
            throw ValidationException::withMessages([
                'trigger_type' => ['Weekly system triggers can only be managed as SYSTEM automations.'],
            ]);
        }

        if (!empty($data['workflow_definition_json'])) {
            $validation = $this->workflowValidator->validate($data['workflow_definition_json']);
            if (!$validation['valid']) {
                throw ValidationException::withMessages([
                    'workflow_definition_json' => $validation['errors'],
                ]);
            }
        }

        if ($this->protectedAutomationEmailMap->isProtectedTrigger((string) ($data['trigger_type'] ?? $automation?->trigger_type ?? ''))) {
            $data['template_id'] = null;
        }

        return $data;
    }

    private function ensureRequiredSystemAutomations(): void
    {
        $existingSystemTriggers = AutomationRule::query()
            ->where('scope', 'SYSTEM')
            ->whereIn('trigger_type', self::REQUIRED_SYSTEM_TRIGGER_TYPES)
            ->pluck('trigger_type')
            ->all();

        $missingTriggers = array_diff(self::REQUIRED_SYSTEM_TRIGGER_TYPES, $existingSystemTriggers);

        if ($missingTriggers === []) {
            if ($this->propertyContactReminderWorkflowsNeedRepair()) {
                Artisan::call('db:seed', [
                    '--class' => MessagingSystemSeeder::class,
                    '--force' => true,
                ]);
            }

            $this->repairPropertyContactReminderWorkflows();
            return;
        }

        Artisan::call('automations:ensure-system');
        if ($this->propertyContactReminderWorkflowsNeedRepair()) {
            Artisan::call('db:seed', [
                '--class' => MessagingSystemSeeder::class,
                '--force' => true,
            ]);
        }
        $this->repairPropertyContactReminderWorkflows();
    }

    private function persistResolvedWorkflow(AutomationRule $automation, array $data): AutomationRule
    {
        $workflow = $data['workflow_definition_json'] ?? $this->workflowConverter->getWorkflowDefinition($automation);
        $entryTrigger = $data['entry_trigger_json'] ?? $this->workflowConverter->getEntryTrigger($automation);

        $automation->forceFill([
            'editor_mode' => $data['editor_mode'] ?? $automation->editor_mode ?? 'visual',
            'engine_version' => $data['engine_version'] ?? 2,
            'workflow_definition_json' => $workflow,
            'entry_trigger_json' => $entryTrigger,
            'is_system_locked' => $data['is_system_locked'] ?? $automation->is_system_locked ?? false,
        ])->save();

        return $automation->fresh();
    }

    private function propertyContactReminderWorkflowsNeedRepair(): bool
    {
        $automations = AutomationRule::query()
            ->where('scope', 'SYSTEM')
            ->where('trigger_type', 'PROPERTY_CONTACT_REMINDER')
            ->whereIn('name', self::PROPERTY_CONTACT_REMINDER_NAMES)
            ->with('template')
            ->get();

        if ($automations->count() !== count(self::PROPERTY_CONTACT_REMINDER_NAMES)) {
            return true;
        }

        foreach ($automations as $automation) {
            $validation = $this->workflowValidator->validate($this->workflowConverter->getWorkflowDefinition($automation));
            $expectedTemplateSlug = str_contains($automation->name, 'SMS')
                ? 'property-contact-reminder-sms'
                : 'property-contact-reminder';
            $expectedTemplateId = MessageTemplate::query()->where('slug', $expectedTemplateSlug)->value('id');

            if (
                !$validation['valid']
                || !$automation->is_system_locked
                || ($automation->engine_version ?? 0) < 2
                || !$automation->workflow_definition_json
                || (int) $automation->template_id !== (int) $expectedTemplateId
            ) {
                return true;
            }
        }

        return false;
    }

    private function repairPropertyContactReminderWorkflows(): void
    {
        AutomationRule::query()
            ->where('scope', 'SYSTEM')
            ->where('trigger_type', 'PROPERTY_CONTACT_REMINDER')
            ->whereIn('name', self::PROPERTY_CONTACT_REMINDER_NAMES)
            ->with('template')
            ->get()
            ->each(function (AutomationRule $automation): void {
                $expectedTemplateSlug = str_contains($automation->name, 'SMS')
                    ? 'property-contact-reminder-sms'
                    : 'property-contact-reminder';
                $expectedTemplateId = MessageTemplate::query()->where('slug', $expectedTemplateSlug)->value('id');

                if ($expectedTemplateId && (int) $automation->template_id !== (int) $expectedTemplateId) {
                    $automation->template_id = $expectedTemplateId;
                }

                $workflow = $this->workflowConverter->buildLegacyWorkflow($automation);
                $triggerNode = collect($workflow['nodes'] ?? [])
                    ->first(fn (array $node) => str_starts_with((string) ($node['type'] ?? ''), 'trigger.'));
                $entryTrigger = [
                    'trigger_type' => $automation->trigger_type,
                    'node_id' => $triggerNode['id'] ?? null,
                    'node_type' => $triggerNode['type'] ?? null,
                    'config' => $triggerNode['config'] ?? [],
                ];
                $validation = $this->workflowValidator->validate($workflow);

                if (!$validation['valid']) {
                    Log::warning('Property contact reminder workflow remains invalid after repair attempt', [
                        'automation_id' => $automation->id,
                        'name' => $automation->name,
                        'errors' => $validation['errors'],
                    ]);
                }

                $automation->forceFill([
                    'editor_mode' => 'visual',
                    'engine_version' => 2,
                    'is_system_locked' => true,
                    'workflow_definition_json' => $workflow,
                    'entry_trigger_json' => $entryTrigger,
                ])->save();
            });
    }

    private function serializeAutomation(AutomationRule $automation, bool $includeRuns): array
    {
        $workflow = $this->workflowConverter->getWorkflowDefinition($automation);
        $validation = $this->workflowValidator->validate($workflow);
        $recentRuns = $automation->recentRuns()
            ->when($includeRuns, fn ($query) => $query->with('steps'))
            ->limit($includeRuns ? 20 : 3)
            ->get();
        $templateSourceOfTruth = $this->protectedAutomationEmailMap->isProtectedTrigger((string) $automation->trigger_type)
            ? 'code'
            : 'database';

        return array_merge($automation->toArray(), [
            'editor_mode' => $automation->editor_mode ?: 'visual',
            'engine_version' => $automation->engine_version ?: 2,
            'workflow_definition_json' => $workflow,
            'entry_trigger_json' => $automation->entry_trigger_json ?: $this->workflowConverter->getEntryTrigger($automation),
            'is_system_locked' => (bool) $automation->is_system_locked,
            'legacy_status' => is_array($automation->workflow_definition_json) ? 'migrated' : 'converted_from_legacy',
            'template_source_of_truth' => $templateSourceOfTruth,
            'template_override_ignored' => $templateSourceOfTruth === 'code',
            'validation_state' => $validation,
            'recent_runs' => $recentRuns->toArray(),
        ]);
    }

    private function assertLockedWorkflowShape(AutomationRule $automation, array $incomingWorkflow): void
    {
        $existingWorkflow = $this->workflowConverter->getWorkflowDefinition($automation);

        $existingSignature = [
            'nodes' => collect($existingWorkflow['nodes'] ?? [])->map(fn (array $node) => [
                'id' => $node['id'] ?? null,
                'type' => $node['type'] ?? null,
            ])->values()->all(),
            'edges' => collect($existingWorkflow['edges'] ?? [])->map(fn (array $edge) => [
                'source' => $edge['source'] ?? null,
                'target' => $edge['target'] ?? null,
                'branchKey' => $edge['branchKey'] ?? null,
            ])->values()->all(),
        ];

        $incomingSignature = [
            'nodes' => collect($incomingWorkflow['nodes'] ?? [])->map(fn (array $node) => [
                'id' => $node['id'] ?? null,
                'type' => $node['type'] ?? null,
            ])->values()->all(),
            'edges' => collect($incomingWorkflow['edges'] ?? [])->map(fn (array $edge) => [
                'source' => $edge['source'] ?? null,
                'target' => $edge['target'] ?? null,
                'branchKey' => $edge['branchKey'] ?? null,
            ])->values()->all(),
        ];

        if ($existingSignature !== $incomingSignature) {
            throw ValidationException::withMessages([
                'workflow_definition_json' => ['System-locked automations cannot change workflow structure in v1.'],
            ]);
        }
    }
}
