<?php

namespace App\Http\Controllers\API\Messaging;

use App\Http\Controllers\Controller;
use App\Models\AutomationRule;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\TemplateRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AutomationController extends Controller
{
    public function __construct(
        private readonly AutomationService $automationService,
        private readonly TemplateRenderer $templateRenderer
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $query = AutomationRule::query()
            ->with(['template', 'channel', 'creator', 'updater']);

        if ($request->has('trigger_type')) {
            $query->where('trigger_type', $request->query('trigger_type'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', (bool) $request->query('is_active'));
        }

        $automations = $query->orderBy('trigger_type')->orderBy('name')->get();

        // Debug: Log weekly automations count
        $weeklyCount = $automations->filter(function($a) {
            return in_array($a->trigger_type, ['WEEKLY_SALES_REPORT', 'WEEKLY_AUTOMATED_INVOICING']);
        })->count();
        
        \Log::info('Automations API Response', [
            'total' => $automations->count(),
            'weekly_count' => $weeklyCount,
            'weekly_trigger_types' => $automations->pluck('trigger_type')->toArray(),
        ]);

        return response()->json($automations);
    }

    public function show(AutomationRule $automation): JsonResponse
    {
        return response()->json($automation->load(['template', 'channel', 'creator', 'updater']));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request);

        $automation = AutomationRule::create(array_merge($data, [
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]));

        return response()->json($automation->load(['template', 'channel']), 201);
    }

    public function update(Request $request, AutomationRule $automation): JsonResponse
    {
        $data = $this->validatePayload($request);

        $automation->update(array_merge($data, [
            'updated_by' => $request->user()->id,
        ]));

        return response()->json($automation->fresh()->load(['template', 'channel']));
    }

    public function destroy(AutomationRule $automation): JsonResponse
    {
        // Prevent deletion of system automations
        if ($automation->scope === 'SYSTEM') {
            return response()->json(['error' => 'Cannot delete system automation'], 403);
        }

        $automation->delete();

        return response()->json(['status' => 'deleted']);
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

        // Render template with test context
        $rendered = $this->templateRenderer->render(
            $automation->template,
            $data['test_context'] ?? []
        );

        // Send test email
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

        return response()->json($automation->fresh());
    }

    protected function validatePayload(Request $request): array
    {
        $triggerTypes = [
            'ACCOUNT_CREATED',
            'ACCOUNT_VERIFIED',
            'SHOOT_BOOKED',
            'SHOOT_SCHEDULED',
            'SHOOT_REMINDER',
            'SHOOT_COMPLETED',
            'PAYMENT_COMPLETED',
            'INVOICE_SUMMARY',
            'WEEKLY_PHOTOGRAPHER_INVOICE',
            'WEEKLY_REP_INVOICE',
            'WEEKLY_SALES_REPORT',
            'WEEKLY_AUTOMATED_INVOICING',
            'PHOTO_UPLOADED',
        ];

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'trigger_type' => ['required', Rule::in($triggerTypes)],
            'is_active' => ['boolean'],
            'scope' => ['required', Rule::in(['SYSTEM', 'GLOBAL', 'ACCOUNT', 'USER'])],
            'owner_id' => ['nullable', 'integer'],
            'template_id' => ['nullable', 'exists:message_templates,id'],
            'channel_id' => ['nullable', 'exists:message_channels,id'],
            'condition_json' => ['nullable', 'array'],
            'schedule_json' => ['nullable', 'array'],
            'recipients_json' => ['nullable', 'array'],
        ]);
    }
}

