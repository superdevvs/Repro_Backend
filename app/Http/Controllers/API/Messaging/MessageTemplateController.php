<?php

namespace App\Http\Controllers\API\Messaging;

use App\Http\Controllers\Controller;
use App\Models\MessageTemplate;
use App\Models\Shoot;
use App\Services\Messaging\ManualNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MessageTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $channel = $request->query('channel', 'EMAIL');
        $scope = $request->query('scope');
        $category = $request->query('category');
        $is_active = $request->query('is_active');

        $templates = MessageTemplate::query()
            ->when($channel, fn ($query) => $query->where('channel', $channel))
            ->when($scope, fn ($query) => $query->where('scope', $scope))
            ->when($category, fn ($query) => $query->where('category', $category))
            ->when($is_active !== null, fn ($query) => $query->where('is_active', (bool) $is_active))
            ->with(['creator', 'updater'])
            ->orderBy('name')
            ->get();

        return response()->json($templates);
    }

    public function show(MessageTemplate $template): JsonResponse
    {
        return response()->json($template->load(['creator', 'updater']));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request);

        $template = MessageTemplate::create(array_merge($data, [
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]));

        return response()->json($template, 201);
    }

    public function update(Request $request, MessageTemplate $template): JsonResponse
    {
        $data = $this->validatePayload($request);
        $template->update(array_merge($data, [
            'updated_by' => $request->user()->id,
        ]));

        return response()->json($template->fresh());
    }

    public function destroy(MessageTemplate $template): JsonResponse
    {
        // Prevent deletion of system templates
        if ($template->is_system) {
            return response()->json(['error' => 'Cannot delete system template'], 403);
        }

        // Check if template is used by any automation
        $automationCount = \App\Models\AutomationRule::where('template_id', $template->id)->count();
        if ($automationCount > 0) {
            return response()->json([
                'error' => "Template is used by {$automationCount} automation(s)",
            ], 400);
        }

        $template->delete();

        return response()->json(['status' => 'deleted']);
    }

    public function duplicate(MessageTemplate $template): JsonResponse
    {
        $newTemplate = $template->replicate();
        $newTemplate->name = $template->name . ' (Copy)';
        $newTemplate->slug = null;
        $newTemplate->is_system = false;
        $newTemplate->scope = 'USER';
        $newTemplate->owner_id = request()->user()->id;
        $newTemplate->created_by = request()->user()->id;
        $newTemplate->updated_by = request()->user()->id;
        $newTemplate->save();

        return response()->json($newTemplate, 201);
    }

    public function testSend(Request $request, MessageTemplate $template): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', 'email'],
            'variables' => ['array'],
            'template' => ['array'],
            'template.name' => ['sometimes', 'required', 'string', 'max:255'],
            'template.description' => ['nullable', 'string'],
            'template.category' => ['nullable', Rule::in(['BOOKING', 'REMINDER', 'PAYMENT', 'INVOICE', 'ACCOUNT', 'GENERAL'])],
            'template.subject' => ['nullable', 'string', 'max:255'],
            'template.body_html' => ['nullable', 'string'],
            'template.body_text' => ['nullable', 'string'],
            'template.channel' => ['sometimes', Rule::in(['EMAIL', 'SMS'])],
        ]);

        $renderer = app(\App\Services\Messaging\TemplateRenderer::class);
        $resolver = app(\App\Services\Messaging\TemplateVariableResolver::class);
        $variables = $resolver->resolve($data['variables'] ?? []);
        $renderTemplate = $this->buildTestTemplate($template, $data['template'] ?? []);
        $result = $renderer->render($renderTemplate, $variables);

        $service = app(\App\Services\Messaging\MessagingService::class);
        $service->sendEmail([
            'to' => $data['to'],
            'subject' => $result['subject'],
            'body_html' => $result['html'],
            'body_text' => $result['text'],
            'user_id' => $request->user()->id,
        ]);

        return response()->json(['status' => 'sent']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function buildTestTemplate(MessageTemplate $template, array $overrides): MessageTemplate
    {
        if ($overrides === []) {
            return $template;
        }

        $draft = $template->replicate();
        $draft->fill([
            'channel' => $overrides['channel'] ?? $template->channel,
            'name' => $overrides['name'] ?? $template->name,
            'description' => $overrides['description'] ?? $template->description,
            'category' => $overrides['category'] ?? $template->category,
            'subject' => $overrides['subject'] ?? $template->subject,
            'body_html' => $overrides['body_html'] ?? $template->body_html,
            'body_text' => $overrides['body_text'] ?? $template->body_text,
        ]);

        return $draft;
    }

    public function preview(Request $request, MessageTemplate $template): JsonResponse
    {
        $data = $request->validate([
            'variables' => ['array'],
        ]);

        $renderer = app(\App\Services\Messaging\TemplateRenderer::class);
        $resolver = app(\App\Services\Messaging\TemplateVariableResolver::class);
        $variables = $resolver->resolve($data['variables'] ?? []);
        $result = $renderer->render($template, $variables);

        return response()->json($result);
    }

    /**
     * Manually send a shoot notification (Req 12.1, 12.6, 12.7) via {@see ManualNotificationService}.
     *
     * Wraps the existing test-send/preview pipeline: maps the manual notification type to a
     * MessageTemplate slug, dispatches through the existing MessagingService for the selected
     * channel, and lets the service record `shoot_ready_notified_at` (AC 12.10) and the
     * Audit_Log entry (AC 12.9). Mounted at POST /messaging/notifications/manual-send.
     */
    public function manualSend(Request $request, ManualNotificationService $manual): JsonResponse
    {
        $data = $this->validateManualPayload($request);

        $shoot = Shoot::findOrFail($data['shoot_id']);

        $message = $manual->send(
            $shoot,
            $data['type'],
            $data['recipient_type'],
            $data['channel'],
            $request->user(),
        );

        return response()->json([
            'status'     => 'sent',
            'message_id' => $message->id ?? null,
            'channel'    => $data['channel'],
            'recipient_type' => $data['recipient_type'],
        ]);
    }

    /**
     * Render a manual shoot notification preview (Req 12.5, 12.8) without sending or auditing.
     *
     * Returns the rendered subject/body plus any unresolved template variables so the Dashboard
     * can show a missing-variables warning before the Admin sends. Mounted at
     * POST /messaging/notifications/manual-preview.
     */
    public function manualPreview(Request $request, ManualNotificationService $manual): JsonResponse
    {
        $data = $this->validateManualPayload($request, requireChannel: false);

        $shoot = Shoot::findOrFail($data['shoot_id']);

        $preview = $manual->preview($shoot, $data['type'], $data['recipient_type']);

        return response()->json($preview);
    }

    /**
     * Validate the manual-send / manual-preview request payload.
     *
     * @return array{shoot_id:int,type:string,recipient_type:string,channel?:string}
     */
    protected function validateManualPayload(Request $request, bool $requireChannel = true): array
    {
        $rules = [
            'shoot_id'       => ['required', 'integer', 'exists:shoots,id'],
            'type'           => ['required', 'string', Rule::in(array_keys(ManualNotificationService::TYPES))],
            'recipient_type' => ['required', Rule::in(['client', 'photographer'])],
            'channel'        => [$requireChannel ? 'required' : 'nullable', Rule::in(['email', 'sms'])],
        ];

        return $request->validate($rules);
    }

    protected function validatePayload(Request $request): array
    {
        $categories = ['BOOKING', 'REMINDER', 'PAYMENT', 'INVOICE', 'ACCOUNT', 'GENERAL'];

        return $request->validate([
            'channel' => ['required', Rule::in(['EMAIL', 'SMS'])],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['nullable', Rule::in($categories)],
            'subject' => ['nullable', 'string', 'max:255'],
            'body_html' => ['nullable', 'string'],
            'body_text' => ['nullable', 'string'],
            'scope' => ['required', Rule::in(['SYSTEM', 'GLOBAL', 'ACCOUNT', 'USER'])],
            'owner_id' => ['nullable', 'integer'],
            'variables_json' => ['nullable', 'array'],
            'is_system' => ['boolean'],
            'is_active' => ['boolean'],
            'email_type' => ['nullable', 'string', 'max:255'],
            'override_enabled' => ['boolean'],
        ]);
    }
}

